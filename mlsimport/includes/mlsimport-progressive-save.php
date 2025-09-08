<?php
/**
 * MLS Import Progressive Save Handlers
 *
 * Server-side handlers for the progressive save system:
 * - Initial save detection and chunked saving
 * - Individual field option saving
 * - Optimized field order saving
 *
 * @package    MLSImport
 * @subpackage MLSImport/includes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * AJAX handler for saving field order
 * This stores the order directly in the mlsimport_admin_fields_select option
 */
function mlsimport_ajax_save_field_order() {
    // Add detailed logging for debugging

    // Check nonce
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'mlsimport_field_selector_nonce')) {
        //error_log('Invalid security token in field order save');
        wp_send_json_error('Invalid security token');
        return;
    }
    
    // Check for both parameter formats
    $field_order = array();
    
    // Check for standard array format (field_order[])
    if (isset($_POST['field_order']) && is_array($_POST['field_order'])) {
        $field_order = $_POST['field_order'];
        //error_log('Using field_order parameter: ' . count($field_order) . ' items');
    } 
    // Check for older format (fields[])
    else if (isset($_POST['fields']) && is_array($_POST['fields'])) {
        $field_order = $_POST['fields'];
        //error_log('Using fields parameter: ' . count($field_order) . ' items');
    }
    // Otherwise, try to get values directly by key pattern
    else {
        //error_log('No direct array found, checking for field_order[] pattern');
        // Check if we need to manually extract from the POST data (for field_order[])
        foreach ($_POST as $key => $value) {
            if (preg_match('/^field_order(\[.*\])?$/', $key)) {
                if (is_array($value)) {
                    $field_order = $value;
                    //error_log('Found field_order as array with ' . count($field_order) . ' items');
                    break;
                }
            }
        }
        
        // If we still don't have field order data, try to extract it directly from POST
        if (empty($field_order)) {
            //error_log('Trying to manually build array from field_order[]');
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'field_order[') === 0) {
                    $field_order[] = $value;
                }
            }
            //error_log('Manually built array with ' . count($field_order) . ' items');
        }
    }
    
    // Check if we have any field order data
    if (empty($field_order)) {
        //error_log('No field order data found in request');
        wp_send_json_error('No field order provided');
        return;
    }
    
    // Log field count for debugging
    //error_log('Saving ' . count($field_order) . ' fields in order');
    
    // Get current options
    $options = get_option('mlsimport_admin_fields_select', array());
    
    // Make sure $options is an array
    if (!is_array($options)) {
        $options = array();
    }
    
    // Store the field order directly in the options
    $options['field_order'] = array_map('sanitize_text_field', $field_order);

    // Save the updated options
    $result = update_option('mlsimport_admin_fields_select', $options);

    if ($result) {
        global $mlsimport;
        if ( isset( $mlsimport->admin->env_data ) && method_exists( $mlsimport->admin->env_data, 'enviroment_custom_fields' ) ) {
            $mlsimport->admin->env_data->enviroment_custom_fields( $mlsimport->get_plugin_name() );
        }
        //error_log('Field order saved successfully');
        wp_send_json_success('Field order saved successfully');
    } else {
        //error_log('Failed to save field order - update_option returned false');
        wp_send_json_error('Failed to save field order - update_option returned false');
    }
}



/**
 * Add this function to your mlsimport-admin-fields-select.php file
 * Outputs the nonce field needed for field ordering
 */
function mlsimport_add_field_selector_nonce() {
    wp_nonce_field('mlsimport_field_selector_nonce', 'mlsimport_field_selector_nonce');
}






/**
 * Register AJAX handlers for progressive saving
 */
function mlsimport_register_progressive_save_handlers() {
    // Check if initial save is needed
    add_action('wp_ajax_mlsimport_check_initial_save_needed', 'mlsimport_ajax_check_initial_save_needed');
    
    // Save a chunk of fields
    add_action('wp_ajax_mlsimport_save_field_chunk', 'mlsimport_ajax_save_field_chunk');

    // Save an individual field option
    add_action('wp_ajax_mlsimport_save_field_option', 'mlsimport_ajax_save_field_option');

    // Bulk save import selections
    add_action('wp_ajax_mlsimport_save_bulk_import', 'mlsimport_ajax_save_bulk_import');

    // Bulk save admin visibility selections
    add_action('wp_ajax_mlsimport_save_bulk_admin', 'mlsimport_ajax_save_bulk_admin');
}
add_action('init', 'mlsimport_register_progressive_save_handlers');






