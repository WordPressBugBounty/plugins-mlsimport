<?php
/**
 * Per-connection reconciliation (issue #279, decision #269, spec #273).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * With N MLS connections, one unscoped daily reconciliation would compare one
 * MLS's snapshot against EVERY local listing and mark the other MLS's posts
 * as deletion candidates. Decision #269 keeps the deep decision module
 * (Mlsimport_Reconciliation) completely untouched and instead runs it once
 * per connection, each run against a connection-scoped environment:
 *
 *   - snapshot fetched with mls_id and verified by the #276 echo guard
 *     (a missing/mismatched echo or a 'not_entitled' rejection aborts ONLY
 *     that connection's sub-run with zero deletions — the connection-level
 *     sibling of "empty status never deletes"),
 *   - inventory limited in SQL to posts stamped 'mlsimport_mls_id' = X
 *     (#278), so orphaned and unstamped listings sit outside every inventory
 *     and are structurally undeletable,
 *   - the 80% plausibility guard applied by the module per connection.
 *
 * One global lock spans the whole loop; outcomes are recorded per connection;
 * orphaned stamps (matching no registered connection) surface one deduplicated
 * health incident. The single retry event re-runs all connections — plan-first
 * means already-completed connections converge to no-op keeps.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-reconciliation.php';

/**
 * Refuse an MLS-scoped snapshot that may not be used for this connection.
 *
 * Step by step:
 * 1. A stable 'not_entitled' rejection marks the connection in the registry
 *    (import paths then skip it too) and aborts the sub-run by throwing.
 * 2. Any response that does not echo the requested mls_id back — a legacy
 *    account-wide snapshot, a misrouted response — is refused by the #276
 *    echo guard and aborts the sub-run by throwing.
 * 3. Only a verified same-connection snapshot is returned for planning.
 *
 * Throwing is deliberate: the untouched decision module already converts a
 * fetch throw into an 'aborted' outcome with zero deletions, which is exactly
 * the invariant — an unreadable connection never deletes.
 *
 * @param mixed $answer Raw decoded SaaS reconciliation response.
 * @param int   $mls_id The connection the request was scoped to.
 * @return array<string, mixed> The verified snapshot response.
 * @throws RuntimeException With a stable refusal code when the snapshot is unusable.
 */
function mlsimport_reconciliation_guarded_snapshot( $answer, int $mls_id ): array {
	// Step 1: the SaaS says this account may not use this MLS any more.
	if ( mlsimport_response_not_entitled( $answer ) ) {
		mlsimport_mark_connection_not_entitled( $mls_id );
		throw new RuntimeException( 'snapshot_not_entitled' );
	}

	// Step 2: no echo / wrong echo => the snapshot is not authoritative for
	// this connection and must never drive its deletions.
	if ( ! mlsimport_mls_scoped_echo_ok( $answer, $mls_id ) ) {
		throw new RuntimeException( 'snapshot_echo_mismatch' );
	}

	// Step 3: verified — hand it to the decision module unchanged.
	return $answer;
}

/**
 * Run the untouched decision module once per connection, sequentially.
 *
 * The factory builds one connection-scoped environment per mls_id; each
 * sub-run is a full Mlsimport_Reconciliation run against that environment.
 * An aborted sub-run simply moves the loop to the next connection — outcome
 * isolation is the module's own contract (its throw handling and guards all
 * end in a zero-deletion abort).
 *
 * @param array<int, int> $mls_ids          Connection ids in priority order.
 * @param callable        $make_environment fn( int $mls_id ): Mlsimport_Reconciliation_Environment.
 * @return array<int, array<string, int|string>> Reconciliation Outcome per mls_id.
 */
function mlsimport_reconciliation_loop( array $mls_ids, callable $make_environment ): array {
	$outcomes = array();

	// Sequential sub-runs: one full module run per connection.
	foreach ( $mls_ids as $mls_id ) {
		$environment               = call_user_func( $make_environment, (int) $mls_id );
		$outcomes[ (int) $mls_id ] = ( new Mlsimport_Reconciliation( $environment ) )->reconcile_current_listings();
	}

	return $outcomes;
}

/**
 * Find provenance stamps on listing posts that match no registered connection.
 *
 * A listing stamped with an mls_id whose connection was deleted (or stamped 0
 * by an unbound task) is outside every scoped inventory — reconciliation can
 * structurally never delete it — but it is also never cleaned up, so it must
 * surface as a health incident. Unstamped pre-migration posts carry no
 * provenance meta at all and are intentionally NOT reported: they are safe by
 * construction until the migration stamps them.
 *
 * @param array<int, int> $registered_ids Currently registered connection ids.
 * @return array<int, int> Distinct orphaned mls_id stamps (may include 0).
 */
function mlsimport_reconciliation_orphan_mls_ids( array $registered_ids ): array {
	global $wpdb;

	// The stamps to keep: every registered id, compared as the string the
	// postmeta table stores.
	$registered = array_map( 'strval', array_map( 'intval', $registered_ids ) );

	// Listing posts are identified by their stable '_mlsimport_listing_key'
	// meta; draft/trash posts are outside reconciliation and outside this scan.
	$exclusion = '';
	if ( array() !== $registered ) {
		$exclusion = ' AND provenance.meta_value NOT IN (' . implode( ', ', array_fill( 0, count( $registered ), '%s' ) ) . ')';
	}

	// Intentional uncached direct scan at the infrastructure boundary.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$stamps = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT provenance.meta_value
			 FROM {$wpdb->postmeta} provenance
			 INNER JOIN {$wpdb->posts} posts ON posts.ID = provenance.post_id
			 INNER JOIN {$wpdb->postmeta} listing_key
			    ON listing_key.post_id = posts.ID AND listing_key.meta_key = %s
			 WHERE provenance.meta_key = %s
			   AND posts.post_status NOT IN ('draft', 'trash')" . $exclusion, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( '_mlsimport_listing_key', 'mlsimport_mls_id' ), $registered )
		)
	);

	$orphans = array_map( 'intval', is_array( $stamps ) ? $stamps : array() );
	sort( $orphans );
	return $orphans;
}

