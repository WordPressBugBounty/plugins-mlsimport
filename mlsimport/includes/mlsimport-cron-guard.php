<?php
/**
 * Auto-sync cron mutual-exclusion guard.
 *
 * Pure function (no WordPress dependency) so it can be unit tested in
 * isolation. The hourly cron and a user's manual import both drive the same
 * mlsimport_item task and write the same progress meta. This decides, from a
 * task's mlsimport_spawn_status, whether the cron may start its own import loop
 * on that task.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Whether the hourly cron may process a task, given its spawn status.
 *
 * The cron runs only on a task that has fully completed a previous import.
 * That single rule excludes both a never-imported task ('') and a task with an
 * import in flight ('started', set by the manual path until its async run ends).
 *
 * @param string $spawn_status The task's mlsimport_spawn_status meta value.
 * @return bool True when the cron may start an import loop on the task.
 */
function mlsimport_cron_should_process_task( string $spawn_status ): bool {
	return 'completed' === $spawn_status;
}

/**
 * Order the hourly sync's tasks by starvation: oldest last-sync first.
 *
 * GitHub issue #203: processing tasks in the same fixed order every hour let
 * one large early task eat the whole cycle, so the tasks at the bottom were
 * skipped run after run and a region could go 9+ hours without an update.
 * Sorting by the last-sync watermark guarantees the task that has waited the
 * longest is always first in line on the next run.
 *
 * Step by step:
 * 1. Receives every cron-enabled task as id => 'Y-m-d\TH:i' watermark
 *    (the task's mlsimport_last_date meta).
 * 2. Sorts ascending by watermark — the fixed-width format compares
 *    correctly as a plain string, no date parsing needed.
 * 3. Returns just the task ids, most starved first.
 *
 * @param array<int, string> $tasks Task id => last-sync watermark.
 * @return int[] Task ids, the longest-unsynced task first.
 */
function mlsimport_cron_task_order( array $tasks ): array {
	asort( $tasks, SORT_STRING );
	return array_keys( $tasks );
}