function mlsimport_ajax_save_field_chunk() {
    // Check nonce
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'mlsimport_field_selector_nonce')) {
        wp_send_json_error('Invalid security token');
        return;
    }
    
    // Check if fields were provided
    if (!isset($_POST['fields']) || !is_array($_POST['fields'])) {
        wp_send_json_error('No field data provided');
        return;
    }
    
    // Get chunk info
    $chunk_index = isset($_POST['chunk_index']) ? intval($_POST['chunk_index']) : 0;
    $total_chunks = isset($_POST['total_chunks']) ? intval($_POST['total_chunks']) : 1;
    $is_last_chunk = ($chunk_index + 1) == $total_chunks;
    
    // Get current options
    $options = get_option('mlsimport_admin_fields_select', array());
    
    // Initialize arrays if they don't exist
    if (!is_array($options)) {
        $options = array();
    }
    
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

    if (!isset($options['field_order'])) {
        $options['field_order'] = array();
        $i = 0;
    }else{
        $i = count($options['field_order'] );
    }
    


    // Process fields in the chunk
    foreach ($_POST['fields'] as $field_key => $field_data) {
     
        
        // Process field data as before
        if (isset($field_data['import'])) {
            $options['mls-fields'][$field_key] = intval($field_data['import']);
        }
        
        if (isset($field_data['admin'])) {
            $options['mls-fields-admin'][$field_key] = intval($field_data['admin']);
        }
        
        if (isset($field_data['label'])) {
            $options['mls-fields-label'][$field_key] = $field_data['label'];
        }
        
        if (isset($field_data['postmeta'])) {
            $options['mls-fields-map-postmeta'][$field_key] = $field_data['postmeta'];
        }
        
        if (isset($field_data['taxonomy'])) {
            $options['mls-fields-map-taxonomy'][$field_key] = $field_data['taxonomy'];
        }

    
        $options['field_order'][$field_key] = $i++;
        
    }
    
 

    
    // Save the options
    $saved = update_option('mlsimport_admin_fields_select', $options);
    if ( $saved ) {
        global $mlsimport;
        if ( isset( $mlsimport->admin->env_data ) && method_exists( $mlsimport->admin->env_data, 'enviroment_custom_fields' ) ) {
            $mlsimport->admin->env_data->enviroment_custom_fields( $mlsimport->get_plugin_name() );
        }
    }
    
    wp_send_json_success(array(
        
      'field_order'=>  $options['field_order'],
        'message' => 'Field chunk ' . ($chunk_index + 1) . ' of ' . $total_chunks . ' saved successfully',
        'chunkIndex' => $chunk_index, 
        'totalChunks' => $total_chunks,
        'isLastChunk' => $is_last_chunk,
   
    ));
}

/**
 * AJAX handler to save import selections in bulk
 */
function mlsimport_ajax_save_bulk_import() {
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'mlsimport_field_selector_nonce')) {
        wp_send_json_error('Invalid security token');
    }

    if (!isset($_POST['fields']) || !is_array($_POST['fields'])) {
        wp_send_json_error('No field data provided');
    }

    $options = get_option('mlsimport_admin_fields_select', array());
    if (!isset($options['mls-fields']) || !is_array($options['mls-fields'])) {
        $options['mls-fields'] = array();
    }

    foreach ($_POST['fields'] as $key => $value) {
        $options['mls-fields'][sanitize_text_field($key)] = intval($value);
    }

    $result = update_option('mlsimport_admin_fields_select', $options);
    if ($result) {
        global $mlsimport;
        if ( isset( $mlsimport->admin->env_data ) && method_exists( $mlsimport->admin->env_data, 'enviroment_custom_fields' ) ) {
            $mlsimport->admin->env_data->enviroment_custom_fields( $mlsimport->get_plugin_name() );
        }
        wp_send_json_success('Bulk import selections saved');
    } else {
        wp_send_json_error('Failed to save selections');
    }
}

