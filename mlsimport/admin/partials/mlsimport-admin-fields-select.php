<?php 
/**
 * Admin partial: "Select Import fields" tab (Field Options).
 *
 * Renders the RESO field-selection interface for the active MLS. It first gates
 * on connectivity (a valid SaaS token AND a successful MLS connection), then — if
 * the MLS metadata has been populated — builds the per-field options structure
 * (defaults derived from the theme schema) and hands it to
 * render_mls_field_selection_interface(). Until metadata arrives it shows a
 * "please stand by" notice. The progressive-save JS persists edits field by field.
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

// Block direct access outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
// Theme-specific RESO->post-meta schema (defines which fields map where by default).
$theme_schema= mlsimport_hardocde_theme_schema();

// Primary plugin options and the metadata-populated flag for this MLS.
$options = get_option( 'mlsimport_admin_options' );
$mlsimport_mls_metadata_populated = get_option( 'mlsimport_mls_metadata_populated', '' );
// Allowed HTML tag set for any rendered content.
$permited_tags = mlsimport_allowed_html_tags_content();
// Resolve the active theme's property post type (if the adapter exposes it).
$post_type = '';
if (method_exists($this->env_data, 'get_property_post_type')) {
    $post_type = $this->env_data->get_property_post_type();
}

// Taxonomies registered against that post type — the taxonomy-mapping dropdowns use these.
$available_taxonomies = mlsimport_get_custom_post_type_taxonomies($post_type);


// SaaS/MLS connection gate: fetch the cached API token and prior connection result.
global $mlsimport;
$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
$is_mls_connected = get_option('mlsimport_connection_test', '');
$mlsimport->admin->mlsimport_saas_setting_up();

// Not yet confirmed connected — re-test the MLS connection now and re-read the flag.
if ('yes' !== $is_mls_connected) {
    $mlsimport->admin->mlsimport_saas_check_mls_connection();
    $is_mls_connected = get_option('mlsimport_connection_test', '');
}

// No SaaS token — the account credentials are missing/invalid; stop here.
if (trim($token) === '') {
    echo '<div class="mlsimport_warning">' . esc_html__('You are not connected to MlsImport - Please check your Username and Password.', 'mlsimport') . '</div>';
    return;
}

// Have a token but the MLS itself did not connect — stop here.
if ('yes' !== $is_mls_connected) {
    echo '<div class="mlsimport_warning">' . esc_html__('The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved.', 'mlsimport') . '</div>';
    return;
}

// Metadata is present — render the full field-selection form.
if ( 'yes' === $mlsimport_mls_metadata_populated ) {
    // We have MLS metadata, so we can show the field selection interface
    ?>
    <form method="post" class= "mlsimport-import-fields-form"  name="cleanup_options" action="options.php">
        <?php


           // Add this to the beginning of the form processing
           // Current saved field-selection options (empty on first visit).
           $options = get_option('mlsimport_admin_fields_select', array());
  
          
            // Ensure all arrays are initialized
            // First run (no saved options) — seed the structure from the MLS metadata.
            if (!is_array($options) ||
                (is_array($options) && empty($options)) ) {

                // Raw MLS field metadata (JSON) captured during connection.
                $mlsimport_mls_metadata_mls_data = get_option( 'mlsimport_mls_metadata_mls_data', '' );
                $metadata_api_call_data_service_property = json_decode( $mlsimport_mls_metadata_mls_data, true );
                $options = array();



                // Whether each field is imported (0/1).
                if (!isset($options['mls-fields'])) {
                    $options['mls-fields'] = array();
                }

                // Whether each field is admin-only (0/1).
                if (!isset($options['mls-fields-admin'])) {
                    $options['mls-fields-admin'] = array();
                }

                // Per-field display label override.
                if (!isset($options['mls-fields-label'])) {
                    $options['mls-fields-label'] = array();
                }

                // Per-field target post-meta key.
                if (!isset($options['mls-fields-map-postmeta'])) {
                    $options['mls-fields-map-postmeta'] = array();
                }

                // Per-field target taxonomy.
                if (!isset($options['mls-fields-map-taxonomy'])) {
                    $options['mls-fields-map-taxonomy'] = array();
                }


                // Running index used to record each field's display order.
                $order_item=0;
                // Guard against non-array metadata (bad/empty JSON).
                if (!is_array($metadata_api_call_data_service_property)) {
                    $metadata_api_call_data_service_property = [];
                }

                // Alphabetise fields by key for a stable initial order.
                ksort($metadata_api_call_data_service_property);

                // Seed defaults for every MLS field.
                foreach ( $metadata_api_call_data_service_property as $key => $value ) {
                    $description = 'no description ';

                    // Default: not imported, next in order, not admin-only, no meta/label mapping.
                    $options['mls-fields'][ $key ]=0;
                    $options['field_order'][ $key ]=$order_item++;
                    $options['mls-fields-admin'][ $key ]=0 ;
                    $options['mls-fields-map-postmeta'][ $key ]='';
             
                    $options['mls-fields-label'][ $key ]='';

                    // Field is part of the theme schema — turn it on by default.
                    if ( array_key_exists( $key, $theme_schema ) ) {
                        $options['mls-fields'][ $key ]=1;

                        // Schema marks it as a taxonomy — pre-map it to that taxonomy name.
                        if( isset( $theme_schema[$key]['type']) && $theme_schema[$key]['type']=='taxonomy'  ){
                            $options['mls-fields-map-taxonomy'][ $key ]=$theme_schema[$key]['name'];
                        }else{
                            // Otherwise leave the taxonomy mapping empty (meta field).
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
        // Pagination + filter/search state, all read from the query string.
        $current_page = isset( $_GET['mlsimport_page'] ) ? intval( $_GET['mlsimport_page'] ) : 1;
        $search_term = isset( $_GET['mlsimport_search'] ) ? sanitize_text_field( $_GET['mlsimport_search'] ) : '';
        $import_filter = isset( $_GET['mlsimport_filter'] ) ? sanitize_text_field( $_GET['mlsimport_filter'] ) : 'all';
        $alpha_filter = isset( $_GET['mlsimport_alpha'] ) ? sanitize_text_field( $_GET['mlsimport_alpha'] ) : '';
        
        // Set up render parameters
        // Config passed to the field-selection renderer (paging, filters, form, UI toggles).
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
        // Render the full drag-and-drop field-selection table.
		echo render_mls_field_selection_interface(
            $options,
			$options, 
			$render_params, 
			$theme_schema
		);
        ?>
        
        <input type="hidden" name="mlsimport_admin_fields_select[mls-fields-admin][force_rand]" value="<?php echo esc_attr( wp_rand() ); ?>">
        
     
        <?php
        // Output the security nonce that the field-selector save handler checks.
        mlsimport_add_field_selector_nonce(); ?>
    </form>
    <?php
} else {
    // We don't have MLS metadata yet, show waiting message
    // Connected but metadata still being gathered — show a stand-by notice.
    global $mlsimport;
    $token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
    // Token present — connected, just waiting on metadata.
    if ( trim( $token ) !== '' ) {
        ?>
        <div class="mlsimport_warning mlsimport_validated">
            <?php 
            esc_html_e( 'We need to gather some information about your MLS. Please Stand By! ', 'mlsimport' ); 
            ?>
        </div>
        <?php
    } else {
        // No token — not connected to the SaaS at all.
        esc_html_e( 'You are not connected to MLS Import', 'mlsimport' );
    }
}
?>
<input type="hidden" id="mlsimport_saas_get_metadata" value="<?php echo esc_attr( wp_create_nonce( "mlsimport_saas_get_metadata" ) ); ?>" />