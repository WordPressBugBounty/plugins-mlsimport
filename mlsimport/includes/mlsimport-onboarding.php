<?php
/**
 * MLSImport Onboarding Wizard
 *
 * This file contains all the functionality for the onboarding wizard
 * that guides users through the initial setup of the MLSImport plugin.
 *includes\mlsimport-onboarding.php
 * @link       https://mlsimport.com/
 * @since      6.1.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Initialize the onboarding functionality
 *
 * @since 6.1.0
 */
function mlsimport_init_onboarding() {
    // Only initialize for admin pages
    if (!is_admin()) {
        return;
    }

    // Check if we need to start or continue onboarding
    mlsimport_check_onboarding_status();


    // Register the onboarding page
    add_action('admin_menu', 'mlsimport_register_onboarding_page');
    
    // Add menu item to start/resume onboarding
    add_action('admin_menu', 'mlsimport_add_onboarding_menu_item');
    
    // Add assets for onboarding
    add_action('admin_enqueue_scripts', 'mlsimport_enqueue_onboarding_assets');
    
    // Register AJAX handlers
    add_action('wp_ajax_mlsimport_test_account_connection', 'mlsimport_ajax_test_account_connection');
    add_action('wp_ajax_mlsimport_test_mls_connection', 'mlsimport_ajax_test_mls_connection');
    add_action('wp_ajax_mlsimport_run_test_import', 'mlsimport_ajax_run_test_import');
    add_action('wp_ajax_mlsimport_save_step_data', 'mlsimport_ajax_save_step_data');
    
    // Add admin notice for incomplete onboarding
    add_action('admin_notices', 'mlsimport_onboarding_admin_notice');
    
    // Intercept form submissions
    add_action('admin_init', 'mlsimport_handle_step_submission');
}

/**
 * Check if onboarding is complete or in progress
 *
 * @since 6.1.0
 */
function mlsimport_check_onboarding_status() {
    // Check if onboarding is complete
    $onboarding_completed = get_option('mlsimport_onboarding_completed', false);

    // If onboarding is complete, we don't need to do anything
    if ($onboarding_completed) {
        return;
    }

    // Redirect to onboarding if activation flag is set
    if (get_option('mlsimport_do_onboarding_redirect', false)) {
        delete_option('mlsimport_do_onboarding_redirect');
        wp_safe_redirect(admin_url('admin.php?page=mlsimport-onboarding'));
        exit;
    }
    
    // Check if we're on the plugin activation page
    if (isset($_GET['activate']) && $_GET['activate'] == 'true' && isset($_GET['plugin']) && strpos($_GET['plugin'], 'mlsimport') !== false) {
        // Redirect to onboarding welcome page
        wp_redirect(admin_url('admin.php?page=mlsimport-onboarding'));
        exit;
    }
}

/**
 * Register the onboarding admin page
 *
 * @since 6.1.0
 */
function mlsimport_register_onboarding_page() {
    add_submenu_page(
        '', // No parent - won't appear in menu
        __('MLS Import Setup Wizard', 'mlsimport'),
        __('Setup Wizard', 'mlsimport'),
        'manage_options',
        'mlsimport-onboarding',
        'mlsimport_render_onboarding_wizard'
    );
}

/**
 * Add onboarding menu item to the MLS Import menu
 *
 * @since 6.1.0
 */
function mlsimport_add_onboarding_menu_item() {
    // Only show if onboarding hasn't been completed

        add_submenu_page(
            'mlsimport_plugin_options',
            __('Setup Wizard', 'mlsimport'),
            __('Setup Wizard', 'mlsimport'),
            'manage_options',
            'mlsimport-onboarding',
            'mlsimport_render_onboarding_wizard'
        );
   
}

/**
 * Enqueue scripts and styles for the onboarding wizard
 *
 * @since 6.1.0
 * @param string $hook The current admin page
 */
