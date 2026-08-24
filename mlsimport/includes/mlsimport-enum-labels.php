<?php
/**
 * Enum option label resolution for Import Task filter dropdowns.
 *
 * The Import Task metabox builds its City / CountyOrParish / PropertyType
 * selects from the MLS enum lists saved in the `mlsimport_mls_metadata_mls_enums`
 * option. The option VALUE submitted by the form is always the enum KEY — that
 * is the value the SaaS URL builder puts into the OData filter. The LABEL the
 * user sees comes from this helper.
 *
 * For every classic MLS the enum lists are name=>name ("Dallas" => "Dallas"),
 * so the label equals the key and nothing changes visually. Code=>name
 * providers — Centris (mls_id 9001) stores its City list as
 * "839" => "Montréal" because listings carry only CityOrTownshipKey — get the
 * human name as the label while the form keeps submitting the code.
 *
 * Kept as a standalone, WordPress-free file so the rule is unit-testable
 * (tests/EnumOptionLabelTest.php) without loading the admin monolith.
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the display label for one enum dropdown option.
 *
 * Step by step:
 * 1. Look the key up in the enum map (key => label) saved for the MLS.
 * 2. If the map holds a non-empty string label, show that.
 * 3. Otherwise fall back to the key itself, so an incomplete or name=>name
 *    map can never blank an option.
 *
 * @param string $select_key The enum key used as the option value.
 * @param array  $enum_map   The enum list for this field (key => label).
 * @return string The label to render for the option.
 */
function mlsimport_enum_option_label( $select_key, $enum_map ) {
	if ( is_array( $enum_map ) && isset( $enum_map[ $select_key ] ) ) {
		$label = $enum_map[ $select_key ];
		if ( is_string( $label ) && '' !== $label ) {
			return $label;
		}
	}

	return (string) $select_key;
}
