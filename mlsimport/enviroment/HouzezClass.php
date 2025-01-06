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
		return 'houzez_agent';
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


	public function return_extra_fields( $property_id ) {
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
		$options          = get_option( 'mlsimport_admin_fields_select' );
		$permited_meta    = $options['mls-fields'];

		// save geo coordinates

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
			foreach ( $meta_properties as $meta_name => $meta_value ) :
				$orignal_meta_name = $meta_name;
				// check if extra meta is set to import
				if ( ! isset( $permited_meta[ $meta_name ] ) ) {
					// we do not have the extra meta
					continue;
				} elseif ( isset( $permited_meta[ $meta_name ] ) && intval( $permited_meta[ $meta_name ] ) === 0 ) {
					// meta exists but is set to no
					continue;
				}

				if ( is_array( $meta_value ) ) {
					$meta_value = implode( ',', $meta_value );
				}

				if ( '' !== $meta_value
					&& isset( $options['mls-fields-label'][ $meta_name ] )
					&& '' !== $options['mls-fields-label'][ $meta_name ]
					&& isset( $options['mls-fields'][ $meta_name ] )
     				&& 1 === intval( $options['mls-fields'][ $meta_name ] )	
				) {
					
					
					if( isset( $options['mls-fields-map-postmeta'][ $meta_name ]) && $options['mls-fields-map-postmeta'][ $meta_name ]!==''   ){
						$new_post_meta_key=$options['mls-fields-map-postmeta'][ $orignal_meta_name ];
						update_post_meta( $property_id, $new_post_meta_key, $meta_value );
						$property_history .= 'Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_name . ' and value ' . $meta_value . '</br>';
						$extra_meta_log   .= 'Property with ID ' . $property_id . '  Update EXTRA Meta ' . $meta_name . ' with value ' . $meta_value . PHP_EOL;
						
					}else if( isset( $options['mls-fields-map-taxonomy'][ $orignal_meta_name ]) && $options['mls-fields-map-taxonomy'][ $orignal_meta_name ]!==''   ){
						$new_taxonomy=$options['mls-fields-map-taxonomy'][ $orignal_meta_name ];
					
						$custom_label=$options['mls-fields-label'][ $orignal_meta_name ];
						if ($custom_label=='none'){
							$custom_label='';
						}
				
						if(!is_array($meta_value)){
							$meta_value_with_label = array( trim( $custom_label.' '.$meta_value) ) ;
						}else{
							$meta_value_with_label=array( trim( $custom_label.' '.implode(', ',$meta_value) ) );
						}
	
						wp_set_object_terms( $property_id, $meta_value_with_label, $new_taxonomy, true );
						clean_term_cache( $property_id, $new_taxonomy );
	
					
						$property_history.= 'Updated CUSTOM TAX: ' . $new_taxonomy . '<-- original '.$orignal_meta_name.'/' . $meta_name .'/'.$custom_label. ' and value ' . json_encode($meta_value_with_label);
					}else{
						$feature_name = $options['mls-fields-label'][ $meta_name ];
						if ( ''  ===  $feature_name ) {
							$feature_name = $meta_name;
						}

						if ( isset( $options['mls-fields-admin'][ $meta_name ] ) &&	intval( $options['mls-fields-admin'][ $meta_name ] ) === 0) {
								$element        = array(
									'fave_additional_feature_title' => $feature_name,
									'fave_additional_feature_value' => $meta_value,
								);
								$extra_fields[] = $element;
						} elseif ( isset( $options['mls-fields-admin'][ $meta_name ] ) &&
							intval( $options['mls-fields-admin'][ $meta_name ] ) === 1
						) {
							update_post_meta( $property_id, strtolower( $feature_name ), $meta_value );

							$property_history .= 'Updated EXTRA Meta ' . $meta_name . ' with label ' . $feature_name . ' and value ' . $meta_value . '</br>';
							$extra_meta_log   .= 'Property with ID ' . $property_id . '  Update EXTRA Meta ' . $meta_name . ' with value ' . $meta_value . PHP_EOL;
						}
					}

					
				}
			endforeach;

			update_post_meta( $property_id, 'additional_features', $extra_fields );

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
		if ( 'yes' === $is_insert ) {
			$options_mls = get_option( 'mlsimport_admin_mls_sync' );
			update_post_meta( $property_id, 'fave_agents', $options_mls['property_agent'] );
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
}
