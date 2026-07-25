<?php
/**
 * Live mode: the auth header for direct MLS requests.
 *
 * Bridge/Spark/MLSGrid/RMLS use the stored MLS token as-is. The expiring-token
 * providers run an automatic credentials -> access-token swap — the identical
 * grants the AWS token functions use — cached in a transient for the token's
 * lifetime. Ported swaps: Trestle, Realcomp (Trestle credential slots),
 * Realtor.ca, Rapattoni, BrightMLS (Okta). Providers with no as-is token and
 * no ported swap (Paragon, ConnectMLS) have no usable credentials, which
 * keeps the live gate off for them.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Providers whose stored token is sent as-is (no swap needed).
 */
function mlsimport_live_token_as_is_types(): array {
	// Providers whose stored MLS token is a long-lived bearer sent verbatim.
	return array( 'bridge', 'spark', 'mlsgrid', 'rmls', 'utah_real_estate' );
}

/**
 * Whether the credentials needed for the configured provider are saved.
 *
 * @return bool
 */
function mlsimport_live_credentials_present(): bool {
	// No config, no credentials to speak of.
	$config = mlsimport_live_config();
	if ( array() === $config ) {
		return false;
	}

	// As-is providers need a non-empty stored token; everything else needs a
	// buildable swap spec (which itself checks its credential slots).
	$type = isset( $config['type'] ) ? (string) $config['type'] : 'bridge';
	if ( in_array( $type, mlsimport_live_token_as_is_types(), true ) ) {
		return '' !== mlsimport_live_stored_token();
	}

	return null !== mlsimport_live_token_swap_spec( $type, $config );
}

/**
 * The access token for the configured provider.
 *
 * @param array $config Per-MLS config.
 * @return string Empty string when unavailable (missing creds, unported
 *         provider, or a rejected swap).
 */
function mlsimport_live_access_token( array $config ): string {
	$type = isset( $config['type'] ) ? (string) $config['type'] : 'bridge';

	// As-is providers: hand back the stored token unchanged.
	if ( in_array( $type, mlsimport_live_token_as_is_types(), true ) ) {
		return mlsimport_live_stored_token();
	}

	// Expiring-token providers: run (or reuse a cached) credentials->token swap.
	return mlsimport_live_swap_token( $type, $config );
}

/**
 * The MLS token saved by the connection setup, as-is.
 *
 * @return string
 */
function mlsimport_live_stored_token(): string {
	// The token saved during connection setup, trimmed; '' when never stored.
	$options = get_option( 'mlsimport_admin_options' );
	return is_array( $options ) && isset( $options['mlsimport_mls_token'] )
		? trim( (string) $options['mlsimport_mls_token'] )
		: '';
}

/**
 * The token-swap request for an expiring-token provider — the same grant the
 * matching AWS token function runs.
 *
 * @param string $type   Provider type.
 * @param array  $config Per-MLS config (rapattoni reads api_token_url).
 * @return array|null {url, body, json} or null when the provider is unported
 *         or its credentials are incomplete.
 */
