<?php
/**
 * MLSImport Per-Connection Telemetry (issue #283, decision #272).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * With multi-MLS support (connections keyed by mls_id) the daily heartbeat
 * must report per-MLS health so the portal can tell WHICH connection is
 * failing while the others keep syncing. Decision #272: the heartbeat stays
 * ONE aggregated daily payload; it gains a `connections` array with one full
 * health entry per registered connection, while install-wide facts stay
 * global and the legacy singular fields keep being sent.
 *
 * This file owns the per-connection half of that:
 *  - the per-connection sync-stamp writer (success time / failure time+code
 *    maps on mlsimport_telemetry_state), shared by the request choke point
 *    and the cron connection gate so both write the maps the same one way,
 *  - the workload gatherer (bound-task / paused-task / listing counts per
 *    connection, resolved through the #277 cron gate and the #278
 *    provenance stamp),
 *  - the PURE payload-assembly seam that turns registry records + telemetry
 *    state + the gathered workload into the `connections` array.
 *
 * The counter buckets themselves ('daily_mls' nested per mls_id) are written
 * by mlsimport_telemetry_flush() in mlsimport-telemetry.php — bump callers
 * pass the connection id they already have in hand from the task binding.
 *
 * @since      7.2.0
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

if (! defined('ABSPATH') ) {
    exit; // Exit if accessed directly.
}

/**
 * Stamp one connection's sync outcome into the per-connection maps.
 *
 * Step by step:
 * 1. Ignore a non-positive id — an unattributed pull has no connection entry
 *    (the GLOBAL sync_health stamps are written by the caller either way).
 * 2. Success: record the time in the 'connection_sync_success' map
 *    (mls_id => epoch).
 * 3. Failure: record time + failure class in the 'connection_sync_failures'
 *    map (mls_id => {at, code}) — the same map the #277 cron gate writes, so
 *    the heartbeat reads ONE map no matter which seam saw the failure.
 * 4. Persist through mlsimport_telemetry_set() (single write, autoload no).
 *
 * @param  int    $mls_id The connection the pull ran for (0 = unattributed).
 * @param  bool   $ok     Whether the pull succeeded.
 * @param  string $code   Failure class when $ok is false ('token', 'auth', ...).
 * @return void
 */
function mlsimport_telemetry_record_connection_sync( int $mls_id, bool $ok, string $code = '' ): void
{
    // Step 1: no connection, no per-connection entry.
    if ($mls_id <= 0 ) {
        return;
    }

    // Load the persisted state; coerce a non-array back to an array.
    $state = get_option('mlsimport_telemetry_state', array());
    if (! is_array($state) ) {
        $state = array();
    }

    if ($ok ) {
        // Step 2: success map — mls_id => epoch of the last good pull.
        $map            = is_array($state['connection_sync_success'] ?? null) ? $state['connection_sync_success'] : array();
        $map[ $mls_id ] = time();
        mlsimport_telemetry_set('connection_sync_success', $map);
        return;
    }

    // Step 3: failure map — mls_id => when it failed and why.
    $map            = is_array($state['connection_sync_failures'] ?? null) ? $state['connection_sync_failures'] : array();
    $map[ $mls_id ] = array(
    'at'   => time(),
    'code' => $code,
    );
    mlsimport_telemetry_set('connection_sync_failures', $map);
}

/**
 * Gather each connection's workload for the heartbeat: how many Import Tasks
 * are bound to it, how many of those the cron gate would skip, and how many
 * listings carry its provenance stamp.
 *
 * Step by step:
 * 1. Zero-base one workload slot per registered connection.
 * 2. Resolve every task through the #277 cron gate wrapper — the SAME
 *    binding resolution (stamped meta, else the current connection) and the
 *    SAME import/skip decision the hourly cron applies, so the heartbeat
 *    counts exactly what the cron would do. A task bound to a deleted /
 *    unregistered connection has no entry to count against and is skipped
 *    (its connection is absent from the array the portal reads anyway).
 * 3. Count each connection's listings: published posts carrying its
 *    'mlsimport_mls_id' provenance stamp (#278). Hidden dedupe losers (#282)
 *    are stored listings and must count, hence the include-hidden flag.
 *
 * Only called with a non-empty registry, from the payload collector — once
 * per heartbeat, so the per-connection count queries stay cheap.
 *
 * @param  array  $records   Registry records keyed by mls_id.
 * @param  array  $task_ids  Every Import Task post id on the install.
 * @param  string $post_type The theme's property post type.
 * @return array<int, array{tasks: int, paused: int, listings: int}> Workload per mls_id.
 */
function mlsimport_telemetry_gather_connection_workload( array $records, array $task_ids, string $post_type ): array
{
    // Step 1: one zeroed slot per registered connection.
    $workload = array();
    foreach ( $records as $record ) {
        $workload[ (int) $record['mls_id'] ] = array(
        'tasks'    => 0,
        'paused'   => 0,
        'listings' => 0,
        );
    }

    // Step 2: gate every task exactly as the hourly cron would.
    foreach ( $task_ids as $task_id ) {
        $gate   = mlsimport_cron_task_gate((int) $task_id);
        $mls_id = (int) $gate['mls_id'];
        if (! isset($workload[ $mls_id ]) ) {
            continue;
        }
        $workload[ $mls_id ]['tasks']++;
        // Paused/dead = the gate would skip it (broken, unentitled, deleted).
        if ('import' !== $gate['action'] ) {
            $workload[ $mls_id ]['paused']++;
        }
    }

    // Step 3: provenance-stamped listing count per connection.
    foreach ( array_keys($workload) as $mls_id ) {
        $provenance_query = new WP_Query(
            array(
            'post_type'                => $post_type,
            'post_status'              => 'publish',
            'posts_per_page'           => 1,
            'fields'                   => 'ids',
            'no_found_rows'            => false,
            'meta_query'               => array(
            array(
            'key'   => 'mlsimport_mls_id',
            'value' => (string) $mls_id,
            ),
            ),
            'mlsimport_include_hidden' => true,
            )
        );
        $workload[ $mls_id ]['listings'] = (int) $provenance_query->found_posts;
    }

    return $workload;
}

