<?php
/**
 * Live mode: the virtual single-property page.
 *
 * Live listings have no posts, so they get a virtual route instead of a
 * permalink: /{base}/{ListingKey}/ (base filterable, default "listing").
 * The route resolves through template_include — fetch the record (cached),
 * 404 when the MLS doesn't know the key, else render the loop-less live
 * template. The property sections then read their view model through seam #2
 * (mlsimport_property_data_pre), built here from the raw RESO record with the
 * same helpers the stored VM uses.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The URL base segment for live single pages.
 *
 * @return string
 */
function mlsimport_live_url_base(): string {
	/** Filter the live single-page URL base segment. @since 6.4 */
	$base = (string) apply_filters( 'mlsimport_live_url_base', 'listing' );
	return '' !== $base ? $base : 'listing';
}

/**
 * The virtual single-page URL for one live listing.
 *
 * @param string $listing_key RESO ListingKey.
 * @return string
 */
function mlsimport_live_url( string $listing_key ): string {
	return home_url( '/' . mlsimport_live_url_base() . '/' . rawurlencode( $listing_key ) . '/' );
}

/**
 * Register the live single route (rewrite + query var) while live mode is on.
 * The settings screen flushes rewrite rules when the toggle changes, so the
 * rule appears/disappears with the mode.
 *
 * @return void
 */
function mlsimport_live_register_route(): void {
	if ( ! mlsimport_live_mode_active() ) {
		return;
	}
	add_rewrite_rule(
		'^' . mlsimport_live_url_base() . '/([^/]+)/?$',
		'index.php?mlsimport_live_listing=$matches[1]',
		'top'
	);
}

/**
 * Expose the live listing query var.
 *
 * @param string[] $vars Public query vars.
 * @return string[]
 */
function mlsimport_live_query_vars( array $vars ): array {
	$vars[] = 'mlsimport_live_listing';
	return $vars;
}

/**
 * The raw RESO record behind the page being rendered (set by the route,
 * read by the VM seam). Request-scoped.
 *
 * @param array|null $set When given, becomes the current record.
 * @return array|null
 */
function mlsimport_live_current_record( ?array $set = null ): ?array {
	static $record = null;
	if ( null !== $set ) {
		$record = $set;
	}
	return $record;
}

/**
 * Resolve the virtual route: fetch the listing (cached) and serve the live
 * single template, or 404 when the key is unknown/unreachable.
 *
 * @param string $template The template WP resolved.
 * @return string
 */
