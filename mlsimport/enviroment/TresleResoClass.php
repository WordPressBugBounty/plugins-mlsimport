<?php
/**
 * TresleResoClass — RESO provider adapter for the Trestle (CoreLogic) MLS data source.
 *
 * Owns Trestle credentials, Stored timestamp formatting, query rules, and login.
 *
 * @package MLSImport
 */
// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/*
/**
 * Trestle Provider Family adapter.
 *
 * The historic class name is misspelled as `Tresle`; changing it would break
 * existing references, while the saved provider type remains `trestle`.
 */

class TresleResoClass extends ResoBase {
	/** @var string[] Trestle uses an OAuth client ID and secret. */
	protected $provider_credential_fields = array(
		'mlsimport_tresle_client_id',
		'mlsimport_tresle_client_secret',
	);

	// The theme importer instance this provider delegates to.
	public $theme_importer;


	/**
	 * Constructor for TresleResoClass.
	 *
	 * @param [Type] $theme_importer Description of the theme_importer parameter.
	 */
	public function __construct( $theme_importer ) {
		// Keep the theme importer for provider-specific handling.
		$this->theme_importer = $theme_importer;
	}

	/**
	 * Return the stable provider type used by saved MLS configuration.
	 *
	 * @return string
	 */
	public function type() {
		return 'trestle';
	}

	/** Format Trestle sync times as full OData DateTimeOffset literals. */
	protected function format_stored_timestamp( $value ) {
		return $this->format_utc_timestamp( $value, true, true );
	}

	/** Return Trestle's PrettyEnums query flag. */
	protected function direct_query_rules() {
		return array( 'pretty_enums' => true );
	}

	/** Build Trestle's client-credentials token request. */
	protected function direct_token_request( array $saved_options, array $config ) {
		return array(
			'url'  => 'https://api-trestle.corelogic.com/trestle/oidc/connect/token',
			'body' => array(
				'client_id'     => trim( (string) $saved_options['mlsimport_tresle_client_id'] ),
				'client_secret' => trim( (string) $saved_options['mlsimport_tresle_client_secret'] ),
				'grant_type'    => 'client_credentials',
				'scope'         => 'api',
			),
			'json' => false,
		);
	}
}
