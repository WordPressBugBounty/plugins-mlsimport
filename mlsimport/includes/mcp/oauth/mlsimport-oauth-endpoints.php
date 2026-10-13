<?php
/**
 * AI app sign-in (OAuth): the register and token addresses.
 *
 *   POST /wp-json/mlsimport/oauth/register   an AI app registers itself (RFC 7591)
 *   POST /wp-json/mlsimport/oauth/token      approval code or refresh token in, tokens out
 *
 * Both are called by the AI app's servers without a WordPress login, so they
 * are open routes; what protects them is what they accept:
 *   - both work only over HTTPS (mlsimport-oauth-guards.php);
 *   - register accepts only the return addresses of ChatGPT and Claude, a
 *     small body, and a bounded number of calls;
 *   - token accepts only a single-use approval code together with its PKCE
 *     verifier, or the current refresh token of a Connected AI App.
 *
 * Errors use the standard OAuth shape {error, error_description}.
 *
 * Public interface:
 *   mlsimport_oauth_register_routes()     hook: rest_api_init
 *   mlsimport_oauth_handle_register()     register address
 *   mlsimport_oauth_handle_token()        token address
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp/oauth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the two REST routes.
 *
 * @return void
 */
function mlsimport_oauth_register_routes(): void {
	register_rest_route(
		'mlsimport',
		'/oauth/register',
		array(
			'methods'             => 'POST',
			'callback'            => 'mlsimport_oauth_handle_register',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'mlsimport',
		'/oauth/token',
		array(
			'methods'             => 'POST',
			'callback'            => 'mlsimport_oauth_handle_token',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Build an OAuth error answer.
 *
 * @param string $error       OAuth error code.
 * @param string $description Plain explanation.
 * @param int    $status      HTTP status.
 * @return WP_REST_Response
 */
function mlsimport_oauth_error( string $error, string $description, int $status = 400 ): WP_REST_Response {
	$response = new WP_REST_Response(
		array(
			'error'             => $error,
			'error_description' => $description,
		),
		$status
	);
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * Register address: accept an AI app only when every return address it
 * registers is on the list.
 *
 * @param WP_REST_Request $request JSON body with client_name and redirect_uris.
 * @return WP_REST_Response
 */
function mlsimport_oauth_handle_register( WP_REST_Request $request ): WP_REST_Response {
	// Step 0: the call must be within the limits of the open address (size,
	// calls per hour from this address, unapproved apps kept) before any
	// work is done on its body.
	$refused = mlsimport_oauth_registration_check( strlen( (string) $request->get_body() ) );
	if ( 'body_too_large' === $refused ) {
		return mlsimport_oauth_error( 'invalid_client_metadata', 'The registration is larger than this site accepts.' );
	}
	if ( '' !== $refused ) {
		return mlsimport_oauth_error( 'too_many_registrations', 'Too many registrations right now. Try again later.', 429 );
	}

	$body          = (array) $request->get_json_params();
	$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();
	$redirect_uris = array_values( array_unique( array_filter( $redirect_uris, 'is_string' ) ) );

	// Step 1: at least one return address, never more than the list holds
	// (repeats were dropped above, so a longer list cannot be all accepted),
	// and every one of them accepted.
	if ( empty( $redirect_uris ) || count( $redirect_uris ) > count( mlsimport_oauth_allowed_redirect_uris() ) ) {
		return mlsimport_oauth_error( 'invalid_redirect_uri', 'redirect_uris is required.' );
	}
	foreach ( $redirect_uris as $uri ) {
		if ( ! mlsimport_oauth_redirect_allowed( $uri ) ) {
			return mlsimport_oauth_error( 'invalid_redirect_uri', 'This site accepts only the ChatGPT and Claude apps.' );
		}
	}

	// Step 2: store the app. The name is only a label.
	$name      = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : '';
	$name      = '' !== $name ? mb_substr( $name, 0, 100 ) : 'AI app';
	$client_id = mlsimport_oauth_client_create( $name, $redirect_uris );

	// Step 3: answer with the registration (no secret: public client + PKCE).
	$response = new WP_REST_Response(
		array(
			'client_id'                  => $client_id,
			'client_name'                => $name,
			'redirect_uris'              => array_values( $redirect_uris ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
		),
		201
	);
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * Token address: exchange an approval code, or refresh.
 *
 * @param WP_REST_Request $request Form body (grant_type and its values).
 * @return WP_REST_Response
 */
function mlsimport_oauth_handle_token( WP_REST_Request $request ): WP_REST_Response {
	$client_id = (string) $request->get_param( 'client_id' );
	$resource  = (string) $request->get_param( 'resource' );

	// A token is only ever issued for the MLSImport MCP address.
	if ( ! mlsimport_oauth_resource_ok( $resource, mlsimport_mcp_endpoint_url() ) ) {
		return mlsimport_oauth_error( 'invalid_target', 'Tokens are issued only for the MLSImport MCP address.' );
	}

	switch ( (string) $request->get_param( 'grant_type' ) ) {
		case 'authorization_code':
			$tokens = mlsimport_oauth_exchange_code(
				$client_id,
				(string) $request->get_param( 'code' ),
				(string) $request->get_param( 'redirect_uri' ),
				(string) $request->get_param( 'code_verifier' )
			);
			break;
		case 'refresh_token':
			$tokens = mlsimport_oauth_refresh( $client_id, (string) $request->get_param( 'refresh_token' ) );
			break;
		default:
			return mlsimport_oauth_error( 'unsupported_grant_type', 'Use authorization_code or refresh_token.' );
	}

	if ( null === $tokens ) {
		return mlsimport_oauth_error( 'invalid_grant', 'The code or refresh token is not valid.' );
	}

	$response = new WP_REST_Response(
		array(
			'access_token'  => $tokens['access_token'],
			'token_type'    => 'Bearer',
			'expires_in'    => $tokens['expires_in'],
			'refresh_token' => $tokens['refresh_token'],
			'scope'         => 'mlsimport',
		),
		200
	);
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * Exchange a single-use approval code for the first tokens.
 *
 * Step by step:
 * 1. Take the code (it is destroyed whether or not the rest passes).
 * 2. The code must belong to this app and this return address.
 * 3. PKCE: the verifier must match the challenge sent with the approval.
 * 4. The approving user must still be an administrator.
 * 5. Create the Connected AI App and return its tokens.
 *
 * @param string $client_id    The app.
 * @param string $code         Approval code.
 * @param string $redirect_uri Return address used for the approval.
 * @param string $verifier     PKCE code_verifier.
 * @return array|null Tokens, or null when anything does not match.
 */
function mlsimport_oauth_exchange_code( string $client_id, string $code, string $redirect_uri, string $verifier ): ?array {
	$data = '' !== $code ? mlsimport_oauth_code_take( $code ) : null;
	if ( null === $data
		|| ! hash_equals( (string) $data['client_id'], $client_id )
		|| ! hash_equals( (string) $data['redirect_uri'], $redirect_uri )
		|| ! mlsimport_oauth_pkce_matches( $verifier, (string) $data['code_challenge'] )
		|| ! user_can( (int) $data['user_id'], 'manage_options' ) ) {
		return null;
	}

	$client = mlsimport_oauth_client_get( $client_id );
	return null === $client ? null : mlsimport_oauth_grant_create( $client, $redirect_uri, (int) $data['user_id'] );
}

/**
 * Give a Connected AI App a new token pair for its current refresh token.
 *
 * Step by step:
 * 1. Find the app by the token. A token that is not current but WAS this
 *    app's refresh token at some earlier rotation is being used again:
 *    either it was stolen or the app is confused. Either way the Connected
 *    AI App is disconnected, so a stolen token cannot keep access.
 * 2. The token must belong to the app that sends it.
 * 3. An app that expired (90 days unused) is removed; one whose
 *    administrator is gone or demoted stays listed as "no longer valid"
 *    but gets nothing.
 * 4. Rotate. The store writes the new pair only if the row still holds the
 *    token validated here; when two refreshes race, exactly one gets
 *    tokens and the other is refused without disconnecting the app.
 *
 * @param string $client_id     The app.
 * @param string $refresh_token Its current refresh token.
 * @return array|null Tokens, or null when the refresh is not accepted.
 */
function mlsimport_oauth_refresh( string $client_id, string $refresh_token ): ?array {
	// Step 1.
	$grant = mlsimport_oauth_grant_find( 'refresh_hash', $refresh_token );
	if ( null === $grant ) {
		$reused = mlsimport_oauth_grant_find_replaced( $refresh_token );
		if ( null !== $reused ) {
			mlsimport_oauth_grant_delete( (int) $reused['id'] );
		}
		return null;
	}

	// Step 2.
	if ( ! hash_equals( (string) $grant['client_id'], $client_id ) ) {
		return null;
	}

	// Step 3.
	$problem = mlsimport_oauth_grant_problem( (int) $grant['last_used'], user_can( (int) $grant['user_id'], 'manage_options' ), time() );
	if ( 'expired' === $problem ) {
		mlsimport_oauth_grant_delete( (int) $grant['id'] );
	}
	if ( '' !== $problem ) {
		return null;
	}

	// Step 4.
	return mlsimport_oauth_grant_rotate( (int) $grant['id'], (string) $grant['refresh_hash'] );
}
