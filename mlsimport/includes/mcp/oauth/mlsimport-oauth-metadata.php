<?php
/**
 * AI app sign-in (OAuth): addresses and discovery.
 *
 * An AI app that is given the MCP address finds the sign-in by itself:
 *   1. it calls the MCP address without a token and gets a 401 whose
 *      WWW-Authenticate header points at the protected-resource document;
 *   2. that document names this site as the sign-in provider;
 *   3. the sign-in provider document lists the register, approve and token
 *      addresses and what they support.
 *
 * The WpResidence theme can be a sign-in provider on the same site, so
 * MLSImport answers only its own two well-known paths, by exact match:
 *   /.well-known/oauth-protected-resource/wp-json/mlsimport/mcp
 *     (RFC 9728 form for a resource with a path: the MCP path appended)
 *   /.well-known/oauth-authorization-server
 *     (RFC 8414 for the issuer, which is the site itself, without a path)
 * The approval page lives at /mlsimport-oauth/authorize. All three are
 * answered early on 'init' straight from the request path, so no rewrite
 * rule (and no permalink flush) is needed.
 *
 * Public interface:
 *   mlsimport_oauth_issuer()                       this site as sign-in provider
 *   mlsimport_oauth_authorize_url()                the approval page address
 *   mlsimport_oauth_resource_metadata_url()        the protected-resource document address
 *   mlsimport_oauth_server_metadata_url()          the sign-in provider document address
 *   mlsimport_oauth_protected_resource_document()  document 2
 *   mlsimport_oauth_server_document()              document 3
 *   mlsimport_oauth_requested_document( $path )    which document a path asks for
 *   mlsimport_oauth_route_request()                hook: init
 *   mlsimport_oauth_unauthorized_header()          hook: rest_post_dispatch
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp/oauth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This site as sign-in provider. AI apps compare this value exactly.
 *
 * @return string The site address without a trailing slash.
 */
function mlsimport_oauth_issuer(): string {
	return untrailingslashit( home_url() );
}

/**
 * The approval (consent) page address.
 *
 * @return string
 */
function mlsimport_oauth_authorize_url(): string {
	return home_url( '/mlsimport-oauth/authorize' );
}

/**
 * The protected-resource document address named in the 401 header.
 *
 * RFC 9728 form for a resource with a path: the well-known folder, then the
 * MCP path appended. The bare /.well-known/oauth-protected-resource is
 * claimed by nobody, so the theme and MLSImport each have their own.
 *
 * @return string
 */
function mlsimport_oauth_resource_metadata_url(): string {
	$mcp_path = (string) wp_parse_url( mlsimport_mcp_endpoint_url(), PHP_URL_PATH );
	return home_url( '/.well-known/oauth-protected-resource' . untrailingslashit( $mcp_path ) );
}

/**
 * The sign-in provider document address (RFC 8414 for an issuer without a path).
 *
 * @return string
 */
function mlsimport_oauth_server_metadata_url(): string {
	return home_url( '/.well-known/oauth-authorization-server' );
}

/**
 * The protected-resource document (RFC 9728): who signs in for the MCP address.
 *
 * @return array
 */
function mlsimport_oauth_protected_resource_document(): array {
	return array(
		'resource'                 => mlsimport_mcp_endpoint_url(),
		'authorization_servers'    => array( mlsimport_oauth_issuer() ),
		'bearer_methods_supported' => array( 'header' ),
		'scopes_supported'         => array( 'mlsimport' ),
	);
}

/**
 * The sign-in provider document (RFC 8414).
 *
 * "none" as token-endpoint method means public clients: an AI app proves
 * itself with PKCE, not with a stored secret. "iss" is returned on every
 * approval answer (RFC 9207), which is what lets ChatGPT use its one stable
 * return address.
 *
 * @return array
 */
function mlsimport_oauth_server_document(): array {
	return array(
		'issuer'                                => mlsimport_oauth_issuer(),
		'authorization_endpoint'                => mlsimport_oauth_authorize_url(),
		'token_endpoint'                        => rest_url( 'mlsimport/oauth/token' ),
		'registration_endpoint'                 => rest_url( 'mlsimport/oauth/register' ),
		'response_types_supported'              => array( 'code' ),
		'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
		'code_challenge_methods_supported'      => array( 'S256' ),
		'token_endpoint_auth_methods_supported' => array( 'none' ),
		'scopes_supported'                      => array( 'mlsimport' ),
		'authorization_response_iss_parameter_supported' => true,
	);
}

/**
 * Which discovery document a request path asks for, if any of ours.
 *
 * The exact-path rule lives in mlsimport-oauth-rules.php; this hands it the
 * paths of the two addresses this site advertises, so the router and the
 * 401 header can never disagree (a site in a subdirectory included).
 *
 * @param string $path Request path.
 * @return array|null The document, or null when the path is not one of ours.
 */
function mlsimport_oauth_requested_document( string $path ): ?array {
	$resource_path = (string) wp_parse_url( mlsimport_oauth_resource_metadata_url(), PHP_URL_PATH );
	$server_path   = (string) wp_parse_url( mlsimport_oauth_server_metadata_url(), PHP_URL_PATH );

	switch ( mlsimport_oauth_document_for_path( $path, $resource_path, $server_path ) ) {
		case 'resource':
			return mlsimport_oauth_protected_resource_document();
		case 'server':
			return mlsimport_oauth_server_document();
		default:
			return null;
	}
}

/**
 * Answer the discovery documents and the approval page from the request path.
 *
 * Hooked on 'init'. Any other request passes through untouched, including
 * the theme's own discovery paths on a site that runs both products.
 *
 * @return void
 */
function mlsimport_oauth_route_request(): void {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';

	// Step 1: one of the two discovery documents.
	$document = mlsimport_oauth_requested_document( $path );
	if ( null !== $document ) {
		nocache_headers();
		header( 'Access-Control-Allow-Origin: *' );
		wp_send_json( $document );
	}

	// Step 2: the approval page.
	$authorize_path = (string) wp_parse_url( mlsimport_oauth_authorize_url(), PHP_URL_PATH );
	if ( untrailingslashit( $path ) === untrailingslashit( $authorize_path ) ) {
		mlsimport_oauth_authorize_page();
	}
}

/**
 * Tell an AI app without a valid token where to sign in.
 *
 * Hooked on rest_post_dispatch. A refused, logged-out call to the MCP address
 * gets the WWW-Authenticate header that starts the discovery.
 *
 * @param WP_HTTP_Response $response The response about to be sent.
 * @param WP_REST_Server   $server   The REST server.
 * @param WP_REST_Request  $request  The request.
 * @return WP_HTTP_Response
 */
function mlsimport_oauth_unauthorized_header( $response, $server, $request ) {
	if ( '/mlsimport/mcp' === untrailingslashit( $request->get_route() )
		&& 401 === $response->get_status()
		&& ! is_user_logged_in() ) {
		$response->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . mlsimport_oauth_resource_metadata_url() . '"' );
	}
	return $response;
}
