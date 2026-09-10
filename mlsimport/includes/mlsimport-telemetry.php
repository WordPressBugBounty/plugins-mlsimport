<?php
/**
 * MLSImport Daily Telemetry Heartbeat
 *
 * Accumulates per-request import/sync counters in memory, flushes them once per
 * request (on `shutdown`) into rolling daily wp_options buckets, and POSTs a
 * structured heartbeat payload to the SaaS `user-activity` endpoint once per UTC day.
 *
 * Procedural include — matches the style of help_functions.php and
 * mlsimport-onboarding.php. No class wrapper.
 *
 * @link       https://mlsimport.com/
 * @since      6.3.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// WordPress defines DAY_IN_SECONDS = 86400; provide a fallback for unit-test
// environments that load this file without the full WP bootstrap.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// WordPress defines MINUTE_IN_SECONDS = 60; same fallback pattern.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// ---------------------------------------------------------------------------
// Request-scoped accumulator
// ---------------------------------------------------------------------------

/**
 * In-memory counter deltas for the current request, nested per connection:
 * mls_id => (imported | updated | deleted | syncs | token_failures => delta).
 * mls_id 0 holds unattributed (account-level) deltas — issue #283.
 * Written to wp_options exactly once — on shutdown — by mlsimport_telemetry_flush().
 *
 * @var array<int,array<string,int>>
 */
$mlsimport_telemetry_pending = array();

// ---------------------------------------------------------------------------
// §1 Public API — counter accumulator
// ---------------------------------------------------------------------------

/**
 * Add an in-memory counter delta for the current request.
 * Allowed $metric: 'imported' | 'updated' | 'deleted' | 'syncs' | 'token_failures'.
 * No DB access — deltas are written to wp_options once, on shutdown, by flush().
 *
 * Multi-MLS (issue #283): callers pass the connection the activity belongs to
 * (they have it in hand from the task binding). Flush folds every delta into
 * the unchanged GLOBAL daily bucket AND, for a positive id, into that
 * connection's own bucket — so global sums stay the sum of the per-connection
 * buckets. mls_id 0 = account-level activity with no owning connection
 * (e.g. SaaS token refresh failures), counted globally only.
 *
 * @param string $metric One of the five allowed metric keys.
 * @param int    $amount Amount to add (default 1).
 * @param int    $mls_id Connection the activity belongs to (0 = unattributed).
 * @return void
 */
function mlsimport_telemetry_bump( string $metric, int $amount = 1, int $mls_id = 0 ): void {
	// Whitelist of accepted metric keys.
	$allowed = array( 'imported', 'updated', 'deleted', 'syncs', 'token_failures' );
	// Guard: silently ignore an unknown metric key.
	if ( ! in_array( $metric, $allowed, true ) ) {
		return;
	}
	// Reach the request-scoped accumulator.
	global $mlsimport_telemetry_pending;
	// Normalize a negative id to the unattributed slot.
	$mls_id = max( 0, $mls_id );
	// Lazily zero-initialise this connection+metric slot on first use.
	if ( ! isset( $mlsimport_telemetry_pending[ $mls_id ][ $metric ] ) ) {
		$mlsimport_telemetry_pending[ $mls_id ][ $metric ] = 0;
	}
	// Add the delta (no DB touch here — flush writes on shutdown).
	$mlsimport_telemetry_pending[ $mls_id ][ $metric ] += $amount;
}

// ---------------------------------------------------------------------------
// §1 Public API — flush (registered on 'shutdown')
// ---------------------------------------------------------------------------

/**
 * Fold one connection's pending deltas into one daily-bucket map. Pure.
 *
 * Step by step:
 * 1. Zero-base today's bucket for all five counters (keeping accumulated values).
 * 2. Add each pending delta into its counter.
 * 3. Prune buckets older than the retention window.
 *
 * Shared by flush() for the GLOBAL map ('daily') and every per-connection
 * map ('daily_mls'[mls_id]) so both fold the same one way (issue #283).
 *
 * @param array  $daily   Daily bucket map (YYYY-MM-DD => counters).
 * @param array  $pending Metric => delta for this request.
 * @param string $today   Today's UTC date 'Y-m-d'.
 * @return array The updated, pruned daily map.
 */
function mlsimport_telemetry_fold_bucket( array $daily, array $pending, string $today ): array {
	// Step 1: zero-base for all five counters in today's bucket.
	$bucket = array_merge(
		array(
			'imported'       => 0,
			'updated'        => 0,
			'deleted'        => 0,
			'syncs'          => 0,
			'token_failures' => 0,
		),
		isset( $daily[ $today ] ) && is_array( $daily[ $today ] ) ? $daily[ $today ] : array()
	);

	// Step 2: fold this request's deltas into the bucket.
	foreach ( $pending as $metric => $delta ) {
		if ( isset( $bucket[ $metric ] ) ) {
			$bucket[ $metric ] += $delta;
		}
	}

	// Step 3: store the bucket, drop buckets past the retention window.
	$daily[ $today ] = $bucket;
	return mlsimport_telemetry_prune_buckets( $daily, $today );
}

