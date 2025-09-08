<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
$theme_schema= mlsimport_hardocde_theme_schema();

$options = get_option( 'mlsimport_admin_options' );
$mlsimport_mls_metadata_populated = get_option( 'mlsimport_mls_metadata_populated', '' );
$permited_tags = mlsimport_allowed_html_tags_content();
$post_type = '';
if (method_exists($this->env_data, 'get_property_post_type')) {
    $post_type = $this->env_data->get_property_post_type();
}

$available_taxonomies = mlsimport_get_custom_post_type_taxonomies($post_type);


global $mlsimport;
$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
$is_mls_connected = get_option('mlsimport_connection_test', '');
$mlsimport->admin->mlsimport_saas_setting_up();

if ('yes' !== $is_mls_connected) {
    $mlsimport->admin->mlsimport_saas_check_mls_connection();
    $is_mls_connected = get_option('mlsimport_connection_test', '');
}

if (trim($token) === '') {
    echo '<div class="mlsimport_warning">' . esc_html__('You are not connected to MlsImport - Please check your Username and Password.', 'mlsimport') . '</div>';
    return;
}

if ('yes' !== $is_mls_connected) {
    echo '<div class="mlsimport_warning">' . esc_html__('The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved.', 'mlsimport') . '</div>';
    return;
}

if ( 'yes' === $mlsimport_mls_metadata_populated ) {
    // We have MLS metadata, so we can show the field selection interface
    ?>
    <form method="post" class= "mlsimport-import-fields-form"  name="cleanup_options" action="options.php">
        <?php
     
        
           global $mlsimport;
           $mlsimport->admin->mlsimport_saas_setting_up();
           
   
           // Add this to the beginning of the form processing
           $options = get_option('mlsimport_admin_fields_select', array());
  
          
            // Ensure all arrays are initialized
            if (!is_array($options) || 
                (is_array($options) && empty($options)) ) {

                $mlsimport_mls_metadata_mls_data = get_option( 'mlsimport_mls_metadata_mls_data', '' );
                $metadata_api_call_data_service_property = json_decode( $mlsimport_mls_metadata_mls_data, true );
                $options = array();
                
             
        
                if (!isset($options['mls-fields'])) {
                    $options['mls-fields'] = array();
                }
        
                if (!isset($options['mls-fields-admin'])) {
                    $options['mls-fields-admin'] = array();
                }
        
                if (!isset($options['mls-fields-label'])) {
                    $options['mls-fields-label'] = array();
                }
        
                if (!isset($options['mls-fields-map-postmeta'])) {
                    $options['mls-fields-map-postmeta'] = array();
                }
        
                if (!isset($options['mls-fields-map-taxonomy'])) {
                    $options['mls-fields-map-taxonomy'] = array();
                }

              
                $order_item=0;
                if (!is_array($metadata_api_call_data_service_property)) {
                    $metadata_api_call_data_service_property = [];
                }

                ksort($metadata_api_call_data_service_property);
         
                foreach ( $metadata_api_call_data_service_property as $key => $value ) {
                    $description = 'no description ';
                    
                    $options['mls-fields'][ $key ]=0;
                    $options['field_order'][ $key ]=$order_item++;
                    $options['mls-fields-admin'][ $key ]=0 ;
                    $options['mls-fields-map-postmeta'][ $key ]='';
             
                    $options['mls-fields-label'][ $key ]='';

                    if ( array_key_exists( $key, $theme_schema ) ) {
                        $options['mls-fields'][ $key ]=1;

                        if( isset( $theme_schema[$key]['type']) && $theme_schema[$key]['type']=='taxonomy'  ){
                            $options['mls-fields-map-taxonomy'][ $key ]=$theme_schema[$key]['name'];
                        }else{
                            $options['mls-fields-map-taxonomy'][ $key ]='';
                        }   
                        
                    }
                } 

             
            }
        
        ?>
  
        
    
        <h3><?php esc_html_e( 'Select the extra fields you want to import', 'mlsimport' ); ?>:</h3>
  
        <?php

                
       // $options = get_option( 'mlsimport_admin_fields_select', array() );
     //    print_r($options);
        


        // Get the current page and search parameters
        $current_page = isset( $_GET['mlsimport_page'] ) ? intval( $_GET['mlsimport_page'] ) : 1;
        $search_term = isset( $_GET['mlsimport_search'] ) ? sanitize_text_field( $_GET['mlsimport_search'] ) : '';
        $import_filter = isset( $_GET['mlsimport_filter'] ) ? sanitize_text_field( $_GET['mlsimport_filter'] ) : 'all';
        $alpha_filter = isset( $_GET['mlsimport_alpha'] ) ? sanitize_text_field( $_GET['mlsimport_alpha'] ) : '';
        
        // Set up render parameters
        $render_params = array(
            'page' => $current_page,
            'fields_per_page' => 99999, // Adjust as needed
            'search_term' => $search_term,
            'import_filter' => $import_filter,
            'alpha_filter' => $alpha_filter,
            'show_pagination' => true,
            'show_filters' => true,
            'show_stats' => true,
            'enable_drag_drop' => true,
            'form_action' => 'options.php',
            'form_method' => 'post',
            'form_name' => 'cleanup_options',
            'nonce_field' => 'mlsimport_admin_fields_select',
            'plugin_name' => 'mlsimport',
        );
        


		
     



        // Then in the admin page, update the function call:
		echo render_mls_field_selection_interface(
            $options,
			$options, 
			$render_params, 
			$theme_schema
		);
        ?>
        
        <input type="hidden" name="mlsimport_admin_fields_select[mls-fields-admin][force_rand]" value="<?php echo esc_attr( wp_rand() ); ?>">
        
     
        <?php mlsimport_add_field_selector_nonce(); ?>
    </form>
    <?php
} else {
    // We don't have MLS metadata yet, show waiting message
    global $mlsimport;
    $token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
    if ( trim( $token ) !== '' ) {
        ?>
        <div class="mlsimport_warning mlsimport_validated">
            <?php 
            esc_html_e( 'We need to gather some information about your MLS. Please Stand By! ', 'mlsimport' ); 
            ?>
        </div>
        <?php
    } else {
        esc_html_e( 'You are not connected to MLS Import', 'mlsimport' );
    }
}
?>
<input type="hidden" id="mlsimport_saas_get_metadata" value="<?php echo esc_attr( wp_create_nonce( "mlsimport_saas_get_metadata" ) ); ?>" />