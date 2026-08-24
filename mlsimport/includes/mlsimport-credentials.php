<?php
/**
 * Credential-field handling rule for the MLSImport plugin (GitHub issue #204).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * WordPress' sanitize_text_field() strips every "%" followed by two hex
 * characters (it treats them as percent-encoded octets), collapses runs of
 * whitespace and removes <...> sequences; esc_attr() turns & < > " ' into
 * HTML entities. Running either over a password silently corrupts it: a
 * customer password like Abcd1234%47Xyz was stored/sent as Abcd1234Xyz and
 * authentication failed with a generic "check your Username and Password".
 *
 * THE RULE
 * --------
 * Credential values (passwords, client secrets, tokens) are NEVER sanitized
 * or escaped on save or on read:
 *   - values read from $_POST use trim( wp_unslash( ... ) ),
 *   - values read from stored options use trim() only,
 *   - escaping happens exclusively at OUTPUT (esc_attr() on the rendered
 *     value="" attribute in the settings form, which is already correct).
 *
 * This file provides the single shared predicate that generic sanitizer
 * loops (the settings whitelist copy and the onboarding step saver) use to
 * decide whether a field is a credential and must skip sanitization.
 *
 * @link       https://mlsimport.com/
 * @since      7.1.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decide whether an option/field key holds a credential value.
 *
 * Step by step:
 * 1. Lowercase the key so the match is case-insensitive.
 * 2. Match keys ENDING in "password", "secret" or "token" — this covers
 *    every credential the plugin stores (mlsimport_password, auth_password,
 *    client_secret, mlsimport_*_client_secret, mlsimport_*_password,
 *    mlsimport_mls_token, and the onboarding step keys password/mls_token)
 *    while leaving usernames, ids and display fields to normal sanitizing.
 *
 * @param string $key Option or posted-field key.
 * @return bool True when the key's value must never be sanitized/escaped.
 */
function mlsimport_is_credential_key( $key ) {
	return (bool) preg_match( '/(password|secret|token)$/', strtolower( (string) $key ) );
}
