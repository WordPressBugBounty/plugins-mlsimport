<?php
/**
 * Import task connection binding + cron isolation (issue #277, spec #273/#265).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * With multi-MLS support every Import Task (mlsimport_item) belongs to exactly
 * ONE connection for its whole life, stamped at creation in the post meta
 * 'mlsimport_item_mls_id' (decision #265 — a task's filters are enum values
 * from one specific MLS, so re-binding would produce garbage by construction;
 * targeting another MLS means creating a new task).
 *
 * This module owns everything about that binding:
 *  - resolving a task's mls_id (with the legacy current-connection fallback
 *    for unstamped tasks, so pre-multi-MLS installs behave exactly as before),
 *  - stamping the binding once at creation (picker choice, single-connection
 *    auto-set, or current-connection fallback),
 *  - the per-task connection gate the hourly cron uses for failure isolation:
 *    tasks on a broken connection skip WITH a recorded reason while tasks on
 *    healthy connections keep importing in the same run,
 *  - the per-connection sync-failure record (telemetry scoped by mls_id),
 *  - mirroring the connection-test result into the registry record's status,
 *    which is what the gate reads for non-current connections.
 *
 * The task-scoped Field Configuration read lives where the projection cache
 * already lives: mlsimport_active_field_configuration( $refresh, $mls_id ).
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the mls_id an Import Task is bound to.
 *
 * Step by step:
 * 1. A stamped task returns its own binding — never the global selection.
 * 2. An unstamped task (created before multi-MLS, or programmatically) falls
 *    back to the current connection, which is exactly what such a task has
 *    always imported from. The #274 migration stamps existing tasks, so this
 *    fallback only carries genuinely legacy cases.
 *
 * @param int $task_id Import Task post id.
 * @return int The bound mls_id, or 0 when the install is unconfigured.
 */
function mlsimport_task_mls_id( int $task_id ): int {
	// Step 1: the task's own stamp wins.
	$stamped = (int) get_post_meta( $task_id, 'mlsimport_item_mls_id', true );
	if ( $stamped > 0 ) {
		return $stamped;
	}

	// Step 2: legacy fallback — the current connection.
	return mlsimport_current_mls_id();
}

/**
 * Bind a task to one connection, exactly once (immutable afterwards).
 *
 * Step by step:
 * 1. A task that is already bound keeps its binding — the posted value is
 *    ignored, so no form manipulation can re-bind a task.
 * 2. A requested id is honored only when it names a REGISTERED connection
 *    (the metabox picker only offers registered ones).
 * 3. Without a valid request: exactly one registered connection auto-binds
 *    (no picker was shown); otherwise the current connection is stamped —
 *    the pre-#271 case where the registry does not mirror the configured MLS.
 * 4. Stamp only a positive id; an unconfigured install stays unstamped and
 *    resolves through the fallback in mlsimport_task_mls_id() until an MLS
 *    is configured.
 *
 * @param int $task_id          Import Task post id.
 * @param int $requested_mls_id Connection chosen in the metabox picker (0 = none).
 * @return int The task's binding after the call (0 only when unconfigured).
 */
function mlsimport_bind_task_connection( int $task_id, int $requested_mls_id = 0 ): int {
	// Step 1: bound is bound — for life.
	$existing = (int) get_post_meta( $task_id, 'mlsimport_item_mls_id', true );
	if ( $existing > 0 ) {
		return $existing;
	}

	// Step 2: a picker choice must be a registered connection.
	$connections = Mlsimport_Connections::all();
	if ( $requested_mls_id > 0 && isset( $connections[ $requested_mls_id ] ) ) {
		$mls_id = $requested_mls_id;
	} elseif ( 1 === count( $connections ) ) {
		// Step 3a: exactly one connection — auto-set, no picker existed.
		$mls_id = (int) array_key_first( $connections );
	} else {
		// Step 3b: no usable request — the current connection.
		$mls_id = mlsimport_current_mls_id();
	}

	// Step 4: persist only a real binding.
	if ( $mls_id > 0 ) {
		update_post_meta( $task_id, 'mlsimport_item_mls_id', $mls_id );
	}

	return $mls_id;
}

/**
 * Decide, for ONE task's connection, whether the hourly cron may import it.
 *
 * Pure function (no WordPress reads) so the isolation rule is unit-testable.
 *
 * The rule, one case per connection kind:
 * - The CURRENT connection keeps today's exact behavior: the global
 *   'mlsimport_connection_test' flag decides (the admin screens maintain it,
 *   and the registry record may not even exist before #271 populates it).
 * - Any OTHER connection is gated by its own registry record:
 *   'yes' (tested OK) imports; '' (untested) or 'not_entitled' (rejected by
 *   the SaaS, #276) skips with the matching failure code.
 * - A connection that no longer exists in the registry is a health-incident
 *   skip ('skip_missing') — a task is NEVER silently re-defaulted to another
 *   connection (decision #265).
 *
 * @param array|null $record                 The connection's registry record, or null.
 * @param bool       $is_current_mls         Whether the task's MLS is the currently selected one.
 * @param string     $global_connection_test The global mlsimport_connection_test flag.
 * @return array{action: string, code: string} action ∈ import|skip_disconnected|skip_missing.
 */
