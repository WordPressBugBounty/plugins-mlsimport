<?php
/**
 * Live mode: transient cache for direct MLS reads.
 *
 * One idiom: mlsimport_live_remember( $key, $fetch ). Each entry stores its
 * payload plus a freshness deadline inside a transient that lives a full day,
 * so an MLS outage serves warm-stale data instead of an empty page. Keys hash
 * the normalized request (sorted params, floats rounded) so map pans and
 * equivalent filter orders reuse entries. Cleanup rides WP's daily
 * expired-transient purge; "Clear live cache" bumps a generation salt so
 * every keyed entry is retired at once — no options scan, no object-cache
 * flush (which would evict unrelated site cache on persistent backends).
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a cache key from a normalized request description.
 *
 * @param string $group Short group tag (search, get, …).
 * @param array  $parts Request-defining values (params + config essentials).
 * @return string
 */
function mlsimport_live_cache_key( string $group, array $parts ): string {
	// Group tag + md5 of (generation salt | normalized request). The salt makes
	// a cache-clear retire every key; normalization makes equivalent requests
	// collapse to one key.
	return 'mlsimport_live_' . $group . '_' . md5(
		mlsimport_live_cache_version() . '|' . (string) wp_json_encode( mlsimport_live_cache_normalize( $parts ) )
	);
}

/**
 * The cache generation salt baked into every keyed entry. Bumping it retires
 * all keyed entries at once; the orphaned rows expire with their day TTL.
 *
 * @return int
 */
function mlsimport_live_cache_version(): int {
	// Read the salt option; floor at 1 so a missing/zeroed option is still valid.
	return max( 1, (int) get_option( 'mlsimport_live_cache_version', 1 ) );
}

/**
 * Normalize request values for stable cache keys: recursive key sort and
 * floats rounded to 3 decimals (~110m) so tiny map-pan deltas share entries.
 *
 * @param array $parts Request values.
 * @return array
 */
function mlsimport_live_cache_normalize( array $parts ): array {
	$out = array();
	// Walk every value, normalizing in place.
	foreach ( $parts as $key => $value ) {
		if ( is_array( $value ) ) {
			// Nested arrays (e.g. multi-value filters): recurse.
			$out[ $key ] = mlsimport_live_cache_normalize( $value );
		} elseif ( is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) && false !== strpos( $value, '.' ) ) ) {
			// A real float, or a numeric string carrying a decimal point (map
			// coords arrive as strings): round to 3dp so tiny pan deltas match.
			$out[ $key ] = round( (float) $value, 3 );
		} else {
			// Ints, bools, plain strings: keep verbatim.
			$out[ $key ] = $value;
		}
	}
	// Sort by key so param order never changes the resulting key.
	ksort( $out );
	return $out;
}

/**
 * Return the cached value for $key, refreshing it via $fetch when stale.
 *
 * $fetch returning null means "the source failed" — the stale payload (when
 * one exists) is served instead and retried on the next request.
 *
 * @param string   $key   Cache key from mlsimport_live_cache_key().
 * @param callable $fetch Returns the fresh value, or null on failure.
 * @return mixed Null only when there is no fresh value and no stale copy.
 */
function mlsimport_live_remember( string $key, callable $fetch ) {
	// Load any existing entry and pin "now" for the freshness comparison.
	$entry = get_transient( $key );
	$now   = time();

	// Fresh hit: a well-formed entry still inside its freshness window — return
	// the payload without touching the source.
	if ( is_array( $entry ) && array_key_exists( 'data', $entry ) && isset( $entry['fresh_until'] ) && $entry['fresh_until'] >= $now ) {
		return $entry['data'];
	}

	// Miss or stale: go to the source.
	$fresh = $fetch();
	if ( null !== $fresh ) {
		// Success: store payload + a fresh-until deadline, but let the whole
		// row live a full day so it can still be served warm-stale after.
		set_transient(
			$key,
			array(
				'data'        => $fresh,
				'fresh_until' => $now + mlsimport_live_cache_ttl(),
			),
			DAY_IN_SECONDS
		);
		return $fresh;
	}

	// Source failed: serve warm-stale when we have it.
	if ( is_array( $entry ) && array_key_exists( 'data', $entry ) ) {
		return $entry['data'];
	}

	// No fresh value and no stale copy: the caller gets nothing.
	return null;
}

/**
 * Retire every live-mode cache entry (the settings screen button).
 *
 * Keyed entries are invalidated by bumping the generation salt. The handful
 * of fixed-name transients (the entitlement flag, price ceiling, provider
 * tokens) are deleted directly — the API also evicts them from persistent
 * object caches.
 *
 * @return void
 */
function mlsimport_live_cache_clear(): void {
	// Bump the generation salt: every keyed entry now hashes to a new key and
	// is effectively retired (the orphans expire with their day TTL).
	update_option( 'mlsimport_live_cache_version', mlsimport_live_cache_version() + 1 );

	// Fixed-name shared transients aren't keyed by the salt, so delete them by
	// hand. The Provider Family module owns the provider-token list.
	$fixed = array( 'mlsimport_live_entitlement_checked', 'mlsimport_live_price_ceiling' );
	// Delete each — this also evicts it from a persistent object cache.
	foreach ( $fixed as $name ) {
		delete_transient( $name );
	}
	Mlsimport_Provider_Family::clear_direct_access_tokens();
}
