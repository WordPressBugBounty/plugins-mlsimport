<?php
/**
 * AI tools (MCP): the MLS catalogue and MLS Connections.
 *
 * Each function here is the body of one AI tool. A tool is a thin wrapper:
 * it reads its input, calls the plugin function that already does the work,
 * and returns that function's result.
 *   - list-available-mls reads the catalogue from mlsimport_saas_request_list()
 *     (the list the Add-MLS drawer's picker shows).
 *   - mls-credential-fields asks the Provider Family adapter, the same source
 *     the drawer uses to show its credential inputs.
 *   - add-mls-connection runs mlsimport_connections_add_execute() and then
 *     mlsimport_connections_seed_execute(), the two steps the drawer runs.
 *
 * Tools never return a credential value.
 *
 * Public interface:
 *   mlsimport_mcp_connection_tools()   the tools of this file
 *   mlsimport_mcp_mls_catalogue()                  catalogue as mls_id => name
 *   mlsimport_mcp_list_available_mls( $input )     tool: list-available-mls
 *   mlsimport_mcp_mls_credential_fields( $input )  tool: mls-credential-fields
 *   mlsimport_mcp_add_mls_connection( $input )     tool: add-mls-connection
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file (the MLS catalogue and MLS Connections), as the tool list expects them.
 *
 * Each entry: label, description, input, required, callback, changes. See
 * mlsimport_mcp_ability_definitions() in mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_connection_tools(): array {
	$text    = array( 'type' => 'string' );
	$integer = array( 'type' => 'integer' );

	return array(
		'list-available-mls' => array(
			'label'       => 'Find an MLS',
			'description' => 'Finds an MLS and its id by part of its name in the catalogue of supported MLSs. If several match, ask the client which one.',
			'input'       => array( 'search' => $text ),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_available_mls',
			'changes'     => false,
		),
		'mls-credential-fields' => array(
			'label'       => 'Credentials an MLS needs',
			'description' => 'Names the credentials an MLS needs, so you can ask the client for them. Needs the MLS id from list-available-mls.',
			'input'       => array( 'mls_id' => $integer ),
			'required'    => array( 'mls_id' ),
			'callback'    => 'mlsimport_mcp_mls_credential_fields',
			'changes'     => false,
		),
		'add-mls-connection' => array(
			'label'       => 'Add an MLS connection',
			'description' => 'Tests the MLS credentials, saves the MLS connection, then gathers the MLS fields and fills the field mapping with the theme defaults. Needs a connected account (account-connect), the MLS id (list-available-mls) and the credentials named by mls-credential-fields. On failure, repeat the reason to the client. Credential values are never returned.',
			'input'       => array(
				'mls_id'      => $integer,
				'credentials' => array(
					'type'                 => 'object',
					'description'          => 'Credential values keyed by the names from mls-credential-fields.',
					'additionalProperties' => array( 'type' => 'string' ),
				),
			),
			'required'    => array( 'mls_id', 'credentials' ),
			'callback'    => 'mlsimport_mcp_add_mls_connection',
			'changes'     => true,
		),
	);
}

/**
 * The MLS catalogue as mls_id => name.
 *
 * mlsimport_saas_request_list() answers with a JSON string of {label, value}
 * pairs on success, or an array carrying the error message under key '0'.
 * The "My MLS is not on this list" entry (value 0) is not an MLS and is dropped.
 *
 * @return array|WP_Error mls_id => name, or the plugin's own failure message.
 */
function mlsimport_mcp_mls_catalogue() {
	$list = mlsimport_saas_request_list();

	// Step 1: the failure shape is an array with the message under '0'.
	if ( is_array( $list ) ) {
		return new WP_Error( 'mlsimport_catalogue_unavailable', (string) ( $list['0'] ?? '' ) );
	}

	// Step 2: decode the {label, value} pairs into mls_id => name.
	$catalogue = array();
	foreach ( (array) json_decode( (string) $list, true ) as $pair ) {
		$mls_id = (int) ( $pair['value'] ?? 0 );
		if ( $mls_id > 0 ) {
			$catalogue[ $mls_id ] = (string) ( $pair['label'] ?? '' );
		}
	}
	return $catalogue;
}

