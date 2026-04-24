<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if (isset($_POST['mlsimport_tool_actions']) && 
	wp_verify_nonce(  sanitize_text_field( wp_unslash( $_POST['mlsimport_tool_actions'] ) ), 'mlsimport_tool_actions')) {

	if ( isset( $_POST['mlsimport-disable-logs'] ) ) {
		$disable_logs = intval( $_POST['mlsimport-disable-logs'] );
		update_option( 'mlsimport_disable_logs', $disable_logs );
	}
	if ( isset( $_POST['mlsimport-disable-history'] ) ) {
		$disable_history = intval( $_POST['mlsimport-disable-history'] );
		update_option( 'mlsimport-disable-history', $disable_history );
	}
	
}
?>

<form method="post" name="cleanup_options" action="">
	<?php
		global $mlsimport;
		settings_fields( $this->plugin_name . '_administrative_options' );
		do_settings_sections( $this->plugin_name . '_administrative_options' );
		$options = get_option( $this->plugin_name . '_administrative_options' );
		$mlsimport->admin->mlsimport_saas_setting_up();
	 	//mlsimport_saas_event_mls_import_auto_function();
		//mlsimport_saas_reconciliation_event_function(); 
	?>
  
<h1> Administrative Tools</h1>


<?php



$disable_logs = intval( get_option( 'mlsimport_disable_logs' ) );
$selected_no  = $selected_yes = '';

if ( 0 ===  intval($disable_logs)  ) {
	$selected_no = ' selected ';
} else {
	$selected_yes = ' selected ';
}


$disable_history     = intval( get_option( 'mlsimport-disable-history', 1 ) );
$selected_history_no = $selected_history_yes = '';

if ( 0 ===  intval($disable_history)  ) {
	$selected_history_no = ' selected ';
} else {
	$selected_history_yes = ' selected ';
}
?>      

<div class="mlsimport_tool_field_item_wrapper">    
	<h4 style="margin-bottom:0px;"> Disable System Logs (logs should only be enabled during debug process) </h4>  
	<select name="mlsimport-disable-logs" class="mlsimport-2025-select" id="mlsimport-disable-logs"> 
		<option value="0" <?php echo esc_html( $selected_no ); ?> >logs disabled</option>
		<option value="1" <?php echo esc_html( $selected_yes ); ?>>logs enabled</option>

</select>
</div>


<div class="mlsimport_tool_field_item_wrapper">    
	<h4 style="margin-bottom:0px;"> Disable Property History (can be seen by editing a property in WordPress admin) </h4>  
	<select name="mlsimport-disable-history" class="mlsimport-2025-select" id="mlsimport-disable-history"> 
	 
		<option value="1" <?php echo esc_html( $selected_history_yes ); ?>>history enabled</option>
		<option value="0" <?php echo esc_html( $selected_history_no ); ?> >history disabled</option>

</select>
</div>


		 
<?php submit_button( __( 'Save Changes', 'mlsimport' ), 'mlsimport_button button save_data', 'submit', true ); ?>

<div class="mlsimport_tool_field_item_wrapper"  style="background-color: #eee;padding: 10px;border-radius: 5px;">
        <h3 style="margin-bottom:20px;"> Clear cached data </h3>
        <input class="button mlsimport_button "  type="button" id="mlsimport-clear-cache" value="Clear Plugin Cached Data" />
</div>

<div class="mlsimport_tool_field_item_wrapper"  style="background-color: #eee;padding: 10px;border-radius: 5px;">
        <h3 style="margin-bottom:20px;"> Clear fields data </h3>
        <input class="button mlsimport_button "  type="button" id="mlsimport-clear-fields-data" value="Clear Field Data" />
</div>
	 
	 
<div class="mlsimport_tool_field_item_wrapper">     
	<h3 style="margin-bottom:0px;">Cron Jobs </h3>
	<div class="cron_job_explainin">
		By default a syncronization event runs every hour. The action will be triggered when someone visits your site if the scheduled time has passed. This is the default, "out of the box" way to do things in WordPress and it works very well in 99% of the cases.

		</br></br>If, for some reason, you want to force the syncronization event to run every two hours(minimum time frame permitted by this plugin) you can set a cron job on your server enviroment and call this url : http://yourwebsite.com/?mlsimport_cron=yes.
		</br></br><strong>Example : 0   */2 *   *   *   wget https://yourwebsite.com/?mlsimport_cron=yes</strong> .   
	</div>
<div>
	 
<fieldset class="mlsimport-fieldset" style="background-color: #eee;padding: 10px;border-radius: 5px;">

	<h3><?php esc_html_e('Delete Properties','mlsimport'); ?></h3>

	<div id="mlsimport-delete-notification"><?php esc_html_e('Select a taxonomy and terms, then click Delete.','mlsimport');?></div>

	<label class="mlsimport-label"><?php esc_html_e( 'Select Taxonomy', 'mlsimport' ); ?></label><br>
	<select id="mlsimport_delete_category" class="mlsimport-select mlsimport-2025-select">
		<option value=""><?php esc_html_e( '-- Select Taxonomy --', 'mlsimport' ); ?></option>
		<?php
		$delete_taxonomies = mlsimport_get_custom_post_type_taxonomies( $mlsimport->admin->env_data->get_property_post_type() );
		foreach ( $delete_taxonomies as $tax_slug => $tax_label ) :
		?>
			<option value="<?php echo esc_attr( $tax_slug ); ?>"><?php echo esc_html( $tax_label ); ?> (<?php echo esc_html( $tax_slug ); ?>)</option>
		<?php endforeach; ?>
	</select>
	<br><br>

	<label class="mlsimport-label"><?php esc_html_e( 'Select Terms', 'mlsimport' ); ?></label><br>
	<select id="mlsimport_delete_category_term" class="mlsimport-select mlsimport-2025-select" multiple disabled style="min-height:120px;width:100%;max-width:400px;">
		<option value="" disabled><?php esc_html_e( 'Select a taxonomy first', 'mlsimport' ); ?></option>
	</select>
	<p class="mlsimport-exp"><?php esc_html_e( 'Hold Ctrl (Windows) or Command (Mac) to select multiple terms.', 'mlsimport' ); ?></p>
	<br>

	<div id="mlsimport-delete-progress" style="display:none;margin-bottom:10px;">
		<div style="background:#ddd;border-radius:4px;overflow:hidden;height:20px;margin-bottom:5px;">
			<div id="mlsimport-delete-progress-bar" style="background:#0073aa;height:100%;width:0%;transition:width 0.3s;"></div>
		</div>
		<span id="mlsimport-delete-progress-text">0 / 0</span>
	</div>

	<input class="button mlsimport_button error_action" type="button" id="mlsimport-delete-prop" value="<?php esc_attr_e( 'Delete', 'mlsimport' ); ?>" />
	<input class="button" type="button" id="mlsimport-delete-stop" value="<?php esc_attr_e( 'Stop', 'mlsimport' ); ?>" style="display:none;margin-left:10px;" />
</fieldset>
<?php
$ajax_nonce = wp_create_nonce( "mlsimport_tool_actions" );
?>

<input type="hidden" id="mlsimport_tool_actions" name="mlsimport_tool_actions" value="<?php echo esc_attr($ajax_nonce); ?>" />

<input type="hidden" name="action" value="mlsimport_form_action">
</form>
