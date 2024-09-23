<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

function mlsimport_get_custom_post_type_taxonomies($post_type) {
    // Get the taxonomies associated with the custom post type
	$taxonomies = get_object_taxonomies($post_type , 'objects');
    
    // Initialize an array to hold the slug => label pairs
    $taxonomy_array = array();
    
    // Loop through the taxonomies and fill the array
    foreach ($taxonomies as $taxonomy_slug => $taxonomy) {
        $taxonomy_array[$taxonomy_slug] = $taxonomy->label;
    }
    
    return $taxonomy_array;
}


/*
 *
 * Request list of ready to go MLS
 *
 *
 */

 function mlsimport_allowed_html_tags_content() {
    // Define the allowable HTML tags and their attributes
    $allowed_tags = array(
        'a' => array(
            'href' => array(),
            'title' => array(),
            'rel' => array(),
            'target' => array(),
        ),
        'b' => array(),
        'i' => array(),
        'p' => array(),
        'br' => array(),
        'ul' => array(),
        'ol' => array(),
        'li' => array(),
        'strong' => array(),
        'em' => array(),
        'blockquote' => array(),
        'code' => array(),
        'pre' => array(),
        'select' => array( // Allow <select> and its attributes
            'name' => array(),
            'id' => array(),
            'class' => array(),
            'multiple' => array(),
            'required' => array(),
        ),
        'option' => array( // Allow <option> and its attributes
            'value' => array(),
            'selected' => array(),
        ),
		'div' => array( // Allow <div> and its attributes
            'id' => array(),
            'class' => array(),
            'style' => array(), // Use with caution, considering inline CSS security implications
        ),
		'h4' => array( 
            'id' => array(),
            'class' => array(),
           
        ),
		'label' => array( 
            'id' => array(),
            'class' => array(),
           
        ),
		'fieldset' => array( 
            'id' => array(),
            'class' => array(),
           
        ),
		'input' => array( 
            'id' => array(),
            'class' => array(),
            'type' => array(), 
			'name' => array(), 
			'value' => array(), 
			'checked'=>array()
			
			
        ),
    );


    return $allowed_tags;
}


/*
 *
 * Request list of ready to go MLS
 *
 *
 */
function mlsimport_saas_request_list() {

	$mls_data = get_transient( 'mlsimport_ready_to_go_mlsimport_data' );

	if (  false === $mls_data  ) {
		$theme_Start = new ThemeImport();
		$values      = array();

		$answer = $theme_Start::globalApiRequestSaas( 'mls', $values, 'GET' );

		if ( isset( $answer['succes'] ) &&  true === $answer['succes']  ) {
			$mls_data      = $answer['mls_list'];
			$mls_data['0'] = esc_html__( 'My MLS is not on this list', 'mlsimport' );

			$autofill_array = array();
			foreach ( $mls_data as $key => $value ) {
					$temp_array       = array(
						'label' => $value,
						'value' => $key,
					);
					$autofill_array[] = $temp_array;
			}

			$mls_data = wp_json_encode( $autofill_array );

			set_transient( 'mlsimport_ready_to_go_mlsimport_data', $mls_data, 60 * 60 * 24 );
		} else {
			$mls_data      = array();
			$mls_data['0'] = esc_html__( 'We could not connect to MLSimport Api', 'mlsimport' );
		}
	}

	return $mls_data;
}


/*
 *  sanitize multidimensional array
 *
 *
 * */
function mlsimport_sanitize_multi_dimensional_array($data){
	if ( is_array( $data ) ) {
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $data[ $key ] = mlsimport_sanitize_multi_dimensional_array( $value );
            } else {
                
                $data[ $key ] = sanitize_text_field( wp_unslash( $value ));
            }
        }
    } else {
        $data = sanitize_text_field( wp_unslash( $data) );
    }

    return $data;
}


 /*
 *  Lopp troght the listings and get Listing key
 *
 *
 *
 *
 *
 * */

function mlsimport_saas_reconciliation_event_function() {

	global $mlsimport;
	$token   = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
	$options = get_option( 'mlsimport_admin_options' );

	if ( isset( $options['mlsimport_mls_name'] ) && '' !==  $options['mlsimport_mls_name']  ) {
		$mlsimport->admin->mlsimport_saas_start_doing_reconciliation();
	}
}

/*
 *  Admin extra columns for MlsImport Items
 *
 *
 *
 *
 *
 * */

add_filter( 'manage_edit-mlsimport_item_columns', 'mlsimport_items_columns_admin' );

