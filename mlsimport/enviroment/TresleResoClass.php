<?php
/**
 * TresleResoClass — RESO provider adapter for the Trestle (CoreLogic) MLS data source.
 *
 * Extends ResoBase. Holds a reference to the active theme importer so provider-specific
 * data handling can delegate to the current theme adapter. Currently a thin stub.
 * (The class docblock below carries a copy-pasted "BridgeResoClass" heading.)
 *
 * @package MLSImport
 */
// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/*
/**
 * BridgeResoClass File Description
 *
 * This file contains the TresleResoClass which extends from ResoBase.
 * It is used for [briefly describe the purpose of the class].
 */

class TresleResoClass extends ResoBase {

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
}
