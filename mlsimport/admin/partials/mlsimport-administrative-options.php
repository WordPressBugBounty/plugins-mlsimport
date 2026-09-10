<?php 
/**
 * Admin partial: "Tools" tab (Administrative Options).
 *
 * Renders the plugin's administrative tools: toggles for system logs and property
 * history, cache/field-data clearing buttons, cron-job guidance, and a
 * taxonomy-scoped bulk "Delete Properties" tool. Also handles the POST for the two
 * toggle selects at the top (nonce-verified) before rendering the form.
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

// Block direct access outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$settings_import_value = '';

// Handle the toggle-form submit: only when the tool-actions nonce is present and valid.
if (isset($_POST['mlsimport_tool_actions']) &&
	wp_verify_nonce(  sanitize_text_field( wp_unslash( $_POST['mlsimport_tool_actions'] ) ), 'mlsimport_tool_actions')) {

	// Persist the "disable system logs" choice.
	if ( isset( $_POST['mlsimport-disable-logs'] ) ) {
		$disable_logs = intval( $_POST['mlsimport-disable-logs'] );
		update_option( 'mlsimport_disable_logs', $disable_logs );
	}
	// Persist the "disable property history" choice.
	if ( isset( $_POST['mlsimport-disable-history'] ) ) {
		$disable_history = intval( $_POST['mlsimport-disable-history'] );
		update_option( 'mlsimport-disable-history', $disable_history );
	}
	// Persist the WordPress-theme choice (moved here from the retired "MLS
	// Connection" tab): merge the single key into mlsimport_admin_options so
	// every other stored value survives untouched.
	if ( isset( $_POST['mlsimport_admin_options']['mlsimport_theme_used'] ) ) {
		$mlsimport_admin_options = get_option( 'mlsimport_admin_options', array() );
		$mlsimport_admin_options = is_array( $mlsimport_admin_options ) ? $mlsimport_admin_options : array();
		$mlsimport_admin_options['mlsimport_theme_used'] = intval( $_POST['mlsimport_admin_options']['mlsimport_theme_used'] );
		update_option( 'mlsimport_admin_options', $mlsimport_admin_options );
	}

	if ( isset( $_POST['mlsimport-import-settings'] ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			add_settings_error( 'mlsimport_settings_transfer', 'forbidden', __( 'You are not allowed to import plugin settings.', 'mlsimport' ), 'error' );
		} else {
			$settings_import_value = isset( $_POST['mlsimport-settings-import'] )
				? trim( (string) wp_unslash( $_POST['mlsimport-settings-import'] ) )
				: '';
			$settings_import_result = mlsimport_import_settings_json( $settings_import_value );
			if ( $settings_import_result['success'] ) {
				$settings_import_value = '';
				add_settings_error( 'mlsimport_settings_transfer', 'imported', __( 'Settings imported successfully.', 'mlsimport' ), 'updated' );
			} else {
				add_settings_error( 'mlsimport_settings_transfer', 'invalid_json', __( 'Paste a valid JSON object exported by MLSImport.', 'mlsimport' ), 'error' );
			}
		}
	}

}
?>

<form method="post" name="cleanup_options" action="">
	<?php
		global $mlsimport;
		// Initialise plugin/SaaS state before rendering the tools.
		$mlsimport->admin->mlsimport_saas_setting_up();
	 	//mlsimport_saas_event_mls_import_auto_function();
		//mlsimport_saas_reconciliation_event_function(); 
	?>
  
<h1> <?php esc_html_e( 'Administrative Tools', 'mlsimport' ); ?></h1>

<?php settings_errors( 'mlsimport_settings_transfer' ); ?>


<?php



// Current "disable logs" value; pre-select the matching <option> below.
$disable_logs = intval( get_option( 'mlsimport_disable_logs' ) );
$selected_no  = $selected_yes = '';

// 0 = logs disabled (default) -> select the "disabled" option; otherwise "enabled".
if ( 0 ===  intval($disable_logs)  ) {
	$selected_no = ' selected ';
} else {
	$selected_yes = ' selected ';
}


// Current "disable history" value (defaults to 1); pre-select the matching option.
$disable_history     = intval( get_option( 'mlsimport-disable-history', 1 ) );
$selected_history_no = $selected_history_yes = '';

// 0 = history disabled -> select the "disabled" option; otherwise "enabled".
if ( 0 ===  intval($disable_history)  ) {
	$selected_history_no = ' selected ';
} else {
	$selected_history_yes = ' selected ';
}
?>      

<div class="mlsimport_tool_block">
	<h4> <?php esc_html_e( 'Disable System Logs (logs should only be enabled during debug process)', 'mlsimport' ); ?> </h4>
	<select name="mlsimport-disable-logs" class="mlsimport-2025-select" id="mlsimport-disable-logs">
		<option value="0" <?php echo esc_html( $selected_no ); ?> ><?php esc_html_e( 'logs disabled', 'mlsimport' ); ?></option>
		<option value="1" <?php echo esc_html( $selected_yes ); ?>><?php esc_html_e( 'logs enabled', 'mlsimport' ); ?></option>

</select>
</div>


<div class="mlsimport_tool_block">
	<h4> <?php esc_html_e( 'Disable Property History (can be seen by editing a property in WordPress admin)', 'mlsimport' ); ?> </h4>
	<select name="mlsimport-disable-history" class="mlsimport-2025-select" id="mlsimport-disable-history">

		<option value="1" <?php echo esc_html( $selected_history_yes ); ?>><?php esc_html_e( 'history enabled', 'mlsimport' ); ?></option>
		<option value="0" <?php echo esc_html( $selected_history_no ); ?> ><?php esc_html_e( 'history disabled', 'mlsimport' ); ?></option>

</select>
</div>



<div class="mlsimport_tool_block">
	<h4> <?php esc_html_e( 'Your WordPress Theme (auto-detected — change only if the detection is wrong)', 'mlsimport' ); ?> </h4>
	<?php
	// Theme selector (moved here from the retired "MLS Connection" tab):
	// rendered from the resolved theme id — saved answer when there is one,
	// detected theme when there is not (#242).
	print wp_kses(
		mlsiport_mls_select_list( 'mlsimport_theme_used', mlsimport_resolve_theme_id(), MLSIMPORT_THEME ),
		mlsimport_allowed_html_tags_content()
	);
	?>
</div>


<?php submit_button( __( 'Save Changes', 'mlsimport' ), 'mlsimport_button button save_data', 'submit', true ); ?>

<div class="mlsimport_tool_block mlsimport_tool_card">
        <h3> <?php esc_html_e( 'Clear cached data', 'mlsimport' ); ?> </h3>
        <input class="button mlsimport_button" type="button" id="mlsimport-clear-cache" value="<?php esc_attr_e( 'Clear Plugin Cached Data', 'mlsimport' ); ?>" />
</div>

<div class="mlsimport_tool_block mlsimport_tool_card">
        <h3> <?php esc_html_e( 'Clear fields data', 'mlsimport' ); ?> </h3>
        <input class="button mlsimport_button" type="button" id="mlsimport-clear-fields-data" value="<?php esc_attr_e( 'Clear Field Data', 'mlsimport' ); ?>" />
</div>
	 
	 
<div class="mlsimport_tool_block">
	<h3><?php esc_html_e( 'Cron Jobs', 'mlsimport' ); ?> </h3>
	<div class="cron_job_explainin">
		<?php esc_html_e( 'By default a syncronization event runs every hour. The action will be triggered when someone visits your site if the scheduled time has passed. This is the default, "out of the box" way to do things in WordPress and it works very well in 99% of the cases.', 'mlsimport' ); ?>

		</br></br><?php esc_html_e( 'If, for some reason, you want to force the syncronization event to run every two hours(minimum time frame permitted by this plugin) you can set a cron job on your server enviroment and call this url : http://yourwebsite.com/?mlsimport_cron=yes.', 'mlsimport' ); ?>
		</br></br><strong><?php esc_html_e( 'Example : 0   */2 *   *   *   wget https://yourwebsite.com/?mlsimport_cron=yes', 'mlsimport' ); ?></strong> .
	</div>
