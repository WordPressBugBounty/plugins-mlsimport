<?php
/**
 * Standalone Stored mode theme adapter.
 *
 * Stored Listing Write owns shared listing decisions and persistence. This
 * adapter retains the Standalone post type, gallery representation, RESO route
 * map, taxonomies, derived flat-table row, content filters, and agent linkage.
 * Its projection intentionally receives the raw RESO property at write time.
 *
 * @package MLSImport
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pull in the standalone helpers this adapter delegates to: the §9 RESO routing map,
// the derivation helpers, and the flat-table schema + row read/write classes.
require_once __DIR__ . '/../includes/standalone/class-mlsimport-standalone-reso-map.php';
require_once __DIR__ . '/../includes/standalone/class-mlsimport-standalone-derive.php';
require_once __DIR__ . '/../includes/standalone/class-mlsimport-standalone-table.php';
require_once __DIR__ . '/../includes/standalone/class-mlsimport-standalone-row.php';

/**
 * Standalone (theme_id 990) write adapter.
 *
 * Implements the same adapter contract as ResidenceClass, but RESO-anchored: it
 * routes a raw RESO property ($property['extra_meta'], PascalCase) into the
 * mlsimport_property post (meta + 9 taxonomies + content) and the mlsimport_listings
 * flat-table row, using the §9 routing map and the derivation helpers. The raw
 * property and flat-table upsert remain together at this write point (ADR-0017).
 */
class StandaloneClass {

	/**
	 * Property CPT slug.
	 *
	 * @return string
	 */
	public function get_property_post_type() {
		return 'mlsimport_property';
	}

	/**
	 * Return the Standalone post type used for Managed Listing lookup/write.
	 *
	 * @return string Standalone Managed Listing post-type slug.
	 */
	public function property_post_type(): string {
		return 'mlsimport_property';
	}

	/**
	 * Agent CPT slug.
	 *
	 * @return string
	 */
	public function get_agent_post_type() {
		return 'mlsimport_agent';
	}

		/**
	 * Persist the gallery attachment IDs.
	 *
	 * @param int   $property_id      Post ID.
	 * @param array $post_attachments Attachment IDs.
	 * @return void
	 */
	public function write_gallery( int $property_id, array $post_attachments ): bool {
		update_post_meta( $property_id, 'mlsimport_gallery', $post_attachments );
		return true;
	}

