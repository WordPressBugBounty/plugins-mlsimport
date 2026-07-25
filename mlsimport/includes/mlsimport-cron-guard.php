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
