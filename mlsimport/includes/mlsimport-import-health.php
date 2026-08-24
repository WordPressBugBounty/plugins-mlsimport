<?php
/**
 * Import health watch (issue #208).
 *
 * File role: gives every import surface a heartbeat and turns silent failures
 * into incidents for includes/mlsimport-alerts.php. Two things are watched:
 *
 *   A. The hourly cron import. mlsimport.php wraps the auto-import loop in
 *      heartbeat start/progress/finish calls, which maintain one record in the
 *      mlsimport_cron_heartbeat option: phase, started_at, progress_at, items.
 *      At the top of every cron entry mlsimport_cron_heartbeat_check() looks
 *      at the PREVIOUS record: a record still in phase 'running' long after it
 *      started means that run's process died mid-loop — the exact silent
 *      failure that previously left no trace. One deduplicated incident is
 *      opened; the next clean finish resolves it.
 *
 *   B. Manual Import Runs. mlsimport_import_health_watch_manual_run() reads
 *      the active run's public status: a run still 'waiting' (the customer
 *      sees "Preparing the import") past the threshold opens an incident, and
 *      a run that got moving again resolves it.
 *
 * Every threshold is filterable, so limits can be tuned without a code change.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Record the start of an hourly cron import run.
 *
 * Overwrites the previous heartbeat — mlsimport_cron_heartbeat_check() must
 * run before this at the cron entry, while the previous record is still there.
 *
 * @param int|null $now Unix time (tests inject; production uses time()).
 * @return void
 */
function mlsimport_cron_heartbeat_start( $now = null ) {
	$now = null === $now ? time() : intval( $now );
	update_option(
		'mlsimport_cron_heartbeat',
		array(
			'phase'       => 'running',
			'started_at'  => $now,
			'progress_at' => $now,
			'items'       => 0,
		),
		false
	);
}

/**
 * Record measurable progress inside the running cron import.
 *
 * @param int      $items_total Items processed so far in this run.
 * @param int|null $now         Unix time (tests inject).
 * @return void
 */
function mlsimport_cron_heartbeat_progress( $items_total, $now = null ) {
	$beat = get_option( 'mlsimport_cron_heartbeat', array() );
	if ( ! is_array( $beat ) || 'running' !== ( $beat['phase'] ?? '' ) ) {
		return;
	}
	$beat['items']       = intval( $items_total );
	$beat['progress_at'] = null === $now ? time() : intval( $now );
	update_option( 'mlsimport_cron_heartbeat', $beat, false );
}

/**
 * Record a clean end of the cron import and resolve any died-run incident.
 *
 * @param int|null $now Unix time (tests inject).
 * @return void
 */
function mlsimport_cron_heartbeat_finish( $now = null ) {
	$beat = get_option( 'mlsimport_cron_heartbeat', array() );
	if ( ! is_array( $beat ) ) {
		$beat = array();
	}
	$beat['phase']       = 'finished';
	$beat['finished_at'] = null === $now ? time() : intval( $now );
	update_option( 'mlsimport_cron_heartbeat', $beat, false );

	// The import demonstrably works again — close the incident, if one is open.
	mlsimport_alert_resolve( 'cron_import_died', array( 'items' => intval( $beat['items'] ?? 0 ) ) );
}

/**
 * Detect a cron import whose process died mid-loop (#208).
 *
 * Called at the very top of every cron entry, before the new heartbeat
 * overwrites the old one. A record still in phase 'running' longer than the
 * stale threshold after it started can no longer be a live run — the process
 * was killed without reaching finish. Opens one deduplicated incident with
 * sanitized progress context; dedup means repeated hourly checks stay silent
 * until a clean finish resolves the incident.
 *
 * @param int|null $now Unix time (tests inject).
 * @return void
 */
function mlsimport_cron_heartbeat_check( $now = null ) {
	$now  = null === $now ? time() : intval( $now );
	$beat = get_option( 'mlsimport_cron_heartbeat', array() );
	if ( ! is_array( $beat ) || 'running' !== ( $beat['phase'] ?? '' ) ) {
		return;
	}

	/** Filter the seconds after which a still-'running' cron heartbeat counts as dead. @since 7.2 */
	$stale_after = intval( apply_filters( 'mlsimport_import_health_cron_stale_seconds', 1800 ) );
	if ( $now - intval( $beat['started_at'] ?? 0 ) < $stale_after ) {
		return;
	}

	mlsimport_alert_open(
		'cron_import_died',
		'cron_import_died',
		array(
			'phase'       => 'running',
			'started_at'  => intval( $beat['started_at'] ?? 0 ),
			'progress_at' => intval( $beat['progress_at'] ?? 0 ),
			'items'       => intval( $beat['items'] ?? 0 ),
		)
	);
}

