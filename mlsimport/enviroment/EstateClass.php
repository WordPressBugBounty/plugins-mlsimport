<?php 
/**
 * Theme adapter: WpEstate.
 *
 * One of the per-theme adapters in enviroment/. Maps RESO-standard MLS data
 * onto WpEstate's post type (estate_property), agent post type (estate_agent),
 * post meta keys and its wp_estate_custom_fields / wp_estate_feature_list
 * options. The importer calls these methods to persist extra meta, property
 * features, hardcoded single-property display defaults, and to keep the theme's
 * custom-field registry in sync with the selected MLS fields.
 */
if ( ! defined( 'ABSPATH' ) ) {
	// Block direct web access — only load when WordPress is bootstrapped.
	exit; // Exit if accessed directly
}

/**
 * Description of EstateClass
 *
 * @author mlsimport
 */
class EstateClass {

	/**
	 * No setup required — the adapter is stateless.
	 */
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
		// WpEstate's property CPT slug — imported listings are stored here.
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
		// WpEstate's agent CPT slug — imported agents are stored here.
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
                // No-op for WpEstate — single-image association is handled elsewhere.
                return;
        }

        /**
         * Format extra meta values into a safe string representation.
         *
         * Flattens an array meta value into a comma-joined string (JSON-encoding
         * any nested arrays, dropping empty entries); scalars are trimmed and
         * their comma spacing normalized.
         *
         * @param mixed $meta_value Raw RESO meta value (scalar or array).
         * @return string Normalized single-line string.
         */
        private function normalizeExtraMetaValue($meta_value) {
                // Array value: build a normalized list of string parts.
                if (is_array($meta_value)) {
                        $normalized = array();

                        foreach ($meta_value as $value) {
                                // Nested array → JSON-encode it as one entry.
                                if (is_array($value)) {
                                        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);

                                        // Keep only a successful encode.
                                        if (false !== $encoded && null !== $encoded) {
                                                $normalized[] = $encoded;
                                        }
                                } else {
                                        // Scalar → trim and keep if non-empty.
                                        $value = trim((string) $value);
                                        if ('' !== $value) {
                                                $normalized[] = $value;
                                        }
                                }
                        }

                        // Nothing survived → empty string.
                        if (empty($normalized)) {
                                return '';
                        }

                        // Join the collected parts.
                        return implode(', ', $normalized);
                }

                // Scalar value: trim and normalize comma spacing to ", ".
                return preg_replace('/\s*,\s*/', ', ', trim((string) $meta_value));
        }

        /**
         * Format Rooms extra meta entries into a single string.
         *
         * Turns the RESO Rooms collection (each entry an array with RoomType,
         * RoomLevel, RoomLength/Width and units) into a human-readable
         * "Type: level, LxW units | Type: …" string. Entries without a RoomType
         * are skipped.
         *
         * @param mixed $rooms RESO Rooms array (array of room detail arrays).
         * @return string Pipe-separated room summary, or '' when empty.
         */
        private function formatRoomsExtraMeta($rooms) {
                // Guard: nothing to format unless it's a non-empty array.
                if (!is_array($rooms) || empty($rooms)) {
                        return '';
                }

                $formatted_rooms = array();

                foreach ($rooms as $room_details) {
                        // Skip malformed (non-array) room entries.
                        if (!is_array($room_details)) {
                                continue;
                        }

                        // Pull each RESO room field, trimmed, defaulting to ''.
                        $room_type  = isset($room_details['RoomType']) ? trim((string) $room_details['RoomType']) : '';
                        $room_level = isset($room_details['RoomLevel']) ? trim((string) $room_details['RoomLevel']) : '';
                        $room_length = isset($room_details['RoomLength']) ? trim((string) $room_details['RoomLength']) : '';
                        $room_width = isset($room_details['RoomWidth']) ? trim((string) $room_details['RoomWidth']) : '';
                        $room_units = isset($room_details['RoomLengthWidthUnits']) ? trim((string) $room_details['RoomLengthWidthUnits']) : '';

                        // RoomType is required — skip entries without one.
                        if ($room_type === '') {
                                continue;
                        }

                        // Collect optional detail parts (level, dimensions).
                        $details = array();
                        if ($room_level !== '') {
                                $details[] = $room_level;
                        }

                        // Build a dimension string from whichever of length/width is present.
                        $dimension = '';
                        if ($room_length !== '' && $room_width !== '') {
                                $dimension = $room_length . ' x ' . $room_width;
                        } elseif ($room_length !== '') {
                                $dimension = $room_length;
                        } elseif ($room_width !== '') {
                                $dimension = $room_width;
                        }

                        // Append units to the dimension, or keep units alone if no dimension.
                        if ($dimension !== '') {
                                if ($room_units !== '') {
                                        $dimension .= ' ' . $room_units;
                                }
                                $details[] = $dimension;
                        } elseif ($room_units !== '') {
                                $details[] = $room_units;
                        }

                        // Compose "Type: detail, detail" for this room.
                        $formatted_value = $room_type;
                        if (!empty($details)) {
                                $formatted_value .= ': ' . implode(', ', $details);
                        }

                        $formatted_rooms[] = $formatted_value;
                }

                // Join all rooms with a pipe separator.
                return implode(' | ', $formatted_rooms);
        }

	/**
	 * Deal with extra meta
	 *
	 * Persists the property's RESO extra_meta onto WpEstate post meta. Only
	 * fields enabled in mlsimport_admin_fields_select['mls-fields'] are saved.
	 * Rooms get the dedicated 'rooms' meta; every other field is stored under its
	 * lowercased RESO name, and additionally copied to a mapped custom post meta
	 * key when one is configured. Returns a running history/log the importer
	 * accumulates. Property features are appended by the helper below.
	 *
	 * @param int   $property_id WpEstate property post ID.
	 * @param array $property    SaaS property payload (expects 'extra_meta', 'meta').
	 * @return array { property_history, extra_meta_log } log strings.
	 */
        public function mlsimportSaasSetExtraMeta( $property_id, $property ) {
                // Accumulators for the human-facing history and the log file.
                $property_history = '';
                $extra_meta_log   = '';
                $answer           = array();
                // Selected-fields config: which MLS fields the user enabled and their mappings.
                $options          = get_option( 'mlsimport_admin_fields_select' );
                $permited_meta    = isset( $options['mls-fields'] ) ? $options['mls-fields'] : array();

                // Only process when the payload carries an extra_meta array.
                if ( isset( $property['extra_meta'] ) && is_array( $property['extra_meta'] ) ) {
                        $meta_properties = $property['extra_meta'];

                        foreach ( $meta_properties as $meta_name => $meta_value ) {
                                // Skip fields the user did not enable in field selection.
                                if ( ! isset( $permited_meta[ $meta_name ] ) || intval( $permited_meta[ $meta_name ] ) === 0 ) {
                                        continue;
                                }

                                // Rooms are special-cased into a formatted 'rooms' meta.
                                if ( 'Rooms' === $meta_name && is_array( $meta_value ) ) {
                                        $formatted_rooms = $this->formatRoomsExtraMeta( $meta_value );
                                        if ( '' !== $formatted_rooms ) {
                                                update_post_meta( $property_id, 'rooms', $formatted_rooms );
                                                $property_history .= 'Updated EXTRA Meta rooms</br>';
                                                $extra_meta_log   .= 'Property with ID ' . $property_id . '  Updated EXTRA Meta rooms with value ' . $formatted_rooms . PHP_EOL;
                                        }
                                        continue;
                                }

                                // Normalize the value and store it under the lowercased RESO field name.
                                $normalized_value = $this->normalizeExtraMetaValue( $meta_value );
                                $meta_key = strtolower( $meta_name );

                                update_post_meta( $property_id, $meta_key, $normalized_value );

                                // If this field is mapped to a custom post meta key, copy the value there too.
                                if ( isset( $options['mls-fields-map-postmeta'][ $meta_key ] ) && $options['mls-fields-map-postmeta'][ $meta_key ] !== '' ) {
                                        $new_post_meta_key = $options['mls-fields-map-postmeta'][ $meta_key ];
                                        update_post_meta( $property_id, $new_post_meta_key, $normalized_value );
                                        $property_history .= 'Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_key . ' and value ' . $normalized_value . '</br>';
                                }

                                // Record what was saved.
                                $property_history .= 'Updated EXTRA Meta ' . $meta_key . ' with meta_value ' . $normalized_value . '</br>';
                                $extra_meta_log   .= 'Property with ID ' . $property_id . '  Updated EXTRA Meta ' . $meta_key . ' with value ' . $normalized_value . PHP_EOL;
                        }

                        // Seed the answer with what this pass logged.
                        $answer['property_history'] = $property_history;
                        $answer['extra_meta_log']   = $extra_meta_log;
                }

                // Append property-feature handling to the same answer.
                $answer = $this->mlsimport_saas_set_extra_meta_features( $property_id, $property, $answer );

                return $answer;
        }

	/**
	 * Store property features as boolean post meta and grow the theme's feature list.
	 *
	 * Iterates $property['meta']['property_features'] (each value a feature name or
	 * an array of them), writes a sanitized meta key = 1 per feature, and appends
	 * any newly seen feature to the wp_estate_feature_list option so WpEstate knows
	 * about it. Appends its own history/log strings onto the passed $answer.
	 *
	 * @param int   $property_id WpEstate property post ID.
	 * @param array $property    SaaS property payload (expects meta.property_features).
	 * @param array $answer      Running { property_history, extra_meta_log } to extend.
	 * @return array The extended $answer.
	 */
	public function mlsimport_saas_set_extra_meta_features( $property_id, $property, $answer ) {
		// Per-call history/log accumulators.
		$property_history = '';
		$extra_meta_log   = '';

		// Current comma-separated theme feature list, and the target post ID.
		$feature_list = esc_html( get_option( 'wp_estate_feature_list' ) );
		$post_id      = $property_id;

		// Only run when the payload has a property_features array.
		if ( isset( $property['meta']['property_features'] ) && is_array( $property['meta']['property_features'] ) ) :
			foreach ( $property['meta']['property_features'] as $key => $feature_name ) :
				// A feature entry may itself be an array of feature names.
				if ( is_array( $feature_name ) ) {
					foreach ( $feature_name as $key => $feature_name_from_arr ) {
						// Derive a sanitized meta key from the feature name ($to_insert is unset here).
						if ( '' === $to_insert  ) {
							$post_var_name = str_replace( ' ', '_', trim( $feature_name_from_arr ) );
							$input_name    = sanitize_title( $post_var_name );
							$input_name    = sanitize_key( $input_name );
						} else {
							$input_name       = $to_insert;
								$feature_name = $to_insert;
						}

						// Flag the feature on the property.
						update_post_meta( $post_id, $input_name, 1 );
						$property_history .= 'Updated Featured  ' . $input_name . ' with yes</br>';
						$extra_meta_log   .= 'Property with ID ' . $property_id . '  pdated Featured  ' . $input_name . ' with yes' . PHP_EOL;

						// Register the feature on the theme list if it isn't already present.
						if ( false === strpos( $feature_list, $feature_name_from_arr ) && '' !== $feature_name ) {
							$feature_list .= ',' . $feature_name_from_arr;
							update_option( 'wp_estate_feature_list', $feature_list );
						}
					}
				} else {
					// Scalar feature name → same sanitize-to-meta-key derivation.
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

					// Flag the feature on the property.
					update_post_meta( $post_id, $input_name, 1 );
					$property_history .= 'Updated Featured  ' . $input_name . ' with yes</br>';
					$extra_meta_log   .= 'Property with ID ' . $property_id . '  pdated Featured  ' . $input_name . ' with yes' . PHP_EOL;

					// Register the feature on the theme list if it isn't already present.
					if ( false === strpos( $feature_list, $feature_name ) && '' !== $feature_name ) {
						$feature_list .= ',' . $feature_name;
						update_option( 'wp_estate_feature_list', $feature_list );
					}
				}
			endforeach;
		endif;

		// Concatenate this pass's logs onto whatever the caller already collected.
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
		// Only run on a fresh insert, not on updates of existing listings.
		if ( 'yes' === $is_insert  ) {
			// Seed WpEstate single-property display defaults (sliders, content, zoom, etc.).
			update_post_meta( $property_id, 'local_pgpr_slider_type', 'global' );
			update_post_meta( $property_id, 'local_pgpr_content_type', 'global' );
			update_post_meta( $property_id, 'prop_featured', 0 );
			update_post_meta( $property_id, 'page_custom_zoom', 16 );
			// Assign the configured default agent from the MLS sync settings.
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
			// Let the theme recompute its hidden-address flag for this listing.
			if ( function_exists( 'wpestate_update_hiddent_address_single' ) ) {
				wpestate_update_hiddent_address_single( $property_id );
			}
		}
	}



	/**
	 * Gallery save hook — no-op for WpEstate (gallery handled elsewhere).
	 *
	 * @param int   $property_id      WpEstate property post ID.
	 * @param array $post_attachments Attachment IDs for the listing gallery.
	 */
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

		// Existing WpEstate custom-field definitions (each a numeric array row).
		$custom_fields = get_option( 'wp_estate_custom_fields', true );

		// Ensure we always work with an array.
		if ( ! is_array( $custom_fields ) ) {
			$custom_fields = array();
		}
		// Starting order index for newly added custom fields.
		$custom_field_no = 100;
		$options         = get_option( $option_name . '_admin_fields_select' );

		foreach ( $options['mls-fields'] as $key => $value ) {
			// Field is enabled AND not flagged as admin-only → it becomes a theme custom field.
			if ( 1 === intval($value)  && 0 === intval( $options['mls-fields-admin'][ $key ])  ) {
				// Not already registered (and non-empty key) → add a new custom-field row.
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
					// Already registered → just refresh its label (index 1).
					$to_replace_key                        = array_search( $key, array_column( $custom_fields, 0 ) );
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $custom_fields[ $to_replace_key ]['1'] = $label;
				}
			} else {
				// remove item from custom fields
				$key_remove = $this->searchForId( $key, $custom_fields );

				// Drop the row when a matching definition exists.
				if ( intval( $key_remove ) > 0 ) {
					unset( $custom_fields[ $key_remove ] );
				}
			}
		}

		// Persist the rebuilt custom-field list back to the theme option.
		update_option( 'wp_estate_custom_fields', $custom_fields );
	}


	/**
	 * Find the array index of a custom-field row whose first element equals $id.
	 *
	 * @param string $id    The field key to look for (matched against $val[0]).
	 * @param array  $array Rows of custom-field definitions.
	 * @return int|string|null Matching key, or null when not found.
	 */
	public function searchForId( $id, $array ) {
		// Linear scan comparing each row's key column (index 0).
		foreach ( $array as $key => $val ) {
			if ( $val[0] === $id ) {
				return $key;
			}
		}
		// No match.
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

		// No schema exposed for WpEstate — returns nothing.
		return;
	}
}
