<?php
/**
 * Deep listing reconciliation module.
 *
 * This file owns the keep/delete decision sequence behind one public operation:
 * Mlsimport_Reconciliation::reconcile_current_listings(). WordPress, the SaaS
 * snapshot endpoint, scheduling, and destructive storage are accessed through
 * the environment interface so policy is testable without exposing helpers.
 *
 * Reconciliation is deliberately plan-first. It reads and validates the whole
 * Managed Listing set, creates every decision, and only then starts deletion.
 * This prevents a late read or policy failure from leaving a half-evaluated run.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-mlsimport-reconciliation-environment.php';

/**
 * Coordinates one complete Reconciliation Run through the confirmed seam.
 */
final class Mlsimport_Reconciliation {
	/** One-hour delay agreed for postponed and partial run retries. */
	private const RETRY_DELAY_SECONDS = 3600;

	/**
	 * External operations used during the run.
	 *
	 * @var Mlsimport_Reconciliation_Environment
	 */
	private $environment;

	/**
	 * Receive the concrete WordPress environment or a system-boundary test double.
	 *
	 * @param Mlsimport_Reconciliation_Environment $environment External operations.
	 */
	public function __construct( Mlsimport_Reconciliation_Environment $environment ) {
		$this->environment = $environment;
	}

	/**
	 * Reconcile every Managed Listing against one authoritative snapshot.
	 *
	 * Run sequence:
	 * 1. Acquire the singleton lock.
	 * 2. Postpone when import or sync is active.
	 * 3. Fetch and structurally validate the snapshot before local reads.
	 * 4. Read the complete inventory and apply the independent 80% guard.
	 * 5. Build the complete keep/delete plan without side effects.
	 * 6. Apply planned deletions and return observable counts.
	 * 7. Always release the lock.
	 *
	 * @return array<string, int|string> Structured Reconciliation Outcome.
	 */
	public function reconcile_current_listings(): array {
		if ( ! $this->environment->acquire_lock() ) {
			return $this->outcome( 'already_running', 'reconciliation_already_running' );
		}

		try {
			if ( $this->environment->import_or_sync_is_active() ) {
				// Reconciliation and imports mutate the same listings. Postpone
				// before fetching or planning, and let the environment deduplicate
				// the single retry event at the scheduling boundary.
				$this->environment->schedule_retry( self::RETRY_DELAY_SECONDS );
				return $this->outcome( 'postponed', 'import_or_sync_active' );
			}

			try {
				$snapshot = $this->environment->fetch_snapshot();
			} catch ( Throwable $exception ) {
				// Endpoint and transport failures are safety aborts. They wait for
				// the next daily run instead of creating an hourly retry loop.
				return $this->outcome( 'aborted', 'snapshot_fetch_failed' );
			}
			$keys           = isset( $snapshot['all_data'] ) && is_array( $snapshot['all_data'] )
				? $snapshot['all_data']
				: array();
			$keys_are_valid = ! empty( $keys );
			foreach ( $keys as $key ) {
				if ( ! is_string( $key ) || '' === trim( $key ) ) {
					$keys_are_valid = false;
					break;
				}
			}

			if (
				true !== ( $snapshot['complete'] ?? false )
				|| ! $keys_are_valid
				|| ! isset( $snapshot['count'] )
				|| ! is_int( $snapshot['count'] )
				|| count( $keys ) !== $snapshot['count']
				|| count( $keys ) !== count( array_unique( $keys, SORT_STRING ) )
			) {
				return $this->outcome( 'aborted', 'snapshot_not_authoritative' );
			}

			try {
				$listings = $this->environment->read_managed_listings();
			} catch ( Throwable $exception ) {
				// A complete plan cannot be proven when any Managed Listing read
				// fails, so return an abort before the deletion phase begins.
				return $this->outcome( 'aborted', 'managed_listing_read_failed' );
			}

			if ( ! mlsimport_reconciliation_feed_is_plausible( count( $keys ), count( $listings ) ) ) {
				return $this->outcome( 'aborted', 'snapshot_not_authoritative' );
			}

			$snapshot_keys = array_fill_keys( $keys, true );
			$plan          = array();

			foreach ( $listings as $listing ) {
				// Every row must contain the values needed to make one reliable
				// policy decision. Any invalid row aborts the whole plan before
				// the later deletion loop can perform a destructive side effect.
				if (
					! is_array( $listing )
					|| empty( $listing['id'] )
					|| ! isset( $listing['listing_key'] )
					|| ! is_string( $listing['listing_key'] )
					|| '' === trim( $listing['listing_key'] )
					|| ! array_key_exists( 'import_task_exists', $listing )
					|| ! isset( $listing['protected_statuses'] )
					|| ! is_array( $listing['protected_statuses'] )
					|| ! array_key_exists( 'status_readable', $listing )
					|| ! array_key_exists( 'status', $listing )
				) {
					return $this->outcome( 'aborted', 'reconciliation_plan_invalid' );
				}

				if ( isset( $snapshot_keys[ $listing['listing_key'] ] ) ) {
					$plan[] = array(
						'decision' => 'keep',
						'listing'  => $listing,
					);
					continue;
				}

				$protected_statuses = isset( $listing['protected_statuses'] ) && is_array( $listing['protected_statuses'] )
					? $listing['protected_statuses']
					: array();

				// Protection belongs to the creating Import Task. When that task
				// has a protection policy, keep either a confirmed matching status
				// or an unreadable status whose protection cannot be ruled out.
				if (
					! empty( $listing['import_task_exists'] )
					&& ! empty( $protected_statuses )
					&& (
						empty( $listing['status_readable'] )
						|| in_array( $listing['status'], $protected_statuses, true )
					)
				) {
					$plan[] = array(
						'decision' => 'keep',
						'listing'  => $listing,
					);
					continue;
				}

				$plan[] = array(
					'decision' => 'delete',
					'listing'  => $listing,
					'reason'   => empty( $listing['import_task_exists'] )
						? 'absent_import_task_missing'
						: 'absent_unprotected',
				);
			}

			$kept    = 0;
			$deleted = 0;
			$failed  = 0;
			foreach ( $plan as $item ) {
				if ( 'keep' === $item['decision'] ) {
					++$kept;
					continue;
				}

				try {
					$was_deleted = $this->environment->delete_managed_listing(
						(int) $item['listing']['id'],
						(string) $item['listing']['listing_key'],
						(string) $item['reason']
					);
				} catch ( Throwable $exception ) {
					// Treat storage exceptions like a false delete result. The plan
					// is already complete, so later independent listings still run.
					$was_deleted = false;
				}

				if ( $was_deleted ) {
					++$deleted;
				} else {
					// A failed destructive operation is isolated to this listing;
					// continue applying the already-validated remainder of the plan.
					++$failed;
				}
			}

			if ( $failed > 0 ) {
				$this->environment->schedule_retry( self::RETRY_DELAY_SECONDS );
				return $this->outcome( 'partial', 'deletion_failed', $kept, $deleted, $failed );
			}

			return $this->outcome( 'completed', 'reconciliation_completed', $kept, $deleted );
		} finally {
			$this->environment->release_lock();
		}
	}

	/**
	 * Build the stable public result shape returned for every run status.
	 *
	 * @param string $status  Completed, partial, aborted, postponed, or already_running.
	 * @param string $reason  Stable machine-readable outcome reason.
	 * @param int    $kept    Number of planned keeps.
	 * @param int    $deleted Number of successful deletions.
	 * @param int    $failed  Number of failed planned deletions.
	 * @return array<string, int|string>
	 */
	private function outcome( string $status, string $reason, int $kept = 0, int $deleted = 0, int $failed = 0 ): array {
		return array(
			'status'  => $status,
			'reason'  => $reason,
			'kept'    => $kept,
			'deleted' => $deleted,
			'failed'  => $failed,
		);
	}
}
