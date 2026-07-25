<?php
/**
 * Live mode: deregister the write paths.
 *
 * Live mode stores nothing, so nothing may import: the hourly import cron,
 * the daily reconciliation import, the Action Scheduler media mover and the
 * ?mlsimport_cron=yes server trigger all detach while the gate is on, and
 * the Import Tasks UI hides. Toggling live off puts everything back on the
 * next request — the registrations themselves are untouched.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detach every import/write handler while live mode is on.
 *
 * Runs at init 0 — before the server-cron trigger (init 10) and before
 * WP-Cron dispatches its due events, so a scheduled fire finds no handler.
 *
 * @return void
 */
function mlsimport_live_guards(): void {
	// Gate off: leave all the normal import/write handlers registered.
	if ( ! mlsimport_live_mode_active() ) {
		return;
	}

	// Detach the two scheduled imports and the server-cron init trigger.
	remove_action( 'event_mls_import_auto', 'mlsimport_saas_event_mls_import_auto_function' );
	remove_action( 'mlsimport_reconciliation_event', 'mlsimport_saas_reconciliation_event_function' );
	remove_action( 'init', 'mlsimport_trigger_cron_job' );

	// Detach the Action Scheduler media-mover handlers, which hang off the
	// admin instance rather than a bare function.
	global $mlsimport;
	if ( is_object( $mlsimport ) && isset( $mlsimport->admin ) && is_object( $mlsimport->admin ) ) {
		remove_action( 'mlsimport_background_process_per_item', array( $mlsimport->admin, 'mlsimport_background_process_per_item_function' ) );
		remove_action( 'mlsimport_background_process_per_item_inital_batch', array( $mlsimport->admin, 'mlsimport_background_process_per_item_inital_batch_function' ) );
	}
}
add_action( 'init', 'mlsimport_live_guards', 0 );

/**
 * Hide the Import Tasks (mlsimport_item) admin UI while live mode is on.
 *
 * @param array  $args      Post type registration args.
 * @param string $post_type Post type slug.
 * @return array
 */
function mlsimport_live_hide_import_ui( $args, $post_type ) {
	// Only the Import Tasks CPT, and only while live mode is on.
	if ( 'mlsimport_item' === $post_type && mlsimport_live_mode_active() ) {
		// Hide the CPT's admin screens + menu entry (registration is untouched).
		$args['show_ui']      = false;
		$args['show_in_menu'] = false;
	}
	return $args;
}
add_filter( 'register_post_type_args', 'mlsimport_live_hide_import_ui', 10, 2 );
