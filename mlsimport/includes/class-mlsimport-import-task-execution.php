<?php
/**
 * Shared Import Task execution module.
 *
 * Manual actions, the setup wizard, and hourly cron will enter through this
 * class. The class owns the Import Run lifecycle while WordPress persistence,
 * the external MLS API, and theme-specific listing writes remain behind the
 * environment boundary.
 *
 * The module is being built in tested vertical slices. The first slice accepts
 * one manual run and completes the valid zero-listing case through the public
 * start() and execute() operations.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-mlsimport-import-task-execution-environment.php';

/**
 * Coordinates one Import Run through the accepted public seam.
 */
final class Mlsimport_Import_Task_Execution {

	/** Thirty minutes without activity makes an abandoned run replaceable. */
	private const STALE_AFTER_SECONDS = 1800;

	/** Number of listings released together after each external request. */
	private const BATCH_SIZE = 25;

	/** Maximum listings handled by one Import Run. */
	private const MAX_LISTINGS = 10000;

	/**
	 * Wall-clock seconds one manual worker may spend before handing off.
	 *
	 * Strict hosts kill long web requests at limits the plugin cannot see
	 * (issue #199). Checked after every batch, so the worst request length is
	 * this budget plus one final batch — safely inside a 60-second kill limit.
	 */
	private const CHUNK_BUDGET_SECONDS = 45;

	/**
	 * Seconds of worker silence before the watchdog revives a manual run.
	 *
	 * A live worker records activity after every listing, so this long a
	 * silence means the worker chain died: a chunk was killed before it could
	 * queue its follow-up, or a queued worker was never dispatched.
	 */
	private const REVIVE_AFTER_SECONDS = 90;

	/**
	 * Consecutive revivals at one unmoved position before the run fails.
	 *
	 * Progress between revivals resets the count: a run that keeps dying but
	 * keeps advancing is allowed to grind to completion. Only a position the
	 * server kills repeatedly is hopeless, and retrying it forever would loop.
	 */
	private const MAX_STALLED_REVIVALS = 3;

	/** @var Mlsimport_Import_Task_Execution_Environment External operations. */
	private $environment;

	/**
	 * Receive the WordPress environment or a system-boundary test double.
	 *
	 * @param Mlsimport_Import_Task_Execution_Environment $environment External operations.
	 */
	public function __construct( Mlsimport_Import_Task_Execution_Environment $environment ) {
		$this->environment = $environment;
	}

	/**
	 * Request the one site-wide slot for a new Import Run.
	 *
	 * The accepted run is stored in waiting state so an HTTP manual caller can
	 * return before Action Scheduler invokes execute(). The request is stored
	 * with the run because background execution must use exactly the values the
	 * caller supplied.
	 *
	 * @param array<string, mixed> $request Task id, source, found count, and limit.
	 * @return array<string, bool|string> Public start response.
	 */
	public function start( array $request ): array {
		$run_id = $this->environment->new_run_id();
		$now    = $this->environment->now();
		$run    = array(
			'run_id'     => $run_id,
			'task_id'    => (int) ( $request['task_id'] ?? 0 ),
			'source'     => (string) ( $request['source'] ?? 'manual' ),
			'state'      => 'waiting',
			'request'    => $request,
			'started_at' => $now,
			'activity_at' => $now,
		);

		$stale_before = $now - self::STALE_AFTER_SECONDS;
		if ( ! $this->environment->claim_run( $run, $stale_before ) ) {
			return array(
				'accepted' => false,
				'reason'   => 'already_running',
			);
		}

		return array(
			'accepted' => true,
			'run_id'   => $run_id,
			'state'    => 'waiting',
		);
	}

	/**
	 * Request that the active Import Run for one task stop safely.
	 *
	 * Stop is final: storage records the stopped status and releases the
	 * site-wide slot immediately, so a new import may start right away.
	 * Execution still checks the persisted request before each listing, so a
	 * listing already inside the theme-specific writer may finish, while no
	 * new listing starts afterward and the stopped status is never overwritten.
	 *
	 * @param int $task_id Import Task identifier.
	 * @return array<string, bool> Whether an active matching run was found.
	 */
	public function stop( int $task_id ): array {
		return array( 'accepted' => $this->environment->request_stop( $task_id ) );
	}

