<?php
/**
 * Import Task health badge decider (GitHub issue #200).
 *
 * Pure function (no WordPress dependency) so it can be unit tested in
 * isolation. The execution engine already records every run's state, progress,
 * heartbeat, and error in the task's mlsimport_import_run_status meta, but the
 * Import Tasks admin list never showed any of it — a stuck or failed task
 * looked identical to a healthy one ("Importing 10 out of 100" forever, no
 * warning). This file decides, from that recorded status plus the sync
 * watermark, what the Status column badge says for one task.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// A live worker records activity after every listing; the execution engine
// treats a run lock silent for 1800 seconds as dead (STALE_AFTER_SECONDS).
// The badge uses the same threshold so "Stuck" and lock takeover agree.
if ( ! defined( 'MLSIMPORT_TASK_HEALTH_STUCK_AFTER' ) ) {
	define( 'MLSIMPORT_TASK_HEALTH_STUCK_AFTER', 1800 );
}

// Auto-sync runs hourly and advances the watermark on every successful run, so
// a watermark 6+ hours old means several consecutive failed or skipped syncs —
// old enough to be a real problem, tolerant of a temporary hiccup.
if ( ! defined( 'MLSIMPORT_TASK_HEALTH_OVERDUE_AFTER' ) ) {
	define( 'MLSIMPORT_TASK_HEALTH_OVERDUE_AFTER', 21600 );
}

/**
 * Decide the Status column badge for one Import Task.
 *
 * Step by step:
 * 0. No run status and no watermark: a task waiting for its first manual
 *    import — neutral, not a health problem.
 * 1. A run the engine marked failed is an error badge carrying the run's own
 *    human-readable error, so the administrator reads WHY in the task list.
 * 2. A running import whose heartbeat went silent longer than the engine's
 *    own staleness rule (30 minutes) is a dead worker: flag it stuck. This is
 *    the issue's reported scenario — "Importing 10 out of 100" forever.
 * 3. A running import with a fresh heartbeat is healthy: show its progress.
 * 4. A cron-enabled task without a watermark cannot hourly-sync at all (the
 *    sync refuses to start without one): warn to run one manual import. A
 *    watermark that fell behind the overdue cutoff means the hourly sync has
 *    been failing or skipped for hours: warn with the last sync time.
 * 5. Auto-sync disabled: neutral "last import" badge — sync wording would be
 *    false framing and an overdue warning would be permanent noise.
 * 6. Otherwise: syncing normally.
 *
 * @param array<string, mixed> $status                 The task's recorded run status meta
 *                                                     (state, handled, expected, error, activity_at).
 * @param string               $watermark              The task's mlsimport_last_date sync watermark ('Y-m-d\TH:i').
 * @param bool                 $cron_enabled           Whether hourly auto-sync is on for the task.
 * @param int                  $now                    Current Unix timestamp.
 * @param string               $watermark_stale_before Watermarks older than this ('Y-m-d\TH:i') are overdue.
 * @return array<string, string> Badge as level ('ok'|'warning'|'error'|'neutral'), label, message.
 */
function mlsimport_task_health( array $status, string $watermark, bool $cron_enabled, int $now, string $watermark_stale_before ): array {
	$state = (string) ( $status['state'] ?? '' );

	// 0. Never ran at all: no run status and no watermark is a task waiting
	// for its first manual import, not a health problem.
	if ( '' === $state && '' === $watermark ) {
		return array(
			'level'   => 'neutral',
			'label'   => 'Never imported',
			'message' => 'Run a manual import to activate this task.',
		);
	}

	// 1. A failed run: show the engine's own stored error message.
	if ( 'failed' === $state ) {
		return array(
			'level'   => 'error',
			'label'   => 'Import failed',
			'message' => (string) ( $status['error'] ?? '' ),
		);
	}

	// 2. A silent 'running' status: the worker heartbeats after every listing,
	// so a heartbeat older than the engine's 30-minute staleness rule means
	// the worker died and nothing will update this task again — call it stuck.
	if ( 'running' === $state && $now - (int) ( $status['activity_at'] ?? 0 ) > MLSIMPORT_TASK_HEALTH_STUCK_AFTER ) {
		return array(
			'level'   => 'error',
			'label'   => 'Stuck',
			'message' => sprintf(
				'Stopped at listing %1$d of %2$d — no worker activity for over 30 minutes.',
				(int) ( $status['handled'] ?? 0 ),
				(int) ( $status['expected'] ?? 0 )
			),
		);
	}

	// 3. A live import: recent activity means it is genuinely progressing —
	// show where it is.
	if ( 'running' === $state ) {
		return array(
			'level'   => 'ok',
			'label'   => 'Importing',
			'message' => sprintf(
				'%1$d of %2$d listings imported.',
				(int) ( $status['handled'] ?? 0 ),
				(int) ( $status['expected'] ?? 0 )
			),
		);
	}

	// 4a. Cron on but no watermark at all: the hourly sync refuses to start
	// without one (tasks imported before manual runs seeded it — GitHub issue
	// #202 follow-up). Say so plainly instead of printing a blank sync date.
	if ( $cron_enabled && '' === $watermark ) {
		return array(
			'level'   => 'warning',
			'label'   => 'Sync not started',
			'message' => 'The hourly sync has no start point yet — run one manual import.',
		);
	}

	// 4b. Auto-sync advances the watermark on every successful hourly run, so a
	// cron-enabled task whose watermark is older than the overdue cutoff has
	// not synced successfully for hours. The fixed-width 'Y-m-d\TH:i' format
	// compares correctly as a plain string, no date parsing needed.
	if ( $cron_enabled && strcmp( $watermark, $watermark_stale_before ) < 0 ) {
		return array(
			'level'   => 'warning',
			'label'   => 'Sync overdue',
			'message' => sprintf( 'Last successful sync: %s.', $watermark ),
		);
	}

	// 5. Auto-sync disabled: "Synced"/"Sync overdue" would be false framing for
	// a task that never syncs — state the last import date neutrally instead.
	if ( ! $cron_enabled ) {
		return array(
			'level'   => 'neutral',
			'label'   => 'Auto-sync off',
			'message' => sprintf( 'Last import: %s.', $watermark ),
		);
	}

	// 6. Healthy steady state: syncing normally inside the overdue cutoff.
	return array(
		'level'   => 'ok',
		'label'   => 'Synced',
		'message' => sprintf( 'Last successful sync: %s.', $watermark ),
	);
}
