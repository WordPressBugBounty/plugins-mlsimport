<?php
/**
 * Add-MLS drawer — server side (issue #281, decision #271, spec #273).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Adding MLS #2+ happens ONLY on the Connections screen, through the right
 * slide-over drawer: pick MLS -> provider credentials -> connection test.
 * This file owns everything the drawer needs server-side:
 *
 *   - pure helpers (no WordPress): the refusal rule (no MLS picked / already
 *     registered / plan cap reached) and building the candidate registry
 *     record from the drawer's posted flat provider credentials,
 *   - the add flow core: test the posted credentials against the SaaS and,
 *     ONLY on a passed test, register the connection (at the lowest priority)
 *     and answer at once,
 *   - the single AJAX handler the drawer posts to.
 *
 * The field-mapping seed that follows a saved connection is its OWN request
 * (#325) and lives in includes/mlsimport-connections-seed.php.
 *
 * A failed test saves NOTHING — no registry record, no options — so the
 * drawer can stay open with the error and the user can retry. A saved
 * connection is always reported as saved, whatever happens to the seed.
 *
 * The drawer markup lives in admin/partials/mlsimport-connections-drawer.php
 * and its behavior in admin/js/mlsimport-connections-drawer.js.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decide whether an add request must be refused before any network call. Pure.
 *
 * Step by step:
 * 1. No MLS picked (id <= 0)            => 'no_mls'.
 * 2. Already a registered connection    => 'already_registered' (checked
 *    before the cap: "you already have this one" is the useful message even
 *    on a full plan).
 * 3. Every entitled slot used (>= cap)  => 'at_cap' — the drawer is
 *    unreachable at the cap in the UI, but the server enforces it too.
 * 4. Otherwise the add may proceed     => null.
 *
 * @param int   $mls_id         The MLS the user picked.
 * @param array $registered_ids mls_ids already in the registry.
 * @param int   $used           Number of registered connections.
 * @param int   $cap            The plan's connection cap (#276).
 * @return string|null Refusal code, or null to proceed.
 */
function mlsimport_connections_add_refusal( int $mls_id, array $registered_ids, int $used, int $cap ): ?string {
	// Step 1: an add without an identity is meaningless.
	if ( $mls_id <= 0 ) {
		return 'no_mls';
	}
	// Step 2: one record per MLS — the registry is keyed by mls_id.
	if ( in_array( $mls_id, array_map( 'intval', $registered_ids ), true ) ) {
		return 'already_registered';
	}
	// Step 3: the entitlement cap is a hard limit, UI and server alike.
	if ( $used >= $cap ) {
		return 'at_cap';
	}
	// Step 4: proceed.
	return null;
}

/**
 * Build the candidate registry record from the drawer's posted values. Pure
 * (uses only the migration module's suffix rule).
 *
 * Step by step:
 * 1. Map each flat adapter credential field to its generic record name
 *    (client_id, client_secret, username, password, mls_token) with the same
 *    suffix rule the #274 migration used — values verbatim apart from trim,
 *    credentials are never sanitized (#204).
 * 2. Assemble the record: status 'yes' because the caller only ever saves it
 *    AFTER a passed connection test, and priority 0 so the registry appends
 *    it at the lowest priority ("new connections join at the end", #271).
 *
 * @param int    $mls_id            The new connection's MLS id.
 * @param string $mls_name          Human-readable MLS name (autocomplete label).
 * @param string $provider_type     Resolved provider adapter type.
 * @param array  $flat_creds        Posted flat credential values, keyed by field.
 * @param array  $credential_fields Flat option keys the adapter declares.
 * @return array Registry record ready for Mlsimport_Connections::save().
 */
function mlsimport_connections_add_record( int $mls_id, string $mls_name, string $provider_type, array $flat_creds, array $credential_fields ): array {
	// Step 1: flat slot-names => generic names, trim-only values.
	$creds = array();
	foreach ( $credential_fields as $field ) {
		$generic = mlsimport_multimls_generic_credential_name( (string) $field );
		if ( '' !== $generic ) {
			$creds[ $generic ] = trim( (string) ( $flat_creds[ $field ] ?? '' ) );
		}
	}

	// Step 2: tested-OK record, appended at the lowest priority on save.
	return array(
		'mls_id'        => $mls_id,
		'mls_name'      => $mls_name,
		'provider_type' => $provider_type,
		'creds'         => $creds,
		'status'        => 'yes',
		'priority'      => 0,
	);
}

/**
 * Run the full add flow: refuse / test / register / seed. Never terminates.
 *
 * Step by step:
 * 1. Refuse without a network call: no MLS, already registered, at cap.
 * 2. Resolve the provider adapter for the picked MLS. A brand-new MLS has no
 *    saved type, so the Provider Family module's numeric compatibility map
 *    decides (same resolution the migration used); the SaaS's authoritative
 *    type can refresh the record later (#276).
 * 3. Whitelist + trim the posted credentials to exactly the adapter's
 *    declared fields.
 * 4. Test through the shared mlsimport_connections_patch_test() sequence
 *    (mlsimport-connections-ajax.php) — blank required fields refuse there
 *    before the network; otherwise the SaaS validates the credentials
 *    against the live MLS.
 * 5. A failed or not-entitled test returns the error and saves NOTHING.
 * 6. Passed: register the record (appends at the lowest priority), apply the
 *    echoed mls_data block when the SaaS sends one (#276 — re-applied HERE
 *    because the shared sequence ran before the record existed, when the
 *    echo guard had nothing to refresh), and stamp tested_at through the
 *    shared recorder (#277).
 * 7. Return success. The field-mapping seed is deliberately NOT part of this
 *    flow any more (#325): it is a second SaaS round trip (GET clients with
 *    the full metadata + enum payload) that, run after the save inside the
 *    same request, let a host time limit or a fatal end the request AFTER the
 *    record was written — the drawer then reported a failure for a connection
 *    that existed, and the retry hit 'already_registered'. The drawer now
 *    acknowledges the save from this response and asks for the seed
 *    separately (mlsimport_connections_seed_execute(), its own file).
 *
 * @param int    $mls_id       The MLS the user picked.
 * @param string $mls_name     Human-readable MLS name.
 * @param array  $posted_creds Posted flat credential values (unslashed).
 * @return array{success:bool, code:string, message:string}
 */
