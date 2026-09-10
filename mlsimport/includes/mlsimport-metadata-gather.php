<?php
/**
 * Connection-scoped MLS metadata gathering (issues #275/#281).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Gathering MLS metadata (theme schema + field data + enums from GET clients)
 * and reconciling the Field Configuration from it used to live only inside
 * the admin AJAX handler, hard-bound to the CURRENT connection. The Add-MLS
 * drawer (#281) must run the exact same gather for the connection it just
 * created — that is how the new connection's field mapping ends up
 * pre-seeded from theme defaults (decision #264: reconcile auto-enables
 * theme-schema fields it has never seen on the first metadata gather).
 *
 * This file holds that single shared gather core, scoped by mls_id:
 *
 *   - mls_id 0 / the current connection => exactly the historic behavior,
 *     including remembering the authoritative provider type globally;
 *   - any other registered connection    => the same gather, but every write
 *     lands in that connection's "_{mls_id}"-suffixed options and NO global
 *     current-connection state (saved provider type) is touched.
 *
 * The admin AJAX handler (mlsimport_saas_get_metadata_function) is now a
 * thin nonce/capability wrapper around this function.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gather MLS metadata from the SaaS and reconcile one connection's mapping.
 *
 * Step by step:
 * 1. GET clients?theme_id=<configured theme> from the SaaS.
 * 2. Shape guard: a failed request returns none of the metadata keys — STOP
 *    before touching anything, so a bad response can never overwrite good
 *    cached metadata or mark the site populated with an empty field list.
 * 3. Refresh the connection registry from mls_entitlements when the SaaS
 *    sends it (#276); a legacy response without it changes nothing.
 * 4. ONLY for the current connection: remember the authoritative provider
 *    type globally (the saved-type pair belongs to the current MLS — writing
 *    it for another connection would orphan the current one's saved type).
 * 5. Persist: the theme schema stays GLOBAL (decision #263); the two MLS
 *    metadata blobs are stored per-connection (#275).
 * 6. Reconcile the target connection's Field Configuration against the fresh
 *    metadata + theme schema. New metadata fields that exist in the theme
 *    schema are auto-enabled — this IS the "pre-seeded from theme defaults"
 *    behavior (#264). A reconcile failure clears the populated flag so the
 *    next page load retries.
 * 7. Mark the connection's metadata as populated.
 *
 * @param int $mls_id Connection to gather for; 0 = the current connection.
 * @return array{success:bool, code:string, message:string, detail:string,
 *               result:array, revision:int} Stable outcome for callers:
 *         code is '' on success, 'request_failed' (step 2) or
 *         'reconcile_failed' (step 6) otherwise; result carries the raw
 *         reconcile result for the reconcile_failed case.
 */
