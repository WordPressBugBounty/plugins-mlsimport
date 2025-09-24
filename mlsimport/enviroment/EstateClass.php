<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Description of EstateClass
 *
 * @author mlsimport
 */
class EstateClass {

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
		return 'estate_agent';
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

	/**
	 * Deal with extra meta
	 */
        public function mlsimportSaasSetExtraMeta( $property_id, $property ) {
                $property_history = '';
                $extra_meta_log   = '';
                $answer           = array();
                $options          = get_option( 'mlsimport_admin_fields_select' );
                $permited_meta    = isset( $options['mls-fields'] ) ? $options['mls-fields'] : array();

                if ( isset( $property['extra_meta'] ) && is_array( $property['extra_meta'] ) ) {
                        $meta_properties = $property['extra_meta'];

                        foreach ( $meta_properties as $meta_name => $meta_value ) {
                                if ( ! isset( $permited_meta[ $meta_name ] ) || intval( $permited_meta[ $meta_name ] ) === 0 ) {
                                        continue;
                                }

                                if ( 'Rooms' === $meta_name && is_array( $meta_value ) ) {
                                        $formatted_rooms = $this->formatRoomsExtraMeta( $meta_value );
                                        if ( '' !== $formatted_rooms ) {
                                                update_post_meta( $property_id, 'rooms', $formatted_rooms );
                                                $property_history .= 'Updated EXTRA Meta rooms</br>';
                                                $extra_meta_log   .= 'Property with ID ' . $property_id . '  Updated EXTRA Meta rooms with value ' . $formatted_rooms . PHP_EOL;
                                        }
                                        continue;
                                }

                                $normalized_value = $this->normalizeExtraMetaValue( $meta_value );
                                $meta_key = strtolower( $meta_name );

                                update_post_meta( $property_id, $meta_key, $normalized_value );

                                if ( isset( $options['mls-fields-map-postmeta'][ $meta_key ] ) && $options['mls-fields-map-postmeta'][ $meta_key ] !== '' ) {
                                        $new_post_meta_key = $options['mls-fields-map-postmeta'][ $meta_key ];
                                        update_post_meta( $property_id, $new_post_meta_key, $normalized_value );
                                        $property_history .= 'Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_key . ' and value ' . $normalized_value . '</br>';
                                }

                                $property_history .= 'Updated EXTRA Meta ' . $meta_key . ' with meta_value ' . $normalized_value . '</br>';
                                $extra_meta_log   .= 'Property with ID ' . $property_id . '  Updated EXTRA Meta ' . $meta_key . ' with value ' . $normalized_value . PHP_EOL;
                        }

                        $answer['property_history'] = $property_history;
                        $answer['extra_meta_log']   = $extra_meta_log;
                }

                $answer = $this->mlsimport_saas_set_extra_meta_features( $property_id, $property, $answer );

                return $answer;
        }

