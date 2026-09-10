<?php
/**
 * Multi-MLS entitlements + MLS-scoped SaaS response handling (issue #276).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Decision #268 made the SaaS contract additive for N MLS entitlements:
 *
 * - GET clients keeps returning 'mls_data' (the primary MLS, for legacy
 *   plugins) and ADDS 'mls_entitlements' — an array of per-MLS config blocks,
 *   each shaped like today's mls_data — plus, for subscription-managed
 *   accounts, a numeric 'mls_entitlement_cap'. The subscription grants a
 *   NUMBER of connections (the customer picks which MLSes in the plugin), so
 *   the number is the cap when present; the array length is the fallback cap
 *   for responses that predate the count field. There is no separate endpoint.
 * - PATCH clients carries 'mls_id' and returns only that MLS's mls_data block.
 * - Every MLS-scoped response echoes 'mls_id' back, and one stable rejection
 *   code — 'not_entitled' — is shared by clients, reconciliation and listings.
 *
 * This module is the plugin-side consumer of that contract:
 *
 * 1. mlsimport_apply_entitlements()   — parse mls_entitlements into the
 *    Mlsimport_Connections registry (matching records only) + store the cap.
 * 2. mlsimport_apply_client_block()   — apply a PATCH clients response block
 *    to exactly the requested connection, echo-guarded.
 * 3. mlsimport_mls_scoped_echo_ok()   — the reusable echo guard: any
 *    mls_id-scoped response must echo the requested id back before it may be
 *    used destructively (spirit of "empty status never deletes").
 * 4. mlsimport_response_not_entitled()/mark/check — detect the stable
 *    rejection, mark that one connection, and let import paths skip it
 *    without affecting any other connection.
 * 5. mlsimport_refresh_entitlements()  — re-ask the SaaS for the account view
 *    and apply it (1). Runs when the install (re)connects to its
 *    mlsimport.com account, so a plan change is picked up by a plain
 *    disconnect + reconnect instead of waiting for a metadata gather.
 *
 * A legacy response without 'mls_entitlements' (old API, new plugin) changes
 * NOTHING here — the plugin degrades to today's single-MLS behavior.
 *
 * The 'not_entitled' mark lives in the registry record's existing 'status'
 * field (values: '' untested, 'yes' tested OK, 'not_entitled' rejected) —
 * a rejected connection is definitionally not tested-OK, and clearing the
 * mark back to '' simply requires the normal re-test.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Echo guard: may an MLS-scoped SaaS response be trusted for the requested MLS?
 *
 * Step by step:
 * 1. The caller must actually have requested a concrete MLS (id > 0).
 * 2. The response must be an array that carries an 'mls_id' echo at its top
 *    level — a missing echo means a legacy/misrouted full-account response.
 * 3. The echoed id must equal the requested id exactly (numeric compare, so
 *    "103" echoes match the int 103 request).
 *
 * Refusing here is the safety boundary that keeps a misrouted or legacy
 * account-wide payload from ever being applied to (or deleting from) another
 * connection.
 *
 * @param mixed $answer           Decoded SaaS response.
 * @param int   $requested_mls_id The mls_id the request was scoped to.
 * @return bool True only when the response echoes the requested mls_id.
 */
function mlsimport_mls_scoped_echo_ok( $answer, int $requested_mls_id ): bool {
	// Step 1: without a concrete requested id there is nothing to verify against.
	if ( $requested_mls_id <= 0 ) {
		return false;
	}
	// Step 2: a non-array or echo-less response is refused, never assumed.
	if ( ! is_array( $answer ) || ! isset( $answer['mls_id'] ) ) {
		return false;
	}
	// Step 3: the echo must name exactly the MLS we asked about.
	return (int) $answer['mls_id'] === $requested_mls_id;
}

/**
 * Whether a SaaS response is the stable 'not_entitled' rejection.
 *
 * The two response shapes the API clients produce/pass through are checked:
 * globalApiRequestSaas() normalizes errors to a top-level 'error_code', and
 * globalApiRequestCurlSaas() returns the raw body, whose error convention is
 * { error: { code, message } }.
 *
 * @param mixed $answer Decoded SaaS response.
 * @return bool True when the response carries the stable rejection code.
 */
function mlsimport_response_not_entitled( $answer ): bool {
	if ( ! is_array( $answer ) ) {
		return false;
	}
	// Normalized shape from globalApiRequestSaas().
	if ( 'not_entitled' === ( $answer['error_code'] ?? '' ) ) {
		return true;
	}
	// Raw body shape passed through by globalApiRequestCurlSaas().
	return 'not_entitled' === ( $answer['error']['code'] ?? '' );
}

