<?php
/**
 * Pure normalizer for MLS status enum values.
 *
 * Trestle's PrettyEnums=true returns spaced enum labels ("Active Under
 * Contract") while the plugin's status config and older imports store the raw
 * RESO enum ("ActiveUnderContract"). Both forms must compare equal, so every
 * status comparison funnels its operands through this normalizer: lowercase
 * and strip spaces, yielding a single stable key ("activeundercontract").
 *
 * No WordPress calls — safe to unit-test in isolation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reduce a status enum value to its comparison key.
 *
 * @param mixed $value Raw or PrettyEnums status value (anything non-string yields '').
 * @return string Lowercased, space-free comparison key.
 */
function mlsimport_normalize_status_enum( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}

	return str_replace( ' ', '', strtolower( trim( $value ) ) );
}
