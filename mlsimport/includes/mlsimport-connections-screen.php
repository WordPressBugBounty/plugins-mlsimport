<?php
/**
 * Connections screen — data layer (issue #280, decision #271, spec #273).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The Connections tab (settings page, ?tab=connections) shows every registered
 * MLS connection as one table row: priority, name/provider, status pill with
 * last-test time, activity counts, and a failure banner under a failing row.
 * This file owns everything the tab needs that is NOT markup:
 *
 *   - pure helpers (no WordPress): status derivation for a row, validation +
 *     application of a drag-reorder, and mapping a record's generic-named
 *     credentials back onto the flat option keys a provider adapter declares,
 *   - the assembled screen data (registry rows + activity counts + plan cap +
 *     account state) the partial renders from.
 *
 * The AJAX handlers that mutate state (reorder, per-row test, disconnect)
 * live in mlsimport-connections-ajax.php beside this file. The markup lives in
 * admin/partials/mlsimport-connections.php.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derive one row's status view from its registry record. Pure.
 *
 * Step by step:
 * 1. status 'yes'          => connected (green pill, not failing).
 * 2. status 'not_entitled' => the SaaS rejected this MLS for the account —
 *    failing, shows the amber banner under the row.
 * 3. status '' with a recorded test time => the last test FAILED (the test
 *    recorder resets a failed connection to '' but stamps tested_at) —
 *    failing, shows the banner.
 * 4. status '' never tested => neutral "untested": a fresh record that simply
 *    has not been checked yet is not an incident, no banner.
 *
 * @param array $record Normalized registry record.
 * @return array{key:string, class:string, failing:bool} Stable status key
 *         ('connected'|'not_entitled'|'failed'|'untested'), pill class
 *         ('ok'|'bad'|'warn'), and whether the failure banner shows.
 */
function mlsimport_connections_row_status( array $record ): array {
	// Step 1: a confirmed test is the only green state.
	if ( 'yes' === ( $record['status'] ?? '' ) ) {
		return array( 'key' => 'connected', 'class' => 'ok', 'failing' => false );
	}

	// Step 2: a standing entitlement rejection is always a failure.
	if ( 'not_entitled' === ( $record['status'] ?? '' ) ) {
		return array( 'key' => 'not_entitled', 'class' => 'bad', 'failing' => true );
	}

	// Step 3+4: '' means untested — but a tested_at stamp proves a test ran
	// and came back negative, which is a real failure, not a blank slate.
	if ( (int) ( $record['tested_at'] ?? 0 ) > 0 ) {
		return array( 'key' => 'failed', 'class' => 'bad', 'failing' => true );
	}
	return array( 'key' => 'untested', 'class' => 'warn', 'failing' => false );
}

/**
 * Apply a drag-reorder to the registry records. Pure.
 *
 * Step by step:
 * 1. Validate the posted order: it must name every registered connection
 *    exactly once — an unknown id, a missing id, or a duplicate refuses the
 *    whole reorder (null) so a stale/foreign POST can never scramble
 *    priorities.
 * 2. Assign priorities 1..N in the posted order; every other record field is
 *    left untouched.
 *
 * @param array $ordered_ids mls_ids in the new priority order (1 first).
 * @param array $connections Registry records keyed by mls_id.
 * @return array|null Updated records keyed by mls_id, or null when refused.
 */
function mlsimport_connections_apply_order( array $ordered_ids, array $connections ): ?array {
	// Step 1: exact one-to-one match between posted ids and registered ids.
	$ordered_ids = array_map( 'intval', $ordered_ids );
	$posted      = $ordered_ids;
	$registered  = array_map( 'intval', array_keys( $connections ) );
	sort( $posted );
	sort( $registered );
	if ( $posted !== $registered ) {
		return null;
	}

	// Step 2: position in the posted order IS the new priority.
	foreach ( $ordered_ids as $position => $mls_id ) {
		$connections[ $mls_id ]['priority'] = $position + 1;
	}
	return $connections;
}

