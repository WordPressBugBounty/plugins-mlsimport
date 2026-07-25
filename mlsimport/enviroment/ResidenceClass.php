<?php
/**
 * ResidenceClass — theme adapter for the WpResidence (WpEstate family) WordPress theme.
 *
 * One of the plugin's theme adapters (enviroment/ directory). Maps RESO-standard MLS
 * property data onto WpResidence's post type ('estate_property') and post-meta keys
 * (property_*, wpestate_*). Responsibilities mirror the other adapters: declare post
 * types, save the gallery, map selected "extra" MLS fields to post meta / taxonomy,
 * set hardcoded defaults on newly inserted properties, and sync selected MLS fields
 * into the theme's custom-fields registry.
 *
 * @package MLSImport
 */
// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Description of ResidenceClass
 *
 * @author mlsimport
 */
class ResidenceClass {

	/**
	 * Constructor — no setup required for this adapter.
	 */
	public function __construct() {
      
	}


	/**
	 * return custom post field
	 *
	 * The post type WpResidence uses to store property listings.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @return   string  The 'estate_property' post-type slug.
	 */
	public function get_property_post_type() {
		// WpResidence stores listings in the 'estate_property' post type.
		return 'estate_property';
	}
	


	/**
	 * return custom post field
	 *
	 * The post type(s) WpResidence uses for agents, agencies, and developers.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @return   array  The agent/agency/developer post-type slugs.
	 */
	public function get_agent_post_type() {
		// WpResidence splits contacts across three post types.
		return array('estate_agent','estate_agency','estate_developer');
	}


	/**
	 *  image save
	 *
	 * No-op for WpResidence: single featured/gallery images are handled elsewhere;
	 * the gallery is saved in one batch by enviroment_image_save_gallery().
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @param    int  $property_id  The property post ID.
	 * @param    int  $attach_id    The image attachment ID (unused).
	 */
	public function enviroment_image_save( $property_id, $attach_id ) {
		// Intentionally does nothing for this theme.
		return;
	}


    
	/**
	 *  image save
	 *
	 * Save the full gallery for a property in the theme's gallery meta.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @param    int    $property_id       The property post ID.
	 * @param    array  $post_attachments  Ordered array of attachment IDs.
	 */
	public function enviroment_image_save_gallery( $property_id, $post_attachments ) {
        // NB: $gallery_meta (CSV form) is built but the array is what gets stored.
        $gallery_meta = implode(',', $post_attachments);
        // WpResidence reads the gallery from the wpestate_property_gallery meta.
        update_post_meta($property_id, 'wpestate_property_gallery', $post_attachments);

	}





        /**
         * Format extra meta values into a safe string representation.
         *
         * Collapses arrays (JSON-encoding nested arrays, trimming scalars) into a
         * comma-separated string; scalars are trimmed with comma spacing normalized.
         *
         * @param  mixed  $meta_value  Raw meta value (scalar or array).
         * @return string  Comma-separated, normalized string form.
         */
        private function normalizeExtraMetaValue($meta_value) {
                // Array values are flattened into a comma-separated list.
                if (is_array($meta_value)) {
                        $normalized = array();

                        // Walk each element, encoding nested arrays and trimming scalars.
                        foreach ($meta_value as $value) {
                                if (is_array($value)) {
                                        // Nested array: prefer wp_json_encode, fall back to json_encode.
                                        if (function_exists('wp_json_encode')) {
                                                $encoded = wp_json_encode($value);
                                        } else {
                                                $encoded = json_encode($value);
                                        }

                                        // Keep only a successful encoding.
                                        if (false !== $encoded && null !== $encoded) {
                                                $normalized[] = $encoded;
                                        }
                                } else {
                                        // Scalar: trim and skip empties.
                                        $value = trim((string) $value);
                                        if ('' !== $value) {
                                                $normalized[] = $value;
                                        }
                                }
                        }

                        // Nothing usable collected -> empty string.
                        if (empty($normalized)) {
                                return '';
                        }

                        // Join collected pieces with commas.
                        return implode(', ', $normalized);
                }

                // Scalar input: trim and normalize whitespace around commas.
                return preg_replace('/\s*,\s*/', ', ', trim((string) $meta_value));
        }

