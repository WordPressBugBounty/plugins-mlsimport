<?php
/**
 * Read-only renderer for the Field Configuration settings tab.
 *
 * Metadata gathering owns initialization and persistence. This partial only
 * checks connection state, asks the deep module for an in-memory normalized
 * view, and renders the active fields. There is deliberately no settings form,
 * options.php submission, pagination state, or browser-triggered initial save;
 * the JavaScript controller sends compact commands to the single AJAX seam.
 *
 * @package    MLSImport
 * @subpackage MLSImport/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $mlsimport;

$metadata_populated = get_option( 'mlsimport_mls_metadata_populated', '' );
$token              = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
$is_mls_connected   = get_option( 'mlsimport_connection_test', '' );

// Refresh connection state before deciding whether the configuration can be
// displayed. These calls preserve the settings page's existing connection flow.
$mlsimport->admin->mlsimport_saas_setting_up();
if ( 'yes' !== $is_mls_connected ) {
	$mlsimport->admin->mlsimport_saas_check_mls_connection();
	$is_mls_connected = get_option( 'mlsimport_connection_test', '' );
}

if ( '' === trim( $token ) ) {
	echo '<div class="mlsimport_warning">' . esc_html__( 'You are not connected to MlsImport - Please check your Username and Password.', 'mlsimport' ) . '</div>';
	return;
}

if ( 'yes' !== $is_mls_connected ) {
	echo '<div class="mlsimport_warning">' . esc_html__( 'The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved.', 'mlsimport' ) . '</div>';
	return;
}

if ( 'yes' === $metadata_populated ) {
	$metadata      = mlsimport_field_configuration_metadata();
	$theme_schema  = mlsimport_hardocde_theme_schema();
	$configuration = mlsimport_field_configuration()->read( $metadata, $theme_schema, mlsimport_field_configuration_taxonomies() );

	echo '<h3>' . esc_html__( 'Select the extra fields you want to import', 'mlsimport' ) . ':</h3>';
	echo render_mls_field_selection_interface(
		$metadata,
		$configuration,
		array(
			'search_term'     => '',
			'import_filter'   => 'all',
			'show_filters'    => true,
			'show_stats'      => true,
			'enable_drag_drop' => true,
			'plugin_name'     => 'mlsimport',
		),
		$theme_schema
	);
} elseif ( '' !== trim( $token ) ) {
	echo '<div class="mlsimport_warning mlsimport_validated">' . esc_html__( 'We need to gather some information about your MLS. Please Stand By! ', 'mlsimport' ) . '</div>';
} else {
	esc_html_e( 'You are not connected to MLS Import', 'mlsimport' );
}
?>
<input type="hidden" id="mlsimport_saas_get_metadata" value="<?php echo esc_attr( wp_create_nonce( 'mlsimport_saas_get_metadata' ) ); ?>">