function mlsimport_enqueue_onboarding_assets($hook) {
    if ($hook != 'admin_page_mlsimport-onboarding' && $hook != 'mlsimport_plugin_options_page_mlsimport-onboarding') {
        return;
    }
    $mls_import_list = mlsimport_saas_request_list();
    // Enqueue styles
    wp_enqueue_style(
        'mlsimport-onboarding-style',
        MLSIMPORT_PLUGIN_URL . 'admin/css/mlsimport-onboarding.css',
        array(),
        MLSIMPORT_VERSION
    );
    
    // Enqueue script
    wp_enqueue_script(
        'mlsimport-onboarding-script',
        MLSIMPORT_PLUGIN_URL . 'admin/js/mlsimport-onboarding.js',
        array('jquery','mlsimport-admin','jquery-ui-autocomplete'),
        MLSIMPORT_VERSION,
        true
    );
    
    // Localize script with data
    wp_localize_script(
        'mlsimport-onboarding-script',
        'mlsimportOnboarding',
        array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mlsimport_onboarding_nonce'),
            'current_step' => mlsimport_get_current_step(),
            'steps' => mlsimport_get_steps(),
            'strings' => array(
                'saving' => __('Saving...', 'mlsimport'),
                'next' => __('Next', 'mlsimport'),
                'back' => __('Back', 'mlsimport'),
                'skip' => __('Skip', 'mlsimport'),
                'connecting' => __('Connecting...', 'mlsimport'),
                'testing' => __('Testing...', 'mlsimport'),
                'importing' => __('Importing...', 'mlsimport'),
                'success' => __('Success!', 'mlsimport'),
                'error' => __('Error', 'mlsimport'),
            )
        )
    );

    if ( $hook === 'admin_page_mlsimport-onboarding' &&
            isset($_GET['page']) && $_GET['page'] === 'mlsimport-onboarding' &&
            isset($_GET['step']) && $_GET['step'] === 'account') {
           
        if (!empty($mls_import_list)) {

            $inline_script = 'jQuery(document).ready(function($){ var autofill=' . wp_kses_post($mls_import_list) . '; mlsimport_autocomplte_mls_selection(autofill); });';
            wp_add_inline_script('mlsimport-onboarding-script', $inline_script);
        }
    }
  
    
    
}

/**
 * Display the onboarding wizard
 *
 * @since 6.1.0
 */
function mlsimport_render_onboarding_wizard() {
    // Check current step
    $current_step = mlsimport_get_current_step();
    
    // Get all steps
    $steps = mlsimport_get_steps();
    
    // Load the wizard template
    include MLSIMPORT_PLUGIN_PATH . 'admin/partials/mlsimport-onboarding-wizard.php';
}

/**
 * Get the current onboarding step
 *
 * @since 6.1.0
 * @return string The current step ID
 */
function mlsimport_get_current_step() {
    // Check if step is set in URL
    if (isset($_GET['step']) && !empty($_GET['step'])) {
        $step = sanitize_text_field($_GET['step']);
        
        // Validate step
        $steps = mlsimport_get_steps();
        if (array_key_exists($step, $steps)) {
            // Save current step
            update_option('mlsimport_onboarding_current_step', $step);
            return $step;
        }
    }
    
    // Check if step is saved in options
    $saved_step = get_option('mlsimport_onboarding_current_step', '');
    if (!empty($saved_step)) {
        return $saved_step;
    }
    
    // Default to first step
    $steps = mlsimport_get_steps();
    $first_step = array_key_first($steps);
    update_option('mlsimport_onboarding_current_step', $first_step);
    
    return $first_step;
}

/**
 * Get all onboarding steps
 *
 * @since 6.1.0
 * @return array The onboarding steps
 */
function mlsimport_get_steps() {
    return array(
        'welcome' => array(
            'title' => __('Welcome', 'mlsimport'),
            'description' =>'',
            'template' => 'step-welcome.php',
        ),
        'account' => array(
            'title' => __('Account & MLS Connection', 'mlsimport'),
            'description' => __('Connect to your MLS Import account and MLS provider', 'mlsimport'),
            'template' => 'step-account.php',
        ),
        'field-mapping' => array(
            'title' => __('Field Mapping', 'mlsimport'),
            'description' => __('Configure how MLS fields map to your website', 'mlsimport'),
            'template' => 'step-field-mapping.php',
        ),
        'import-config' => array(
            'title' => __('Import Configuration', 'mlsimport'),
            'description' => __('Set up your first import configuration', 'mlsimport'),
            'template' => 'step-import-config.php',
        ),
        'test-import' => array(
            'title' => __('Test Import', 'mlsimport'),
            'description' => __('Run a test import to verify your setup', 'mlsimport'),
            'template' => 'step-test-import.php',
        ),
        'success' => array(
            'title' => __('Success', 'mlsimport'),
            'description' => __('Your MLS Import is now configured', 'mlsimport'),
            'template' => 'step-success.php',
        ),
    );
}

