<?php
/**
 * Edit-connection drawer — server side (tab consolidation).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The old "MLS Connection" settings tab (the flat credentials form) was
 * retired: the Connections tab is the single connection surface, and editing
 * a connection's credentials happens in the same slide-over drawer the Add
 * flow uses (includes/mlsimport-connections-add.php), opened in edit mode
 * from a row's Edit action. This file owns everything edit needs server-side:
 *
 *   - pure helpers (no WordPress): the refusal rule (no id / not a registered
 *     connection) and rebuilding a record's credentials from the drawer's
 *     posted flat values,
 *   - the flow core: test the posted credentials against the SaaS and, ONLY
 *     on a passed test, store them — in the registry record always, and ALSO
 *     in the flat mlsimport_admin_options when the edited connection is the
 *     CURRENT one (the plugin reads the current connection's credentials from
 *     the flat options; that was the old tab's job),
 *   - the single AJAX handler the drawer posts to in edit mode.
 *
 * A failed test saves NOTHING — record and flat options keep the previous
 * credentials, the drawer stays open with the error.
 *
 * @since   7.3.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decide whether an edit request must be refused before any network call. Pure.
 *
 * Step by step:
 * 1. No connection identified (id <= 0)   => 'no_mls'.
 * 2. The id is not a registered connection => 'not_registered' (edit never
 *    creates — creating is the Add flow's job, with its own cap rule).
 * 3. Otherwise the edit may proceed       => null.
 *
 * @param int   $mls_id         The connection being edited.
 * @param array $registered_ids mls_ids already in the registry.
 * @return string|null Refusal code, or null to proceed.
 */
function mlsimport_connections_edit_refusal( int $mls_id, array $registered_ids ): ?string {
	// Step 1: an edit without an identity is meaningless.
	if ( $mls_id <= 0 ) {
		return 'no_mls';
	}
	// Step 2: only existing connections can be edited.
	if ( ! in_array( $mls_id, array_map( 'intval', $registered_ids ), true ) ) {
		return 'not_registered';
	}
	// Step 3: proceed.
	return null;
}

/**
 * Rebuild a registry record's credentials from the drawer's posted values.
 * Pure (uses only the migration module's suffix rule).
 *
 * Step by step:
 * 1. Map each flat adapter credential field to its generic record name
 *    (client_id, client_secret, username, password, mls_token) with the same
 *    suffix rule the migration used — values verbatim apart from trim,
 *    credentials are never sanitized (#204). The adapter's declared fields
 *    are the WHOLE credential set: an unposted field empties rather than
 *    keeping a stale old value.
 * 2. Everything else — identity, display name, provider type, priority,
 *    live_config — survives untouched: an edit changes credentials only.
 * 3. Status becomes 'yes' because the caller only ever saves the rebuilt
 *    record AFTER a passed connection test.
 *
 * @param array $record            The existing registry record.
 * @param array $flat_creds        Posted flat credential values, keyed by field.
 * @param array $credential_fields Flat option keys the adapter declares.
 * @return array Updated record ready for Mlsimport_Connections::save().
 */
function mlsimport_connections_edit_record( array $record, array $flat_creds, array $credential_fields ): array {
	// Step 1: flat slot-names => generic names, trim-only values, full replace.
	$creds = array();
	foreach ( $credential_fields as $field ) {
		$generic = mlsimport_multimls_generic_credential_name( (string) $field );
		if ( '' !== $generic ) {
			$creds[ $generic ] = trim( (string) ( $flat_creds[ $field ] ?? '' ) );
		}
	}

	// Step 2+3: same record, new creds, tested-OK status.
	$record['creds']  = $creds;
	$record['status'] = 'yes';
	return $record;
}