/**
 * Flush accumulated counter deltas into today's daily buckets.
 * No-op when nothing is pending. Reads + writes the single option
 * 'mlsimport_telemetry_state' exactly once, prunes buckets older than 8 days,
 * resets the pending array. Registered on the 'shutdown' action.
 *
 * Multi-MLS (issue #283): pending deltas arrive nested per connection.
 * Every delta folds into the unchanged GLOBAL 'daily' map; a positive
 * connection id additionally folds into that connection's own map under
 * 'daily_mls' — so the global 7-day sums equal the sum of the per-connection
 * buckets by construction. A connection whose fold carried import activity
 * (imported/updated/deleted) also gets its 'connection_last_import' stamp.
 *
 * @return void
 */
function mlsimport_telemetry_flush(): void {
	// Reach the request-scoped accumulator.
	global $mlsimport_telemetry_pending;

	// Nothing accumulated this request — do not read or write the option.
	if ( empty( $mlsimport_telemetry_pending ) ) {
		return;
	}

	// Load the persisted state; coerce a corrupt/legacy value back to an array.
	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}

	// Ensure the global and per-connection bucket maps exist.
	if ( ! isset( $state['daily'] ) || ! is_array( $state['daily'] ) ) {
		$state['daily'] = array();
	}
	if ( ! isset( $state['daily_mls'] ) || ! is_array( $state['daily_mls'] ) ) {
		$state['daily_mls'] = array();
	}

	// Today's UTC date is the bucket key everywhere.
	$today = gmdate( 'Y-m-d' );

	// Fold every connection's deltas — each connection once, globals once each.
	foreach ( $mlsimport_telemetry_pending as $mls_id => $pending ) {
		// Every delta counts globally (legacy fields unchanged).
		$state['daily'] = mlsimport_telemetry_fold_bucket( $state['daily'], $pending, $today );

		// Unattributed (account-level) deltas stop at the global map.
		if ( $mls_id <= 0 ) {
			continue;
		}

		// This connection's own bucket map.
		$mls_daily = is_array( $state['daily_mls'][ $mls_id ] ?? null ) ? $state['daily_mls'][ $mls_id ] : array();
		$state['daily_mls'][ $mls_id ] = mlsimport_telemetry_fold_bucket( $mls_daily, $pending, $today );

		// Import activity stamps this connection's last-import time (#283) —
		// syncs/token ticks alone are not imports and do not move it.
		$activity = (int) ( $pending['imported'] ?? 0 ) + (int) ( $pending['updated'] ?? 0 ) + (int) ( $pending['deleted'] ?? 0 );
		if ( $activity > 0 ) {
			if ( ! isset( $state['connection_last_import'] ) || ! is_array( $state['connection_last_import'] ) ) {
				$state['connection_last_import'] = array();
			}
			$state['connection_last_import'][ $mls_id ] = time();
		}
	}

	// Single write, non-autoloaded.
	update_option( 'mlsimport_telemetry_state', $state, false );

	// Reset pending.
	$mlsimport_telemetry_pending = array();
}

add_action( 'shutdown', 'mlsimport_telemetry_flush' );

// ---------------------------------------------------------------------------
// §1 Public API — immediate key setter
// ---------------------------------------------------------------------------

/**
 * Set a non-counter "last X" field on mlsimport_telemetry_state.
 * $key ∈ last_sync_success | last_sync_failed | last_sync_failed_code |
 *        last_sync_attempt | last_feed_found | last_admin_load | last_import_task_load.
 * Immediate small read-modify-write; option saved with autoload = 'no'.
 *
 * @param string $key   The state key to set.
 * @param mixed  $value The value to store.
 * @return void
 */
function mlsimport_telemetry_set( string $key, $value ): void {
	// Load the persisted state; coerce a non-array back to an array.
	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
	// Overwrite the key unconditionally, then persist (non-autoloaded).
	$state[ $key ] = $value;
	update_option( 'mlsimport_telemetry_state', $state, false );
}

/**
 * Set a "first time only" lifecycle stamp on mlsimport_telemetry_state.
 * Unlike mlsimport_telemetry_set(), this is a no-op when $key already holds a
 * non-empty value — the first occurrence wins. Used for installed_at /
 * account_connected_at / mls_connected_at. Saved with autoload = 'no'.
 *
 * @param string $key   The state key to set once.
 * @param mixed  $value The value to store on the first call.
 * @return void
 */
function mlsimport_telemetry_set_once( string $key, $value ): void {
	// Load the persisted state; coerce a non-array back to an array.
	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
	// First occurrence wins — bail if the stamp already holds a non-empty value.
	if ( ! empty( $state[ $key ] ) ) {
		return;
	}
	// Record the value and persist (non-autoloaded).
	$state[ $key ] = $value;
	update_option( 'mlsimport_telemetry_state', $state, false );
}

