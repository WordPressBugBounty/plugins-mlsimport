<?php
/**
 * AI app sign-in (OAuth): the approval (consent) page.
 *
 * The AI app sends the administrator's browser to /mlsimport-oauth/authorize.
 * The page:
 *   1. checks the request (known app, accepted return address, PKCE S256);
 *   2. sends a logged-out visitor through the normal WordPress login;
 *   3. refuses anyone who is not an administrator;
 *   4. shows which app is asking AND the domain the approval is sent back to
 *      (the name is chosen by whoever registered and proves nothing; the
 *      return domain cannot be faked), with Allow and Deny;
 *   5. on Allow, sends the browser back to the app with a single-use code.
 *
 * A request with an unknown app or a return address that is not accepted is
 * never redirected anywhere: the page shows an error instead.
 *
 * Public interface:
 *   mlsimport_oauth_authorize_check( $params )            the request check (no output)
 *   mlsimport_oauth_authorize_answer( $params, $user_id, $allow )   where to send the browser back
 *   mlsimport_oauth_authorize_page()                      the page itself (called from the router)
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp/oauth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check an approval request.
 *
 * @param array $params client_id, redirect_uri, response_type, code_challenge,
 *                      code_challenge_method, resource, state.
 * @return array {
 *     @type string     $fatal   Problem that must be SHOWN, never redirected ('' when none).
 *     @type string     $error   OAuth error to send back to the app ('' when none).
 *     @type array|null $client  The registered app.
 * }
 */
function mlsimport_oauth_authorize_check( array $params ): array {
	$client       = mlsimport_oauth_client_get( (string) ( $params['client_id'] ?? '' ) );
	$redirect_uri = (string) ( $params['redirect_uri'] ?? '' );

	// Step 1: the app and its return address. Failing here must not redirect.
	if ( null === $client ) {
		return array( 'fatal' => __( 'This AI app is not registered on this site.', 'mlsimport' ), 'error' => '', 'client' => null );
	}
	if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) || ! mlsimport_oauth_redirect_allowed( $redirect_uri ) ) {
		return array( 'fatal' => __( 'The return address of this request is not accepted.', 'mlsimport' ), 'error' => '', 'client' => null );
	}

	// Step 2: the rest is reported back to the app.
	$error = '';
	if ( 'code' !== ( $params['response_type'] ?? '' ) ) {
		$error = 'unsupported_response_type';
	} elseif ( '' === (string) ( $params['code_challenge'] ?? '' ) || 'S256' !== ( $params['code_challenge_method'] ?? '' ) ) {
		$error = 'invalid_request';
	} elseif ( ! mlsimport_oauth_resource_ok( (string) ( $params['resource'] ?? '' ), mlsimport_mcp_endpoint_url() ) ) {
		$error = 'invalid_target';
	}

	return array( 'fatal' => '', 'error' => $error, 'client' => $client );
}

/**
 * Build the address the browser is sent back to.
 *
 * Every answer carries "state" as received and "iss" (this site), so the app
 * can tell the answer really comes from this sign-in provider.
 *
 * @param array  $params  The checked approval request.
 * @param int    $user_id The administrator answering.
 * @param bool   $allow   True for Allow, false for Deny.
 * @param string $error   An OAuth error to return instead of an answer.
 * @return string The full return address.
 */
function mlsimport_oauth_authorize_answer( array $params, int $user_id, bool $allow, string $error = '' ): string {
	$answer = array();
	if ( '' !== $error ) {
		$answer['error'] = $error;
	} elseif ( ! $allow ) {
		$answer['error'] = 'access_denied';
	} else {
		$answer['code'] = mlsimport_oauth_code_create(
			array(
				'client_id'      => (string) $params['client_id'],
				'redirect_uri'   => (string) $params['redirect_uri'],
				'user_id'        => $user_id,
				'code_challenge' => (string) $params['code_challenge'],
			)
		);
	}
	if ( isset( $params['state'] ) && '' !== (string) $params['state'] ) {
		$answer['state'] = (string) $params['state'];
	}
	$answer['iss'] = mlsimport_oauth_issuer();

	return add_query_arg( array_map( 'rawurlencode', $answer ), (string) $params['redirect_uri'] );
}

/**
 * The approval page. Always ends the request.
 *
 * @return void
 */
