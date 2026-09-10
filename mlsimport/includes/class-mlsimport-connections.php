<?php
/**
 * Multi-MLS connection registry (issue #274, spec #273).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The plugin historically held exactly ONE MLS configuration in flat global
 * options. Multi-MLS support replaces that single slot with first-class
 * "connections": one record per MLS, all stored together in the single
 * non-autoloaded option 'mlsimport_connections', keyed by mls_id (decision
 * #263 — the mls_id IS the connection identifier, no synthetic id).
 *
 * Each record holds the SMALL per-MLS state:
 *   - mls_id        (int)    the numeric MLS identifier
 *   - mls_name      (string) human-readable MLS name for admin display
 *   - provider_type (string) adapter type ('bridge', 'trestle', ...)
 *   - creds         (array)  adapter-generic credential names => values
 *                            (client_id, client_secret, username, password,
 *                            mls_token) — never provider-slot-named
 *   - status        (string) connection test result ('yes' = tested OK, '')
 *   - live_config   (array)  live/direct MLS config for this connection
 *   - priority      (int)    1 = highest; drives dedupe winner + display order
 *   - tested_at     (int)    unix time of the last connection test (0 = never)
 *
 * LARGE per-MLS state (metadata blobs, field selection, sync settings) does
 * NOT live in the record — it lives in separate "_{mls_id}"-suffixed options,
 * resolved through option_key() / get_suffixed_option() below so every caller
 * builds the suffixed key the same single way.
 *
 * The rest of the plugin must resolve connections through this accessor and
 * never read the 'mlsimport_connections' option directly.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accessor for the mlsimport_connections registry option.
 */
class Mlsimport_Connections {

	/**
	 * The single registry option. Non-autoloaded: it is only needed on
	 * admin/import/cron paths, never on every front-end request.
	 */
	const OPTION = 'mlsimport_connections';

	/**
	 * Return every connection record, sorted by priority (1 first).
	 *
	 * Step by step:
	 * 1. Read the raw option (empty array when absent).
	 * 2. Normalize each record so callers always see the full field set.
	 * 3. Sort by the priority attribute ascending — priority 1 is the
	 *    highest-priority connection (the dedupe winner / legacy default).
	 *
	 * @return array<int, array> Records keyed by mls_id, priority order.
	 */
	public static function all(): array {
		$connections = array();

		// Step 1+2: normalize whatever is stored, dropping unusable entries.
		foreach ( self::read() as $record ) {
			$record = self::normalize( (array) $record );
			if ( $record['mls_id'] > 0 ) {
				$connections[ $record['mls_id'] ] = $record;
			}
		}

		// Step 3: order by priority while keeping mls_id keys.
		uasort(
			$connections,
			static function ( array $a, array $b ): int {
				return $a['priority'] <=> $b['priority'];
			}
		);

		return $connections;
	}

	/**
	 * Return one connection record by mls_id, or null when not registered.
	 *
	 * @param int|string $mls_id Numeric MLS identifier.
	 * @return array|null Normalized record, or null.
	 */
	public static function get( $mls_id ): ?array {
		$all = self::all();
		return $all[ (int) $mls_id ] ?? null;
	}

	/**
	 * Insert or update one connection record (upsert keyed by mls_id).
	 *
	 * Step by step:
	 * 1. Normalize the record; refuse one without a positive mls_id.
	 * 2. When no priority was given (0), append at the lowest priority
	 *    (max existing + 1) — "new connections join at the end" (#271).
	 * 3. Write the whole registry back, non-autoloaded.
	 *
	 * @param array $record Connection record (at minimum 'mls_id').
	 * @return bool True when the registry was persisted.
	 */
	public static function save( array $record ): bool {
		// Step 1: a record without an identity cannot be stored.
		$record = self::normalize( $record );
		if ( $record['mls_id'] <= 0 ) {
			return false;
		}

		$connections = self::all();

		// Step 2: missing priority => append after the current lowest.
		if ( $record['priority'] <= 0 ) {
			$max = 0;
			foreach ( $connections as $existing ) {
				$max = max( $max, $existing['priority'] );
			}
			$record['priority'] = $max + 1;
		}

		// Step 3: upsert by mls_id and persist.
		$connections[ $record['mls_id'] ] = $record;
		return self::write( $connections );
	}

	/**
	 * Remove one connection record from the registry.
	 *
	 * Step by step:
	 * 1. Unregistered id => false (nothing to remove).
	 * 2. Drop the record and persist the remaining registry unchanged —
	 *    remaining priorities keep their values (their relative order is what
	 *    matters; the next reorder renumbers 1..N anyway).
	 *
	 * Registry record only: the caller (the remove flow,
	 * includes/mlsimport-connections-remove.php) owns cleaning up the
	 * connection's suffixed options and promoting a new current connection.
	 *
	 * @param int|string $mls_id Numeric MLS identifier.
	 * @return bool True when the record existed and the registry was persisted.
	 */
	public static function remove( $mls_id ): bool {
		$mls_id      = (int) $mls_id;
		$connections = self::all();

		// Step 1: only registered connections can be removed.
		if ( ! isset( $connections[ $mls_id ] ) ) {
			return false;
		}

		// Step 2: drop and persist.
		unset( $connections[ $mls_id ] );
		return self::write( $connections );
	}

