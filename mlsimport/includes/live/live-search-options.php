<?php
/**
 * Live mode: search-form option lists and slider bounds from the MLS.
 *
 * The form, its field catalog and its controls are unchanged
 * (Mlsimport_Page_Block_Search_Fields); only the option SOURCE switches. Each
 * taxonomy-backed field draws its dropdown from its RESO field's enum in the
 * saved MLS metadata (mlsimport_mls_metadata_mls_enums) — the full list, as
 * the Import Task screen ships it. A field with no RESO enum renders empty
 * and hides itself, exactly like an empty taxonomy in stored mode.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed a search field's options from the MLS enums (seam #4). Hooked on the
 * pre filter, BEFORE the taxonomy terms are queried — live mode never needs
 * the local terms, so the DB work is skipped rather than discarded.
 *
 * @param array|null $pre Prior short-circuit value (null = query terms).
 * @param array      $def The field definition.
 * @return array|null
 */
function mlsimport_live_search_field_options( $pre, $def ) {
	// Already answered, gate off, or no field def: leave term-querying to run.
	if ( null !== $pre || ! mlsimport_live_mode_active() || ! is_array( $def ) ) {
		return $pre;
	}

	// The same param => RESO field vocabulary the live query filters by, so a
	// dropdown can only ever offer values the search will honour.
	$lists = mlsimport_live_param_vocabulary()['lists'];
	$key   = isset( $def['key'] ) ? (string) $def['key'] : '';
	// status maps to StandardStatus; other fields come from the vocabulary.
	$field = 'status' === $key ? 'StandardStatus' : ( isset( $lists[ $key ] ) ? $lists[ $key ] : '' );
	// No RESO field behind this control: render empty (field hides itself).
	if ( '' === $field ) {
		return array();
	}

	// Pull this field's enum from the saved MLS metadata.
	$enums = mlsimport_live_property_enums();
	if ( empty( $enums[ $field ] ) || ! is_array( $enums[ $field ] ) ) {
		return array();
	}

	// value => label (falling back to the value itself when the label is blank).
	$out = array();
	foreach ( $enums[ $field ] as $value => $label ) {
		$out[ (string) $value ] = is_string( $label ) && '' !== $label ? $label : (string) $value;
	}
	return $out;
}
add_filter( 'mlsimport_search_field_options_pre', 'mlsimport_live_search_field_options', 10, 2 );

/**
 * Hide the keyword box in live mode: OData has no full-text search, so the
 * field would silently do nothing (spec v1 limitation).
 *
 * @param array|null $fields Visible field keys; null means show every field.
 * @return array|null
 */
function mlsimport_live_hide_keyword_field( $fields ) {
	// Gate off: leave the visible-field list untouched.
	if ( ! mlsimport_live_mode_active() ) {
		return $fields;
	}
	// null means "all fields": expand to the full default set first so the
	// diff below has a concrete list to remove keywords from.
	if ( null === $fields ) {
		$fields = array_merge( array( 'keywords' ), Mlsimport_Page_Block_Search_Fields::catalog(), array( 'sort' ) );
	}
	// Drop the keyword field and re-index.
	return array_values( array_diff( (array) $fields, array( 'keywords' ) ) );
}
add_filter( 'mlsimport_listings_visible_fields', 'mlsimport_live_hide_keyword_field' );

/**
 * Hide the half-map draw-on-map tools in live mode: point-in-polygon search
 * needs the local listings table (MLS OData has no portable geo.intersects),
 * so a drawn shape could not be honoured.
 *
 * @param bool $enabled Whether the draw tools render.
 * @return bool
 */
function mlsimport_live_hide_draw_tools( $enabled ) {
	// Force the draw tools off in live mode; otherwise honour the caller's value.
	return mlsimport_live_mode_active() ? false : $enabled;
}
add_filter( 'mlsimport_half_map_draw_tools', 'mlsimport_live_hide_draw_tools' );

/**
 * The saved MLS PropertyEnums map: RESO field => ( value => label ).
 *
 * The enum JSON runs to hundreds of KB, so the decode is memoized on the raw
 * option value — a form full of dropdowns parses it once, while a mid-request
 * option change (settings save, tests) still takes effect.
 *
 * @return array
 */
function mlsimport_live_property_enums(): array {
	// Memoize the decode on the raw option so a form of dropdowns parses once.
	static $memo_raw = null;
	static $memo     = array();

	// No saved enum blob: no options.
	$raw = get_option( 'mlsimport_mls_metadata_mls_enums', '' );
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}
	// Same raw string as last time: return the cached decode.
	if ( $raw === $memo_raw ) {
		return $memo;
	}

	// Decode and pull the PropertyEnums map; remember it against this raw value.
	$decoded  = json_decode( $raw, true );
	$memo_raw = $raw;
	$memo     = isset( $decoded['global_array']['PropertyEnums'] ) && is_array( $decoded['global_array']['PropertyEnums'] )
		? $decoded['global_array']['PropertyEnums']
		: array();

	return $memo;
}

/**
 * The price slider's ceiling from the MLS: the highest live list price,
 * cached a day (the stored version reads MAX(price) from the local table).
 *
 * @param int $ceiling The stored-mode ceiling.
 * @return int
 */
function mlsimport_live_price_ceiling( $ceiling ) {
	// Gate off: keep the stored-mode ceiling.
	if ( ! mlsimport_live_mode_active() ) {
		return $ceiling;
	}

	// Recompute only on a cold cache.
	$cached = get_transient( 'mlsimport_live_price_ceiling' );
	if ( false === $cached ) {
		// One-record search sorted by price desc = the single most expensive listing.
		$result = mlsimport_live_search(
			array(
				'orderby' => 'price',
				'order'   => 'DESC',
				'limit'   => 1,
				'page'    => 1,
			)
		);
		// Pull that top listing's price via the reso-map row.
		$top    = null;
		if ( is_array( $result ) && ! empty( $result['records'][0] ) && is_array( $result['records'][0] ) ) {
			$top = mlsimport_live_row_from_reso( $result['records'][0] )->price;
		}
		if ( null === $top || (float) $top <= 0 ) {
			// MLS unreachable or priceless top record: keep the stored ceiling,
			// don't cache the failure.
			return $ceiling;
		}
		// Round up to a whole currency unit and cache for a day.
		$cached = (int) ceil( (float) $top );
		set_transient( 'mlsimport_live_price_ceiling', $cached, DAY_IN_SECONDS );
	}

	// Never return below 1 (the slider needs a positive ceiling).
	return max( 1, (int) $cached );
}
add_filter( 'mlsimport_search_price_ceiling', 'mlsimport_live_price_ceiling' );