/**
 * Record the outcome of one listings request into sync-health telemetry
 * (GitHub issue #207).
 *
 * Called from the single choke point every import path routes through
 * (Mlsimport_Admin::mlsimport_make_listing_requests()), with the already
 * normalized API answer. Stamps last_sync_success when the pull returned a
 * feed, so a cron run that dies later in its loop still leaves fresh
 * success evidence — the previous end-of-loop-only stamp left actively
 * syncing sites reporting last_successful_sync = "never".
 *
 * Multi-MLS (issue #283): the caller passes the connection the pull ran for
 * (in hand from the task binding). Each pull ticks that connection's 'syncs'
 * counter, and the outcome is additionally stamped into the per-connection
 * success/failure maps — the GLOBAL sync_health stamps stay exactly as before.
 *
 * @param mixed $answer The normalized listings API answer array.
 * @param int   $mls_id Connection the pull ran for (0 = unattributed).
 * @return void
 */
function mlsimport_telemetry_record_sync_result( $answer, int $mls_id = 0 ): void {
	// One pull = one sync tick, counted against its own connection (#283).
	// This is also what makes syncs_last_7_days a live counter again.
	mlsimport_telemetry_bump( 'syncs', 1, $mls_id );

	// A successful pull always carries the feed count under 'results'.
	if ( is_array( $answer ) && isset( $answer['results'] ) ) {
		mlsimport_telemetry_set( 'last_sync_success', time() );
		// Per-connection success stamp (#283).
		if ( $mls_id > 0 ) {
			mlsimport_telemetry_record_connection_sync( $mls_id, true );
		}
		return;
	}
	// Anything else is a failed pull: stamp when it happened and a real
	// failure class — previously every failure surfaced as "unknown".
	$code = mlsimport_telemetry_classify_sync_failure( $answer );
	mlsimport_telemetry_set( 'last_sync_failed', time() );
	mlsimport_telemetry_set( 'last_sync_failed_code', $code );
	// Per-connection failure stamp (#283).
	if ( $mls_id > 0 ) {
		mlsimport_telemetry_record_connection_sync( $mls_id, false, $code );
	}
}

/**
 * Map a failed listings answer to a short failure class for
 * sync_health.last_failure_code. Pure — inspects only the answer shape and
 * the message strings globalApiRequestCurlSaas() actually produces.
 *
 * @param mixed $answer The normalized failed listings API answer.
 * @return string One of the short failure-class codes.
 */
function mlsimport_telemetry_classify_sync_failure( $answer ): string {
	// A provider-rule rejection already carries a machine code under 'type'
	// (set by mlsimport_make_listing_requests()) — pass it through as-is.
	if ( is_array( $answer ) && ! empty( $answer['type'] ) ) {
		return (string) $answer['type'];
	}
	$message = is_array( $answer ) && isset( $answer['message'] ) ? (string) $answer['message'] : '';
	// The exact string ThemeImport returns when the SaaS JWT cannot be
	// minted/refreshed (bad account credentials, token endpoint down).
	if ( 'Token validation failed' === $message ) {
		return 'token';
	}
	// WP_Error transport messages pass through verbatim; cURL timeouts read
	// 'cURL error 28: Operation timed out after N milliseconds ...'.
	if ( false !== stripos( $message, 'timed out' ) ) {
		return 'timeout';
	}
	// AWS API Gateway rejections decode to {"message":"Unauthorized"} /
	// {"message":"Forbidden"} with no 'results' key.
	if ( false !== stripos( $message, 'unauthorized' ) || false !== stripos( $message, 'forbidden' ) ) {
		return 'auth';
	}
	return 'api_error';
}

/**
 * Record the first-completion time of an onboarding-wizard step into the
 * 'onboarding_steps' map on mlsimport_telemetry_state. First completion wins;
 * re-running a step does not move the timestamp. Saved with autoload = 'no'.
 *
 * @param string $step The onboarding step ID (e.g. 'account', 'field-mapping').
 * @return void
 */
function mlsimport_telemetry_mark_onboarding_step( string $step ): void {
	// Guard: ignore an empty step id.
	if ( '' === $step ) {
		return;
	}
	// Load the persisted state; coerce a non-array back to an array.
	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
	// Ensure the onboarding-steps map exists.
	if ( ! isset( $state['onboarding_steps'] ) || ! is_array( $state['onboarding_steps'] ) ) {
		$state['onboarding_steps'] = array();
	}
	// First completion wins — do not move an existing timestamp.
	if ( isset( $state['onboarding_steps'][ $step ] ) ) {
		return;
	}
	// Stamp the step with the current epoch and persist (non-autoloaded).
	$state['onboarding_steps'][ $step ] = time();
	update_option( 'mlsimport_telemetry_state', $state, false );
}

// ---------------------------------------------------------------------------
// §1 Import performance snapshot (GitHub issue #216)
// ---------------------------------------------------------------------------

/**
 * Build the import-performance snapshot for one finished Import Run. Pure.
 *
 * Answers support's "is it us or the host?" question from data the run
 * machinery already tracks:
 * - elapsed_seconds: wall time from the run's started_at to its finish, across
 *   every chunk worker — not just the finishing request.
 * - workers: 1 + chunk hand-offs + watchdog revivals. Any revival means a
 *   worker died without handing off, i.e. the host killed it.
 * - queue_depth: pending worker actions at finish — backlog evidence.
 * - peak_memory_mb: peak PHP memory of the finishing worker.
 *
 * @param array $run               Final Import Run record (started_at, source,
 *                                 expected, handoffs, revive_count).
 * @param array $result            Final public Import Run Result.
 * @param int   $now               Finish time (Unix epoch).
 * @param int   $peak_memory_bytes memory_get_peak_usage(true) of the finisher.
 * @param int   $queue_depth       Pending worker actions for the import hook.
 * @return array<string,int|string> The snapshot stored under 'last_import_run'.
 */