	/**
	 * Route the raw RESO property into post meta / taxonomies / content and the
	 * mlsimport_listings row.
	 *
	 * @param int   $property_id Post ID.
	 * @param array $property    Pipeline property; reads $property['extra_meta'].
	 * @param array $context Prepared fields and creation/agent choices.
	 * @return bool Whether the standalone projection completed.
	 */
	public function write_theme_projection( int $property_id, array $property, array $context ): bool {
		// Nothing to route without a RESO extra_meta array.
		if ( ! isset( $property['extra_meta'] ) || ! is_array( $property['extra_meta'] ) ) {
			return true;
		}

		// Raw RESO fields plus the accumulators used while routing them.
		$extra        = $property['extra_meta'];
		$terms_by_tax = array();
		$field_configuration = is_array( $context['field_configuration'] ?? null ) ? $context['field_configuration'] : array();
		$opted_in            = is_array( $field_configuration['mls-fields'] ?? null ) ? $field_configuration['mls-fields'] : array();
		$listing_key         = isset( $extra['ListingKey'] ) ? (string) $extra['ListingKey'] : (string) ( $property['ListingKey'] ?? '' );

		// Route every incoming RESO field to its configured target(s).
		foreach ( $extra as $field => $value ) {
			$is_array = is_array( $value );

			// A field may map to multiple targets (meta, tax, feature...).
			foreach ( Mlsimport_Standalone_Reso_Map::targets_for( $field ) as $target ) {
				// 'skip'/'content' are handled elsewhere (or not at all) here.
				if ( 'skip' === $target || 'content' === $target ) {
					continue;
				}

				// Target format is "<kind>:<name>" (e.g. "meta:x_foo", "tax:mlsimport_view").
				$parts = explode( ':', $target, 2 );
				$kind  = $parts[0];
				$name  = isset( $parts[1] ) ? $parts[1] : '';

				// Target kind 1: post meta.
				if ( 'meta' === $kind ) {
					// Meta key is namespaced; an "x_" name marks a passthrough field.
					$meta_key       = 'mlsimport_' . $name;
					$is_passthrough = 0 === strpos( $name, 'x_' );
					$raw            = $value;

					if ( $is_array ) {
						// A multi-value RESO field (Flooring, Cooling, PoolFeatures...)
						// stores as a comma-joined string, mapped or passthrough alike —
						// that string is what the property page prints as the field's row.
						// Arrays of records (Rooms, Media) have no scalar members and so
						// store nothing.
						$raw = implode( ', ', array_filter( $value, 'is_scalar' ) );
						if ( '' === $raw ) {
							continue;
						}
					}

					/** Filter a property meta value before write. @since 6.3 */
					$meta_value = apply_filters( 'mlsimport_property_meta_value', $raw, $meta_key, $property_id, $property );

					if ( $is_passthrough ) {
						// Passthrough: write only when the field is opted in via the selector.
						if ( isset( $opted_in[ $field ] ) && 1 === intval( $opted_in[ $field ] ) ) {
							update_post_meta( $property_id, $meta_key, $meta_value );
						}
						continue;
					}
					update_post_meta( $property_id, $meta_key, $meta_value );
				// Target kind 2: taxonomy terms.
				} elseif ( 'tax' === $kind ) {
					// Multi-enum arrays (Appliances, View...) -> one term per value.
					// Some feeds send the multi-enum as ONE comma-glued string; split
					// it so each value still becomes its own term, never a glued term
					// whose sanitized slug is a dead-end archive (fix #290).
					foreach ( ( $is_array ? $value : array_map( 'trim', explode( ',', (string) $value ) ) ) as $term ) {
						// Collect non-empty term names under their taxonomy.
						if ( '' !== (string) $term ) {
							$terms_by_tax[ $name ][] = (string) $term;
						}
					}
				// Target kind 3: boolean "feature" flag -> label term when true.
				} elseif ( 'feature' === $kind && ! $is_array && $this->is_truthy( $value ) ) {
					// feature:<Label> -> add the label term only when the YN is true.
					/** Filter the feature label term. @since 6.3 */
					$terms_by_tax['mlsimport_feature'][] = (string) apply_filters( 'mlsimport_property_feature_label', $name, $field, $value );
				}
			}
		}

		// Flat-table columns (§9 column targets + derivations) — shared with reindex.
		$row = Mlsimport_Standalone_Row::build_columns( $extra );

		// VirtualTourURLUnbranded arrives as an <iframe> blob -> store the clean URL.
		if ( isset( $extra['VirtualTourURLUnbranded'] ) ) {
			$tour = Mlsimport_Standalone_Derive::extract_tour_src( (string) $extra['VirtualTourURLUnbranded'] );
			// Only store when a src URL was successfully extracted.
			if ( null !== $tour ) {
				update_post_meta( $property_id, 'mlsimport_virtual_tour', $tour );
			}
		}

		// Write the accumulated terms, one taxonomy at a time (replace, not append).
		foreach ( $terms_by_tax as $taxonomy => $terms ) {
			wp_set_object_terms( $property_id, $terms, $taxonomy, false );
		}

		// PublicRemarks arrives promoted to the top level (theme_id 990) -> post body.
		// MLS feeds send remarks as one unbroken block, so break it into readable
		// paragraphs before write; add-ons can still override via the filter.
		$remarks = Mlsimport_Standalone_Derive::paragraphs( isset( $property['content'] ) ? (string) $property['content'] : '' );
		/** Filter the post body before write. @since 6.3 */
		$content = (string) apply_filters( 'mlsimport_property_post_content', $remarks, $property );
		if ( '' !== $content ) {
			wp_update_post(
				array(
					'ID'           => $property_id,
					'post_content' => $content,
				)
			);
		}

		// FULLTEXT source: title + remarks + address (§8).
		// Combine the searchable pieces into the row's FULLTEXT column.
		$search_parts       = array(
			get_the_title( $property_id ),
			isset( $property['content'] ) ? (string) $property['content'] : '',
			isset( $property['adr_title'] ) ? (string) $property['adr_title'] : '',
			isset( $extra['UnparsedAddress'] ) ? (string) $extra['UnparsedAddress'] : '',
		);
		$row['search_text'] = trim( implode( ' ', array_filter( $search_parts ) ) );

		// Upsert the flat-table row only when we have a stable ListingKey.
		if ( '' !== $listing_key ) {
			Mlsimport_Standalone_Row::upsert( $property_id, $listing_key, $row );
		}

		// The display-source choice is live task configuration. Assigned Agent is
		// creation-time only, so updates never replace its stored relationship.
		update_post_meta( $property_id, 'mlsimport_use_mls_agent', ! empty( $context['use_mls_agent'] ) ? 1 : 0 );
		if ( ! empty( $context['is_new'] ) ) {
			$agent_id = (int) ( $context['assigned_agent_id'] ?? 0 );
			if ( $agent_id > 0 ) {
				update_post_meta( $property_id, 'mlsimport_list_agent_id', $agent_id );
			}
		}

		return true;
	}

