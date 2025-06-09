<?php
/**
 * Template for the Success step of the MLSImport onboarding wizard
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
$user_data = get_option('mlsimport_onboarding_user_data', array());
$import_id = isset($user_data['import_id']) ? $user_data['import_id'] : 0;

// Get import details if available
$import_title = '';
$property_count = 0;
$auto_update = false;

if ($import_id) {
    $import_title = get_the_title($import_id);
    $auto_update = get_post_meta($import_id, 'mlsimport_item_stat_cron', true);
    
    // Count imported properties
    global $mlsimport;
    $post_type = $mlsimport->admin->env_data->get_property_post_type();
    
    $args = array(
        'post_type' => $post_type,
        'post_status' => 'any',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'MLSimport_item_inserted',
                'value' => $import_id,
                'compare' => '=',
            ),
        ),
    );
    
    $query = new WP_Query($args);
    $property_count = $query->found_posts;
    wp_reset_postdata();
}

// Get the current theme
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

// Define quick links
$quick_links = array(
    array(
        'title' => __('View Properties', 'mlsimport'),
        'description' => __('See your imported MLS listings', 'mlsimport'),
        'url' => admin_url('edit.php?post_type=' . $post_type),
        'icon' => 'dashicons-admin-home',
    ),
    array(
        'title' => __('Import Settings', 'mlsimport'),
        'description' => __('Configure MLS Import settings', 'mlsimport'),
        'url' => admin_url('admin.php?page=mlsimport_plugin_options'),
        'icon' => 'dashicons-admin-settings',
    ),
    array(
        'title' => __('Field Mapping', 'mlsimport'),
        'description' => __('Customize MLS field mappings', 'mlsimport'),
        'url' => admin_url('admin.php?page=mlsimport_plugin_options&tab=field_options'),
        'icon' => 'dashicons-admin-generic',
    ),
    array(
        'title' => __('Create New Import', 'mlsimport'),
        'description' => __('Set up additional import configurations', 'mlsimport'),
        'url' => admin_url('post-new.php?post_type=mlsimport_item'),
        'icon' => 'dashicons-plus',
    ),
);

// Define next steps
$next_steps = array(
    array(
        'title' => __('Create Additional Import Configurations', 'mlsimport'),
        'description' => __('Set up separate imports for different cities, property types, or price ranges.', 'mlsimport'),
    ),
    array(
        'title' => __('Customize Field Mappings', 'mlsimport'),
        'description' => __('Fine-tune how MLS fields map to your theme\'s property fields.', 'mlsimport'),
    ),
    array(
        'title' => __('Adjust Import Schedule', 'mlsimport'),
        'description' => __('Modify automated sync settings or set up server-side cron jobs for better performance.', 'mlsimport'),
    ),
    array(
        'title' => __('Configure Property Display', 'mlsimport'),
        'description' => sprintf(__('Customize how properties appear in %s with imported MLS data.', 'mlsimport'), $detected_theme),
    ),
);

// Define resources
$resources = array(
    array(
        'title' => __('Documentation', 'mlsimport'),
        'description' => __('Comprehensive guides and tutorials', 'mlsimport'),
        'url' => 'https://mlsimport.com/documentation/',
        'icon' => 'dashicons-media-document',
    ),
    array(
        'title' => __('Knowledge Base', 'mlsimport'),
        'description' => __('Answers to common questions', 'mlsimport'),
        'url' => 'https://mlsimport.com/knowledge-base/',
        'icon' => 'dashicons-book',
    ),
    array(
        'title' => __('Support', 'mlsimport'),
        'description' => __('Get help from our team', 'mlsimport'),
        'url' => 'https://mlsimport.com/support/',
        'icon' => 'dashicons-sos',
    ),
    array(
        'title' => __('Video Tutorials', 'mlsimport'),
        'description' => __('Step-by-step visual guides', 'mlsimport'),
        'url' => 'https://mlsimport.com/videos/',
        'icon' => 'dashicons-video-alt3',
    ),
);
?>

<div class="mlsimport-success-content">
    <div class="mlsimport-success-header">
        <div class="mlsimport-success-icon">
            <span class="dashicons dashicons-yes-alt"></span>
        </div>
        <div class="mlsimport-success-message">
            <h2><?php _e('Setup Complete!', 'mlsimport'); ?></h2>
            <p>
                <?php echo sprintf(
                    __('Congratulations! You\'ve successfully set up MLSImport to connect your %s website with your MLS provider.', 'mlsimport'),
                    $detected_theme
                ); ?>
            </p>
        </div>
    </div>
    
    <?php if ($import_id && $property_count > 0) : ?>
        <div class="mlsimport-import-summary">
            <h3><?php _e('Import Summary', 'mlsimport'); ?></h3>
            <div class="mlsimport-summary-grid">
                <div class="mlsimport-summary-item">
                    <div class="mlsimport-summary-value"><?php echo esc_html($import_title); ?></div>
                    <div class="mlsimport-summary-label"><?php _e('Import Configuration', 'mlsimport'); ?></div>
                </div>
                <div class="mlsimport-summary-item">
                    <div class="mlsimport-summary-value"><?php echo esc_html($property_count); ?></div>
                    <div class="mlsimport-summary-label"><?php _e('Properties Imported', 'mlsimport'); ?></div>
                </div>
                <div class="mlsimport-summary-item">
                    <div class="mlsimport-summary-value">
                        <?php echo $auto_update ? esc_html__('Enabled', 'mlsimport') : esc_html__('Disabled', 'mlsimport'); ?>
                    </div>
                    <div class="mlsimport-summary-label"><?php _e('Auto Updates', 'mlsimport'); ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="mlsimport-quick-links">
        <h3><?php _e('Quick Links', 'mlsimport'); ?></h3>
        <div class="mlsimport-links-grid">
            <?php foreach ($quick_links as $link) : ?>
                <a href="<?php echo esc_url($link['url']); ?>" class="mlsimport-quick-link">
                    <div class="mlsimport-quick-link-icon">
                        <span class="dashicons <?php echo esc_attr($link['icon']); ?>"></span>
                    </div>
                    <div class="mlsimport-quick-link-content">
                        <div class="mlsimport-quick-link-title"><?php echo esc_html($link['title']); ?></div>
                        <div class="mlsimport-quick-link-description"><?php echo esc_html($link['description']); ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="mlsimport-next-steps">
        <h3><?php _e('Recommended Next Steps', 'mlsimport'); ?></h3>
        <div class="mlsimport-steps-list">
            <?php foreach ($next_steps as $index => $step) : ?>
                <div class="mlsimport-next-step">
                    <div class="mlsimport-step-number"><?php echo esc_html($index + 1); ?></div>
                    <div class="mlsimport-step-content">
                        <div class="mlsimport-step-title"><?php echo esc_html($step['title']); ?></div>
                        <div class="mlsimport-step-description"><?php echo esc_html($step['description']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    

   
</div>

<style>
.mlsimport-success-content {
    max-width: 800px;
}

/* Success Header */
.mlsimport-success-header {
    display: flex;
    align-items: center;
    margin-bottom: 30px;
    background-color: #ecf7ed;
    padding: 25px;
    border-radius: 4px;
}