	/**
	 * Revive a manual Import Run whose worker chain went silent (issue #199).
	 *
	 * Chunked execution depends on each worker queueing its follow-up. When a
	 * host kills a worker before that hand-off, the chain is dead and the run
	 * would sit unfinished. The watchdog is called from the polled admin
	 * progress endpoint and from hourly cron; it queues a replacement worker
	 * once activity has been silent long enough, and fails the run with an
	 * explicit hosting error when revivals at one position keep dying.
	 *
	 * The worker generation advances with every revival: a presumed-dead
	 * worker that is actually still alive sees the newer generation at its
	 * next listing boundary and exits, so one run never has two live writers.
	 *
	 * @param int $task_id Import Task identifier.
	 * @return array<string, bool|string> Whether a worker was queued, with reason.
	 */
	public function revive( int $task_id ): array {
		$run = $this->environment->read_active_run( $task_id );
		if ( empty( $run ) ) {
			return array(
				'revived' => false,
				'reason'  => 'no_active_run',
			);
		}
		// Scope agreed for issue #199: only manual runs chunk, so only manual
		// runs are revived. A resumed automatic run would recount mid-run.
		if ( 'manual' !== (string) ( $run['source'] ?? '' ) ) {
			return array(
				'revived' => false,
				'reason'  => 'not_manual',
			);
		}
		$now = $this->environment->now();
		if ( $now - (int) ( $run['activity_at'] ?? 0 ) < self::REVIVE_AFTER_SECONDS ) {
			return array(
				'revived' => false,
				'reason'  => 'recent_activity',
			);
		}

		$run_id   = (string) $run['run_id'];
		$position = max( 0, (int) ( $run['handled'] ?? 0 ) );
		// Progress since the last revival proves the run is advancing, so the
		// stall count restarts; the same position again means another death
		// with zero progress.
		$stalled_revivals = $position === (int) ( $run['revived_at_position'] ?? -1 )
			? (int) ( $run['revive_count'] ?? 0 ) + 1
			: 1;
		if ( $stalled_revivals >= self::MAX_STALLED_REVIVALS ) {
			$this->environment->finish_run(
				$run_id,
				array(
					'state'  => 'failed',
					'found'  => (int) ( $run['expected'] ?? 0 ),
					'saved'  => (int) ( $run['saved'] ?? 0 ),
					'failed' => (int) ( $run['failed'] ?? 0 ),
					'error'  => sprintf(
						'Import stopped at listing %1$d of %2$d: the server terminated the import worker %3$d times at this position. Ask your hosting provider about PHP execution limits.',
						$position,
						(int) ( $run['expected'] ?? 0 ),
						self::MAX_STALLED_REVIVALS
					),
				)
			);
			return array(
				'revived' => false,
				'reason'  => 'stalled',
			);
		}

		// Refreshing activity_at here also arms a fresh 90-second window, so
		// repeated watchdog calls cannot queue a second replacement while the
		// first is still dispatching.
		$this->environment->update_run(
			$run_id,
			array(
				'worker_generation'   => (int) ( $run['worker_generation'] ?? 0 ) + 1,
				'revive_count'        => $stalled_revivals,
				'revived_at_position' => $position,
				'activity_at'         => $now,
			)
		);
		$this->environment->revive_worker( $run_id );
		return array(
			'revived' => true,
			'reason'  => '',
		);
	}

	/**
	 * Return the latest administrator-visible progress and final result.
	 *
	 * Storage retains the completed, stopped, or failed result until start()
	 * accepts a later run for the same Import Task.
	 *
	 * @param int $task_id Import Task identifier.
	 * @return array<string, mixed> Public Import Run Progress and Result.
	 */
	public function status( int $task_id ): array {
		return $this->environment->read_task_status( $task_id );
	}

