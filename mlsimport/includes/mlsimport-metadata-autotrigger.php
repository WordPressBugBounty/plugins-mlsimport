<?php
/**
 * Metadata auto-trigger decision for the credential surfaces.
 *
 * The Field Options tab saves the whole import-field configuration server-side
 * inside the mlsimport_saas_get_metadata_function AJAX handler (metadata fetch
 * + reconcile + mlsimport_admin_fields_select save). Historically that AJAX
 * only fired when the user opened the Field Options tab. This module lets the
 * credential surfaces (settings display_options tab, onboarding account step)
 * fire the very same AJAX in the background the moment BOTH connections are
 * confirmed, so the field configuration is already saved before the user ever
 * opens Field Options.
 *
 * Step by step:
 * 1. The partial computes the SaaS token and the MLS connection flag exactly
 *    as it does for the two green status messages.
 * 2. It calls mlsimport_metadata_autotrigger_markup() with those values, the
 *    current mlsimport_mls_metadata_populated flag, and a fresh nonce.
 * 3. When (and only when) token present + connected + not yet populated, the
 *    returned markup carries the #mlsimport_saas_get_metadata nonce input the
 *    AJAX needs plus a DOM-ready call to the existing JS function
 *    mlsimport_saas_get_metadata() in mlsimport-admin.js.
 * 4. Any credentials/MLS save deletes mlsimport_mls_metadata_populated, so the
 *    trigger re-arms automatically after a change and fires at most once per
 *    validated configuration.
 *
 * Pure PHP on purpose: no WordPress calls, the caller passes every input
 * (nonce already escaped), so the rule is unit-testable without stubs.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the background metadata-gather trigger markup, or nothing.
 *
 * @param string $token              SaaS API token ('' = account not connected).
 * @param string $is_mls_connected   mlsimport_connection_test flag ('yes' = connected).
 * @param string $metadata_populated mlsimport_mls_metadata_populated flag ('yes' = already gathered).
 * @param string $nonce              Escaped nonce for the mlsimport_saas_get_metadata action.
 * @return string Trigger markup when the gather should fire, '' otherwise.
 */
function mlsimport_metadata_autotrigger_markup( $token, $is_mls_connected, $metadata_populated, $nonce ) {
	// Fires at most once: only a credentials/MLS save clears this flag,
	// which re-arms the trigger for the new configuration.
	if ( 'yes' === $metadata_populated ) {
		return '';
	}
	// BOTH connections must be confirmed: SaaS account (token) and MLS.
	if ( '' === trim( $token ) || 'yes' !== $is_mls_connected ) {
		return '';
	}
	// Post the gather AJAX directly instead of calling the shared JS helper
	// mlsimport_saas_get_metadata(): that helper reloads the page on success,
	// which would yank the credentials page from under the user (and abort a
	// click to another admin page while the gather is in flight). Here the
	// gather is fire-and-forget; only the Field Options "Stand By" page needs
	// the reloading variant.
	return '<input type="hidden" id="mlsimport_saas_get_metadata" value="' . $nonce . '">'
		. '<script>jQuery(document).ready(function(){'
		. " jQuery.post(ajaxurl, { action: 'mlsimport_saas_get_metadata_function', security: '" . $nonce . "' });"
		. ' });</script>';
}
