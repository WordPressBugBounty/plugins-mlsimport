<?php
/**
 * Reconciliation feed sanity guard.
 *
 * Pure function (no WordPress dependency) so it can be unit tested in
 * isolation. Reconciliation deletes every local listing absent from the MLS
 * feed; a well-formed but truncated feed would therefore trigger a mass
 * deletion. This decides whether the feed is a plausible size before any
 * deletion is allowed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/** Feed must carry at least this fraction of the local ListingKey count. */
const MLSIMPORT_RECONCILIATION_MIN_FEED_FRACTION = 0.8;

/**
 * Whether the reconciliation feed is a plausible size relative to the local set.
 *
 * @param int   $feed_count   Number of ListingKeys returned by the MLS feed.
 * @param int   $local_count  Number of local listings carrying a ListingKey.
 * @return bool True when reconciliation may proceed.
 */
function mlsimport_reconciliation_feed_is_plausible( int $feed_count, int $local_count ): bool {
	// With no local listings there is nothing to reconcile against; refuse.
	if ( $local_count <= 0 ) {
		return false;
	}
	// Plausible only when the feed covers at least the required fraction of locals.
	return $feed_count >= $local_count * MLSIMPORT_RECONCILIATION_MIN_FEED_FRACTION;
}
