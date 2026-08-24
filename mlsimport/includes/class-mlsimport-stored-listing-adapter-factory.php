<?php
/**
 * Select the configured Stored mode theme adapter explicitly.
 *
 * Saved theme IDs are configuration data, not class names. Keeping the complete
 * ID-to-adapter mapping in this factory prevents dynamic class derivation and
 * makes a missing or unsupported adapter fail before any listing is processed.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create one of the five supported Stored mode adapters.
 */
final class Mlsimport_Stored_Listing_Adapter_Factory {

	/**
	 * Return the concrete adapter named by a saved theme ID.
	 *
	 * Each supported case is deliberately written out so adding a theme requires
	 * an explicit code and test change. Unknown IDs never receive a stub or a
	 * guessed class because that would defer a configuration error until a write.
	 *
	 * @param int $theme_id Saved `mlsimport_theme_used` value.
	 * @return object Configured Stored mode theme adapter.
	 * @throws UnexpectedValueException When the theme ID is unsupported.
	 */
	public function create( int $theme_id ) {
		switch ( $theme_id ) {
			case 990:
				return new StandaloneClass();
			case 991:
				return new ResidenceClass();
			case 992:
				return new HouzezClass();
			case 993:
				return new RealHomesClass();
			case 994:
				return new EstateClass();
			default:
				throw new UnexpectedValueException(
					'Stored mode theme ' . $theme_id . ' is not supported.'
				);
		}
	}
}
