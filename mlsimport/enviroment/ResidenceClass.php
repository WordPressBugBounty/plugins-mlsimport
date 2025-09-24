<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Description of ResidenceClass
 *
 * @author mlsimport
 */
class ResidenceClass {

	public function __construct() {
      
	}


	/**
	 * return custom post field
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function get_property_post_type() {
		return 'estate_property';
	}
	


	/**
	 * return custom post field
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function get_agent_post_type() {
		return array('estate_agent','estate_agency','estate_developer');
	}


	/**
	 *  image save
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function enviroment_image_save( $property_id, $attach_id ) {
		return;
	}


    
	/**
	 *  image save
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function enviroment_image_save_gallery( $property_id, $post_attachments ) {
        $gallery_meta = implode(',', $post_attachments);
        update_post_meta($property_id, 'wpestate_property_gallery', $post_attachments);

	}





        /**
         * Format extra meta values into a safe string representation.
         */
        private function normalizeExtraMetaValue($meta_value) {
                if (is_array($meta_value)) {
                        $normalized = array();

                        foreach ($meta_value as $value) {
                                if (is_array($value)) {
                                        if (function_exists('wp_json_encode')) {
                                                $encoded = wp_json_encode($value);
                                        } else {
                                                $encoded = json_encode($value);
                                        }

                                        if (false !== $encoded && null !== $encoded) {
                                                $normalized[] = $encoded;
                                        }
                                } else {
                                        $value = trim((string) $value);
                                        if ('' !== $value) {
                                                $normalized[] = $value;
                                        }
                                }
                        }

                        if (empty($normalized)) {
                                return '';
                        }

                        return implode(', ', $normalized);
                }

                return preg_replace('/\s*,\s*/', ', ', trim((string) $meta_value));
        }

       /**
        * Format Rooms extra meta entries into a single string.
        */
       private function formatRoomsExtraMeta($rooms, $property_id) {
               if (!is_array($rooms) || empty($rooms)) {
                       return '';
               }


               $formatted_rooms = array();

               foreach ($rooms as $index => $room_details) {
                       if (!is_array($room_details)) {
                               continue;
                       }

                       $room_type  = isset($room_details['RoomType']) ? trim((string) $room_details['RoomType']) : '';
                       $room_level = isset($room_details['RoomLevel']) ? trim((string) $room_details['RoomLevel']) : '';
                       $room_length = isset($room_details['RoomLength']) ? trim((string) $room_details['RoomLength']) : '';
                       $room_width = isset($room_details['RoomWidth']) ? trim((string) $room_details['RoomWidth']) : '';
                       $room_units = isset($room_details['RoomLengthWidthUnits']) ? trim((string) $room_details['RoomLengthWidthUnits']) : '';

                       if ($room_type === '') {
                               continue;
                       }

                       $details = array();
                       if ($room_level !== '') {
                               $details[] = $room_level;
                       }

                       $dimension = '';
                       if ($room_length !== '' && $room_width !== '') {
                               $dimension = $room_length . ' x ' . $room_width;
                       } elseif ($room_length !== '') {
                               $dimension = $room_length;
                       } elseif ($room_width !== '') {
                               $dimension = $room_width;
                       }

                       if ($dimension !== '') {
                               if ($room_units !== '') {
                                       $dimension .= ' ' . $room_units;
                               }
                               $details[] = $dimension;
                       } elseif ($room_units !== '') {
                               $details[] = $room_units;
                       }

                       $formatted_value = $room_type;
                       if (!empty($details)) {
                               $formatted_value .= ': ' . implode(', ', $details);
                       }

                       $formatted_rooms[] = $formatted_value;
               }

               $result = implode(' | ', $formatted_rooms);

               return $result;
       }


        /**
         * Deal with extra meta
         */


