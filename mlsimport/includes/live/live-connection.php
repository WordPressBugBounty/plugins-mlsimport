<?php
/**
 * Direct MLS access authentication executor.
 *
 * Provider adapters own credential requirements and the exact login plan. This
 * file performs the identical WordPress HTTP and transient work for those plans;
 * it contains no provider names, credential maps, or provider-type switches.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the active Direct MLS provider and its authentication plan.
 *
 * @param array|null $config Optional already-loaded MLS configuration.
 * @return array{provider:ResoBase,plan:array,config:array}
 */
function mlsimport_live_auth_context( $config = null ): array {
	$config  = is_array( $config ) ? $config : mlsimport_live_config();
	$type    = isset( $config['type'] ) ? (string) $config['type'] : '';
	$mls_id  = isset( $config['mls_id'] ) ? $config['mls_id'] : 0;
	$options = get_option( 'mlsimport_admin_options', array() );
	$options = is_array( $options ) ? $options : array();
	$provider = Mlsimport_Provider_Family::adapter( $type, $mls_id );

	return array(
		'provider' => $provider,
		'plan'     => $provider->direct_auth_plan( $options, $config ),
		'config'   => $config,
	);
}

/**
 * Whether the active provider has a complete Direct MLS login plan.
 *
 * @return bool
 */
function mlsimport_live_credentials_present(): bool {
	$config = mlsimport_live_config();
	if ( array() === $config ) {
		return false;
	}

	$context = mlsimport_live_auth_context( $config );
	return ! empty( $context['plan']['success'] );
}

/**
 * Resolve a Direct MLS access token from the provider-owned login plan.
 *
 * Stored-token plans return immediately. Expiring-token plans are executed and
 * cached by mlsimport_live_execute_token_request(). Failures return an empty
 * string; no secret, request body, or provider response is logged.
 *
 * @param array $config Per-MLS configuration.
 * @return string Access token, or empty when login cannot be completed.
 */
function mlsimport_live_access_token( array $config ): string {
	$context = mlsimport_live_auth_context( $config );
	$plan    = $context['plan'];
	if ( empty( $plan['success'] ) ) {
		return '';
	}

	if ( 'stored_token' === $plan['mode'] ) {
		return (string) $plan['token'];
	}

	return mlsimport_live_execute_token_request(
		$context['provider']->type(),
		$plan['request']
	);
}

/**
 * Execute and cache one provider-owned token request.
 *
 * Step 1 reuses a cached token or short failure marker. Step 2 sends JSON or
 * form data exactly as requested by the adapter. Step 3 validates the response
 * and caches either the access token or a five-minute failure marker.
 *
 * @param string $type Provider type, used only to name its transient.
 * @param array  $spec Adapter token request with url, body, and json keys.
 * @return string Access token, or empty on failure.
 */
function mlsimport_live_execute_token_request( string $type, array $spec ): string {
	$transient = 'mlsimport_live_token_' . $type;
	$cached    = get_transient( $transient );
	if ( is_string( $cached ) && '' !== $cached ) {
		return 'fail' === $cached ? '' : $cached;
	}

	$args = array( 'timeout' => 15 );
	if ( ! empty( $spec['json'] ) ) {
		$args['headers'] = array( 'Content-Type' => 'application/json' );
		$args['body']    = wp_json_encode( $spec['body'] );
	} else {
		$args['body'] = $spec['body'];
	}

	$response = wp_remote_post( $spec['url'], $args );
	$body     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$code     = (int) wp_remote_retrieve_response_code( $response );
	if ( is_wp_error( $response ) || 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
		set_transient( $transient, 'fail', 5 * MINUTE_IN_SECONDS );
		return '';
	}

	$token = (string) $body['access_token'];
	$ttl   = isset( $body['expires_in'] )
		? max( 60, (int) $body['expires_in'] - 60 )
		: HOUR_IN_SECONDS - 60;
	set_transient( $transient, $token, $ttl );

	return $token;
}

/**
 * Build the bearer headers for one Direct MLS listing or media request.
 *
 * @return array Empty when provider login fails.
 */
function mlsimport_live_auth_headers(): array {
	$token = mlsimport_live_access_token( mlsimport_live_config() );
	if ( '' === $token ) {
		return array();
	}

	return array(
		'Authorization' => 'Bearer ' . $token,
		'Accept'        => 'application/json',
	);
}
