<?php
/**
 * RealHomesClass — theme adapter for the "Real Homes" (Inspiry) WordPress theme.
 *
 * One of the plugin's theme adapters (see enviroment/ directory). Each adapter maps
 * RESO-standard MLS property data onto the post type + post-meta conventions of a
 * specific real-estate theme. This class targets Real Homes, whose property meta keys
 * are prefixed REAL_HOMES_* / inspiry_*.
 *
 * Responsibilities:
 *  - Declare the theme's property/agent post types.
 *  - Persist featured image and gallery attachments in the theme's expected meta.
 *  - Map imported "extra" MLS fields to custom post meta, taxonomies, or the theme's
 *    additional-details list, honoring the admin field-selection options.
 *  - Set hardcoded default meta on newly inserted properties.
 *  - Sync selected MLS fields into the theme's custom-fields registry.
 *  - Serve remote (MLS-hosted) images to the media library and image_downsize so
 *    thumbnails display without a local file.
 *
 * @package MLSImport
 */
// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Description of RealHomesClass
 *
 * @author mlsimport
 */
class RealHomesClass {

	/**
	 * Register the filters that make remote MLS images render like local attachments.
	 */
	public function __construct() {
				// Enable support for remote MLS images in media JS and thumbnails
		// Inject remote URL/sizes into the media library's JS attachment payload.
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'inject_remote_image_data' ], 20 );
		// Short-circuit image_downsize so wp_get_attachment_image() returns the remote URL.
		add_filter( 'image_downsize', [ $this, 'override_image_downsize' ], 10, 3 );
	}


	/**
	 * return custom post field
	 *
	 * The post type Real Homes uses to store property listings.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @return   string  The 'property' post type slug.
	 */
	public function get_property_post_type() {
		// Real Homes stores listings in the 'property' post type.
		return 'property';
	}



	/**
	 * return custom post field
	 *
	 * The post type(s) Real Homes uses for agents/agencies.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @return   array  The 'agency' and 'agent' post-type slugs.
	 */
	public function get_agent_post_type() {
		// Real Homes represents contacts as both 'agency' and 'agent' post types.
		return array('agency','agent');
	}

		/**
		 *  image save
		 *
		 * Attach one gallery image to the property using the theme's gallery meta.
		 *
		 * @since    1.0.0
		 * @access   protected
		 * @var      string    $plugin_name
		 * @param    int  $property_id  The property post ID.
		 * @param    int  $attach_id    The image attachment ID.
		 */
	public function enviroment_image_save( $property_id, $attach_id ) {
		// Real Homes reads gallery images from repeated REAL_HOMES_property_images meta rows.
		add_post_meta( $property_id, 'REAL_HOMES_property_images', intval( $attach_id ) );
	}

    
	/**
	 *  gallery save
	 *
	 * No-op for Real Homes: gallery images are saved one-by-one via
	 * enviroment_image_save() rather than as a single batch, so this hook does nothing.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @param    int    $property_id       The property post ID.
	 * @param    array  $post_attachments  Attachment IDs (unused).
	 */
        public function enviroment_image_save_gallery( $property_id, $post_attachments ) {
      // Intentionally does nothing for this theme.
      return;
        }

        /**
         * Format extra meta values into a safe string representation.
         *
         * Collapses arrays (and nested arrays) into a comma-separated string; scalars are
         * trimmed and their comma spacing normalized.
         *
         * @param  mixed  $meta_value  Raw meta value (scalar or array).
         * @return string  Comma-separated, normalized string form.
         */
        private function normalizeExtraMetaValue($meta_value) {
                // Array values are flattened into a comma-separated list.
                if (is_array($meta_value)) {
                        $normalized = array();

                        // Walk each element, JSON-encoding nested arrays and trimming scalars.
                        foreach ($meta_value as $value) {
                                if (is_array($value)) {
                                        // Nested array: store its JSON encoding (wp_json_encode preferred).
                                        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);

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
         * Turns the RESO "Rooms" collection into a human-readable string like
         * "Kitchen: Main, 10 x 12 ft | Bedroom: Upper" for storage in a single meta field.
         *
         * @param  array  $rooms  Array of room detail arrays (RESO Rooms structure).
         * @return string  Pipe-separated room summaries, or '' when nothing usable.
         */
        private function formatRoomsExtraMeta($rooms) {
                // Guard: nothing to format.
                if (!is_array($rooms) || empty($rooms)) {
                        return '';
                }

                $formatted_rooms = array();

                // Build a summary line for each room entry.
                foreach ($rooms as $room_details) {
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
                return implode(' | ', $formatted_rooms);
        }

        /**
         * Deal with extra meta
         *
         * Persists the property's coordinate meta and iterates every selected RESO "extra"
         * field, routing each to one of: the theme's REAL_HOMES_property_location meta, a
         * mapped custom post meta key, a mapped taxonomy term, the theme's additional-details
         * list, or a lowercased-label post meta — according to the admin field-selection
         * options in 'mlsimport_admin_fields_select'.
         *
         * @param  int    $property_id  The property post ID.
         * @param  array  $property     Parsed property payload (meta, extra_meta, etc.).
         * @return array  Log strings: 'property_history' (HTML) and 'extra_meta_log' (plain).
         */
        public function mlsimportSaasSetExtraMeta( $property_id, $property ) {
                // Accumulators for the two log formats returned to the caller.
                $property_history = '';
                $extra_meta_log   = '';
                $answer           = array();
                $extra_fields     = array();
                // Admin's field-selection options drive which meta is imported and how.
                $options          = get_option( 'mlsimport_admin_fields_select' );
                if ( ! is_array( $options ) ) {
                        $options = array();
                }
                // Map of field-name => enabled flag, and the desired display order.
                $permited_meta = isset( $options['mls-fields'] ) && is_array( $options['mls-fields'] ) ? $options['mls-fields'] : array();
                $field_order   = isset( $options['field_order'] ) && is_array( $options['field_order'] ) ? $options['field_order'] : array();

                // Store lat/long as a single "lat,long" string in the theme's location meta.
                if ( isset( $property['meta']['property_longitude'] ) && isset( $property['meta']['property_latitude'] ) ) {
                        $savingx = $property['meta']['property_latitude'] . ',' . $property['meta']['property_longitude'];
                        update_post_meta( $property_id, 'REAL_HOMES_property_location', $savingx );
                        $property_history .= 'Update Coordinates Meta with ' . $savingx . '</br>';
                        $extra_meta_log   .= 'Property with ID ' . $property_id . '  Update Coordinates Meta with ' . $savingx . PHP_EOL;
                }

                // Process each extra MLS field carried on the property payload.
                if ( isset( $property['extra_meta'] ) && is_array( $property['extra_meta'] ) ) {
                        $meta_properties = $property['extra_meta'];

                        foreach ( $meta_properties as $meta_name => $meta_value ) {
                                // Skip fields not present in the selection map.
                                if ( ! isset( $permited_meta[ $meta_name ] ) ) {
                                        continue;
                                // Skip fields explicitly disabled (flag === 0).
                                } elseif ( isset( $permited_meta[ $meta_name ] ) && intval( $permited_meta[ $meta_name ] ) === 0 ) {
                                        continue;
                                }

                                // Special-case the RESO "Rooms" collection -> single formatted meta.
                                if ( 'Rooms' === $meta_name && is_array( $meta_value ) ) {
                                        // Flatten the rooms collection and store it in the 'rooms' meta.
                                        $formatted_rooms = $this->formatRoomsExtraMeta( $meta_value );
                                        if ( '' !== $formatted_rooms ) {
                                                update_post_meta( $property_id, 'rooms', $formatted_rooms );
                                                $property_history .= 'Updated EXTRA Meta rooms</br>';
                                                $extra_meta_log   .= 'Property with ID ' . $property_id . '  Update EXTRA Meta rooms with value ' . $formatted_rooms . PHP_EOL;
                                        }
                                        continue;
                                }

                                // Normalize the value and resolve the display label for this field.
                                $meta_value = $this->normalizeExtraMetaValue( $meta_value );
                                $orignal_meta_name = $meta_name;
                                $feature_label = isset( $options['mls-fields-label'][ $meta_name ] ) && '' !== $options['mls-fields-label'][ $meta_name ] ? $options['mls-fields-label'][ $meta_name ] : $meta_name;

                                // Route 1: field mapped to an explicit custom post meta key.
                                if ( isset( $options['mls-fields-map-postmeta'][ $orignal_meta_name ] ) && '' !== $options['mls-fields-map-postmeta'][ $orignal_meta_name ] ) {
                                        $new_post_meta_key = $options['mls-fields-map-postmeta'][ $orignal_meta_name ];
                                        update_post_meta( $property_id, $new_post_meta_key, $meta_value );
                                        $property_history .= 'Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_name . ' and value ' . $meta_value . '</br>';
                                // Route 2: field mapped to a taxonomy -> attach a labeled term.
                                } elseif ( isset( $options['mls-fields-map-taxonomy'][ $orignal_meta_name ] ) && '' !== $options['mls-fields-map-taxonomy'][ $orignal_meta_name ] ) {
                                        $new_taxonomy = $options['mls-fields-map-taxonomy'][ $orignal_meta_name ];
                                        $custom_label = $options['mls-fields-label'][ $orignal_meta_name ];
                                        // Prefix the value with its custom label to form the term name.
                                        $meta_value_with_label = array( trim( $custom_label . ' ' . $meta_value ) );

                                        // Append (append=true) the term and refresh the term cache.
                                        wp_set_object_terms( $property_id, $meta_value_with_label, $new_taxonomy, true );
                                        clean_term_cache( $property_id, $new_taxonomy );

                                        $property_history .= 'Updated CUSTOM TAX: ' . $new_taxonomy . '<-- original ' . $orignal_meta_name . '/' . $meta_name . '/' . $custom_label . ' and value ' . json_encode( $meta_value_with_label );
                                // Route 3: no explicit mapping -> additional-details list or plain meta.
                                } else {
                                        // Only when the value is non-empty and the field is enabled.
                                        if (
                                                '' !== $meta_value &&
                                                isset( $options['mls-fields'][ $meta_name ] ) &&
                                                1 === intval( $options['mls-fields'][ $meta_name ] )
                                        ) {
                                                // admin flag 0 -> collect for the ordered additional-details list.
                                                if (
                                                        isset( $options['mls-fields-admin'][ $meta_name ] ) &&
                                                        0 === intval( $options['mls-fields-admin'][ $meta_name ] )
                                                ) {
                                                        // Resolve the sort order: direct key, else index lookup, else 9999.
                                                        if ( isset( $field_order[ $orignal_meta_name ] ) ) {
                                                                $order = intval( $field_order[ $orignal_meta_name ] );
                                                        } else {
                                                                $index = array_search( $orignal_meta_name, $field_order, true );
                                                                $order = ( false !== $index ) ? intval( $index ) : 9999;
                                                        }
                                                        // Buffer the field for later sorting/writing.
                                                        $extra_fields[] = array(
                                                                'label' => $feature_label,
                                                                'value' => $meta_value,
                                                                'order' => $order,
                                                        );
                                                // admin flag 1 -> store directly as lowercased-label meta.
                                                } elseif (
                                                        isset( $options['mls-fields-admin'][ $meta_name ] ) &&
                                                        1 === intval( $options['mls-fields-admin'][ $meta_name ] )
                                                ) {
                                                        update_post_meta( $property_id, strtolower( $feature_label ), $meta_value );
                                                }
                                        }
                                }

                                // Record this field in both log formats.
                                $property_history .= 'Updated EXTRA Meta ' . $meta_name . ' with label ' . $feature_label . ' and value ' . $meta_value . '</br>';
                                $extra_meta_log   .= 'Property with ID ' . $property_id . '  Update EXTRA Meta ' . $meta_name . ' with value ' . $meta_value . PHP_EOL;
                        }

                        // Sort buffered additional-detail fields by their resolved order.
                        usort(
                                $extra_fields,
                                function ( $a, $b ) {
                                        $orderA = isset( $a['order'] ) ? intval( $a['order'] ) : 9999;
                                        $orderB = isset( $b['order'] ) ? intval( $b['order'] ) : 9999;
                                        return $orderA <=> $orderB;
                                }
                        );
                        // Flatten to a label => value map in sorted order.
                        $ordered_extra_fields = array();
                        foreach ( $extra_fields as $field ) {
                                $ordered_extra_fields[ $field['label'] ] = $field['value'];
                        }

                        // Write the theme's additional-details meta (two keys the theme reads).
                        update_post_meta( $property_id, 'REAL_HOMES_additional_details', $ordered_extra_fields );
                        update_post_meta( $property_id, 'REAL_HOMES_additional_details_list', $ordered_extra_fields );

                        // Return the logs to the caller.
                        $answer['property_history'] = $property_history;
                        $answer['extra_meta_log']   = $extra_meta_log;
                }

                return $answer;
        }





	/**
	 * set hardcode fields after updated
	 *
	 * On first insert of a property, seed the theme's default meta (featured flag,
	 * agent display, assigned agent, map flag, label color, size/lot-size units).
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
		// Only run defaults for freshly inserted properties.
		if ( 'yes' ===  $is_insert ) {
			// Not featured by default.
			update_post_meta( $property_id, 'REAL_HOMES_featured', 0 );
			// Show the agent info box on the property page.
			update_post_meta( $property_id, 'REAL_HOMES_agent_display_option', 'agent_info' );

			$options_mls = get_option( 'mlsimport_admin_mls_sync' );
			// update_post_meta($property_id, 'REAL_HOMES_agents', $options_mls['property_agent']);
			// Assign the imported/default agent to the property.
			update_post_meta( $property_id, 'REAL_HOMES_agents', $new_agent );

			// Disable the per-property map by default.
			update_post_meta( $property_id, 'REAL_HOMES_property_map', 0 );
			// Default the listing label color.
			update_post_meta( $property_id, 'inspiry_property_label_color', '#fb641c' );

			// Default the floor-area unit to "Sq Ft" only if not already set.
			$fave_property_size_prefix = get_post_meta( $property_id, 'REAL_HOMES_property_size_postfix', true );
			if ( '' === $fave_property_size_prefix ) {
				update_post_meta( $property_id, 'REAL_HOMES_property_size_postfix', 'Sq Ft' );
			}

			// Default the lot-size unit to "Sq Ft" only if not already set.
			$fave_property_land_postfix = get_post_meta( $property_id, 'REAL_HOMES_property_lot_size_postfix', true );
			if ( '' === $fave_property_land_postfix  ) {
				update_post_meta( $property_id, 'REAL_HOMES_property_lot_size_postfix', 'Sq Ft' );
			}

			
		}
	}





        /**
         * save custom fields per environment
         *
         * Syncs the plugin's selected MLS fields into the theme's custom-fields registry
         * (stored in the 'wpresidence_admin' option as 'wpestate_custom_fields_list').
         * Enabled non-admin, non-taxonomy fields are added/updated; others are removed.
         * Finally the list is re-sorted by field order.
         *
         * @since    1.0.0
         * @access   protected
         * @var      string    $plugin_name
         * @param    string  $option_name  Option prefix used to read '{prefix}_admin_fields_select'.
         */
        public function enviroment_custom_fields( $option_name ) {
                // Load the theme option that holds the custom-fields registry.
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
                if ( ! is_array( $options ) ) {
                        $options = array();
                }
                // Extract the sub-maps that decide import/admin/taxonomy/order per field.
                $mls_fields       = isset( $options['mls-fields'] ) && is_array( $options['mls-fields'] ) ? $options['mls-fields'] : array();
                $mls_fields_admin = isset( $options['mls-fields-admin'] ) && is_array( $options['mls-fields-admin'] ) ? $options['mls-fields-admin'] : array();
                $mls_fields_tax   = isset( $options['mls-fields-map-taxonomy'] ) && is_array( $options['mls-fields-map-taxonomy'] ) ? $options['mls-fields-map-taxonomy'] : array();
                $field_order      = isset( $options['field_order'] ) && is_array( $options['field_order'] ) ? $options['field_order'] : array();

                // Reconcile each MLS field against the theme's custom-fields registry.
                foreach ( $mls_fields as $key => $value ) {
                        // Per-field flags: enabled, admin-managed, taxonomy target, order.
                        $import      = intval( $value );
                        $admin       = isset( $mls_fields_admin[ $key ] ) ? intval( $mls_fields_admin[ $key ] ) : 0;
                        $taxonomy    = isset( $mls_fields_tax[ $key ] ) ? $mls_fields_tax[ $key ] : '';
                        $order_value = isset( $field_order[ $key ] ) ? intval( $field_order[ $key ] ) + 100 : 100;

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
                        // Sort the order column, preserving its keys as the new sequence.
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
	 * No-op placeholder: Real Homes provides no field schema through this adapter.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function return_theme_schema() {
		// No schema to return for this theme.
		return;
	}

	/**
	 * Filter wp_prepare_attachment_for_js to inject remote image URL and fake sizes.
	 *
	 * This ensures Meta Box and the WordPress media library display thumbnails
	 * for images that are stored remotely (i.e., imported via MLS).
	 *
	 * @param array $response Attachment response data prepared for JS.
	 * @return array Modified response with remote image URL and sizes if applicable.
	 */
	public function inject_remote_image_data( $response ) {
		// Nothing to do without an attachment ID.
		if ( empty( $response['id'] ) ) {
			return $response;
		}

		$attachment_id = $response['id'];

		// Only modify attachments imported by MLS
		if ( intval( get_post_meta( $attachment_id, 'is_mlsimport', true ) ) === 1 ) {
			// Bail if the attachment post cannot be loaded.
			$attachment = get_post( $attachment_id );
			if ( ! $attachment ) {
				return $response;
			}

			// Determine the correct remote URL
			// Prefer a valid URL in the guid, else fall back to the stored external URL.
			$url = filter_var( $attachment->guid, FILTER_VALIDATE_URL )
				? $attachment->guid
				: get_post_meta( $attachment_id, 'houzez_external_url', true );

			// Inject the URL and fake thumbnail size into the response
			if ( $url ) {
				$response['url'] = $url;
				$response['sizes'] = [
					'thumbnail' => [
						'url'    => $url,
						'width'  => 150,
						'height' => 150,
					],
				];
			
			}
		}

		return $response;
	}

	/**
	 * Filter image_downsize to return remote image data instead of local file paths.
	 *
	 * This enables WordPress functions like wp_get_attachment_image() to work
	 * with remote images by returning a URL and fake dimensions.
	 *
	 * @param bool|array $out  Whether to short-circuit the image downsize. Default false.
	 * @param int        $id   Attachment ID.
	 * @param string|array $size Requested image size.
	 * @return array|false Array of image data (URL, width, height, crop) or false to fall back.
	 */
	public function override_image_downsize( $out, $id, $size ) {
		// Only override for MLS-imported attachments
		if ( intval( get_post_meta( $id, 'is_mlsimport', true ) ) !== 1 ) {
			return false;
		}

		// Bail if the attachment post cannot be loaded.
		$attachment = get_post( $id );
		if ( ! $attachment ) {
			return false;
		}

		// Determine the remote image URL
		// Prefer a valid URL in the guid, else the stored external URL.
		$url = filter_var( $attachment->guid, FILTER_VALIDATE_URL )
			? $attachment->guid
			: get_post_meta( $id, 'houzez_external_url', true );

		// No URL resolved -> let WordPress handle it normally.
		if ( ! $url ) {
			return false;
		}

	

		// Return URL with fake dimensions (used for thumbnail display)
		return [ $url, 150, 150, false ];
	}
}
