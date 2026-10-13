<?php
/**
 * AI tools (MCP): managing the MLS Connections the site already has.
 *
 * Each function here is the body of one AI tool. A tool is a thin wrapper:
 * it reads its input, calls the plugin function the Connections screen
 * already uses, and returns that function's result.
 *   - list-mls-connections reads mlsimport_connections_screen_data(), the data
 *     the Connections table is drawn from.
 *   - get-mls-connection reads one record with Mlsimport_Connections::get().
 *   - edit-mls-connection-credentials runs mlsimport_connections_edit_execute()
 *     (the Edit drawer): test first, save only when the test passes.
 *   - test-mls-connection runs mlsimport_connections_run_test() (a row's Test).
 *   - reorder-mls-connections runs mlsimport_connections_reorder_execute()
 *     (the drag-reorder).
 *
 * Adding a connection lives in mlsimport-mcp-connections.php; removing one in
 * mlsimport-mcp-destructive.php.
 *
 * Tools never return a credential value: each credential is reported as
 * "set" or "not set", the way the Connections screen shows it.
 *
 * Public interface:
 *   mlsimport_mcp_connection_manage_tools()             the tools of this file
 *   mlsimport_mcp_connection_view( $record )            one connection, no secrets
 *   mlsimport_mcp_require_connection( $mls_id )         refusal for an unknown MLS
 *   mlsimport_mcp_list_mls_connections()                tool: list-mls-connections
 *   mlsimport_mcp_get_mls_connection( $input )          tool: get-mls-connection
 *   mlsimport_mcp_edit_mls_connection_credentials( $input )
 *   mlsimport_mcp_test_mls_connection( $input )         tool: test-mls-connection
 *   mlsimport_mcp_reorder_mls_connections( $input )     tool: reorder-mls-connections
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file, as the tool list expects them.
 *
 * Each entry: label, description, input, required, callback, changes. See
 * mlsimport_mcp_ability_definitions() in mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_connection_manage_tools(): array {
	$mls_id = array(
		'type'        => 'integer',
		'description' => 'The MLS id of one of the site\'s MLS Connections, from list-mls-connections.',
	);

	return array(
		'list-mls-connections'            => array(
			'label'       => 'List the MLS connections',
			'description' => 'Reads the MLS Connections of this site: name, id, status, last test, priority, how many import tasks and listings each has, and how many connections the plan allows.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_mls_connections',
			'changes'     => false,
		),
		'get-mls-connection'              => array(
			'label'       => 'One MLS connection',
			'description' => 'Reads one MLS Connection. Each credential is reported only as "set" or "not set", never its value.',
			'input'       => array( 'mls_id' => $mls_id ),
			'required'    => array( 'mls_id' ),
			'callback'    => 'mlsimport_mcp_get_mls_connection',
			'changes'     => false,
		),
		'edit-mls-connection-credentials' => array(
			'label'       => 'Change the credentials of an MLS connection',
			'description' => 'Tests new credentials for an existing MLS Connection and saves them only when the test passes; a failed test changes nothing. Pass every credential named by mls-credential-fields. Credential values are never returned.',
			'input'       => array(
				'mls_id'      => $mls_id,
				'credentials' => array(
					'type'                 => 'object',
					'description'          => 'Credential values keyed by the names from mls-credential-fields.',
					'additionalProperties' => array( 'type' => 'string' ),
				),
			),
			'required'    => array( 'mls_id', 'credentials' ),
			'callback'    => 'mlsimport_mcp_edit_mls_connection_credentials',
			'changes'     => true,
		),
		'test-mls-connection'             => array(
			'label'       => 'Test an MLS connection',
			'description' => 'Tests the saved credentials of one MLS Connection against the MLS and records the result on the connection.',
			'input'       => array( 'mls_id' => $mls_id ),
			'required'    => array( 'mls_id' ),
			'callback'    => 'mlsimport_mcp_test_mls_connection',
			'changes'     => true,
		),
		'reorder-mls-connections'         => array(
			'label'       => 'Change the priority of the MLS connections',
			'description' => 'Sets the priority order of the MLS Connections (first = highest). When two MLSs send the same property, the copy from the higher priority MLS is the one shown. The order must name every MLS Connection of the site exactly once.',
			'input'       => array(
				'order' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Every MLS id of the site, highest priority first.',
				),
			),
			'required'    => array( 'order' ),
			'callback'    => 'mlsimport_mcp_reorder_mls_connections',
			'changes'     => true,
		),
	);
}

/**
 * One MLS Connection as a tool reports it. Never contains a credential value.
 *
 * @param array $record Registry record (may carry 'status_view' and 'activity'
 *                      from mlsimport_connections_screen_data()).
 * @return array The connection: identity, status, last test, priority, activity.
 */
function mlsimport_mcp_connection_view( array $record ): array {
	// The status words are the Connections screen's own (one shared map).
	$status = $record['status_view'] ?? mlsimport_connections_row_status( $record );

	$view = array(
		'mls_id'      => (int) $record['mls_id'],
		'mls_name'    => (string) $record['mls_name'],
		'provider'    => (string) $record['provider_type'],
		'priority'    => (int) $record['priority'],
		'status'      => $status['key'],
		'status_text' => mlsimport_connections_status_label( $status['key'] ),
		'last_tested' => (int) $record['tested_at'] > 0 ? gmdate( 'c', (int) $record['tested_at'] ) : '',
	);

	// Activity counts exist only on rows that came from the screen data.
	if ( isset( $record['activity'] ) ) {
		$view['tasks']       = (int) $record['activity']['tasks'];
		$view['listings']    = (int) $record['activity']['listings'];
		$view['last_import'] = (string) $record['activity']['last_import'];
	}
	return $view;
}