/**
 * The connection cap: how many MLS connections this account is entitled to.
 *
 * The cap is what the SaaS last granted: the numeric mls_entitlement_cap the
 * subscription carries, or — for responses without the count field — the
 * length of the mls_entitlements array (decision #268: no hard-coded tier
 * constant lives in the plugin). Before the API ever sends either (old API /
 * never refreshed) the cap is 1: single-MLS behavior.
 *
 * @return int Connection cap, minimum 1.
 */
function mlsimport_entitlement_cap(): int {
	return max( 1, (int) get_option( 'mlsimport_entitlement_cap', 1 ) );
}

/**
 * Parse the mls_entitlements array out of a GET clients response and refresh
 * the connection registry from it.
 *
 * Step by step:
 * 1. Resolve the cap source. The subscription grants a NUMBER of connections
 *    ("2 MLS connections"), not a list of specific MLS ids — nobody knows the
 *    ids at purchase time, the customer picks them in the plugin. So a usable
 *    numeric 'mls_entitlement_cap' (int >= 1) in the response is the cap;
 *    without it the cap falls back to the mls_entitlements array length
 *    (the pre-count contract, kept so an older API stays fully supported).
 * 2. Legacy degrade: neither a usable cap number nor an array → return
 *    without touching anything (old API, new plugin: single-MLS behavior).
 * 3. Store the cap.
 * 4. For each entitlement block with a usable mls_id, refresh ONLY an already
 *    registered matching connection: provider type + per-MLS config, and clear
 *    a previous 'not_entitled' mark (presence in the array proves entitlement).
 *    Unregistered ids are never auto-created — creating records is the
 *    Connections UI's job (#271).
 *
 * @param mixed $answer Decoded GET clients response.
 * @return void
 */
function mlsimport_apply_entitlements( $answer ): void {
	if ( ! is_array( $answer ) ) {
		return;
	}

	// Step 1: prefer the subscription's numeric cap; fall back to array length.
	$has_list = isset( $answer['mls_entitlements'] ) && is_array( $answer['mls_entitlements'] );
	$raw_cap  = $answer['mls_entitlement_cap'] ?? null;
	$has_cap  = is_numeric( $raw_cap ) && (int) $raw_cap >= 1;

	// Step 2: legacy response (neither source) → single-MLS behavior, nothing changes.
	if ( ! $has_cap && ! $has_list ) {
		return;
	}

	// Step 3: store the cap. Non-autoloaded: only admin/import paths ask for it.
	$cap = $has_cap ? (int) $raw_cap : count( $answer['mls_entitlements'] );
	if ( false === get_option( 'mlsimport_entitlement_cap', false ) ) {
		add_option( 'mlsimport_entitlement_cap', $cap, '', 'no' );
	} else {
		update_option( 'mlsimport_entitlement_cap', $cap, false );
	}

	// Step 4: refresh each matching registered connection from its block.
	if ( $has_list ) {
		foreach ( $answer['mls_entitlements'] as $block ) {
			if ( is_array( $block ) && isset( $block['mls_id'] ) ) {
				mlsimport_entitlement_refresh_record( (int) $block['mls_id'], $block );
			}
		}
	}
}

/**
 * Re-read the account's entitlements from the SaaS and apply them.
 *
 * The cap belongs to the mlsimport.com ACCOUNT, not to any MLS connection,
 * so the moment the install signs in to that account is the moment to ask
 * for it again. Without this, an upgraded plan stays invisible on the
 * Connections screen until Field Mapping happens to gather metadata.
 *
 * Step by step:
 * 1. GET clients?theme_id=<configured theme> — the unscoped account view,
 *    the same call the metadata gather makes (minus the mls_id scope).
 * 2. Hand the answer to mlsimport_apply_entitlements(): a failed or legacy
 *    answer changes nothing; a usable cap / blocks array refreshes the cap
 *    and the already registered connections.
 *
 * @return void
 */
function mlsimport_refresh_entitlements(): void {
	// Step 1: the account-wide GET clients.
	$options = get_option( 'mlsimport_admin_options', array() );
	$options = is_array( $options ) ? $options : array();
	$answer  = ThemeImport::globalApiRequestSaas(
		'clients?theme_id=' . intval( $options['mlsimport_theme_used'] ?? 0 ),
		array(),
		'GET'
	);

	// Step 2: apply — same degrade rules as every other consumer.
	mlsimport_apply_entitlements( $answer );
}