/**
 * Merge one connection's identity + credentials into the flat options. Pure.
 *
 * The CURRENT connection's identity and credentials live in the flat
 * mlsimport_admin_options — every legacy read path (imports, cron, token
 * refresh) resolves the current connection from there. Whenever a drawer save
 * concerns the current connection (an edit of it, or the first add on an
 * install with no current connection yet), the caller passes the flat options
 * through here so both stores tell the same story.
 *
 * Step by step:
 * 1. Identity: 'mlsimport_mls_name' takes the record's numeric id (as the
 *    string the option has always stored), 'mlsimport_mls_name_front' its
 *    display name.
 * 2. Credentials: each posted flat field lands under its own flat key —
 *    trimmed ONLY, never sanitized (#204).
 * 3. Every other key (account login, theme choice, other providers' stored
 *    credentials) survives untouched.
 *
 * @param array $flat_options The current mlsimport_admin_options array.
 * @param array $record       Registry record supplying mls_id and mls_name.
 * @param array $flat_creds   Posted flat credential values, keyed by field.
 * @return array Updated flat options, ready for update_option().
 */
function mlsimport_connections_flat_sync( array $flat_options, array $record, array $flat_creds ): array {
	// Step 1: identity follows the record.
	$flat_options['mlsimport_mls_name']       = (string) (int) ( $record['mls_id'] ?? 0 );
	$flat_options['mlsimport_mls_name_front'] = (string) ( $record['mls_name'] ?? '' );

	// Step 2: credentials land verbatim (trim only) under their flat keys.
	foreach ( $flat_creds as $field => $value ) {
		$flat_options[ (string) $field ] = trim( (string) $value );
	}

	// Step 3: nothing else is touched.
	return $flat_options;
}

/**
 * Run the full edit flow: refuse / test / store. Never terminates.
 *
 * Step by step:
 * 1. Refuse without a network call: no id, or not a registered connection.
 * 2. Resolve the provider adapter from the record's stored type and whitelist
 *    + trim the posted credentials to exactly the adapter's declared fields.
 * 3. Test through the shared mlsimport_connections_patch_test() sequence
 *    (mlsimport-connections-ajax.php) — blank required fields refuse there
 *    before the network; otherwise the SaaS validates the credentials
 *    against the live MLS.
 * 4. A failed or not-entitled test returns the error and stores NOTHING —
 *    the record and the flat options keep the previous credentials.
 * 5. Passed: save the rebuilt record (credentials replaced, everything else
 *    kept) and stamp tested_at through the shared recorder (#277).
 * 6. When the edited connection is the CURRENT one, mirror the new identity
 *    + credentials into the flat mlsimport_admin_options (the legacy read
 *    paths — imports, cron, token refresh — resolve the current connection
 *    from there), drop the provider access tokens so the next MLS request
 *    re-authenticates with the new credentials, and confirm the global
 *    connection flag (the test just passed). Metadata is left alone: the MLS
 *    identity did not change, so its gathered fields are still valid.
 *
 * @param int   $mls_id       The connection being edited.
 * @param array $posted_creds Posted flat credential values (unslashed).
 * @return array{success:bool, code:string, message:string}
 */
