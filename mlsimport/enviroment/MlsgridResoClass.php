<?php 
/**
 * MLS provider adapter: MLS Grid (RESO Web API standard).
 *
 * Owns MLS Grid identity and saved-token login behavior.
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
 * MLS Grid Provider Family adapter.
 *
 * @author cretu
 */
class MlsgridResoClass extends ResoBase {

		/** @var string Stable provider type saved for MLS Grid. */
		protected $provider_type = 'mlsgrid';

		/** @var bool MLS Grid sends its saved bearer token unchanged. */
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
}
