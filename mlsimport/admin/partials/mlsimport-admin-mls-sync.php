<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>

<form method="post" name="cleanup_options" action="options.php">
<?php



settings_fields( $this->plugin_name . '_admin_mls_sync' );
do_settings_sections( $this->plugin_name . '_admin_mls_sync' );
// $options            =   get_option($this->plugin_name.'_admin_mls_sync');
// $metadata_api_call  =   $this->mls_env_data->return_metadata_enums_from_mls();




$mlsimport->admin->setting_up();
?>
<h1>Import settings</h1>
<fieldset class="mlsimport-fieldset">      
<p class="mlsimport-exp">Starting with MlsImport 3.0 ths import settings options are set per each Mls Import Task. Create a new MlsImport and adjust the importat parameters from that interface.</p>
</fieldset>

<?php
$mlsimport->admin->mls_env_data->start_reconciliation_links_per_item();

return;
