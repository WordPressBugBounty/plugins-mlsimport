<?php
/**
 * Agents & Offices addon.
 *
 * On the front end (template_redirect), for MLSPIN-family feeds (mls_id 110/200) this
 * addon lazily fetches the listing agent's and office's details from the Bridge Data
 * Output API and caches them into the property's post meta (memberfullname / officename),
 * marking each property as checked so the API is called at most once per property.
 * It also registers the 'officename' and 'memberfullname' custom fields with the active
 * theme (WpResidence 991 / Houzez 992) once.
 *
 * @package MLSImport
 */


/**
 * Fetch agent data from MLS API and save it as post meta if not already saved or attempted.
 *
 * @param string $agent_id The MLS ID of the agent.
 * @param int    $post_id  The post ID to save the fetched data.
 * @param string $token    The API authorization token.
 */
function mlsimport_fetch_agent_data($mls_id,$agent_id, $post_id, $token) {
    // Guard: need both an agent ID and an API token.
    if (!$agent_id || !$token) {
        //error_log("MLSImport: Missing agent ID or token for post ID {$post_id}");
        return;
    }

    // Check if the agent data is already saved or fetch was attempted
    $existing_member_name = get_post_meta($post_id, 'memberfullname', true);
    $fetch_attempted = get_post_meta($post_id, 'mlsimport_agent_checked', true);

    // Skip when we already have data or have already tried once.
    if (!empty($existing_member_name) || $fetch_attempted) {
        //error_log("MLSImport: Agent data already fetched for post ID {$post_id}. Skipping API request.");
        return;
    }


    // Map the numeric MLS ID to its Bridge dataset slug.
    $mls_array_data = [
        '110' => 'mlspin',
        '200' => 'shared_mlspin_41854c5'
    ];
    
    // Construct API URL
    // Bridge OData Member endpoint for this agent.
     $api_url = "https://api.bridgedataoutput.com/api/v2/OData/{$mls_array_data[$mls_id]}/Member('{$agent_id}')";
    //error_log("MLSImport: Fetching agent data from API for Agent ID: {$agent_id}, Post ID: {$post_id}");

    // Set request headers with authorization token
    // Bearer-token auth header for the Bridge API.
    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token
        )
    );

    // Make GET request to the API
    $response = wp_remote_get($api_url, $args);

    // Handle request errors
    // On transport error, mark as checked and stop (avoids retry loops).
    if (is_wp_error($response)) {
        //error_log("MLSImport: Error fetching agent data for Agent ID: {$agent_id}. Marking as checked.");
        update_post_meta($post_id, 'mlsimport_agent_checked', 1);
        return;
    }

    // Decode the JSON response
    $data = json_decode(wp_remote_retrieve_body($response), true);
    //error_log("MLSImport: API response for Agent ID: {$agent_id}: " . json_encode($data));

    // Cache the agent's full name (lowercased) when present.
    if (!empty($data['MemberFullName'])) {
        update_post_meta($post_id, 'memberfullname', strtolower($data['MemberFullName']));
        //error_log("MLSImport: Saved MemberFullName for Agent ID: {$agent_id}, Post ID: {$post_id}");
    }

    // Mark that we attempted to fetch the agent data
    update_post_meta($post_id, 'mlsimport_agent_checked', 1);
}






/**
 * Fetch office data from MLS API and save it as post meta if not already saved or attempted.
 *
 * @param string $office_id The MLS ID of the office.
 * @param int    $post_id   The post ID to save the fetched data.
 * @param string $token     The API authorization token.
 */
