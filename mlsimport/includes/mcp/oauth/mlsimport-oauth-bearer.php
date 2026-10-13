<?php
/**
 * AI app sign-in (OAuth): accepting an access token on the MCP address.
 *
 * A Connected AI App acts as the administrator who approved it. This file
 * turns a valid access token into that WordPress user, for ONE address only:
 * /wp-json/mlsimport/mcp. On every other REST route, and everywhere else in
 * WordPress, a token means nothing.
 *
 * Every call re-checks the app:
 *   - the request must come over HTTPS (over plain HTTP a token is nobody,
 *     see mlsimport-oauth-guards.php);
 *   - the access token must be the app's current one and not past its hour;
 *   - the approving user must still exist and still be an administrator
 *     (otherwise the app is "no longer valid": refused, kept in the list so
 *     the site owner sees it, never handed to another user);
 *   - an app unused for 90 days is expired and removed.
 *
 * Public interface:
 *   mlsimport_oauth_is_mcp_request()            is this request for the MCP address
 *   mlsimport_oauth_bearer_token()              the token sent with the request
 *   mlsimport_oauth_user_for_token( $token )    the user a token acts as (0 when none)
 *   mlsimport_oauth_determine_user( $user_id )  hook: determine_current_user
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp/oauth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether WordPress will serve the current request as the MLSImport MCP address.
 *
 * Gathers the facts of the request and hands them to the pure rule
 * mlsimport_oauth_route_is_mcp() (mlsimport-oauth-rules.php), which explains
 * why each one matters. The values are read raw on purpose: WordPress routes
 * on the raw values, and they are only compared, never stored or printed.
 *
 * The MCP path is built from the site address and the REST prefix, without
 * rest_url(): this runs while WordPress works out the current user, which can
 * happen before the rewrite rules object exists.
 *
 * @return bool
 */
function mlsimport_oauth_is_mcp_request(): bool {
	$script      = isset( $_SERVER['SCRIPT_FILENAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
	$front_index = 'index.php' === $script && ! is_admin();

	// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw route values, compared only.
	$get_route  = isset( $_GET['rest_route'] ) ? wp_unslash( $_GET['rest_route'] ) : null;
	$post_route = isset( $_POST['rest_route'] ) ? wp_unslash( $_POST['rest_route'] ) : null;
	$uri        = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	// phpcs:enable

	$path     = (string) strtok( $uri, '?' );
	$mcp_path = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) . rest_get_url_prefix() . '/mlsimport/mcp';

	return mlsimport_oauth_route_is_mcp( $front_index, $get_route, $post_route, $path, $mcp_path );
}

/**
 * The bearer token sent with the request.
 *
 * Some servers hand the Authorization header over under a different name.
 *
 * @return string The token, or '' when none was sent.
 */
function mlsimport_oauth_bearer_token(): string {
	foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only by hash.
			$header = trim( (string) wp_unslash( $_SERVER[ $key ] ) );
			if ( 0 === stripos( $header, 'Bearer ' ) ) {
				return trim( substr( $header, 7 ) );
			}
		}
	}
	return '';
}

/**
 * The WordPress user an access token acts as.
 *
 * Step by step:
 * 1. Find the Connected AI App holding this access token.
 * 2. The token must not be past its lifetime.
 * 3. The app must still be allowed to act (approver still an administrator,
 *    used within 90 days). An expired app is removed.
 * 4. Record the use and remember the app's name for AI Activity.
 *
 * @param string $token Access token as sent.
 * @return int The approving administrator's user id, or 0 when the token is not accepted.
 */
function mlsimport_oauth_user_for_token( string $token ): int {
	// Step 1.
	$grant = mlsimport_oauth_grant_find( 'access_hash', $token );
	if ( null === $grant ) {
		return 0;
	}

	// Step 2.
	if ( (int) $grant['access_expires'] < time() ) {
		return 0;
	}

	// Step 3.
	$user_id = (int) $grant['user_id'];
	$problem = mlsimport_oauth_grant_problem( (int) $grant['last_used'], user_can( $user_id, 'manage_options' ), time() );
	if ( 'expired' === $problem ) {
		mlsimport_oauth_grant_delete( (int) $grant['id'] );
	}
	if ( '' !== $problem ) {
		return 0;
	}

	// Step 4. The flag lets mlsimport_mcp_guard_token_route() refuse any REST
	// route other than the MCP one for a request signed in by a token. The
	// app's row id lets a confirmation code be tied to this very app.
	mlsimport_oauth_grant_touch( (int) $grant['id'] );
	mlsimport_mcp_set_current_app( (string) $grant['client_name'], (int) $grant['id'] );
	$GLOBALS['mlsimport_oauth_token_request'] = true;
	return $user_id;
}

/**
 * Sign the request in as the approving administrator, on the MCP address only.
 *
 * Hooked on determine_current_user. WordPress may ask more than once per
 * request, so the answer is worked out once.
 *
 * @param int|false $user_id The user WordPress has determined so far.
 * @return int|false
 */
function mlsimport_oauth_determine_user( $user_id ) {
	static $resolved = null;

	// Somebody is already signed in (cookie, Application Password): leave it.
	if ( ! empty( $user_id ) ) {
		return $user_id;
	}

	// A token counts on the MCP address, over HTTPS, and nowhere else.
	if ( ! mlsimport_oauth_is_mcp_request() || ! mlsimport_oauth_https_ok() ) {
		return $user_id;
	}

	if ( null === $resolved ) {
		$token    = mlsimport_oauth_bearer_token();
		$resolved = '' !== $token ? mlsimport_oauth_user_for_token( $token ) : 0;
	}

	return $resolved > 0 ? $resolved : $user_id;
}