function mlsimport_live_template_include( $template ) {
	$key = (string) get_query_var( 'mlsimport_live_listing' );
	if ( '' === $key || ! mlsimport_live_mode_active() ) {
		return $template;
	}

	$record = mlsimport_live_get( $key );
	if ( ! is_array( $record ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		return get_404_template();
	}

	mlsimport_live_current_record( $record );
	return MLSIMPORT_PLUGIN_PATH . 'templates/live/single-mlsimport-property-live.php';
}

/**
 * Seam #2 callback: answer mlsimport_property_data( 0 ) with the live VM while
 * a live record is being rendered. Real post IDs keep the stored behavior.
 *
 * @param array|null $pre Short-circuit value (null = build normally).
 * @param int        $id  The requested property post ID.
 * @return array|null
 */
function mlsimport_live_property_data_pre( $pre, int $id ) {
	if ( null !== $pre || 0 !== $id ) {
		return $pre;
	}
	$record = mlsimport_live_current_record();
	if ( null === $record ) {
		return $pre;
	}

	static $vm = null;
	if ( null === $vm ) {
		$vm = mlsimport_live_property_vm( $record );
	}
	return $vm;
}

/**
 * Build the property view model from a raw RESO record — the same keys
 * mlsimport_property_data() assembles from the post + flat row, built with
 * the same helpers, so every section renders unchanged. id is 0 (no post);
 * media is URL-based (gallery_urls / image_url from Media[]).
 *
 * @param array $record Raw RESO property record.
 * @return array
 */
function mlsimport_live_property_vm( array $record ): array {
	$row = mlsimport_live_row_from_reso( $record );

	// Same reader contracts the stored assembly uses: $meta by RESO field name
	// (stored meta keys are mlsimport_<Field>, so names line up 1:1), $num/$str
	// from the flat-row columns.
	$meta = static function ( $key ) use ( $record ) {
		return isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ? (string) $record[ $key ] : '';
	};
	$num  = static function ( $col ) use ( $row ) {
		return isset( $row->$col ) && null !== $row->$col && '' !== $row->$col ? (float) $row->$col : null;
	};
	$str  = static function ( $col ) use ( $row ) {
		return isset( $row->$col ) ? (string) $row->$col : '';
	};

	$gallery_urls = mlsimport_live_media_urls( $record );
	$address      = mlsimport_property_build_address( $meta, $str );

	$vm = array(
		'id'                => 0,
		'title'             => $address,
		'permalink'         => mlsimport_live_url( (string) $row->listing_key ),
		'content'           => $meta( 'PublicRemarks' ),
		'excerpt'           => '',

		// Price + variants.
		'price'             => $num( 'price' ),
		'price_per_sqft'    => ( null !== $num( 'price' ) && $num( 'living_area' ) ) ? (int) round( $num( 'price' ) / $num( 'living_area' ) ) : null,
		'original_price'    => '' !== $meta( 'OriginalListPrice' ) ? (float) $meta( 'OriginalListPrice' ) : null,
		'close_price'       => '' !== $meta( 'ClosePrice' ) ? (float) $meta( 'ClosePrice' ) : null,
		'previous_price'    => '' !== $meta( 'PreviousListPrice' ) ? (float) $meta( 'PreviousListPrice' ) : null,
		'hoa_fee'           => '' !== $meta( 'AssociationFee' ) ? (float) $meta( 'AssociationFee' ) : null,
		'hoa_frequency'     => $meta( 'AssociationFeeFrequency' ),

		// Structure / facts.
		'bedrooms'          => $num( 'bedrooms' ),
		'bathrooms'         => $num( 'bathrooms' ),
		'living_area'       => $num( 'living_area' ),
		'lot_size'          => $num( 'lot_size' ),
		'year_built'        => null !== $num( 'year_built' ) ? (int) $num( 'year_built' ) : null,
		'garage'            => '' !== $meta( 'GarageSpaces' ) ? (int) (float) $meta( 'GarageSpaces' ) : null,
		'stories'           => '' !== $meta( 'StoriesTotal' ) ? (int) (float) $meta( 'StoriesTotal' ) : null,
		'days_on_market'    => null !== $num( 'days_on_market' ) ? (int) $num( 'days_on_market' ) : null,

		// Location.
		'street'            => mlsimport_property_street_line( $meta ),
		'city'              => $str( 'city' ),
		'state'             => $str( 'state' ),
		'zip'               => $str( 'zip' ),
		'subdivision'       => $str( 'subdivision' ),
		'county'            => $meta( 'CountyOrParish' ),
		'country'           => 'US' === $meta( 'Country' ) ? __( 'United States', 'mlsimport' ) : $meta( 'Country' ),
		'latitude'          => '' !== $meta( 'Latitude' ) ? (float) $meta( 'Latitude' ) : null,
		'longitude'         => '' !== $meta( 'Longitude' ) ? (float) $meta( 'Longitude' ) : null,
		'address'           => $address,

		// Type / status.
		'property_type'     => $str( 'property_type' ),
		'property_sub_type' => $meta( 'PropertySubType' ),
		'listing_type'      => $str( 'listing_type' ),
		'status'            => '' !== $str( 'status' ) ? $str( 'status' ) : $meta( 'MlsStatus' ),

		// Provenance / freshness (display-only).
		'mls_id'            => '' !== $meta( 'ListingId' ) ? $meta( 'ListingId' ) : $meta( 'ListingKey' ),
		'updated'           => mlsimport_property_format_date( $meta( 'ModificationTimestamp' ) ),

		// Media: CDN URLs, no attachments.
		'thumbnail_id'      => 0,
		'image_url'         => array() === $gallery_urls ? '' : $gallery_urls[0],
		'gallery_ids'       => array(),
		'gallery_urls'      => $gallery_urls,
		'virtual_tour'      => $meta( 'VirtualTourURLUnbranded' ),
		'video_url'         => $meta( 'VideoURL' ),

		// Features: no amenity terms without a post.
		'features'          => array(),

		// Agent from the record's ListAgent*/ListOffice* fields (id 0 = no linked post).
		'agent'             => mlsimport_property_agent( 0, $meta ),

		// Live extras: the key for lead/context use + the raw record.
		'listing_key'       => (string) $row->listing_key,
	);

	/** Filter the property view model — same seam the stored VM passes. @since 6.3 */
	return (array) apply_filters( 'mlsimport_property_data', $vm, 0 );
}

add_action( 'init', 'mlsimport_live_register_route' );
add_filter( 'query_vars', 'mlsimport_live_query_vars' );
add_filter( 'template_include', 'mlsimport_live_template_include', 20 );
add_filter( 'mlsimport_property_data_pre', 'mlsimport_live_property_data_pre', 10, 2 );