/**
 * Save data for the current step
 *
 * @since 6.1.0
 * @param string $step The step ID
 * @param array $data The step data to save
 * @return bool Success or failure
 */
function mlsimport_save_step_data($step, $data) {
    $user_data = get_option('mlsimport_onboarding_user_data', array());
    
    // Sanitize data
    $sanitized_data = array();
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $sanitized_data[$key] = array_map('sanitize_text_field', $value);
        } else {
            $sanitized_data[$key] = sanitize_text_field($value);
        }
    }
    
    // Update user data
    $user_data[$step] = $sanitized_data;
    
    // Save user data
    return update_option('mlsimport_onboarding_user_data', $user_data);
}

/**
 * Get saved data for a specific step
 *
 * @since 6.1.0
 * @param string $step The step ID
 * @return array The step data
 */
function mlsimport_get_onboarding_step_data($step) {
    $user_data = get_option('mlsimport_onboarding_user_data', array());
    
    if (isset($user_data[$step])) {
        return $user_data[$step];
    }
    
    return array();
}

/**
 * Redirect to the next step
 *
 * @since 6.1.0
 * @param string $current_step The current step ID
 */
function mlsimport_redirect_to_next_step($current_step) {
    $next_step = mlsimport_get_next_step($current_step);
    
    if ($next_step) {
        wp_redirect(admin_url('admin.php?page=mlsimport-onboarding&step=' . $next_step));
        exit;
    }
}

/**
 * Get the next step ID
 *
 * @since 6.1.0
 * @param string $current_step The current step ID
 * @return string|null The next step ID or null if there is no next step
 */
function mlsimport_get_next_step($current_step) {
    $steps = mlsimport_get_steps();
    $step_keys = array_keys($steps);
    
    $current_index = array_search($current_step, $step_keys);
    
    if ($current_index !== false && isset($step_keys[$current_index + 1])) {
        return $step_keys[$current_index + 1];
    }
    
    return null;
}

/**
 * Get the previous step ID
 *
 * @since 6.1.0
 * @param string $current_step The current step ID
 * @return string|null The previous step ID or null if there is no previous step
 */
function mlsimport_get_previous_step($current_step) {
    $steps = mlsimport_get_steps();
    $step_keys = array_keys($steps);
    
    $current_index = array_search($current_step, $step_keys);
    
    if ($current_index !== false && $current_index > 0) {
        return $step_keys[$current_index - 1];
    }
    
    return null;
}

/**
 * Handle step form submission
 *
 * @since 6.1.0
 */
