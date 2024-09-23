<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<form method="post" name="cleanup_options" action="options.php">
<?php
	settings_fields( $this->plugin_name . '_admin_options' );
	do_settings_sections( $this->plugin_name . '_admin_options' );
	$options = get_option( $this->plugin_name . '_admin_options' );

	global $mlsimport;






	$settings_list = array(

		'mlsimport_username'                => array(
			'name'    => esc_html__( 'MLSImport Username', 'mlsimport' ),
			'details' => 'to be added',
		),
		'mlsimport_password'                => array(
			'name'    => esc_html__( 'MLSImport Password', 'mlsimport'),
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
		'mlsimport_theme_used'              => array(
			'type'    => 'select',
			'name'    => esc_html__( 'Your Wordpress Theme', 'mlsimport'),
			'details' => 'to be added',
		),

	);



	?>
	




<?php
$token            = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();

$is_mls_connected = get_option( 'mlsimport_connection_test', '' );
$mlsimport->admin->mlsimport_saas_setting_up();



if ( 'yes' !==  $is_mls_connected  ) {
	$mlsimport->admin->mlsimport_saas_check_mls_connection();
	$is_mls_connected = get_option( 'mlsimport_connection_test', '' );
}






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

if ( 'yes' ===  $is_mls_connected  ) { ?>
	<div class="mlsimport_warning mlsimport_validated">
		<?php  esc_html_e( 'The connection to your MLS was successful', 'mlsimport' );?>
	</div>
<?php
} else { ?>
	<div class="mlsimport_warning">
		<?php esc_html_e( 'The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved. ', 'mlsimport' );?>
	</div>
<?php
}



foreach ( $settings_list as $key => $setting ) {
		$value = ( isset( $options[ $key ] ) && ! empty( $options[ $key ] ) ) ? esc_attr( $options[ $key ] ) : '';
	?>
		<fieldset class="mlsimport-fieldset <?php echo 'fieldset_' . esc_attr( $key ); ?>">
			<label class="mlsimport-label" for="<?php echo esc_attr($this->plugin_name ). '_admin_options'; ?>-<?php echo esc_attr($key); ?>" >
				<?php echo esc_html( $setting['name'] ); ?>
			</label>
			
			
			<?php
			if ( 'mlsimport_mls_name' === $key  && isset( $setting['type'] ) and  'select' === $setting['type']  ) {
				$mls_import_list = mlsimport_saas_request_list();
			
				?>

				<div class="mls_explanations">
					If your MLS is not on this list yet please <a href="https://mlsimport.com" target="_blank">contact us</a> in order to check it and enable it.
				</div>
				
				<input type="text" id="mlsimport_mls_name_front"   name="mlsimport_admin_options[mlsimport_mls_name_front]" placeholder="search your MLS" value="<?php 
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
			} elseif ( 'mlsimport_theme_used' === $key  && isset( $setting['type'] ) and  'select' === $setting['type']  ) {
				$permited_tags	=	mlsimport_allowed_html_tags_content();
				$list 			= 	mlsiport_mls_select_list( $key, $value, MLSIMPORT_THEME);
				print wp_kses(	$list ,$permited_tags );
			} else {
				?>
			
			<input 
				<?php
				if ( 'mlsimport_password' === $key  ) { ?>
					type="password" 
				<?php 
				} else { ?>
					type="text"
				<?php
				}
				?>
					
				class="mlsimport-input xxx" autocomplete="off" 
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
$attributes = array( 'data-style' => 'mlsimport_but' );
submit_button( __( 'Save Changes', 'mlsimport' ), 'mlsimport_button', 'submit', true, $attributes );
?>
</form>



<?php









/*
 *
 * create dropdown list
 *
 *
 */
function mlsiport_mls_select_list( $key, $value, $data_array ) {
	$select = '<select id="' . esc_attr( $key ) . '" name="mlsimport_admin_options[' . $key . ']">';
	if ( is_array( $data_array ) ) :
		foreach ( $data_array as $key => $mls_item ) {
			$select .= '<option value="' .esc_attr( $key ). '"';
			if ( intval( $value ) === intval( $key ) ) {
				$select .= ' selected ';
			}
			$select .= '>' .esc_html( $mls_item ). '</option>';
		}
	endif;
	$select .= '</select>';
	return $select;
}








?>