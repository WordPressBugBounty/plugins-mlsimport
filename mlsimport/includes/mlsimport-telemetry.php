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
 * In-memory counter deltas for the current request.
 * Keys: imported | updated | deleted | syncs | token_failures.
 * Written to wp_options exactly once — on shutdown — by mlsimport_telemetry_flush().
 *
 * @var array<string,int>
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
 * @param string $metric One of the five allowed metric keys.
 * @param int    $amount Amount to add (default 1).
 * @return void
 */
function mlsimport_telemetry_bump( string $metric, int $amount = 1 ): void {
	$allowed = array( 'imported', 'updated', 'deleted', 'syncs', 'token_failures' );
	if ( ! in_array( $metric, $allowed, true ) ) {
		return;
	}
	global $mlsimport_telemetry_pending;
	if ( ! isset( $mlsimport_telemetry_pending[ $metric ] ) ) {
		$mlsimport_telemetry_pending[ $metric ] = 0;
	}
	$mlsimport_telemetry_pending[ $metric ] += $amount;
}

// ---------------------------------------------------------------------------
// §1 Public API — flush (registered on 'shutdown')
// ---------------------------------------------------------------------------

/**
 * Flush accumulated counter deltas into today's daily bucket.
 * No-op when nothing is pending. Reads + writes the single option
 * 'mlsimport_telemetry_state' exactly once, prunes buckets older than 8 days,
 * resets the pending array. Registered on the 'shutdown' action.
 *
 * @return void
 */
function mlsimport_telemetry_flush(): void {
	global $mlsimport_telemetry_pending;

	if ( empty( $mlsimport_telemetry_pending ) ) {
		return;
	}

	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}

	if ( ! isset( $state['daily'] ) || ! is_array( $state['daily'] ) ) {
		$state['daily'] = array();
	}

	$today  = gmdate( 'Y-m-d' );
	$bucket = isset( $state['daily'][ $today ] ) ? $state['daily'][ $today ] : array();

	// Initialise zero-base for all five counters in this bucket.
	$defaults = array(
		'imported'       => 0,
		'updated'        => 0,
		'deleted'        => 0,
		'syncs'          => 0,
		'token_failures' => 0,
	);
	$bucket = array_merge( $defaults, $bucket );

	foreach ( $mlsimport_telemetry_pending as $metric => $delta ) {
		if ( isset( $bucket[ $metric ] ) ) {
			$bucket[ $metric ] += $delta;
		}
	}

	$state['daily'][ $today ] = $bucket;
	$state['daily']           = mlsimport_telemetry_prune_buckets( $state['daily'], $today );

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
	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
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
	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
	if ( ! empty( $state[ $key ] ) ) {
		return;
	}
	$state[ $key ] = $value;
	update_option( 'mlsimport_telemetry_state', $state, false );
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
	if ( '' === $step ) {
		return;
	}
	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
	if ( ! isset( $state['onboarding_steps'] ) || ! is_array( $state['onboarding_steps'] ) ) {
		$state['onboarding_steps'] = array();
	}
	if ( isset( $state['onboarding_steps'][ $step ] ) ) {
		return;
	}
	$state['onboarding_steps'][ $step ] = time();
	update_option( 'mlsimport_telemetry_state', $state, false );
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
	if ( $epoch <= 0 ) {
		return null;
	}
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
	$cutoff = gmdate( 'Y-m-d', strtotime( $today ) - ( $keep_days * DAY_IN_SECONDS ) );
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

	for ( $i = 0; $i < $days; $i++ ) {
		$date = gmdate( 'Y-m-d', strtotime( $today ) - ( $i * DAY_IN_SECONDS ) );
		if ( ! isset( $daily[ $date ] ) || ! is_array( $daily[ $date ] ) ) {
			continue;
		}
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
				'key'     => 'ListingKey',
				'compare' => 'EXISTS',
			),
		),
		'no_found_rows'  => true,
	);

	$post_ids = function_exists( 'get_posts' ) ? get_posts( $args ) : array();

	if ( empty( $post_ids ) ) {
		return $empty;
	}

	$total       = count( $post_ids );
	$photos      = 0;
	$price       = 0;
	$address     = 0;
	$coordinates = 0;

	foreach ( $post_ids as $pid ) {
		if ( has_post_thumbnail( $pid ) ) {
			$photos++;
		}
		if ( '' !== get_post_meta( $pid, $keys['price'], true ) ) {
			$price++;
		}
		if ( '' !== get_post_meta( $pid, $keys['address'], true ) ) {
			$address++;
		}
		if ( '' !== get_post_meta( $pid, $keys['coordinate'], true ) ) {
			$coordinates++;
		}
	}

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

	// --- configuration: import tasks ---
	$raw_tasks_query = function_exists( 'get_posts' ) ? get_posts( array(
		'post_type'      => 'mlsimport_item',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) ) : array();

	$import_tasks       = array();
	$auto_update_any    = false;
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

	// --- MLS provider / ID ---
	$mls_provider = '';
	$mls_id       = 0;
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
	$state = get_option( 'mlsimport_telemetry_state', array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
	$now    = time();
	$stored = isset( $state['last_field_management'] ) ? (int) $state['last_field_management'] : 0;
	if ( ( $now - $stored ) < 10 * MINUTE_IN_SECONDS ) {
		return;
	}
	$state['last_field_management'] = $now;
	update_option( 'mlsimport_telemetry_state', $state, false );
}

// Field-selector progressive-save AJAX actions — "managing import fields".
// Priority 1 so the timestamp is recorded before the real save handler runs.
foreach (
	array(
		'mlsimport_save_field_chunk',
		'mlsimport_save_field_option',
		'mlsimport_save_field_position',
		'mlsimport_save_bulk_import',
		'mlsimport_save_bulk_admin',
	) as $mlsimport_field_action
) {
	add_action( 'wp_ajax_' . $mlsimport_field_action, 'mlsimport_telemetry_track_field_management', 1 );
}
unset( $mlsimport_field_action );