function mlsimport_handle_step_submission() {
    // Only process on onboarding page
    if (!isset($_GET['page']) || $_GET['page'] !== 'mlsimport-onboarding') {
        return;
    }
    
    // Check if form was submitted
    if (!isset($_POST['mlsimport_onboarding_submit'])) {
        return;
    }
    
    // Verify nonce
    if (!mlsimport_verify_onboarding_nonce()) {
        wp_die(__('Security check failed. Please try again.', 'mlsimport'));
    }
    
    // Get current step
    $current_step = mlsimport_get_current_step();
    
    // Process based on step
    switch ($current_step) {
        case 'welcome':
            // Nothing to save, just redirect to next step
            mlsimport_redirect_to_next_step($current_step);
            break;
            
        case 'account':
            // Save account information only if all required fields are filled
            $username = isset($_POST['mlsimport_username']) ? trim($_POST['mlsimport_username']) : '';
            $password = isset($_POST['mlsimport_password']) ? trim($_POST['mlsimport_password']) : '';
            $mls_id   = isset($_POST['mlsimport_mls_name']) ? trim($_POST['mlsimport_mls_name']) : '';
            $token    = isset($_POST['mlsimport_mls_token']) ? trim($_POST['mlsimport_mls_token']) : '';
        
            // Only save if all fields are non-empty
            if ($username !== '' && $password !== '' && $mls_id !== '' && $token !== '') {
                $account_data = array(
                    'username'   => $username,
                    'password'   => $password,
                    'mls_id'     => $mls_id,
                    'mls_token'  => $token,
                );
        
                mlsimport_save_step_data($current_step, $account_data);
        
                // Save to plugin options
                $options = get_option('mlsimport_admin_options', array());
                $options['mlsimport_username'] = $username;
                $options['mlsimport_password'] = $password;
                $options['mlsimport_mls_name'] = $mls_id;
                $options['mlsimport_mls_token'] = $token;
                update_option('mlsimport_admin_options', $options);
        
                // Redirect to next step
                mlsimport_redirect_to_next_step($current_step);
            }
            break;
            
            
        case 'field-mapping':
            // Save field mapping template selection
            $field_data = array(
                'template' => isset($_POST['mlsimport_field_template']) ? $_POST['mlsimport_field_template'] : 'standard',
                'custom_fields' => isset($_POST['mlsimport_custom_fields']) ? $_POST['mlsimport_custom_fields'] : array(),
            );
            
            mlsimport_save_step_data($current_step, $field_data);
            
            // Redirect to next step
            mlsimport_redirect_to_next_step($current_step);
            break;
            
        case 'import-config':
            // Save import configuration
            $import_data = array(
                'import_title' => isset($_POST['mlsimport_import_title']) ? $_POST['mlsimport_import_title'] : '',
                'property_status' => isset($_POST['mlsimport_property_status']) ? $_POST['mlsimport_property_status'] : 'publish',
                'agent_id' => isset($_POST['mlsimport_agent_id']) ? $_POST['mlsimport_agent_id'] : '',
                'property_user' => isset($_POST['mlsimport_property_user']) ? $_POST['mlsimport_property_user'] : '',
                'min_price' => isset($_POST['mlsimport_min_price']) && $_POST['mlsimport_min_price'] !== ''
                    ? $_POST['mlsimport_min_price']
                    : '0',
                'max_price' => isset($_POST['mlsimport_max_price']) && $_POST['mlsimport_max_price'] !== ''
                    ? $_POST['mlsimport_max_price']
                    : '10000000',
                'property_cities' => isset($_POST['mlsimport_property_cities']) ? $_POST['mlsimport_property_cities'] : array(),
                'property_types' => isset($_POST['mlsimport_property_types']) ? $_POST['mlsimport_property_types'] : array(),
                'auto_update' => isset($_POST['mlsimport_auto_update']) ? 1 : 0,
            );
            
            mlsimport_save_step_data($current_step, $import_data);
            
            // Create import item
            $import_id = mlsimport_create_initial_import_item($import_data);
            
            // Save the import ID
            $user_data = get_option('mlsimport_onboarding_user_data', array());
            $user_data['import_id'] = $import_id;
            update_option('mlsimport_onboarding_user_data', $user_data);
            
            // Redirect to next step
            mlsimport_redirect_to_next_step($current_step);
            break;
            
        case 'test-import':
            // Nothing to save here, just redirect to next step
            mlsimport_redirect_to_next_step($current_step);
            break;
            
        case 'success':
            // Mark onboarding as complete
            mlsimport_mark_onboarding_complete();
            
            // Redirect to main plugin page
            wp_redirect(admin_url('admin.php?page=mlsimport_plugin_options'));
            exit;
            break;
    }
}

/**
 * Verify onboarding nonce
 *
 * @since 6.1.0
 * @return bool True if nonce is valid, false otherwise
 */
function mlsimport_verify_onboarding_nonce() {
    return isset($_POST['mlsimport_onboarding_nonce']) && 
           wp_verify_nonce($_POST['mlsimport_onboarding_nonce'], 'mlsimport_onboarding');
}

