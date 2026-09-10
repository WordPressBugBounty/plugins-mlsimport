<?php
/**
 * Remove-connection flow — server side.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The Connections tab lets an administrator remove an MLS connection (a row's
 * Remove action, admin/js/mlsimport-connections.js). This file owns everything
 * remove needs server-side, mirroring the add/edit module layout
 * (includes/mlsimport-connections-add.php / -edit.php):
 *
 *   - the flow core: delete the connection's per-connection suffixed options,
 *     drop its registry record, and — when the removed connection was the
 *     CURRENT one — promote the next-highest-priority remaining connection to
 *     current (mirroring its identity + credentials into the flat
 *     mlsimport_admin_options, which every legacy read path resolves current
 *     from). Removing the LAST connection leaves the install unconfigured,
 *   - the single AJAX handler the row's Remove action posts to.
 *
 * ONE RULE (what remove means): removing a connection deletes the connection
 * itself — record, credentials, field mapping, gathered metadata. It never
 * deletes content: imported listings and import tasks stay (tasks bound to the
 * removed connection simply stop running — task binding fails closed, #277).
 *
 * @since   7.3.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run the full remove flow: refuse / clean up / drop / promote.
 *
 * Step by step:
 * 1. Refuse: no id, or not a registered connection (the same "must name a
 *    registered connection" rule the edit flow uses).
 * 2. Note whether the removed connection is the CURRENT one — decided BEFORE
 *    anything is deleted.
 * 3. Delete the connection's suffixed per-connection options (the same five
 *    bases the #274 migration creates: field selection, sync settings, the
 *    two metadata blobs, the metadata-populated flag).
 * 4. Drop the registry record.
 * 5. Current-connection promotion: when the removed connection was current,
 *    the highest-priority REMAINING connection becomes current — its identity
 *    + credentials are mirrored into the flat mlsimport_admin_options through
 *    the shared mlsimport_connections_flat_sync(), the provider access tokens
 *    are dropped so the next MLS request re-authenticates as the promoted
 *    connection, and the global connection flag follows the promoted record's
 *    tested status. With NO connection remaining the flat identity is cleared
 *    and the flag deleted — the install is unconfigured again.
 * 6. Priority drives the dedupe winner (#267/#282): removal is a removal seam,
 *    so every currently flagged duplicate group is re-decided — a hidden
 *    duplicate whose winner belonged to the removed connection is promoted.
 *
 * @param int $mls_id The connection being removed.
 * @return array{success:bool, code:string, message:string}
 */
function mlsimport_connections_remove_execute( int $mls_id ): array {
	// Step 1: cheap refusals first — same pure rule as the edit flow.
	$refusal_messages = array(
		'no_mls'         => esc_html__( 'No connection selected.', 'mlsimport' ),
		'not_registered' => esc_html__( 'This MLS is not one of your connections.', 'mlsimport' ),
	);
	$refusal          = mlsimport_connections_edit_refusal( $mls_id, array_keys( Mlsimport_Connections::all() ) );
	if ( null !== $refusal ) {
		return array(
			'success' => false,
			'code'    => $refusal,
			'message' => $refusal_messages[ $refusal ],
		);
	}

	// Step 2: current-ness is decided before any state changes.
	$was_current = ( $mls_id === mlsimport_current_mls_id() );

	// Step 3: the connection's large per-MLS state goes with it.
	foreach ( array(
		'mlsimport_admin_fields_select',
		'mlsimport_admin_mls_sync',
		'mlsimport_mls_metadata_mls_data',
		'mlsimport_mls_metadata_mls_enums',
		'mlsimport_mls_metadata_populated',
	) as $base ) {
		mlsimport_delete_connection_option( $base, $mls_id );
	}

	// Step 4: the record itself.
	Mlsimport_Connections::remove( $mls_id );

	// Step 5: promotion when the current connection was removed.
	if ( $was_current ) {
		$options   = get_option( 'mlsimport_admin_options', array() );
		$options   = is_array( $options ) ? $options : array();
		$remaining = Mlsimport_Connections::all();

		if ( array() !== $remaining ) {
			// The registry is priority-sorted: the first record is the new
			// current. Mirror identity + credentials into the flat options.
			$promoted = reset( $remaining );
			$adapter  = Mlsimport_Provider_Family::adapter( (string) $promoted['provider_type'], (string) $promoted['mls_id'] );
			update_option(
				'mlsimport_admin_options',
				mlsimport_connections_flat_sync(
					$options,
					$promoted,
					mlsimport_connections_credential_options( $promoted, $adapter->credential_fields() )
				)
			);
			// The flag follows the promoted record's own tested status — a
			// promotion never fabricates a passed test.
			update_option( 'mlsimport_connection_test', 'yes' === $promoted['status'] ? 'yes' : '' );
		} else {
			// Last connection removed: unconfigured install. Identity cleared;
			// other flat keys (account login, theme choice, stored provider
			// credential slots) stay untouched by design.
			$options['mlsimport_mls_name']       = '';
			$options['mlsimport_mls_name_front'] = '';
			update_option( 'mlsimport_admin_options', $options );
			delete_option( 'mlsimport_connection_test' );
		}

		// Either way the old current connection's tokens must not be reused.
		Mlsimport_Provider_Family::clear_direct_access_tokens();
	}

	// Step 6: re-decide flagged duplicates under the shrunk connection set.
	mlsimport_dedupe_reevaluate_flagged();

	return array(
		'success' => true,
		'code'    => '',
		'message' => '',
	);
}

/**
 * AJAX: a row's Remove action.
 *
 * Step by step:
 * 1. Nonce (the Connections screen nonce) + administrator capability.
 * 2. Run the flow core for the posted mls_id and answer the JS, which
 *    reloads the page on success (table, slot strip, and current-connection
 *    surfaces all re-render server-side).
 *
 * @return void
 */
function mlsimport_ajax_connections_remove() {
	// Step 1: security.
	check_ajax_referer( 'mlsimport_connections_screen', 'security' );
	if ( ! current_user_can( 'administrator' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}

	// Step 2: run the flow and answer the row.
	$outcome = mlsimport_connections_remove_execute( isset( $_POST['mls_id'] ) ? (int) $_POST['mls_id'] : 0 );
	if ( ! $outcome['success'] ) {
		wp_send_json_error(
			array(
				'code'    => $outcome['code'],
				'message' => $outcome['message'],
			)
		);
	}
	wp_send_json_success();
}

add_action( 'wp_ajax_mlsimport_connections_remove', 'mlsimport_ajax_connections_remove' );