/**
 * Apply a PATCH clients response's mls_data block to one connection record.
 *
 * The server scopes the PATCH by the mls_id in the payload and returns that
 * MLS's block. Before writing anything, the echo guard verifies the block
 * names the requested MLS — a mismatched or missing echo is refused so a
 * misrouted/legacy response can never overwrite another connection's config.
 *
 * @param mixed $mls_data         The response's mls_data block.
 * @param int   $requested_mls_id The mls_id the PATCH was scoped to.
 * @return bool True when the block was applied to the requested record.
 */
function mlsimport_apply_client_block( $mls_data, int $requested_mls_id ): bool {
	// Refuse anything that does not echo the requested MLS back.
	if ( ! mlsimport_mls_scoped_echo_ok( $mls_data, $requested_mls_id ) ) {
		return false;
	}
	// Echo verified: refresh exactly that record (and only if it is registered).
	return mlsimport_entitlement_refresh_record( $requested_mls_id, $mls_data );
}

/**
 * Refresh one REGISTERED connection record from a SaaS config block.
 *
 * Step by step:
 * 1. Only an existing registry record is updated — never created here.
 * 2. The block's provider 'type' becomes the record's provider_type.
 * 3. The whitelisted scalar per-MLS config keys (same set live mode stores)
 *    become the record's live_config.
 * 4. A previous 'not_entitled' status mark is cleared: the SaaS returning a
 *    config block for this MLS proves the entitlement again. A 'yes'
 *    (tested OK) status is kept as-is.
 *
 * @param int   $mls_id MLS whose record to refresh.
 * @param array $block  SaaS config block (mls_data / entitlement entry shape).
 * @return bool True when a matching record existed and was saved.
 */
function mlsimport_entitlement_refresh_record( int $mls_id, array $block ): bool {
	// Step 1: unmatched ids are ignored — registry creation belongs to #271.
	$record = Mlsimport_Connections::get( $mls_id );
	if ( null === $record ) {
		return false;
	}

	// Step 2: the block's saved provider type is authoritative when present.
	if ( isset( $block['type'] ) && is_scalar( $block['type'] ) && '' !== trim( (string) $block['type'] ) ) {
		$record['provider_type'] = strtolower( trim( (string) $block['type'] ) );
	}

	// Step 3: copy only the whitelisted scalar config keys into live_config.
	$config = array();
	foreach ( array( 'api_import_url', 'api_token_url', 'api_media_url', 'type', 'expand', 'field_corellation', 'mls_filter_params', 'mls_id' ) as $key ) {
		if ( isset( $block[ $key ] ) && is_scalar( $block[ $key ] ) ) {
			$config[ $key ] = (string) $block[ $key ];
		}
	}
	if ( array() !== $config ) {
		$record['live_config'] = $config;
	}

	// Step 4: a returned block proves entitlement — lift a not_entitled mark.
	if ( 'not_entitled' === $record['status'] ) {
		$record['status'] = '';
	}

	return Mlsimport_Connections::save( $record );
}

/**
 * Mark one connection as rejected by the SaaS with the stable 'not_entitled'
 * code. Only that record changes; every other connection is untouched.
 *
 * @param int $mls_id MLS the SaaS rejected.
 * @return void
 */
function mlsimport_mark_connection_not_entitled( int $mls_id ): void {
	$record = Mlsimport_Connections::get( $mls_id );
	// Without a registry record there is nothing to mark; the caller still
	// surfaces the API error itself, so the rejection is never silent.
	if ( null === $record ) {
		return;
	}
	$record['status'] = 'not_entitled';
	Mlsimport_Connections::save( $record );
}

/**
 * Whether a connection is currently marked 'not_entitled'.
 *
 * Import paths call this before contacting the SaaS so a rejected connection
 * skips its work (and stops hammering the API) while the others run normally.
 * An unregistered mls_id is NOT considered rejected — fail-open, matching the
 * live entitlement gate's semantics.
 *
 * @param int $mls_id MLS to check.
 * @return bool True when the registry marks this connection not entitled.
 */
function mlsimport_connection_not_entitled( int $mls_id ): bool {
	$record = Mlsimport_Connections::get( $mls_id );
	return null !== $record && 'not_entitled' === $record['status'];
}