/**
 * Mark onboarding as complete
 *
 * @since 6.1.0
 */
function mlsimport_mark_onboarding_complete() {
    update_option('mlsimport_onboarding_completed', true);
    
    // Log completion event
    mlsimport_log_onboarding_event('Onboarding completed successfully', 'info');
}

/**
 * Render a specific onboarding step
 *
 * @since 6.1.0
 * @param string $step The step ID to render
 */
function mlsimport_render_onboarding_step($step) {
    $steps = mlsimport_get_steps();
    
    if (!isset($steps[$step])) {
        return;
    }
    
    $template = $steps[$step]['template'];
    $path = MLSIMPORT_PLUGIN_PATH . 'admin/partials/mlsimport-onboarding-steps/' . $template;
    
    if (file_exists($path)) {
        // Get step data
        $step_data = mlsimport_get_onboarding_step_data($step);
        
        // Include template
        include $path;
    }
}

/**
 * Create the initial import item
 *
 * @since 6.1.0
 * @param array $import_data The import configuration data
 * @return int The post ID of the created import item
 */
function mlsimport_create_initial_import_item($import_data) {
    // Create post
    $post_data = array(
        'post_title' => !empty($import_data['import_title']) ? $import_data['import_title'] : __('Initial Import', 'mlsimport'),
        'post_status' => 'publish',
        'post_type' => 'mlsimport_item',
    );
    
    $post_id = wp_insert_post($post_data);
    
    if (!is_wp_error($post_id)) {
        // Set up import item defaults
        mlsimport_setup_import_item_defaults($post_id, $import_data);
    }
    
    return $post_id;
}

/**
 * Set up default meta values for an import item
 *
 * @since 6.1.0
 * @param int $post_id The post ID of the import item
 * @param array $import_data The import configuration data
 * @return bool Success or failure
 */
function mlsimport_setup_import_item_defaults($post_id, $import_data) {
    // Set basic meta
    update_post_meta($post_id, 'mlsimport_item_property_status', $import_data['property_status']);
    update_post_meta($post_id, 'mlsimport_item_agent', $import_data['agent_id']);
    update_post_meta($post_id, 'mlsimport_item_property_user', $import_data['property_user']);
    update_post_meta($post_id, 'mlsimport_item_min_price', $import_data['min_price']);
    update_post_meta($post_id, 'mlsimport_item_max_price', $import_data['max_price']);
    update_post_meta($post_id, 'mlsimport_item_stat_cron', $import_data['auto_update']);

    // Default statuses and visibility options
    update_post_meta($post_id, 'mlsimport_item_standardstatus', array('Active'));
    update_post_meta(
        $post_id,
        'mlsimport_item_standardstatusdelete',
        array(
            'Active',
            'ActiveUnderContract',
            'Canceled',
            'Closed',
            'ComingSoon',
            'Delete',
            'Expired',
            'Hold',
            'Incomplete',
            'Pending',
            'Withdrawn'
        )
    );
    update_post_meta($post_id, 'mlsimport_item_internetentirelistingdisplayyn', 'yes');
    update_post_meta($post_id, 'mlsimport_item_internetaddressdisplayyn', 'yes');
    
    // Set title format
    update_post_meta($post_id, 'mlsimport_item_title_format', '{Address}, {City}, {CountyOrParish}, {PropertyType}');
    
    // Set locations
    if (!empty($import_data['property_cities'])) {
        update_post_meta($post_id, 'mlsimport_item_city', $import_data['property_cities']);
    }
    
    // Set property types
    if (!empty($import_data['property_types'])) {
        update_post_meta($post_id, 'mlsimport_item_propertytype', $import_data['property_types']);
    }
    
    // Set creation date for reference
    update_post_meta($post_id, 'mlsimport_item_created_date', current_time('mysql'));
    
    // Log action
    mlsimport_log_onboarding_event(
        sprintf('Created initial import item (ID: %d)', $post_id),
        'info'
    );
    
    return true;
}

/**
 * Log onboarding event
 *
 * @since 6.1.0
 * @param string $message The log message
 * @param string $type The log type (info, warning, error)
 */