function mlsimport_live_token_swap_spec( string $type, array $config ) {
	// Credential slots all live in the admin options; $opt reads one, trimmed.
	$options = get_option( 'mlsimport_admin_options' );
	$options = is_array( $options ) ? $options : array();
	$opt     = static function ( string $key ) use ( $options ): string {
		return isset( $options[ $key ] ) ? trim( (string) $options[ $key ] ) : '';
	};

	// One case per ported provider; each returns its exact token-endpoint POST.
	switch ( $type ) {
		case 'trestle':
			// Trestle client_credentials grant (form-encoded).
			$id     = $opt( 'mlsimport_tresle_client_id' );
			$secret = $opt( 'mlsimport_tresle_client_secret' );
			if ( '' === $id || '' === $secret ) {
				return null;
			}
			return array(
				'url'  => 'https://api-trestle.corelogic.com/trestle/oidc/connect/token',
				'body' => array(
					'client_id'     => $id,
					'client_secret' => $secret,
					'grant_type'    => 'client_credentials',
					'scope'         => 'api',
				),
				'json' => false,
			);

		case 'realcomp':
			// Realcomp re-uses the Trestle credential slots (JSON-body grant).
			$id     = $opt( 'mlsimport_tresle_client_id' );
			$secret = $opt( 'mlsimport_tresle_client_secret' );
			if ( '' === $id || '' === $secret ) {
				return null;
			}
			return array(
				'url'  => 'https://auth.realcomp.com/Token',
				'body' => array(
					'client_id'     => $id,
					'client_secret' => $secret,
					'audience'      => 'rcapi.realcomp.com',
				),
				'json' => true,
			);

		case 'realtorca':
			// Realtor.ca / CREA DDF client_credentials grant (scope DDFApi_Read).
			$id     = $opt( 'mlsimport_realtorca_client_id' );
			$secret = $opt( 'mlsimport_realtorca_client_secret' );
			if ( '' === $id || '' === $secret ) {
				return null;
			}
			return array(
				'url'  => 'https://identity.crea.ca/connect/token',
				'body' => array(
					'client_id'     => $id,
					'client_secret' => $secret,
					'grant_type'    => 'client_credentials',
					'scope'         => 'DDFApi_Read',
				),
				'json' => false,
			);

		case 'brightmls':
			// BrightMLS Okta client_credentials grant.
			$id     = $opt( 'mlsimport_brightmls_client_id' );
			$secret = $opt( 'mlsimport_brightmls_client_secret' );
			if ( '' === $id || '' === $secret ) {
				return null;
			}
			return array(
				'url'  => 'https://brightmls.okta.com/oauth2/default/v1/token',
				'body' => array(
					'client_id'     => $id,
					'client_secret' => $secret,
					'grant_type'    => 'client_credentials',
				),
				'json' => false,
			);

		case 'rapattoni':
			// Rapattoni password grant: needs client id/secret + a user login,
			// and its token URL comes from the per-MLS config, not a constant.
			$id        = $opt( 'mlsimport_rapattoni_client_id' );
			$secret    = $opt( 'mlsimport_rapattoni_client_secret' );
			$user      = $opt( 'mlsimport_rapattoni_username' );
			$pass      = $opt( 'mlsimport_rapattoni_password' );
			$token_url = isset( $config['api_token_url'] ) ? trim( (string) $config['api_token_url'] ) : '';
			// Any missing slot (incl. the token URL) makes the swap impossible.
			if ( '' === $id || '' === $secret || '' === $user || '' === $pass || '' === $token_url ) {
				return null;
			}
			return array(
				'url'  => $token_url,
				'body' => array(
					'client_id'     => $id,
					'client_secret' => $secret,
					'username'      => $user,
					'password'      => $pass,
					'grant_type'    => 'password',
				),
				'json' => false,
			);
	}

	// Unported provider type: no swap spec.
	return null;
}

/**
 * Run (or reuse) a provider's token swap. The token is transient-cached for
 * its lifetime; a rejected swap caches a short 'fail' sentinel so a broken
 * credential doesn't re-POST on every request.
 *
 * @param string $type   Provider type.
 * @param array  $config Per-MLS config.
 * @return string The access token, or '' when unavailable.
 */
function mlsimport_live_swap_token( string $type, array $config ): string {
	// No spec means the provider is unported or its credentials are incomplete.
	$spec = mlsimport_live_token_swap_spec( $type, $config );
	if ( null === $spec ) {
		return '';
	}

	// Reuse a cached token: a 'fail' sentinel means a recent swap was rejected,
	// so don't re-POST — return '' until the short sentinel TTL lapses.
	$transient = 'mlsimport_live_token_' . $type;
	$cached    = get_transient( $transient );
	if ( is_string( $cached ) && '' !== $cached ) {
		return 'fail' === $cached ? '' : $cached;
	}

	// Build the POST body: JSON-body grants set the content-type + encode;
	// the rest send a form-encoded array.
	$args = array( 'timeout' => 15 );
	if ( $spec['json'] ) {
		$args['headers'] = array( 'Content-Type' => 'application/json' );
		$args['body']    = wp_json_encode( $spec['body'] );
	} else {
		$args['body'] = $spec['body'];
	}

	// Fire the token request and decode the response.
	$response = wp_remote_post( $spec['url'], $args );
	$body     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$code     = (int) wp_remote_retrieve_response_code( $response );

	// Any transport error, non-200, or missing access_token: cache a short
	// 'fail' sentinel so a broken credential doesn't hammer the endpoint.
	if ( is_wp_error( $response ) || 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
		set_transient( $transient, 'fail', 5 * MINUTE_IN_SECONDS );
		return '';
	}

	// Success: cache the token for its lifetime, shaved 60s (or ~1h when the
	// provider omits expires_in), and return it.
	$token = (string) $body['access_token'];
	$ttl   = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : HOUR_IN_SECONDS - 60;
	set_transient( $transient, $token, $ttl );

	return $token;
}

/**
 * Request headers for a direct MLS call.
 *
 * @return array
 */
function mlsimport_live_auth_headers(): array {
	// Resolve the access token for the configured provider.
	$token = mlsimport_live_access_token( mlsimport_live_config() );
	// No token: return no headers (the caller treats this as "can't reach MLS").
	if ( '' === $token ) {
		return array();
	}
	// Standard bearer auth + JSON accept for the direct MLS request.
	return array(
		'Authorization' => 'Bearer ' . $token,
		'Accept'        => 'application/json',
	);
}
