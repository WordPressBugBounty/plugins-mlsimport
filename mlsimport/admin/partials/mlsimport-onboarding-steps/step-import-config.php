<?php
/**
 * Template for the Import Configuration step of the MLSImport onboarding wizard
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

// Get saved data
$import_data = mlsimport_get_onboarding_step_data('import-config');

// Set default values
$import_title = isset($import_data['import_title']) ? $import_data['import_title'] : __('Initial MLS Import', 'mlsimport');
$property_status = isset($import_data['property_status']) ? $import_data['property_status'] : 'publish';
$agent_id = isset($import_data['agent_id']) ? $import_data['agent_id'] : '';
$property_user = isset($import_data['property_user']) ? $import_data['property_user'] : get_current_user_id();
$min_price = isset($import_data['min_price']) && $import_data['min_price'] !== ''
    ? $import_data['min_price']
    : '0';
$max_price = isset($import_data['max_price']) && $import_data['max_price'] !== ''
    ? $import_data['max_price']
    : '10000000';
$property_cities = isset($import_data['property_cities']) ? $import_data['property_cities'] : array();
$property_types = isset($import_data['property_types']) ? $import_data['property_types'] : array();
$auto_update = isset($import_data['auto_update']) ? $import_data['auto_update'] : 1;

// Get MLS metadata
global $mlsimport;
$mls_metadata = get_option('mlsimport_mls_metadata_mls_enums', '');
$enums_data = json_decode($mls_metadata, true);

// Get property types
$property_type_options = array();
if (isset($enums_data['global_array']['PropertyEnums']['PropertyType'])) {
    $property_type_options = $enums_data['global_array']['PropertyEnums']['PropertyType'];
} else {
    // Default property types if metadata not available
    $property_type_options = array(
        'Residential' => 'Residential',
        'Commercial' => 'Commercial',
        'Land' => 'Land',
        'MultiFamily' => 'Multi-Family',
        'Condo/Townhome/Row Home/Co-Op' => 'Condo/Townhouse',
    );
}

// Get cities
$city_options = array();
if (isset($enums_data['global_array']['PropertyEnums']['City'])) {
    $city_options = $enums_data['global_array']['PropertyEnums']['City'];
}

// Get status options
$status_options = array(
    'publish' => __('Published', 'mlsimport'),
    'draft' => __('Draft', 'mlsimport'),
    'pending' => __('Pending Review', 'mlsimport'),
);

// Get agents
$agents = array();
if (method_exists($mlsimport->admin->env_data, 'get_agent_post_type')) {
    $agent_post_type = $mlsimport->admin->env_data->get_agent_post_type();
    
    $args = array(
        'post_type' => $agent_post_type,
        'post_status' => 'publish',
        'posts_per_page' => 100,
    );
    
    $agent_query = new WP_Query($args);
    
    if ($agent_query->have_posts()) {
        while ($agent_query->have_posts()) {
            $agent_query->the_post();
            $agents[get_the_ID()] = get_the_title();
        }
        wp_reset_postdata();
    }
}

// Get users
$users = array();
$blogusers = get_users(array('role__in' => array('administrator', 'editor', 'author'), 'orderby' => 'display_name'));
foreach ($blogusers as $user) {
    $users[$user->ID] = $user->display_name . ' (' . $user->user_login . ')';
}

// Get current theme
$current_theme = wp_get_theme();
$theme_name = $current_theme->get('Name');

// Detect supported theme
$supported_themes = array(
    'WpResidence' => 'WP Residence',
    'houzez' => 'Houzez',
    'RealHomes' => 'Real Homes',
    'Wpestate' => 'WP Estate',
);

$detected_theme = false;
foreach ($supported_themes as $theme_key => $theme_label) {
    if (strtolower($theme_name) === strtolower($theme_key) || strpos(strtolower($theme_name), strtolower($theme_key)) !== false) {
        $detected_theme = $theme_label;
        break;
    }
}
?>

<div class="mlsimport-import-config-content">
    <div class="mlsimport-section">
        <p class="mlsimport-config-intro">
            <?php _e('Configure your first property import settings. These settings will create an import job that you can run and modify later.', 'mlsimport'); ?>
        </p>
        
        <div class="mlsimport-section-inner">
            <h3><?php _e('Basic Settings', 'mlsimport'); ?></h3>
            
            <div class="mlsimport-wizard-field-group">
                <label for="mlsimport_import_title" class="mlsimport-wizard-field-label">
                    <?php _e('Import Name', 'mlsimport'); ?>
                </label>
                <input type="text" id="mlsimport_import_title" name="mlsimport_import_title" value="<?php echo esc_attr($import_title); ?>">
                <p class="mlsimport-wizard-field-help">
                    <?php _e('A descriptive name to identify this import configuration', 'mlsimport'); ?>
                </p>
            </div>
            
         

            
         
        </div>
        
        <div class="mlsimport-section-inner">
            <h3><?php _e('Property Filters', 'mlsimport'); ?></h3>
            <p><?php _e('Set criteria to determine which properties to import:', 'mlsimport'); ?></p>
            
            <div class="mlsimport-filter-group">
                <div class="mlsimport-wizard-field-group mlsimport-price-range">
                    <label class="mlsimport-wizard-field-label">
                        <?php _e('Price Range', 'mlsimport'); ?>
                    </label>
                    <div class="mlsimport-price-fields">
                        <div class="mlsimport-min-price">
                            <span class="mlsimport-price-label"><?php _e('Min', 'mlsimport'); ?>:</span>
                            <input type="text" id="mlsimport_min_price" name="mlsimport_min_price" value="<?php echo esc_attr($min_price); ?>" placeholder="0">
                        </div>
                        <div class="mlsimport-max-price">
                            <span class="mlsimport-price-label"><?php _e('Max', 'mlsimport'); ?>:</span>
                            <input type="text" id="mlsimport_max_price" name="mlsimport_max_price" value="<?php echo esc_attr($max_price); ?>" placeholder="10000000">
                        </div>
                    </div>
                    <p class="mlsimport-wizard-field-help">
                        <?php _e('Limit properties by price range (leave blank for no limit)', 'mlsimport'); ?>
                    </p>
                </div>
                
                <?php if (!empty($property_type_options)) : ?>
                    <div class="mlsimport-wizard-field-group">
                        <label for="mlsimport_property_types" class="mlsimport-wizard-field-label">
                            <?php _e('Property Types', 'mlsimport'); ?>
                        </label>
                        
                        <select id="mlsimport_property_types" name="mlsimport_property_types[]" multiple="multiple" size="5">
                            <?php foreach ($property_type_options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected(in_array($value, $property_types), true); ?>>
                                    <?php echo esc_html($value); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mlsimport-wizard-field-help">
                            <?php _e('Select property types to import (hold Ctrl/Cmd for multiple selections, leave blank for all types)', 'mlsimport'); ?>
                        </p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($city_options)) : ?>
                    <div class="mlsimport-wizard-field-group">
                        <label for="mlsimport_property_cities" class="mlsimport-wizard-field-label">
                            <?php _e('Cities', 'mlsimport'); ?>
                        </label>
                        <select id="mlsimport_property_cities" name="mlsimport_property_cities[]" multiple="multiple" size="5" class="mlsimport-city-select">
                            <?php foreach ($city_options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected(in_array($value, $property_cities), true); ?>>
                                    <?php echo esc_html($value); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mlsimport-wizard-field-help">
                            <?php _e('Select cities to import from (hold Ctrl/Cmd for multiple selections, leave blank for all cities)', 'mlsimport'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="mlsimport-config-note">
            <p>
                <strong><?php _e('Note:', 'mlsimport'); ?></strong> 
                <?php echo sprintf(__('After initial setup, you can create additional import configurations with different filters to organize listings by city, price range, or property type. Each configuration creates a separate import job in %s.', 'mlsimport'), $detected_theme ? $detected_theme : 'your theme'); ?>
            </p>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
 
    // Ensure number fields only accept numbers
    $('#mlsimport_min_price, #mlsimport_max_price').on('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
});
</script>

<style>

.mlsimport-config-intro {
    font-size: 15px;
    margin-bottom: 25px;
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

/* Price Range Field */
.mlsimport-price-fields {
    display: flex;
    gap: 20px;
}