/**
 * Build the heartbeat `connections` array — the PURE payload-assembly seam.
 *
 * Everything WordPress-dependent (registry read, task gating, listing
 * counts) is gathered by the caller and passed in, so this function can be
 * unit-tested with multi-connection state fixtures (issue #283 acceptance)
 * and never touches the DB.
 *
 * Step by step, per registered connection (records arrive priority-sorted
 * from Mlsimport_Connections::all(), so the array keeps that order):
 * 1. Identity: mls_id, provider type, priority.
 * 2. Connection status: the record's test result + when it was last tested.
 * 3. Sync health: last successful / last failed pull (+ failure class) from
 *    the per-connection maps written above.
 * 4. 7-day counters: sum this connection's 'daily_mls' buckets with the same
 *    summer the global fields use — so global sums equal the sum of the
 *    per-connection buckets by construction. token_failures is fed only by
 *    token failures attributable to ONE connection; the plugin's own SaaS
 *    JWT is account-level and counts globally only, so per-connection token
 *    pain currently surfaces through last_failure_code = 'token' instead.
 * 5. Workload: bound task count, how many of those tasks the cron gate would
 *    skip (paused/dead), the count of listings stamped with this mls_id, and
 *    the last time an import touched a listing for this connection.
 *
 * @param  array  $records  Registry records keyed by mls_id, priority order.
 * @param  array  $state    The full mlsimport_telemetry_state array.
 * @param  string $today    Reference UTC date 'Y-m-d' for the 7-day sums.
 * @param  array  $workload mls_id => {tasks, paused, listings} counts.
 * @return array<int, array> One complete health entry per connection.
 */
function mlsimport_telemetry_connections_payload( array $records, array $state, string $today, array $workload ): array
{
    // The per-connection daily buckets and stamp maps (all optional in state).
    $daily_mls   = is_array($state['daily_mls'] ?? null) ? $state['daily_mls'] : array();
    $success_map = is_array($state['connection_sync_success'] ?? null) ? $state['connection_sync_success'] : array();
    $failure_map = is_array($state['connection_sync_failures'] ?? null) ? $state['connection_sync_failures'] : array();
    $import_map  = is_array($state['connection_last_import'] ?? null) ? $state['connection_last_import'] : array();

    $entries = array();
    foreach ( $records as $record ) {
        $mls_id = (int) ( $record['mls_id'] ?? 0 );

        // Step 4: this connection's own 7-day sums (same summer as the globals).
        $mls_daily = is_array($daily_mls[ $mls_id ] ?? null) ? $daily_mls[ $mls_id ] : array();
        $sums      = mlsimport_telemetry_sum_buckets($mls_daily, $today, 7);

        // Step 3: this connection's failure record ({at, code} or absent).
        $failure = is_array($failure_map[ $mls_id ] ?? null) ? $failure_map[ $mls_id ] : array();

        // Step 5: this connection's gathered workload counts (or all-zero).
        $load = is_array($workload[ $mls_id ] ?? null) ? $workload[ $mls_id ] : array();

        $entries[] = array(
        // Step 1: identity.
        'mls_id'                     => $mls_id,
        'provider'                   => (string) ( $record['provider_type'] ?? '' ),
        'priority'                   => (int) ( $record['priority'] ?? 0 ),
        // Step 2: connection-test status.
        'status'                     => (string) ( $record['status'] ?? '' ),
        'last_test'                  => mlsimport_telemetry_iso((int) ( $record['tested_at'] ?? 0 )),
        // Step 3: per-connection sync health.
        'last_successful_sync'       => mlsimport_telemetry_iso((int) ( $success_map[ $mls_id ] ?? 0 )),
        'last_failed_sync'           => mlsimport_telemetry_iso((int) ( $failure['at'] ?? 0 )),
        'last_failure_code'          => (string) ( $failure['code'] ?? '' ),
        // Step 4: 7-day counters from this connection's own buckets.
        'imported_last_7_days'       => (int) $sums['imported'],
        'updated_last_7_days'        => (int) $sums['updated'],
        'deleted_last_7_days'        => (int) $sums['deleted'],
        'syncs_last_7_days'          => (int) $sums['syncs'],
        'token_failures_last_7_days' => (int) $sums['token_failures'],
        // Step 5: workload.
        'task_count'                 => (int) ( $load['tasks'] ?? 0 ),
        'tasks_paused_dead'          => (int) ( $load['paused'] ?? 0 ),
        'listing_count'              => (int) ( $load['listings'] ?? 0 ),
        'last_import'                => mlsimport_telemetry_iso((int) ( $import_map[ $mls_id ] ?? 0 )),
        );
    }

    return $entries;
}