       /**
        * Format Rooms extra meta entries into a single string.
        *
        * Turns the RESO "Rooms" collection into a pipe-separated human-readable summary
        * (e.g. "Kitchen: Main, 10 x 12 ft | Bedroom: Upper").
        *
        * @param  array  $rooms        Array of room detail arrays (RESO Rooms structure).
        * @param  int    $property_id  Property post ID (unused; kept for signature parity).
        * @return string  Pipe-separated room summaries, or '' when nothing usable.
        */
       private function formatRoomsExtraMeta($rooms, $property_id) {
               // Guard: nothing to format.
               if (!is_array($rooms) || empty($rooms)) {
                       return '';
               }


               $formatted_rooms = array();

               // Build a summary line for each room entry.
               foreach ($rooms as $index => $room_details) {
                       // Skip malformed (non-array) entries.
                       if (!is_array($room_details)) {
                               continue;
                       }

                       // Pull the RESO room fields, trimming each to a string.
                       $room_type  = isset($room_details['RoomType']) ? trim((string) $room_details['RoomType']) : '';
                       $room_level = isset($room_details['RoomLevel']) ? trim((string) $room_details['RoomLevel']) : '';
                       $room_length = isset($room_details['RoomLength']) ? trim((string) $room_details['RoomLength']) : '';
                       $room_width = isset($room_details['RoomWidth']) ? trim((string) $room_details['RoomWidth']) : '';
                       $room_units = isset($room_details['RoomLengthWidthUnits']) ? trim((string) $room_details['RoomLengthWidthUnits']) : '';

                       // A room with no type is not worth listing.
                       if ($room_type === '') {
                               continue;
                       }

                       $details = array();
                       // Include the floor/level when present.
                       if ($room_level !== '') {
                               $details[] = $room_level;
                       }

                       // Compose the dimension string from length and/or width.
                       $dimension = '';
                       if ($room_length !== '' && $room_width !== '') {
                               $dimension = $room_length . ' x ' . $room_width;
                       } elseif ($room_length !== '') {
                               $dimension = $room_length;
                       } elseif ($room_width !== '') {
                               $dimension = $room_width;
                       }

                       // Append units to the dimension, or use units alone if no dimension.
                       if ($dimension !== '') {
                               if ($room_units !== '') {
                                       $dimension .= ' ' . $room_units;
                               }
                               $details[] = $dimension;
                       } elseif ($room_units !== '') {
                               $details[] = $room_units;
                       }

                       // Start with the room type, then append its details after a colon.
                       $formatted_value = $room_type;
                       if (!empty($details)) {
                               $formatted_value .= ': ' . implode(', ', $details);
                       }

                       $formatted_rooms[] = $formatted_value;
               }

               // Join all room summaries with a pipe separator.
               $result = implode(' | ', $formatted_rooms);

               return $result;
       }


        /**
         * Deal with extra meta
         */