	public function mlsimport_saas_set_extra_meta_features( $property_id, $property, $answer ) {
		$property_history = '';
		$extra_meta_log   = '';

		$feature_list = esc_html( get_option( 'wp_estate_feature_list' ) );
		$post_id      = $property_id;

		if ( isset( $property['meta']['property_features'] ) && is_array( $property['meta']['property_features'] ) ) :
			foreach ( $property['meta']['property_features'] as $key => $feature_name ) :
				if ( is_array( $feature_name ) ) {
					foreach ( $feature_name as $key => $feature_name_from_arr ) {
						if ( '' === $to_insert  ) {
							$post_var_name = str_replace( ' ', '_', trim( $feature_name_from_arr ) );
							$input_name    = sanitize_title( $post_var_name );
							$input_name    = sanitize_key( $input_name );
						} else {
							$input_name       = $to_insert;
								$feature_name = $to_insert;
						}

						update_post_meta( $post_id, $input_name, 1 );
						$property_history .= 'Updated Featured  ' . $input_name . ' with yes</br>';
						$extra_meta_log   .= 'Property with ID ' . $property_id . '  pdated Featured  ' . $input_name . ' with yes' . PHP_EOL;

						if ( false === strpos( $feature_list, $feature_name_from_arr ) && '' !== $feature_name ) {
							$feature_list .= ',' . $feature_name_from_arr;
							update_option( 'wp_estate_feature_list', $feature_list );
						}
					}
				} else {
					if ( '' === $to_insert ) {
						$post_var_name = str_replace( ' ', '_', trim( $feature_name ) );
						$input_name    = sanitize_title( $post_var_name );
						$input_name    = sanitize_key( $input_name );
					} else {
						$post_var_name = str_replace( ' ', '_', trim( $to_insert ) );
						$input_name    = sanitize_title( $post_var_name );
						$input_name    = sanitize_key( $input_name );

						$feature_name = $to_insert;
					}

					update_post_meta( $post_id, $input_name, 1 );
					$property_history .= 'Updated Featured  ' . $input_name . ' with yes</br>';
					$extra_meta_log   .= 'Property with ID ' . $property_id . '  pdated Featured  ' . $input_name . ' with yes' . PHP_EOL;

					if ( false === strpos( $feature_list, $feature_name ) && '' !== $feature_name ) {
						$feature_list .= ',' . $feature_name;
						update_option( 'wp_estate_feature_list', $feature_list );
					}
				}
			endforeach;
		endif;

		$answer['property_history'] = $answer['property_history'] . $property_history;
		$answer['extra_meta_log']   = $answer['extra_meta_log'] . $extra_meta_log;

		return $answer;
	}





	/**
	 * set hardcode fields after updated
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function correlationUpdateAfter( $is_insert, $property_id, $global_extra_fields ) {
		if ( 'yes' === $is_insert  ) {
			update_post_meta( $property_id, 'local_pgpr_slider_type', 'global' );
			update_post_meta( $property_id, 'local_pgpr_content_type', 'global' );
			update_post_meta( $property_id, 'prop_featured', 0 );
			update_post_meta( $property_id, 'page_custom_zoom', 16 );
			$options_mls = get_option( 'mlsimport_admin_mls_sync' );
			update_post_meta( $property_id, 'property_agent', $options_mls['property_agent'] );

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



	public function enviroment_image_save_gallery( $property_id, $post_attachments ) {
      

	}


	/**
	 * save custom fields per environment
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function enviroment_custom_fields( $option_name ) {

		$custom_fields = get_option( 'wp_estate_custom_fields', true );

		if ( ! is_array( $custom_fields ) ) {
			$custom_fields = array();
		}
		$custom_field_no = 100;
		$options         = get_option( $option_name . '_admin_fields_select' );

		foreach ( $options['mls-fields'] as $key => $value ) {
			if ( 1 === intval($value)  && 0 === intval( $options['mls-fields-admin'][ $key ])  ) {
				if ( ! in_array( $key, array_column( $custom_fields, 0 ) ) && '' !== $key  ) {
					++$custom_field_no;
					$temp_array    = array();
					$temp_array[0] = $key;
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $temp_array[1] = $label;

					$temp_array[2]   = 'short text';
					$temp_array[3]   = $custom_field_no;
					$custom_fields[] = $temp_array;
				} else {
					$to_replace_key                        = array_search( $key, array_column( $custom_fields, 0 ) );
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $custom_fields[ $to_replace_key ]['1'] = $label;
				}
			} else {
				// remove item from custom fields
				$key_remove = $this->searchForId( $key, $custom_fields );

				if ( intval( $key_remove ) > 0 ) {
					unset( $custom_fields[ $key_remove ] );
				}
			}
		}

		update_option( 'wp_estate_custom_fields', $custom_fields );
	}


	public function searchForId( $id, $array ) {
		foreach ( $array as $key => $val ) {
			if ( $val[0] === $id ) {
				return $key;
			}
		}
		return null;
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