function mlsimport_telemetry_import_run_snapshot( array $run, array $result, int $now, int $peak_memory_bytes, int $queue_depth ): array {
	// Wall time across the whole worker chain; guard against a missing or
	// future started_at leaving a negative duration.
	$started_at = (int) ( $run['started_at'] ?? $now );
	return array(
		'source'          => (string) ( $run['source'] ?? '' ),
		'state'           => (string) ( $result['state'] ?? '' ),
		'expected'        => (int) ( $run['expected'] ?? 0 ),
		'saved'           => (int) ( $result['saved'] ?? 0 ),
		'failed'          => (int) ( $result['failed'] ?? 0 ),
		'elapsed_seconds' => max( 0, $now - $started_at ),
		// One initial worker, plus one per chunk hand-off, plus one per
		// watchdog revival (a revival is a worker the host killed).
		'workers'         => 1 + (int) ( $run['handoffs'] ?? 0 ) + (int) ( $run['revive_count'] ?? 0 ),
		'peak_memory_mb'  => (int) round( $peak_memory_bytes / 1048576 ),
		'queue_depth'     => $queue_depth,
		'finished_at'     => $now,
	);
}

// ---------------------------------------------------------------------------
// §1 Pure helpers
// ---------------------------------------------------------------------------

/**
 * Positive epoch -> "Y-m-d\TH:i:s\Z" (UTC). 0 / empty -> null. Pure.
 *
 * @param int $epoch Unix timestamp.
 * @return string|null ISO 8601 UTC string or null.
 */
function mlsimport_telemetry_iso( int $epoch ): ?string {
	// Non-positive epoch means "never" — represent as null.
	if ( $epoch <= 0 ) {
		return null;
	}
	// Format the epoch as an ISO 8601 UTC string.
	return gmdate( 'Y-m-d\TH:i:s\Z', $epoch );
}

/**
 * Drop daily-bucket keys older than $keep_days relative to $today. Pure.
 *
 * @param array  $daily     Daily bucket map (YYYY-MM-DD => array).
 * @param string $today     Reference date string 'Y-m-d'.
 * @param int    $keep_days Number of days to keep (default 8).
 * @return array Pruned daily map.
 */
function mlsimport_telemetry_prune_buckets( array $daily, string $today, int $keep_days = 8 ): array {
	// Compute the oldest date to keep (today minus the retention window).
	$cutoff = gmdate( 'Y-m-d', strtotime( $today ) - ( $keep_days * DAY_IN_SECONDS ) );
	// Drop any bucket whose date string sorts before the cutoff.
	foreach ( array_keys( $daily ) as $date ) {
		if ( $date < $cutoff ) {
			unset( $daily[ $date ] );
		}
	}
	return $daily;
}

/**
 * Sum the last $days daily buckets ending at $today.
 * Returns [ 'imported'=>int, 'updated'=>int, 'deleted'=>int, 'syncs'=>int,
 *           'token_failures'=>int ]. Pure.
 *
 * @param array  $daily Daily bucket map.
 * @param string $today Reference date string 'Y-m-d'.
 * @param int    $days  Number of days to sum (default 7).
 * @return array<string,int> Summed counters.
 */
function mlsimport_telemetry_sum_buckets( array $daily, string $today, int $days = 7 ): array {
	$sums = array(
		'imported'       => 0,
		'updated'        => 0,
		'deleted'        => 0,
		'syncs'          => 0,
		'token_failures' => 0,
	);

	// Walk back $days days from $today, accumulating each present bucket.
	for ( $i = 0; $i < $days; $i++ ) {
		// The date for this step back from today.
		$date = gmdate( 'Y-m-d', strtotime( $today ) - ( $i * DAY_IN_SECONDS ) );
		// Skip a missing or malformed bucket.
		if ( ! isset( $daily[ $date ] ) || ! is_array( $daily[ $date ] ) ) {
			continue;
		}
		// Add each counter this bucket carries into the running totals.
		foreach ( $sums as $key => $_ ) {
			if ( isset( $daily[ $date ][ $key ] ) ) {
				$sums[ $key ] += (int) $daily[ $date ][ $key ];
			}
		}
	}

	return $sums;
}

/**
 * True when $last_sent equals $today (UTC 'Y-m-d' strings). Pure.
 *
 * @param string $last_sent Previously stored send date.
 * @param string $today     Today's UTC date.
 * @return bool
 */
function mlsimport_telemetry_already_sent_today( string $last_sent, string $today ): bool {
	return $last_sent === $today;
}

// ---------------------------------------------------------------------------
// §1 Completeness sampler
// ---------------------------------------------------------------------------

/**
 * Per-theme meta keys used for data-completeness checks.
 * Keys: price, address, coordinate.
 *
 * WpResidence / WpEstate: use shared RESO-mapped meta names.
 * Houzez: coordinates are stored in a combined `fave_property_location` meta.
 * RealHomes: coordinates are stored in `REAL_HOMES_property_location`.
 *
 * @return array<string,array<string,string>>
 */
