<?php
/**
 * Template for the Test Import step of the MLSImport onboarding wizard
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

// Get import status
$spawn_status = '';
if ($import_id) {
    $spawn_status = get_post_meta($import_id, 'mlsimport_spawn_status', true);
}

// Check if test has been run
$test_completed = false;
if ($import_id) {
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
    $test_completed = $query->found_posts > 0;
    $imported_count = $query->found_posts;
    wp_reset_postdata();
}

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

// Get log file content if it exists
$log_content = '';
$log_path = WP_PLUGIN_DIR . '/mlsimport/logs/import_logs-' . date('Y-m-d') . '.log';
if (file_exists($log_path)) {
    $log_content = file_get_contents($log_path);
    // Get the last few lines (up to 20)
    $log_lines = explode(PHP_EOL, $log_content);
    $log_lines = array_filter($log_lines); // Remove empty lines
    $log_lines = array_slice($log_lines, -20); // Get last 20 lines
    $log_content = implode(PHP_EOL, $log_lines);
}

// Get any import errors
$import_errors = array();
if (!empty($log_content)) {
    // Extract error messages
    if (preg_match_all('/ERROR: (.+?)(?=\n|$)/i', $log_content, $matches)) {
        $import_errors = $matches[1];
    }
}
?>

<div class="mlsimport-test-import-content">
    <div class="mlsimport-section">
        <p class="mlsimport-test-intro">
            <?php _e('Now let\'s run a small test import to verify everything is working properly. We will import 5 listings from your MLS.', 'mlsimport'); ?>
        </p>
        
        <?php if (!$import_id) : ?>
            <div class="mlsimport-error-message">
                <p>
                    <?php _e('No import configuration found. Please go back to the previous step and try again.', 'mlsimport'); ?>
                </p>
            </div>
        <?php else : ?>

            

        <div class="mlsimport-section-inner">
            <h3><?php _e('Import Configuration', 'mlsimport'); ?></h3>
            <div class="mlsimport-import-summary">
                <p>
                    <strong><?php _e('Import Name:', 'mlsimport'); ?></strong> 
                    <?php echo esc_html(get_the_title($import_id)); ?>
                </p>
                <p>
                    <strong><?php _e('Status:', 'mlsimport'); ?></strong> 
                    <?php 
                        if ($test_completed) {
                            echo '<span class="mlsimport-status-success">' . esc_html__('Test Completed 2', 'mlsimport') . '</span>';
                        } elseif ($spawn_status === 'started') {
                            echo '<span class="mlsimport-status-progress">' . esc_html__('In Progress', 'mlsimport') . '</span>';
                        } else {
                            echo '<span class="mlsimport-status-pending">' . esc_html__('Ready to Test', 'mlsimport') . '</span>';
                        }
                    ?>
                </p>
                
                <?php
                // Get listing count based on filters
                global $mlsimport;
                $listing_count = 0;
                
                if ($import_id) {
                    $mlsrequest = $mlsimport->admin->mlsimport_make_listing_requests($import_id);

                    $listing_count = isset($mlsrequest['results']) ? intval($mlsrequest['results']) : 0;
                }
                ?>
                
                <p>
                    <strong><?php _e('Listings Found:', 'mlsimport'); ?></strong> 
                    <span class="mlsimport-listing-count"><?php echo esc_html($listing_count); ?></span>
                    <?php if ($listing_count === 0): ?>
                        <span class="mlsimport-zero-warning"><?php _e('(No listings match your current filters)', 'mlsimport'); ?></span>
                    <?php endif; ?>
                </p>
                
                <?php if ($test_completed) : ?>
                    <p>
                        <strong><?php _e('Properties Imported:', 'mlsimport'); ?></strong> 
                        <?php echo esc_html($imported_count); ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <?php if ($listing_count === 0): ?>
                <div class="mlsimport-zero-listings-warning">
                    <p>
                        <?php _e('Please go back and adjust your filters to broaden your search.', 'mlsimport'); ?>
                    </p>
                    <a href="<?php echo admin_url('admin.php?page=mlsimport-onboarding&step=import-config'); ?>" class="button  mlsimport_button  secondary ">
                        <?php _e('Back to Import Configuration', 'mlsimport'); ?>
                    </a>
                </div>
            <?php endif; ?>
            
            <?php if (!$test_completed && $spawn_status !== 'started' && $listing_count > 0) : ?>
                <div class="mlsimport-test-actions">
                    <button type="button" id="mlsimport-run-test"  data-post-number="<?php echo intval($listing_count);?>" data-post_id="<?php echo intval($import_id)?>" class="button mlsimport_button">
                        <?php _e('Run Test Import', 'mlsimport'); ?>
                    </button>
                    <input type="hidden" id="mlsimport_item_actions" value="<?php echo esc_attr(wp_create_nonce("mlsimport_item_actions")); ?>"/>
                    <span id="mlsimport-test-spinner" class="mlsimport-spinner" style="display: none;"></span>
                </div>
            <?php endif; ?>
        </div>




    
       
           
            
            <div class="mlsimport-test-recommendations">
                <h3><?php _e('Next Steps', 'mlsimport'); ?></h3>
                
                <?php if ($test_completed) : ?>
                    <p>
                        <?php echo sprintf(
                            __('Congratulations! You\'ve successfully imported properties from your MLS into %s.', 'mlsimport'),
                            $detected_theme
                        ); ?>
                    </p>
                    <ul>
                        <li><?php _e('Click "Continue" to complete the setup', 'mlsimport'); ?></li>
                        <li><?php _e('Your import configuration has been saved and will update automatically', 'mlsimport'); ?></li>
                        <li><?php _e('You can create additional import configurations with different criteria later', 'mlsimport'); ?></li>
                    </ul>
                <?php elseif ($spawn_status === 'started') : ?>
                    <p>
                        <?php _e('The import is currently in progress. Please wait for it to complete before continuing.', 'mlsimport'); ?>
                    </p>
                <?php else : ?>
                    <p>
                        <?php _e('Please run the test import to verify your MLS connection and configuration.', 'mlsimport'); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var testIntervalId;
    var importId = <?php echo intval($import_id); ?>;
    var testStarted = <?php echo ($spawn_status === 'started') ? 'true' : 'false'; ?>;
    var testCompleted = <?php echo $test_completed ? 'true' : 'false'; ?>;
    
    // If import already started, check status
    if (testStarted && !testCompleted) {
       // startStatusCheck();
    }
    
    // Run test import
    $('#mlsimport-run-test').on('click', function() {
        $(this).prop('disabled', true);
        $('#mlsimport-test-spinner').show();
        
        $('.mlsimport-status-message')
            .removeClass('pending')
            .addClass('progress')
            .html('<p><?php _e("Starting import... Please wait.", "mlsimport"); ?></p>');
        
        // Send AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mlsimport_run_test_import',
                nonce: mlsimportOnboarding.nonce
            },
            success: function(response) {
                if (response.success) {
                    testStarted = true;
                    startStatusCheck();
                } else {
                    $('.mlsimport-status-message')
                        .removeClass('pending progress')
                        .addClass('error')
                        .html('<p>' + response.data.message + '</p>');
                    
                    $('#mlsimport-run-test').prop('disabled', false);
                    $('#mlsimport-test-spinner').hide();
                }
            },
            error: function() {
                $('.mlsimport-status-message')
                    .removeClass('pending progress')
                    .addClass('error')
                    .html('<p><?php _e("Connection failed. Please try again.", "mlsimport"); ?></p>');
                
                $('#mlsimport-run-test').prop('disabled', false);
                $('#mlsimport-test-spinner').hide();
            }
        });
    });
    
    // Check test status periodically
    function startStatusCheck() {
    function checkStatus() {
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',  
            data: {
                action: 'mlsimport_logger_per_item',
                post_id: importId,
                security: mlsimportOnboarding.nonce
            },
            success: function(response) {
                console.log("Status response:", response);
                
                if (response.is_done === 'done' || response.status === 'completed') {
                        
                        clearInterval(testIntervalId);
                        testIntervalId = null;
               
                        // Hide spinner and get imported count
                        jQuery('#mlsimport-test-spinner,#mlsimport-run-test').hide();
                        
                        
                        // Update next steps
                        jQuery('.mlsimport-test-recommendations').html(
                            '<h3><Next Steps</h3>' +
                            '<p>Congratulations! You\'ve successfully imported properties from your MLS ! </p>'
                            
                        );

                        
                }
            },
            error: function() {
                // Error checking status
            }
        });
    }
    
    // Check immediately, then every 5 seconds
    checkStatus();
    testIntervalId = setInterval(checkStatus, 5000);
}







});


    
</script>

<style>
.mlsimport-test-import-content {
    max-width: 800px;
}

.mlsimport-test-intro {
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

.mlsimport-import-summary {
    background-color: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.mlsimport-import-summary p {
    margin: 5px 0;
}

.mlsimport-status-success {
    color: #46b450;
    font-weight: 600;
}

.mlsimport-status-progress {
    color: #f56e28;
    font-weight: 600;
}

.mlsimport-status-pending {
    color: #666;
    font-weight: 600;
}

.mlsimport-test-actions {
    margin-top: 20px;
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    justify-content: flex-start;
    align-items: center;
}

.mlsimport-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    margin-left: 10px;
    vertical-align: middle;
    border: 2px solid rgba(0, 0, 0, 0.1);
    border-radius: 50%;
    border-top-color: #07d;
    animation: mlsimport-spin 1s linear infinite;
    order:2;
}

@keyframes mlsimport-spin {
    to {
        transform: rotate(360deg);
    }
}

/* Status message styling */
.mlsimport-status-message {
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.mlsimport-status-message.success {
    background-color: #ecf7ed;
    border-left: 4px solid #46b450;
}

.mlsimport-status-message.error {
    background-color: #fbeaea;
    border-left: 4px solid #dc3232;
}

.mlsimport-status-message.warning {
    background-color: #fff8e5;
    border-left: 4px solid #ffb900;
}

.mlsimport-status-message.progress {
    background-color: #f0f8ff;
    border-left: 4px solid #00a0d2;
}

.mlsimport-status-message.pending {
    background-color: #f9f9f9;
    border-left: 4px solid #ccc;
}

.mlsimport-status-message p {
    margin: 0 0 10px 0;
}

.mlsimport-status-message p:last-child {
    margin-bottom: 0;
}

/* Progress bar */
/* Log container */
.mlsimport-log-container {
    max-height: 300px;
    overflow-y: auto;
    background-color: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 3px;
    padding: 10px;
    margin-bottom: 20px;
}

.mlsimport-log-content {
    font-family: monospace;
    font-size: 12px;
    line-height: 1.5;
    margin: 0;
    white-space: pre-wrap;
}

.mlsimport-log-empty {
    color: #666;
    font-style: italic;
}

.mlsimport-view-properties {
    margin-top: 20px;
}

/* Error list */
.mlsimport-error-list {
    background-color: #fbeaea;
    padding: 15px;
    border-radius: 4px;
    margin-top: 20px;
}

.mlsimport-error-list h4 {
    margin-top: 0;
    margin-bottom: 10px;
    color: #dc3232;
}

.mlsimport-error-list ul {
    margin-top: 10px;
    margin-left: 20px;
}

.mlsimport-error-list li {
    margin-bottom: 5px;
}

/* Error message */
.mlsimport-error-message {
    background-color: #fbeaea;
    border-left: 4px solid #dc3232;
    padding: 15px;
    margin-bottom: 20px;
}

.mlsimport-error-message p {
    margin: 0;
}

/* Next steps */
.mlsimport-test-recommendations {
    background-color: #f9f9f9;
    border-left: 4px solid #4f46e5;
    padding: 15px;
    margin-top: 20px;
}

.mlsimport-test-recommendations h3 {
    margin-top: 0;
    margin-bottom: 15px;
    border-bottom: none;
    padding-bottom: 0;
}

.mlsimport-test-recommendations ul {
    margin-left: 20px;
}

.mlsimport-test-recommendations li {
    margin-bottom: 8px;
}
</style>