function mlsimport_connections_edit_execute( int $mls_id, array $posted_creds ): array {
	$refused = static function ( string $code, string $message ): array {
		return array(
			'success' => false,
			'code'    => $code,
			'message' => $message,
		);
	};

	// Step 1: cheap refusals first — nothing leaves the site.
	$refusal_messages = array(
		'no_mls'         => esc_html__( 'No connection selected.', 'mlsimport' ),
		'not_registered' => esc_html__( 'This MLS is not one of your connections.', 'mlsimport' ),
	);
	$refusal          = mlsimport_connections_edit_refusal( $mls_id, array_keys( Mlsimport_Connections::all() ) );
	if ( null !== $refusal ) {
		return $refused( $refusal, $refusal_messages[ $refusal ] );
	}

	// Step 2: adapter from the record's stored type; declared fields only.
	$record     = Mlsimport_Connections::get( $mls_id );
	$adapter    = Mlsimport_Provider_Family::adapter( (string) $record['provider_type'], (string) $mls_id );
	$flat_creds = array();
	foreach ( $adapter->credential_fields() as $field ) {
		$flat_creds[ $field ] = trim( (string) ( $posted_creds[ $field ] ?? '' ) );
	}

	// Step 3: the shared record-scoped test sequence (payload guard + PATCH
	// + #276 contract).
	$result = mlsimport_connections_patch_test( $adapter, $flat_creds, $mls_id );

	// Step 4: anything but a confirmed test stores nothing.
	if ( $result['refused'] ) {
		return 'missing_credentials' === ( $result['error']['code'] ?? '' )
			? $refused( 'missing_credentials', esc_html__( 'Please fill in every credential field for this MLS.', 'mlsimport' ) )
			: $refused( 'unsupported', (string) ( $result['error']['message'] ?? '' ) );
	}
	$answer = $result['answer'];
	if ( mlsimport_response_not_entitled( $answer ) ) {
		return $refused( 'not_entitled', esc_html__( 'Your mlsimport.com plan does not include this MLS — upgrade your plan or contact support.', 'mlsimport' ) );
	}
	if ( ! $result['tested_ok'] ) {
		$api_message = is_array( $answer ) ? trim( (string) ( $answer['error_message'] ?? '' ) ) : '';
		return $refused(
			'test_failed',
			'' !== $api_message
				? $api_message
				: esc_html__( 'The connection test failed. Please check the credentials your MLS provided and try again.', 'mlsimport' )
		);
	}

	// Step 5: the test passed — the new credentials become real now.
	Mlsimport_Connections::save(
		mlsimport_connections_edit_record( $record, $flat_creds, $adapter->credential_fields() )
	);
	mlsimport_connection_record_test_result( $mls_id, true );

	// Step 6: current-connection mirror into the flat options.
	if ( $mls_id === mlsimport_current_mls_id() ) {
		$options = get_option( 'mlsimport_admin_options', array() );
		update_option(
			'mlsimport_admin_options',
			mlsimport_connections_flat_sync( is_array( $options ) ? $options : array(), $record, $flat_creds )
		);
		Mlsimport_Provider_Family::clear_access_tokens();
		update_option( 'mlsimport_connection_test', 'yes' );
	}

	return array(
		'success' => true,
		'code'    => '',
		'message' => '',
	);
}

/**
 * AJAX: the drawer's edit-mode submit ("Test & save credentials").
 *
 * Step by step:
 * 1. Nonce (the Connections screen nonce) + administrator capability.
 * 2. Read the posted MLS id and flat credential values — credential values
 *    are unslashed + trimmed ONLY, never sanitized (#204); the flow core
 *    whitelists them against the adapter's declared fields.
 * 3. Run the flow core and translate its outcome to the JSON response the
 *    drawer JS repaints from.
 *
 * @return void
 */
function mlsimport_ajax_connections_edit() {
	// Step 1: security.
	check_ajax_referer( 'mlsimport_connections_screen', 'security' );
	if ( ! current_user_can( 'administrator' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}

	// Step 2: posted drawer values.
	$mls_id       = isset( $_POST['mls_id'] ) ? (int) $_POST['mls_id'] : 0;
	$posted_creds = isset( $_POST['creds'] ) && is_array( $_POST['creds'] )
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- credential values must reach the SaaS verbatim (#204); the flow core whitelists the keys.
		? wp_unslash( $_POST['creds'] )
		: array();

	// Step 3: run the flow and answer the drawer.
	$outcome = mlsimport_connections_edit_execute( $mls_id, $posted_creds );
	if ( ! $outcome['success'] ) {
		wp_send_json_error(
			array(
				'code'    => $outcome['code'],
				'message' => $outcome['message'],
			)
		);
	}
	wp_send_json_success( array( 'mls_id' => $mls_id ) );
}

add_action( 'wp_ajax_mlsimport_connections_edit', 'mlsimport_ajax_connections_edit' );
