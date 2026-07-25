<?php 
/**
 * Admin partial: MLS/RESO API options (credentials form).
 *
 * The main connection screen. Renders the MLSImport account + MLS provider
 * credential fields (username/password, MLS selector, and the various
 * provider-specific tokens/IDs — Trestle, ConnectMLS, Rapattoni, Paragon,
 * Realtor.ca, BrightMLS), plus a theme selector. Above the form it tests and
 * reports both the MLSImport (SaaS) and MLS connection status. Fields are driven
 * by the $settings_list config array.
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

// Block direct access outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<form method="post" name="cleanup_options" action="options.php">
<?php
	// Register the settings-API fields/sections and load the saved credentials.
	settings_fields( $this->plugin_name . '_admin_options' );
	do_settings_sections( $this->plugin_name . '_admin_options' );
	$options = get_option( $this->plugin_name . '_admin_options' );

	global $mlsimport;






	// Field catalog for the credentials form: each key is the option name, mapping to
	// a display label ('name'), help text ('details'), and optional 'type' (e.g. select).
	$settings_list = array(

		'mlsimport_username'                => array(
			'name'    => esc_html__( 'MLSImport.com Username (not your email)', 'mlsimport' ),
			'details' => 'to be added',
		),
		'mlsimport_password'                => array(
			'name'    => esc_html__( 'MLSImport.com Password', 'mlsimport'),
			'details' => 'to be added',
		),


		'mlsimport_mls_name'                => array(
			'type'    => 'select',
			'name'    => esc_html__( 'Your MLS', 'mlsimport'),
			'details' => 'to be added',
		),

		'mlsimport_mls_token'               => array(
			'name'    => esc_html__( 'Your API Server token -  provided by your MLS','mlsimport'),
			'details' => 'to be added',
		),
		'mlsimport_tresle_client_id'        => array(
			'name'    => esc_html__( 'Your Trestle Client ID - provided by your MLS', 'mlsimport'),
			'details' => 'to be added',
		),

                'mlsimport_tresle_client_secret'    => array(
                        'name'    => esc_html__( 'Your Trestle Client Secret - provided by your MLS', 'mlsimport' ),
                        'details' => 'to be added',
                ),

                'mlsimport_connectmls_username'     => array(
                        'name'    => esc_html__( 'Your ConnectMLS Username - provided by your MLS', 'mlsimport' ),
                        'details' => 'to be added',
                ),

                'mlsimport_connectmls_password'     => array(
                        'name'    => esc_html__( 'Your ConnectMLS Password - provided by your MLS', 'mlsimport' ),
                        'details' => 'to be added',
                ),

                'mlsimport_rapattoni_client_id'     => array(
                        'name'    => esc_html__( 'MLSImport Rapattoni Client id', 'mlsimport' ),
                        'details' => 'to be added',
                ),

		'mlsimport_rapattoni_client_secret' => array(
			'name'    => esc_html__( 'MLSImport Rapattoni Client Secret', 'mlsimport' ),
			'details' => 'to be added',
		),

		'mlsimport_rapattoni_username'      => array(
			'name'    => esc_html__( 'MLSImport Rapattoni Username', 'mlsimport' ),
			'details' => 'to be added',
		),

		'mlsimport_rapattoni_password'      => array(
			'name'    => esc_html__( 'MLSImport Rapattoni Client Password','mlsimport' ),
			'details' => 'to be added',
		),


		'mlsimport_paragon_client_id'       => array(
			'name'    => esc_html__( 'MLSImport Paragon Client id', 'mlsimport' ),
			'details' => 'to be added',
		),

		'mlsimport_paragon_client_secret'   => array(
			'name'    => esc_html__( 'MLSImport Paragon Client Secret', 'mlsimport' ),
			'details' => 'to be added',
		),
		
		'mlsimport_realtorca_client_id'       => array(
			'name'    => esc_html__( 'MLSImport Realtor.ca Client id', 'mlsimport' ),
			'details' => 'to be added',
		),

		'mlsimport_realtorca_client_secret'   => array(
			'name'    => esc_html__( 'MLSImport Realtor.ca Client Secret', 'mlsimport' ),
			'details' => 'to be added',
		),

		'mlsimport_brightmls_client_id'       => array(
			'name'    => esc_html__( 'Your BrightMLS Client ID - provided by your MLS', 'mlsimport' ),
			'details' => 'to be added',
		),

		'mlsimport_brightmls_client_secret'   => array(
			'name'    => esc_html__( 'Your BrightMLS Client Secret - provided by your MLS', 'mlsimport' ),
			'details' => 'to be added',
		),

		'mlsimport_theme_used'              => array(
			'type'    => 'select',
			'name'    => esc_html__( 'Your Wordpress Theme', 'mlsimport'),
			'details' => 'to be added',
		),

	);



	?>
	




<?php
// Cached SaaS API token (empty = not connected to the MlsImport account).
$token            = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();

// Last known MLS connection result, then (re)initialise SaaS state.
$is_mls_connected = get_option( 'mlsimport_connection_test', '' );
$mlsimport->admin->mlsimport_saas_setting_up();



// Not yet confirmed connected — re-test the MLS connection and re-read the flag.
if ( 'yes' !==  $is_mls_connected  ) {
	$mlsimport->admin->mlsimport_saas_check_mls_connection();
	$is_mls_connected = get_option( 'mlsimport_connection_test', '' );
}






// No SaaS token — show the signup prompt and a "not connected" warning.
if ( trim( $token ) === '' ) {
	mlsimport_show_signup(); 
	?>
	<div class="mlsimport_warning">
		<?php esc_html_e( 'You are not connected to MlsImport - Please check your Username and Password.', 'mlsimport' );?> 
	</div>
	<?php
} else { ?>
	<div class="mlsimport_warning mlsimport_validated">
		<?php esc_html_e( 'You are connected to your MlsImport account!', 'mlsimport' );?>
	</div>
<?php
}

// Separately report the MLS-side connection status.
if ( 'yes' ===  $is_mls_connected  ) { ?>
	<div class="mlsimport_warning mlsimport_validated">
		<?php  esc_html_e( 'You are connected to your MLS.', 'mlsimport' );?>
	</div>
<?php
} else { ?>
	<div class="mlsimport_warning">
		<?php esc_html_e( 'The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved. ', 'mlsimport' );?>
	</div>
<?php
}


// Add before username fieldset
/*
global $mlsimport;
$current_token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
$token_expiry = get_option('mlsimport_token_expiry', 0);


$expiry_date = date('Y-m-d H:i:s', $token_expiry);
$is_expired = time() >= $token_expiry;
$status_color = $is_expired ? '#dc3545' : '#28a745';
$status_text = $is_expired ? 'EXPIRED' : 'VALID';

echo '<div style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border-radius: 5px;">';
echo '<h4>Current Token Status</h4>';
echo '<p><strong>Token:</strong> ' . esc_html(substr($current_token, 0, 20)) . '...</p>';
echo '<p><strong>Status:</strong> <span style="color: ' . $status_color . '; font-weight: bold;">' . $status_text . '</span></p>';
echo '<p><strong>Expires:</strong> ' . esc_html($expiry_date) . '</p>';
echo '</div>';
*/


