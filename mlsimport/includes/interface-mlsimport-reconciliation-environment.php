<?php
/**
 * System-boundary contract for the deep reconciliation module.
 *
 * Implementations provide infrastructure behavior only. Snapshot validation,
 * keep/delete policy, complete planning, and failure outcomes remain inside
 * Mlsimport_Reconciliation.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies external operations used by one Reconciliation Run.
 */
interface Mlsimport_Reconciliation_Environment {

	/**
	 * Claim the singleton reconciliation lock.
	 *
	 * @return bool True when the caller owns the lock.
	 */
	public function acquire_lock(): bool;

	/**
	 * Release the singleton lock owned by this environment instance.
	 *
	 * @return void
	 */
	public function release_lock(): void;

	/**
	 * Report whether a manual import or hourly sync is active.
	 *
	 * @return bool True when reconciliation must postpone.
	 */
	public function import_or_sync_is_active(): bool;

	/**
	 * Fetch the raw Reconciliation Snapshot response.
	 *
	 * @return array<string, mixed> Raw response contract.
	 */
	public function fetch_snapshot(): array;

	/**
	 * Read the complete active Managed Listing inventory.
	 *
	 * @return array<int, array<string, mixed>> Managed Listing records.
	 */
	public function read_managed_listings(): array;

	/**
	 * Delete one planned Managed Listing and record successful activity.
	 *
	 * @param int    $listing_id  Property post ID.
	 * @param string $listing_key ListingKey validated during planning.
	 * @param string $reason      Stable successful-deletion reason.
	 * @return bool True only when deletion completed.
	 */
	public function delete_managed_listing( int $listing_id, string $listing_key, string $reason ): bool;

	/**
	 * Schedule a deduplicated retry after the requested delay.
	 *
	 * @param int $delay_seconds Delay from current time.
	 * @return void
	 */
	public function schedule_retry( int $delay_seconds ): void;
}