.mlsimport-success-icon {
    margin-right: 20px;
}

.mlsimport-success-icon .dashicons {
    font-size: 50px;
    width: 50px;
    height: 50px;
    color: #46b450;
}

.mlsimport-success-message h2 {
    margin-top: 0;
    margin-bottom: 10px;
    font-size: 24px;
    color: #46b450;
}

.mlsimport-success-message p {
    font-size: 16px;
    margin: 0;
}

/* Import Summary */
.mlsimport-import-summary {
    margin-bottom: 40px;
    background-color: #f9f9f9;
    padding: 20px;
    border-radius: 4px;
}

.mlsimport-import-summary h3 {
    margin-top: 0;
    margin-bottom: 20px;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.mlsimport-summary-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
}

.mlsimport-summary-item {
    flex: 1;
    min-width: 150px;
    text-align: center;
}

.mlsimport-summary-value {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 5px;
}

.mlsimport-summary-label {
    color: #666;
    font-size: 14px;
}

/* Quick Links */
.mlsimport-quick-links {
    margin-bottom: 40px;
}

.mlsimport-quick-links h3,
.mlsimport-next-steps h3,
.mlsimport-resources h3 {
    margin-top: 0;
    margin-bottom: 20px;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.mlsimport-links-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
}

.mlsimport-quick-link {
    display: flex;
    align-items: center;
    padding: 15px;
    background-color: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 4px;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s ease;
}

.mlsimport-quick-link:hover {
    background-color: #f0f0f0;
    border-color: #ccc;
}

.mlsimport-quick-link-icon {
    margin-right: 15px;
}

.mlsimport-quick-link-icon .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    color: #4f46e5;
}

.mlsimport-quick-link-title {
    font-weight: 600;
    margin-bottom: 5px;
}

.mlsimport-quick-link-description {
    font-size: 13px;
    color: #666;
}

/* Next Steps */
.mlsimport-next-steps {
    margin-bottom: 40px;
}

.mlsimport-steps-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.mlsimport-next-step {
    display: flex;
    align-items: flex-start;
    padding: 15px;
    background-color: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 4px;
}

.mlsimport-step-number {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    background-color: #4f46e5;
    color: #fff;
    border-radius: 50%;
    font-weight: 600;
    margin-right: 15px;
    flex-shrink: 0;
}

.mlsimport-step-title {
    font-weight: 600;
    margin-bottom: 5px;
}

.mlsimport-step-description {
    font-size: 14px;
    color: #666;
}

/* Resources */
.mlsimport-resources {
    margin-bottom: 40px;
}

.mlsimport-resources-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
}

.mlsimport-resource-link {
    display: flex;
    align-items: center;
    padding: 15px;
    background-color: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 4px;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s ease;
}

.mlsimport-resource-link:hover {
    background-color: #f0f0f0;
    border-color: #ccc;
}

.mlsimport-resource-icon {
    margin-right: 15px;
}

.mlsimport-resource-icon .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    color: #00a0d2;
}

.mlsimport-resource-title {
    font-weight: 600;
    margin-bottom: 5px;
}

.mlsimport-resource-description {
    font-size: 13px;
    color: #666;
}

/* Finish Button */
.mlsimport-finish-actions {
    text-align: center;
    margin-top: 30px;
}

.mlsimport-finish-button {
    padding: 10px 20px !important;
    font-size: 16px !important;
    height: auto !important;
}

/* Responsive Adjustments */
@media (max-width: 782px) {
    .mlsimport-links-grid,
    .mlsimport-resources-grid {
        grid-template-columns: 1fr;
    }
    
    .mlsimport-success-header {
        flex-direction: column;
        text-align: center;
    }
    
    .mlsimport-success-icon {
        margin-right: 0;
        margin-bottom: 15px;
    }
}
</style>