/**
 * AJAX handler to save admin visibility selections in bulk
 */
function mlsimport_ajax_save_bulk_admin() {
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'mlsimport_field_selector_nonce')) {
        wp_send_json_error('Invalid security token');
    }

    if (!isset($_POST['fields']) || !is_array($_POST['fields'])) {
        wp_send_json_error('No field data provided');
    }

    $options = get_option('mlsimport_admin_fields_select', array());
    if (!isset($options['mls-fields-admin']) || !is_array($options['mls-fields-admin'])) {
        $options['mls-fields-admin'] = array();
    }

    foreach ($_POST['fields'] as $key => $value) {
        $options['mls-fields-admin'][sanitize_text_field($key)] = intval($value);
    }

    $result = update_option('mlsimport_admin_fields_select', $options);
    if ($result) {
        global $mlsimport;
        if ( isset( $mlsimport->admin->env_data ) && method_exists( $mlsimport->admin->env_data, 'enviroment_custom_fields' ) ) {
            $mlsimport->admin->env_data->enviroment_custom_fields( $mlsimport->get_plugin_name() );
        }
        wp_send_json_success('Bulk admin selections saved');
    } else {
        wp_send_json_error('Failed to save selections');
    }
}























/**
 * AJAX handler to check if initial save is needed
 */
function mlsimport_ajax_check_initial_save_needed() {
    // Check nonce
    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'mlsimport_field_selector_nonce' ) ) {
        wp_send_json_error( 'Invalid security token' );
    }
    
    // Check if option exists and has content
    $options = get_option( 'mlsimport_admin_fields_select' );
    $mlsimport_mls_metadata_mls_data = get_option( 'mlsimport_mls_metadata_mls_data', '' );
    
    // Decode MLS data
    $mls_data = json_decode( $mlsimport_mls_metadata_mls_data, true );
    
    // Check if we need an initial save
    $initial_save_needed = false;
    
    // If MLS data exists but option is empty/incomplete
    // had !empty( $mls_data ) &&
    if (  ( 
        empty( $options ) || 
        empty( $options['mls-fields'] ) 
    ) ) {
        $initial_save_needed = true;
    }
    
    wp_send_json_success( array(
        'initialSaveNeeded' => $initial_save_needed,
      
        'options'=>$options,
        'fieldsInOption' => is_array( $options ) && isset( $options['mls-fields'] ) ? count( $options['mls-fields'] ) : 0,
        'fieldsInMlsData' => is_array( $mls_data ) ? count( $mls_data ) : 0
    ) );
}

/**
 * AJAX handler to save an individual field option
 */
