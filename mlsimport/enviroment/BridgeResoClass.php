<?php 
/**
 * MLS provider adapter: Bridge (Bridge Data Output API, RESO standard).
 *
 * Owns Bridge identity, Stored request exceptions, native Direct MLS queries,
 * endpoint rewriting, and saved-token login.
 */
if ( ! defined( 'ABSPATH' ) ) {
	// Block direct web access — only load when WordPress is bootstrapped.
	exit; // Exit if accessed directly
}

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of BridgeResoClass
 *
 * @author cretu
 */
class BridgeResoClass extends ResoBase {

		/** @var string Stable provider type saved for Bridge. */
		protected $provider_type = 'bridge';

		/** @var bool Bridge sends its saved bearer token unchanged. */
		protected $direct_uses_stored_token = true;

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

	/** Format Bridge sync times with seconds and no forced UTC suffix. */
	protected function format_stored_timestamp( $value ) {
		return $this->format_utc_timestamp( $value, false, false );
	}

	/** Remove StandardStatus for Edmonton, whose feed has no status field. */
	public function prepare_stored_request( array $arguments, $last_date = '' ) {
		if ( isset( $arguments['mls_id'] ) && 111 === (int) $arguments['mls_id'] ) {
			unset( $arguments['status'] );
		}
		return parent::prepare_stored_request( $arguments, $last_date );
	}

	/**
	 * Build Bridge's native listings query instead of an OData query.
	 *
	 * @param array $params Standalone filter and paging values.
	 * @param array $config Per-MLS config including field_corellation.
	 * @return string
	 */
	public function build_direct_query( array $params, array $config ) {
		return mlsimport_live_build_query_bridge( $params, $config );
	}

	/** Build Bridge's native exact-ListingKey query. */
	public function build_direct_get_query( $listing_key, array $config ) {
		return '?ListingKey=' . rawurlencode( (string) $listing_key );
	}

	/**
	 * Rewrite Bridge's configured OData Property URL to its native listings URL.
	 *
	 * @param array $config Per-MLS configuration.
	 * @return string
	 */
	public function direct_endpoint( array $config ) {
		$base   = parent::direct_endpoint( $config );
		$native = preg_replace( '#/OData/([^/?]+)/Property/?#i', '/$1/listings', $base );
		return is_string( $native ) && '' !== $native ? $native : $base;
	}
}