function mlsimport_telemetry_theme_meta_map(): array {
	return array(
		// WpResidence (991) and WpEstate (994) share the same RESO-mapped meta names.
		'ResidenceClass' => array(
			'price'      => 'property_price',
			'address'    => 'property_address',
			'coordinate' => 'property_latitude',
		),
		'EstateClass'    => array(
			'price'      => 'property_price',
			'address'    => 'property_address',
			'coordinate' => 'property_latitude',
		),
		// Houzez (992): uses fave_property_location for combined lat,lng.
		'HouzezClass'    => array(
			'price'      => 'property_price',
			'address'    => 'property_address',
			'coordinate' => 'fave_property_location',
		),
		// RealHomes (993): uses REAL_HOMES_property_location for combined lat,lng.
		'RealHomesClass' => array(
			'price'      => 'property_price',
			'address'    => 'property_address',
			'coordinate' => 'REAL_HOMES_property_location',
		),
	);
}

/**
 * Sample the 20 most-recent property posts holding a 'ListingKey' meta.
 * Returns [ 'with_photos_percent'=>int, 'with_price_percent'=>int,
 *           'with_address_percent'=>int, 'with_coordinates_percent'=>int ]
 * (integer percentages 0-100; all 0 when the sample is empty).
 *
 * @return array<string,int>
 */
function mlsimport_telemetry_sample_completeness(): array {
	$empty = array(
		'with_photos_percent'      => 0,
		'with_price_percent'       => 0,
		'with_address_percent'     => 0,
		'with_coordinates_percent' => 0,
	);

	// Resolve the active theme adapter class.
	global $mlsimport;
	$env_class = '';
	if (
		isset( $mlsimport ) &&
		isset( $mlsimport->admin ) &&
		isset( $mlsimport->admin->env_data ) &&
		is_object( $mlsimport->admin->env_data )
	) {
		$env_class = get_class( $mlsimport->admin->env_data );
	}

	$meta_map = mlsimport_telemetry_theme_meta_map();
	// Default fallback — shared RESO keys used by WpResidence / WpEstate.
	$keys = isset( $meta_map[ $env_class ] )
		? $meta_map[ $env_class ]
		: array(
			'price'      => 'property_price',
			'address'    => 'property_address',
			'coordinate' => 'property_latitude',
		);

	// Determine the post type from the adapter; fall back to 'estate_property'.
	$post_type = 'estate_property';
	if (
		isset( $mlsimport ) &&
		isset( $mlsimport->admin ) &&
		isset( $mlsimport->admin->env_data ) &&
		is_object( $mlsimport->admin->env_data ) &&
		method_exists( $mlsimport->admin->env_data, 'get_property_post_type' )
	) {
		$post_type = $mlsimport->admin->env_data->get_property_post_type();
	}

	// Fetch the 20 most-recent posts that have a ListingKey meta.
	$args = array(
		'post_type'      => $post_type,
		'post_status'    => 'any',
		'posts_per_page' => 20,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'meta_query'     => array(
			array(
				'key'     => '_mlsimport_listing_key',
				'compare' => 'EXISTS',
			),
		),
		'no_found_rows'  => true,
		// Telemetry samples STORED listings; dedupe-hidden copies (#282) are
		// stored and must count.
		'mlsimport_include_hidden' => true,
	);

	// Run the query (guard for environments without get_posts()).
	$post_ids = function_exists( 'get_posts' ) ? get_posts( $args ) : array();

	// No sample — return all-zero percentages.
	if ( empty( $post_ids ) ) {
		return $empty;
	}

	// Denominator + per-field hit counters.
	$total       = count( $post_ids );
	$photos      = 0;
	$price       = 0;
	$address     = 0;
	$coordinates = 0;

	// Tally how many sampled posts carry each field.
	foreach ( $post_ids as $pid ) {
		// Featured image present?
		if ( has_post_thumbnail( $pid ) ) {
			$photos++;
		}
		// Price meta non-empty?
		if ( '' !== get_post_meta( $pid, $keys['price'], true ) ) {
			$price++;
		}
		// Address meta non-empty?
		if ( '' !== get_post_meta( $pid, $keys['address'], true ) ) {
			$address++;
		}
		// Coordinate meta non-empty?
		if ( '' !== get_post_meta( $pid, $keys['coordinate'], true ) ) {
			$coordinates++;
		}
	}

	// Convert each tally to an integer 0-100 percentage of the sample.
	return array(
		'with_photos_percent'      => (int) round( $photos / $total * 100 ),
		'with_price_percent'       => (int) round( $price / $total * 100 ),
		'with_address_percent'     => (int) round( $address / $total * 100 ),
		'with_coordinates_percent' => (int) round( $coordinates / $total * 100 ),
	);
}

// ---------------------------------------------------------------------------
// §1 Payload collector
// ---------------------------------------------------------------------------

/**
 * Build the full human-readable heartbeat payload (see §5 for the shape).
 * Converts stored epochs to ISO 8601 via mlsimport_telemetry_iso().
 * Generates + persists mlsimport_admin_options['mlsimport_install_uuid'] if absent.
 *
 * @return array The structured heartbeat payload.
 */
