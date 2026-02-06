<?php
/**
 * Refactored onboarding step using admin options, keeping original onboarding layout and logic.
 */

if (!defined('ABSPATH')) exit;
global $mlsimport;

settings_fields( 'mlsimport_admin_options');
do_settings_sections('mlsimport_admin_options');
$options = get_option('mlsimport_admin_options');


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
	'mlsimport_theme_used'              => array('type' => 'select', 'name' => esc_html__('Your Wordpress Theme', 'mlsimport')),
);

$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
$is_mls_connected = get_option('mlsimport_connection_test', '');
$mlsimport->admin->mlsimport_saas_setting_up();

if ('yes' !== $is_mls_connected) {
	$mlsimport->admin->mlsimport_saas_check_mls_connection();
	$is_mls_connected = get_option('mlsimport_connection_test', '');
}

if (trim(string: $token) === '') {

	echo '<div class="mlsimport_warning">' . esc_html__('You are not connected to MlsImport - Please check your Username and Password.', 'mlsimport') . '</div>';
} else {
	echo '<div class="mlsimport_warning mlsimport_validated">' . esc_html__('You are connected to your MlsImport account!', 'mlsimport') . '</div>';
}

if ('yes' === $is_mls_connected) {
	echo '<div class="mlsimport_warning mlsimport_validated">' . esc_html__('You are connected to your MLS.', 'mlsimport') . '</div>';
} else {
	echo '<div class="mlsimport_warning">' . esc_html__('The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved.', 'mlsimport') . '</div>';
}

foreach ($settings_list as $key => $setting) {
	$value = isset($options[$key]) ? esc_attr($options[$key]) : '';
	echo '<fieldset class="mlsimport-fieldset fieldset_' . esc_attr($key) . '">';
	echo '<label class="mlsimport-label" for="' . esc_attr('mlsimport_admin_options') . '-' . esc_attr($key) . '">' . esc_html($setting['name']) . '</label>';

	if ($key === 'mlsimport_mls_name' && isset($setting['type']) && $setting['type'] === 'select') {
		$mls_import_list = mlsimport_saas_request_list();
		echo '<div class="mls_explanations">If your MLS is not on the list yet, requires manual activation, or if your credentials are not connecting, please <a href="https://mlsimport.com/contact-us/" target="_blank">contact us</a> for support.</div>';
		echo '<input type="text" id="mlsimport_mls_name_front" name="mlsimport_admin_options[mlsimport_mls_name_front]" placeholder="search your MLS" value="' . esc_attr($options['mlsimport_mls_name_front'] ?? '') . '">';
		echo '<input type="hidden" id="mlsimport_mls_name" name="mlsimport_admin_options[mlsimport_mls_name]" value="' . esc_attr($value) . '">';
	} elseif ($key === 'mlsimport_theme_used' && isset($setting['type']) && $setting['type'] === 'select') {
		$list = mlsiport_mls_select_list($key, $value, MLSIMPORT_THEME);
		echo wp_kses($list, mlsimport_allowed_html_tags_content());
        } else {
                $password_fields = array('mlsimport_password', 'mlsimport_connectmls_password');
                $type = in_array($key, $password_fields, true) ? 'password' : 'text';
                echo '<input type="' . $type . '" class="mlsimport-input xxx" autocomplete="off" id="' . esc_attr( 'mlsimport_admin_options') . '-' . esc_attr($key) . '" name="' . esc_attr('mlsimport_admin_options') . '[' . esc_attr($key) . ']" value="' . $value . '" />';
        }

    if($key ==='mlsimport_password'){
        echo '<button  class="button button-primary mlsimport-save-account">'.esc_html('Save account','mlsimport').'</button>';
                echo '<a href="https://mlsimport.com/mls-import-plugin-pricing" class="button button-primary mlsimport-save-account"  style="margin-left:15px;" target="_blank">'. esc_html__('Create My Account', 'mlsimport').'</a>';
        }


	echo '</fieldset>';
}

echo '<input type="hidden" name="mlsimport_admin_options[force_rand]" value="' . esc_attr(wp_rand()) . '">';
echo '<button  class="button button-primary mlsimport-save-mls-data">'.esc_html('Save MLS','mlsimport').'</button>';
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