function mlsimport_log_onboarding_event($message, $type = 'info') {
    // Format log message
    $formatted_message = '[' . current_time('mysql') . '] [ONBOARDING] [' . strtoupper($type) . '] ' . $message;
    
    // Write to plugin logs
    mlsimport_saas_single_write_import_custom_logs($formatted_message, 'onboarding');
}

/**
 * Display admin notice for incomplete onboarding
 *
 * @since 6.1.0
 */
function mlsimport_onboarding_admin_notice() {
    // Allow disabling the notice via constant
    if (defined('MLSIMPORT_HIDE_SETUP_NOTICE') && MLSIMPORT_HIDE_SETUP_NOTICE) {
        return;
    }
    // Only show on plugin pages
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'mlsimport') === false) {
        return;
    }
    
    // Don't show on onboarding page
    if (isset($_GET['page']) && $_GET['page'] === 'mlsimport-onboarding') {
        return;
    }
    
    // Check if onboarding is complete
    $onboarding_completed = get_option('mlsimport_onboarding_completed', false);
    if ($onboarding_completed) {
        return;
    }
    
    // Get current step
    $current_step = get_option('mlsimport_onboarding_current_step', 'welcome');
    $steps = mlsimport_get_steps();
    
    // Display notice
    ?>
  
    <?php
}

/**
 * Handle AJAX test account connection
 *
 * @since 6.1.0
 */
function mlsimport_ajax_test_account_connection() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mlsimport_onboarding_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'mlsimport')));
    }
    
    // Get credentials
    $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
    $password = isset($_POST['password']) ? sanitize_text_field($_POST['password']) : '';
    
    if (empty($username) || empty($password)) {
        wp_send_json_error(array('message' => __('Username and password are required', 'mlsimport')));
    }
    
    // Save to temporary storage for test
    $options = get_option('mlsimport_admin_options', array());
    $options['mlsimport_username'] = $username;
    $options['mlsimport_password'] = $password;
    update_option('mlsimport_admin_options', $options);
    
    // Delete token to force fresh request
    delete_transient('mlsimport_saas_token');
    
    // Test connection using existing methods
    global $mlsimport;
    $token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
    
    if (empty($token)) {
        wp_send_json_error(array('message' => __('Unable to connect to MLS Import. Please check your credentials.', 'mlsimport')));
    }
    
    // Log success
    mlsimport_log_onboarding_event('Successfully connected to MLS Import account', 'info');
    
    wp_send_json_success(array('message' => __('Successfully connected to MLS Import', 'mlsimport')));
}

/**
 * Handle AJAX test MLS connection
 *
 * @since 6.1.0
 */
function mlsimport_ajax_test_mls_connection() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mlsimport_onboarding_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'mlsimport')));
    }
    
    // Get MLS info
    $mls_id = isset($_POST['mls_id']) ? sanitize_text_field($_POST['mls_id']) : '';
    $mls_token = isset($_POST['mls_token']) ? sanitize_text_field($_POST['mls_token']) : '';
    
    if (empty($mls_id)) {
        wp_send_json_error(array('message' => __('MLS selection is required', 'mlsimport')));
    }
    
    // Save to temporary storage for test
    $options = get_option('mlsimport_admin_options', array());
    $options['mlsimport_mls_name'] = $mls_id;
    $options['mlsimport_mls_token'] = $mls_token;
    update_option('mlsimport_admin_options', $options);
    
    // Test connection using existing methods
    global $mlsimport;
    $connection_result = $mlsimport->admin->mlsimport_saas_check_mls_connection();
    $is_connected = get_option('mlsimport_connection_test', '');
    
    if ($is_connected !== 'yes') {
        wp_send_json_error(array('message' => __('Unable to connect to MLS. Please check your credentials.', 'mlsimport')));
    }
    
    // Log success
    mlsimport_log_onboarding_event('Successfully connected to MLS provider', 'info');
    
    wp_send_json_success(array('message' => __('Successfully connected to MLS', 'mlsimport')));
}

/**
 * Handle AJAX run test import
 *
 * @since 6.1.0
 */