function mlsimport_oauth_authorize_page(): void {
	nocache_headers();
	// The page must never be shown inside another site's frame.
	header( 'X-Frame-Options: DENY' );
	header( "Content-Security-Policy: frame-ancestors 'none'" );

	$fields = array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'resource', 'state', 'scope' );
	$params = array();
	// phpcs:disable WordPress.Security.NonceVerification -- the GET shows the form; the POST nonce is verified below.
	foreach ( $fields as $field ) {
		if ( isset( $_REQUEST[ $field ] ) ) {
			$params[ $field ] = sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) );
		}
	}
	// phpcs:enable

	// Step 0: the approval code travels back through this page, so plain
	// HTTP is refused outright (see mlsimport-oauth-guards.php).
	if ( ! mlsimport_oauth_https_ok() ) {
		mlsimport_oauth_authorize_render( __( 'An AI app can be connected only over HTTPS. Open this site with https:// and try again.', 'mlsimport' ) );
	}

	// Step 1: the request itself.
	$check = mlsimport_oauth_authorize_check( $params );
	if ( '' !== $check['fatal'] ) {
		mlsimport_oauth_authorize_render( $check['fatal'] );
	}
	if ( '' !== $check['error'] ) {
		wp_redirect( mlsimport_oauth_authorize_answer( $params, 0, false, $check['error'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- return address is on the accepted list.
		exit;
	}

	// Step 2: a normal WordPress login first.
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( add_query_arg( array_map( 'rawurlencode', $params ), mlsimport_oauth_authorize_url() ) ) );
		exit;
	}

	// Step 3: administrators only.
	if ( ! current_user_can( 'manage_options' ) ) {
		mlsimport_oauth_authorize_render( __( 'Only an administrator of this site can connect an AI app.', 'mlsimport' ) );
	}

	// Step 4: the answer (Allow / Deny), protected by a nonce.
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		check_admin_referer( 'mlsimport_oauth_authorize_' . $params['client_id'] );
		$allow = isset( $_POST['mlsimport_oauth_decision'] ) && 'allow' === $_POST['mlsimport_oauth_decision'];
		wp_redirect( mlsimport_oauth_authorize_answer( $params, get_current_user_id(), $allow ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- return address is on the accepted list.
		exit;
	}

	// Step 5: show the question.
	mlsimport_oauth_authorize_render( '', $check['client'], $params );
}

/**
 * Print the approval page (the question, or an error) and end the request.
 *
 * @param string     $error  Error to show instead of the question ('' for the question).
 * @param array|null $client The registered app.
 * @param array      $params The approval request, repeated as hidden fields.
 * @return void
 */
function mlsimport_oauth_authorize_render( string $error, ?array $client = null, array $params = array() ): void {
	$site = wp_parse_url( home_url(), PHP_URL_HOST );
	status_header( '' !== $error ? 400 : 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php esc_html_e( 'Connect an AI app', 'mlsimport' ); ?></title>
	<style>
		body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;color:#1f2937;margin:0;padding:40px 16px}
		.mlsimport-oauth-card{max-width:460px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 1px 3px rgba(0,0,0,.12)}
		h1{font-size:20px;margin:0 0 16px}
		p{line-height:1.5;margin:0 0 14px}
		.mlsimport-oauth-domain{font-weight:600;background:#eef2ff;border-radius:8px;padding:10px 12px;margin:0 0 14px;word-break:break-all}
		.mlsimport-oauth-actions{display:flex;gap:12px;margin-top:24px}
		button{flex:1;border:0;border-radius:8px;padding:12px 16px;font-size:15px;cursor:pointer}
		.mlsimport-oauth-allow{background:#4f46e5;color:#fff}
		.mlsimport-oauth-deny{background:#e5e7eb;color:#1f2937}
		.mlsimport-oauth-error{color:#b91c1c}
		.mlsimport-oauth-note{font-size:14px;color:#4b5563;background:#f9fafb;border-radius:8px;padding:10px 12px}
	</style>
</head>
<body>
	<div class="mlsimport-oauth-card">
	<?php if ( '' !== $error ) : ?>
		<h1><?php esc_html_e( 'This app cannot be connected', 'mlsimport' ); ?></h1>
		<p class="mlsimport-oauth-error"><?php echo esc_html( $error ); ?></p>
	<?php else : ?>
		<h1><?php esc_html_e( 'Connect an AI app to MLSImport', 'mlsimport' ); ?></h1>
		<p>
			<?php
			/* translators: 1: AI app name, 2: site domain. */
			printf( esc_html__( '"%1$s" asks to set up and operate MLSImport on %2$s as you.', 'mlsimport' ), esc_html( $client['client_name'] ), esc_html( $site ) );
			?>
		</p>
		<p><?php esc_html_e( 'Your approval will be sent to:', 'mlsimport' ); ?></p>
		<p class="mlsimport-oauth-domain"><?php echo esc_html( mlsimport_oauth_redirect_domain( $params['redirect_uri'] ) ); ?></p>
		<p><?php esc_html_e( 'Allow only if you started this from ChatGPT or Claude yourself.', 'mlsimport' ); ?></p>
		<p><?php esc_html_e( 'The app gets your full administrator access to MLSImport and to the site content it works on, there is no read-only option: it can change MLSImport settings, connect an MLS and import listings; create, change and delete pages, posts, menus, listings and media, and change the homepage; read saved searches and leads, including visitors\' names and contact details; change who receives lead and notification emails; and send email from this site. Deleting takes two calls and is marked so the app asks you first, but the app itself sends the confirmation.', 'mlsimport' ); ?></p>
		<p><?php esc_html_e( 'You can disconnect it at any time in MLSImport > Settings > Tools.', 'mlsimport' ); ?></p>
		<p class="mlsimport-oauth-note"><?php esc_html_e( 'Claude will ask you before every change unless you turn on Skip approvals for this connector, once, in Claude > Settings > Connectors > MLSImport.', 'mlsimport' ); ?></p>
		<form method="post" action="<?php echo esc_url( mlsimport_oauth_authorize_url() ); ?>">
			<?php wp_nonce_field( 'mlsimport_oauth_authorize_' . $params['client_id'] ); ?>
			<?php foreach ( $params as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<div class="mlsimport-oauth-actions">
				<button class="mlsimport-oauth-deny" type="submit" name="mlsimport_oauth_decision" value="deny"><?php esc_html_e( 'Deny', 'mlsimport' ); ?></button>
				<button class="mlsimport-oauth-allow" type="submit" name="mlsimport_oauth_decision" value="allow"><?php esc_html_e( 'Allow', 'mlsimport' ); ?></button>
			</div>
		</form>
	<?php endif; ?>
	</div>
</body>
</html>
	<?php
	exit;
}
