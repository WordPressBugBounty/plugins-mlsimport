<?php
/**
 * Standalone (theme_id 990) flat-table row assembly.
 *
 * Single source of truth for turning a RESO field map into mlsimport_listings
 * column values (via the §9 column targets + derivations) and upserting the row
 * by listing_key. Shared by the live write adapter (fields from the import
 * payload) and reindex (fields reconstructed from a post's meta + taxonomies).
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-reso-map.php';
require_once __DIR__ . '/class-mlsimport-standalone-derive.php';
require_once __DIR__ . '/class-mlsimport-standalone-table.php';

/**
 * Builds and upserts mlsimport_listings rows.
 */
class Mlsimport_Standalone_Row {

	/**
	 * Assemble the flat-table column values from a RESO field => value map.
	 * Column targets come from the §9 map; bathrooms/lot_size/list_date are
	 * derived (overriding any raw copy).
	 *
	 * @param array $fields RESO field => value (scalars; arrays are skipped).
	 * @return array Column => value.
	 */
	public static function build_columns( array $fields ): array {
		$row = array();

		// Walk every RESO field; copy the raw value into each of its column: targets.
		foreach ( $fields as $field => $value ) {
			// Only scalars map to flat columns; multi-value arrays go to taxonomies/meta.
			if ( is_array( $value ) ) {
				continue;
			}
			// A field may resolve to several targets; keep only the column: ones here.
			foreach ( Mlsimport_Standalone_Reso_Map::targets_for( $field ) as $target ) {
				if ( 0 === strpos( $target, 'column:' ) ) {
					// Strip the "column:" prefix (7 chars) to get the bare column name.
					$row[ substr( $target, 7 ) ] = $value;
				}
			}
		}

		// Derived columns override any raw copy: combine multiple RESO fields into one.
		$bathrooms = Mlsimport_Standalone_Derive::derive_bathrooms( $fields );
		if ( null !== $bathrooms ) {
			$row['bathrooms'] = $bathrooms;
		}
		$lot_sqft = Mlsimport_Standalone_Derive::derive_lot_sqft( $fields );
		if ( null !== $lot_sqft ) {
			$row['lot_size'] = $lot_sqft;
		}
		$list_date = Mlsimport_Standalone_Derive::derive_list_date( $fields );
		if ( null !== $list_date ) {
			$row['list_date'] = $list_date;
		}

		// DATETIME columns: normalize RESO ISO-8601 to MySQL format.
		foreach ( array( 'modification_timestamp', 'list_date' ) as $dt_col ) {
			if ( isset( $row[ $dt_col ] ) ) {
				$row[ $dt_col ] = Mlsimport_Standalone_Derive::normalize_datetime( $row[ $dt_col ] );
			}
		}

		return $row;
	}

	/**
	 * Insert or update the row for a listing, keyed by listing_key (UNIQUE).
	 *
	 * The index mirrors published listings only: when the post is not published
	 * (trashed, draft, pending, private) its row is dropped instead of written, so
	 * a non-public listing can never sit in the search index. This is the single
	 * chokepoint every write path (live import + reindex) flows through.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $listing_key RESO ListingKey.
	 * @param array  $row         Column => value.
	 * @return bool True when the row was written, false when skipped/removed.
	 */
	public static function upsert( $post_id, $listing_key, array $row ): bool {
		global $wpdb;

		// Index mirrors published listings only: drop the row for any non-public post.
		if ( 'publish' !== get_post_status( $post_id ) ) {
			self::delete( (int) $post_id );
			return false;
		}

		// Stamp the identity columns onto the row before write.
		$table              = Mlsimport_Standalone_Table::table_name();
		$row['listing_key'] = $listing_key;
		$row['post_id']     = $post_id;

		/** Filter the flat-table row before write. @since 6.3 */
		$row = (array) apply_filters( 'mlsimport_listings_row', $row, $post_id, $listing_key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE listing_key = %s", $listing_key ) );

		// Update in place when a row already exists for this listing_key, else insert.
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, $row );
		}

		/** Fires after a listings row is inserted/updated. @since 6.3 */
		do_action( 'mlsimport_after_listings_row_upsert', $post_id, $listing_key, $row );
		return true;
	}

	/**
	 * Remove a listing's row from the index. The single row-deletion path, shared
	 * by the upsert guard, post deletion and the non-published status transition.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete( $post_id ): void {
		global $wpdb;
		$post_id = (int) $post_id;

		/** Fires before the listings row is deleted. @since 6.3 */
		do_action( 'mlsimport_before_delete_listing_row', $post_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Mlsimport_Standalone_Table::table_name(), array( 'post_id' => $post_id ) );

		/** Fires after the listings row is deleted. @since 6.3 */
		do_action( 'mlsimport_after_delete_listing_row', $post_id );
	}

	/**
	 * Clean up everything a property's raw-SQL delete leaves behind: its term
	 * relationships, the affected terms' cached counts, and its listings row.
	 *
	 * The reconciliation delete removes the post straight from wp_posts/wp_postmeta
	 * for speed (no wp_delete_post), so none of core's cleanup runs — the term
	 * relationships, the denormalized wp_term_taxonomy.count, and this table's row
	 * are all orphaned. This does that cleanup with the same SQL-first approach:
	 * capture the term links, delete them, recompute count for exactly those terms
	 * (WP's default published-post semantics: publish + mlsimport_property), clear
	 * the term cache so a persistent object cache stops serving the stale count,
	 * then drop the listings row. Call it before the raw wp_posts delete.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function purge_post_relations( $post_id ): void {
		global $wpdb;
		$post_id = (int) $post_id;

		// The terms this object is linked to, captured before we delete the links.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.term_taxonomy_id, tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d",
				$post_id
			)
		);

		if ( $links ) {
			$ttids    = array_map( 'intval', wp_list_pluck( $links, 'term_taxonomy_id' ) );
			$term_ids = array_map( 'intval', wp_list_pluck( $links, 'term_id' ) );

			// Drop the object's term relationships.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $post_id ) );

			// Recompute count for exactly the affected terms — WP's default published
			// post-count definition, so the number matches the published-only archive.
			// Bounded to this post's terms and self-healing (fixes any prior drift).
			$placeholders = implode( ', ', array_fill( 0, count( $ttids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->term_taxonomy} tt SET tt.count = ( SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tr.term_taxonomy_id = tt.term_taxonomy_id AND p.post_status = 'publish' AND p.post_type = 'mlsimport_property' ) WHERE tt.term_taxonomy_id IN ({$placeholders})",
					$ttids
				)
			);

			// Drop the stale term cache (counts + relationships) so a persistent object
			// cache doesn't keep serving the old numbers after the DB is corrected.
			clean_term_cache( $term_ids );
		}

		self::delete( $post_id );
	}
}