/**
 * Refuse an MLS id that is not one of the site's MLS Connections.
 *
 * @param int $mls_id MLS id from the tool input.
 * @return WP_Error|null The refusal (it names the next step), or null.
 */
function mlsimport_mcp_require_connection( int $mls_id ) {
	if ( null !== Mlsimport_Connections::get( $mls_id ) ) {
		return null;
	}
	return new WP_Error(
		'mlsimport_not_a_connection',
		__( 'That MLS is not one of this site\'s MLS Connections. See them with list-mls-connections.', 'mlsimport' )
	);
}

/**
 * Tool list-mls-connections: every MLS Connection with status and activity.
 *
 * @return array The connections in priority order, plus the plan slots.
 */
function mlsimport_mcp_list_mls_connections(): array {
	// The same data the Connections table is drawn from.
	$screen = mlsimport_connections_screen_data();

	return array(
		'connections' => array_values( array_map( 'mlsimport_mcp_connection_view', $screen['rows'] ) ),
		'used'        => (int) $screen['used'],
		'cap'         => (int) $screen['cap'],
		'at_cap'      => (bool) $screen['at_cap'],
	);
}

/**
 * Tool get-mls-connection: one MLS Connection, credentials masked.
 *
 * @param array $input {
 *     @type int $mls_id The MLS Connection to read.
 * }
 * @return array|WP_Error The connection and, per credential, "set" / "not set".
 */
function mlsimport_mcp_get_mls_connection( array $input ) {
	$mls_id  = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$refusal = mlsimport_mcp_require_connection( $mls_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 1: the record, without its credentials.
	$record = Mlsimport_Connections::get( $mls_id );
	$view   = mlsimport_mcp_connection_view( $record );

	// Step 2: each credential the provider declares, as set / not set only.
	$adapter             = Mlsimport_Provider_Family::adapter( (string) $record['provider_type'], (string) $mls_id );
	$view['credentials'] = array();
	foreach ( mlsimport_connections_credential_options( $record, $adapter->credential_fields() ) as $field => $value ) {
		$view['credentials'][ $field ] = '' === $value ? 'not set' : 'set';
	}
	return $view;
}

/**
 * Tool edit-mls-connection-credentials: test new credentials, save on success.
 *
 * @param array $input {
 *     @type int   $mls_id      The MLS Connection to change.
 *     @type array $credentials Credential values keyed by the names from
 *                              mls-credential-fields.
 * }
 * @return array|WP_Error "saved", or the plugin's own reason (nothing saved).
 */
function mlsimport_mcp_edit_mls_connection_credentials( array $input ) {
	$mls_id      = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$credentials = isset( $input['credentials'] ) && is_array( $input['credentials'] ) ? $input['credentials'] : array();

	// The Edit drawer's flow: refuse / test / save. Values go in verbatim.
	$outcome = mlsimport_connections_edit_execute( $mls_id, $credentials );
	if ( ! $outcome['success'] ) {
		return new WP_Error( 'mlsimport_' . $outcome['code'], (string) $outcome['message'] );
	}
	return array(
		'saved'  => true,
		'mls_id' => $mls_id,
	);
}

/**
 * Tool test-mls-connection: test the saved credentials of one MLS Connection.
 *
 * @param array $input {
 *     @type int $mls_id The MLS Connection to test.
 * }
 * @return array|WP_Error The connection with its fresh status and test time.
 */
function mlsimport_mcp_test_mls_connection( array $input ) {
	// A row's Test action; null means the id is not a connection.
	$record = mlsimport_connections_run_test( isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0 );
	if ( null === $record ) {
		return mlsimport_mcp_require_connection( 0 );
	}
	return mlsimport_mcp_connection_view( $record );
}

/**
 * Tool reorder-mls-connections: set the priority order.
 *
 * @param array $input {
 *     @type int[] $order Every MLS id of the site, highest priority first.
 * }
 * @return array|WP_Error The connections in their new order, or the refusal.
 */
function mlsimport_mcp_reorder_mls_connections( array $input ) {
	$order = isset( $input['order'] ) && is_array( $input['order'] ) ? $input['order'] : array();

	// The drag-reorder's sequence: validate, save, re-decide duplicates.
	if ( ! mlsimport_connections_reorder_execute( $order ) ) {
		return new WP_Error(
			'mlsimport_invalid_order',
			sprintf(
				/* translators: %s: list of MLS Connections as "name (id)". */
				__( 'The order must name every MLS Connection exactly once. The MLS Connections are: %s.', 'mlsimport' ),
				mlsimport_mcp_connection_labels( Mlsimport_Connections::all() )
			)
		);
	}
	return array(
		'connections' => array_values( array_map( 'mlsimport_mcp_connection_view', Mlsimport_Connections::all() ) ),
	);
}