/**
 * Tool list-available-mls: find an MLS and its id by name.
 *
 * @param array $input {
 *     @type string $search Part of the MLS name (any letter case).
 * }
 * @return array|WP_Error Up to 25 matches as {mls_id, name}.
 */
function mlsimport_mcp_list_available_mls( array $input ) {
	$catalogue = mlsimport_mcp_mls_catalogue();
	if ( is_wp_error( $catalogue ) ) {
		return $catalogue;
	}

	// Keep the entries whose name contains the search text.
	$search  = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
	$matches = array();
	foreach ( $catalogue as $mls_id => $name ) {
		if ( '' === $search || false !== stripos( $name, $search ) ) {
			$matches[] = array(
				'mls_id' => $mls_id,
				'name'   => $name,
			);
		}
	}

	return array(
		'total'   => count( $matches ),
		'matches' => array_slice( $matches, 0, 25 ),
	);
}

/**
 * Tool mls-credential-fields: which credentials an MLS needs.
 *
 * @param array $input {
 *     @type int $mls_id The MLS, from list-available-mls.
 * }
 * @return array|WP_Error The credential field names add-mls-connection expects.
 */
function mlsimport_mcp_mls_credential_fields( array $input ) {
	$mls_id  = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$adapter = Mlsimport_Provider_Family::adapter( '', $mls_id );

	// An MLS without a usable provider has no credential form.
	if ( $mls_id < 1 || ! $adapter->supported() ) {
		return new WP_Error(
			'mlsimport_mls_unsupported',
			__( 'That MLS is not supported. Find the MLS id with list-available-mls first.', 'mlsimport' )
		);
	}

	return array(
		'mls_id'            => $mls_id,
		'credential_fields' => $adapter->credential_fields(),
		'note'              => __( 'Ask the client for each value. They are provided by the MLS. Pass them to add-mls-connection under these exact names.', 'mlsimport' ),
	);
}

/**
 * Tool add-mls-connection: test and save an MLS Connection, then seed its mapping.
 *
 * @param array $input {
 *     @type int   $mls_id      The MLS, from list-available-mls.
 *     @type array $credentials Credential values keyed by the names from
 *                              mls-credential-fields.
 * }
 * @return array|WP_Error The saved connection and the seed outcome, or the
 *                        plugin's own reason for refusing.
 */
function mlsimport_mcp_add_mls_connection( array $input ) {
	$mls_id      = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$credentials = isset( $input['credentials'] ) && is_array( $input['credentials'] ) ? $input['credentials'] : array();

	// Step 1: the MLS name comes from the catalogue, never from the AI.
	$catalogue = mlsimport_mcp_mls_catalogue();
	if ( is_wp_error( $catalogue ) ) {
		return $catalogue;
	}
	if ( ! isset( $catalogue[ $mls_id ] ) ) {
		return new WP_Error(
			'mlsimport_mls_not_in_catalogue',
			__( 'That MLS id is not in the catalogue. Find it with list-available-mls first.', 'mlsimport' )
		);
	}

	// Step 2: the drawer's add flow: refuse / test / save. Credential values go
	// in verbatim; the flow keeps only the fields the provider declares.
	$add = mlsimport_connections_add_execute( $mls_id, $catalogue[ $mls_id ], $credentials );
	if ( ! $add['success'] ) {
		return new WP_Error( 'mlsimport_' . $add['code'], (string) $add['message'] );
	}

	// Step 3: the drawer's follow-up: pull the MLS fields and seed the mapping
	// from the theme defaults. A failed seed never undoes the connection.
	$seed = mlsimport_connections_seed_execute( $mls_id );

	// Step 4: report. No credential value is included.
	return array(
		'connected'      => true,
		'mls_id'         => $mls_id,
		'mls_name'       => $catalogue[ $mls_id ],
		'mapping_seeded' => (bool) $seed['seeded'],
		'note'           => $seed['seeded']
			? __( 'The MLS Connection is saved and its field mapping is filled with the theme defaults. Next: create-task.', 'mlsimport' )
			: __( 'The MLS Connection is saved, but its fields are not gathered yet. Opening MLSImport > Settings > Field Options in wp-admin gathers them.', 'mlsimport' ),
	);
}
