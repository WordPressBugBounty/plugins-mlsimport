<?php
/**
 * MLSImport Onboarding Wizard
 *
 * This file contains all the functionality for the onboarding wizard
 * that guides users through the initial setup of the MLSImport plugin.
 * Manual account checks discard the prior login verdict before testing the
 * submitted credentials, so a failed retry cannot reuse a subscription notice.
 * The account identifier is a username or email; both use the established
 * mlsimport_username option and token API parameter for compatibility.
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
    
    // Register the AJAX handlers used by the onboarding wizard.
    add_action('wp_ajax_mlsimport_run_test_import', 'mlsimport_ajax_run_test_import');
    add_action('wp_ajax_mlsimport_save_step_data', 'mlsimport_ajax_save_step_data');
    
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
    // Fires when WP's plugins screen just activated (activate=true) a plugin
    // (plugin=...) whose path contains 'mlsimport'.
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
    /*
     * Bail unless we are on the wizard page.
     *
     * This deliberately tests the page slug rather than $hook. WordPress does not
     * build a submenu's hook from its parent SLUG — it uses the sanitized parent
     * MENU TITLE. The wizard's parent is registered with the title "MLS Import
     * Settings", so the real hook is 'mls-import-settings_page_mlsimport-onboarding',
     * which matched neither of the two hook strings previously hardcoded here.
     * The result was that this function returned immediately on the wizard page:
     * mlsimport-onboarding.js and the MLS autocomplete data were never enqueued,
     * so the "search your MLS" field had no autocomplete at all.
     *
     * The page slug is what the wizard itself is registered and routed by, and it
     * does not change when a menu title is edited or a parent is moved.
     */
    if ( ! isset( $_GET['page'] ) || 'mlsimport-onboarding' !== $_GET['page'] ) {
        return;
    }
    // Fetch the list of MLS providers (used to feed the account-step autocomplete).
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
                'connecting' => __('Connecting...', 'mlsimport'),
                'testing' => __('Testing...', 'mlsimport'),
                'importing' => __('Importing...', 'mlsimport'),
                'success' => __('Success!', 'mlsimport'),
                'error' => __('Error', 'mlsimport'),
            )
        )
    );

    /*
     * Only the account step needs the MLS-provider autocomplete data.
     *
     * The step is resolved with mlsimport_get_current_step() — the SAME call the
     * wizard itself uses to decide which step to render — rather than by reading
     * $_GET['step'] directly. Those are not equivalent: the step falls back to the
     * saved 'mlsimport_onboarding_current_step' option when the URL has no step
     * parameter, which is what happens on the two entry points that matter most —
     * the post-activation redirect and the "Setup Wizard" submenu link, both of
     * which point at plain admin.php?page=mlsimport-onboarding. On those the
     * account step renders while $_GET['step'] is unset, so a $_GET-based test
     * skips the script and the MLS field silently has no autocomplete.
     *
     * The hook slug is likewise not re-tested here: this function already returned
     * early above unless $hook is one of the wizard's two slugs. Re-testing only
     * 'admin_page_mlsimport-onboarding' dropped the script whenever WordPress
     * resolved the page under the submenu hook instead.
     */
    if ( mlsimport_get_current_step() === 'account' && ! empty( $mls_import_list ) ) {

        // Build a ready-handler that primes the MLS-selection autocomplete widget.
        $inline_script = 'jQuery(document).ready(function($){ var autofill=' . wp_kses_post($mls_import_list) . '; mlsimport_autocomplte_mls_selection(autofill); });';
        wp_add_inline_script('mlsimport-onboarding-script', $inline_script);
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
    // Walk each posted field; array values are sanitized element-by-element.
    // Credential keys (password/secret/token) are kept verbatim — the
    // sanitizer strips %[hex][hex] sequences and would corrupt them (#204).
    $sanitized_data = array();
    foreach ($data as $key => $value) {
        if (mlsimport_is_credential_key($key)) {
            $sanitized_data[$key] = trim((string) $value);
        } elseif (is_array($value)) {
            $sanitized_data[$key] = array_map('sanitize_text_field', $value);
        } else {
            $sanitized_data[$key] = sanitize_text_field($value);
        }
    }
    
    // Update user data
    // Store this step's sanitized data under its step key in the aggregate option.
    $user_data[$step] = $sanitized_data;

    // Record onboarding-step completion (lifecycle telemetry).
    mlsimport_telemetry_mark_onboarding_step( $step );

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
    // Each wizard step persists its own fields, then redirects to the next step.
    switch ($current_step) {
        case 'welcome':
            // Nothing to save, just redirect to next step
            mlsimport_redirect_to_next_step($current_step);
            break;
            
        case 'account':
            /*
             * The account partial uses Settings API names such as
             * mlsimport_admin_options[mlsimport_password]. Read that real form
             * contract as one array; the former flat-key reads could never see
             * a submitted value and made every native form POST a silent no-op.
             */
            $submitted_options = isset($_POST['mlsimport_admin_options']) && is_array($_POST['mlsimport_admin_options'])
                ? wp_unslash($_POST['mlsimport_admin_options'])
                : array();

            // Usernames and ids are plain text. Credential values are trimmed
            // only because text sanitization corrupts valid %xx sequences (#204).
            $username = isset($submitted_options['mlsimport_username'])
                ? sanitize_text_field($submitted_options['mlsimport_username'])
                : '';
            $password = isset($submitted_options['mlsimport_password'])
                ? trim((string) $submitted_options['mlsimport_password'])
                : '';
            $mls_id = isset($submitted_options['mlsimport_mls_name'])
                ? sanitize_text_field($submitted_options['mlsimport_mls_name'])
                : '';
            $token = isset($submitted_options['mlsimport_mls_token'])
                ? trim((string) $submitted_options['mlsimport_mls_token'])
                : '';

            // Build one message from administrator-facing labels so every
            // missing value is actionable on the same re-rendered form.
            $required_fields = array(
                __('MLSImport.com Username or email', 'mlsimport') => $username,
                __('MLSImport.com Password', 'mlsimport') => $password,
                __('Your MLS', 'mlsimport')                => $mls_id,
                __('Your API Server token', 'mlsimport')   => $token,
            );
            $missing_fields = array();
            foreach ($required_fields as $label => $value) {
                if ('' === $value) {
                    $missing_fields[] = $label;
                }
            }

            if (!empty($missing_fields)) {
                add_settings_error(
                    'mlsimport_onboarding',
                    'mlsimport_onboarding_required_fields',
                    sprintf(
                        /* translators: %s: comma-separated required onboarding field labels. */
                        __('Please complete the following required field(s): %s.', 'mlsimport'),
                        implode(', ', $missing_fields)
                    ),
                    'error'
                );
                break;
            }

            $account_data = array(
                'username'  => $username,
                'password'  => $password,
                'mls_id'    => $mls_id,
                'mls_token' => $token,
            );

            mlsimport_save_step_data($current_step, $account_data);

            // Mirror accepted values into the live settings used by the
            // account and MLS connection clients.
            $options = get_option('mlsimport_admin_options', array());
            $options['mlsimport_username'] = $username;
            $options['mlsimport_password'] = $password;
            $options['mlsimport_mls_name'] = $mls_id;
            $options['mlsimport_mls_token'] = $token;
            update_option('mlsimport_admin_options', $options);

            mlsimport_redirect_to_next_step($current_step);
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
        // Connection binding (#277): stamp the new task's MLS at creation —
        // onboarding always runs against the connection being set up, which
        // the helper resolves (single registered connection or the current
        // selection).
        mlsimport_bind_task_connection((int) $post_id, 0);
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
    update_post_meta($post_id, 'mlsimport_item_standardstatusprotect', array('Active', 'ActiveUnderContract', 'ComingSoon', 'Pending'));
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

add_action('wp_ajax_mlsimport_save_account', 'mlsimport_save_account_callback');
/**
 * AJAX handler: save the MLSImport username or email/password and test login.
 *
 * Verifies the onboarding nonce, stores credentials in mlsimport_admin_options,
 * fetches a fresh API token, and returns connected/not-connected HTML + flag.
 * Clears the prior account verdict before that request: HTTP 400 or a transport
 * failure must not inherit a no-subscription message from an earlier login.
 * A successful login also re-reads the account's entitlements (connection
 * cap) from the SaaS — see mlsimport_refresh_entitlements().
 *
 * Defined in the onboarding module so the callback and its credential-handling
 * dependencies are loadable by the pure-PHP credentials regression harness.
 *
 * @return void
 */
function mlsimport_save_account_callback() {
	// Verify the shared onboarding AJAX nonce.
	check_ajax_referer('mlsimport_onboarding_nonce', 'security');
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}

	/*
	 * Validate the live Save Account payload before loading prior account state.
	 * Falling through on blanks let a warm token from the saved account answer
	 * "connected" for an empty form (#306).
	 */
	$username = isset($_POST['mlsimport_username'])
		? sanitize_text_field(wp_unslash($_POST['mlsimport_username']))
		: '';
	$password = isset($_POST['mlsimport_password'])
		? trim(wp_unslash($_POST['mlsimport_password']))
		: '';
	$missing_fields = array();
	if ('' === $username) {
		$missing_fields[] = __('MLSImport.com Username or email', 'mlsimport');
	}
	if ('' === $password) {
		$missing_fields[] = __('MLSImport.com Password', 'mlsimport');
	}

	if (!empty($missing_fields)) {
		$message = 1 === count($missing_fields)
			? sprintf(
				/* translators: %s: one missing MLSImport account field label. */
				__('%s is required.', 'mlsimport'),
				$missing_fields[0]
			)
			: sprintf(
				/* translators: 1: username label, 2: password label. */
				__('%1$s and %2$s are required.', 'mlsimport'),
				$missing_fields[0],
				$missing_fields[1]
			);

		wp_send_json_error(array('message' => $message));
	}

	// Load current plugin options.
	$options = get_option('mlsimport_admin_options', []);
	// Both values are present. Preserve the password verbatim after unslashing
	// and trim because text sanitization strips valid %xx sequences (#204).
	$options['mlsimport_username'] = $username;
	$options['mlsimport_password'] = $password;
	update_option('mlsimport_admin_options', $options);

	// Force the check below to exercise the credentials just saved rather than
	// a token minted from the previous password (#205).
	delete_transient('mlsimport_saas_token');
	delete_option('mlsimport_token_expiry');
	// This is a new login attempt. Only its response may confirm no subscription;
	// short passwords (HTTP 400) and timeouts do not overwrite an old verdict.
	delete_option( MLSIMPORT_ACCOUNT_STATUS_OPTION );

	global $mlsimport;

	// Refresh token
	$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();

	// Empty token means the login failed. The box names the reason the
	// server gave (no subscription vs wrong password) — see
	// includes/mlsimport-account-status.php.
	if (trim($token) === '') {
		$html            = mlsimport_account_not_connected_html();
		$account_status  = mlsimport_account_status();
		$is_unsubscribed = 'no_subscription' === $account_status;

		// Return one public account-state contract for both consumers. The
		// onboarding and Connections screens both render the shared escaped
		// notice HTML. Plain message and link fields remain available to callers.
		// Link data is present only for a confirmed no-subscription verdict, so
		// invalid credentials never receive a misleading purchase action.
		wp_send_json_success([
			'message'         => mlsimport_account_not_connected_message(),
			'html'            => $html,
			'connected'       => false,
			'account_status'  => $account_status,
			'subscribe_url'   => $is_unsubscribed ? MLSIMPORT_ACCOUNT_SUBSCRIBE_URL : '',
			'subscribe_label' => $is_unsubscribed ? esc_html__( 'View plans', 'mlsimport' ) : '',
		]);
	} else {
		// Signed in: re-read the account's entitlements (connection cap +
		// registered MLS blocks) so a plan change shows up on reconnect.
		mlsimport_refresh_entitlements();

		ob_start();
		?>
		<div class="mlsimport_warning mlsimport_validated">
			<?php esc_html_e('You are connected to your MlsImport account!', 'mlsimport'); ?>
		</div>
		<?php
		$html = ob_get_clean();

		wp_send_json_success([
			'message' => __('Connected successfully!', 'mlsimport'),
			'html'    => $html,
			'connected' => true
		]);
	}
}

/**
 * Handle AJAX run test import
 *
 * Verifies the 'mlsimport_onboarding_nonce' nonce; performs no capability
 * check. Caps the configured import item at 5 listings, builds the import
 * request set, and enqueues the background async action that performs the
 * actual import.
 *
 * @since 6.1.0
 */
function mlsimport_ajax_run_test_import() {
    // Prove request intent before resolving and authorizing the saved Import Task.
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mlsimport_onboarding_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'mlsimport')));
    }
    
    // Get import ID
    // The import item id was stashed during the import-config step.
    $user_data = get_option('mlsimport_onboarding_user_data', array());
    $import_id = isset($user_data['import_id']) ? $user_data['import_id'] : 0;

    // Without an import item there is nothing to run.
    if (empty($import_id)) {
        wp_send_json_error(array('message' => __('No import configuration found', 'mlsimport')));
    }
    
    // The saved onboarding id must still be a task this user may manage.
    if ( 'mlsimport_item' !== get_post_type( $import_id ) || ! current_user_can( 'edit_post', $import_id ) ) {
        wp_send_json_error( array( 'message' => __( 'You are not allowed to manage this import task.', 'mlsimport' ) ), 403 );
    }

    update_post_meta( $import_id, 'mlsimport_item_how_many', 5 );
    global $mlsimport;

    try {
        // Setup has no count displayed by the page, so this small scheduling
        // adapter performs one count and passes it into the shared manual run.
        $mlsrequest = $mlsimport->admin->mlsimport_make_listing_requests( $import_id );
        if ( ! isset( $mlsrequest['results'] ) || 0 === intval( $mlsrequest['results'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No listings found with current configuration', 'mlsimport' ) ) );
        }
        $found_items = min( 5, max( 0, intval( $mlsrequest['results'] ) ) );
        $start       = $mlsimport->admin->mlsimport_import_task_execution()->start(
            array(
                'task_id'    => (int) $import_id,
                'source'     => 'manual',
                'found'      => $found_items,
                'limit'      => 5,
                'is_onboard' => 1,
            )
        );
        if ( true !== ( $start['accepted'] ?? false ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Another import is already running. Please wait for it to finish.', 'mlsimport' ) )
            );
        }

        mlsimport_log_onboarding_event( 'Starting test import of up to 5 properties', 'info' );
        // Shared worker scheduling: clears dead/superseded queue entries first
        // so a previously crashed worker can never block this start.
        $mlsimport->admin->mlsimport_enqueue_import_worker( (string) $start['run_id'] );

        wp_send_json_success(
            array(
                'message'   => __( 'Import process started', 'mlsimport' ),
                'import_id' => (int) $import_id,
                'run_id'    => (string) $start['run_id'],
            )
        );
    } catch (Exception $e) {
        mlsimport_log_onboarding_event('Test import failed: ' . $e->getMessage(), 'error');
        wp_send_json_error(array('message' => __('Import failed: ', 'mlsimport') . $e->getMessage()));
    }
}

/**
 * Handle AJAX save step data
 *
 * Verifies the 'mlsimport_onboarding_nonce' nonce, then requires the same
 * manage_options capability as the onboarding settings screen before reading
 * or persisting any submitted values. Delegates accepted requests to
 * mlsimport_save_step_data(), which sanitizes and persists the posted
 * per-step form data.
 *
 * @since 6.1.0
 */
function mlsimport_ajax_save_step_data() {
    // First prove that the request originated from the onboarding screen.
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mlsimport_onboarding_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'mlsimport')));
    }

    /*
     * A nonce prevents cross-site request forgery but does not grant permission.
     * Stop non-administrators before the submitted step or data is inspected and
     * before the persistence helper reaches the shared option write boundary.
     */
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error(
            array( 'message' => __( 'You are not allowed to change onboarding settings.', 'mlsimport' ) ),
            403
        );
    }
    
    // Get step and data
    // Step id is sanitized; the raw data array is sanitized inside save_step_data().
    $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : '';
    $data = isset($_POST['data']) ? $_POST['data'] : array();

    // A step id is required to know where to store the data.
    if (empty($step)) {
        wp_send_json_error(array('message' => __('No step specified', 'mlsimport')));
    }
    
    // Save step data
    $result = mlsimport_save_step_data($step, $data);

    // update_option returns false when the write fails (or value is unchanged).
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

// Initialize onboarding
add_action('init', 'mlsimport_init_onboarding');

// Register activation hook to redirect to onboarding
function mlsimport_activation_redirect() {
    // Set a flag so the next admin request redirects to the onboarding wizard
    update_option('mlsimport_do_onboarding_redirect', true);
}
register_activation_hook(MLSIMPORT_PLUGIN_PATH . 'mlsimport.php', 'mlsimport_activation_redirect');