function mlsimport_fetch_office_data($mls_id,$office_id, $post_id, $token) {
    // Guard: need both an office ID and an API token.
    if (!$office_id || !$token) {
        //error_log("MLSImport: Missing office ID or token for post ID {$post_id}");
        return;
    }

    // Check if the office data is already saved or fetch was attempted
    $existing_office_name = get_post_meta($post_id, 'officename', true);
    $fetch_attempted = get_post_meta($post_id, 'mlsimport_office_checked', true);

    // Skip when we already have data or have already tried once.
    if (!empty($existing_office_name) || $fetch_attempted) {
        //error_log("MLSImport: Office data already fetched for post ID {$post_id}. Skipping API request.");
        return;
    }


    // Map the numeric MLS ID to its Bridge dataset slug.
    $mls_array_data = [
        '110' => 'mlspin',
        '200' => 'shared_mlspin_41854c5'
    ];
    
    // Construct API URL
    // Bridge OData Office endpoint for this office.
    $api_url = "https://api.bridgedataoutput.com/api/v2/OData/{$mls_array_data[$mls_id]}/Office('{$office_id}')";

    //error_log("MLSImport: Fetching office data from API for Office ID: {$office_id}, Post ID: {$post_id}");

    // Set request headers with authorization token
    // Bearer-token auth header for the Bridge API.
    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token
        )
    );

    // Make GET request to the API
    $response = wp_remote_get($api_url, $args);

    // Handle request errors
    // On transport error, mark as checked and stop (avoids retry loops).
    if (is_wp_error($response)) {
        //error_log("MLSImport: Error fetching office data for Office ID: {$office_id}. Marking as checked.");
        update_post_meta($post_id, 'mlsimport_office_checked', 1);
        return;
    }

    // Decode the JSON response
    $data = json_decode(wp_remote_retrieve_body($response), true);
    //error_log("MLSImport: API response for Office ID: {$office_id}: " . json_encode($data));

    // Cache the office name (lowercased) when present.
    if (!empty($data['OfficeName'])) {
        update_post_meta($post_id, 'officename', strtolower($data['OfficeName']));
        //error_log("MLSImport: Saved OfficeName for Office ID: {$office_id}, Post ID: {$post_id}");
    }

    // Mark that we attempted to fetch the office data
    update_post_meta($post_id, 'mlsimport_office_checked', 1);
}





/**
 * Setup function to fetch and save MLS agent and office data only if not already saved or checked.
 */
function mlsimport_fetch_and_save_mls_data() {
    // Only act on single property pages (WpResidence or Real Homes post types).
    if (!is_singular(array('estate_property', 'property'))) {
        return; // Ensure it only runs on the correct post types
    }

    global $post;

    // Retrieve plugin options
    $options = get_option('mlsimport_admin_options');

    // Extract MLS ID and token from options
    $mls_id = isset($options['mlsimport_mls_name']) ? sanitize_text_field(trim($options['mlsimport_mls_name'])) : '';
    $mls_token = isset($options['mlsimport_mls_token']) ? sanitize_text_field(trim($options['mlsimport_mls_token'])) : '';

  // Ensure MLS ID is 110 or 200 before proceeding
    // This addon only supports the MLSPIN datasets and requires a token.
    if (!in_array($mls_id, ['110', '200']) || !$mls_token) {
        // error_log("MLSImport: Invalid MLS ID ({$mls_id}) or missing token. Skipping.");
        return;
    }
    // Get agent and office MLS IDs from post meta
    $agent_id = get_post_meta($post->ID, 'listagentmlsid', true);
    $office_id = get_post_meta($post->ID, 'listofficemlsid', true);

    //error_log("MLSImport: Checking property Post ID: {$post->ID}, Agent ID: {$agent_id}, Office ID: {$office_id}");

    // Fetch and save agent and office data only if they are not already stored or checked
    // Lazily fetch the agent record when the property has a list-agent ID.
    if ($agent_id) {
        mlsimport_fetch_agent_data($mls_id,$agent_id, $post->ID, $mls_token);
    }

    // Lazily fetch the office record when the property has a list-office ID.
    if ($office_id) {
        mlsimport_fetch_office_data($mls_id,$office_id, $post->ID, $mls_token);
    }
}

// Run the fetch and save function on template_redirect to ensure data is processed before rendering the page
add_action('template_redirect', 'mlsimport_fetch_and_save_mls_data');