	/**
	 * Whether a RESO boolean/YN value should count as true.
	 *
	 * @param mixed $value Raw YN value.
	 * @return bool
	 */
	private function is_truthy( $value ) {
		// A real boolean is returned as-is.
		if ( is_bool( $value ) ) {
			return $value;
		}
		// Otherwise accept common truthy string spellings.
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'y' ), true );
	}

	/**
	 * Removes the mlsimport_listings row when a property post is deleted
	 * (before_delete_post). Covers reconciliation, import-removal and manual
	 * admin deletes. Gated to mlsimport_property.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function cleanup_on_delete( $post_id ) {
		// Only act on our own property post type.
		if ( 'mlsimport_property' !== get_post_type( $post_id ) ) {
			return;
		}
		// Remove the associated flat-table row.
		Mlsimport_Standalone_Row::delete( (int) $post_id );
	}

	/**
	 * Drop the mlsimport_listings row when a property leaves the published state
	 * (trashed, drafted, set pending/private). before_delete_post only fires on a
	 * permanent delete, so this covers a manual or reconciliation trash that keeps
	 * the post around — keeping the index to published listings only.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public static function cleanup_on_status_change( $new_status, $old_status, $post ) {
		// Only act on our own property posts.
		if ( ! $post instanceof WP_Post || 'mlsimport_property' !== $post->post_type ) {
			return;
		}
		// Any non-published state drops the row (keeps the index published-only).
		if ( 'publish' !== $new_status ) {
			Mlsimport_Standalone_Row::delete( (int) $post->ID );
		}
	}

		/**
	 * Theme custom-fields hook — intentionally empty for standalone.
	 *
	 * The other adapters mirror the field selector into a theme-owned option here
	 * because their themes read labels from their own settings. Standalone has no
	 * such option: the standalone render layer reads the module's active projection
	 * at render time, so there is no copied option to synchronize or make stale.
	 *
	 * @param string $option_name Option name.
	 * @return void
	 */
	public function enviroment_custom_fields( $option_name ) {
	}

	/**
	 * Standalone sends theme_id 990, so there is no theme schema to return.
	 *
	 * @return string
	 */
	public function return_theme_schema() {
		return '';
	}
}