        /**
         * Deal with extra meta
         */
        public function mlsimportSaasSetExtraMeta($property_id, $property) {
        // Memory tracking
        $startMemory = memory_get_usage(true);
        $startMemoryFormatted = round($startMemory / 1048576, 2);

        $property_history = array();
        $answer = array();
        
        if (!isset($property['extra_meta']) || !is_array($property['extra_meta'])) {
            $answer['property_history'] = '';

            $endMemory = memory_get_usage(true);
            $endMemoryFormatted = round($endMemory / 1048576, 2);
            $memoryDiff = $endMemoryFormatted - $startMemoryFormatted;

            return $answer;
        }
        
        // Get options once and extract only what we need
        $options = get_option('mlsimport_admin_fields_select');
        $permited_meta = isset($options['mls-fields']) ? $options['mls-fields'] : array();
        
        // Process meta in smaller batches
        $meta_batches = array_chunk(array_keys($property['extra_meta']), 10, true);
        
        $batch_count = 0;
        foreach ($meta_batches as $meta_batch_keys) {
            $batch_count++;

            $batchMemory = memory_get_usage(true);
            $batchMemoryFormatted = round($batchMemory / 1048576, 2);
            
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

                if ('Rooms' === $meta_name && is_array($meta_value)) {
                    $formatted_rooms = $this->formatRoomsExtraMeta($meta_value, $property_id);
                    if ($formatted_rooms !== '') {
                        update_post_meta($property_id, 'rooms', $formatted_rooms);
                        $property_history[] = 'Updated EXTRA Meta rooms';
                    }
                    continue;
                }

                $normalized_meta_value = $this->normalizeExtraMetaValue($meta_value);

                $original_meta_name = $meta_name;
                $meta_name_lower = strtolower($meta_name);
                
                // Process custom postmeta mapping
                if (isset($options['mls-fields-map-postmeta'][$original_meta_name]) && $options['mls-fields-map-postmeta'][$original_meta_name] !== '') {
                    $new_post_meta_key = $options['mls-fields-map-postmeta'][$original_meta_name];

                    update_post_meta($property_id, $new_post_meta_key, $normalized_meta_value);
                    $property_history[] = 'Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_name;
                }
                // Process custom taxonomy mapping
                else if (isset($options['mls-fields-map-taxonomy'][$original_meta_name]) && $options['mls-fields-map-taxonomy'][$original_meta_name] !== '') {
                    $new_taxonomy = $options['mls-fields-map-taxonomy'][$original_meta_name];
                    $custom_label = isset($options['mls-fields-label'][$original_meta_name]) ? $options['mls-fields-label'][$original_meta_name] : '';

                    if ($custom_label == 'none') {
                        $custom_label = '';
                    }

                    $meta_value_with_label = array();
                    $term_value = trim($custom_label . ' ' . $normalized_meta_value);
                    if ($term_value !== '') {
                        $meta_value_with_label[] = $term_value;
                    }

                    if (!empty($meta_value_with_label)) {
                        wp_set_object_terms($property_id, $meta_value_with_label, $new_taxonomy, true);

                        // Fix the clean_term_cache call to avoid SQL errors
                        $term_ids = array();
                        foreach ($meta_value_with_label as $term_name) {
                            $term = get_term_by('name', $term_name, $new_taxonomy);
                            if ($term && !is_wp_error($term)) {
                                $term_ids[] = $term->term_id;
                            }
                        }

                        if (!empty($term_ids)) {
                            clean_term_cache($term_ids, $new_taxonomy);
                        }
                    }

                    $property_history[] = 'Updated CUSTOM TAX: ' . $new_taxonomy . ' original ' . $original_meta_name;
                }
                // Standard meta update
                else {
                    update_post_meta($property_id, $meta_name_lower, $normalized_meta_value);
                    $property_history[] = 'Updated EXTRA Meta ' . $meta_name_lower;
                }

                // Clear each variable after use
                $meta_value = null;
                $normalized_meta_value = null;
            }
            
            // Clean up after each batch
            wp_cache_flush();
            gc_collect_cycles();
            
            $afterBatchMemory = memory_get_usage(true);
            $afterBatchMemoryFormatted = round($afterBatchMemory / 1048576, 2);
            $batchMemoryDiff = $afterBatchMemoryFormatted - $batchMemoryFormatted;
        }
        
        // Remove unused code that was referencing undefined variables
        // (The processed_field_values code wasn't being used)
        