function mlsimport_telemetry_collect_payload(): array {
	// --- Install UUID ---
	$opts = get_option( 'mlsimport_admin_options', array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	if ( empty( $opts['mlsimport_install_uuid'] ) ) {
		$opts['mlsimport_install_uuid'] = wp_generate_uuid4();
		update_option( 'mlsimport_admin_options', $opts );
	}

	// --- Telemetry state ---
	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
	$daily = isset( $state['daily'] ) && is_array( $state['daily'] ) ? $state['daily'] : array();

	$today  = gmdate( 'Y-m-d' );
	$sums   = mlsimport_telemetry_sum_buckets( $daily, $today, 7 );

	// --- sync_health ---
	$last_sync_success      = isset( $state['last_sync_success'] ) ? (int) $state['last_sync_success'] : 0;
	$last_sync_failed       = isset( $state['last_sync_failed'] ) ? (int) $state['last_sync_failed'] : 0;
	$last_sync_failed_code  = isset( $state['last_sync_failed_code'] ) ? (string) $state['last_sync_failed_code'] : '';
	$last_feed_found        = isset( $state['last_feed_found'] ) ? (int) $state['last_feed_found'] : 0;
	$last_admin_load        = isset( $state['last_admin_load'] ) ? (int) $state['last_admin_load'] : 0;
	$last_import_task_load  = isset( $state['last_import_task_load'] ) ? (int) $state['last_import_task_load'] : 0;

	// --- lifecycle / onboarding funnel ---
	$installed_at         = isset( $state['installed_at'] ) ? (int) $state['installed_at'] : 0;
	$account_connected_at = isset( $state['account_connected_at'] ) ? (int) $state['account_connected_at'] : 0;
	$mls_connected_at     = isset( $state['mls_connected_at'] ) ? (int) $state['mls_connected_at'] : 0;
	$last_field_mgmt      = isset( $state['last_field_management'] ) ? (int) $state['last_field_management'] : 0;
	$onboarding_steps     = array();
	if ( isset( $state['onboarding_steps'] ) && is_array( $state['onboarding_steps'] ) ) {
		foreach ( $state['onboarding_steps'] as $step_id => $step_epoch ) {
			$onboarding_steps[ (string) $step_id ] = mlsimport_telemetry_iso( (int) $step_epoch );
		}
	}

	// WP cron working: daily event is scheduled.
	if ( function_exists( 'wp_next_scheduled' ) ) {
		$wp_cron_working = ( false !== wp_next_scheduled( 'mlsimport_daily_telemetry_event' ) ) ||
		                   ( false !== wp_next_scheduled( 'event_mls_import_auto' ) );
	} else {
		$wp_cron_working = false;
	}

	// --- output: active listings ---
	$post_type = 'estate_property';
	global $mlsimport;
	if (
		isset( $mlsimport ) &&
		isset( $mlsimport->admin ) &&
		isset( $mlsimport->admin->env_data ) &&
		is_object( $mlsimport->admin->env_data ) &&
		method_exists( $mlsimport->admin->env_data, 'get_property_post_type' )
	) {
		$post_type = $mlsimport->admin->env_data->get_property_post_type();
	}

	$active_listings = 0;
	if ( class_exists( 'WP_Query' ) ) {
		$active_count_query = new WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		) );
		$active_listings = (int) $active_count_query->found_posts;
	}

	// --- data completeness ---
	$completeness = mlsimport_telemetry_sample_completeness();

	// --- import performance (issue #216) ---
	// The latest finished-run snapshot, recorded at finish_run(). Null means
	// no run has ever finished on this install — distinct from a missing field.
	$import_performance = null;
	if ( isset( $state['last_import_run'] ) && is_array( $state['last_import_run'] ) ) {
		$import_performance                = $state['last_import_run'];
		$import_performance['finished_at'] = mlsimport_telemetry_iso( (int) ( $import_performance['finished_at'] ?? 0 ) );
	}

	// --- configuration: import tasks ---
	$raw_tasks_query = function_exists( 'get_posts' ) ? get_posts( array(
		'post_type'      => 'mlsimport_item',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) ) : array();

	// --- connections registry (issue #283) ---
	// One record per registered MLS, priority-sorted (1 first). The
	// class_exists guard mirrors the get_posts/WP_Query guards above: legacy
	// unit harnesses load this file without the registry class.
	$connection_records = class_exists( 'Mlsimport_Connections' ) ? Mlsimport_Connections::all() : array();

	$import_tasks    = array();
	$auto_update_any = false;
	foreach ( $raw_tasks_query as $task_id ) {
		$how_many   = (int) get_post_meta( $task_id, 'mlsimport_item_how_many', true );
		$stat_cron  = (int) get_post_meta( $task_id, 'mlsimport_item_stat_cron', true );
		$auto_upd   = ( 1 === $stat_cron );
		if ( $auto_upd ) {
			$auto_update_any = true;
		}
		$import_tasks[] = array(
			'import_limit' => $how_many,
			'auto_update'  => $auto_upd,
		);
	}

	// Per-connection workload (issue #283): task/paused/listing counts per
	// connection, gathered by the module that owns the per-connection half
	// of the heartbeat. Skipped entirely on an empty registry (also keeps
	// legacy unit harnesses off the binding-module functions).
	$connection_workload = $connection_records
		? mlsimport_telemetry_gather_connection_workload( $connection_records, $raw_tasks_query, $post_type )
		: array();

	// --- MLS provider / ID ---
	// Legacy singular fields (decision #272): filled from the PRIORITY-1
	// connection so the current portal keeps working while it learns the
	// connections array. An empty registry keeps the pre-multi-MLS derivation.
	$mls_provider = '';
	$mls_id       = 0;
	if ( $connection_records ) {
		$priority_one = reset( $connection_records );
		$mls_id       = (int) $priority_one['mls_id'];
		$mls_provider = (string) $priority_one['provider_type'];
	} else {
		if ( isset( $opts['mlsimport_mls_name'] ) && '' !== $opts['mlsimport_mls_name'] ) {
			$mls_id = (int) $opts['mlsimport_mls_name'];
		}
		// Derive MLS provider label from the theme/MLS env class name if available.
		if (
			isset( $mlsimport ) &&
			isset( $mlsimport->admin ) &&
			isset( $mlsimport->admin->mls_env_data ) &&
			is_object( $mlsimport->admin->mls_env_data )
		) {
			$mls_class    = get_class( $mlsimport->admin->mls_env_data );
			$mls_provider = ( 'stdClass' !== $mls_class ) ? $mls_class : '';
		}
	}

	// Theme label.
	$theme_label = '';
	if (
		isset( $mlsimport ) &&
		isset( $mlsimport->admin ) &&
		isset( $mlsimport->admin->env_data ) &&
		is_object( $mlsimport->admin->env_data )
	) {
		$env_class   = get_class( $mlsimport->admin->env_data );
		$theme_label = ( 'stdClass' !== $env_class ) ? $env_class : '';
	}

	// The real plugin stores the account name under 'mlsimport_username'.
	// The unit test bootstrap seeds it under 'account' (legacy key).
	// Read both; prefer 'mlsimport_username' (canonical).
	if ( ! empty( $opts['mlsimport_username'] ) ) {
		$account = (string) $opts['mlsimport_username'];
	} elseif ( ! empty( $opts['account'] ) ) {
		$account = (string) $opts['account'];
	} else {
		$account = '';
	}

	return array(
		'event_type'    => 'daily_telemetry',
		'reported_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'install'       => array(
			'install_id' => (string) $opts['mlsimport_install_uuid'],
			'account'    => $account,
			'site_url'   => (string) home_url(),
		),
		'sync_health'   => array(
			'last_successful_sync'              => mlsimport_telemetry_iso( $last_sync_success ),
			'last_failed_sync'                  => mlsimport_telemetry_iso( $last_sync_failed ),
			'last_failure_code'                 => $last_sync_failed_code,
			'syncs_last_7_days'                 => (int) $sums['syncs'],
			'token_refresh_failures_last_7_days' => (int) $sums['token_failures'],
			'wp_cron_working'                   => (bool) $wp_cron_working,
		),
		'feed'          => array(
			'listings_found_in_feed' => $last_feed_found,
		),
		'output'        => array(
			'imported_last_7_days'   => (int) $sums['imported'],
			'updated_last_7_days'    => (int) $sums['updated'],
			'deleted_last_7_days'    => (int) $sums['deleted'],
			'active_listings_on_site' => $active_listings,
			'data_completeness'      => array(
				'with_photos_percent'      => (int) $completeness['with_photos_percent'],
				'with_price_percent'       => (int) $completeness['with_price_percent'],
				'with_address_percent'     => (int) $completeness['with_address_percent'],
				'with_coordinates_percent' => (int) $completeness['with_coordinates_percent'],
			),
		),
		'import_performance' => $import_performance,
		'engagement'    => array(
			'last_admin_page_view'       => mlsimport_telemetry_iso( $last_admin_load ),
			'last_import_task_page_view' => mlsimport_telemetry_iso( $last_import_task_load ),
		),
		'configuration' => array(
			'mls_provider'        => $mls_provider,
			'mls_id'              => $mls_id,
			'import_tasks'        => $import_tasks,
			'import_tasks_count'  => count( $import_tasks ),
			'auto_update_enabled' => (bool) $auto_update_any,
		),
		// Per-connection health (issue #283, decision #272): one entry per
		// registered connection, priority order; a single-connection install
		// sends the identical shape with a one-entry array.
		'connections'   => mlsimport_telemetry_connections_payload(
			$connection_records,
			$state,
			$today,
			$connection_workload
		),
		'environment'   => array(
			'plugin_version'    => defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : '',
			'php_version'       => PHP_VERSION,
			'wordpress_version' => get_bloginfo( 'version' ),
			'theme'             => $theme_label,
		),
		'lifecycle'     => array(
			'installed_at'          => mlsimport_telemetry_iso( $installed_at ),
			'account_connected_at'  => mlsimport_telemetry_iso( $account_connected_at ),
			'mls_connected_at'      => mlsimport_telemetry_iso( $mls_connected_at ),
			'last_field_management' => mlsimport_telemetry_iso( $last_field_mgmt ),
			'onboarding_steps'      => (object) $onboarding_steps,
		),
	);
}