function mlsimport_ajax_save_field_ssswsd2option() {
    // Check nonce
    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'mlsimport_field_selector_nonce' ) ) {
        wp_send_json_error( 'Invalid security token' );
    }
    
    // Check if field key and option type were provided
    if ( ! isset( $_POST['field_key'] ) || ! isset( $_POST['option_type'] ) ) {
        wp_send_json_error( 'Missing field key or option type' );
    }
    
    // Sanitize inputs
    $field_key = sanitize_text_field( $_POST['field_key'] );
    $option_type = sanitize_text_field( $_POST['option_type'] );
    $value = isset( $_POST['value'] ) ? $_POST['value'] : '';
    
    // Validate option type
    $valid_option_types = array( 'import', 'admin', 'label', 'postmeta', 'taxonomy' );
    
    if ( ! in_array( $option_type, $valid_option_types ) ) {
        wp_send_json_error( 'Invalid option type' );
    }
    
    // Get current options
    $options = get_option( 'mlsimport_admin_fields_select', array() );
    // Keep a copy of the original options to detect changes
    $original_options = $options;
    
    // Map option type to options array key
    $option_map = array(
        'import' => 'mls-fields',
        'admin' => 'mls-fields-admin',
        'label' => 'mls-fields-label',
        'postmeta' => 'mls-fields-map-postmeta',
        'taxonomy' => 'mls-fields-map-taxonomy'
    );
    
    $option_key = $option_map[ $option_type ];
    
    // Initialize array if it doesn't exist
    if ( ! isset( $options[ $option_key ] ) ) {
        $options[ $option_key ] = array();
    }
    
    // Sanitize and update value based on option type
    switch ( $option_type ) {
        case 'import':
        case 'admin':
            $options[ $option_key ][ $field_key ] = intval( $value );
            break;
            
        case 'label':
        case 'postmeta':
        case 'taxonomy':
            $options[ $option_key ][ $field_key ] = sanitize_text_field( $value );
            break;
    }
    
    // If nothing changed, treat as success without calling update_option
    if ( $options === $original_options ) {
        wp_send_json_success( array(
            'message'    => 'Field option saved successfully',
            'fieldKey'   => $field_key,
            'optionType' => $option_type,
            'value'      => $value,
        ) );
    }

    // Save updated options
    $result = update_option( 'mlsimport_admin_fields_select', $options );

    if ( false !== $result ) {
        wp_send_json_success( array(
            'message'    => 'Field option saved successfully',
            'fieldKey'   => $field_key,
            'optionType' => $option_type,
            'value'      => $value,
        ) );
    } else {
        wp_send_json_error( 'Failed to save field option' );
    }
}
function mlsimport_ajax_save_field_option() {
    // Check nonce
    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'mlsimport_field_selector_nonce' ) ) {
        //error_log( '[mlsimport] Invalid security token' );
        wp_send_json_error( 'Invalid security token' );
    }

    // Check if field key and option type were provided
    if ( ! isset( $_POST['field_key'] ) || ! isset( $_POST['option_type'] ) ) {
        //error_log( '[mlsimport] Missing field key or option type' );
        wp_send_json_error( 'Missing field key or option type' );
    }

    // Sanitize inputs
    $field_key   = sanitize_text_field( $_POST['field_key'] );
    $option_type = sanitize_text_field( $_POST['option_type'] );
    $value       = isset( $_POST['value'] ) ? $_POST['value'] : '';

    // Error log for debugging
    //error_log( '[mlsimport] Field Key: ' . $field_key );
    //error_log( '[mlsimport] Option Type: ' . $option_type );
    //error_log( '[mlsimport] Raw Value: ' . print_r( $value, true ) );

    // Validate option type
    $valid_option_types = array( 'import', 'admin', 'label', 'postmeta', 'taxonomy' );
    if ( ! in_array( $option_type, $valid_option_types ) ) {
        //error_log( '[mlsimport] Invalid option type: ' . $option_type );
        wp_send_json_error( 'Invalid option type' );
    }

    // Get current options
    $options = get_option( 'mlsimport_admin_fields_select', array() );
    $original_options = $options;

    // Map option type to options array key
    $option_map = array(
        'import'   => 'mls-fields',
        'admin'    => 'mls-fields-admin',
        'label'    => 'mls-fields-label',
        'postmeta' => 'mls-fields-map-postmeta',
        'taxonomy' => 'mls-fields-map-taxonomy'
    );

    $option_key = $option_map[ $option_type ];

    if ( ! isset( $options[ $option_key ] ) ) {
        $options[ $option_key ] = array();
        //error_log( '[mlsimport] Initialized option key: ' . $option_key );
    }

    // Sanitize and update value
    switch ( $option_type ) {
        case 'import':
        case 'admin':
            $options[ $option_key ][ $field_key ] = intval( $value );
            break;

        case 'label':
        case 'postmeta':
        case 'taxonomy':
            $options[ $option_key ][ $field_key ] = sanitize_text_field( $value );
            break;
    }

   // //error_log( '[mlsimport] Updated Options: ' . print_r( $options, true ) );

    // No changes
    if ( $options === $original_options ) {
        //error_log( '[mlsimport] No changes detected for option update.' );
        wp_send_json_success( array(
            'message'    => 'Field option saved successfully',
            'fieldKey'   => $field_key,
            'optionType' => $option_type,
            'value'      => $value,
        ) );
    }
    //error_log('saving  mls-fields '.count($options['mls-fields']));
    //error_log('saving mls-fields-admin '.count($options['mls-fields-admin']));
    //error_log('saving mls-fields-label '.count($options['mls-fields-label']));
    //error_log('saving mls-fields-map-postmeta '.count($options['mls-fields-map-postmeta']));
    //error_log('saving mls-fields-map-taxonomy '.count($options['mls-fields-map-taxonomy']));
    //error_log('saving field_order '.count($options['field_order']));

    // Save updated options
    $result = update_option( 'mlsimport_admin_fields_select', $options );

    if ( false !== $result ) {
        //error_log( '[mlsimport] Options updated successfully.' );
        wp_send_json_success( array(
            'message'    => 'Field option saved successfully',
            'fieldKey'   => $field_key,
            'optionType' => $option_type,
            'value'      => $value,
        ) );
    } else {
        //error_log( '[mlsimport] Failed to update options.' );
        wp_send_json_error( 'Failed to save field option' );
    }
}



