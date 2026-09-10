<?php
/**
 * Settings export/import payload transfer (issue #275, spec #273/#264).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The plugin's settings import feature accepts one JSON payload (pasted into
 * the administrative/import options) and restores the field-mapping and sync
 * option groups from it. Historically that payload carried ONE flat copy of
 * each group. With multi-MLS, field selection and sync settings are
 * per-connection (#264), so the payload must round-trip one section PER
 * CONNECTION, keyed by mls_id.
 *
 * PAYLOAD SHAPE
 * -------------
 * New exports carry:
 *   mlsimport_connections_settings: {
 *     "<mls_id>": {
 *       mlsimport_admin_fields_select: {...},   // that connection's mapping
 *       mlsimport_admin_mls_sync:      {...}    // that connection's sync settings
 *     }, ...
 *   }
 *   plus the legacy flat keys for the CURRENT connection (old importers can
 *   still read a new export) and the untouched global groups
 *   (mlsimport_admin_import_options, mlsimport_admin_use_transients).
 *
 * IMPORT RULE (one rule): when the per-connection block is present it is
 * authoritative and the legacy flat keys are ignored; an old flat-only
 * payload restores into the CURRENT connection exactly as before.
 *
 * Both legacy import entry points in the admin class delegate here so the
 * decode-and-restore logic exists once.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the complete exportable settings payload.
 *
 * Step by step:
 * 1. Start with the two global (connection-agnostic) groups, unchanged.
 * 2. Add the legacy flat sections holding the CURRENT connection's values so
 *    a pre-multi-MLS site can still import a payload exported here.
 * 3. Add one section per registered connection, keyed by mls_id, each
 *    carrying that connection's field selection and sync settings read
 *    through the per-connection resolver.
 *
 * @return array JSON-encodable settings payload.
 */
function mlsimport_export_settings_payload(): array {
	// Step 1: global groups are not per-connection (#264 leaves them alone).
	$payload = array(
		'mlsimport_admin_import_options' => get_option( 'mlsimport_admin_import_options', array() ),
		'mlsimport_admin_use_transients' => get_option( 'mlsimport_admin_use_transients', '' ),
	);

	// Step 2: legacy flat sections = the current connection's values.
	$payload['mlsimport_admin_fields_select'] = mlsimport_get_connection_option( 'mlsimport_admin_fields_select', array() );
	$payload['mlsimport_admin_mls_sync']      = mlsimport_get_connection_option( 'mlsimport_admin_mls_sync', array() );

	// Step 3: one authoritative section per connection, keyed by mls_id
	// (JSON-encoding turns the keys into strings; import casts them back).
	$payload['mlsimport_connections_settings'] = array();
	foreach ( Mlsimport_Connections::all() as $mls_id => $record ) {
		$payload['mlsimport_connections_settings'][ $mls_id ] = array(
			'mlsimport_admin_fields_select' => mlsimport_get_connection_option( 'mlsimport_admin_fields_select', array(), $mls_id ),
			'mlsimport_admin_mls_sync'      => mlsimport_get_connection_option( 'mlsimport_admin_mls_sync', array(), $mls_id ),
		);
	}

	return $payload;
}

/**
 * Restore settings from a decoded import payload.
 *
 * Step by step:
 * 1. Global groups restore as-is when present (unchanged behavior).
 * 2. A payload WITH the per-connection block restores each listed
 *    connection's field selection (through that connection's own
 *    Field Configuration module, so normalization/revision rules apply)
 *    and sync settings into its suffixed options.
 * 3. A payload WITHOUT the block is a legacy flat export: its two flat
 *    sections restore into the CURRENT connection, exactly as before.
 *
 * @param array $decode Decoded JSON payload.
 * @return void
 */
function mlsimport_import_settings_payload( array $decode ): void {
	// Step 1: connection-agnostic groups.
	if ( isset( $decode['mlsimport_admin_import_options'] ) && is_array( $decode['mlsimport_admin_import_options'] ) ) {
		update_option( 'mlsimport_admin_import_options', $decode['mlsimport_admin_import_options'] );
	}
	if ( isset( $decode['mlsimport_admin_use_transients'] ) ) {
		update_option( 'mlsimport_admin_use_transients', $decode['mlsimport_admin_use_transients'] );
	}

	// Step 2: multi-MLS payload — the per-connection block is authoritative.
	if ( isset( $decode['mlsimport_connections_settings'] ) && is_array( $decode['mlsimport_connections_settings'] ) ) {
		foreach ( $decode['mlsimport_connections_settings'] as $mls_id => $sections ) {
			$mls_id = (int) $mls_id;
			if ( $mls_id <= 0 || ! is_array( $sections ) ) {
				continue;
			}
			if ( isset( $sections['mlsimport_admin_fields_select'] ) && is_array( $sections['mlsimport_admin_fields_select'] ) ) {
				mlsimport_import_field_configuration( $sections['mlsimport_admin_fields_select'], $mls_id );
			}
			if ( isset( $sections['mlsimport_admin_mls_sync'] ) && is_array( $sections['mlsimport_admin_mls_sync'] ) ) {
				mlsimport_update_connection_option( 'mlsimport_admin_mls_sync', $sections['mlsimport_admin_mls_sync'], $mls_id );
			}
		}
		return;
	}

	// Step 3: legacy flat payload — restore into the current connection.
	if ( isset( $decode['mlsimport_admin_fields_select'] ) && is_array( $decode['mlsimport_admin_fields_select'] ) ) {
		mlsimport_import_field_configuration( $decode['mlsimport_admin_fields_select'] );
	}
	if ( isset( $decode['mlsimport_admin_mls_sync'] ) ) {
		mlsimport_update_connection_option( 'mlsimport_admin_mls_sync', $decode['mlsimport_admin_mls_sync'] );
	}
}

/**
 * Validate and import a settings JSON document from the admin UI.
 *
 * Keeping JSON decoding outside the renderer gives every future UI the same
 * all-or-nothing boundary: malformed input is rejected before the payload
 * importer can update a single option.
 *
 * @param string $json Raw JSON document.
 * @return array{success: bool, code: string}
 */
function mlsimport_import_settings_json( string $json ): array {
	$decoded_object = json_decode( trim( $json ) );
	if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $decoded_object ) ) {
		return array(
			'success' => false,
			'code'    => 'invalid_json_object',
		);
	}

	$decoded = json_decode( trim( $json ), true );
	mlsimport_import_settings_payload( is_array( $decoded ) ? $decoded : array() );

	return array(
		'success' => true,
		'code'    => 'imported',
	);
}