// ---------------------------------------------------------------------------
// §1 Daily cron handler
// ---------------------------------------------------------------------------

/**
 * Daily cron handler. No-op if already sent today. Builds the payload and calls
 * ThemeImport::globalApiRequestSaasFireAndForget('user-activity', $payload) inside
 * try/catch(\Throwable). Sets mlsimport_telemetry_last_sent on a non-false return.
 * Hooked to 'mlsimport_daily_telemetry_event'.
 *
 * @return void
 */
function mlsimport_telemetry_run_daily(): void {
	$last_sent = (string) get_option( 'mlsimport_telemetry_last_sent', '' );
	$today     = gmdate( 'Y-m-d' );

	if ( mlsimport_telemetry_already_sent_today( $last_sent, $today ) ) {
		return;
	}

	$payload = mlsimport_telemetry_collect_payload();

	try {
		$result = ThemeImport::globalApiRequestSaasFireAndForget( 'user-activity', $payload );
	} catch ( \Throwable $e ) {
		// Fire-and-forget: transport errors are silently discarded.
		return;
	}

	if ( false !== $result ) {
		update_option( 'mlsimport_telemetry_last_sent', $today, false );
	}
}

// ---------------------------------------------------------------------------
// §1 Admin engagement tracker (registered on 'admin_init')
// ---------------------------------------------------------------------------