        /**
         * Deal with extra meta
         *
         * Iterates the property's selected RESO "extra" fields in memory-bounded batches,
         * routing each to a mapped custom post meta key, a mapped taxonomy term, or a
         * lowercased-name post meta. The RESO "Rooms" collection is flattened into one
         * meta. Returns an HTML history log (capped to conserve memory).
         *
         * @param  int    $property_id  The property post ID.
         * @param  array  $property     Parsed property payload (expects 'extra_meta').
         * @return array  ['property_history' => string] HTML log of what was written.
         */
        public function mlsimportSaasSetExtraMeta($property_id, $property) {
        // Memory tracking
        // Record starting memory for the diagnostic diff at the end.
        $startMemory = memory_get_usage(true);
        $startMemoryFormatted = round($startMemory / 1048576, 2);

        $property_history = array();
        $answer = array();

        // Nothing to do without an extra_meta array -> return empty history.
        if (!isset($property['extra_meta']) || !is_array($property['extra_meta'])) {
            $answer['property_history'] = '';

            $endMemory = memory_get_usage(true);
            $endMemoryFormatted = round($endMemory / 1048576, 2);
            $memoryDiff = $endMemoryFormatted - $startMemoryFormatted;

            return $answer;
        }
        
        // Get options once and extract only what we need
        // Field-selection options decide which extra fields are imported and how.
        $options = get_option('mlsimport_admin_fields_select');
        $permited_meta = isset($options['mls-fields']) ? $options['mls-fields'] : array();
        
        // Process meta in smaller batches
        // Chunk the field keys so each batch can be GC'd, bounding peak memory.
        $meta_batches = array_chunk(array_keys($property['extra_meta']), 10, true);
        
        $batch_count = 0;
        // Process each batch of up to 10 field keys.
        foreach ($meta_batches as $meta_batch_keys) {
            $batch_count++;

            $batchMemory = memory_get_usage(true);
            $batchMemoryFormatted = round($batchMemory / 1048576, 2);

            // Handle each field in the current batch.
            foreach ($meta_batch_keys as $meta_name) {
                // Skip if meta doesn't exist in property extra_meta
                if (!isset($property['extra_meta'][$meta_name])) {
                    continue;
                }

                $meta_value = $property['extra_meta'][$meta_name];

                // Check if extra meta is set to import
                if (!isset($permited_meta[$meta_name])) {
                    // We do not have the extra meta
                    continue;
                } elseif (isset($permited_meta[$meta_name]) && intval($permited_meta[$meta_name]) === 0) {
                    // Meta exists but is set to no
                    continue;
                }

                // Special-case the RESO "Rooms" collection -> single formatted meta.
                if ('Rooms' === $meta_name && is_array($meta_value)) {
                    $formatted_rooms = $this->formatRoomsExtraMeta($meta_value, $property_id);
                    if ($formatted_rooms !== '') {
                        update_post_meta($property_id, 'rooms', $formatted_rooms);
                        $property_history[] = 'Updated EXTRA Meta rooms';
                    }
                    continue;
                }

                // Normalize the raw value to a storable string.
                $normalized_meta_value = $this->normalizeExtraMetaValue($meta_value);

                $original_meta_name = $meta_name;
                $meta_name_lower = strtolower($meta_name);
                
                // Process custom postmeta mapping
                // Route 1: field mapped to an explicit custom post meta key.
                if (isset($options['mls-fields-map-postmeta'][$original_meta_name]) && $options['mls-fields-map-postmeta'][$original_meta_name] !== '') {
                    $new_post_meta_key = $options['mls-fields-map-postmeta'][$original_meta_name];

                    update_post_meta($property_id, $new_post_meta_key, $normalized_meta_value);
                    $property_history[] = 'Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_name;
                }
                // Process custom taxonomy mapping
                // Route 2: field mapped to a taxonomy -> attach a labeled term.
                else if (isset($options['mls-fields-map-taxonomy'][$original_meta_name]) && $options['mls-fields-map-taxonomy'][$original_meta_name] !== '') {
                    $new_taxonomy = $options['mls-fields-map-taxonomy'][$original_meta_name];
                    $custom_label = isset($options['mls-fields-label'][$original_meta_name]) ? $options['mls-fields-label'][$original_meta_name] : '';

                    // A label of 'none' means "no prefix".
                    if ($custom_label == 'none') {
                        $custom_label = '';
                    }

                    // Build the term name from optional label + value; skip if empty.
                    $meta_value_with_label = array();
                    $term_value = trim($custom_label . ' ' . $normalized_meta_value);
                    if ($term_value !== '') {
                        $meta_value_with_label[] = $term_value;
                    }

                    if (!empty($meta_value_with_label)) {
                        // Append (append=true) the term to the property.
                        wp_set_object_terms($property_id, $meta_value_with_label, $new_taxonomy, true);

                        // Fix the clean_term_cache call to avoid SQL errors
                        // Resolve created/matched term IDs before clearing their cache.
                        $term_ids = array();
                        foreach ($meta_value_with_label as $term_name) {
                            $term = get_term_by('name', $term_name, $new_taxonomy);
                            if ($term && !is_wp_error($term)) {
                                $term_ids[] = $term->term_id;
                            }
                        }

                        // Clear cache by term IDs (not the property ID).
                        if (!empty($term_ids)) {
                            clean_term_cache($term_ids, $new_taxonomy);
                        }
                    }

                    $property_history[] = 'Updated CUSTOM TAX: ' . $new_taxonomy . ' original ' . $original_meta_name;
                }
                // Standard meta update
                // Route 3: no mapping -> store under the lowercased field name.
                else {
                    update_post_meta($property_id, $meta_name_lower, $normalized_meta_value);
                    $property_history[] = 'Updated EXTRA Meta ' . $meta_name_lower;
                }

                // Clear each variable after use
                $meta_value = null;
                $normalized_meta_value = null;
            }
            
            // Clean up after each batch
            // Flush caches and force GC between batches to keep memory flat.
            wp_cache_flush();
            gc_collect_cycles();
            
            $afterBatchMemory = memory_get_usage(true);
            $afterBatchMemoryFormatted = round($afterBatchMemory / 1048576, 2);
            $batchMemoryDiff = $afterBatchMemoryFormatted - $batchMemoryFormatted;
        }
        
        // Remove unused code that was referencing undefined variables
        // (The processed_field_values code wasn't being used)
        
        // Prepare the answer with limited history to conserve memory
        // Cap the history to the last 20 entries to bound the returned string.
        if (count($property_history) > 20) {
            $property_history = array_slice($property_history, -20);
            $property_history[] = '... [truncated history to save memory] ...';
        }

        // Join the history entries into a single HTML string.
        $answer['property_history'] = implode('</br>', $property_history);
        
        // Clean up variables
        $property_history = null;
        $options = null;
        $permited_meta = null;
        
        // Final memory tracking
        $endMemory = memory_get_usage(true);
        $endMemoryFormatted = round($endMemory / 1048576, 2);
        $memoryDiff = $endMemoryFormatted - $startMemoryFormatted;
        
        return $answer;
    }





