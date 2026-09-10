<?php
/**
 * MLSImport account status: WHY the last SaaS token request failed.
 *
 * The SaaS 'token' endpoint answers three ways:
 *   - HTTP 200  -> the password was right and the account is active (token);
 *   - HTTP 401  -> wrong password or unknown username/email ("Invalid credentials");
 *   - HTTP 403  -> the password was right but the account has NO active
 *                  subscription (the portal's is_active flag is not "yes").
 *
 * Before this module the plugin reduced all three to "got a token or not"
 * and every screen blamed the password, so customers without a subscription
 * opened tickets about a password that was correct. This file owns the one
 * option that remembers the reason and the one message builder every
 * "not connected" surface prints, so the wording lives in a single place.
 * All login failures use the same prominent notice with a heading and message.
 * Only subscription failures add a plans button. Both account screens use it.
 *
 * Public interface:
 *   mlsimport_account_status_record( $answer )  record the token reply reason
 *   mlsimport_account_status()                  'no_subscription' | 'invalid_credentials' | ''
 *   mlsimport_account_not_connected_html()      the warning box for the reason
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option holding the reason of the last failed token request. */
define( 'MLSIMPORT_ACCOUNT_STATUS_OPTION', 'mlsimport_account_status' );

/** Portal page where a customer without a subscription can buy one. */
define( 'MLSIMPORT_ACCOUNT_SUBSCRIBE_URL', 'https://mlsimport.com/mls-import-plugin-pricing/' );

/**
 * Record the outcome of a SaaS token request.
 *
 * Accepts the reply as returned by ThemeImport::globalApiRequestSaas(): the
 * decoded body on HTTP 200 (success => true) or the failure descriptor
 * (success => false, error_code => HTTP status) on anything else. Only the
 * two definitive server verdicts are remembered:
 *
 *   1. success true         -> the reason is cleared (account is fine);
 *   2. error_code 403       -> 'no_subscription';
 *   3. error_code 401       -> 'invalid_credentials';
 *   4. anything else (transport error, 5xx, malformed) -> left untouched,
 *      because a hiccup says nothing about the account.
 *
 * @param mixed $answer The token endpoint reply.
 * @return void
 */
function mlsimport_account_status_record( $answer ) {
	// Step 1: a successful login wipes any previous failure reason.
	if ( is_array( $answer ) && ! empty( $answer['success'] ) ) {
		delete_option( MLSIMPORT_ACCOUNT_STATUS_OPTION );
		return;
	}

	// Step 2: map the HTTP status the server answered with to a reason.
	$code = is_array( $answer ) && isset( $answer['error_code'] ) ? (int) $answer['error_code'] : 0;
	if ( 403 === $code ) {
		update_option( MLSIMPORT_ACCOUNT_STATUS_OPTION, 'no_subscription', false );
	} elseif ( 401 === $code ) {
		update_option( MLSIMPORT_ACCOUNT_STATUS_OPTION, 'invalid_credentials', false );
	}
	// Step 3: any other outcome is not a verdict about the account; keep the
	// last known reason so the screens do not flip on a transient failure.
}

/**
 * The reason of the last failed token request.
 *
 * @return string 'no_subscription', 'invalid_credentials' or '' when unknown.
 */
function mlsimport_account_status() {
	return (string) get_option( MLSIMPORT_ACCOUNT_STATUS_OPTION, '' );
}

/**
 * The "not connected" sentence for the current account status, plain text.
 *
 * Used where only text can be shown (the Connections screen sign-in error
 * line, the AJAX 'message' field). The login hint names both supported account
 * identifiers. No markup, no link.
 *
 * @return string Translated sentence.
 */
function mlsimport_account_not_connected_message() {
	// Step 1: no subscription -> say so, point at the portal.
	if ( 'no_subscription' === mlsimport_account_status() ) {
		return esc_html__( 'Your MLSImport account was found, but it has no active subscription. Please subscribe at mlsimport.com to connect the plugin.', 'mlsimport' );
	}

	// Step 2: suggest either accepted account identifier for a failed login.
	return esc_html__( 'You are not connected to MLSImport - Please check your username or email and password.', 'mlsimport' );
}

/**
 * The "not connected" warning box for the current account status.
 *
 * Every surface that used to print the generic "check your Username and
 * Password" box calls this instead, so a customer whose password is right
 * but who has no subscription is sent to the portal rather than to the
 * password field. The generic wording stays for every other reason.
 *
 * Every failure uses the same heading, explanatory paragraph and notice layout.
 * Subscription failures additionally include a prominent plans action.
 * The action's WordPress button class excludes it from the admin's text-link
 * color override, preserving its white label on the dark button background.
 *
 * @return string Escaped HTML of one <div class="mlsimport_warning">.
 */
function mlsimport_account_not_connected_html() {
	// Step 1: share one layout; the account verdict changes only the content.
	$is_unsubscribed = 'no_subscription' === mlsimport_account_status();
	$title = $is_unsubscribed
		? esc_html__( 'No active subscription', 'mlsimport' )
		: esc_html__( 'Unable to connect', 'mlsimport' );
	$html = '<div class="mlsimport_warning mlsimport-account-notice'
		. ( $is_unsubscribed ? ' mlsimport-account-subscription' : '' ) . '" role="alert">'
		. '<strong class="mlsimport-account-notice-title">' . $title . '</strong>'
		. '<p>' . mlsimport_account_not_connected_message() . '</p>';

	// Step 2: offer a purchase action only after confirmed subscription failure.
	if ( $is_unsubscribed ) {
		$html .= '<a class="button mlsimport-account-subscription-action" href="' . esc_url( MLSIMPORT_ACCOUNT_SUBSCRIBE_URL ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'View plans', 'mlsimport' )
			. '</a>';
	}
	return $html . '</div>';
}
