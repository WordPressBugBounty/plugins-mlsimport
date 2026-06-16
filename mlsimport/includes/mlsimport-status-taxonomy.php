<?php
/**
 * Pure resolver for the taxonomy that holds a listing's MLS status.
 *
 * No WordPress calls — safe to unit-test in isolation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the taxonomy that holds a listing's MLS status.
 *
 * Honors the user's StandardStatus field mapping (the global
 * mls-fields-map-taxonomy array) and falls back to the theme default when
 * StandardStatus is not mapped.
 *
 * @param mixed  $taxonomy_map     The mls-fields-map-taxonomy array (anything else is treated as unmapped).
 * @param string $default_taxonomy The theme's default status taxonomy.
 * @return string
 */
function mlsimport_status_taxonomy( $taxonomy_map, $default_taxonomy ) {
	if ( is_array( $taxonomy_map ) && isset( $taxonomy_map['StandardStatus'] ) ) {
		$mapped = trim( (string) $taxonomy_map['StandardStatus'] );
		if ( '' !== $mapped ) {
			return $mapped;
		}
	}

	return $default_taxonomy;
}
