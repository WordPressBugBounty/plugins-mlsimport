<?php
/**
 * Connections screen — AJAX handlers (issue #280, decision #271, spec #273).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The Connections tab has three state-changing actions, all admin-only and
 * all nonce-checked against 'mlsimport_connections_screen':
 *
 *   - reorder:    persist a drag-reorder as registry priorities 1..N
 *                 (priority drives the dedupe winner, decision #267),
 *   - test:       run the SaaS connection test for ONE connection and mirror
 *                 the outcome (status + tested_at) into its registry record,
 *   - disconnect: sign the install out of the mlsimport.com account (drop the
 *                 cached token AND the saved password — the token getter
 *                 re-authenticates from saved credentials, so a kept password
 *                 would silently sign back in on the next page load — and
 *                 the account's cached connection cap, re-read on sign-in).
 *
 * The inline connect form on the same tab needs no handler here: it posts to
 * the existing 'mlsimport_save_account' action (onboarding module), which
 * already saves credentials and verifies the login.
 *
 * This file also owns mlsimport_connections_patch_test() — the single
 * record-scoped credential-test sequence shared by the per-row Test below
 * and the Add-MLS drawer flow (#281, mlsimport-connections-add.php).
 *
 * Pure helpers + screen data live in mlsimport-connections-screen.php.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PATCH one set of MLS credentials to the SaaS and apply the #276 contract.
 *
 * The single record-scoped test sequence, shared by the per-row Test (#280,
 * below) and the Add-MLS drawer flow (#281,
 * includes/mlsimport-connections-add.php). It deliberately has NO global
 * side effects — the global connection flags belong to the CURRENT
 * connection's legacy test, mlsimport_saas_check_mls_connection() (admin
 * class), which mirrors this same PATCH + contract sequence; a contract
 * change must land in that method and here.
 *
 * Step by step:
 * 1. Build the payload from the adapter's declared flat credential fields —
 *    an unsupported provider or blank required fields refuse here, BEFORE
 *    any network call.
 * 2. PATCH 'clients': the SaaS validates the credentials against the live MLS.
 * 3. Apply the echoed mls_data block to exactly this connection's record
 *    (echo-guarded; a no-op when the record is not registered yet), and mark
 *    a stable not_entitled rejection on this record only (also a no-op when
 *    unregistered — the caller still sees the rejection in 'answer').
 *
 * @param object $adapter          Provider Family adapter for this MLS.
 * @param array  $flat_credentials Flat slot-named credential values.
 * @param int    $mls_id           The MLS being tested.
 * @return array{refused:bool, error:?array, answer:mixed, tested_ok:bool}
 *         refused = stopped before the network (error says why);
 *         otherwise answer is the SaaS response and tested_ok its verdict.
 */
function mlsimport_connections_patch_test( $adapter, array $flat_credentials, int $mls_id ): array {
	// Step 1: refuse unsupported providers / missing credentials pre-network.
	$payload_result = $adapter->supported()
		? $adapter->connection_test_payload( $flat_credentials, $mls_id )
		: array( 'success' => false, 'error' => $adapter->error() );
	if ( ! $payload_result['success'] ) {
		return array(
			'refused'   => true,
			'error'     => $payload_result['error'],
			'answer'    => null,
			'tested_ok' => false,
		);
	}

	// Step 2: the SaaS validates the credentials against the live MLS.
	$answer = ThemeImport::globalApiRequestSaas( 'clients', $payload_result['payload'], 'PATCH' );

	// Step 3: #276 contract — echoed config to this record, stable rejection
	// marked on this record only.
	if ( isset( $answer['mls_data'] ) && is_array( $answer['mls_data'] ) ) {
		mlsimport_apply_client_block( $answer['mls_data'], $mls_id );
	}
	if ( mlsimport_response_not_entitled( $answer ) ) {
		mlsimport_mark_connection_not_entitled( $mls_id );
	}

	return array(
		'refused'   => false,
		'error'     => null,
		'answer'    => $answer,
		'tested_ok' => true === ( $answer['success'] ?? false ) && true === ( $answer['tested'] ?? false ),
	);
}

/**
 * Run the SaaS connection test for one registered connection.
 *
 * Step by step:
 * 1. Unregistered id => null (nothing to test, nothing to record).
 * 2. The CURRENT connection uses the full legacy test in the admin class —
 *    it owns the global side effects (mlsimport_connection_test flag,
 *    metadata re-arm) and already mirrors the outcome into the record (#277).
 * 3. Any OTHER connection is tested from its own record through the shared
 *    mlsimport_connections_patch_test() sequence: the flat option keys its
 *    provider adapter declares are synthesized from the record's generic
 *    creds, and the outcome is recorded. The global flags are deliberately
 *    NOT touched — they belong to the current connection only.
 * 4. Return the fresh record so the caller sees the new status + tested_at.
 *
 * @param int $mls_id The connection to test.
 * @return array|null Fresh registry record, or null when not registered.
 */
