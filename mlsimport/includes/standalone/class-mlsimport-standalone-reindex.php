<?php
/**
 * Standalone (theme_id 990) reindex (M4).
 *
 * Rebuilds the mlsimport_listings rows from each property's locally-stored data
 * (mlsimport_<ResoField> meta for meta-backed columns, the term in the matching
 * taxonomy for column+tax fields like City/Status), reusing the shared row
 * builder. The recovery path when enabling standalone on an existing install or
 * after a mapping-code change. Feature terms live on the post already and are
 * untouched. Exposed as `wp mlsimport reindex`.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-row.php';

/**
 * Rebuilds the standalone search index from post data.
 */
class Mlsimport_Standalone_Reindex {

	/**
	 * Column+tax fields carry their column value only in the taxonomy term;
	 * map the taxonomy back to its RESO field so build_columns can read it.
	 */
	private const TAX_FIELD = array(
		'mlsimport_city'          => 'City',
		'mlsimport_state'         => 'StateOrProvince',
		'mlsimport_property_type' => 'PropertySubType',
		'mlsimport_listing_type'  => 'PropertyType',
		'mlsimport_status'        => 'StandardStatus',
	);

	/**
	 * Rebuild the row for one property post. Returns false when it has no
	 * ListingKey (nothing to key the row on) or the post is not published (upsert
	 * drops its row), so a reindex also clears stale rows for trashed/draft posts.
	 *
	 * @param int $post_id Property post ID.
	 * @return bool
	 */
	public static function rebuild_post( $post_id ): bool {
		$fields      = self::fields_from_post( $post_id );
		$listing_key = isset( $fields['ListingKey'] ) ? (string) $fields['ListingKey'] : '';
		if ( '' === $listing_key ) {
			return false;
		}

		$row                = Mlsimport_Standalone_Row::build_columns( $fields );
		$row['search_text'] = self::search_text( $post_id, $fields );

		$written = Mlsimport_Standalone_Row::upsert( $post_id, $listing_key, $row );

		/** Fires after one property is reindexed. @since 6.3 */
		do_action( 'mlsimport_reindex_property', $post_id );
		return $written;
	}

	/**
	 * Rebuild every mlsimport_property row. Returns the number rebuilt.
	 *
	 * @return int
	 */
	public static function rebuild_all(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'mlsimport_property',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		// Rebuild each property's row, counting only the ones that actually wrote
		// (rebuild_post returns false for keyless / non-published posts).
		$count = 0;
		foreach ( $ids as $pid ) {
			if ( self::rebuild_post( (int) $pid ) ) {
				++$count;
			}
		}

		/** Fires after a full reindex completes. @since 6.3 */
		do_action( 'mlsimport_after_reindex', $count );
		return $count;
	}

	/**
	 * Reconstruct a RESO field => value map from a post's mlsimport_<ResoField>
	 * meta (PascalCase keys only) plus the column+tax taxonomy terms.
	 *
	 * @param int $post_id Property post ID.
	 * @return array
	 */
	private static function fields_from_post( $post_id ): array {
		$fields = array();

		// Walk every post meta row, keeping only the mlsimport_<ResoField> entries
		// whose suffix is a PascalCase RESO field name.
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			// Only our namespaced meta keys are candidates.
			if ( 0 !== strpos( $key, 'mlsimport_' ) ) {
				continue;
			}
			// Strip the "mlsimport_" prefix to recover the RESO field name.
			$reso = substr( $key, 10 );
			// PascalCase RESO field names only; semantic keys (idx_display,
			// virtual_tour, x_*, list_agent_id...) are lowercase and skipped.
			if ( '' === $reso || ! ctype_upper( $reso[0] ) ) {
				continue;
			}
			$fields[ $reso ] = maybe_unserialize( $values[0] );
		}

		// Column+tax fields store their value only as a taxonomy term; read the first
		// term back into the matching RESO field so build_columns can see it.
		foreach ( self::TAX_FIELD as $taxonomy => $reso_field ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$fields[ $reso_field ] = $terms[0];
			}
		}

		return $fields;
	}

	/**
	 * Rebuild the FULLTEXT source from title + body + address.
	 *
	 * @param int   $post_id Property post ID.
	 * @param array $fields  Reconstructed RESO fields.
	 * @return string
	 */
	private static function search_text( $post_id, array $fields ): string {
		$parts = array(
			get_the_title( $post_id ),
			(string) get_post_field( 'post_content', $post_id ),
			isset( $fields['UnparsedAddress'] ) ? (string) $fields['UnparsedAddress'] : '',
		);
		return trim( implode( ' ', array_filter( $parts ) ) );
	}
}