        // Prepare the answer with limited history to conserve memory
        if (count($property_history) > 20) {
            $property_history = array_slice($property_history, -20);
            $property_history[] = '... [truncated history to save memory] ...';
        }

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
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function correlationUpdateAfter( $is_insert, $property_id, $global_extra_fields, $new_agent ) {
		if ( 'yes' === $is_insert   ) {
			update_post_meta( $property_id, 'prop_featured', 0 );
			update_post_meta( $property_id, 'page_custom_zoom', 16 );
			update_post_meta( $property_id, 'property_country', 'United States' );

			update_post_meta( $property_id, 'property_agent', $new_agent );
			update_post_meta( $property_id, 'property_page_desing_local', '' );
			update_post_meta( $property_id, 'header_transparent', 'global' );
			update_post_meta( $property_id, 'page_show_adv_search', 'global' );
			update_post_meta( $property_id, 'page_show_adv_search', 'global' );
			update_post_meta( $property_id, 'header_type', 0 );
			update_post_meta( $property_id, 'sidebar_agent_option', 'global' );
			update_post_meta( $property_id, 'local_pgpr_slider_type', 'global' );
			update_post_meta( $property_id, 'local_pgpr_content_type', 'global' );
			update_post_meta( $property_id, 'sidebar_select', 'global' );
			update_post_meta( $property_id, 'sidebar_option', 'global' );

			if ( function_exists( 'wpestate_update_hiddent_address_single' ) ) {
				wpestate_update_hiddent_address_single( $property_id );
			}
		}
	}




	/**
	 * save custom fields per environment
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
        public function enviroment_custom_fields( $option_name ) {
                $theme_options = get_option( 'wpresidence_admin' );
                $custom_fields = array();
                if ( isset( $theme_options['wpestate_custom_fields_list'] ) ) {
                        $custom_fields = $theme_options['wpestate_custom_fields_list'];
                }

                if ( ! is_array( $custom_fields ) ) {
                        $custom_fields = array();
                }

                foreach ( array( 'add_field_name', 'add_field_label', 'add_field_type', 'add_field_order', 'add_dropdown_order' ) as $field_key ) {
                        if ( ! isset( $custom_fields[ $field_key ] ) || ! is_array( $custom_fields[ $field_key ] ) ) {
                                $custom_fields[ $field_key ] = array();
                        }
                }

                $options = get_option( $option_name . '_admin_fields_select' );

                foreach ( $options['mls-fields'] as $key => $value ) {
                        $import   = intval( $value );
                        $admin    = isset( $options['mls-fields-admin'][ $key ] ) ? intval( $options['mls-fields-admin'][ $key ] ) : 0;
                        $taxonomy = isset( $options['mls-fields-map-taxonomy'][ $key ] ) ? $options['mls-fields-map-taxonomy'][ $key ] : '';
                        $order_value = isset( $options['field_order'][ $key ] ) ? intval( $options['field_order'][ $key ] ) + 100 : 100;

                        if ( 1 === $import && 0 === $admin && '' === $taxonomy ) {
                                $existing_index = array_search( $key, $custom_fields['add_field_name'], true );

                                if ( false === $existing_index ) {
                                        $custom_fields['add_field_name'][]     = $key;
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $custom_fields['add_field_label'][]    = $label;
                                        $custom_fields['add_field_type'][]     = 'short text';
                                        $custom_fields['add_field_order'][]    = $order_value;
                                        $custom_fields['add_dropdown_order'][] = '';
                                } else {
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $custom_fields['add_field_label'][ $existing_index ] = $label;
                                        $custom_fields['add_field_order'][ $existing_index ] = $order_value;
                                }
                        } else {
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

                if ( ! empty( $custom_fields['add_field_order'] ) ) {
                        asort( $custom_fields['add_field_order'] );
                        $ordered = array(
                                'add_field_name'     => array(),
                                'add_field_label'    => array(),
                                'add_field_type'     => array(),
                                'add_field_order'    => array(),
                                'add_dropdown_order' => array(),
                        );
                        foreach ( array_keys( $custom_fields['add_field_order'] ) as $idx ) {
                                $ordered['add_field_name'][]     = $custom_fields['add_field_name'][ $idx ];
                                $ordered['add_field_label'][]    = $custom_fields['add_field_label'][ $idx ];
                                $ordered['add_field_type'][]     = $custom_fields['add_field_type'][ $idx ];
                                $ordered['add_field_order'][]    = $custom_fields['add_field_order'][ $idx ];
                                $ordered['add_dropdown_order'][] = $custom_fields['add_dropdown_order'][ $idx ];
                        }
                        $custom_fields = $ordered;
                }

                $theme_options['wpestate_custom_fields_list'] = $custom_fields;
                update_option( 'wpresidence_admin', $theme_options );

        }






	/**
	 * return theme schema
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function return_theme_schema() {
		return;
	}


    
}
