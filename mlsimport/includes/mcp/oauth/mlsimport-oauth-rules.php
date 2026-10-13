<?php
/**
 * AI app sign-in (OAuth): the rules, as pure functions.
 *
 * The plugin is its own sign-in provider for AI apps (docs/adr/0019). This
 * file holds every yes/no rule of that sign-in, with no database and no
 * request handling, so each rule can be tested alone:
 *   - which return addresses may register (ChatGPT and Claude only);
 *   - PKCE (S256 only);
 *   - how tokens are made and how they are stored (hashed);
 *   - when a Connected AI App may still act;
 *   - which "resource" a token may be asked for;
 *   - which browser Origin may call the MCP address;
 *   - how many registrations the open register address accepts.
 *
 * Public interface:
 *   mlsimport_oauth_allowed_redirect_uris()
 *   mlsimport_oauth_redirect_allowed( $uri )
 *   mlsimport_oauth_redirect_domain( $uri )
 *   mlsimport_oauth_pkce_matches( $verifier, $challenge )
 *   mlsimport_oauth_new_token()
 *   mlsimport_oauth_hash( $token )
 *   mlsimport_oauth_grant_problem( $last_used, $approver_is_admin, $now )
 *   mlsimport_oauth_resource_ok( $resource, $mcp_url )
 *   mlsimport_oauth_route_is_mcp( $front_index, $get_route, $post_route, $path, $mcp_path )
 *   mlsimport_oauth_origin_allowed( $origin, $site_host, $allowed )
 *   mlsimport_oauth_registration_refused( $body_size, $from_this_address, $pending )
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp/oauth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Seconds an access token lives. */
define( 'MLSIMPORT_OAUTH_ACCESS_LIFETIME', 3600 );

/** Seconds an approval code lives. */
define( 'MLSIMPORT_OAUTH_CODE_LIFETIME', 600 );

/** Seconds without use after which a Connected AI App expires (90 days). */
define( 'MLSIMPORT_OAUTH_UNUSED_LIFETIME', 90 * 86400 );

/** Largest registration body read (bytes). ChatGPT and Claude send well under 1 KB. */
define( 'MLSIMPORT_OAUTH_REGISTER_BODY_MAX', 8192 );

/** Registrations one network address may make per hour. */
define( 'MLSIMPORT_OAUTH_REGISTER_PER_HOUR', 20 );

/** Registered-but-never-approved apps the site keeps at most. */
define( 'MLSIMPORT_OAUTH_PENDING_MAX', 100 );

/**
 * The return addresses an AI app may register with.
 *
 * Any program can register itself under any name, so the name proves
 * nothing; the address the approval is sent back to cannot be faked. Only
 * these exact addresses are accepted (confirmed 2026-10-02):
 *   - ChatGPT: the stable address it uses when the sign-in sends "iss";
 *   - Claude: the claude.ai address, and the same path on claude.com.
 *
 * There is deliberately no wp-admin setting for this list, so a client
 * cannot be talked into adding an address. Developers extend it in code.
 *
 * @return string[]
 */
function mlsimport_oauth_allowed_redirect_uris(): array {
	$uris = array(
		'https://chatgpt.com/connector_platform_oauth_redirect',
		'https://claude.ai/api/mcp/auth_callback',
		'https://claude.com/api/mcp/auth_callback',
	);

	/**
	 * Filters the return addresses an AI app may register with.
	 *
	 * @param string[] $uris Exact return addresses.
	 */
	return (array) apply_filters( 'mlsimport_mcp_allowed_redirect_uris', $uris );
}

/**
 * Whether a return address is on the list. Exact match only.
 *
 * @param string $uri Return address sent by the AI app.
 * @return bool
 */
function mlsimport_oauth_redirect_allowed( string $uri ): bool {
	return '' !== $uri && in_array( $uri, mlsimport_oauth_allowed_redirect_uris(), true );
}

/**
 * The domain of a return address, shown on the consent screen.
 *
 * @param string $uri Return address.
 * @return string Host name, or '' when the address has none.
 */
