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
 * PER-CONNECTION SCOPE (multi-MLS): the Field Configuration is stored per
 * connection (#275). This tab addresses ONE connection, resolved by the shared
 * scope rule (includes/mlsimport-field-mapping-scope.php): ?mls=<id> when it
 * names a registered connection, the current connection otherwise. With 2+
 * connections a selector at the top switches the scope by reloading the page;
 * the resolved scope is exposed to the JS in the hidden #mlsimport_field_scope
 * input so every mutation and gather request stays on the same connection.
 *
 * Step by step:
 * 1. Resolve the scope and read that connection's populated flag.
 * 2. Gate on the global SaaS account token (the account is install-wide).
 * 3. Gate on the MLS connection: the CURRENT connection keeps the historic
 *    global flag + refresh flow; a non-current scope uses its own record's
 *    tested status (the global flag belongs to the current connection only).
 * 4. With 2+ connections, render the scope selector.
 * 5. Render the scoped configuration, or the "gathering" notice when the
 *    scope's metadata is not populated yet.
 *
 * @package    MLSImport
 * @subpackage MLSImport/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $mlsimport;

// Step 1: which connection is this tab editing? (?mls=<registered id> wins,
// anything else resolves to the current connection.)
$mlsimport_field_scope = mlsimport_field_mapping_request_scope( isset( $_GET['mls'] ) ? wp_unslash( $_GET['mls'] ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector.
$mlsimport_connections = Mlsimport_Connections::all();
$mlsimport_scope_is_current = $mlsimport_field_scope === mlsimport_current_mls_id();

$metadata_populated = mlsimport_get_connection_option( 'mlsimport_mls_metadata_populated', '', $mlsimport_field_scope );
$token              = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();

// Step 2: no SaaS account token — nothing on this tab can work.
$mlsimport->admin->mlsimport_saas_setting_up();
if ( '' === trim( $token ) ) {
	// Names the reason (no subscription vs wrong password).
	echo mlsimport_account_not_connected_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the builder.
	return;
}

// Step 3: the MLS-connected gate, per scope.
if ( $mlsimport_scope_is_current ) {
	// Historic flow: refresh the global connection state before deciding.
	$is_mls_connected = get_option( 'mlsimport_connection_test', '' );
	if ( 'yes' !== $is_mls_connected ) {
		$mlsimport->admin->mlsimport_saas_check_mls_connection();
		$is_mls_connected = get_option( 'mlsimport_connection_test', '' );
	}
} else {
	// A non-current connection answers from its own record (#277 status).
	$mlsimport_scope_record = Mlsimport_Connections::get( $mlsimport_field_scope );
	$is_mls_connected       = is_array( $mlsimport_scope_record ) ? (string) ( $mlsimport_scope_record['status'] ?? '' ) : '';
}

if ( 'yes' !== $is_mls_connected ) {
	echo '<div class="mlsimport_warning">' . esc_html__( 'The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved.', 'mlsimport' ) . '</div>';
	return;
}

// Step 4: with 2+ connections the user picks which MLS's fields to edit.
if ( count( $mlsimport_connections ) > 1 ) {
	echo '<div class="mlsimport-field-scope">';
	echo '<label for="mlsimport-field-mls-scope">' . esc_html__( 'Editing fields for', 'mlsimport' ) . '</label> ';
	echo '<select id="mlsimport-field-mls-scope">';
	foreach ( $mlsimport_connections as $mlsimport_scope_option ) {
		$mlsimport_scope_id    = (int) $mlsimport_scope_option['mls_id'];
		$mlsimport_scope_label = '' !== $mlsimport_scope_option['mls_name']
			? $mlsimport_scope_option['mls_name']
			: __( 'MLS', 'mlsimport' ) . ' ' . $mlsimport_scope_id;
		printf(
			'<option value="%d"%s>%s</option>',
			esc_attr( $mlsimport_scope_id ),
			selected( $mlsimport_field_scope, $mlsimport_scope_id, false ),
			esc_html( $mlsimport_scope_label )
		);
	}
	echo '</select>';
	echo '</div>';
}

// Step 5: render the scoped configuration (or the gathering notice).
if ( 'yes' === $metadata_populated ) {
	$metadata      = mlsimport_field_configuration_metadata( $mlsimport_field_scope );
	$theme_schema  = mlsimport_hardocde_theme_schema();
	$configuration = mlsimport_field_configuration( $mlsimport_field_scope )->read( $metadata, $theme_schema, mlsimport_field_configuration_taxonomies() );

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
} else {
	echo '<div class="mlsimport_warning mlsimport_validated">' . esc_html__( 'We need to gather some information about your MLS. Please Stand By! ', 'mlsimport' ) . '</div>';
}
?>
<input type="hidden" id="mlsimport_field_scope" value="<?php echo esc_attr( (string) $mlsimport_field_scope ); ?>">
<input type="hidden" id="mlsimport_saas_get_metadata" value="<?php echo esc_attr( wp_create_nonce( 'mlsimport_saas_get_metadata' ) ); ?>">
