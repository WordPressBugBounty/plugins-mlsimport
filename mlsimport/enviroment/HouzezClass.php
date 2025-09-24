<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Description of HouzezClass
 *
 * @author mlsimport
 */
class HouzezClass {


	public function __construct() {
		// Enable support for remote MLS images in media JS and thumbnails
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'inject_remote_image_data' ], 20 );
		add_filter( 'image_downsize', [ $this, 'override_image_downsize' ], 10, 3 );
	}


	/**
	 * return custom post field
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function get_property_post_type() {
		return 'property';
	}


	/**
	 * return custom post field
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function get_agent_post_type() {
		return array('houzez_agency','houzez_agent');
	}

	/**
	 *  image save
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function enviroment_image_save( $property_id, $attach_id ) {
		add_post_meta( $property_id, 'fave_property_images', intval( $attach_id ) );
	}

/**
	 *  gallery save
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	        public function enviroment_image_save_gallery( $property_id, $post_attachments ) {
      return;
        }


        /**
         * Format extra meta values into a safe string representation.
         */
        private function normalizeExtraMetaValue($meta_value) {
                if (is_array($meta_value)) {
                        $normalized = array();

                        foreach ($meta_value as $value) {
                                if (is_array($value)) {
                                        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);

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
        private function formatRoomsExtraMeta($rooms) {
                if (!is_array($rooms) || empty($rooms)) {
                        return '';
                }

                $formatted_rooms = array();

                foreach ($rooms as $room_details) {
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

                return implode(' | ', $formatted_rooms);
        }

        public function return_extra_fields
( $property_id ) {
		return get_post_meta( $property_id, 'additional_features', true );
	}


	/**
	 * Deal with extra meta
		*/
        public function mlsimportSaasSetExtraMeta( $property_id, $property ) {
                $property_history = '';
                $extra_meta_log   = '';
                $answer           = array();
                $extra_fields     = array();
                $options = get_option('mlsimport_admin_fields_select');
                $permited_meta = isset($options['mls-fields']) ? $options['mls-fields'] : array();

                if ( isset( $property['meta']['houzez_geolocation_long'] ) && isset( $property['meta']['houzez_geolocation_lat'] ) ) {
                        $savingx = $property['meta']['houzez_geolocation_lat'] . ',' . $property['meta']['houzez_geolocation_long'];
                        update_post_meta( $property_id, 'fave_property_location', $savingx );
                        update_post_meta( $property_id, 'property_location', $savingx );
                        $property_history .= 'Update Coordinates Meta (having houzez_geolocation_long ) with ' . $savingx . '</br>';
                        $extra_meta_log   .= 'Property with ID ' . $property_id . '  Update Coordinates Meta with ' . $savingx . PHP_EOL;
                } elseif ( isset( $property['meta']['property_longitude'] ) && isset( $property['meta']['property_latitude'] ) ) {
                        $savingx = $property['meta']['property_latitude'] . ',' . $property['meta']['property_longitude'];
                        update_post_meta( $property_id, 'fave_property_location', $savingx );
                        update_post_meta( $property_id, 'property_location', $savingx );
                        $property_history .= 'Update Coordinates Meta (having property_longitude) with ' . $savingx . '</br>';
                        $extra_meta_log   .= 'Property with ID ' . $property_id . '  Update Coordinates Meta with ' . $savingx . PHP_EOL;
                }

                if ( isset( $property['extra_meta'] ) && is_array( $property['extra_meta'] ) ) {
                        $meta_properties = $property['extra_meta'];

                        foreach ( $meta_properties as $meta_name => $meta_value ) {
                                if ( ! isset( $permited_meta[ $meta_name ] ) || intval( $permited_meta[ $meta_name ] ) === 0 ) {
                                        continue;
                                }

                                if ( 'Rooms' === $meta_name && is_array( $meta_value ) ) {
                                        $formatted_rooms = $this->formatRoomsExtraMeta( $meta_value );
                                        if ( '' === $formatted_rooms ) {
                                                continue;
                                        }

                                        // Save the formatted value on the dedicated meta key and
                                        // reuse the string for the rest of the processing pipeline
                                        // (custom mappings, additional features, etc.).
                                        update_post_meta( $property_id, 'rooms', $formatted_rooms );
                                        $property_history .= 'Updated EXTRA Meta rooms</br>';
                                        $extra_meta_log   .= 'Property with ID ' . $property_id . ' Updated EXTRA Meta rooms with value ' . $formatted_rooms . PHP_EOL;

                                        $meta_value = $formatted_rooms;
                                }

                                $meta_value = $this->normalizeExtraMetaValue( $meta_value );
                                $orignal_meta_name = $meta_name;
                                $feature_name = isset( $options['mls-fields-label'][ $meta_name ] ) && '' !== $options['mls-fields-label'][ $meta_name ] ? $options['mls-fields-label'][ $meta_name ] : $meta_name;

                                if ( isset( $options['mls-fields-map-postmeta'][ $orignal_meta_name ] ) && $options['mls-fields-map-postmeta'][ $orignal_meta_name ] !== '' ) {
                                        $new_post_meta_key = $options['mls-fields-map-postmeta'][ $orignal_meta_name ];
                                        update_post_meta( $property_id, $new_post_meta_key, $meta_value );
                                        $log_msg = 'Property with ID ' . $property_id . ' Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_name . ' with value ' . $meta_value;
                                        $property_history .= $log_msg . '</br>';
                                        $extra_meta_log   .= $log_msg . PHP_EOL;
                                        continue;
                                }

                                if ( isset( $options['mls-fields-map-taxonomy'][ $orignal_meta_name ] ) && $options['mls-fields-map-taxonomy'][ $orignal_meta_name ] !== '' ) {
                                        $new_taxonomy = $options['mls-fields-map-taxonomy'][ $orignal_meta_name ];
                                        $custom_label = isset( $options['mls-fields-label'][ $orignal_meta_name ] ) ? $options['mls-fields-label'][ $orignal_meta_name ] : '';
                                        if ( 'none' === $custom_label ) {
                                                $custom_label = '';
                                        }
                                        $term_value = trim( $custom_label . ' ' . $meta_value );
                                        $meta_value_with_label = array();
                                        if ( '' !== $term_value ) {
                                                $meta_value_with_label[] = $term_value;
                                                wp_set_object_terms( $property_id, $meta_value_with_label, $new_taxonomy, true );
                                                clean_term_cache( $property_id, $new_taxonomy );
                                        }
                                        $property_history .= 'Updated CUSTOM TAX: ' . $new_taxonomy . '<-- original ' . $orignal_meta_name . '/' . $meta_name . '/' . $custom_label . ' value ' . json_encode( $meta_value_with_label );
                                        continue;
                                }

                                if (
                                        '' !== $meta_value &&
                                        isset( $options['mls-fields'][ $meta_name ] ) &&
                                        1 === intval( $options['mls-fields'][ $meta_name ] )
                                ) {
                                        if (
                                                isset( $options['mls-fields-admin'][ $meta_name ] ) &&
                                                0 === intval( $options['mls-fields-admin'][ $meta_name ] )
                                        ) {
                                                if ( isset( $options['field_order'][ $orignal_meta_name ] ) ) {
                                                        $order = intval( $options['field_order'][ $orignal_meta_name ] );
                                                } else {
                                                        $index = array_search( $orignal_meta_name, $options['field_order'], true );
                                                        $order = ( false !== $index ) ? intval( $index ) : 9999;
                                                }
                                                $extra_fields[] = array(
                                                        'meta_name' => $orignal_meta_name,
                                                        'fave_additional_feature_title' => $feature_name,
                                                        'fave_additional_feature_value' => $meta_value,
                                                        'order' => $order,
                                                );
                                        } elseif (
                                                isset( $options['mls-fields-admin'][ $meta_name ] ) &&
                                                1 === intval( $options['mls-fields-admin'][ $meta_name ] )
                                        ) {
                                                update_post_meta( $property_id, strtolower( $feature_name ), $meta_value );
                                                $log_msg = 'Property with ID ' . $property_id . ' Updated EXTRA Meta ' . $meta_name . ' label ' . $feature_name . ' with value ' . $meta_value;
                                                $property_history .= $log_msg . '</br>';
                                                $extra_meta_log   .= $log_msg . PHP_EOL;
                                        }
                                }
                        }

                        usort(
                                $extra_fields,
                                function ( $a, $b ) {
                                        $orderA = isset( $a['order'] ) ? intval( $a['order'] ) : 9999;
                                        $orderB = isset( $b['order'] ) ? intval( $b['order'] ) : 9999;
                                        return $orderA <=> $orderB;
                                }
                        );

                        $ordered_extra_fields = array();
                        foreach ( $extra_fields as $field ) {
                                $ordered_extra_fields[] = array(
                                        'fave_additional_feature_title' => $field['fave_additional_feature_title'],
                                        'fave_additional_feature_value' => $field['fave_additional_feature_value'],
                                );
                        }

                        update_post_meta( $property_id, 'additional_features', $ordered_extra_fields );
                }

                $answer['property_history'] = $property_history;
                $answer['extra_meta_log']   = $extra_meta_log;

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
		if ( 'yes' === $is_insert ) {
			$options_mls = get_option( 'mlsimport_admin_mls_sync' );
			if ( is_array( $options_mls ) && isset( $options_mls['property_agent'] ) ) {
				update_post_meta( $property_id, 'fave_agents', $options_mls['property_agent'] );
			}
			update_post_meta( $property_id, 'fave_agents', $new_agent );
			update_post_meta( $property_id, 'fave_agent_display_option', 'agent_info' );
			update_post_meta( $property_id, 'fave_featured', 0 );
			// fave_agents

			update_post_meta( $property_id, 'fave_property_map', '1' );
			update_post_meta( $property_id, 'fave_property_map_street_view', 'show' );
			update_post_meta( $property_id, 'fave_single_top_area', 'global' );
			update_post_meta( $property_id, 'fave_single_content_area', 'global' );
			update_post_meta( $property_id, 'fave_additional_features_enable', 'enable' );
		
			$fave_property_size_prefix = get_post_meta( $property_id, 'fave_property_size_prefix', true );
			if ( '' === $fave_property_size_prefix   ) {
				update_post_meta( $property_id, 'fave_property_size_prefix', 'Sq Ft' );
			}

			$fave_property_land_postfix = get_post_meta( $property_id, 'fave_property_land_postfix', true );
			if ( '' === $fave_property_land_postfix ) {
				update_post_meta( $property_id, 'fave_property_land_postfix', 'Sq Ft' );
			}
		}
	}


	/**
	 * save custom fields per environment
	 */
	public function enviroment_custom_fields( $option_name ) {
		return;
		$theme_options   = get_option( 'wpresidence_admin' );
		$custom_fields   = $theme_options['wpestate_custom_fields_list'];
		$custom_field_no = 100;

		$options = get_option( $option_name . '_admin_fields_select' );

		$custom_fields_admin = array();

		$test = 0;
		foreach ( $options['mls-fields'] as $key => $value ) {
			++$test;

			if ( 1 === $value && 0 === intval( $options['mls-fields-admin'][$key]) ) {
				if ( !in_array( $key, $custom_fields['add_field_name'] ) && '' !==  $key ) {
					++$custom_field_no;
					$custom_fields['add_field_name'][]     = $key;
					$custom_fields['add_field_label'][]    = $key;
					$custom_fields['add_field_type'][]     = 'short text';
					$custom_fields['add_field_order'][]    = $custom_field_no;
					$custom_fields['add_dropdown_order'][] = '';
				}
			} else {
				// remove item from custom fields
				$key_remove = array_search( $key, $custom_fields['add_field_name'] );

				unset( $custom_fields['add_field_name'][ $key_remove ] );
				unset( $custom_fields['add_field_label'][ $key_remove ] );
				unset( $custom_fields['add_field_type'][ $key_remove ] );
				unset( $custom_fields['add_field_order'][ $key_remove ] );
				unset( $custom_fields['add_dropdown_order'][ $key_remove ] );
			}
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
	 * property_type - is type, property_status is action,property_feature is features,property_label is status,
	 * property_city is city, property_area is area,property_state is county state
	 */
	public function return_theme_schema() {

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
		if ( empty( $response['id'] ) ) {
			return $response;
		}

		$attachment_id = $response['id'];

		// Only modify attachments imported by MLS
		if ( intval( get_post_meta( $attachment_id, 'is_mlsimport', true ) ) === 1 ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment ) {
				return $response;
			}

			// Determine the correct remote URL
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

		$attachment = get_post( $id );
		if ( ! $attachment ) {
			return false;
		}

		// Determine the remote image URL
		$url = filter_var( $attachment->guid, FILTER_VALIDATE_URL )
			? $attachment->guid
			: get_post_meta( $id, 'houzez_external_url', true );

		if ( ! $url ) {
			return false;
		}

		

		// Return URL with fake dimensions (used for thumbnail display)
		return [ $url, 150, 150, false ];
	}


}