/**
 * Watch the active Import Run for a stuck "Preparing" phase (#208).
 *
 * The ticket's worst churn case was an import showing "Preparing the import"
 * with zero progress for 32 days — a run accepted into 'waiting' whose worker
 * was never dispatched. Called from hourly cron: reads the site-wide run lock
 * and the run it points at (the same records the execution module maintains),
 * then applies one rule each way:
 *   - still 'waiting' past the threshold → open one incident for this run_id;
 *   - any other state (the run moved)     → resolve that incident.
 * Dedup lives in the alerts module, so repeated hourly checks stay silent.
 *
 * @param int|null $now Unix time (tests inject).
 * @return void
 */
function mlsimport_import_health_watch_manual_run( $now = null ) {
	$now  = null === $now ? time() : intval( $now );
	$lock = get_option( 'mlsimport_import_run_lock', array() );
	if ( ! is_array( $lock ) || empty( $lock['run_id'] ) ) {
		return;
	}

	// The full run record behind the lock (same key scheme the execution
	// environment uses: run id hashed into the option name).
	$run = get_option( 'mlsimport_import_run_' . md5( (string) $lock['run_id'] ), array() );
	if ( ! is_array( $run ) || empty( $run['run_id'] ) ) {
		return;
	}

	$incident = 'import_preparing:' . $run['run_id'];

	// The run moved past waiting — whatever happens next, "stuck at
	// Preparing" is over for this run.
	if ( 'waiting' !== (string) ( $run['state'] ?? '' ) ) {
		mlsimport_alert_resolve( $incident, array( 'state' => (string) ( $run['state'] ?? '' ) ) );
		return;
	}

	/** Filter the seconds a run may sit in 'waiting' before it counts as stuck. @since 7.2 */
	$preparing_limit = intval( apply_filters( 'mlsimport_import_health_preparing_seconds', 900 ) );
	if ( $now - intval( $run['started_at'] ?? 0 ) < $preparing_limit ) {
		return;
	}

	mlsimport_alert_open(
		$incident,
		'import_preparing',
		array(
			'task_id'         => intval( $run['task_id'] ?? 0 ),
			'source'          => (string) ( $run['source'] ?? '' ),
			'waiting_seconds' => $now - intval( $run['started_at'] ?? 0 ),
		)
	);
}

/**
 * Show the broken-connection remediation notice in wp-admin (#208).
 *
 * The counterpart of the connection-health state ThemeImport records: when the
 * SaaS has definitively rejected (or is missing) the account credentials, the
 * administrator sees one clear error notice telling them exactly what to fix
 * and where — instead of imports silently doing nothing. Healthy and unknown
 * states render nothing. Never prints any credential value.
 *
 * @return void
 */
function mlsimport_connection_health_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$health = get_option( 'mlsimport_connection_health', array() );
	$status = is_array( $health ) ? (string) ( $health['status'] ?? '' ) : '';
	if ( 'credentials_invalid' !== $status && 'credentials_missing' !== $status ) {
		return;
	}

	$message = 'credentials_missing' === $status
		? esc_html__( 'MLSImport cannot connect: no account credentials are configured, so imports and hourly sync are stopped.', 'mlsimport' )
		: esc_html__( 'MLSImport cannot connect: the SaaS rejected your account credentials, so imports and hourly sync are stopped. Re-enter your MLSImport username and password.', 'mlsimport' );

	echo '<div class="notice notice-error"><p><strong>MLSImport</strong> — '
		. $message
		. ' <a href="' . esc_url( admin_url( 'admin.php?page=mlsimport_plugin_options' ) ) . '">'
		. esc_html__( 'Open MLSImport settings', 'mlsimport' )
		. '</a></p></div>';
}
add_action( 'admin_notices', 'mlsimport_connection_health_notice' );