function mlsimport_connections_add_execute( int $mls_id, string $mls_name, array $posted_creds ): array {
	$refused = static function ( string $code, string $message ): array {
		return array(
			'success' => false,
			'code'    => $code,
			'message' => $message,
		);
	};

	// Step 1: cheap refusals first — nothing leaves the site.
	$refusal_messages = array(
		'no_mls'             => esc_html__( 'Pick your MLS from the list first.', 'mlsimport' ),
		'already_registered' => esc_html__( 'This MLS is already one of your connections.', 'mlsimport' ),
		'at_cap'             => esc_html__( 'All your plan\'s MLS connections are in use — upgrade your plan to add another.', 'mlsimport' ),
	);
	$refusal          = mlsimport_connections_add_refusal(
		$mls_id,
		array_keys( Mlsimport_Connections::all() ),
		count( Mlsimport_Connections::all() ),
		mlsimport_entitlement_cap()
	);
	if ( null !== $refusal ) {
		return $refused( $refusal, $refusal_messages[ $refusal ] );
	}

	// Step 2: resolve the adapter (no saved type yet => numeric map decides).
	$adapter = Mlsimport_Provider_Family::adapter( '', $mls_id );

	// Step 3: only declared fields enter, values trim-only (#204).
	$flat_creds = array();
	foreach ( $adapter->credential_fields() as $field ) {
		$flat_creds[ $field ] = trim( (string) ( $posted_creds[ $field ] ?? '' ) );
	}

	// Step 4: the shared record-scoped test sequence (payload guard + PATCH
	// + #276 contract). The record does not exist yet, so its echo-guarded
	// registry writes are no-ops here — the block is re-applied after save.
	$result = mlsimport_connections_patch_test( $adapter, $flat_creds, $mls_id );

	// Step 5: anything but a confirmed test saves nothing.
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

	// Step 6: the test passed — the connection becomes real now.
	$record = mlsimport_connections_add_record( $mls_id, $mls_name, $adapter->type(), $flat_creds, $adapter->credential_fields() );
	Mlsimport_Connections::save( $record );
	if ( isset( $answer['mls_data'] ) && is_array( $answer['mls_data'] ) ) {
		mlsimport_apply_client_block( $answer['mls_data'], $mls_id );
	}
	mlsimport_connection_record_test_result( $mls_id, true );

	// Step 6b: an install with no current connection yet (first MLS added
	// through the drawer — since the tab consolidation the drawer is the
	// settings page's only add path) promotes this connection to current by
	// mirroring its identity + credentials into the flat options, which every
	// legacy read path (imports, cron, token refresh) resolves current from.
	// The just-passed test also confirms the global connection flag.
	if ( 0 === mlsimport_current_mls_id() ) {
		$options = get_option( 'mlsimport_admin_options', array() );
		update_option(
			'mlsimport_admin_options',
			mlsimport_connections_flat_sync( is_array( $options ) ? $options : array(), $record, $flat_creds )
		);
		update_option( 'mlsimport_connection_test', 'yes' );
	}

	// Step 7: the connection is saved — say so now. Seeding is a separate
	// request (mlsimport-connections-seed.php).
	return array(
		'success' => true,
		'code'    => '',
		'message' => '',
	);
}

/**
 * AJAX: the Add-MLS drawer's single submit ("Test & save connection").
 *
 * Step by step:
 * 1. Nonce (the Connections screen nonce) + administrator capability.
 * 2. Read the posted MLS id, display name, and flat credential values —
 *    credential values are unslashed + trimmed ONLY, never sanitized (#204);
 *    the flow core whitelists them against the adapter's declared fields.
 * 3. Run the flow core and translate its outcome to the JSON response the
 *    drawer JS repaints from.
 *
 * @return void
 */
function mlsimport_ajax_connections_add() {
	// Step 1: security.
	check_ajax_referer( 'mlsimport_connections_screen', 'security' );
	if ( ! current_user_can( 'administrator' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}

	// Step 2: posted drawer values.
	$mls_id       = isset( $_POST['mls_id'] ) ? (int) $_POST['mls_id'] : 0;
	$mls_name     = isset( $_POST['mls_name'] ) ? sanitize_text_field( wp_unslash( $_POST['mls_name'] ) ) : '';
	$posted_creds = isset( $_POST['creds'] ) && is_array( $_POST['creds'] )
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- credential values must reach the SaaS verbatim (#204); the flow core whitelists the keys.
		? wp_unslash( $_POST['creds'] )
		: array();

	// Step 3: run the flow and answer the drawer.
	$outcome = mlsimport_connections_add_execute( $mls_id, $mls_name, $posted_creds );
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

add_action( 'wp_ajax_mlsimport_connections_add', 'mlsimport_ajax_connections_add' );
