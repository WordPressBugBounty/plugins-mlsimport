<?php
/**
 * MLS provider adapter: Centris (Quebec provincial MLS, RESO Web API / OData v4).
 *
 * Centris is the single provincial MLS for Quebec, operated by the Association
 * professionnelle des courtiers immobiliers du Quebec (APCIQ/QPAREB). It serves
 * one federated feed at datadistributionqc.centris.ca rather than a platform
 * hosting many separate boards, so there is no OriginatingSystemName filter and
 * no board-selection step: one MLS id (9001) is the whole province.
 *
 * What this adapter owns:
 *
 * 1. Identity — the stable saved provider type string 'centris', which is what
 *    the mld_details DynamoDB row stores in its `type` attribute and what the
 *    AWS listings Lambda dispatches on.
 * 2. Login — Centris issues a static, long-lived Bearer token (the `cddk.<...>`
 *    string). Despite decoding to something that looks like client_id/secret, it
 *    is NOT an OAuth2 client_credentials pair: there is no token endpoint and no
 *    refresh. The saved token is sent verbatim in the Authorization header, so
 *    this adapter reuses ResoBase's shared stored-token login by declaring
 *    $direct_uses_stored_token, exactly like Bridge and RMLS.
 * 3. Timestamps — Centris is a strict OData v4 endpoint and rejects a bare
 *    'Y-m-d\TH:i' in a $filter=ModificationTimestamp comparison, so the stored
 *    sync marker is widened to a full DateTimeOffset literal before it leaves
 *    WordPress.
 *
 * What this adapter deliberately does NOT claim: Direct MLS (Standalone Live /
 * passthrough) support. Centris has two shape differences from every provider
 * already ported — Property carries no City string field (only a
 * CityOrTownshipKey foreign key into a separate CityOrTownship entity set) and
 * has no CountyOrParish concept at all (the StateRegion administrative region
 * is the closest analog). Until those two lookups are resolved, a Live query
 * built from the shared OData encoder would emit filters on fields Centris does
 * not have, so $direct_access_supported stays false and callers receive the
 * standard 'unsupported_direct_provider' outcome instead of a broken request.
 *
 * @package MLSImport
 */

// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/** Provider adapter for Centris (Quebec). */
class CentrisResoClass extends ResoBase {

	/** @var string Stable provider type saved with the MLS configuration. */
	protected $provider_type = 'centris';

	/**
	 * @var bool Direct MLS (Standalone Live) is not implemented for Centris yet.
	 *           See the file header: City and CountyOrParish have no matching
	 *           Property field, so the shared OData query cannot be trusted.
	 */
	protected $direct_access_supported = false;

	/**
	 * Widen the saved sync marker to a full OData DateTimeOffset literal.
	 *
	 * Step 1: the plugin stores the Last Successful Sync Time as 'Y-m-d\TH:i'.
	 * Step 2: Centris's OData v4 endpoint compares ModificationTimestamp as an
	 *         Edm.DateTimeOffset and rejects a value with no seconds and no
	 *         zone, which would surface to the customer as an import that finds
	 *         zero changed listings on every run.
	 * Step 3: re-emit the same instant as 'Y-m-d\TH:i:s.000\Z' (milliseconds and
	 *         the UTC Z suffix), which Centris accepts.
	 *
	 * @param string $value Saved sync timestamp.
	 * @return string Full UTC DateTimeOffset literal.
	 */
	protected function format_stored_timestamp( $value ) {
		return $this->format_utc_timestamp( $value, true, true );
	}
}
