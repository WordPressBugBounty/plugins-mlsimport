<?php
/**
 * Refactored onboarding step using admin options, keeping original onboarding layout and logic.
 *
 * Onboarding wizard step: MLSImport account + MLS credentials. Mirrors the main
 * credentials partial (mlsimport-admin-options.php) but rendered inline for the
 * wizard: it tests/reports the SaaS and MLS connection status, renders one field
 * per credential from $settings_list, and provides save buttons. The trailing
 * <script> enables the wizard's "Continue" button only once both connections
 * succeed and wires the step navigation.
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
	'mlsimport_username'                => array('name' => esc_html__('MLSImport.com Username (not your email)', 'mlsimport')),
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
if (trim(string: $token) === '') {

	echo '<div class="mlsimport_warning">' . esc_html__('You are not connected to MlsImport - Please check your Username and Password.', 'mlsimport') . '</div>';
} else {
	echo '<div class="mlsimport_warning mlsimport_validated">' . esc_html__('You are connected to your MlsImport account!', 'mlsimport') . '</div>';
}

// Report MLS-side connection status separately.
if ('yes' === $is_mls_connected) {
	echo '<div class="mlsimport_warning mlsimport_validated">' . esc_html__('You are connected to your MLS.', 'mlsimport') . '</div>';
} else {
	echo '<div class="mlsimport_warning">' . esc_html__('The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved.', 'mlsimport') . '</div>';
}

// Render one fieldset per credential field.
foreach ($settings_list as $key => $setting) {
	// Current saved value for this field (escaped), or empty string if unset.
	$value = isset($options[$key]) ? esc_attr($options[$key]) : '';
	echo '<fieldset class="mlsimport-fieldset fieldset_' . esc_attr($key) . '">';
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
		$list = mlsiport_mls_select_list($key, $value, MLSIMPORT_THEME);
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
                echo '<a href="https://mlsimport.com/mls-import-plugin-pricing" class="button button-primary mlsimport-save-account"  style="margin-left:15px;" target="_blank">'. esc_html__('Create My Account', 'mlsimport').'</a>';
        }


	echo '</fieldset>';
}

// Random hidden value to force the option to change so WP always persists a save.
echo '<input type="hidden" name="mlsimport_admin_options[force_rand]" value="' . esc_attr(wp_rand()) . '">';
// Final "Save MLS" button for the provider credentials.
echo '<button  class="button button-primary mlsimport-save-mls-data">'.esc_html__('Save MLS','mlsimport').'</button>';
?>

<script>
jQuery(document).ready(function(jQuery) {
	// Check initial state and disable/enable button accordingly
	function updateContinueButtonState() {
		var token = '<?php echo trim($token); ?>';
		var isConnected = '<?php echo $is_mls_connected; ?>';
		var $continueButton = jQuery('.mlsimport-wizard-content-account .mlsimport-wizard-next');
		if (token === '' || isConnected !== 'yes') {
			$continueButton.prop('disabled', true);
			$continueButton.addClass('disabled');
		} else {
			$continueButton.prop('disabled', false);
			$continueButton.removeClass('disabled');
		}
	}

	// Run on page load
	updateContinueButtonState();

	// Handle continue button click on account step
	jQuery('.mlsimport-wizard-content-account .mlsimport-wizard-next').on('click', function(e) {
		e.preventDefault();
		
		// Only proceed if button is not disabled
		if (!jQuery(this).prop('disabled')) {
			window.location.href = '<?php echo admin_url('admin.php?page=mlsimport-onboarding&step=field-mapping'); ?>';
		}
		
		return false;
	});

	// Enable button when MLS connection is successful
	jQuery('.mlsimport-save-account, .mlsimport-save-mls-data').on('click', function() {
		// Add slight delay to let AJAX complete
		setTimeout(updateContinueButtonState, 1000);
	});
});

</script>