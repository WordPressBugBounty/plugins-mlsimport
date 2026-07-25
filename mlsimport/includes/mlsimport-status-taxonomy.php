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
	// Honor an explicit StandardStatus remap when the map array supplies one.
	if ( is_array( $taxonomy_map ) && isset( $taxonomy_map['StandardStatus'] ) ) {
		$mapped = trim( (string) $taxonomy_map['StandardStatus'] );
		// A non-empty mapping wins over the theme default.
		if ( '' !== $mapped ) {
			return $mapped;
		}
	}

	// Unmapped (or blank) -> fall back to the theme's default status taxonomy.
	return $default_taxonomy;
}

/**
 * Read the MLS status stored on a property, normalized for comparison.
 *
 * Each mode keeps status somewhere else, so the property's OWN post type picks
 * the source — not whichever theme happens to be active. Standalone (990)
 * listings are `mlsimport_property` and carry their status in the
 * mlsimport_status taxonomy (see class-mlsimport-standalone-reso-map.php, which
 * writes StandardStatus to `tax:mlsimport_status`); reading them through the
 * active theme's taxonomy returns '' and makes reconciliation delete every
 * listing it looks at.
 *
 * @param int   $property_id  The property post.
 * @param mixed $taxonomy_map The mls-fields-map-taxonomy array (user remap of StandardStatus).
 * @return string Normalized status, or '' when the property carries none.
 */
function mlsimport_read_property_status( $property_id, $taxonomy_map = array() ) {
	if ( 'mlsimport_property' === get_post_type( $property_id ) ) {
		// Standalone (990).
		$taxonomy = mlsimport_status_taxonomy( $taxonomy_map, 'mlsimport_status' );
	} elseif ( post_type_exists( 'estate_property' ) ) {
		// WPResidence / WpEstate.
		$taxonomy = mlsimport_status_taxonomy( $taxonomy_map, 'property_status' );
	} elseif ( post_type_exists( 'property' ) && taxonomy_exists( 'property_label' ) ) {
		// Houzez.
		$taxonomy = mlsimport_status_taxonomy( $taxonomy_map, 'property_label' );
	} else {
		// Real Homes keeps status in post meta, not a taxonomy.
		return mlsimport_normalize_status_enum( get_post_meta( $property_id, 'inspiry_property_label', true ) );
	}

	// Read the status term(s) attached to the property in the resolved taxonomy.
	$terms = get_the_terms( $property_id, $taxonomy );
	// No terms (or an error) -> property carries no status.
	if ( empty( $terms ) || ! is_array( $terms ) ) {
		return '';
	}

	// Use the first term's name as the status, normalized for comparison.
	return mlsimport_normalize_status_enum( $terms[0]->name );
}