function mlsimport_connections_run_test( int $mls_id ): ?array {
	global $mlsimport;

	// Step 1: only registered connections can be tested from this screen.
	$record = Mlsimport_Connections::get( $mls_id );
	if ( null === $record ) {
		return null;
	}

	if ( $mls_id === mlsimport_current_mls_id() ) {
		// Step 2: the legacy full test (global flags + record mirror).
		$mlsimport->admin->mlsimport_saas_check_mls_connection();
	} else {
		// Step 3: record-scoped test for a non-current connection.
		$adapter = Mlsimport_Provider_Family::adapter( $record['provider_type'], (string) $mls_id );
		$result  = mlsimport_connections_patch_test(
			$adapter,
			mlsimport_connections_credential_options( $record, $adapter->credential_fields() ),
			$mls_id
		);

		if ( $result['refused'] ) {
			// Keep the refusal reason discoverable (unsupported provider type
			// vs missing credentials need different remedies).
			error_log(
				'MLSImport connection test refused for connection ' . $mls_id . ': '
				. ( $result['error']['code'] ?? 'unknown' )
			);
		}

		mlsimport_connection_record_test_result( $mls_id, $result['tested_ok'] );
	}

	// Step 4: re-read — the recorder above rewrote status and tested_at.
	return Mlsimport_Connections::get( $mls_id );
}

/**
 * AJAX: persist a drag-reorder as registry priorities.
 *
 * Step by step:
 * 1. Nonce + administrator capability.
 * 2. Validate + apply the posted id order through the pure helper — an order
 *    that does not name every registered connection exactly once is refused.
 * 3. Persist each record's new priority through the registry accessor.
 *
 * @return void
 */
function mlsimport_ajax_connections_reorder() {
	// Step 1: security.
	check_ajax_referer( 'mlsimport_connections_screen', 'security' );
	if ( ! current_user_can( 'administrator' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}

	// Step 2: validate + apply (ids are cast to int inside the helper).
	$order   = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : array();
	$updated = mlsimport_connections_apply_order( $order, Mlsimport_Connections::all() );
	if ( null === $updated ) {
		wp_send_json_error( array( 'message' => 'Invalid connection order' ) );
	}

	// Step 3: persist.
	foreach ( $updated as $record ) {
		Mlsimport_Connections::save( $record );
	}

	// Step 4: priority drives the dedupe winner (#267/#282) — re-decide every
	// currently flagged duplicate group under the new order, deterministically.
	mlsimport_dedupe_reevaluate_flagged();
	wp_send_json_success();
}

/**
 * AJAX: per-row connection test.
 *
 * Runs the test for the posted mls_id and returns the row's fresh status
 * view (pill key/class/failing + translated label + human last-test time)
 * so the JS can update the pill and banner without a reload.
 *
 * @return void
 */
function mlsimport_ajax_connections_test() {
	check_ajax_referer( 'mlsimport_connections_screen', 'security' );
	if ( ! current_user_can( 'administrator' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}

	$mls_id = isset( $_POST['mls_id'] ) ? (int) $_POST['mls_id'] : 0;
	$record = mlsimport_connections_run_test( $mls_id );
	if ( null === $record ) {
		wp_send_json_error( array( 'message' => 'Unknown connection' ) );
	}

	$status = mlsimport_connections_row_status( $record );
	wp_send_json_success(
		array(
			'status'  => $status,
			'label'   => mlsimport_connections_status_label( $status['key'] ),
			'tested'  => $record['tested_at'] > 0
				/* translators: %s: human time difference since the last connection test. */
				? sprintf( __( 'tested %s ago', 'mlsimport' ), human_time_diff( $record['tested_at'] ) )
				: __( 'never tested', 'mlsimport' ),
		)
	);
}

/**
 * AJAX: disconnect the install from the mlsimport.com account.
 *
 * Step by step:
 * 1. Nonce + administrator capability.
 * 2. Remove the saved account password — with it present, the token getter
 *    would just re-authenticate and undo the disconnect on the next load.
 *    The username is kept so reconnecting only asks for the password.
 * 3. Drop the cached token + its expiry stamp so the session ends now.
 * 4. Forget the account's connection cap — it belongs to the account that
 *    was just signed out. Until the next sign-in re-reads it the screen
 *    shows the legacy single-MLS cap.
 *
 * @return void
 */
function mlsimport_ajax_connections_disconnect() {
	// Step 1: security.
	check_ajax_referer( 'mlsimport_connections_screen', 'security' );
	if ( ! current_user_can( 'administrator' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}

	// Step 2: forget the password (credentials are never sanitized — this
	// only removes the key).
	$options = get_option( 'mlsimport_admin_options', array() );
	if ( is_array( $options ) ) {
		unset( $options['mlsimport_password'] );
		update_option( 'mlsimport_admin_options', $options );
	}

	// Step 3: end the current session.
	delete_transient( 'mlsimport_saas_token' );
	delete_option( 'mlsimport_token_expiry' );

	// Step 4: the cap was this account's grant — forget it with the account.
	delete_option( 'mlsimport_entitlement_cap' );

	wp_send_json_success();
}

add_action( 'wp_ajax_mlsimport_connections_reorder', 'mlsimport_ajax_connections_reorder' );
add_action( 'wp_ajax_mlsimport_connections_test', 'mlsimport_ajax_connections_test' );
add_action( 'wp_ajax_mlsimport_connections_disconnect', 'mlsimport_ajax_connections_disconnect' );