// Render one fieldset per credential field defined in $settings_list.
foreach ( $settings_list as $key => $setting ) {
		// Current saved value for this field (escaped), or empty string if unset.
		$value = ( isset( $options[ $key ] ) && ! empty( $options[ $key ] ) ) ? esc_attr( $options[ $key ] ) : '';
	?>
		<fieldset class="mlsimport-fieldset <?php echo 'fieldset_' . esc_attr( $key ); ?>">
			<label class="mlsimport-label" for="<?php echo esc_attr($this->plugin_name ). '_admin_options'; ?>-<?php echo esc_attr($key); ?>" >
				<?php echo esc_html( $setting['name'] ); ?>
			</label>
			
			
			<?php
			// MLS selector: a searchable text box (front label) plus a hidden field for the
			// resolved MLS id; the list of available MLSes is fetched from the SaaS.
			if ( 'mlsimport_mls_name' === $key  && isset( $setting['type'] ) and  'select' === $setting['type']  ) {
				$mls_import_list = mlsimport_saas_request_list();
			
				?>

				<div class="mls_explanations">
					If your MLS is not on the list yet, requires manual activation, or if your credentials are not connecting, please <a href="https://mlsimport.com/contact-us/" target="_blank">contact us</a> for support.
				</div>
				
				<input type="text" class="mlsimport-input mlsimport-2025-input" id="mlsimport_mls_name_front"   name="mlsimport_admin_options[mlsimport_mls_name_front]" placeholder="search your MLS" value="<?php 
					if ( ! empty( $options['mlsimport_mls_name_front'] ) ) {
						echo esc_html($options['mlsimport_mls_name_front']);
					} 
				?>">


				<input type="hidden" id="mlsimport_mls_name" name="mlsimport_admin_options[mlsimport_mls_name]"  value="<?php
				if ( ! empty( $value ) ) {
					echo esc_html($value);
				} 
				?>">
				
				
				<?php 
			// Theme selector: render a select list of supported themes (escaped via wp_kses).
			} elseif ( 'mlsimport_theme_used' === $key  && isset( $setting['type'] ) and  'select' === $setting['type']  ) {
				$permited_tags	=	mlsimport_allowed_html_tags_content();
				$list 			= 	mlsiport_mls_select_list( $key, $value, MLSIMPORT_THEME);
				print wp_kses(	$list ,$permited_tags );
			} else {
				// Default: a plain text input (password type for the password fields).
				?>
			
                        <input
                                <?php
                                if ( in_array( $key, array( 'mlsimport_password', 'mlsimport_connectmls_password' ), true ) ) { ?>
                                        type="password"
                                <?php
                                } else { ?>
                                        type="text"
                                <?php
                                }
                                ?>
					
				class="mlsimport-input mlsimport-2025-input " autocomplete="off" 
				id="<?php echo esc_attr( $this->plugin_name . '_admin_options' ); ?>-<?php echo esc_attr( $key ); ?>" 
				name="<?php echo esc_attr( $this->plugin_name . '_admin_options' ); ?>[<?php echo esc_attr( $key ); ?>]" 
				value="<?php
					if ( ! empty( $value ) ) {
						echo trim( esc_html( ( $value ) ) );
					} else {
						echo '';
					}
					?>"/>
			<?php } ?>
		</fieldset>
<?php } ?>
	
 
<input type="hidden" name="<?php echo esc_attr($this->plugin_name) . '_admin_options'; ?>[force_rand]" value="<?php echo esc_attr( wp_rand() ); ?>">
	
<?php
// Save button (with a custom data-style attribute for the plugin's button styling).
$attributes = array( 'data-style' => 'mlsimport_but' );
submit_button( __( 'Save Changes', 'mlsimport' ), 'mlsimport_button save_data', 'submit', true, $attributes );
?>
</form>



<?php















?>