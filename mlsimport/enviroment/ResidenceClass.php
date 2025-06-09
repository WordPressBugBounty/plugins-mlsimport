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
	 * Deal with extra meta
	 */
	public function mlsimportSaasSetExtraMeta2( $property_id, $property ) {
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
     * Deal with extra meta
     */
    public function mlsimportSaasSetExtraMeta($property_id, $property) {
        // Memory tracking
        $startMemory = memory_get_usage(true);
        $startMemoryFormatted = round($startMemory / 1048576, 2);
        //error_log("[Memory-ExtraMeta] Starting extra meta processing with memory: {$startMemoryFormatted} MB");

        $property_history = array();
        $answer = array();
        
        if (!isset($property['extra_meta']) || !is_array($property['extra_meta'])) {
            $answer['property_history'] = '';
            
            $endMemory = memory_get_usage(true);
            $endMemoryFormatted = round($endMemory / 1048576, 2);
            $memoryDiff = $endMemoryFormatted - $startMemoryFormatted;
            //error_log("[Memory-ExtraMeta] No extra meta, finished with memory: {$endMemoryFormatted} MB (change: +{$memoryDiff} MB)");
            
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
            //error_log("[Memory-ExtraMeta] Processing batch {$batch_count} with memory: {$batchMemoryFormatted} MB");
            
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
                
                $original_meta_name = $meta_name;
                $meta_name_lower = strtolower($meta_name);
                
                // Process custom postmeta mapping
                if (isset($options['mls-fields-map-postmeta'][$original_meta_name]) && $options['mls-fields-map-postmeta'][$original_meta_name] !== '') {
                    $new_post_meta_key = $options['mls-fields-map-postmeta'][$original_meta_name];
                    
                    if (is_array($meta_value)) {
                        $meta_value = implode(',', $meta_value);
                    }
                    
                    update_post_meta($property_id, $new_post_meta_key, $meta_value);
                    $property_history[] = 'Updated CUSTOM post meta ' . $new_post_meta_key . ' original ' . $meta_name;
                } 
                // Process custom taxonomy mapping
                else if (isset($options['mls-fields-map-taxonomy'][$original_meta_name]) && $options['mls-fields-map-taxonomy'][$original_meta_name] !== '') {
                    $new_taxonomy = $options['mls-fields-map-taxonomy'][$original_meta_name];
                    $custom_label = isset($options['mls-fields-label'][$original_meta_name]) ? $options['mls-fields-label'][$original_meta_name] : '';
                    
                    if ($custom_label == 'none') {
                        $custom_label = '';
                    }
                    
                    if (!is_array($meta_value)) {
                        $meta_value_with_label = array(trim($custom_label . ' ' . $meta_value));
                    } else {
                        $meta_value_with_label = array(trim($custom_label . ' ' . implode(', ', $meta_value)));
                    }
                    
                    wp_set_object_terms($property_id, $meta_value_with_label, $new_taxonomy, true);
                    
                    // Fix the clean_term_cache call to avoid SQL errors
                    if (!empty($meta_value_with_label)) {
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
                    if (is_array($meta_value)) {
                        $meta_value = implode(',', $meta_value);
                    }
                    
                    update_post_meta($property_id, $meta_name_lower, $meta_value);
                    $property_history[] = 'Updated EXTRA Meta ' . $meta_name_lower;
                }
                
                // Clear each variable after use
                $meta_value = null;
            }
            
            // Clean up after each batch
            wp_cache_flush();
            gc_collect_cycles();
            
            $afterBatchMemory = memory_get_usage(true);
            $afterBatchMemoryFormatted = round($afterBatchMemory / 1048576, 2);
            $batchMemoryDiff = $afterBatchMemoryFormatted - $batchMemoryFormatted;
            //error_log("[Memory-ExtraMeta] Finished batch {$batch_count} with memory: {$afterBatchMemoryFormatted} MB (change: +{$batchMemoryDiff} MB)");
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
        //error_log("[Memory-ExtraMeta] Finished extra meta processing with memory: {$endMemoryFormatted} MB (change: +{$memoryDiff} MB)");
        
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
