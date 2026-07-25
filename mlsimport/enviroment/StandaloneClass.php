<?php
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
 * flat-table row, using the §9 routing map and the derivation helpers. The flat-table
 * upsert happens here (this method has $property), not correlationUpdateAfter (ADR-0002).
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
	 * Agent CPT slug.
	 *
	 * @return string
	 */
	public function get_agent_post_type() {
		return 'mlsimport_agent';
	}

	/**
	 * Featured-image hook (no extra work needed for standalone).
	 *
	 * @param int $property_id Post ID.
	 * @param int $attach_id   Attachment ID.
	 * @return void
	 */
	public function enviroment_image_save( $property_id, $attach_id ) {
	}

	/**
	 * Persist the gallery attachment IDs.
	 *
	 * @param int   $property_id      Post ID.
	 * @param array $post_attachments Attachment IDs.
	 * @return void
	 */
	public function enviroment_image_save_gallery( $property_id, $post_attachments ) {
		update_post_meta( $property_id, 'mlsimport_gallery', $post_attachments );
	}

	/**
	 * Route the raw RESO property into post meta / taxonomies / content and the
	 * mlsimport_listings row.
	 *
	 * @param int   $property_id Post ID.
	 * @param array $property    Pipeline property; reads $property['extra_meta'].
	 * @return array{property_history:string}
	 */
	public function mlsimportSaasSetExtraMeta( $property_id, $property ) {
		// Nothing to route without a RESO extra_meta array.
		if ( ! isset( $property['extra_meta'] ) || ! is_array( $property['extra_meta'] ) ) {
			return array( 'property_history' => '' );
		}

		// Raw RESO fields plus the accumulators used while routing them.
		$extra        = $property['extra_meta'];
		$terms_by_tax = array();
		$opted_in     = $this->opted_in_fields();
		$listing_key  = isset( $extra['ListingKey'] ) ? (string) $extra['ListingKey'] : '';
		$incoming_mod = isset( $extra['ModificationTimestamp'] ) ? (string) $extra['ModificationTimestamp'] : '';

		/** Fires before a property is written (or skipped). @since 6.3 */
		do_action( 'mlsimport_before_save_property', $property_id, $property );

		// Sole change signal (ADR-0002): skip the whole write when the stored row
		// is at least as fresh as the incoming ModificationTimestamp. The verdict is
		// filterable so add-ons can force or suppress a write.
		$skip = '' !== $listing_key && '' !== $incoming_mod && $this->is_unchanged( $listing_key, $incoming_mod );
		/** Filter whether to skip writing this property. @since 6.3 */
		if ( apply_filters( 'mlsimport_property_skip_write', $skip, $property_id, $incoming_mod, $property ) ) {
			return array( 'property_history' => 'skipped: not modified since last import' );
		}

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
					foreach ( ( $is_array ? $value : array( $value ) ) as $term ) {
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

		/** Fires after a property has been written. @since 6.3 */
		do_action( 'mlsimport_after_save_property', $property_id, $property );

		return array( 'property_history' => '' );
	}

	/**
	 * Whether the stored row is already at least as fresh as the incoming
	 * ModificationTimestamp. Compared via timestamps so RESO ISO-8601 and the
	 * stored DATETIME format interoperate. No existing row => not unchanged.
	 *
	 * @param string $listing_key  RESO ListingKey.
	 * @param string $incoming_mod Incoming ModificationTimestamp.
	 * @return bool
	 */
	private function is_unchanged( $listing_key, $incoming_mod ) {
		global $wpdb;

		// Look up the stored modification timestamp for this listing.
		$table = Mlsimport_Standalone_Table::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed prefixed table name; value binds via prepare().
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT modification_timestamp FROM {$table} WHERE listing_key = %s", $listing_key ) );

		// No stored row -> treat as changed (needs a write).
		if ( null === $stored ) {
			return false;
		}

		// Unchanged when the stored timestamp is at least as fresh as the incoming one.
		return strtotime( $stored ) >= strtotime( $incoming_mod );
	}

	/**
	 * The field-selector opt-in map (RESO field => 1/0). Passthrough meta is
	 * written only for fields the user opted in.
	 *
	 * @return array
	 */
	private function opted_in_fields() {
		// Read the field selector; return its opt-in map or an empty array.
		$selected = get_option( 'mlsimport_admin_fields_select' );
		return isset( $selected['mls-fields'] ) && is_array( $selected['mls-fields'] ) ? $selected['mls-fields'] : array();
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
	 * admin deletes (ADR-0002). Gated to mlsimport_property.
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
	 * Post-insert hook. The flat-table upsert is NOT done here (ADR-0002) — this
	 * runs without $property. Editorial taxonomies are never touched.
	 *
	 * Assigns the agent the user picked in the import task (mlsimport_item_agent,
	 * a mlsimport_agent post ID) to the property, and records whether the task
	 * opted to use the MLS feed's own listing agent instead. The display layer
	 * (mlsimport_property_agent) reads mlsimport_use_mls_agent to choose between
	 * the linked agent post and the feed's ListAgent* meta. Agents are never
	 * created from the feed here.
	 *
	 * @param string $is_insert           'yes' on insert.
	 * @param int    $property_id         Post ID.
	 * @param array  $global_extra_fields Carries 'use_mls_agent' (bool) from the task.
	 * @param mixed  $new_agent           Selected mlsimport_agent post ID, or empty.
	 * @return void
	 */
	public function correlationUpdateAfter( $is_insert, $property_id, $global_extra_fields, $new_agent ) {
		// Record whether the task chose to use the feed's own listing agent.
		$use_mls_agent = ! empty( $global_extra_fields['use_mls_agent'] );
		update_post_meta( $property_id, 'mlsimport_use_mls_agent', $use_mls_agent ? 1 : 0 );

		// Link the selected agent post to the property when one was picked.
		$agent_id = (int) $new_agent;
		if ( $agent_id > 0 ) {
			update_post_meta( $property_id, 'mlsimport_list_agent_id', $agent_id );
		}
	}

	/**
	 * Theme custom-fields hook — intentionally empty for standalone.
	 *
	 * The other adapters mirror the field selector into a theme-owned option here
	 * because their themes read labels from their own settings. Standalone has no
	 * such option: mlsimport_property_selected_fields() reads
	 * mlsimport_admin_fields_select directly at render time, so there is nothing to
	 * sync and no stale copy to keep in step.
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