function mlsimport_ajax_save_field_position() {
    // Verify nonce
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'mlsimport_field_selector_nonce')) {
        wp_send_json_error('Invalid security token');
    }

    // Validate required fields
    if (!isset($_POST['moving_index'], $_POST['target_index'], $_POST['position'])) {
        wp_send_json_error('Missing parameters');
    }

    $moving_index = intval($_POST['moving_index']);
    $target_index = intval($_POST['target_index']);
    $position     = sanitize_text_field($_POST['position']); // 'before' or 'after'

    // Load current options
    $options = get_option('mlsimport_admin_fields_select', []);
   
    if (empty($options['field_order']) || !is_array($options['field_order'])) {
        wp_send_json_error('Field order not found');
    }




    // Normalize field_order to ensure correct index order
    $order_map = $options['field_order'];

    asort($order_map); // Sort by index
    $ordered_keys = array_keys($order_map);
 
    // Safety checks
    if (!isset($ordered_keys[$moving_index]) || !isset($ordered_keys[$target_index])) {
        wp_send_json_error('Invalid indexes');
    }

    $moving_key = $ordered_keys[$moving_index];
    $target_key = $ordered_keys[$target_index];

    // Remove moving key
    array_splice($ordered_keys, $moving_index, 1);

    // Adjust target index if needed
    if ($position === 'after' && $moving_index < $target_index) {
        $target_index--;
    }

    // Calculate new position
    $insert_index = ($position === 'before') ? $target_index : $target_index + 1;

    // Insert the field key at the new position
    array_splice($ordered_keys, $insert_index, 0, $moving_key);

    // Rebuild the new order map
    $new_order = [];
    foreach ($ordered_keys as $i => $key) {
        $new_order[$key] = $i;
    }

    // Save new field order
    $options['field_order'] = $new_order;

    // Apply order to all related field maps
    $targets = [
        'mls-fields',
        'mls-fields-map-taxonomy',
        'mls-fields-map-postmeta',
        'mls-fields-map-admin',
        'mls-fields-map-label',
    ];

    foreach ($targets as $target) {
        if (!empty($options[$target]) && is_array($options[$target])) {
            $reordered = [];

            foreach ($new_order as $field_key => $index) {
                if (isset($options[$target][$field_key])) {
                    $reordered[$field_key] = $options[$target][$field_key];
                }
            }

            // Preserve any extra keys not in field_order
            foreach ($options[$target] as $key => $value) {
                if (!isset($reordered[$key])) {
                    $reordered[$key] = $value;
                }
            }

            $options[$target] = $reordered;
        }
    }

    // Save the updated options
    $saved = update_option('mlsimport_admin_fields_select', $options);
    if ( $saved ) {
        global $mlsimport;
        if ( isset( $mlsimport->admin->env_data ) && method_exists( $mlsimport->admin->env_data, 'enviroment_custom_fields' ) ) {
            $mlsimport->admin->env_data->enviroment_custom_fields( $mlsimport->get_plugin_name() );
        }
    }

    wp_send_json_success('Field order updated');
}

// Register the AJAX handler
add_action('wp_ajax_mlsimport_save_field_position', 'mlsimport_ajax_save_field_position');