<?php
/**
 * System-boundary contract for Import Task execution.
 *
 * The execution module owns Import Run rules. Implementations of this contract
 * only provide time, identity, and persistent WordPress run storage. Later TDD
 * slices extend the same boundary when external MLS and listing writes enter
 * the public run sequence.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies external operations without exposing execution internals.
 */
interface Mlsimport_Import_Task_Execution_Environment {

	/**
	 * Create a unique identity for a newly requested Import Run.
	 *
	 * @return string Unique run identity.
	 */
	public function new_run_id(): string;

	/**
	 * Return the current clock time used for activity and stale-run rules.
	 *
	 * @return int Unix timestamp.
	 */
	public function now(): int;

	/**
	 * Atomically claim the one site-wide Import Run slot and save the run.
	 *
	 * @param array<string, mixed> $run           Waiting run record.
	 * @param int                  $stale_before  Heartbeats at or before this time are stale.
	 * @return bool True when the run owns the slot.
	 */
	public function claim_run( array $run, int $stale_before ): bool;

	/**
	 * Read a previously accepted Import Run.
	 *
	 * @param string $run_id Run identity.
	 * @return array<string, mixed> Saved run, or an empty array when unavailable.
	 */
	public function read_run( string $run_id ): array;

	/**
	 * Report whether a worker still owns the one site-wide Import Run slot.
	 *
	 * @param string $run_id Run identity.
	 * @return bool True only while this identity remains current.
	 */
	public function owns_run( string $run_id ): bool;

	/**
	 * Persist state and progress changes for an active Import Run.
	 *
	 * @param string               $run_id Run identity.
	 * @param array<string, mixed> $changes Changed run fields.
	 * @return void
	 */
	public function update_run( string $run_id, array $changes ): void;

	/**
	 * Persist the final public result and release the site-wide slot.
	 *
	 * @param string               $run_id Run identity.
	 * @param array<string, mixed> $result Final Import Run Result.
	 * @return void
	 */
	public function finish_run( string $run_id, array $result ): void;

	/**
	 * Fetch one group of listings from the external MLS boundary.
	 *
	 * @param array<string, mixed> $run   Active run record and request.
	 * @param int                  $skip  Number of planned listings already handled.
	 * @param int                  $limit Maximum listings requested in this group.
	 * @return array<string, mixed> Response with success and data fields.
	 */
	public function fetch_listing_batch( array $run, int $skip, int $limit ): array;

	/**
	 * Save one MLS listing through the existing theme-specific write boundary.
	 *
	 * @param array<string, mixed> $run     Active run record and request.
	 * @param array<string, mixed> $listing One MLS listing payload.
	 * @return array<string, mixed> Response with success and error fields.
	 */
	public function save_listing( array $run, array $listing ): array;

	/**
	 * Persist a Stop request for the active run belonging to an Import Task.
	 *
	 * Stop is final for the administrator: the implementation records the
	 * stopped status and releases the site-wide slot immediately, so a new
	 * import may start right away. A still-live worker discovers the stop at
	 * its next listing boundary and exits without overwriting that status.
	 *
	 * @param int $task_id Import Task identifier.
	 * @return bool True when a matching active run received the request.
	 */
	public function request_stop( int $task_id ): bool;

	/**
	 * Report whether Stop has been requested for an active run.
	 *
	 * @param string $run_id Run identity.
	 * @return bool Stored Stop state.
	 */
	public function stop_requested( string $run_id ): bool;

	/**
	 * Read the latest public progress and result for an Import Task.
	 *
	 * @param int $task_id Import Task identifier.
	 * @return array<string, mixed> Public status, or an empty array when absent.
	 */
	public function read_task_status( int $task_id ): array;

	/**
	 * Queue the next background worker for an Import Run that hands off.
	 *
	 * Called from inside a still-running worker whose chunk time budget is
	 * spent. The implementation must only enqueue: it must not clean up
	 * queue state, because at hand-off time the current worker's own action
	 * is the one legitimately marked as running.
	 *
	 * @param string $run_id Run identity to continue.
	 * @return void
	 */
	public function enqueue_worker( string $run_id ): void;

	/**
	 * Queue a replacement worker for a run whose worker chain went silent.
	 *
	 * Called from the watchdog, never from inside a worker. Unlike
	 * enqueue_worker(), the implementation first clears dead or stale queue
	 * entries for the worker hook — at revival time any recorded running
	 * action belongs to a killed process, and any pending action failed to
	 * dispatch — then queues a fresh worker for the run.
	 *
	 * @param string $run_id Run identity to continue.
	 * @return void
	 */
	public function revive_worker( string $run_id ): void;

	/**
	 * Read the run currently holding the site-wide slot for one Import Task.
	 *
	 * @param int $task_id Import Task identifier.
	 * @return array<string, mixed> Active run record, or empty when the task
	 *                              holds no active run.
	 */
	public function read_active_run( int $task_id ): array;

	/**
	 * Count listings changed in the automatic run's saved sync period.
	 *
	 * @param array<string, mixed> $run Active automatic run and request.
	 * @return array<string, mixed> Response with success, found, and error fields.
	 */
	public function count_listings( array $run ): array;

	/**
	 * Advance one Import Task's successful automatic-sync time.
	 *
	 * The WordPress implementation preserves the agreed two-hour overlap while
	 * formatting and storing the new value.
	 *
	 * @param int $task_id     Import Task identifier.
	 * @param int $completed_at Successful run completion timestamp.
	 * @return void
	 */
	public function advance_last_successful_sync_time( int $task_id, int $completed_at ): void;
}
