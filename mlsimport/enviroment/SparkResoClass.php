<?php
/**
 * SparkResoClass — RESO provider adapter for the Spark (FBS) MLS data source.
 *
 * Extends ResoBase. Holds a reference to the active theme importer so provider-specific
 * data handling can delegate to the current theme adapter. Currently a thin stub.
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
}
