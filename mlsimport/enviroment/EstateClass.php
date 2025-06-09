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
	 * Deal with extra meta
	 */
	public function mlsimportSaasSetExtraMeta( $property_id, $property ) {
		$property_history = '';
		$extra_meta_log   = '';
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

				$meta_name = strtolower( $meta_name );
				if ( is_array( $meta_value ) ) {
					$meta_value = implode( ',', $meta_value );
				}
				update_post_meta( $property_id, $meta_name, $meta_value );
				
				if( isset( $options['mls-fields-map-postmeta'][ $meta_name ]) && $options['mls-fields-map-postmeta'][ $meta_name ]!==''   ){
					$new_post_meta_key=$options['mls-fields-map-postmeta'][ $meta_name ];
					update_post_meta( $property_id, $new_post_meta_key, $meta_value );
					$property_history .= 'Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_name . ' and value ' . $meta_value . '</br>';
				}
				

				$property_history .= 'Updated EXTRA Meta ' . $meta_name . ' with meta_value ' . $meta_value . '</br>';
				$extra_meta_log   .= 'Property with ID ' . $property_id . '  Updated EXTRA Meta ' . $meta_name . ' with value ' . $meta_value . PHP_EOL;
			endforeach;

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