/**
 * The daily/retry entry point: reconcile every registered connection.
 *
 * Step by step:
 * 1. Legacy degrade: an empty registry (install not yet using connections)
 *    runs today's single unscoped reconciliation unchanged — no echo guard,
 *    no scoping — exactly the pre-multi-MLS behavior.
 * 2. One global lock spans the whole loop (reconciliation vs import mutual
 *    exclusion stays global); when it is held, every connection reports
 *    'already_running' and nothing runs.
 * 3. Orphan scan: stamps matching no registered connection open ONE
 *    deduplicated health incident (they are already structurally
 *    undeletable); a clean scan resolves it so the alert re-arms.
 * 4. Loop the connections in priority order: each sub-run gets a scoped
 *    environment whose fetcher requests `reconciliation?mls_id=X` and passes
 *    the guarded-snapshot check above before the module may plan anything.
 * 5. Outcomes are recorded per connection (non-autoloaded option + error_log).
 *
 * @return array<int, array<string, int|string>> Reconciliation Outcome per
 *         mls_id (key 0 = the single legacy unscoped run).
 */
function mlsimport_reconciliation_run_connections(): array {
	global $mlsimport;

	$connections = Mlsimport_Connections::all();

	// Step 1: no registered connections => the unchanged legacy single run.
	if ( array() === $connections ) {
		$environment = new Mlsimport_Reconciliation_WordPress_Environment(
			static function () use ( $mlsimport ): array {
				return $mlsimport->admin->mlsimport_saas_get_mls_reconciliation_data();
			}
		);
		$outcomes = array( 0 => ( new Mlsimport_Reconciliation( $environment ) )->reconcile_current_listings() );
		mlsimport_reconciliation_record_outcomes( $outcomes );
		return $outcomes;
	}

	// Step 2: claim the one global lock for the whole loop. The holder is a
	// plain environment instance — reusing the canonical add_option lock
	// instead of duplicating it here. The scoped environments below
	// deliberately do not lock (mls_id > 0 makes their lock methods no-ops),
	// so this holder is the only owner.
	$lock = new Mlsimport_Reconciliation_WordPress_Environment(
		static function (): array {
			return array();
		}
	);
	if ( ! $lock->acquire_lock() ) {
		// Mirrors Mlsimport_Reconciliation::outcome( 'already_running',
		// 'reconciliation_already_running' ) — keep the shapes in sync.
		$busy = array(
			'status'  => 'already_running',
			'reason'  => 'reconciliation_already_running',
			'kept'    => 0,
			'deleted' => 0,
			'failed'  => 0,
		);
		return array_fill_keys( array_keys( $connections ), $busy );
	}

	try {
		// Step 3: orphaned provenance stamps become one deduplicated incident;
		// a clean scan resolves it (silent when not open) so the alert re-arms
		// once the stamps are repaired.
		$orphans = mlsimport_reconciliation_orphan_mls_ids( array_keys( $connections ) );
		if ( array() !== $orphans ) {
			mlsimport_alert_open(
				'reconciliation_orphaned_listings',
				'reconciliation_orphaned_listings',
				array( 'mls_ids' => $orphans )
			);
		} else {
			mlsimport_alert_resolve( 'reconciliation_orphaned_listings' );
		}

		// Step 4: sequential scoped sub-runs. A guard refusal aborts the
		// sub-run through the module's fetch-throw handling; the specific
		// refusal code is logged here, where it is in hand.
		$outcomes = mlsimport_reconciliation_loop(
			array_keys( $connections ),
			static function ( int $mls_id ) use ( $mlsimport ) {
				return new Mlsimport_Reconciliation_WordPress_Environment(
					static function () use ( $mlsimport, $mls_id ): array {
						$answer = $mlsimport->admin->mlsimport_saas_get_mls_reconciliation_data( $mls_id );
						try {
							return mlsimport_reconciliation_guarded_snapshot( $answer, $mls_id );
						} catch ( RuntimeException $refusal ) {
							error_log( 'MLSImport reconciliation snapshot refused for connection ' . $mls_id . ': ' . $refusal->getMessage() );
							throw $refusal;
						}
					},
					$mls_id
				);
			}
		);

		// Step 5: per-connection record for support/telemetry visibility.
		mlsimport_reconciliation_record_outcomes( $outcomes );
		return $outcomes;
	} finally {
		$lock->release_lock();
	}
}

/**
 * Record the run's per-connection outcomes (decision #269: outcomes are
 * recorded per connection) and log one line per connection.
 *
 * @param array<int, array<string, int|string>> $outcomes Outcome per mls_id.
 * @return void
 */
function mlsimport_reconciliation_record_outcomes( array $outcomes ): void {
	// Non-autoloaded (update_option creates it that way on first write):
	// only support/diagnostic paths ever read this back.
	$record = array(
		'time'     => time(),
		'outcomes' => $outcomes,
	);
	update_option( 'mlsimport_reconciliation_last_run', $record, false );

	foreach ( $outcomes as $mls_id => $outcome ) {
		error_log( 'MLSImport reconciliation outcome for connection ' . (int) $mls_id . ': ' . wp_json_encode( $outcome ) );
	}
}
