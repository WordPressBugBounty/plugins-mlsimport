<?php
/**
 * Per-connection option resolution helpers (issue #275, spec #273/#264).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * With multi-MLS support the LARGE per-MLS state (field selection, sync
 * settings, metadata blobs, the metadata-populated flag) lives in
 * "_{mls_id}"-suffixed options — one copy per connection — created by the
 * #274 migration and owned per connection from #275 on. Every read, write,
 * and delete of that state must resolve the option NAME the same single way:
 *
 *   base name + current (or explicit) mls_id  =>  "base_{mls_id}"
 *
 * These helpers are that single way. Call sites never build suffixed names
 * by hand and never need to know which connection is current.
 *
 * RESOLUTION RULE (one rule, no layered fallbacks):
 * - mls_id given (> 0)          => that connection's suffixed option.
 * - mls_id omitted (0)          => the CURRENT connection's suffixed option,
 *                                  current = mlsimport_admin_options['mlsimport_mls_name']
 *                                  (task-level binding arrives with #265).
 * - no current connection (0)   => the flat legacy name, so an unconfigured
 *                                  install behaves exactly as before multi-MLS.
 *
 * The flat legacy options are NOT read as a fallback for a configured install:
 * the #274 load-time migration has already copied them into the current
 * connection's suffixed options before any of these helpers run (it hooks
 * init priority 6; admin screens, AJAX, and cron all run later).
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the mls_id of the CURRENT connection.
 *
 * Step by step:
 * 1. Read the flat admin options (still the single "which MLS is selected"
 *    source for every settings screen — decision #266 keeps it that way
 *    until task binding in #265).
 * 2. Cast the stored MLS name (a numeric id string) to int.
 * 3. 0 means "no MLS configured yet".
 *
 * @return int Current mls_id, or 0 when the install is unconfigured.
 */
function mlsimport_current_mls_id(): int {
	// Step 1: the selected MLS lives in the flat admin options.
	$options = get_option( 'mlsimport_admin_options', array() );
	if ( ! is_array( $options ) ) {
		return 0;
	}

	// Step 2+3: the MLS "name" field holds the numeric MLS identifier.
	return (int) trim( (string) ( $options['mlsimport_mls_name'] ?? '' ) );
}

/**
 * Resolve the concrete option name for one piece of per-connection state.
 *
 * Step by step:
 * 1. An explicit mls_id (> 0) wins; 0 means "the current connection".
 * 2. A resolved id > 0 yields the suffixed per-connection name.
 * 3. No connection at all (unconfigured install) yields the flat legacy
 *    name so pre-multi-MLS behavior is preserved unchanged.
 *
 * @param string $base   Flat (legacy) option name, e.g. 'mlsimport_admin_mls_sync'.
 * @param int    $mls_id Explicit connection id, or 0 for the current one.
 * @return string The option name to read/write.
 */
function mlsimport_connection_option_name( string $base, int $mls_id = 0 ): string {
	// Step 1: fall back to the current connection when no id was given.
	if ( $mls_id <= 0 ) {
		$mls_id = mlsimport_current_mls_id();
	}

	// Step 2+3: suffixed when a connection exists, flat legacy name otherwise.
	return $mls_id > 0 ? Mlsimport_Connections::option_key( $base, $mls_id ) : $base;
}

/**
 * Read one piece of per-connection state.
 *
 * @param string $base    Flat (legacy) option name.
 * @param mixed  $default Returned when the resolved option is absent.
 * @param int    $mls_id  Explicit connection id, or 0 for the current one.
 * @return mixed Stored value or $default.
 */
function mlsimport_get_connection_option( string $base, $default = false, int $mls_id = 0 ) {
	return get_option( mlsimport_connection_option_name( $base, $mls_id ), $default );
}

/**
 * Write one piece of per-connection state.
 *
 * Step by step:
 * 1. Resolve the concrete option name.
 * 2. First write creates the row NON-autoloaded — per-connection blobs are
 *    large and only needed on admin/import paths (same rule as the #274
 *    migration copies), never on every front-end request.
 * 3. Later writes update in place; update_option keeps the stored autoload.
 *
 * @param string $base   Flat (legacy) option name.
 * @param mixed  $value  Value to store.
 * @param int    $mls_id Explicit connection id, or 0 for the current one.
 * @return bool True when the option now holds the value.
 */
function mlsimport_update_connection_option( string $base, $value, int $mls_id = 0 ): bool {
	// Step 1: one resolution path for every writer.
	$name = mlsimport_connection_option_name( $base, $mls_id );

	// Step 2: create non-autoloaded on first write.
	if ( false === get_option( $name, false ) ) {
		return (bool) add_option( $name, $value, '', 'no' );
	}

	// Step 3: update; false for an identical value still means "stored".
	update_option( $name, $value, false );
	return get_option( $name ) === $value;
}

/**
 * Delete one piece of per-connection state.
 *
 * Used by the "force a fresh metadata gather / reset field mapping" paths;
 * the delete is scoped to ONE connection so other connections' gathered
 * state and per-field customizations stay isolated (#264).
 *
 * @param string $base   Flat (legacy) option name.
 * @param int    $mls_id Explicit connection id, or 0 for the current one.
 * @return bool True when a stored option was deleted.
 */
function mlsimport_delete_connection_option( string $base, int $mls_id = 0 ): bool {
	return delete_option( mlsimport_connection_option_name( $base, $mls_id ) );
}