</div>

<?php
// The legacy cron-log partial had no route and assumed the file always existed,
// which turned a missing optional log into PHP warnings. Keep the useful viewer
// on the existing Tools surface and treat an absent log as the normal empty state.
$cron_log_path = MLSIMPORT_PLUGIN_PATH . 'logs/cron_logs.log';
$cron_log_size = is_readable( $cron_log_path ) ? (int) filesize( $cron_log_path ) : 0;
?>
<div id="mlsimport-cron-log-viewer" class="mlsimport_tool_block mlsimport_tool_card">
	<h3><?php esc_html_e( 'Property Update logs', 'mlsimport' ); ?></h3>
	<?php if ( ! is_readable( $cron_log_path ) ) : ?>
		<p><?php esc_html_e( 'No cron log entries yet.', 'mlsimport' ); ?></p>
	<?php else : ?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: cron log file size in bytes. */
					__( 'Cron Log File Size is %d bytes', 'mlsimport' ),
					$cron_log_size
				)
			);
			?>
		</p>
		<?php if ( $cron_log_size < 3000000 ) : ?>
			<pre class="mlsimport-cron-log-content"><?php echo esc_html( (string) file_get_contents( $cron_log_path ) ); ?></pre>
		<?php else : ?>
			<p><?php esc_html_e( 'The file is too large to be displayed. You can read it in mlsimport/logs/cron_logs.log.', 'mlsimport' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>

<div id="mlsimport-settings-transfer" class="mlsimport_tool_block mlsimport_tool_card">
	<h3><?php esc_html_e( 'Export or import settings', 'mlsimport' ); ?></h3>
	<p><?php esc_html_e( 'Copy the export JSON for a backup, or paste an MLSImport export below to restore its settings.', 'mlsimport' ); ?></p>
	<label class="mlsimport-label" for="mlsimport-settings-export"><?php esc_html_e( 'Export settings', 'mlsimport' ); ?></label>
	<textarea id="mlsimport-settings-export" class="large-text code" rows="10" readonly><?php echo esc_textarea( wp_json_encode( mlsimport_export_settings_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
	<label class="mlsimport-label" for="mlsimport-settings-import"><?php esc_html_e( 'Import settings', 'mlsimport' ); ?></label>
	<textarea id="mlsimport-settings-import" name="mlsimport-settings-import" class="large-text code" rows="10"><?php echo esc_textarea( $settings_import_value ); ?></textarea>
	<button type="submit" class="button mlsimport_button" name="mlsimport-import-settings" value="1"><?php esc_html_e( 'Import Settings', 'mlsimport' ); ?></button>
</div>

<fieldset class="mlsimport-fieldset mlsimport_tool_block mlsimport_tool_card">

	<h3><?php esc_html_e('Delete Properties','mlsimport'); ?></h3>

	<div id="mlsimport-delete-notification"><?php esc_html_e('Select a taxonomy and terms, then click Delete.','mlsimport');?></div>

	<label class="mlsimport-label"><?php esc_html_e( 'Select Taxonomy', 'mlsimport' ); ?></label>
	<select id="mlsimport_delete_category" class="mlsimport-select mlsimport-2025-select">
		<option value=""><?php esc_html_e( '-- Select Taxonomy --', 'mlsimport' ); ?></option>
		<?php
		// Populate the taxonomy dropdown with the active property post type's taxonomies.
		$delete_taxonomies = mlsimport_get_custom_post_type_taxonomies( $mlsimport->admin->env_data->get_property_post_type() );
		// One <option> per taxonomy (value = slug, label shows name + slug).
		foreach ( $delete_taxonomies as $tax_slug => $tax_label ) :
		?>
			<option value="<?php echo esc_attr( $tax_slug ); ?>"><?php echo esc_html( $tax_label ); ?> (<?php echo esc_html( $tax_slug ); ?>)</option>
		<?php endforeach; ?>
	</select>

	<label class="mlsimport-label"><?php esc_html_e( 'Select Terms', 'mlsimport' ); ?></label>
	<select id="mlsimport_delete_category_term" class="mlsimport-select mlsimport-2025-select" multiple disabled>
		<option value="" disabled><?php esc_html_e( 'Select a taxonomy first', 'mlsimport' ); ?></option>
	</select>
	<p class="mlsimport-exp"><?php esc_html_e( 'Hold Ctrl (Windows) or Command (Mac) to select multiple terms.', 'mlsimport' ); ?></p>

	<div id="mlsimport-delete-progress" style="display:none;">
		<div class="mlsimport-delete-progress-track">
			<div id="mlsimport-delete-progress-bar" style="width:0%;"></div>
		</div>
		<span id="mlsimport-delete-progress-text">0 / 0</span>
	</div>

	<input class="button mlsimport_button error_action" type="button" id="mlsimport-delete-prop" value="<?php esc_attr_e( 'Delete', 'mlsimport' ); ?>" />
	<input class="button" type="button" id="mlsimport-delete-stop" value="<?php esc_attr_e( 'Stop', 'mlsimport' ); ?>" style="display:none;" />
</fieldset>
<?php
// Nonce for the tools form / delete AJAX actions, emitted as a hidden field below.
$ajax_nonce = wp_create_nonce( "mlsimport_tool_actions" );
?>

<input type="hidden" id="mlsimport_tool_actions" name="mlsimport_tool_actions" value="<?php echo esc_attr($ajax_nonce); ?>" />

<input type="hidden" name="action" value="mlsimport_form_action">
</form>