function mlsimport_gather_connection_metadata( int $mls_id = 0 ): array {
	$theme_start = new ThemeImport();

	// Step 1: GET the theme schema + MLS metadata for the configured theme,
	// NAMING the MLS the gather is for (#293, closing the #268 gap): the
	// legacy unscoped GET answered for whatever MLS the account record
	// happened to sit on, which could seed this connection's slot with
	// another MLS's metadata. mls_id 0 resolves to the current connection;
	// only a site with no connection at all still sends the legacy URL.
	$options = get_option( 'mlsimport_admin_options' );
	$options = is_array( $options ) ? $options : array();
	$target  = $mls_id > 0 ? $mls_id : mlsimport_current_mls_id();
	$url     = 'clients?theme_id=' . intval( $options['mlsimport_theme_used'] ?? 0 );
	if ( $target > 0 ) {
		$url .= '&mls_id=' . $target;
	}
	$answer = $theme_start::globalApiRequestSaas( $url, array(), 'GET' );

	// Step 2: refuse a response without the full metadata shape, untouched.
	if ( ! is_array( $answer ) || ! isset( $answer['theme_schema'], $answer['mls_data']['mls_meta_data'], $answer['mls_data']['mls_meta_enums'] ) ) {
		return array(
			'success'  => false,
			'code'     => 'request_failed',
			'message'  => esc_html__( 'Gathering MLS metadata failed. Nothing was changed - it will retry on the next page load.', 'mlsimport' ),
			'detail'   => is_array( $answer ) && isset( $answer['error_message'] ) ? $answer['error_message'] : '',
			'result'   => array(),
			'revision' => 0,
		);
	}

	// Step 2b (#293): a scoped request must get a CONFIRMED answer. The API
	// echoes mls_id on every scoped reply (#292), so anything else — a
	// mismatched echo, or no echo at all — means the answer cannot be
	// attributed to this connection. Saving it would poison this connection's
	// metadata slot, so refuse before ANY write (fail-closed, #279/#285).
	// A request that named no MLS (a site with no connection yet) has nothing
	// to confirm and keeps the legacy unscoped behavior.
	if ( $target > 0 && ! mlsimport_mls_scoped_echo_ok( $answer, $target ) ) {
		return array(
			'success'  => false,
			'code'     => 'wrong_mls',
			'message'  => esc_html__( 'The metadata service answered for a different MLS. Nothing was saved - please try again.', 'mlsimport' ),
			'detail'   => 'asked ' . $target . ', got ' . ( isset( $answer['mls_id'] ) ? intval( $answer['mls_id'] ) : 'no mls_id' ),
			'result'   => array(),
			'revision' => 0,
		);
	}

	// Step 3: refresh the registry (cap + matching records) from the response.
	mlsimport_apply_entitlements( $answer );

	// Step 4: the global saved-type pair belongs to the CURRENT connection
	// only. A scoped gather for another connection must not overwrite it.
	$is_current = 0 === $mls_id || mlsimport_current_mls_id() === $mls_id;
	if ( $is_current && isset( $answer['mls_data']['type'], $options['mlsimport_mls_name'] ) ) {
		Mlsimport_Provider_Family::remember_type(
			$answer['mls_data']['type'],
			$options['mlsimport_mls_name']
		);
	}

	// Step 5: cache metadata — theme schema global, MLS blobs per-connection.
	update_option( 'mlsimport_mls_metadata_theme_schema', $answer['theme_schema'] );
	mlsimport_update_connection_option( 'mlsimport_mls_metadata_mls_data', $answer['mls_data']['mls_meta_data'], $mls_id );
	mlsimport_update_connection_option( 'mlsimport_mls_metadata_mls_enums', $answer['mls_data']['mls_meta_enums'], $mls_id );

	// Step 6: one server-side reconcile seeds/updates this connection's
	// Field Configuration (theme-schema defaults auto-enable, #264).
	$metadata = is_string( $answer['mls_data']['mls_meta_data'] )
		? json_decode( $answer['mls_data']['mls_meta_data'], true )
		: $answer['mls_data']['mls_meta_data'];
	$metadata = is_array( $metadata ) ? $metadata : array();
	$result   = mlsimport_reconcile_field_configuration( $metadata, mlsimport_hardocde_theme_schema(), $mls_id );
	if ( ! $result['success'] ) {
		mlsimport_delete_connection_option( 'mlsimport_mls_metadata_populated', $mls_id );
		return array(
			'success'  => false,
			'code'     => 'reconcile_failed',
			'message'  => (string) ( $result['message'] ?? '' ),
			'detail'   => '',
			'result'   => $result,
			'revision' => 0,
		);
	}

	// Step 7: this connection's metadata is now gathered and reconciled.
	mlsimport_update_connection_option( 'mlsimport_mls_metadata_populated', 'yes', $mls_id );

	return array(
		'success'  => true,
		'code'     => '',
		'message'  => '',
		'detail'   => '',
		'result'   => $result,
		'revision' => (int) $result['revision'],
	);
}
