<?php
/**
 * Template for the Field Mapping step of the MLSImport onboarding wizard
 *
 * @link       https://mlsimport.com/
 * @since      6.1.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/admin/partials/mlsimport-onboarding-steps
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
// Get post type
$post_type = '';
global $mlsimport;
if (method_exists($mlsimport->admin->env_data, 'get_property_post_type')) {
    $post_type = $mlsimport->admin->env_data->get_property_post_type();
}


// Get saved data
$field_data = mlsimport_get_onboarding_step_data('field-mapping');

// Set default values
$template = isset($field_data['template']) ? $field_data['template'] : 'standard';
$custom_fields = isset($field_data['custom_fields']) ? $field_data['custom_fields'] : array();

// Get MLS metadata from API
$mlsimport_mls_metadata_mls_data = get_option('mlsimport_mls_metadata_mls_data', '');
$mlsimport_mls_metadata_theme_schema = get_option('mlsimport_mls_metadata_theme_schema', '');
// Get taxonomies
$available_taxonomies = mlsimport_get_custom_post_type_taxonomies($post_type);

$mlsimport_mls_metadata_populated = get_option( 'mlsimport_mls_metadata_populated', '' );

// Parse metadata
$mls_data = is_string($mlsimport_mls_metadata_mls_data) ? json_decode($mlsimport_mls_metadata_mls_data, true) : $mlsimport_mls_metadata_mls_data;
$theme_schema= mlsimport_hardocde_theme_schema();
// Define template options
$template_options = array(
    'essential' => array(
        'title' => __('Essential', 'mlsimport'),
        'description' => __('Import only the most essential fields needed for a basic property listing.', 'mlsimport'),
        'field_count' => 15,
    ),
    'standard' => array(
        'title' => __('Standard', 'mlsimport'),
        'description' => __('A balanced selection of fields suitable for most real estate websites.', 'mlsimport'),
        'field_count' => 30,
    ),
    'complete' => array(
        'title' => __('Complete', 'mlsimport'),
        'description' => __('Import all available fields for the most comprehensive property listings.', 'mlsimport'),
        'field_count' => is_array($mls_data) ? count($mls_data) : '700+',
    ),
);

// Get the current theme to provide tailored advice
$current_theme = wp_get_theme();
$theme_name = $current_theme->get('Name');

// Detect supported theme
$supported_themes = array(
    'WpResidence' => 'WP Residence',
    'houzez' => 'Houzez',
    'RealHomes' => 'Real Homes',
    'Wpestate' => 'WP Estate',
);

$detected_theme = 'your theme';
foreach ($supported_themes as $theme_key => $theme_label) {
    if (strtolower($theme_name) === strtolower($theme_key) || strpos(strtolower($theme_name), strtolower($theme_key)) !== false) {
        $detected_theme = $theme_label;
        break;
    }
}



if ( 'yes' === $mlsimport_mls_metadata_populated ) { 
    ?>
   <form method="post" name="cleanup_options" action="options.php">
    <?php
    // We have MLS metadata, so we can show the field selection interface
    ?>

        <?php
           settings_fields( 'mlsimport_admin_fields_select' );
           do_settings_sections( 'mlsimport_admin_fields_select' );
        
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
        
   
  
      
     
// Set up render parameters
$render_params = array(
    'page' => 1,
    'fields_per_page' => 15, // Show limited fields for onboarding
    'search_term' => '',
    'import_filter' => 'selected', // Show selected fields by default
    'alpha_filter' => '',
    'show_pagination' => true,
    'show_filters' => true,
    'show_stats' => true,
    'enable_drag_drop' => true,
    'form_action' => 'options.php',
    'form_method' => 'post',
    'form_name' => 'mlsimport_onboarding_form',
    'nonce_field' => 'mlsimport_admin_fields_select',
    'plugin_name' => 'mlsimport',
);

?>

<div class="mlsimport-field-mapping-content">
    <div class="mlsimport-section">
   
    
        
        <div class="mlsimport-section-inner">
        <button type="submit" class="button button-primary mlsimport-wizard-next mlsimport_button ">
                            <?php _e('Continue', 'mlsimport'); ?>
                        </button>
            
            <form method="post" name="mlsimport_onboarding_form" action="options.php">
                <?php
                    settings_fields('mlsimport_admin_fields_select');
                    do_settings_sections('mlsimport_admin_fields_select');
                    
                    // Add hidden field for template selection
                    echo '<input type="hidden" id="selected_template" name="selected_template" value="' . esc_attr($template) . '">';
                    
                    // Call the render function from admin
                    if (function_exists('render_mls_field_selection_interface')) {
                        echo render_mls_field_selection_interface(
                            $options,
                            $options, 
                            $render_params, 
                            $theme_schema
                        );
                    }
                    
                    // Add nonce field
                    mlsimport_add_field_selector_nonce();
                ?>
                <input type="hidden" name="mlsimport_admin_fields_select[mls-fields-admin][force_rand]" value="<?php echo esc_attr(wp_rand()); ?>">
            </form>
            
            <p class="mlsimport-field-note">
                <?php _e('You can always refine these field mappings later in MLS Import settings.', 'mlsimport'); ?>
            </p>
        </div>
    </div>
  
</div>

</form>
    <?php
} else {
    // We don't have MLS metadata yet, show waiting message
    global $mlsimport;
    $token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
    if ( trim( $token ) !== '' ) {
        ?>
        <div class="mlsimport_populate_warning">
            <?php 
            esc_html_e( 'We need to gather some information about your MLS. Please Stand By! ', 'mlsimport' ); 
            ?>
        </div>
        <?php
    } else {
        esc_html_e( 'You are not connected to MLS Import', 'mlsimport' );
    }
    ?>
    <input type="hidden" id="mlsimport_saas_get_metadata" value="<?php echo esc_attr( wp_create_nonce( "mlsimport_saas_get_metadata" ) ); ?>" />
    <?php
}
?>
<script>
    jQuery(document).ready(function(jQuery) {
        jQuery('.mlsimport-wizard-content-field-mapping .mlsimport-wizard-next').on('click', function(e) {
            e.preventDefault();
            window.location.href = ajaxurl.replace('admin-ajax.php', 'admin.php') + '?page=mlsimport-onboarding&step=import-config';
        });
    });
</script>

<style>
.mlsimport-field-mapping-content {
    max-width: 100%;
}



.mlsimport-section-inner {
    margin-bottom: 40px;
}

.mlsimport-section-inner h3 {
    margin-top: 0;
    border-bottom: 1px solid #f0f0f0;
    padding-bottom: 10px;
    margin-bottom: 15px;
}

.mlsimport-template-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.mlsimport-template-title {
    margin: 0;
    font-size: 16px;
}

.mlsimport-template-description {
    margin: 0 0 15px 0;
    font-size: 13px;
    color: #666;
    min-height: 40px;
}

.mlsimport-template-detail {
    font-size: 12px;
    color: #666;
    padding-top: 10px;
    border-top: 1px solid #eee;
}

.mlsimport-template-field-count {
    font-weight: 600;
}

.mlsimport-field-note {
    font-style: italic;
    color: #666;
    margin-top: 15px;
}

/* Tips section */
.mlsimport-mapping-tips {
    background-color: #f9f9f9;
    border-left: 4px solid #00a0d2;
    padding: 15px;
    margin-top: 20px;
}

.mlsimport-mapping-tips h3 {
    margin-top: 0;
    margin-bottom: 10px;
    font-size: 15px;
}

.mlsimport-mapping-tips ul {
    margin-top: 10px;
    margin-left: 20px;
}

.mlsimport-mapping-tips li {
    margin-bottom: 8px;
}

</style>