.mlsimport-min-price,
.mlsimport-max-price {
    flex: 1;
    display: flex;
    align-items: center;
}

.mlsimport-price-label {
    margin-right: 8px;
    font-weight: 500;
}

/* Auto Update Toggle Switch */
.mlsimport-auto-update-field {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
}

.mlsimport-auto-update-field .mlsimport-wizard-field-label {
    margin-bottom: 10px;
    width: 100%;
}

.mlsimport-auto-update-field .mlsimport-wizard-field-help {
    flex: 0 0 100%;
    margin-top: 5px;
}

.mlsimport-switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
    margin-right: 10px;
}

.mlsimport-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.mlsimport-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
}

.mlsimport-slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
}

input:checked + .mlsimport-slider {
    background-color: #4f46e5;
}

input:focus + .mlsimport-slider {
    box-shadow: 0 0 1px #4f46e5;
}

input:checked + .mlsimport-slider:before {
    transform: translateX(26px);
}

.mlsimport-slider.round {
    border-radius: 24px;
}

.mlsimport-slider.round:before {
    border-radius: 50%;
}

/* Filter styling */
.mlsimport-filter-group {
    padding: 15px;
    background-color: #f9f9f9;
    border-radius: 4px;
    margin-bottom: 20px;
}

.mlsimport-filter-group .mlsimport-wizard-field-group:last-child {
    margin-bottom: 0;
}

/* Note styling */
.mlsimport-config-note {
    background-color: #f9f9f9;
    border-left: 4px solid #00a0d2;
    padding: 15px;
    margin-top: 20px;
}

.mlsimport-config-note p {
    margin: 0;
}

/* Fix for multiselect */
.mlsimport-city-select {
    width: 100%;
}

/* Select2 customizations if used */
.select2-container--default .select2-selection--multiple {
    border-color: #ddd;
}

.select2-container--default.select2-container--focus .select2-selection--multiple {
    border-color: #4f46e5;
}
</style>