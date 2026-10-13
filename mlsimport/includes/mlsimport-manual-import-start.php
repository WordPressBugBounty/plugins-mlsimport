<?php
/**
 * Start a manual Import Run for one Import Task.
 *
 * The single "start a manual import" path of the plugin. Three callers use it
 * and nothing else starts a manual run:
 *   - the Import Task screen (Mlsimport_Admin::mlsimport_move_files_per_item());
 *   - the setup wizard's test import (mlsimport_ajax_run_test_import());
 *   - the run-import AI tool.
 *
 * It does four things, in order: count the matching listings at the MLS when
 * the caller has no count, ask the shared execution module for the one
 * site-wide run slot, write the import log lines, and queue the background
 * worker. It reads nothing from the request and performs no permission check;
 * each caller authorizes its own request first.
 *
 * Public interface:
 *   mlsimport_start_manual_import( $task_id, $limit, $found, $is_onboard )
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Start a manual Import Run.
 *
 * The matching count: the Import Task screen already counted the listings and
 * showed the number, so it passes that count and the MLS is not asked again.
 * The setup wizard and the AI tool have no count on screen; they pass none and
 * this function counts once at the MLS, then caps the count by $limit. A count
 * is never taken from the AI.
 *
 * @param int      $task_id    Import Task post id.
 * @param int      $limit      How many listings to import.
 * @param int|null $found      How many listings match the task, when the caller
 *                             already counted them; null to count here.
 * @param int      $is_onboard 1 when the run belongs to the setup wizard.
 * @return array {
 *     @type bool   $started True when the run was accepted and queued.
 *     @type string $run_id  The accepted run (when started).
 *     @type int    $found   Matching listings the run was started with (when started).
 *     @type string $reason  Why not (when not started): 'no_listings' | 'already_running'.
 *     @type string $message Plain sentence for the refusal (when not started).
 * }
 */
function mlsimport_start_manual_import( int $task_id, int $limit, ?int $found = null, int $is_onboard = 0 ): array {
	global $mlsimport;
	$admin = $mlsimport->admin;

	// Step 1: the caller has no count, so count once at the MLS. Zero matches
	// (or an answer without a count) starts no run.
	if ( null === $found ) {
		$mlsrequest = $admin->mlsimport_make_listing_requests( $task_id );
		if ( ! isset( $mlsrequest['results'] ) || 0 === intval( $mlsrequest['results'] ) ) {
			return array(
				'started' => false,
				'reason'  => 'no_listings',
				'message' => __( 'No listings found with current configuration', 'mlsimport' ),
			);
		}
		$found = min( $limit, max( 0, intval( $mlsrequest['results'] ) ) );
	}

	// Step 2: ask for the one site-wide run slot. The count and the limit
	// travel with the run so the background worker does not count again.
	$start = $admin->mlsimport_import_task_execution()->start(
		array(
			'task_id'    => $task_id,
			'source'     => 'manual',
			'found'      => $found,
			'limit'      => $limit,
			'is_onboard' => $is_onboard,
		)
	);
	if ( true !== ( $start['accepted'] ?? false ) ) {
		return array(
			'started' => false,
			'reason'  => (string) ( $start['reason'] ?? 'already_running' ),
			'message' => __( 'Another import is already running. Please wait for it to finish.', 'mlsimport' ),
		);
	}

	// Step 3: write the import log lines.
	mlsimport_saas_single_write_import_custom_logs( 'Manual import queued for task ' . $task_id . '.' . PHP_EOL, 'manual' );
	mlsimport_debuglogs_per_plugin( 'Manual import queued for task ' . $task_id . '.' . PHP_EOL );

	// Step 4: queue the background worker. The shared scheduling clears dead
	// or superseded queue entries first, so a crashed worker never blocks it.
	$admin->mlsimport_enqueue_import_worker( (string) $start['run_id'] );

	return array(
		'started' => true,
		'run_id'  => (string) $start['run_id'],
		'found'   => $found,
	);
}
