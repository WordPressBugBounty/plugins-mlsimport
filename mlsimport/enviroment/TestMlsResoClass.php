<?php
/**
 * MLS provider adapter: the Test MLS (catalogue row 100, "Test MLS (sample listings)").
 *
 * Row 100 is Bridge's public sample dataset test_sd (10,000 generated listings).
 * It exists so a site whose MLS has not approved any credentials yet can still
 * import something (issue #376). The SaaS pulls it with its own server-held
 * Bridge token, so on the site it is a Bridge MLS Connection that needs no
 * Provider Credentials:
 *
 * 1. credential_fields() is empty: the Add-MLS drawer and the wizard show no
 *    token field, and connection_test_payload() sends the mls_id alone.
 * 2. Direct (live) mode is not offered: there is no local token to call
 *    Bridge with, and the token must never reach the site.
 *
 * Everything else (Stored request shaping, timestamps) is plain Bridge.
 * Mlsimport_Provider_Family::adapter() picks this class by id, whatever
 * provider type the SaaS reported, so the id check lives in one place.
 */
if ( ! defined( 'ABSPATH' ) ) {
	// Block direct web access — only load when WordPress is bootstrapped.
	exit; // Exit if accessed directly
}

class TestMlsResoClass extends BridgeResoClass {

	/** @var string[] No saved option is needed: the SaaS holds the token. */
	protected $provider_credential_fields = array();

	/** @var bool No local token, so Direct MLS requests cannot be made. */
	protected $direct_access_supported = false;
}
