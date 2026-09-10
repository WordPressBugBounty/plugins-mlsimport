<?php
/**
 * Live mode: the daily subscription (entitlement) check.
 *
 * Soft gate semantics (locked 2026-07-03): fail-open on check errors — a
 * network/server problem keeps the last definitive answer and retries hourly;
 * fail-closed only on an explicit "not entitled" (auth-rejected) answer,
 * which also deletes the stored MLS metadata + live config. Re-subscribing
 * re-fetches everything through the normal setup fetch.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the MLSImport subscription is (still) considered active.
 *
 * @return bool
 */
function mlsimport_live_entitled(): bool {
	// Only hit the SaaS when the per-check throttle transient has lapsed.
	if ( false === get_transient( 'mlsimport_live_entitlement_checked' ) ) {
		mlsimport_live_entitlement_refresh();
	}
	// Fail-open: anything other than a definitive 'no' counts as entitled.
	return 'no' !== get_option( 'mlsimport_live_entitlement_state', 'yes' );
}

/**
 * Run the entitlement check against the SaaS and store the outcome.
 *
 * @return void
 */
function mlsimport_live_entitlement_refresh(): void {
	// A GET /clients that returns the client's mls_data proves the subscription.
	$answer = ThemeImport::globalApiRequestSaas( 'clients', array(), 'GET' );

	// Entitled: record 'yes' and throttle the next check to a day out.
	if ( is_array( $answer ) && ! empty( $answer['mls_data'] ) ) {
		// Refresh the connection registry from mls_entitlements when the SaaS
		// sends it (#276); a legacy response without it changes nothing.
		mlsimport_apply_entitlements( $answer );
		update_option( 'mlsimport_live_entitlement_state', 'yes' );
		set_transient( 'mlsimport_live_entitlement_checked', 1, DAY_IN_SECONDS );
		return;
	}

	// Only an explicit auth rejection (401/403) is a definitive "not entitled".
	$code = is_array( $answer ) && isset( $answer['error_code'] ) ? (int) $answer['error_code'] : 0;
	if ( in_array( $code, array( 401, 403 ), true ) ) {
		// Definitive "not entitled": close the gate and drop the MLS data the
		// subscription paid for. Setup re-fetches it on re-subscribe.
		update_option( 'mlsimport_live_entitlement_state', 'no' );
		// Drop the MLS metadata + live config the subscription paid for; setup
		// re-fetches all of it on re-subscribe. The blobs are per-connection
		// (#275): drop the current connection's copies.
		mlsimport_delete_connection_option( 'mlsimport_mls_metadata_mls_data' );
		mlsimport_delete_connection_option( 'mlsimport_mls_metadata_mls_enums' );
		mlsimport_delete_connection_option( 'mlsimport_mls_metadata_populated' );
		delete_option( 'mlsimport_live_mls_config' );
		// Throttle a day out like the entitled path.
		set_transient( 'mlsimport_live_entitlement_checked', 1, DAY_IN_SECONDS );
		return;
	}

	// Indeterminate (network error, 5xx, token endpoint down): keep the last
	// definitive state and retry sooner.
	set_transient( 'mlsimport_live_entitlement_checked', 1, HOUR_IN_SECONDS );
}

/**
 * Admin notice when the subscription check has closed the gate.
 *
 * @return void
 */
function mlsimport_live_entitlement_notice(): void {
	// Only show admins the pause notice, and only when the gate is actually closed.
	if ( ! current_user_can( 'manage_options' ) || 'no' !== get_option( 'mlsimport_live_entitlement_state', 'yes' ) ) {
		return;
	}
	// Render the dismissible-less error notice explaining why listings stopped.
	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'MLSImport live mode is paused: your subscription appears to be inactive. Listings are no longer served. Renew your subscription and reconnect to resume.', 'mlsimport' )
		. '</p></div>';
}