/**
 * Record admin-page engagement timestamps. Updates last_admin_load (and
 * last_import_task_load on the Import Task editor) only when the stored value is
 * older than 10 minutes. Hooked to 'admin_init'.
 *
 * Throttle logic (Pre-mortem Scenario 4):
 * - A missing stored timestamp is treated as epoch 0 (far in the past), so the
 *   very first admin page view writes once.
 * - Subsequent views within the 10-minute window do not write again.
 *
 * @return void
 */
function mlsimport_telemetry_track_admin_load(): void {
	// Only run on genuine admin requests — skip AJAX, CLI, cron.
	if ( ! is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}

	// Detect the screen from $pagenow + request vars. get_current_screen() is
	// not yet populated on 'admin_init', so a screen-object lookup misses every
	// real page load — $pagenow and $_GET are reliably set this early.
	global $pagenow;
	$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

	// Import Task list / editor — edit.php, post-new.php, or post.php for the
	// mlsimport_item CPT.
	$is_import_task_screen = (
		( ( 'edit.php' === $pagenow || 'post-new.php' === $pagenow ) && 'mlsimport_item' === $post_type ) ||
		( 'post.php' === $pagenow && isset( $_GET['post'] ) && 'mlsimport_item' === get_post_type( (int) $_GET['post'] ) )
	);

	// Any MLSImport admin screen — a plugin menu page or the import-task editor.
	$is_mlsimport_screen = ( $is_import_task_screen || 0 === strpos( $page, 'mlsimport' ) );

	if ( ! $is_mlsimport_screen ) {
		return;
	}

	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}

	$now        = time();
	$threshold  = 10 * MINUTE_IN_SECONDS; // 600 seconds.
	$did_write  = false;

	// Throttle: only update last_admin_load when stored value is older than 10 min.
	// A missing key defaults to 0, which is always older than 10 min — writes once.
	$stored_admin = isset( $state['last_admin_load'] ) ? (int) $state['last_admin_load'] : 0;
	if ( ( $now - $stored_admin ) >= $threshold ) {
		$state['last_admin_load'] = $now;
		$did_write                = true;
	}

	if ( $is_import_task_screen ) {
		$stored_task = isset( $state['last_import_task_load'] ) ? (int) $state['last_import_task_load'] : 0;
		if ( ( $now - $stored_task ) >= $threshold ) {
			$state['last_import_task_load'] = $now;
			$did_write                      = true;
		}
	}

	if ( $did_write ) {
		update_option( 'mlsimport_telemetry_state', $state, false );
	}
}

add_action( 'admin_init', 'mlsimport_telemetry_track_admin_load' );

/**
 * Record import-field management activity. Fires on the field-selector
 * progressive-save AJAX actions; throttled to one write per 10 minutes so a
 * burst of chunked field saves causes a single option write. autoload = 'no'.
 *
 * @return void
 */
function mlsimport_telemetry_track_field_management(): void {
	// Load the persisted state; coerce a non-array back to an array.
	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
	// Current time and the last-recorded field-management stamp (missing = 0).
	$now    = time();
	$stored = isset( $state['last_field_management'] ) ? (int) $state['last_field_management'] : 0;
	// Throttle: skip if the last write was under 10 minutes ago.
	if ( ( $now - $stored ) < 10 * MINUTE_IN_SECONDS ) {
		return;
	}
	// Record the activity and persist (non-autoloaded).
	$state['last_field_management'] = $now;
	update_option( 'mlsimport_telemetry_state', $state, false );
}

// The single compact mutation endpoint is the Field Configuration activity
// seam. Priority 1 records activity before validation/persistence runs.
add_action( 'wp_ajax_mlsimport_change_field_configuration', 'mlsimport_telemetry_track_field_management', 1 );
