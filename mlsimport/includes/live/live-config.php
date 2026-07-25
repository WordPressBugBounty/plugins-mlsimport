<?php
/**
 * Live mode: the per-MLS provider configuration.
 *
 * The GET /clients SaaS call already returns the client's mld_details record
 * (api_import_url, api_token_url, api_media_url, type, expand,
 * field_corellation, mls_filter_params) — mlsimport_live_config_refresh()
 * stores the whitelisted keys as one option. Manual overrides from the
 * settings screen win over fetched values; provider type additionally falls
 * back to the mls_id ranges the admin class uses.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The per-MLS config: fetched values overlaid with manual overrides.
 *
 * @return array Empty array when no usable config exists (no api_import_url).
 */
function mlsimport_live_config(): array {
	// Start from the fetched mld_details record (guard against a corrupt option).
	$stored = get_option( 'mlsimport_live_mls_config', array() );
	$config = is_array( $stored ) ? $stored : array();
	// Manual settings-screen overrides win over fetched values.
	$config = array_merge( $config, mlsimport_live_config_overrides() );

	// Provider type: fall back to the mls_id range mapping when unset.
	if ( empty( $config['type'] ) ) {
		$config['type'] = mlsimport_live_provider_type_from_mls_id();
	}
	// Normalize type to lower-case for the dialect dispatch downstream.
	$config['type'] = strtolower( trim( (string) $config['type'] ) );

	// No base URL means no usable config — report empty so the gate stays off.
	if ( empty( $config['api_import_url'] ) ) {
		return array();
	}

	return $config;
}

/**
 * Fetch the mld_details config from the SaaS (GET clients) and store it.
 *
 * @return array|false The stored config, or false when the call failed.
 */
function mlsimport_live_config_refresh() {
	// Ask the SaaS GET /clients endpoint for this client's record.
	$answer = ThemeImport::globalApiRequestSaas( 'clients', array(), 'GET' );
	// No mls_data block means the call failed or the client isn't configured.
	if ( ! is_array( $answer ) || empty( $answer['mls_data'] ) || ! is_array( $answer['mls_data'] ) ) {
		return false;
	}

	// Copy only the whitelisted, scalar mld_details keys into the stored config.
	$config = array();
	foreach ( array( 'api_import_url', 'api_token_url', 'api_media_url', 'type', 'expand', 'field_corellation', 'mls_filter_params', 'mls_id' ) as $key ) {
		if ( isset( $answer['mls_data'][ $key ] ) && is_scalar( $answer['mls_data'][ $key ] ) ) {
			$config[ $key ] = (string) $answer['mls_data'][ $key ];
		}
	}

	// Persist the fetched config and hand it back to the caller.
	update_option( 'mlsimport_live_mls_config', $config );
	return $config;
}

/**
 * Provider type derived from the configured mls_id, mirroring the admin
 * class ranges: 900–3000 trestle, 5000–6000 rapattoni, 6000–7000 paragon,
 * 7000–8000 realtorca, 8000–9000 connectmls (8001 brightmls); bridge is the
 * default everywhere else (the Lambda's own default).
 *
 * @return string
 */
function mlsimport_live_provider_type_from_mls_id(): string {
	// The selected MLS id drives the range test below (0 when unset).
	$options = get_option( 'mlsimport_admin_options' );
	$mls_id  = is_array( $options ) && isset( $options['mlsimport_mls_name'] ) ? (int) $options['mlsimport_mls_name'] : 0;

	// 8001 is the single BrightMLS id — checked before the 8000–9000 band it sits in.
	if ( 8001 === $mls_id ) {
		return 'brightmls';
	}
	// 900–3000: Trestle (CoreLogic).
	if ( $mls_id >= 900 && $mls_id < 3000 ) {
		return 'trestle';
	}
	// 5000–6000: Rapattoni.
	if ( $mls_id >= 5000 && $mls_id < 6000 ) {
		return 'rapattoni';
	}
	// 6000–7000: Paragon.
	if ( $mls_id >= 6000 && $mls_id < 7000 ) {
		return 'paragon';
	}
	// 7000–8000: Realtor.ca (CREA).
	if ( $mls_id >= 7000 && $mls_id < 8000 ) {
		return 'realtorca';
	}
	// 8000–9000: ConnectMLS (BrightMLS 8001 already peeled off above).
	if ( $mls_id >= 8000 && $mls_id < 9000 ) {
		return 'connectmls';
	}
	// Everything else: bridge — the Lambda's own default.
	return 'bridge';
}