function mlsimport_oauth_redirect_domain( string $uri ): string {
	return (string) parse_url( $uri, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure function, also used without WordPress.
}

/**
 * PKCE check, S256 only: BASE64URL( SHA256( verifier ) ) must equal the challenge.
 *
 * @param string $verifier  code_verifier sent to the token address.
 * @param string $challenge code_challenge sent with the approval request.
 * @return bool
 */
function mlsimport_oauth_pkce_matches( string $verifier, string $challenge ): bool {
	if ( '' === $verifier || '' === $challenge ) {
		return false;
	}
	$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE encoding.
	return hash_equals( $computed, $challenge );
}

/**
 * A new random token (access token, refresh token, approval code or client id).
 *
 * @return string 43 URL-safe characters carrying 256 random bits.
 */
function mlsimport_oauth_new_token(): string {
	return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- token encoding.
}

/**
 * The value stored for a token. The token itself is never stored.
 *
 * The tokens are 256 random bits, so a plain SHA-256 is enough (no salt or
 * slow hash is needed for values that cannot be guessed).
 *
 * @param string $token Token as given to the AI app.
 * @return string 64 hex characters.
 */
function mlsimport_oauth_hash( string $token ): string {
	return hash( 'sha256', $token );
}

/**
 * Why a Connected AI App may not act right now.
 *
 * A Connected AI App acts as the administrator who approved it. It is checked
 * on every call.
 *
 * @param int  $last_used         When the app was last used (approval time when never).
 * @param bool $approver_is_admin The approving user still exists and is an administrator.
 * @param int  $now               Current time.
 * @return string '' when it may act, 'no_longer_valid' or 'expired' when not.
 */
function mlsimport_oauth_grant_problem( int $last_used, bool $approver_is_admin, int $now ): string {
	if ( ! $approver_is_admin ) {
		return 'no_longer_valid';
	}
	if ( $now - $last_used > MLSIMPORT_OAUTH_UNUSED_LIFETIME ) {
		return 'expired';
	}
	return '';
}

/**
 * Whether the "resource" an AI app asks a token for is acceptable.
 *
 * Tokens work only on the MLSImport MCP address. An app that names a
 * resource must name that address; an app that names none gets the same.
 *
 * @param string $resource The resource value sent ('' when none).
 * @param string $mcp_url  The MLSImport MCP address.
 * @return bool
 */
function mlsimport_oauth_resource_ok( string $resource, string $mcp_url ): bool {
	return '' === $resource || rtrim( $resource, '/' ) === rtrim( $mcp_url, '/' );
}

/**
 * Whether WordPress will serve a request as the MLSImport MCP address.
 *
 * A token must count on the MCP address and nowhere else, so this decides the
 * route the way WordPress itself does, not by what the address looks like:
 *   - Only the front-end index.php serves the REST API. admin-ajax.php,
 *     admin-post.php and the other entry points never count, whatever their
 *     address contains.
 *   - A "rest_route" value decides the route, and one in the POST body wins
 *     over one in the address and over the path. So when either is present it
 *     must be exactly the MCP route; the path is then irrelevant.
 *   - Without a "rest_route", the path must be the MCP path.
 * The value is compared raw (WordPress routes on the raw value), and anything
 * unexpected fails closed.
 *
 * @param bool   $front_index Request is served by the front-end index.php (not wp-admin).
 * @param mixed  $get_route   Raw "rest_route" from the address, null when absent.
 * @param mixed  $post_route  Raw "rest_route" from the POST body, null when absent.
 * @param string $path        Request path.
 * @param string $mcp_path    Path of the MCP address.
 * @return bool
 */
function mlsimport_oauth_route_is_mcp( bool $front_index, $get_route, $post_route, string $path, string $mcp_path ): bool {
	// Step 1: the entry point.
	if ( ! $front_index ) {
		return false;
	}

	// Step 2: a rest_route decides (POST first, as WordPress reads it).
	$route = null !== $post_route ? $post_route : $get_route;
	if ( null !== $route ) {
		return is_string( $route ) && '/mlsimport/mcp' === rtrim( $route, '/' );
	}

	// Step 3: the path.
	return '' !== $mcp_path && rtrim( $path, '/' ) === rtrim( $mcp_path, '/' );
}

/**
 * Whether a request's Origin header may call the MCP address.
 *
 * ChatGPT and Claude call from their servers and send no Origin: a token in
 * the Authorization header is what admits them. A browser sends an Origin,
 * and a page on another site must not be able to drive the MCP address with
 * a visitor's session or token (MCP transport rule). So:
 *   - no Origin: allowed;
 *   - an Origin on this site's own host: allowed;
 *   - an Origin a developer added to the list on purpose: allowed;
 *   - anything else, including the literal "null": refused.
 *
 * @param string|null $origin    Raw Origin header, null when absent.
 * @param string      $site_host Host name of this site, lower case.
 * @param string[]    $allowed   Extra origins accepted on purpose (scheme://host[:port]).
 * @return bool
 */
function mlsimport_oauth_origin_allowed( ?string $origin, string $site_host, array $allowed ): bool {
	if ( null === $origin || '' === $origin ) {
		return true;
	}
	$origin = strtolower( rtrim( $origin, '/' ) );
	$host   = (string) parse_url( $origin, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure function, also used without WordPress.
	if ( '' !== $host && strtolower( $site_host ) === $host ) {
		return true;
	}
	return in_array( $origin, array_map( 'strtolower', $allowed ), true );
}

/**
 * Why a registration is refused before any work is done on it.
 *
 * The register address is open to the internet, so each call is bounded:
 * a body no larger than the apps really send, a number of registrations per
 * hour from one network address, and a ceiling on registered-but-never-
 * approved apps kept in the table.
 *
 * @param int $body_size         Bytes in the request body.
 * @param int $from_this_address Registrations from the same address in the last hour.
 * @param int $pending           Registered apps nobody approved yet (after the day-old ones were dropped).
 * @return string '' when allowed; else 'body_too_large', 'too_many_from_address' or 'too_many_pending'.
 */
function mlsimport_oauth_registration_refused( int $body_size, int $from_this_address, int $pending ): string {
	if ( $body_size > MLSIMPORT_OAUTH_REGISTER_BODY_MAX ) {
		return 'body_too_large';
	}
	if ( $from_this_address >= MLSIMPORT_OAUTH_REGISTER_PER_HOUR ) {
		return 'too_many_from_address';
	}
	if ( $pending >= MLSIMPORT_OAUTH_PENDING_MAX ) {
		return 'too_many_pending';
	}
	return '';
}