function mlsimport_ajax_run_test_import() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mlsimport_onboarding_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'mlsimport')));
    }
    
    // Get import ID
    $user_data = get_option('mlsimport_onboarding_user_data', array());
    $import_id = isset($user_data['import_id']) ? $user_data['import_id'] : 0;
    
    if (empty($import_id)) {
        wp_send_json_error(array('message' => __('No import configuration found', 'mlsimport')));
    }
    
    // Set a lower limit for test import
    update_post_meta($import_id, 'mlsimport_item_how_many', 5);
    
    // Run a limited import using the admin class methods
    global $mlsimport;
    
    try {
        // Set up import parameters
        $item_id_array = array(
            'item_id' => $import_id,
            'how_many' => 5,
            'max_number' => 5,
            'batch_counter' => 1,
        );
        
        // Make sure we're starting clean
        update_option('mlsimport_force_stop_' . $import_id, 'no', false);
        update_post_meta($import_id, 'mlsimport_attach_to_move_' . $import_id, '');
        
        // Get listings
        $mlsrequest = $mlsimport->admin->mlsimport_make_listing_requests($import_id);
  
        if (!isset($mlsrequest['results']) || $mlsrequest['results'] == 0) {
            wp_send_json_error(array('message' => __('No listings found with current configuration', 'mlsimport')));
        }
        
        $found_items = intval($mlsrequest['results']);
        if ($found_items > 5) {
            $found_items = 5;
        }
        
        $item_id_array['max_number'] = $found_items;
        
        // Generate import requests
        $attachments_to_move = (array)$mlsimport->admin->mlsimport_saas_generate_import_requests_per_item($item_id_array);
        update_post_meta($import_id, 'mlsimport_attach_to_move_' . $import_id, $attachments_to_move);
        
        // Prepare background process arguments
        $attachments_to_send = array(
            'args' => array(
                'attachments_to_move' => $import_id,
                'item_id_array' => $item_id_array,
            ),
        );
        
       // Start background process
update_post_meta($import_id, 'mlsimport_spawn_status', 'started');
mlsimport_log_onboarding_event('Starting test import of 5 properties', 'info');

// Use the async action system instead of direct execution
as_enqueue_async_action('mlsimport_background_process_per_item', $attachments_to_send);
spawn_cron();

// Return success data without checking import count
wp_send_json_success(array(
    'message' => __('Import process started', 'mlsimport'),
    'import_id' => $import_id
));


    } catch (Exception $e) {
        mlsimport_log_onboarding_event('Test import failed: ' . $e->getMessage(), 'error');
        wp_send_json_error(array('message' => __('Import failed: ', 'mlsimport') . $e->getMessage()));
    }
}

/**
 * Handle AJAX save step data
 *
 * @since 6.1.0
 */
function mlsimport_ajax_save_step_data() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mlsimport_onboarding_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'mlsimport')));
    }
    
    // Get step and data
    $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : '';
    $data = isset($_POST['data']) ? $_POST['data'] : array();
    
    if (empty($step)) {
        wp_send_json_error(array('message' => __('No step specified', 'mlsimport')));
    }
    
    // Save step data
    $result = mlsimport_save_step_data($step, $data);
    
    if (!$result) {
        wp_send_json_error(array('message' => __('Failed to save data', 'mlsimport')));
    }
    
    wp_send_json_success(array('message' => __('Data saved successfully', 'mlsimport')));
}

/**
 * Save current onboarding state
 *
 * @since 6.1.0
 * @param string $step_id The current step ID
 * @param array $form_data The form data
 * @return bool Success or failure
 */
function mlsimport_save_onboarding_state($step_id, $form_data) {
    $state = array(
        'current_step' => $step_id,
        'form_data' => $form_data,
        'timestamp' => current_time('timestamp'),
    );
    
    return update_option('mlsimport_onboarding_state', $state);
}

/**
 * Restore onboarding state
 *
 * @since 6.1.0
 * @return array The saved state data
 */
function mlsimport_restore_onboarding_state() {
    return get_option('mlsimport_onboarding_state', array());
}

