<?php
/**
 * SparkResoClass — RESO provider adapter for the Spark (FBS) MLS data source.
 *
 * Owns Spark identity, Stored timestamp formatting, and saved-token login.
 *
 * @package MLSImport
 */
// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of SparkResoClass
 *
 * @author cretu
 */

class SparkResoClass extends ResoBase {

		/** @var string Stable provider type saved for Spark. */
		protected $provider_type = 'spark';

		/** @var bool Spark sends its saved bearer token unchanged. */
		protected $direct_uses_stored_token = true;

		// The theme importer instance this provider delegates to.
		public $theme_importer;

	/**
	 * Store the theme importer reference for later delegation.
	 *
	 * @param object  $theme_importer  The active theme importer instance.
	 */
	public function __construct( $theme_importer ) {
		// Keep the theme importer for provider-specific handling.
		$this->theme_importer = $theme_importer;
	}

	/** Format Spark sync times with seconds and a UTC suffix. */
	protected function format_stored_timestamp( $value ) {
		return $this->format_utc_timestamp( $value, false, true );
	}
}
