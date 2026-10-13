<?php
/**
 * MLS provider adapter: MLS Grid (RESO Web API v2, https://api.mlsgrid.com/v2).
 *
 * MLS Grid serves many MLSes from one endpoint and one static bearer token.
 * The MLS a request reads is chosen by the OriginatingSystemName filter, which
 * AWS adds from the mld_details row; this adapter owns the WordPress-side rules
 * that keep an Import Task inside what MLS Grid accepts:
 *
 * - A request may filter only StandardStatus, PropertyType, ListingId and
 *   ListOfficeMlsId (plus OriginatingSystemName, MlgCanView and
 *   ModificationTimestamp, which AWS handles). Any other field - City, ListPrice,
 *   PostalCode, PropertySubType, agent keys - is an HTTP 400, so the task editor
 *   offers only the usable inputs and the price range is dropped from requests.
 * - The hourly-sync watermark must be a full OData literal
 *   (yyyy-mm-ddThh:mm:ss.000Z); the short local-time form is rejected.
 * - The pasted token is sent unchanged as a Bearer (no token exchange).
 *
 * Verified live against api-demo.mlsgrid.com (OriginatingSystemName paar) on
 * 2026-10-12.
 *
 * @package MLSImport
 */
if ( ! defined( 'ABSPATH' ) ) {
	// Block direct web access — only load when WordPress is bootstrapped.
	exit; // Exit if accessed directly
}

/**
 * MLS Grid Provider Family adapter.
 *
 * @author cretu
 */
class MlsgridResoClass extends ResoBase {

	/** @var string Stable provider type saved for MLS Grid. */
	protected $provider_type = 'mlsgrid';

	/** @var bool MLS Grid sends its saved bearer token unchanged. */
	protected $direct_uses_stored_token = true;

	/** @var bool ListPrice is not a searchable field on MLS Grid. */
	protected $price_filter_supported = false;

	/**
	 * Import Task inputs MLS Grid can act on, in the editor's order.
	 *
	 * StandardStatusProtect is a reconciliation setting, not a request filter,
	 * and CustomParameters is the raw escape hatch for an expert; both stay.
	 *
	 * @var string[]
	 */
	const TASK_FIELDS = array(
		'PropertyType',
		'StandardStatus',
		'StandardStatusProtect',
		'ListOfficeMlsId',
		'ListingId',
		'CustomParameters',
	);

	// Active theme adapter (e.g. ResidenceClass) this provider maps RESO fields through.
	public $theme_importer;

	/**
	 * Store the theme importer so RESO-to-theme mapping can delegate to it.
	 *
	 * @param object $theme_importer The active theme adapter instance.
	 */
	public function __construct( $theme_importer ) {
		// Keep the theme adapter reference for later field mapping.
		$this->theme_importer = $theme_importer;
	}

	/**
	 * Keep only the Import Task inputs MLS Grid accepts as server-side filters.
	 *
	 * Step by step:
	 * 1. Walk the shared field catalog in its own order.
	 * 2. Keep a field only when it is in TASK_FIELDS; everything else would be
	 *    rejected by MLS Grid with "Invalid filter field".
	 *
	 * @param array $fields Import Task fields keyed by RESO field name.
	 * @return array The MLS Grid subset, catalog order preserved.
	 */
	public function prepare_import_task_fields( array $fields ) {
		return array_intersect_key( $fields, array_flip( self::TASK_FIELDS ) );
	}

	/**
	 * Request keys of the Import Task inputs the editor does NOT offer on MLS Grid.
	 *
	 * These are the request names the shared request builder
	 * (Mlsimport_Admin::mlsimport_saas_import_prepare_arguments) gives to the
	 * fields that prepare_import_task_fields() hides, plus the price range the
	 * editor saves outside the field catalog. The builder reads every saved task
	 * meta value, so a task created before the field list was narrowed, or
	 * copied from another provider, still carries values for them (for example
	 * "Internet Entire Listing Display = yes"): MLS Grid answered 400 and the
	 * error pointed the customer to an option the editor no longer shows.
	 *
	 * @var string[]
	 */
	const HIDDEN_REQUEST_KEYS = array(
		'list_price_min',
		'list_price_max',
		'city',
		'county_or_parish',
		'country',
		'mls_area_major',
		'subdivision_name',
		'postal_code',
		'property_subtype',
		'internet_entirelisting_displayyn',
		'internet_address_displayyn',
		'list_agentkey',
		'list_agentmlsid',
		'buyer_agentmlsid',
		'list_officekey',
		'listingkey',
		'exclude_list_officekey',
		'exclude_list_officemlsid',
		'exclude_list_agentkey',
		'exclude_list_agentmlsid',
	);

	/**
	 * Send only the filters the task editor offers: anything else is an MLS Grid 400.
	 *
	 * Step by step:
	 * 1. Remove every HIDDEN_REQUEST_KEYS entry from the shared arguments, so a
	 *    saved value for a field the editor hides (and the always-saved price
	 *    range) never reaches the request.
	 * 2. Let the base class add the hourly-sync watermark.
	 *
	 * @param array  $arguments Shared Stored-mode request arguments.
	 * @param string $last_date Last Successful Sync Time, or an empty string.
	 * @return array{success:bool,arguments:array,error:?array}
	 */
	public function prepare_stored_request( array $arguments, $last_date = '' ) {
		$arguments = array_diff_key( $arguments, array_flip( self::HIDDEN_REQUEST_KEYS ) );
		return parent::prepare_stored_request( $arguments, $last_date );
	}

	/**
	 * Format sync times as full OData DateTimeOffset literals in UTC.
	 *
	 * @param string $value Saved sync timestamp (site-local, no seconds).
	 * @return string yyyy-mm-ddThh:mm:ss.000Z
	 */
	protected function format_stored_timestamp( $value ) {
		return $this->format_utc_timestamp( $value, true, true );
	}
}