/**
 * Clear onboarding state
 *
 * @since 6.1.0
 * @return bool Success or failure
 */
function mlsimport_clear_onboarding_state() {
    return delete_option('mlsimport_onboarding_state');
}

/**
 * Maybe restart wizard
 *
 * @since 6.1.0
 */
function mlsimport_maybe_restart_wizard() {
    if (isset($_GET['restart_wizard']) && $_GET['restart_wizard'] == 1) {
        // Clear onboarding state
        mlsimport_clear_onboarding_state();
        
        // Reset current step
        update_option('mlsimport_onboarding_current_step', '');
        
        // Clear user data
        delete_option('mlsimport_onboarding_user_data');
        
        // Mark onboarding as not completed
        update_option('mlsimport_onboarding_completed', false);
        
        // Redirect to first step
        wp_redirect(admin_url('admin.php?page=mlsimport-onboarding'));
        exit;
    }
}
add_action('admin_init', 'mlsimport_maybe_restart_wizard');
/**
 * Get a template configuration based on MLS provider
 *
 * @since 6.1.0
 * @param int $mls_id The MLS provider ID
 * @return array Default settings for the specified MLS
 */
function mlsimport_get_import_item_template($mls_id) {
    // Default template
    $template = array(
        'title_format' => '{Address}, {City}, {CountyOrParish}, {PropertyType}',
        'property_status' => 'publish',
        'auto_update' => 1,
        'standard_status' => array('Active', 'Coming Soon'),
        'property_types' => array('Residential', 'Condo/Townhome/Row Home/Co-Op'),
    );
    
    // Customize based on MLS ID if needed
    switch ($mls_id) {
        // Add MLS-specific customizations here
        case '111': // Example - Rae Edmonton
            $template['standard_status'] = array('Active');
            break;
            
        default:
            // Use defaults
            break;
    }
    
    return $template;
}

/**
 * Display a condensed log summary
 *
 * @since 6.1.0
 * @param int $num_entries Number of entries to show
 * @return string HTML output of log summary
 */
function mlsimport_display_onboarding_log_summary($num_entries = 10) {
    $path = WP_PLUGIN_DIR . '/mlsimport/logs/onboarding_logs.log';
    
    if (!file_exists($path)) {
        return '<div class="mlsimport-log-summary empty">' . __('No logs available', 'mlsimport') . '</div>';
    }
    
    // Get the last N lines
    $lines = file($path);
    $lines = array_slice($lines, -$num_entries);
    
    $output = '<div class="mlsimport-log-summary">';
    $output .= '<h4>' . __('Recent Activity', 'mlsimport') . '</h4>';
    $output .= '<ul class="mlsimport-logs">';
    
    foreach ($lines as $line) {
        // Extract log type for styling
        if (strpos($line, '[INFO]') !== false) {
            $class = 'info';
        } elseif (strpos($line, '[WARNING]') !== false) {
            $class = 'warning';
        } elseif (strpos($line, '[ERROR]') !== false) {
            $class = 'error';
        } else {
            $class = '';
        }
        
        $output .= '<li class="log-item ' . $class . '">' . esc_html($line) . '</li>';
    }
    
    $output .= '</ul>';
    $output .= '</div>';
    
    return $output;
}

/**
 * Register onboarding-specific log types with logging system
 *
 * @since 6.1.0
 */
function mlsimport_register_onboarding_logs() {
    // Create logs directory if it doesn't exist
    $log_dir = WP_PLUGIN_DIR . '/mlsimport/logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    // Create onboarding log file if it doesn't exist
    $log_file = $log_dir . '/onboarding_logs.log';
    if (!file_exists($log_file)) {
        touch($log_file);
    }
}

// Initialize onboarding
add_action('init', 'mlsimport_init_onboarding');

// Register activation hook to redirect to onboarding
function mlsimport_activation_redirect() {
    // Set a flag so the next admin request redirects to the onboarding wizard
    update_option('mlsimport_do_onboarding_redirect', true);
}
register_activation_hook(MLSIMPORT_PLUGIN_PATH . 'mlsimport.php', 'mlsimport_activation_redirect');
