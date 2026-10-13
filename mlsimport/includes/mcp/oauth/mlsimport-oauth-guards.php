<?php
/**
 * AI app sign-in (OAuth): what a request must satisfy before it is served.
 *
 * Three checks that sit in front of the open addresses, all decided by the
 * pure rules in mlsimport-oauth-rules.php:
 *
 *   1. HTTPS. A token, an approval code and the approval page travel only
 *      over HTTPS: over plain HTTP anyone on the path could copy the token
 *      and act as the administrator. The MCP address, the register and
 *      token addresses and the approval page refuse plain HTTP. (A site
 *      behind a proxy that ends HTTPS must tell WordPress so, the usual
 *      HTTP_X_FORWARDED_PROTO line in wp-config.php.)
 *   2. Origin. The MCP address refuses a browser Origin that is not this
 *      site (and not on the developer list), so a page on another site
 *      cannot drive it. Server-to-server calls send no Origin and pass.
 *   3. Registration limits. The register address is open to the internet;
 *      each call is bounded in size and in number (per address and per
 *      hour, and in registered-but-never-approved apps kept).
 *
 * Public interface:
 *   mlsimport_oauth_https_ok()                       is this request over HTTPS
 *   mlsimport_oauth_guard_request()                  hook: rest_pre_dispatch (checks 1 and 2)
 *   mlsimport_oauth_registration_check( $body_size ) check 3, and count the accepted call
 *   mlsimport_oauth_registration_counter_key( $ip )  the transient that counts one address
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp/oauth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current request came over HTTPS.
 *
 * @return bool
 */
function mlsimport_oauth_https_ok(): bool {
	return is_ssl();
}

/**
 * Refuse the sign-in and MCP routes over plain HTTP, and the MCP route for a
 * foreign browser Origin.
 *
 * Hooked on rest_pre_dispatch, so it runs on the route WordPress really
 * resolved, before any handler. Other routes pass through untouched.
 *
 * @param mixed           $result  Response to send instead of dispatching (null to go on).
 * @param WP_REST_Server  $server  The REST server.
 * @param WP_REST_Request $request The request about to be dispatched.
 * @return mixed
 */
function mlsimport_oauth_guard_request( $result, $server, $request ) {
	$route = untrailingslashit( (string) $request->get_route() );
	if ( ! in_array( $route, array( '/mlsimport/mcp', '/mlsimport/oauth/register', '/mlsimport/oauth/token' ), true ) ) {
		return $result;
	}

	// Check 1: HTTPS, on all three routes.
	if ( ! mlsimport_oauth_https_ok() ) {
		return new WP_Error( 'mlsimport_https_required', __( 'AI assistant access works only over HTTPS.', 'mlsimport' ), array( 'status' => 403 ) );
	}

	// Check 2: Origin, on the MCP route.
	if ( '/mlsimport/mcp' === $route ) {
		$origin = $request->get_header( 'origin' );
		/**
		 * Filters the browser origins allowed to call the MCP address besides this site.
		 *
		 * @param string[] $allowed Origins as scheme://host[:port].
		 */
		$allowed = (array) apply_filters( 'mlsimport_mcp_allowed_origins', array() );
		if ( ! mlsimport_oauth_origin_allowed( is_string( $origin ) ? $origin : null, (string) wp_parse_url( home_url(), PHP_URL_HOST ), $allowed ) ) {
			return new WP_Error( 'mlsimport_origin_refused', __( 'This origin may not call the MLSImport MCP address.', 'mlsimport' ), array( 'status' => 403 ) );
		}
	}
	return $result;
}

/**
 * The transient that counts registrations from one network address.
 *
 * @param string $ip The address.
 * @return string
 */
function mlsimport_oauth_registration_counter_key( string $ip ): string {
	return 'mlsimport_oauth_register_' . md5( $ip );
}

/**
 * Decide whether a registration may go ahead, and count it when it may.
 *
 * Step by step:
 * 1. Read how many registrations this address made in the last hour.
 * 2. Count the registered-but-never-approved apps; when the ceiling is hit,
 *    drop the day-old ones first and count again, so stale registrations
 *    never lock out a real one. The pruning runs only here, not on every
 *    accepted call.
 * 3. Apply the rule. An accepted call is counted for its address.
 *
 * @param int $body_size Bytes in the request body.
 * @return string '' when the registration may go ahead, else the reason (see the rule).
 */
function mlsimport_oauth_registration_check( int $body_size ): string {
	// Step 1.
	$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key     = mlsimport_oauth_registration_counter_key( $ip );
	$from_ip = (int) get_transient( $key );

	// Step 2.
	$pending = mlsimport_oauth_clients_pending();
	if ( $pending >= MLSIMPORT_OAUTH_PENDING_MAX ) {
		mlsimport_oauth_clients_prune();
		$pending = mlsimport_oauth_clients_pending();
	}

	// Step 3.
	$refused = mlsimport_oauth_registration_refused( $body_size, $from_ip, $pending );
	if ( '' === $refused ) {
		set_transient( $key, $from_ip + 1, HOUR_IN_SECONDS );
	}
	return $refused;
}
