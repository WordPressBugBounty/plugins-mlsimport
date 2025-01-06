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
	 * Deal with extra meta
	 */
	public function mlsimportSaasSetExtraMeta( $property_id, $property ) {
		$property_history = array();
		$extra_meta_log   = array();
		$answer           = array();
		$options          = get_option( 'mlsimport_admin_fields_select' );
		$permited_meta    = $options['mls-fields'];

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
				$orignal_meta_name = $meta_name;
				$meta_name = strtolower( $meta_name );
				
		
				
				if( isset( $options['mls-fields-map-postmeta'][ $orignal_meta_name ]) && $options['mls-fields-map-postmeta'][ $orignal_meta_name ]!==''   ){
					$new_post_meta_key=$options['mls-fields-map-postmeta'][ $orignal_meta_name ];
					
					if ( is_array( $meta_value ) ) {
						$meta_value = implode( ',', $meta_value );
					}

					update_post_meta( $property_id, $new_post_meta_key, $meta_value );
					$property_history[] = 'Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_name . ' new meta '.$new_post_meta_key.' and value ' . $meta_value;
				} 
				else if( isset( $options['mls-fields-map-taxonomy'][ $orignal_meta_name ]) && $options['mls-fields-map-taxonomy'][ $orignal_meta_name ]!==''   ){
					$new_taxonomy=$options['mls-fields-map-taxonomy'][ $orignal_meta_name ];
				
					$custom_label=$options['mls-fields-label'][ $orignal_meta_name ];
					if ($custom_label=='none'){
						$custom_label='';
					}
				

					if(!is_array($meta_value)){
						$meta_value_with_label = array( trim($custom_label.' '.$meta_value) );
					}else{
						$meta_value_with_label=array( trim( $custom_label.' '.implode(', ',$meta_value))  );
					}

					wp_set_object_terms( $property_id, $meta_value_with_label, $new_taxonomy, true );
					clean_term_cache( $property_id, $new_taxonomy );

				
					$property_history[] = 'Updated CUSTOM TAX: ' . $new_taxonomy . '<-- original '.$orignal_meta_name.'/' . $meta_name .'/'.$custom_label. ' and value ' . json_encode($meta_value_with_label);
				}else{
					
					if ( is_array( $meta_value ) ) {
						$meta_value = implode( ',', $meta_value );
					}

					update_post_meta( $property_id, $meta_name, $meta_value );
					$property_history[] = 'Updated  EXTRA Meta ' . $meta_name . ' with meta_value ' . $meta_value;
					$mem_usage          = memory_get_usage( true );
					$mem_usage_show     = round( $mem_usage / 1048576, 2 );
					$extra_meta_log[]   = 'Memory:' . $mem_usage_show . ' Property with ID ' . $property_id . '  Updated EXTRA Meta ' . $meta_name . ' with value ' . $meta_value;
		
	
				}




	// Remove empty values and decode values in one pass
	$processed_field_values = array();
	if(isset($field_values) && is_array($field_values)){
		foreach ( $field_values as $value ) {
				if ( ! empty( $value ) ) {
						$processed_field_values[] = $value;
				}
		}
	}

	// Bulk update terms if array is not empty
	if ( ! empty( $processed_field_values ) ) {

	}






				$meta_value         = null;

			endforeach;

			$answer['property_history'] = implode( '</br>', $property_history );
			$answer['extra_meta_log']   = implode( PHP_EOL, $extra_meta_log );
		}

		$property_history = null;
		$extra_meta_log   = null;
		$options          = null;
		$permited_meta    = null;
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
		$custom_fields = '';
		if ( isset( $theme_options['wpestate_custom_fields_list'] ) ) {
			$custom_fields = $theme_options['wpestate_custom_fields_list'];
		}

		if ( ! is_array( $custom_fields ) ) {
			$custom_fields = array();
		}

		$custom_field_no = 100;

		$options = get_option( $option_name . '_admin_fields_select' );


		$test = 0;
		foreach ( $options['mls-fields'] as $key => $value ) {
			++$test;

			if ( 1 === intval($value)  &&  0 ===  intval($options['mls-fields-admin'][ $key ]) ) {
				if ( isset( $custom_fields['add_field_name'] ) && is_array( $custom_fields['add_field_name'] ) && ! in_array( $key, $custom_fields['add_field_name'] ) && '' !== $key  ) {
					++$custom_field_no;
					if ( ! is_array( $custom_fields['add_field_name'] ) ) {
						$custom_fields['add_field_name']     = array();
						$custom_fields['add_field_label']    = array();
						$custom_fields['add_field_type']     = array();
						$custom_fields['add_field_order']    = array();
						$custom_fields['add_dropdown_order'] = array();
					}

					$custom_fields['add_field_name'][]     = $key;
					$custom_fields['add_field_label'][]    = $options['mls-fields-label'][ $key ];
					$custom_fields['add_field_type'][]     = 'short text';
					$custom_fields['add_field_order'][]    = $custom_field_no;
					$custom_fields['add_dropdown_order'][] = '';
				} elseif ( isset( $custom_fields['add_field_name'] ) ) {
						$to_replace_key                                      = array_search( $key, $custom_fields['add_field_name'] );
						$custom_fields['add_field_label'][ $to_replace_key ] = $options['mls-fields-label'][ $key ];
				}
			} else {
				// remove item from custom fields
				$key_remove = array_search( $key, $custom_fields['add_field_name'] );
				if ( '' !== $key_remove  && isset( $custom_fields['add_field_name'][ $key_remove ] ) ) {
					unset( $custom_fields['add_field_name'][ $key_remove ] );
					unset( $custom_fields['add_field_label'][ $key_remove ] );
					unset( $custom_fields['add_field_type'][ $key_remove ] );
					unset( $custom_fields['add_field_order'][ $key_remove ] );
					unset( $custom_fields['add_dropdown_order'][ $key_remove ] );
				}
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
	 */
	public function return_theme_schema() {
		return;
	}
}
