<?php
/**
 * Country resolution for newly created listings.
 *
 * The parsed listing arrives from the SaaS with the feed's own country under
 * meta.property_country: the Lambda maps the RESO `Country` field into that
 * key and translates ISO codes to full names (Centris's "CA" → "Canada").
 * The WpResidence projection historically hardcoded "United States" over it
 * for every new listing, which mislabelled Canadian imports.
 *
 * Step by step:
 * 1. Read meta.property_country from the parsed property.
 * 2. If the feed sent a non-empty country, keep it.
 * 3. Otherwise return the historical default, "United States", so US feeds
 *    that omit the field behave exactly as before.
 *
 * Kept WordPress-free so the rule is unit-testable
 * (tests/PropertyCountryTest.php) without loading the theme adapters.
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the country name to store for a listing.
 *
 * @param array $property Parsed incoming property (SaaS structure).
 * @return string Full country name for the theme's property_country meta.
 */
function mlsimport_property_country( $property ) {
	$country = $property['meta']['property_country'] ?? '';
	if ( is_string( $country ) && '' !== $country ) {
		return $country;
	}

	return 'United States';
}
