<?php 
/**
 * Admin partial: MLS sync / import settings.
 *
 * Legacy settings surface. As of MlsImport 3.0 the per-import parameters moved to
 * each Import Task, so this page only shows an explanatory note and then renders
 * the per-item reconciliation links before returning early.
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



// Register the settings-API fields/sections for this option group.
settings_fields( $this->plugin_name . '_admin_mls_sync' );
do_settings_sections( $this->plugin_name . '_admin_mls_sync' );
// $options            =   get_option($this->plugin_name.'_admin_mls_sync');
// $metadata_api_call  =   $this->mls_env_data->return_metadata_enums_from_mls();




// Initialise plugin state before rendering.
$mlsimport->admin->setting_up();
?>
<h1>Import settings</h1>
<fieldset class="mlsimport-fieldset">      
<p class="mlsimport-exp">Starting with MlsImport 3.0 ths import settings options are set per each Mls Import Task. Create a new MlsImport and adjust the importat parameters from that interface.</p>
</fieldset>

<?php
// Render the per-import-task reconciliation links, then stop (nothing else on this page).
$mlsimport->admin->mls_env_data->start_reconciliation_links_per_item();

return;
