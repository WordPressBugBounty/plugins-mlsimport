<?php
/**
 * Direct MLS access: the per-MLS provider configuration.
 *
 * The GET /clients SaaS call already returns the client's mld_details record
 * (api_import_url, api_token_url, api_media_url, type, expand,
 * field_corellation, mls_filter_params) — mlsimport_live_config_refresh()
 * stores the whitelisted keys as one option. Manual overrides from the
 * settings screen win over fetched values; provider type additionally falls
 * falls back through the Provider Family module for older saved settings.
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

	// Provider type: use the Provider Family compatibility map when unset.
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
	if ( ! empty( $config['type'] ) ) {
		$options = get_option( 'mlsimport_admin_options', array() );
		$mls_id  = ! empty( $config['mls_id'] )
			? $config['mls_id']
			: ( is_array( $options ) && isset( $options['mlsimport_mls_name'] ) ? $options['mlsimport_mls_name'] : '' );
		Mlsimport_Provider_Family::remember_type( $config['type'], $mls_id );
	}
	return $config;
}

/**
 * Return the selected MLS provider type through the public Provider Family
 * module. Saved provider data wins and numeric ranges are only a fallback.
 *
 * @return string
 */
function mlsimport_live_provider_type_from_mls_id(): string {
	// Read the selected MLS once and let the Provider Family module choose it.
	$options = get_option( 'mlsimport_admin_options' );
	$mls_id  = is_array( $options ) && isset( $options['mlsimport_mls_name'] ) ? (int) $options['mlsimport_mls_name'] : 0;
	$saved   = Mlsimport_Provider_Family::saved_type( $mls_id );

	return Mlsimport_Provider_Family::adapter( $saved, $mls_id )->type();
}
