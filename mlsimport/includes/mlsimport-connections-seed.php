<?php
/**
 * Add-MLS drawer — the follow-up field-mapping seed (issue #325).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The drawer's add used to save the connection and THEN, inside the same
 * request, run the connection-scoped metadata gather that seeds the new
 * connection's field mapping from theme defaults (#264). That gather is a
 * second SaaS round trip carrying the full metadata + enum payload. On hosts
 * with a PHP or proxy time limit the request died after the record was
 * written: the browser got no answer, the drawer painted a failure, and the
 * user's retry was refused with "already one of your connections".
 *
 * The rule now: the add request answers the moment the connection is saved,
 * and the seed is its own request. This file owns that seed:
 *
 *   - the flow core: refuse an unregistered id, otherwise run the scoped
 *     gather and report whether the mapping was seeded,
 *   - the AJAX handler the drawer posts right after a successful add.
 *
 * A failed seed never undoes the add — the connection works; the drawer tells
 * the user to open Field Options, which retries the gather on its own.
 *
 * The add flow lives in includes/mlsimport-connections-add.php; the drawer
 * behavior in admin/js/mlsimport-connections-drawer.js.
 *
 * @since   7.2.1
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seed one registered connection's field mapping. Never terminates.
 *
 * Step by step:
 * 1. Refuse an id that is not a registered connection — there is nothing to
 *    seed and no reason to contact the SaaS.
 * 2. Run the connection-scoped metadata gather (#264/#281): it reconciles this
 *    connection's Field Configuration and auto-enables the theme defaults.
 * 3. Report success either way — the connection exists regardless — with the
 *    'seeded' flag carrying the gather outcome, so the drawer can tell the
 *    user to open Field Options when the seed is still pending.
 *
 * @param int $mls_id The registered connection to seed.
 * @return array{success:bool, code:string, message:string, seeded:bool}
 */
function mlsimport_connections_seed_execute( int $mls_id ): array {
	// Step 1: only a registered connection can be seeded.
	if ( null === Mlsimport_Connections::get( $mls_id ) ) {
		return array(
			'success' => false,
			'code'    => 'unknown_connection',
			'message' => esc_html__( 'This MLS is not one of your connections.', 'mlsimport' ),
			'seeded'  => false,
		);
	}

	// Step 2: the scoped gather seeds the mapping from theme defaults.
	$gather = mlsimport_gather_connection_metadata( $mls_id );

	// Step 3: the seed request succeeded; 'seeded' says whether the mapping did.
	return array(
		'success' => true,
		'code'    => (string) $gather['code'],
		'message' => (string) $gather['message'],
		'seeded'  => (bool) $gather['success'],
	);
}

/**
 * AJAX: the Add-MLS drawer's follow-up seed after a saved connection.
 *
 * Step by step:
 * 1. Nonce (the Connections screen nonce) + administrator capability.
 * 2. Read the posted MLS id.
 * 3. Run the seed core; the drawer repaints from 'seeded'.
 *
 * @return void
 */
function mlsimport_ajax_connections_seed() {
	// Step 1: security.
	check_ajax_referer( 'mlsimport_connections_screen', 'security' );
	if ( ! current_user_can( 'administrator' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}

	// Step 2: which connection.
	$mls_id = isset( $_POST['mls_id'] ) ? (int) $_POST['mls_id'] : 0;

	// Step 3: seed and answer.
	$outcome = mlsimport_connections_seed_execute( $mls_id );
	if ( ! $outcome['success'] ) {
		wp_send_json_error(
			array(
				'code'    => $outcome['code'],
				'message' => $outcome['message'],
			)
		);
	}
	wp_send_json_success(
		array(
			'mls_id' => $mls_id,
			'seeded' => $outcome['seeded'],
		)
	);
}

add_action( 'wp_ajax_mlsimport_connections_seed', 'mlsimport_ajax_connections_seed' );
