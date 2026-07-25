<?php
/**
 * Live mode: the viewport map payload, answered from the MLS.
 *
 * One bbox search capped at the marker cap: when the in-view total fits, the
 * records become full markers (photo/address/beds/baths — parity with the
 * stored map); when it doesn't, the SaaS /clusters endpoint answers counted
 * bubbles from the per-MLS index the daily sweep maintains (v1.1). The
 * { type: 'zoom_in' } notice remains the fallback — for filtered maps (the
 * cluster index is unfiltered by design) and for any endpoint failure.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answer the viewport map payload from the MLS (seam #3).
 *
 * @param array|null $pre  Prior short-circuit value.
 * @param array      $args Filter args incl. the viewport bbox.
 * @param int        $zoom Map zoom level (cluster grid sizing).
 * @return array|null
 */
function mlsimport_live_map_payload( $pre, $args, $zoom ) {
	// Already answered by an earlier filter, or gate off: don't intervene.
	if ( null !== $pre || ! mlsimport_live_mode_active() ) {
		return $pre;
	}

	/** Filter the cap on individual markers before clustering kicks in. @since 6.5 */
	$cap = max( 1, (int) apply_filters( 'mlsimport_map_marker_cap', Mlsimport_Standalone_Render::MAP_MARKER_CAP ) );

	// The map shows the in-view set, never the grid's page size.
	$args          = (array) $args;
	$args['limit'] = $cap;
	$args['page']  = 1;

	// Cluster-first (v1.1): on an unfiltered viewport the index's daily count
	// already tells us bubbles will render, so the full record fetch is
	// skipped instead of pulled and discarded. Filtered maps and endpoint
	// failures answer null here and fall through to the live search.
	$clusters = mlsimport_live_map_clusters( $args, (int) $zoom );
	// Index already says the area is over the cap: return bubbles, skip the fetch.
	if ( null !== $clusters && (int) $clusters['total'] > $cap ) {
		return $clusters;
	}

	// Otherwise fetch the in-view records themselves.
	$result = mlsimport_live_search( $args );
	if ( ! is_array( $result ) ) {
		// MLS unreachable, no warm cache: an empty viewport, not a fatal.
		return array(
			'type'    => 'markers',
			'total'   => 0,
			'markers' => array(),
		);
	}

	// Over the cap — or more than the provider will return in one page
	// (Bridge caps limit at 200): a partial pin set would silently
	// misrepresent the area. Slightly-stale cluster bubbles beat the zoom-in
	// notice; the notice remains the fallback when the index can't answer.
	// Over the cap, or the provider returned fewer than the true total (a
	// partial page): don't draw a misleading partial pin set.
	$total = (int) $result['total'];
	if ( $total > $cap || count( $result['records'] ) < $total ) {
		// Prefer cluster bubbles when the index can answer; else the zoom-in notice.
		if ( null !== $clusters ) {
			return $clusters;
		}
		return array(
			'type'  => 'zoom_in',
			'total' => $total,
		);
	}

	// Under the cap: turn each record into a full marker (skipping coord-less ones).
	$markers = array();
	foreach ( $result['records'] as $record ) {
		$marker = is_array( $record ) ? mlsimport_live_marker_from_record( $record ) : null;
		if ( null !== $marker ) {
			$markers[] = $marker;
		}
	}

	// Markers + the true total + the set's bounds so the map can fit itself.
	return array(
		'type'    => 'markers',
		'total'   => $total,
		'markers' => $markers,
		'bounds'  => mlsimport_live_bounds_of_markers( $markers ),
	);
}
add_filter( 'mlsimport_map_payload_pre', 'mlsimport_live_map_payload', 10, 3 );

/**
 * Answer a filter set's overall map bounds from the MLS, so the map block can
 * mount and fit itself with no local table.
 *
 * Unfiltered blocks get EXACT bounds from the SaaS coords index — one cached
 * world-bbox /clusters ask, no record fetch. Filtered blocks (the index is
 * unfiltered by design) keep the approximation: the bounds of the first
 * marker-cap's worth of listings, from a cached search.
 *
 * @param array|null $pre    Prior short-circuit value.
 * @param array      $params Filter params (no bbox).
 * @return array|null
 */
function mlsimport_live_map_bounds( $pre, $params ) {
	// Already answered, or gate off: don't intervene.
	if ( null !== $pre || ! mlsimport_live_mode_active() ) {
		return $pre;
	}

	$params = (array) $params;

	// Unfiltered block: get EXACT bounds from the coords index with one
	// world-bbox /clusters ask — no record fetch needed.
	if ( ! mlsimport_live_map_has_filters( $params ) ) {
		$clusters = mlsimport_live_map_clusters(
			array_merge(
				$params,
				array(
					'lat_min' => -90,
					'lat_max' => 90,
					'lng_min' => -180,
					'lng_max' => 180,
				)
			),
			0
		);
		if ( is_array( $clusters ) && isset( $clusters['bounds'] ) ) {
			return $clusters['bounds'];
		}
	}

	/** Filter the cap on individual markers before clustering kicks in. @since 6.5 */
	$cap = max( 1, (int) apply_filters( 'mlsimport_map_marker_cap', Mlsimport_Standalone_Render::MAP_MARKER_CAP ) );

	// Filtered block (or the index couldn't answer): approximate the bounds from
	// the first marker-cap's worth of matching listings.
	$params          = (array) $params;
	$params['limit'] = $cap;
	$params['page']  = 1;

	// Fetch that first page; on failure keep the prior value ($pre).
	$result = mlsimport_live_search( $params );
	if ( ! is_array( $result ) ) {
		return $pre;
	}

	// Turn the records into markers (dropping coord-less ones)...
	$markers = array();
	foreach ( $result['records'] as $record ) {
		$marker = is_array( $record ) ? mlsimport_live_marker_from_record( $record ) : null;
		if ( null !== $marker ) {
			$markers[] = $marker;
		}
	}

	// ...and return their bounding box.
	return mlsimport_live_bounds_of_markers( $markers );
}
add_filter( 'mlsimport_map_bounds_pre', 'mlsimport_live_map_bounds', 10, 2 );