/**
 * Map a record's generic-named creds onto flat adapter option keys. Pure
 * (uses only the migration module's suffix rule).
 *
 * Provider adapters build their connection-test payload from the FLAT
 * slot-named options ('mlsimport_tresle_client_id', ...). A non-current
 * connection's credentials live in its registry record under generic names
 * ({client_id, client_secret, username, password, mls_token} — decision #263).
 * This reverses the migration's suffix mapping so the adapter can test any
 * registered connection without touching the flat options.
 *
 * Step by step:
 * 1. For each flat field the adapter declares, resolve its generic name via
 *    the same suffix rule the migration used to store it.
 * 2. Take that generic cred's value from the record — trimmed ONLY, never
 *    sanitized (credential values must reach the SaaS verbatim).
 *
 * @param array $record            Normalized registry record.
 * @param array $credential_fields Flat option keys the adapter declares.
 * @return array Flat-option-shaped credentials ('' when the record lacks one).
 */
function mlsimport_connections_credential_options( array $record, array $credential_fields ): array {
	$creds   = is_array( $record['creds'] ?? null ) ? $record['creds'] : array();
	$options = array();

	foreach ( $credential_fields as $field ) {
		// Step 1: flat slot-name => generic name (suffix rule, shared helper).
		$generic = mlsimport_multimls_generic_credential_name( (string) $field );
		// Step 2: verbatim value, trimmed only.
		$options[ $field ] = trim( (string) ( $creds[ $generic ] ?? '' ) );
	}

	return $options;
}

/**
 * Translate a row-status key into its pill label.
 *
 * One map, used by both the partial (initial render) and the test AJAX
 * response (live pill update) so the two can never drift apart.
 *
 * @param string $key Status key from mlsimport_connections_row_status().
 * @return string Translated pill label.
 */
function mlsimport_connections_status_label( string $key ): string {
	switch ( $key ) {
		case 'connected':
			return __( 'Connected', 'mlsimport' );
		case 'not_entitled':
			return __( 'Not entitled', 'mlsimport' );
		case 'failed':
			return __( 'Connection failed', 'mlsimport' );
		default:
			return __( 'Not tested', 'mlsimport' );
	}
}

/**
 * Resolve the settings page's active tab from the requested ?tab= value. Pure.
 *
 * The Connections tab is the settings page's single connection surface AND its
 * default (tab consolidation: the old "MLS Connection" credentials tab was
 * retired in favor of the Connections screen + Edit drawer). One rule:
 *
 * 1. A known live tab (connections, field_options, administrative_options)
 *    passes through.
 * 2. Everything else — no tab, the retired 'display_options' value (old
 *    bookmarks/links), or junk — resolves to 'connections'.
 *
 * @param string $requested The raw (sanitized) ?tab= value, '' when absent.
 * @return string The tab to render.
 */
function mlsimport_settings_active_tab( string $requested ): string {
	$live_tabs = array( 'connections', 'field_options', 'administrative_options' );
	return in_array( $requested, $live_tabs, true ) ? $requested : 'connections';
}

/**
 * Per-connection activity counts read straight from the database.
 *
 * Step by step:
 * 1. Task count: mlsimport_item posts carrying the #277 binding meta
 *    'mlsimport_item_mls_id', grouped by the bound mls_id.
 * 2. Listing count: posts carrying the #278 provenance stamp
 *    'mlsimport_mls_id', grouped by the stamp (draft/trash excluded — same
 *    visibility rule reconciliation uses).
 * 3. Last import: the newest 'mlsimport_last_date' task meta (ISO timestamp,
 *    so MAX() on the string is chronological) per bound connection.
 *
 * @return array<int, array{tasks:int, listings:int, last_import:string}>
 *         Activity keyed by mls_id (only ids with any activity appear).
 */
