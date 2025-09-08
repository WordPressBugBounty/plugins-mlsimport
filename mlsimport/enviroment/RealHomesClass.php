<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Description of RealHomesClass
 *
 * @author mlsimport
 */
class RealHomesClass {

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
		return array('agency','agent');
	}

		/**
		 *  image save
		 *
		 * @since    1.0.0
		 * @access   protected
		 * @var      string    $plugin_name
		 */
	public function enviroment_image_save( $property_id, $attach_id ) {
		add_post_meta( $property_id, 'REAL_HOMES_property_images', intval( $attach_id ) );
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
	 * Deal with extra meta
	 */
	public function mlsimportSaasSetExtraMeta( $property_id, $property ) {
		$property_history = '';
		$extra_meta_log   = '';
		$answer           = array();
		$extra_fields     = array();
		$options          = get_option( 'mlsimport_admin_fields_select' );
                $permited_meta    = isset($options['mls-fields']) ? $options['mls-fields'] : array();

		// save geo coordinates

		if ( isset( $property['meta']['property_longitude'] ) && isset( $property['meta']['property_latitude'] ) ) {
			$savingx = $property['meta']['property_latitude'] . ',' . $property['meta']['property_longitude'];
			update_post_meta( $property_id, 'REAL_HOMES_property_location', $savingx );
			$property_history .= 'Update Coordinates Meta with ' . $savingx . '</br>';
			$extra_meta_log   .= 'Property with ID ' . $property_id . '  Update Coordinates Meta with ' . $savingx . PHP_EOL;
		}

		if ( isset( $property['extra_meta'] ) && is_array( $property['extra_meta'] ) ) {
			$meta_properties = $property['extra_meta'];
			foreach ( $meta_properties as $meta_name => $meta_value ) :
				// check if extra meta is set to import
				if ( ! isset( $permited_meta[ $meta_name ] ) ) {
					// we do not have the extra meta
					continue;
				} elseif ( isset( $permited_meta[ $meta_name ] ) && intval( $permited_meta[ $meta_name ] ) === 0 ) {
					// meta exists but is set to no
					continue;
				}

                               if ( is_array( $meta_value ) ) {
                                       $meta_value = implode( ', ', array_map( 'trim', $meta_value ) );
                               } else {
                                       $meta_value = preg_replace( '/\s*,\s*/', ', ', trim( $meta_value ) );
                               }
               $orignal_meta_name = $meta_name;

				if( isset( $options['mls-fields-map-postmeta'][ $meta_name ]) && $options['mls-fields-map-postmeta'][ $meta_name ]!==''   ){
					$new_post_meta_key=$options['mls-fields-map-postmeta'][ $orignal_meta_name ];
					update_post_meta( $property_id, $new_post_meta_key, $meta_value );
					$property_history .= 'Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_name . ' and value ' . $meta_value . '</br>';
				}else if( isset( $options['mls-fields-map-taxonomy'][ $orignal_meta_name ]) && $options['mls-fields-map-taxonomy'][ $orignal_meta_name ]!==''   ){
					$new_taxonomy=$options['mls-fields-map-taxonomy'][ $orignal_meta_name ];
				
                                       $custom_label=$options['mls-fields-label'][ $orignal_meta_name ];
                                       $meta_value_with_label = array( trim( $custom_label.' '.$meta_value) );
					

					

					wp_set_object_terms( $property_id, $meta_value_with_label, $new_taxonomy, true );
					clean_term_cache( $property_id, $new_taxonomy );

				
					$property_history.= 'Updated CUSTOM TAX: ' . $new_taxonomy . '<-- original '.$orignal_meta_name.'/' . $meta_name .'/'.$custom_label. ' and value ' . json_encode($meta_value_with_label);
				}else{

					
                                if ( '' !== $meta_value &&
                                        isset( $options['mls-fields'][ $meta_name ] ) &&
                                        1 === intval( $options['mls-fields'][ $meta_name ] )
                                ) {
                                        $feature_name = isset( $options['mls-fields-label'][ $meta_name ] ) && '' !== $options['mls-fields-label'][ $meta_name ] ? $options['mls-fields-label'][ $meta_name ] : $meta_name;

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
                                                        'label' => $feature_name,
                                                        'value' => $meta_value,
                                                        'order' => $order,
                                                );
                                        } elseif (
                                                isset( $options['mls-fields-admin'][ $meta_name ] ) &&
                                                1 === intval( $options['mls-fields-admin'][ $meta_name ] )
                                        ) {
                                                update_post_meta( $property_id, strtolower( $feature_name ), $meta_value );
                                        }
                                }
				}
				

				$property_history .= 'Updated EXTRA Meta ' . $meta_name . ' with label ' . $feature_name . ' and value ' . $meta_value . '</br>';
				$extra_meta_log   .= 'Property with ID ' . $property_id . '  Update EXTRA Meta ' . $meta_name . ' with value ' . $meta_value . PHP_EOL;

			endforeach;
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
					$ordered_extra_fields[ $field['label'] ] = $field['value'];
				}

				update_post_meta( $property_id, 'REAL_HOMES_additional_details', $ordered_extra_fields );
				update_post_meta( $property_id, 'REAL_HOMES_additional_details_list', $ordered_extra_fields );

				$answer['property_history'] = $property_history;
				$answer['extra_meta_log']   = $extra_meta_log;

		}

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
		if ( 'yes' ===  $is_insert ) {
			update_post_meta( $property_id, 'REAL_HOMES_featured', 0 );
			update_post_meta( $property_id, 'REAL_HOMES_agent_display_option', 'agent_info' );

			$options_mls = get_option( 'mlsimport_admin_mls_sync' );
			// update_post_meta($property_id, 'REAL_HOMES_agents', $options_mls['property_agent']);
			update_post_meta( $property_id, 'REAL_HOMES_agents', $new_agent );

			update_post_meta( $property_id, 'REAL_HOMES_property_map', 0 );
			update_post_meta( $property_id, 'inspiry_property_label_color', '#fb641c' );

			$fave_property_size_prefix = get_post_meta( $property_id, 'REAL_HOMES_property_size_postfix', true );
			if ( '' === $fave_property_size_prefix ) {
				update_post_meta( $property_id, 'REAL_HOMES_property_size_postfix', 'Sq Ft' );
			}

			$fave_property_land_postfix = get_post_meta( $property_id, 'REAL_HOMES_property_lot_size_postfix', true );
			if ( '' === $fave_property_land_postfix  ) {
				update_post_meta( $property_id, 'REAL_HOMES_property_lot_size_postfix', 'Sq Ft' );
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