	/**
	 * set hardcode fields after updated
	 *
	 * On first insert, seed WpResidence's default meta (featured flag, map zoom,
	 * country, assigned agent, and various "global" layout/sidebar settings), then
	 * refresh the theme's hidden-address value for the property.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @param    string  $is_insert            'yes' when the property was newly inserted.
	 * @param    int     $property_id          The property post ID.
	 * @param    array   $global_extra_fields  Extra fields (unused here).
	 * @param    mixed   $new_agent            Agent ID to assign to the property.
	 */
	public function correlationUpdateAfter( $is_insert, $property_id, $global_extra_fields, $new_agent ) {
		// Only seed defaults for freshly inserted properties.
		if ( 'yes' === $is_insert   ) {
			// Not featured; default map zoom; default country.
			update_post_meta( $property_id, 'prop_featured', 0 );
			update_post_meta( $property_id, 'page_custom_zoom', 16 );
			update_post_meta( $property_id, 'property_country', 'United States' );

			// Assign the agent and reset the per-page design override.
			update_post_meta( $property_id, 'property_agent', $new_agent );
			update_post_meta( $property_id, 'property_page_desing_local', '' );
			// Layout/header/search/sidebar settings all inherit the theme "global" default.
			update_post_meta( $property_id, 'header_transparent', 'global' );
			update_post_meta( $property_id, 'page_show_adv_search', 'global' );
			update_post_meta( $property_id, 'page_show_adv_search', 'global' );
			update_post_meta( $property_id, 'header_type', 0 );
			update_post_meta( $property_id, 'sidebar_agent_option', 'global' );
			update_post_meta( $property_id, 'local_pgpr_slider_type', 'global' );
			update_post_meta( $property_id, 'local_pgpr_content_type', 'global' );
			update_post_meta( $property_id, 'sidebar_select', 'global' );
			update_post_meta( $property_id, 'sidebar_option', 'global' );

			// Let the theme recompute the hidden/approximate address if available.
			if ( function_exists( 'wpestate_update_hiddent_address_single' ) ) {
				wpestate_update_hiddent_address_single( $property_id );
			}
		}
	}