function mlsimport_connections_activity(): array {
	global $wpdb;

	$activity = array();

	// Step 1: bound task counts. Uncached direct reads are intentional here —
	// this is an admin screen assembling live counts.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$tasks = $wpdb->get_results(
		"SELECT binding.meta_value AS mls_id, COUNT(*) AS total
		 FROM {$wpdb->postmeta} binding
		 INNER JOIN {$wpdb->posts} posts ON posts.ID = binding.post_id
		 WHERE binding.meta_key = 'mlsimport_item_mls_id'
		   AND posts.post_type = 'mlsimport_item'
		   AND posts.post_status NOT IN ('trash', 'auto-draft')
		 GROUP BY binding.meta_value"
	);
	foreach ( $tasks as $row ) {
		$activity[ (int) $row->mls_id ]['tasks'] = (int) $row->total;
	}

	// Step 2: stamped listing counts.
	$listings = $wpdb->get_results(
		"SELECT provenance.meta_value AS mls_id, COUNT(*) AS total
		 FROM {$wpdb->postmeta} provenance
		 INNER JOIN {$wpdb->posts} posts ON posts.ID = provenance.post_id
		 WHERE provenance.meta_key = 'mlsimport_mls_id'
		   AND posts.post_status NOT IN ('draft', 'trash', 'auto-draft')
		 GROUP BY provenance.meta_value"
	);
	foreach ( $listings as $row ) {
		$activity[ (int) $row->mls_id ]['listings'] = (int) $row->total;
	}

	// Step 3: newest successful sync per bound connection — over the SAME task
	// population Step 1 counts, so "2 tasks" and "imported X ago" never come
	// from different sets (a trashed task must not contribute its last_date).
	$last = $wpdb->get_results(
		"SELECT binding.meta_value AS mls_id, MAX(last_date.meta_value) AS latest
		 FROM {$wpdb->postmeta} binding
		 INNER JOIN {$wpdb->posts} posts ON posts.ID = binding.post_id
		 INNER JOIN {$wpdb->postmeta} last_date
		    ON last_date.post_id = binding.post_id AND last_date.meta_key = 'mlsimport_last_date'
		 WHERE binding.meta_key = 'mlsimport_item_mls_id'
		   AND posts.post_type = 'mlsimport_item'
		   AND posts.post_status NOT IN ('trash', 'auto-draft')
		 GROUP BY binding.meta_value"
	);
	// phpcs:enable
	foreach ( $last as $row ) {
		$activity[ (int) $row->mls_id ]['last_import'] = (string) $row->latest;
	}

	// Fill defaults so every present id has the full shape.
	foreach ( $activity as $mls_id => $entry ) {
		$activity[ $mls_id ] = $entry + array(
			'tasks'       => 0,
			'listings'    => 0,
			'last_import' => '',
		);
	}

	return $activity;
}

/**
 * Assemble everything the Connections partial renders.
 *
 * Step by step:
 * 1. Registry rows in priority order, each with its derived status view and
 *    activity counts (zeros when a connection has no activity yet).
 * 2. Account state: connected = a SaaS token exists for the saved account
 *    credentials; the username identifies the account in the summary bar.
 * 3. Plan slots: used = registered connections, cap = the #276 entitlement
 *    cap (minimum 1), re-read from the SaaS first when the account is
 *    connected. at_cap switches "+ Add MLS" to "Upgrade plan".
 *
 * @return array{rows:array, used:int, cap:int, at_cap:bool,
 *               account:array{connected:bool, username:string}}
 */
function mlsimport_connections_screen_data(): array {
	global $mlsimport;

	// Step 1: one row per registered connection, priority order.
	$activity = mlsimport_connections_activity();
	$rows     = array();
	foreach ( Mlsimport_Connections::all() as $mls_id => $record ) {
		$rows[ $mls_id ]                = $record;
		$rows[ $mls_id ]['status_view'] = mlsimport_connections_row_status( $record );
		$rows[ $mls_id ]['activity']    = $activity[ $mls_id ] ?? array(
			'tasks'       => 0,
			'listings'    => 0,
			'last_import' => '',
		);
	}

	// Step 2: account state — a non-empty token proves the saved account
	// credentials authenticate (the getter refreshes from them when needed).
	$options = get_option( 'mlsimport_admin_options', array() );
	$options = is_array( $options ) ? $options : array();
	$token   = trim( (string) $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient() );

	// Step 3: plan slot strip numbers. The cap belongs to the mlsimport.com
	// account and changes without this site doing anything (a plan upgrade
	// on the portal, a cap granted after the site signed in), so a connected
	// account re-reads it from the SaaS on every render of this screen
	// instead of showing whatever was cached at sign-in. A failed or legacy
	// answer changes nothing (mlsimport_apply_entitlements() degrade rules).
	if ( '' !== $token ) {
		mlsimport_refresh_entitlements();
	}
	$used = count( $rows );
	$cap  = mlsimport_entitlement_cap();

	return array(
		'rows'    => $rows,
		'used'    => $used,
		'cap'     => $cap,
		'at_cap'  => $used >= $cap,
		'account' => array(
			'connected' => '' !== $token,
			'username'  => (string) ( $options['mlsimport_username'] ?? '' ),
		),
	);
}
