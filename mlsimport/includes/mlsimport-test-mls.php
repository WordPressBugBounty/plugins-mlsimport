<?php
/**
 * The Test MLS on first install (issue #376).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * A customer whose MLS has not approved any credentials yet cannot see the
 * plugin do anything: every MLS needs a token first. Catalogue row 100,
 * "Test MLS (sample listings)", is Bridge's public sample dataset (10,000
 * generated listings) that the SaaS pulls with its own server-held token, so
 * it needs no Provider Credentials on the site and never counts as one of the
 * MLSImport Account's MLS slots (Mlsimport_Provider_Family::TEST_MLS_ID,
 * TestMlsResoClass, mlsimport_connections_used()).
 *
 * This file owns the one moment it is set up: the first successful MLSImport
 * Account login of an install that holds no MLS Connection yet. The rule is
 * one conditional — registry empty and never seeded on this install — and
 * the work reuses the Add-MLS drawer's own flow end to end:
 *
 *   1. mlsimport_connections_add_execute(): refuse / test (PATCH clients with
 *      the mls_id alone) / register / promote to current;
 *   2. mlsimport_connections_seed_execute(): gather the MLS fields and fill
 *      the mapping from the theme defaults (a failed seed never undoes the
 *      connection; Field Options retries it);
 *   3. mlsimport_create_initial_import_item(): the first Import Task, bound
 *      to the Test MLS, status Active, ListPrice max 50,000. The sample data's
 *      cities are near-unique and every listing is Active, so price is the
 *      only filter that narrows the first import (about 100 listings).
 *
 * The customer removes the Test MLS from the Connections tab like any other
 * connection; nothing here deletes anything.
 *
 * @since   7.2.3
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option set once the Test MLS has been seeded on this install. */
const MLSIMPORT_TEST_MLS_SEEDED_OPTION = 'mlsimport_test_mls_seeded';

/** The Test MLS's catalogue name, used until the catalogue sync refreshes it. */
const MLSIMPORT_TEST_MLS_NAME = 'Test MLS (sample listings)';

/** ListPrice ceiling of the first task: about 100 of the 10,000 sample listings. */
const MLSIMPORT_TEST_MLS_MAX_PRICE = 50000;

/**
 * Seed the Test MLS once, on an install with no MLS Connection. Never throws.
 *
 * Step by step:
 * 1. The guard: a registered MLS Connection, or a previous seed on this
 *    install, means there is nothing to do. Removing the Test MLS later does
 *    not bring it back on the next login.
 * 2. Register it through the drawer's add flow with no credentials. A refused
 *    or failed connection test leaves the install untouched (and unseeded,
 *    so the next login tries again — e.g. the SaaS was unreachable).
 * 3. Mark the install seeded, then seed the field mapping and create the
 *    first Import Task. The mark comes first: the task must never be created
 *    twice even if the seed below dies mid-request.
 *
 * @return array{seeded:bool, reason:string, task_id:int}
 *         reason: '' when seeded, else 'registry_not_empty' |
 *         'already_seeded' | the add flow's refusal code.
 */
function mlsimport_seed_test_connection(): array {
	$done = static function ( bool $seeded, string $reason = '', int $task_id = 0 ): array {
		return array(
			'seeded'  => $seeded,
			'reason'  => $reason,
			'task_id' => $task_id,
		);
	};

	// Step 1: the guard.
	if ( array() !== Mlsimport_Connections::all() ) {
		return $done( false, 'registry_not_empty' );
	}
	if ( get_option( MLSIMPORT_TEST_MLS_SEEDED_OPTION ) ) {
		return $done( false, 'already_seeded' );
	}

	// Step 2: the drawer's add flow, with nothing to fill in.
	$add = mlsimport_connections_add_execute( Mlsimport_Provider_Family::TEST_MLS_ID, MLSIMPORT_TEST_MLS_NAME, array() );
	if ( ! $add['success'] ) {
		return $done( false, (string) $add['code'] );
	}

	// Step 3: once per install, then mapping + first task.
	update_option( MLSIMPORT_TEST_MLS_SEEDED_OPTION, time(), false );
	mlsimport_connections_seed_execute( Mlsimport_Provider_Family::TEST_MLS_ID );

	$task_id = mlsimport_create_initial_import_item(
		array(
			'import_title'    => __( 'Sample listings (Test MLS)', 'mlsimport' ),
			'mls_id'          => Mlsimport_Provider_Family::TEST_MLS_ID,
			'property_status' => 'publish',
			'agent_id'        => '',
			'property_user'   => '',
			'min_price'       => '',
			'max_price'       => MLSIMPORT_TEST_MLS_MAX_PRICE,
			'auto_update'     => 1,
		)
	);

	return $done( true, '', is_wp_error( $task_id ) ? 0 : (int) $task_id );
}
