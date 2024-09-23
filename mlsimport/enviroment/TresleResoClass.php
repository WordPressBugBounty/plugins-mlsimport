<?php 
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

	public $theme_importer;


	/**
	 * Constructor for TresleResoClass.
	 *
	 * @param [Type] $theme_importer Description of the theme_importer parameter.
	 */
	public function __construct( $theme_importer ) {
		$this->theme_importer = $theme_importer;
	}
}
