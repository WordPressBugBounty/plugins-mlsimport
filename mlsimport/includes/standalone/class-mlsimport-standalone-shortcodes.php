<?php
/**
 * Standalone (theme_id 990) shortcodes (M7).
 *
 * Thin wrappers over the one base render fn so [mlsimport_listings], the Gutenberg
 * block and the Elementor widget all emit identical output from a single source.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-render.php';

/**
 * Registers and handles the standalone front-end shortcodes.
 */
class Mlsimport_Standalone_Shortcodes {

	/**
	 * Filter attributes a listings shortcode/block accepts, mapped 1:1 to the
	 * render args. Scalar only; defaults are empty so unset atts are dropped.
	 */
	private const FILTER_ATTS = array(
		'price_min',
		'price_max',
		'beds',
		'baths',
		'sqft_min',
		'sqft_max',
		'lot_min',
		'lot_max',
		'year_min',
		'year_max',
		'hoa_max',
		'dom_max',
		'garage_min',
		'stories',
		'subdivision',
		// The "MLS #" search box: the public MLS number, matched exactly.
		'listing_id',
		'list_date_min',
		'location',
		'city',
		'state',
		'zip',
		'area',
		'county',
		'high_school_district',
		'label',
		'status',
		'property_type',
		'listing_type',
		'keywords',
		'features',
		'agent',
		'orderby',
		'order',
		'limit',
		'page',
		'lat_min',
		'lat_max',
		'lng_min',
		'lng_max',
		'polygon',
	);

	/**
	 * Register the shortcodes.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( 'mlsimport_listings', array( __CLASS__, 'listings' ) );
	}

	/**
	 * The filter keys the listings surfaces accept (shared with the block).
	 *
	 * @return string[]
	 */
	public static function filter_keys(): array {
		return self::FILTER_ATTS;
	}

	/**
	 * [mlsimport_listings] handler — map attributes to filter args and render.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function listings( $atts ): string {
		$atts = (array) $atts;
		$args = self::atts_to_args( $atts );
		// search_fields is display config (which search fields to show), not a filter
		// key, so it lives outside the FILTER_ATTS whitelist atts_to_args applies.
		if ( isset( $atts['search_fields'] ) && '' !== trim( (string) $atts['search_fields'] ) ) {
			$args['search_fields'] = (string) $atts['search_fields'];
		}
		// fields_per_row (how many search fields per row) is display config, not a
		// filter key, so it also lives outside the FILTER_ATTS whitelist.
		if ( isset( $atts['fields_per_row'] ) && '' !== trim( (string) $atts['fields_per_row'] ) ) {
			$args['fields_per_row'] = (string) $atts['fields_per_row'];
		}
		return Mlsimport_Standalone_Render::render_grid( $args );
	}

	/**
	 * Normalize raw shortcode/block attributes into render args. Comma lists
	 * (status, property_type, features) become arrays.
	 *
	 * @param array|string $atts Raw attributes.
	 * @return array
	 */
	public static function atts_to_args( $atts ): array {
		// Whitelist to the known filter keys (unknown atts are dropped, defaults '').
		$atts = shortcode_atts( array_fill_keys( self::FILTER_ATTS, '' ), (array) $atts, 'mlsimport_listings' );

		// Keys whose value is a set of choices rather than a single scalar.
		$multi = array( 'status', 'property_type', 'listing_type', 'features', 'area', 'county', 'high_school_district', 'label', 'city', 'state', 'zip' );

		$args = array();
		foreach ( $atts as $key => $value ) {
			// Skip unset filters so they don't narrow the query.
			if ( '' === $value || array() === $value ) {
				continue;
			}
			if ( in_array( $key, $multi, true ) ) {
				// A multi-select submits an array (name="key[]"); a shortcode/legacy
				// attribute submits a comma list. Both normalize to a value array.
				$values       = is_array( $value ) ? $value : explode( ',', (string) $value );
				// Trim, stringify and drop empties so stray commas don't add blank terms.
				$values       = array_values( array_filter( array_map( 'trim', array_map( 'strval', $values ) ), 'strlen' ) );
				$args[ $key ] = $values;
			} elseif ( ! is_array( $value ) ) {
				// Single-value filter: pass the scalar straight through.
				$args[ $key ] = $value;
			}
		}

		return $args;
	}
}