	/**
	 * save custom fields per environment
	 *
	 * Syncs the plugin's selected MLS fields into WpResidence's custom-fields registry
	 * (stored in 'wpresidence_admin' -> 'wpestate_custom_fields_list'). Enabled,
	 * non-admin, non-taxonomy fields are added/updated; others removed; list re-sorted.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @param    string  $option_name  Option prefix used to read '{prefix}_admin_fields_select'.
	 */
        public function enviroment_custom_fields( $option_name ) {
                // Load the theme option holding the custom-fields registry.
                $theme_options = get_option( 'wpresidence_admin' );
                $custom_fields = array();
                if ( isset( $theme_options['wpestate_custom_fields_list'] ) ) {
                        $custom_fields = $theme_options['wpestate_custom_fields_list'];
                }

                // Ensure we have an array to work with.
                if ( ! is_array( $custom_fields ) ) {
                        $custom_fields = array();
                }

                // Guarantee every parallel column exists as an array.
                foreach ( array( 'add_field_name', 'add_field_label', 'add_field_type', 'add_field_order', 'add_dropdown_order' ) as $field_key ) {
                        if ( ! isset( $custom_fields[ $field_key ] ) || ! is_array( $custom_fields[ $field_key ] ) ) {
                                $custom_fields[ $field_key ] = array();
                        }
                }

                // Load the plugin's field-selection options for this prefix.
                $options = get_option( $option_name . '_admin_fields_select' );

                // Reconcile each MLS field against the theme registry.
                foreach ( $options['mls-fields'] as $key => $value ) {
                        // Per-field flags: enabled, admin-managed, taxonomy target, order.
                        $import   = intval( $value );
                        $admin    = isset( $options['mls-fields-admin'][ $key ] ) ? intval( $options['mls-fields-admin'][ $key ] ) : 0;
                        $taxonomy = isset( $options['mls-fields-map-taxonomy'][ $key ] ) ? $options['mls-fields-map-taxonomy'][ $key ] : '';
                        $order_value = isset( $options['field_order'][ $key ] ) ? intval( $options['field_order'][ $key ] ) + 100 : 100;

                        // Add/update only enabled, non-admin, non-taxonomy fields.
                        if ( 1 === $import && 0 === $admin && '' === $taxonomy ) {
                                // Is this field already registered?
                                $existing_index = array_search( $key, $custom_fields['add_field_name'], true );

                                if ( false === $existing_index ) {
                                        // New field: append across all parallel columns.
                                        $custom_fields['add_field_name'][]     = $key;
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $custom_fields['add_field_label'][]    = $label;
                                        $custom_fields['add_field_type'][]     = 'short text';
                                        $custom_fields['add_field_order'][]    = $order_value;
                                        $custom_fields['add_dropdown_order'][] = '';
                                } else {
                                        // Existing field: refresh its label and order in place.
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $custom_fields['add_field_label'][ $existing_index ] = $label;
                                        $custom_fields['add_field_order'][ $existing_index ] = $order_value;
                                }
                        } else {
                                // Not eligible: remove it from every parallel column if present.
                                $remove_index = array_search( $key, $custom_fields['add_field_name'], true );
                                if ( false !== $remove_index ) {
                                        unset( $custom_fields['add_field_name'][ $remove_index ] );
                                        unset( $custom_fields['add_field_label'][ $remove_index ] );
                                        unset( $custom_fields['add_field_type'][ $remove_index ] );
                                        unset( $custom_fields['add_field_order'][ $remove_index ] );
                                        unset( $custom_fields['add_dropdown_order'][ $remove_index ] );
                                }
                        }
                }

                // Re-sort all columns by field order so the registry stays ordered.
                if ( ! empty( $custom_fields['add_field_order'] ) ) {
                        // Sort the order column, preserving keys as the new sequence.
                        asort( $custom_fields['add_field_order'] );
                        $ordered = array(
                                'add_field_name'     => array(),
                                'add_field_label'    => array(),
                                'add_field_type'     => array(),
                                'add_field_order'    => array(),
                                'add_dropdown_order' => array(),
                        );
                        // Rebuild each column following the sorted order of indices.
                        foreach ( array_keys( $custom_fields['add_field_order'] ) as $idx ) {
                                $ordered['add_field_name'][]     = $custom_fields['add_field_name'][ $idx ];
                                $ordered['add_field_label'][]    = $custom_fields['add_field_label'][ $idx ];
                                $ordered['add_field_type'][]     = $custom_fields['add_field_type'][ $idx ];
                                $ordered['add_field_order'][]    = $custom_fields['add_field_order'][ $idx ];
                                $ordered['add_dropdown_order'][] = $custom_fields['add_dropdown_order'][ $idx ];
                        }
                        $custom_fields = $ordered;
                }

                // Persist the updated registry back to the theme option.
                $theme_options['wpestate_custom_fields_list'] = $custom_fields;
                update_option( 'wpresidence_admin', $theme_options );

        }






	/**
	 * return theme schema
	 *
	 * No-op placeholder: WpResidence provides no field schema through this adapter.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function return_theme_schema() {
		// No schema to return for this theme.
		return;
	}


    
}