	/**
	 * Refresh every registered connection's display name from the MLS
	 * catalogue (the public GET /mls list, id => name).
	 *
	 * WHY: mls_name is a snapshot taken when the connection was added. When
	 * an MLS is renamed on the MLSImport side (e.g. "Austin Board of
	 * Realtors" becoming "Unlock MLS"), every admin surface that renders from
	 * the registry kept showing the old name forever, even after the
	 * catalogue cache was cleared. The fetch that refills that cache is the
	 * one moment we hold the current names, so it calls this.
	 *
	 * Step by step:
	 * 1. Walk the registry; look each mls_id up in the catalogue.
	 * 2. Skip ids the catalogue no longer lists (never blank a name).
	 * 3. Overwrite only names that actually differ.
	 * 4. Persist once, and only when something changed.
	 *
	 * @param array $catalogue mls_id => current MLS name.
	 * @return int Number of records whose name changed.
	 */
	public static function sync_names( array $catalogue ): int {
		$connections = self::all();
		$changed     = 0;

		// Step 1-3: apply the catalogue name wherever it differs.
		foreach ( $connections as $mls_id => $record ) {
			$current = trim( (string) ( $catalogue[ $mls_id ] ?? $catalogue[ (string) $mls_id ] ?? '' ) );
			if ( '' === $current || $current === $record['mls_name'] ) {
				continue;
			}
			$connections[ $mls_id ]['mls_name'] = $current;
			++$changed;
		}

		// Step 4: one write, only when needed.
		if ( $changed > 0 ) {
			self::write( $connections );
		}
		return $changed;
	}

	/**
	 * Build the per-connection suffixed option key for large per-MLS state.
	 *
	 * Example: option_key( 'mlsimport_admin_fields_select', 103 )
	 *          => 'mlsimport_admin_fields_select_103'.
	 *
	 * @param string     $base   Flat (legacy/global) option name.
	 * @param int|string $mls_id Numeric MLS identifier.
	 * @return string Suffixed option name.
	 */
	public static function option_key( string $base, $mls_id ): string {
		return $base . '_' . (int) $mls_id;
	}

	/**
	 * Read a per-connection suffixed option.
	 *
	 * @param string     $base    Flat (legacy/global) option name.
	 * @param int|string $mls_id  Numeric MLS identifier.
	 * @param mixed      $default Returned when the suffixed option is absent.
	 * @return mixed Stored value or $default.
	 */
	public static function get_suffixed_option( string $base, $mls_id, $default = false ) {
		return get_option( self::option_key( $base, $mls_id ), $default );
	}

	/**
	 * Read the raw registry option.
	 *
	 * @return array Raw stored value ([] when absent or malformed).
	 */
	private static function read(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Persist the registry, guaranteeing the option stays non-autoloaded.
	 *
	 * add_option() with autoload 'no' creates it correctly the first time;
	 * update_option() afterwards keeps the stored autoload flag.
	 *
	 * @param array $connections Records keyed by mls_id.
	 * @return bool True when the value is stored (add, update, or unchanged).
	 */
	private static function write( array $connections ): bool {
		// First write: create the row non-autoloaded.
		if ( false === get_option( self::OPTION, false ) ) {
			return (bool) add_option( self::OPTION, $connections, '', 'no' );
		}
		// Later writes: update_option returning false for an identical value
		// still means the registry holds what we were asked to store.
		update_option( self::OPTION, $connections, false );
		return get_option( self::OPTION ) === $connections;
	}

	/**
	 * Force one record into the canonical field set and types.
	 *
	 * @param array $record Raw record.
	 * @return array Record with every canonical field present and typed.
	 */
	private static function normalize( array $record ): array {
		return array(
			'mls_id'        => (int) ( $record['mls_id'] ?? 0 ),
			'mls_name'      => (string) ( $record['mls_name'] ?? '' ),
			'provider_type' => strtolower( trim( (string) ( $record['provider_type'] ?? '' ) ) ),
			'creds'         => is_array( $record['creds'] ?? null ) ? $record['creds'] : array(),
			'status'        => (string) ( $record['status'] ?? '' ),
			'live_config'   => is_array( $record['live_config'] ?? null ) ? $record['live_config'] : array(),
			'priority'      => (int) ( $record['priority'] ?? 0 ),
			'tested_at'     => (int) ( $record['tested_at'] ?? 0 ),
		);
	}
}