/**
 * Ask the SaaS /clusters endpoint for the viewport's cluster bubbles (v1.1).
 *
 * Only unfiltered maps qualify — the per-MLS index covers the whole active
 * feed, so filtered counts would be wrong. The outcome (bubbles or a 'none'
 * sentinel for any failure) is cached for the live TTL per quantized
 * viewport, so neither map pans nor an undeployed endpoint produce per-pan
 * SaaS traffic.
 *
 * @param array $args Filter args incl. the viewport bbox.
 * @param int   $zoom Map zoom level.
 * @return array|null The { type: clusters } payload, or null (keep zoom_in).
 */
function mlsimport_live_map_clusters( array $args, int $zoom ) {
	// Filtered viewport: the index is unfiltered, so its counts would be wrong.
	if ( mlsimport_live_map_has_filters( $args ) ) {
		return null;
	}

	// The site's MLS id keys the index; build the quantized request params.
	$options = get_option( 'mlsimport_admin_options' );
	$mls_id  = is_array( $options ) && isset( $options['mlsimport_mls_name'] ) ? (int) $options['mlsimport_mls_name'] : 0;
	$params  = mlsimport_live_clusters_params( $args, $zoom, $mls_id );
	// No full viewport or no MLS id: can't ask the index.
	if ( null === $params || $mls_id <= 0 ) {
		return null;
	}

	// Cache the /clusters answer per quantized viewport; a 'none' sentinel is
	// stored for any failure so an undeployed endpoint isn't re-hit per pan.
	$data = mlsimport_live_remember(
		mlsimport_live_cache_key( 'clusters', $params ),
		static function () use ( $params ) {
			$answer  = ThemeImport::globalApiRequestSaas( 'clusters?' . http_build_query( $params ), array(), 'GET' );
			$payload = mlsimport_live_clusters_payload( $answer );
			return null !== $payload ? $payload : array( 'type' => 'none' );
		}
	);

	// Only a real cluster payload counts; the 'none' sentinel maps back to null.
	return is_array( $data ) && 'clusters' === ( $data['type'] ?? '' ) ? $data : null;
}

/**
 * One map marker from a raw RESO record — same keys and formatting as the
 * stored markers_from_data(), with the virtual single URL as the link.
 *
 * @param array $record Raw RESO property.
 * @return array|null Null when the record has no coordinates.
 */
function mlsimport_live_marker_from_record( array $record ) {
	// Build the row so we get parsed coordinates; drop the marker without them.
	$row = mlsimport_live_row_from_reso( $record );
	if ( null === $row->latitude || null === $row->longitude ) {
		return null;
	}

	// Spec formatter — mirrors mlsimport_format_amount(), as the stored
	// marker builder does, so both maps read identically.
	$fmt = static function ( $value ) {
		if ( null === $value || '' === $value ) {
			return '';
		}
		$f = (float) $value;
		return ( (float) (int) $f === $f ) ? number_format( $f ) : number_format( $f, 1 );
	};

	// Marker title: the address, falling back to the listing key.
	$title = isset( $record['UnparsedAddress'] ) ? trim( (string) $record['UnparsedAddress'] ) : '';
	if ( '' === $title ) {
		$title = $row->listing_key;
	}

	// The marker shape the map JS expects (post_id 0 — no real post behind it).
	return array(
		'post_id' => 0,
		'lat'     => (float) $row->latitude,
		'lng'     => (float) $row->longitude,
		'price'   => null !== $row->price ? (float) $row->price : null,
		'title'   => $title,
		'url'     => mlsimport_live_url( $row->listing_key ),
		'image'   => $row->thumb,
		'beds'    => $fmt( isset( $row->bedrooms ) ? $row->bedrooms : null ),
		'baths'   => $fmt( isset( $row->bathrooms ) ? $row->bathrooms : null ),
		// Whole-number ft² (like the listing card + property map popup); $fmt keeps a
		// decimal for non-integers, which would leak a fractional area.
		'area'    => ( isset( $row->living_area ) && null !== $row->living_area && '' !== $row->living_area ) ? number_format_i18n( (float) $row->living_area ) : '',
	);
}

/**
 * The bounding box of a marker set, in the shape the map JS refits to.
 *
 * @param array $markers Markers with lat/lng.
 * @return array|null Null when there are no markers.
 */
function mlsimport_live_bounds_of_markers( array $markers ) {
	// No markers, no box.
	if ( array() === $markers ) {
		return null;
	}
	// Pull the lat/lng columns and take their extremes.
	$lats = array_column( $markers, 'lat' );
	$lngs = array_column( $markers, 'lng' );
	return array(
		'lat_min' => (float) min( $lats ),
		'lat_max' => (float) max( $lats ),
		'lng_min' => (float) min( $lngs ),
		'lng_max' => (float) max( $lngs ),
	);
}
