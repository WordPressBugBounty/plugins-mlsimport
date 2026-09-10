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
 * Whether the hourly runner will process a task at all (GitHub issue #330).
 *
 * The ONE eligibility rule, shared by the hourly runner and the Import Tasks
 * Status badge so the two can never disagree: a task is eligible once a
 * manual import has completed. That is recorded permanently as
 * mlsimport_initial_import_completed = 1 (a later manual retry that dies
 * cannot revoke it), or, on installs upgraded before that flag existed, as
 * the legacy mlsimport_spawn_status = 'completed'.
 *
 * A task whose only manual run died part-way sits at spawn status 'started'
 * with no flag and is therefore NOT eligible: the hourly sync is a delta on
 * top of a complete first import, so it must not adopt such a task.
 *
 * @param int    $initial_import_completed The task's mlsimport_initial_import_completed meta (1 = done).
 * @param string $spawn_status             The task's mlsimport_spawn_status meta value.
 * @return bool True when the hourly runner may start an import on the task.
 */
function mlsimport_cron_task_is_eligible( int $initial_import_completed, string $spawn_status ): bool {
	return 1 === $initial_import_completed || mlsimport_cron_should_process_task( $spawn_status );
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

/**
 * The queue key one task sorts by in mlsimport_cron_task_order() (issue #330).
 *
 * Ordering by the last SUCCESS alone had a hole: a task whose hourly run
 * never completes keeps the oldest watermark, so it is first in line every
 * hour and the tasks behind it never get the slot (one client site: one
 * city re-run all night, seven cities unsynced). The key is therefore the
 * newer of the two stamps, last success and last attempt: any attempt,
 * whatever its outcome, sends the task to the back of the line.
 *
 * Both stamps use the fixed-width 'Y-m-d\TH:i' format, so the newer one is
 * simply the greater string; an empty stamp never wins.
 *
 * @param string $watermark    Last successful sync (mlsimport_last_date meta).
 * @param string $last_attempt Last automatic attempt (mlsimport_last_attempt meta).
 * @return string The queue key.
 */
function mlsimport_cron_task_queue_key( string $watermark, string $last_attempt ): string {
	return strcmp( $last_attempt, $watermark ) > 0 ? $last_attempt : $watermark;
}