/**
 * Updates custom fields based on the theme ID.
 *
 * If the theme ID is 991, it modifies the `wpresidence_admin` options by adding new custom fields.
 * If the theme ID is 992, it updates the `additional_features` meta field for properties.
 * The function runs only once by checking `mlsimport_custom_fields_updated`.
 */
function mlsimport_update_custom_fields() {
    // Read the active theme and MLS from plugin options.
    $options = get_option('mlsimport_admin_options');
    $theme_id = isset($options['mlsimport_theme_used']) ? intval($options['mlsimport_theme_used']) : 0;
    $mls_id = isset($options['mlsimport_mls_name']) ? sanitize_text_field(trim($options['mlsimport_mls_name'])) : '';



// Ensure MLS ID is 110 or 200 before proceeding
    // NOTE: $mls_token is not defined in this function's scope (see SPOTTED notes).
    if (!in_array($mls_id, ['110', '200']) || !$mls_token) {
        // error_log("MLSImport: Invalid MLS ID ({$mls_id}) or missing token. Skipping.");
        return;
    }

    // Check if update has already run to prevent duplicate updates
    if (get_option('mlsimport_custom_fields_updated')) {
        return;
    }

    // WpResidence (991): register the two fields in the theme's custom-fields list.
    if ($theme_id === 991) {
        // Retrieve theme options
        $theme_options = get_option('wpresidence_admin');
        $custom_fields = isset($theme_options['wpestate_custom_fields_list']) ? $theme_options['wpestate_custom_fields_list'] : array();

        // Ensure we have an array to work with.
        if (!is_array($custom_fields)) {
            $custom_fields = array();
        }

        // Check if the fields already exist before adding
        // Add the office-name field once.
        if (!in_array('officename', $custom_fields['add_field_name'] ?? [])) {
            $custom_fields['add_field_name'][] = 'officename';
            $custom_fields['add_field_label'][] = 'Office Name';
            $custom_fields['add_field_type'][] = 'short text';
            $custom_fields['add_field_order'][] = 998;
        }

        // Add the member-name field once.
        if (!in_array('memberfullname', $custom_fields['add_field_name'] ?? [])) {
            $custom_fields['add_field_name'][] = 'memberfullname';
            $custom_fields['add_field_label'][] = 'Member Name';
            $custom_fields['add_field_type'][] = 'short text';
            $custom_fields['add_field_order'][] = 999;
        }

        // Save updated custom fields
        $theme_options['wpestate_custom_fields_list'] = $custom_fields;
        // Only flag as done if the option actually saved.
        if (update_option('wpresidence_admin', $theme_options)) {
            // Mark as updated to prevent re-running
            update_option('mlsimport_custom_fields_updated', true);
        }
    // Houzez (992): add the fields to the current property's additional_features.
    } else if ($theme_id === 992) {
        // Update additional features meta for properties
        global $post;
        $property_id = $post->ID;
        $extra_fields = get_post_meta($property_id, 'additional_features', true);

        // Ensure we have an array to work with.
        if (!is_array($extra_fields)) {
            $extra_fields = array();
        }
        
        // Check if the fields already exist before adding
        // Collect existing feature titles to avoid duplicates.
        $existing_titles = array_column($extra_fields, 'fave_additional_feature_title');

        // Add the office-name feature once.
        if (!in_array('officename', $existing_titles)) {
            $extra_fields[] = array(
                'fave_additional_feature_title' => 'officename',
                'fave_additional_feature_value' => '',
            );
        }

        // Add the member-name feature once.
        if (!in_array('memberfullname', $existing_titles)) {
            $extra_fields[] = array(
                'fave_additional_feature_title' => 'memberfullname',
                'fave_additional_feature_value' => '',
            );
        }

        // Save updated additional features
        update_post_meta($property_id, 'additional_features', $extra_fields);
    }
}

// Hook into admin init or another relevant action
add_action('admin_init', 'mlsimport_update_custom_fields');