	/**
	 * Execute an accepted Import Run and return its final public result.
	 *
	 * The first vertical slice completes only the zero-listing path. A non-zero
	 * run deliberately fails fast until the next red test introduces external
	 * batch fetching and listing saves.
	 *
	 * @param string $run_id Accepted run identity.
	 * @return array<string, int|string> Final Import Run Result.
	 */
	public function execute( string $run_id ): array {
		$run = $this->environment->read_run( $run_id );
		if ( empty( $run ) ) {
			throw new InvalidArgumentException( 'Import Run was not found.' );
		}
		$request = isset( $run['request'] ) && is_array( $run['request'] ) ? $run['request'] : array();
		$source  = (string) ( $run['source'] ?? 'manual' );
		$found   = max( 0, (int) ( $request['found'] ?? 0 ) );

		// A stale worker may wake after a replacement claimed the site-wide
		// slot. It must stop before changing state, fetching, or saving.
		if ( ! $this->environment->owns_run( $run_id ) ) {
			$result = array(
				'state'  => 'stopped',
				'found'  => $found,
				'saved'  => 0,
				'failed' => 0,
				'error'  => 'Import Run was replaced.',
			);
			$this->environment->finish_run( $run_id, $result );
			return $result;
		}

		// Automatic runs count changed listings at execution time. Manual runs
		// intentionally retain the count already shown on the task screen.
		if ( 'automatic' === $source ) {
			try {
				$count_response = $this->environment->count_listings( $run );
			} catch ( Throwable $exception ) {
				$count_response = array(
					'success' => false,
					'error'   => $exception->getMessage(),
				);
			}
			if ( true !== ( $count_response['success'] ?? false ) || ! isset( $count_response['found'] ) ) {
				$result = array(
					'state'  => 'failed',
					'found'  => 0,
					'saved'  => 0,
					'failed' => 0,
					'error'  => (string) ( $count_response['error'] ?? 'Listings count failed.' ),
				);
				$this->environment->finish_run( $run_id, $result );
				return $result;
			}
			$found = max( 0, (int) $count_response['found'] );
			if ( $found > self::MAX_LISTINGS ) {
				$result = array(
					'state'  => 'failed',
					'found'  => $found,
					'saved'  => 0,
					'failed' => 0,
					'error'  => 'Automatic Import Run exceeds the 10,000 listing limit.',
				);
				$this->environment->finish_run( $run_id, $result );
				return $result;
			}
		}

		$limit    = max( 0, (int) ( $request['limit'] ?? 0 ) );
		$expected = 0 === $limit ? $found : min( $found, $limit );
		$expected = min( self::MAX_LISTINGS, $expected );

		// A resumed chunk continues exactly where the previous worker handed
		// off: position and totals come from the persisted run, so a fresh run
		// starts at zero and a continuation never repeats or skips a listing.
		$saved   = max( 0, (int) ( $run['saved'] ?? 0 ) );
		$failed  = max( 0, (int) ( $run['failed'] ?? 0 ) );
		$handled = max( 0, (int) ( $run['handled'] ?? 0 ) );
		$error   = (string) ( $run['error'] ?? '' );
		$this->environment->update_run(
			$run_id,
			array(
				'state'       => 'running',
				'handled'     => $handled,
				'expected'    => $expected,
				'error'       => $error,
				'activity_at' => $this->environment->now(),
			)
		);
		// A Stop request can arrive while an async manual run is still waiting.
		// Honor it before the first external request; during a listing save, the
		// existing per-listing check lets that current save finish safely.
		$stopped          = $this->environment->stop_requested( $run_id );
		$chunk_started_at = $this->environment->now();
		// The generation this worker was started for. A watchdog revival
		// advances the stored generation, so an overtaken worker — presumed
		// dead but actually alive — recognizes its replacement per listing.
		$generation = (int) ( $run['worker_generation'] ?? 0 );
		while ( $handled < $expected ) {
			if ( $stopped ) {
				break;
			}
			$batch_limit = min( self::BATCH_SIZE, $expected - $handled );
			try {
				$response = $this->environment->fetch_listing_batch( $run, $handled, $batch_limit );
			} catch ( Throwable $exception ) {
				$error = '' !== $exception->getMessage() ? $exception->getMessage() : 'Listings request failed.';
				break;
			}
			$data        = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

			// A failed response is a real error: continuing would silently
			// leave a hole in the import.
			if ( true !== ( $response['success'] ?? false ) ) {
				$error = (string) ( $response['error'] ?? 'Listings request failed.' );
				break;
			}

			// A valid but EMPTY page is not an error: the MLS holds fewer
			// matching listings than were counted when the run started
			// (listings change status or vanish during a long import — observed
			// as 1037 real listings for a count of 1039). Everything available
			// has been imported, so finish the run normally.
			if ( empty( $data ) ) {
				break;
			}

			$remaining = $expected - $handled;
			foreach ( array_slice( $data, 0, $remaining ) as $listing ) {
				// A final Stop releases the site-wide slot immediately, so an
				// explicit Stop must be recognized before the lost slot is
				// treated as a replacement by another run.
				if ( $this->environment->stop_requested( $run_id ) ) {
					$stopped = true;
					break;
				}
				if ( ! $this->environment->owns_run( $run_id ) ) {
					$stopped = true;
					$error   = 'Import Run was replaced.';
					break;
				}
				// A revival replaced this worker while it was presumed dead.
				// Unlike the lost-slot case above, the run itself is still
				// alive under the replacement worker, so this worker must
				// leave the run record, the slot, and the task status alone —
				// it exits without finishing anything.
				if ( (int) ( $this->environment->read_run( $run_id )['worker_generation'] ?? 0 ) !== $generation ) {
					return array(
						'state'  => 'stopped',
						'found'  => $found,
						'saved'  => $saved,
						'failed' => $failed,
						'error'  => 'Import Run was replaced.',
					);
				}
				++$handled;
				if ( ! is_array( $listing ) || empty( $listing['ListingKey'] ) ) {
					++$failed;
					$error = 'ListingKey is missing.';
				} else {
					try {
						$save = $this->environment->save_listing( $run, $listing );
					} catch ( Throwable $exception ) {
						$save = array(
							'success' => false,
							'error'   => '' !== $exception->getMessage()
								? $exception->getMessage()
								: 'Listing could not be saved.',
						);
					}
					if ( true === ( $save['success'] ?? false ) ) {
						++$saved;
					} else {
						++$failed;
						$error = (string) ( $save['error'] ?? 'Listing could not be saved.' );
					}
				}

				// Persist progress after every listing so the polled admin
				// progress bar advances in near real time, not once per batch.
				// Saved and failed totals are stored too, so a later chunk or a
				// watchdog revival can continue with correct final counts.
				$this->environment->update_run(
					$run_id,
					array(
						'handled'     => $handled,
						'expected'    => $expected,
						'saved'       => $saved,
						'failed'      => $failed,
						'error'       => $error,
						'activity_at' => $this->environment->now(),
					)
				);
			}

			if ( $stopped ) {
				break;
			}

			// Resumable chunking (issue #199): a manual worker whose time budget
			// is spent must not start another batch inside this same request —
			// strict hosts kill long requests at limits the plugin cannot see.
			// Position and totals were persisted with the last listing, so this
			// worker queues a follow-up worker for the same run, keeps the
			// site-wide slot, and exits. Only a worker that reaches the end of
			// the plan finishes the run below.
			if ( 'manual' === $source
				&& $handled < $expected
				&& ( $this->environment->now() - $chunk_started_at ) >= self::CHUNK_BUDGET_SECONDS ) {
				// Count the hand-off on the run record (issue #216): the finished
				// run's telemetry snapshot reports 1 + handoffs + revivals as its
				// worker total. Each worker hands off at most once, so the value
				// read at execute() start is still current here.
				$this->environment->update_run(
					$run_id,
					array( 'handoffs' => (int) ( $run['handoffs'] ?? 0 ) + 1 )
				);
				$this->environment->enqueue_worker( $run_id );
				return array(
					'state'  => 'running',
					'found'  => $found,
					'saved'  => $saved,
					'failed' => $failed,
					'error'  => $error,
				);
			}
		}

		$state  = $stopped ? 'stopped' : ( '' === $error && 0 === $failed ? 'completed' : 'failed' );
		$result = array(
			'state'  => $state,
			'found'  => $found,
			'saved'  => $saved,
			'failed' => $failed,
			'error'  => $error,
		);
		// Every completed run advances the task's last-sync watermark — not just
		// automatic ones (GitHub issue #202 follow-up). A completed manual run
		// has just written everything the task matches, so "changed since
		// completion" is exactly the right next window; and it is the manual run
		// that makes a task cron-eligible, so it must seed the watermark the
		// hourly sync requires (an automatic run refuses to start without one).
		if ( 'completed' === $state ) {
			$this->environment->advance_last_successful_sync_time(
				(int) $run['task_id'],
				$this->environment->now()
			);
		}
		$this->environment->finish_run( $run_id, $result );

		return $result;
	}
}
