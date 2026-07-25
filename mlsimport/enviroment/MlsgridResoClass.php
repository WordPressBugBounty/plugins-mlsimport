<?php 
/**
 * MLS provider adapter: MLS Grid (RESO Web API standard).
 *
 * One of the per-provider adapters in enviroment/ that sit between an MLS data
 * source and the active theme importer. This class is a thin subtype of
 * ResoBase; it holds a reference to the theme importer so provider-specific
 * mapping can delegate to the theme adapter. MLS Grid currently adds no
 * behaviour of its own beyond holding that reference. (The class docblock
 * below reads "BridgeResoClass" — an unchanged copy-paste from that adapter.)
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
class MlsgridResoClass extends ResoBase {


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
}