if ( ! function_exists( 'mlsimport_items_columns_admin' ) ) :

	function mlsimport_items_columns_admin( $columns ) {
		$slice = array_slice( $columns, 2, 2 );
		unset( $columns['comments'] );
		unset( $slice['comments'] );
		$splice = array_splice( $columns, 2 );

		$columns['mlsimport_items_params'] = esc_html__( 'Import Parameters', 'mlsimport' );
		$columns['mlsimport_last_action']  = esc_html__( 'Last action', 'mlsimport' );
		$columns['mlsimport_autoupdates']  = esc_html__( 'Auto Update Enabled', 'mlsimport' );

		return array_merge( $columns, array_reverse( $slice ) );
	}

endif; // end   wpestate_my_columns




/*
 *  Admin extra columns for MlsImport Items - Populate with data display value
 *
 *
 *
 *
 *
 * */
function mlsimport_populate_columns_params_display_value( $value ) {
	$display_value = '';
	if ( is_array( $value ) ) {
		foreach ( $value as $key_item => $item_name ) :
			$display_value .= $item_name . ',';
		endforeach;
		$display_value = rtrim( $display_value, ',' );
	} else {
		$display_value = $value;
	}

	return $display_value;
}
/*
 *  Admin extra columns for MlsImport Items - Populate with data
 *
 *
 *
 *
 *
 * */

function mlsimport_populate_columns_params_display( $postID ) {
	global $mlsimport;
	$field_import = $mlsimport->admin->mlsimport_saas_return_mls_fields();

	$select_all_none = array(
		'InternetAddressDisplayYN',
		'InternetEntireListingDisplayYN',
		'PostalCode',
		'ListAgentKey',
		'ListAgentMlsId',
		'ListOfficeKey',
		'ListOfficeMlsId',
		'ListingID',
		'StandardStatus',
		'extraCity',
		'extraCounty',
		'Exclude_ListOfficeKey',
		'Exclude_ListOfficeMlsId',
		'Exclude_ListAgentKey',
		'Exclude_ListAgentMlsId',

	);
	foreach ( $field_import as $key => $field ) :
		$display_value = '';
		$name_check    = strtolower( 'mlsimport_item_' . $key . '_check' );
		$name          = strtolower( 'mlsimport_item_' . $key );

		$value       = get_post_meta( $postID, $name, true );
		$value_check = get_post_meta( $postID, $name_check, true );

		$is_checkbox_admin = 0;
		if ( 1 ===  intval($value_check)  ) {
			$is_checkbox_admin = 1;
		}

		if ( ! in_array( $key, $select_all_none ) ) {
			if ( 1 === intval($is_checkbox_admin)  ) {
				$display_value = esc_html__( 'ALL', 'mlsimport' );
			} else {
				$display_value = mlsimport_populate_columns_params_display_value( $value );
			}
		} else {
			$display_value = mlsimport_populate_columns_params_display_value( $value );
		}

		if ( '' !==  $display_value  ) { ?>
			<strong>
				<?php
				print esc_html(  ucfirst( str_replace( 'Select ', '', $field['label'] ) ) );
				?>
			 :</strong>
			<?php
			print esc_html($display_value).'</br>';
			
		}
	endforeach;
}



add_action( 'manage_posts_custom_column', 'mlsimport_populate_columns' );
if ( ! function_exists( 'mlsimport_populate_columns' ) ) :

	function mlsimport_populate_columns( $column ) {

		global $post;

		if ( 'mlsimport_items_params' === $column ) {
			$mlsimport_item_min_price = floatval( get_post_meta( $post->ID, 'mlsimport_item_min_price', true ) );
			$mlsimport_item_max_price = floatval( get_post_meta( $post->ID, 'mlsimport_item_max_price', true ) );
			?>

	
			<!-- Strong tag for emphasizing the label -->
			<strong>
				<?php esc_html_e('Minimum price:', 'mlsimport'); ?>
			</strong>

			<?php echo esc_html($mlsimport_item_min_price); ?><br>

			<strong>
				<?php esc_html_e('Maximum price:', 'mlsimport'); ?>
			</strong>
		
			<?php echo esc_html($mlsimport_item_max_price); ?><br>
			<?php
	
			mlsimport_populate_columns_params_display( $post->ID );
		} elseif ( 'mlsimport_last_action' === $column ) {
			$last_date = get_post_meta( $post->ID, 'mlsimport_last_date', true );
			if ( '' !==  $last_date  ) {
				print esc_html($last_date) . ' </br>';
				esc_html_e( 'On this date we found new or edited listings.', 'mlsimport' );
			} else {
				esc_html_e( 'Not available - sync option may be off.', 'mlsimport' );
			}
		} elseif ( 'mlsimport_autoupdates' === $column ) {
			$mlsimport_item_stat_cron = esc_html( get_post_meta( $post->ID, 'mlsimport_item_stat_cron', true ) );

			if ( intval( $mlsimport_item_stat_cron ) > 0 ) {
				?>
				yes
				<?php
			} else {
				?>
				no
				<?php
			}
		}
	}
endif;