function mlsimport_task_connection_gate( ?array $record, bool $is_current_mls, string $global_connection_test ): array {
	// The current connection: unchanged single-MLS rule.
	if ( $is_current_mls ) {
		return 'yes' === $global_connection_test
			? array( 'action' => 'import', 'code' => '' )
			: array( 'action' => 'skip_disconnected', 'code' => 'mls_not_connected' );
	}

	// A deleted/unregistered connection: health incident, never a re-default.
	if ( null === $record ) {
		return array( 'action' => 'skip_missing', 'code' => 'connection_missing' );
	}

	// Any other connection: its own record status decides.
	if ( 'yes' === (string) ( $record['status'] ?? '' ) ) {
		return array( 'action' => 'import', 'code' => '' );
	}

	return array(
		'action' => 'skip_disconnected',
		'code'   => 'not_entitled' === (string) ( $record['status'] ?? '' ) ? 'not_entitled' : 'mls_not_connected',
	);
}

/**
 * Gate one cron task: resolve its connection and apply the pure rule above.
 *
 * @param int $task_id Import Task post id.
 * @return array{action: string, code: string, mls_id: int} Gate result plus the resolved mls_id.
 */
function mlsimport_cron_task_gate( int $task_id ): array {
	$mls_id = mlsimport_task_mls_id( $task_id );

	$gate = mlsimport_task_connection_gate(
		Mlsimport_Connections::get( $mls_id ),
		$mls_id === mlsimport_current_mls_id(),
		(string) get_option( 'mlsimport_connection_test', '' )
	);

	$gate['mls_id'] = $mls_id;
	return $gate;
}

/**
 * Record one connection's sync failure — scoped by mls_id (decision #265).
 *
 * Step by step:
 * 1. Stamp the legacy GLOBAL failure fields exactly as the old pre-loop
 *    connection bail-out did, so single-MLS heartbeat reporting is unchanged.
 * 2. Add/overwrite this connection's entry in the 'connection_sync_failures'
 *    telemetry map (mls_id => {at, code}), so a multi-connection site can see
 *    WHICH MLS is failing while the others keep syncing.
 *
 * @param int    $mls_id The failing connection.
 * @param string $code   Failure class ('mls_not_connected', 'not_entitled', ...).
 * @return void
 */
function mlsimport_record_connection_sync_failure( int $mls_id, string $code ): void {
	// Step 1: unchanged global stamps.
	mlsimport_telemetry_set( 'last_sync_failed', time() );
	mlsimport_telemetry_set( 'last_sync_failed_code', $code );

	// Step 2: the per-connection record — written through the single map
	// writer (#283) so this gate and the request choke point stamp the same
	// 'connection_sync_failures' map the same one way.
	mlsimport_telemetry_record_connection_sync( $mls_id, false, $code );
}

/**
 * Mirror a connection-test outcome into the registry record's status field.
 *
 * The gate above reads record status for every non-current connection, so the
 * test that today only writes the global flag must also keep the tested
 * connection's record truthful.
 *
 * Step by step:
 * 1. No record => no-op. Records are created by migration/#271, never here.
 * 2. Success => 'yes'. The SaaS validated the credentials against the live
 *    MLS — that also supersedes an earlier not_entitled rejection.
 * 3. Failure => '' (untested), EXCEPT a standing 'not_entitled' mark: that
 *    rejection is lifted only by re-entitlement (#276 rule), and softening it
 *    to plain "untested" would lose why the connection is skipped.
 * 4. Every test — pass or fail — stamps tested_at, so the Connections screen
 *    (#280) can show WHEN the status was last checked and tell "never tested"
 *    ('' with no stamp) apart from "the last test failed" ('' with a stamp).
 *
 * @param int  $mls_id    The tested connection.
 * @param bool $tested_ok Whether the SaaS confirmed the MLS connection works.
 * @return void
 */
function mlsimport_connection_record_test_result( int $mls_id, bool $tested_ok ): void {
	// Step 1: only registered connections carry status.
	$record = Mlsimport_Connections::get( $mls_id );
	if ( null === $record ) {
		return;
	}

	// Step 2+3: decide the new status; keep a not_entitled mark on failure.
	if ( $tested_ok ) {
		$record['status'] = 'yes';
	} elseif ( 'not_entitled' !== $record['status'] ) {
		$record['status'] = '';
	}

	// Step 4: a test just ran — stamp the time regardless of the outcome.
	$record['tested_at'] = time();
	Mlsimport_Connections::save( $record );
}

