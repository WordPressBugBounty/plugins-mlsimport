<?php
/**
 * Refactored onboarding step using admin options, keeping original onboarding layout and logic.
 *
 * Onboarding wizard step: MLSImport account + MLS credentials. The wizard's own
 * inline credentials form (the settings page's equivalent surface is the
 * Connections tab + drawer): it tests/reports the SaaS and MLS connection
 * status, renders one field
 * per credential from $settings_list, and provides save buttons. The trailing
 * <script> enables the wizard's "Continue" button only once both connections
 * succeed and wires the step navigation.
 * Account login accepts a username or email through the existing username
 * option; provider-specific credential labels retain their own meaning.
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

// Block direct access outside of WordPress.
if (!defined('ABSPATH')) exit;
global $mlsimport;

// Register the settings-API fields/sections and load the saved credentials.
settings_fields( 'mlsimport_admin_options');
do_settings_sections('mlsimport_admin_options');
$options = get_option('mlsimport_admin_options');


// Field catalog: option key => display label (+ optional 'type' => 'select').
$settings_list = array(
	'mlsimport_username'                => array('name' => esc_html__('MLSImport.com Username or email', 'mlsimport')),
	'mlsimport_password'                => array('name' => esc_html__('MLSImport.com Password', 'mlsimport')),
	'mlsimport_mls_name'                => array('type' => 'select', 'name' => esc_html__('Your MLS', 'mlsimport')),
	'mlsimport_mls_token'               => array('name' => esc_html__('Your API Server token -  provided by your MLS', 'mlsimport')),
        'mlsimport_tresle_client_id'        => array('name' => esc_html__('Your Trestle Client ID - provided by your MLS', 'mlsimport')),
        'mlsimport_tresle_client_secret'    => array('name' => esc_html__('Your Trestle Client Secret - provided by your MLS', 'mlsimport')),
        'mlsimport_connectmls_username'     => array('name' => esc_html__('Your ConnectMLS Username - provided by your MLS', 'mlsimport')),
        'mlsimport_connectmls_password'     => array('name' => esc_html__('Your ConnectMLS Password - provided by your MLS', 'mlsimport')),
        'mlsimport_rapattoni_client_id'     => array('name' => esc_html__('MLSImport Rapattoni Client id', 'mlsimport')),
        'mlsimport_rapattoni_client_secret' => array('name' => esc_html__('MLSImport Rapattoni Client Secret', 'mlsimport')),
	'mlsimport_rapattoni_username'      => array('name' => esc_html__('MLSImport Rapattoni Username', 'mlsimport')),
	'mlsimport_rapattoni_password'      => array('name' => esc_html__('MLSImport Rapattoni Client Password', 'mlsimport')),
	'mlsimport_paragon_client_id'       => array('name' => esc_html__('MLSImport Paragon Client id', 'mlsimport')),
	'mlsimport_paragon_client_secret'   => array('name' => esc_html__('MLSImport Paragon Client Secret', 'mlsimport')),
	'mlsimport_realtorca_client_id'     => array('name' => esc_html__('MLSImport Realtor.ca Client id', 'mlsimport')),
	'mlsimport_realtorca_client_secret' => array('name' => esc_html__('MLSImport Realtor.ca Client Secret', 'mlsimport')),
	'mlsimport_brightmls_client_id'     => array('name' => esc_html__('Your BrightMLS Client ID - provided by your MLS', 'mlsimport')),
	'mlsimport_brightmls_client_secret' => array('name' => esc_html__('Your BrightMLS Client Secret - provided by your MLS', 'mlsimport')),
	'mlsimport_theme_used'              => array('type' => 'select', 'name' => esc_html__('Your Wordpress Theme', 'mlsimport')),
);

// Cached SaaS token and last known MLS connection result; (re)init SaaS state.
$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
$is_mls_connected = get_option('mlsimport_connection_test', '');
$mlsimport->admin->mlsimport_saas_setting_up();

// Not yet confirmed connected — re-test the MLS connection and re-read the flag.
if ('yes' !== $is_mls_connected) {
	$mlsimport->admin->mlsimport_saas_check_mls_connection();
	$is_mls_connected = get_option('mlsimport_connection_test', '');
}

// Report SaaS account status: empty token = not connected, otherwise connected.
// The not-connected box names the server's reason (no subscription vs wrong
// password) — see includes/mlsimport-account-status.php.
if (trim(string: $token) === '') {
	echo mlsimport_account_not_connected_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the builder.
} else {
	echo '<div class="mlsimport_warning mlsimport_validated">' . esc_html__('You are connected to your MlsImport account!', 'mlsimport') . '</div>';
}

// Report MLS-side connection status separately.
if ('yes' === $is_mls_connected) {
	echo '<div class="mlsimport_warning mlsimport_validated">' . esc_html__('You are connected to your MLS.', 'mlsimport') . '</div>';
} else {
	echo '<div class="mlsimport_warning">' . esc_html__('The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved.', 'mlsimport') . '</div>';
}

// Server-side provider visibility. Resolve the currently selected MLS to its
// provider family ONCE, before rendering, so every provider credential fieldset
// that does not belong to the selected family is emitted already hidden
// (inline display:none) instead of waiting for the footer JavaScript to hide it.
// mlsimport_token_on_load() in mlsimport-admin.js applies the exact same rule
// when the user changes MLS without a page reload.
$selected_mls_id     = (is_array($options) && !empty($options['mlsimport_mls_name'])) ? (string) $options['mlsimport_mls_name'] : '';
$provider_visibility = Mlsimport_Provider_Family::browser_config_for_ids(
	'' !== $selected_mls_id ? array($selected_mls_id) : array(),
	Mlsimport_Provider_Family::saved_type($selected_mls_id),
	$selected_mls_id
);
// Full catalog of provider credential option keys (marks which fieldsets toggle).
$all_credential_fields    = $provider_visibility['all_credential_fields'];
// Credential keys of the selected MLS's family; empty when no MLS is selected.
$active_credential_fields = isset($provider_visibility['by_mls_id'][$selected_mls_id]['credential_fields'])
	? $provider_visibility['by_mls_id'][$selected_mls_id]['credential_fields']
	: array();

// Render one fieldset per credential field.
foreach ($settings_list as $key => $setting) {
	// Current saved value for this field (escaped), or empty string if unset.
	$value = isset($options[$key]) ? esc_attr($options[$key]) : '';
	// A provider credential fieldset renders hidden unless it belongs to the
	// selected MLS's provider family (shared fields always render visible).
	$is_hidden_credential = in_array($key, $all_credential_fields, true) && !in_array($key, $active_credential_fields, true);
	echo '<fieldset class="mlsimport-fieldset fieldset_' . esc_attr($key) . '"' . ($is_hidden_credential ? ' style="display:none"' : '') . '>';
	echo '<label class="mlsimport-label" for="' . esc_attr('mlsimport_admin_options') . '-' . esc_attr($key) . '">' . esc_html($setting['name']) . '</label>';

	// MLS selector: searchable text box (front label) + hidden id field; list from SaaS.
	if ($key === 'mlsimport_mls_name' && isset($setting['type']) && $setting['type'] === 'select') {
		$mls_import_list = mlsimport_saas_request_list();
		echo '<div class="mls_explanations">' . wp_kses(
			sprintf(
				/* translators: %s: "contact us" link to the MLSImport contact page. */
				__( 'If your MLS is not on the list yet, requires manual activation, or if your credentials are not connecting, please %s for support.', 'mlsimport' ),
				'<a href="https://mlsimport.com/contact-us/" target="_blank">' . esc_html__( 'contact us', 'mlsimport' ) . '</a>'
			),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
				),
			)
		) . '</div>';
		echo '<input type="text" id="mlsimport_mls_name_front" name="mlsimport_admin_options[mlsimport_mls_name_front]" placeholder="' . esc_attr__( 'search your MLS', 'mlsimport' ) . '" value="' . esc_attr($options['mlsimport_mls_name_front'] ?? '') . '">';
		echo '<input type="hidden" id="mlsimport_mls_name" name="mlsimport_admin_options[mlsimport_mls_name]" value="' . esc_attr($value) . '">';
	// Theme selector: render a select list of supported themes (escaped via wp_kses).
	} elseif ($key === 'mlsimport_theme_used' && isset($setting['type']) && $setting['type'] === 'select') {
		// Preselect the theme the site already reports. Detection only fills a
		// blank: a saved answer wins, and nothing is written by rendering (#242).
		$list = mlsiport_mls_select_list($key, mlsimport_resolve_theme_id(), MLSIMPORT_THEME);
		echo wp_kses($list, mlsimport_allowed_html_tags_content());
        } else {
                // Default: a text input (password type for the password fields).
                $password_fields = array('mlsimport_password', 'mlsimport_connectmls_password');
                $type = in_array($key, $password_fields, true) ? 'password' : 'text';
                echo '<input type="' . $type . '" class="mlsimport-input xxx" autocomplete="off" id="' . esc_attr( 'mlsimport_admin_options') . '-' . esc_attr($key) . '" name="' . esc_attr('mlsimport_admin_options') . '[' . esc_attr($key) . ']" value="' . $value . '" />';
        }

    // After the password field, add the "Save account" + "Create My Account" buttons.
    if($key ==='mlsimport_password'){
        echo '<button  class="button button-primary mlsimport-save-account">'.esc_html__('Save account','mlsimport').'</button>';
                // Keep the pricing link visually paired with Save Account, but
                // give it a behavior-neutral class so the credential-save click
                // handler cannot prevent its external navigation (#307).
                echo '<a href="https://mlsimport.com/mls-import-plugin-pricing" class="button button-primary mlsimport-create-account"  style="margin-left:15px;" target="_blank">'. esc_html__('Create My Account', 'mlsimport').'</a>';
        }


	echo '</fieldset>';
}

// Random hidden value to force the option to change so WP always persists a save.
echo '<input type="hidden" name="mlsimport_admin_options[force_rand]" value="' . esc_attr(wp_rand()) . '">';
// Final "Save MLS" button for the provider credentials.
echo '<button  class="button button-primary mlsimport-save-mls-data">'.esc_html__('Save MLS','mlsimport').'</button>';
// Nonce for the background metadata gather fired by the Save MLS success
// callback (mlsimport-onboarding.js) once the MLS connection is confirmed.
echo '<input type="hidden" id="mlsimport_saas_get_metadata" value="' . esc_attr( wp_create_nonce( 'mlsimport_saas_get_metadata' ) ) . '">';
?>
