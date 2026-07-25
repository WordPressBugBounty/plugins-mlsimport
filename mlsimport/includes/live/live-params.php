<?php
/**
 * Live mode: translate standalone filter params into a provider OData query.
 *
 * Pure functions — no WordPress, no DB, no network — so the translation is
 * unit-testable per provider family. Input vocabulary is the standalone
 * shortcode/block filter params (FILTER_ATTS); output is the query string
 * appended to the MLS base URL. Ported from the AWS get-listings recipe
 * (url_builders.py), which stays the reference for provider quirks.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the query string for a live listings search, in the configured
 * provider's dialect.
 *
 * Bridge speaks its native listings API (verified live vs Stellar 2026-07-03:
 * OData hides Media for client tokens; the native API returns it inline).
 * Every other Family 1 provider speaks generic RESO OData.
 *
 * @param array $params Standalone filter params (city, status, price_min, …).
 * @param array $config Per-MLS config (type, expand, field_corellation, …).
 * @return string Query string beginning with '?'.
 */
function mlsimport_live_build_query( array $params, array $config ): string {
	// Dispatch on provider type: Bridge speaks its native API, all others OData.
	$type = isset( $config['type'] ) ? strtolower( (string) $config['type'] ) : 'bridge';
	if ( 'bridge' === $type ) {
		return mlsimport_live_build_query_bridge( $params, $config );
	}
	return mlsimport_live_build_query_odata( $params, $config );
}

/**
 * The shared translation vocabularies: standalone param => RESO field.
 * Note the deliberate cross-map: the standalone property_type column holds
 * RESO PropertySubType values and listing_type holds RESO PropertyType
 * values (see the standalone reso-map).
 *
 * @return array{lists:array,numeric:array,sortable:array}
 */
function mlsimport_live_param_vocabulary(): array {
	return array(
		'lists'    => array(
			'city'          => 'City',
			'county'        => 'CountyOrParish',
			'state'         => 'StateOrProvince',
			'zip'           => 'PostalCode',
			'property_type' => 'PropertySubType',
			'listing_type'  => 'PropertyType',
		),
		'numeric'  => array(
			'price_min'  => array( 'ListPrice', 'ge' ),
			'price_max'  => array( 'ListPrice', 'le' ),
			'beds'       => array( 'BedroomsTotal', 'ge' ),
			'baths'      => array( 'BathroomsTotalDecimal', 'ge' ),
			'sqft_min'   => array( 'LivingArea', 'ge' ),
			'sqft_max'   => array( 'LivingArea', 'le' ),
			'lot_min'    => array( 'LotSizeSquareFeet', 'ge' ),
			'lot_max'    => array( 'LotSizeSquareFeet', 'le' ),
			'year_min'   => array( 'YearBuilt', 'ge' ),
			'year_max'   => array( 'YearBuilt', 'le' ),
			'hoa_max'    => array( 'AssociationFee', 'le' ),
			'dom_max'    => array( 'DaysOnMarket', 'le' ),
			'garage_min' => array( 'GarageSpaces', 'ge' ),
			'stories'    => array( 'StoriesTotal', 'ge' ),
		),
		'sortable' => array(
			'price'                  => 'ListPrice',
			'bedrooms'               => 'BedroomsTotal',
			'bathrooms'              => 'BathroomsTotalDecimal',
			'living_area'            => 'LivingArea',
			'lot_size'               => 'LotSizeSquareFeet',
			'year_built'             => 'YearBuilt',
			'list_date'              => 'ListingContractDate',
			'days_on_market'         => 'DaysOnMarket',
			'modification_timestamp' => 'ModificationTimestamp',
		),
	);
}

/**
 * Bridge native listings API dialect: Field.in= for lists, Field.gte/.lte
 * for ranges, sortBy+order, limit/offset, box=lng,lat,lng,lat. Media comes
 * inline — no expand parameter exists or is needed.
 *
 * @param array $params Standalone filter params.
 * @param array $config Per-MLS config.
 * @return string Query string beginning with '?'.
 */
function mlsimport_live_build_query_bridge( array $params, array $config ): string {
	// $alias maps each canonical RESO field to the provider's own field name.
	$vocab = mlsimport_live_param_vocabulary();
	$alias = static function ( string $field ) use ( $config ): string {
		return mlsimport_live_field_alias( $field, $config );
	};

	// Each query fragment is collected here and &-joined at the end.
	$pairs = array();

	// List-valued filters -> Field.in=a,b,c (any value matches).
	foreach ( $vocab['lists'] as $param => $field ) {
		if ( ! empty( $params[ $param ] ) ) {
			// Coerce to a trimmed, non-empty string list.
			$values = array_filter( array_map( 'trim', array_map( 'strval', (array) $params[ $param ] ) ), 'strlen' );
			if ( array() !== $values ) {
				// Canonical order: any-match lists mean the same query in any
				// order, and one spelling means one cache entry.
				sort( $values );
				$pairs[] = $alias( $field ) . '.in=' . implode( ',', array_map( 'rawurlencode', $values ) );
			}
		}
	}

	// An explicit ListingKey set IS the filter — no status baseline, or a
	// hand-picked Pending/Closed listing would silently vanish. Sorted for
	// the cache; callers re-apply their input order after the fetch.
	if ( ! empty( $params['keys'] ) && is_array( $params['keys'] ) ) {
		// Explicit key set: filter on ListingKey and add no status baseline.
		$keys = array_filter( array_map( 'trim', array_map( 'strval', $params['keys'] ) ), 'strlen' );
		sort( $keys );
		$pairs[] = $alias( 'ListingKey' ) . '.in=' . implode( ',', array_map( 'rawurlencode', $keys ) );
	} else {
		// Baseline: a search without an explicit status only shows Active listings.
		$status = ! empty( $params['status'] ) ? (array) $params['status'] : array( 'Active' );
		$status = array_filter( array_map( 'trim', array_map( 'strval', $status ) ), 'strlen' );
		sort( $status );
		$pairs[] = $alias( 'StandardStatus' ) . '.in=' . implode( ',', array_map( 'rawurlencode', $status ) );
	}

	// Single-value subdivision as an equality match.
	if ( ! empty( $params['subdivision'] ) && ! is_array( $params['subdivision'] ) ) {
		$pairs[] = $alias( 'SubdivisionName' ) . '=' . rawurlencode( (string) $params['subdivision'] );
	}

	// Minimum list date, only when it's a valid YYYY-MM-DD.
	if ( ! empty( $params['list_date_min'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $params['list_date_min'] ) ) {
		$pairs[] = $alias( 'ListingContractDate' ) . '.gte=' . $params['list_date_min'];
	}

	// Numeric ranges -> Field.gte/.lte, one pair per set numeric param.
	foreach ( $vocab['numeric'] as $param => $rule ) {
		if ( isset( $params[ $param ] ) && '' !== $params[ $param ] && is_numeric( $params[ $param ] ) ) {
			// rule[1] is the comparator direction (ge->gte, le->lte).
			$suffix  = 'ge' === $rule[1] ? 'gte' : 'lte';
			$pairs[] = $alias( $rule[0] ) . '.' . $suffix . '=' . ( 0 + $params[ $param ] );
		}
	}

	// Map viewport: box takes lng,lat corner pairs (verified order).
	if ( isset( $params['lat_min'], $params['lat_max'], $params['lng_min'], $params['lng_max'] ) ) {
		$pairs[] = 'box=' . ( 0 + $params['lng_min'] ) . ',' . ( 0 + $params['lat_min'] )
			. ',' . ( 0 + $params['lng_max'] ) . ',' . ( 0 + $params['lat_max'] );
	}

	// Sorting: whitelisted columns via sortBy/order; ListingKey keeps paging stable.
	$orderby = isset( $params['orderby'] ) ? (string) $params['orderby'] : '';
	if ( isset( $vocab['sortable'][ $orderby ] ) ) {
		// Known sort column: emit sortBy + direction (default desc).
		$order   = isset( $params['order'] ) && 'ASC' === strtoupper( (string) $params['order'] ) ? 'asc' : 'desc';
		$pairs[] = 'sortBy=' . $vocab['sortable'][ $orderby ];
		$pairs[] = 'order=' . $order;
	} else {
		// Unknown/no sort: fall back to the stable ListingKey order.
		$pairs[] = 'sortBy=ListingKey';
	}

	// Bridge's native API rejects limit > 200 with HTTP 400 (verified vs
	// Stellar 2026-07-03).
	$limit = isset( $params['limit'] ) ? max( 1, (int) $params['limit'] ) : 0;
	$limit = min( $limit, 200 );
	if ( $limit > 0 ) {
		// limit + offset paging (offset only past page 1).
		$pairs[] = 'limit=' . $limit;
		$page    = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		if ( $page > 1 ) {
			$pairs[] = 'offset=' . ( ( $page - 1 ) * $limit );
		}
	}

	// Join every fragment into the final ?a&b&c query string.
	return '?' . implode( '&', $pairs );
}

/**
 * Generic RESO OData dialect ($filter/$orderby/$top/$skip/$count/$expand).
 *
 * @param array $params Standalone filter params (city, status, price_min, …).
 * @param array $config Per-MLS config (type, expand, field_corellation, …).
 * @return string Query string beginning with '?'.
 */
function mlsimport_live_build_query_odata( array $params, array $config ): string {
	// $filter accumulates the OData $filter clauses (each ends ' and ');
	// $alias resolves canonical RESO names to the provider's own names.
	$type   = isset( $config['type'] ) ? strtolower( (string) $config['type'] ) : '';
	$filter = '';
	$alias  = static function ( string $field ) use ( $config ): string {
		return mlsimport_live_field_alias( $field, $config );
	};

	// List-valued params (any value matches). Note the deliberate cross-map:
	// the standalone property_type column holds RESO PropertySubType values and
	// listing_type holds RESO PropertyType values (see the standalone reso-map).
	$vocab = mlsimport_live_param_vocabulary();
	foreach ( $vocab['lists'] as $param => $field ) {
		if ( 'rapattoni' === $type && 'listing_type' === $param ) {
			// Rapattoni takes the class as a plain Class= parameter, not a
			// PropertyType $filter (same rule as the AWS URL builder).
			continue;
		}
		if ( ! empty( $params[ $param ] ) ) {
			$filter .= mlsimport_live_filter_list_segment( $alias( $field ), (array) $params[ $param ] );
		}
	}

	if ( ! empty( $params['subdivision'] ) && ! is_array( $params['subdivision'] ) ) {
		$filter .= mlsimport_live_filter_list_segment( $alias( 'SubdivisionName' ), array( (string) $params['subdivision'] ) );
	}

	if ( ! empty( $params['list_date_min'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $params['list_date_min'] ) ) {
		$filter .= '(' . $alias( 'ListingContractDate' ) . ' ge ' . $params['list_date_min'] . ') and ';
	}

	// Numeric ranges + the bbox coordinate ranges (OData pushes them as plain
	// Latitude/Longitude comparisons; the bridge dialect uses box= instead).
	$numeric = array_merge(
		$vocab['numeric'],
		array(
			'lat_min' => array( 'Latitude', 'ge' ),
			'lat_max' => array( 'Latitude', 'le' ),
			'lng_min' => array( 'Longitude', 'ge' ),
			'lng_max' => array( 'Longitude', 'le' ),
		)
	);
	foreach ( $numeric as $param => $rule ) {
		if ( isset( $params[ $param ] ) && '' !== $params[ $param ] && is_numeric( $params[ $param ] ) ) {
			$filter .= '(' . $alias( $rule[0] ) . ' ' . $rule[1] . ' ' . ( 0 + $params[ $param ] ) . ') and ';
		}
	}

	// An explicit ListingKey set IS the filter — no status baseline, or a
	// hand-picked Pending/Closed listing would silently vanish.
	if ( ! empty( $params['keys'] ) && is_array( $params['keys'] ) ) {
		$filter .= mlsimport_live_filter_list_segment( $alias( 'ListingKey' ), $params['keys'] );
	} else {
		// Baseline: a search without an explicit status only shows Active listings
		// (mirrors the Import Task default).
		$status  = ! empty( $params['status'] ) ? (array) $params['status'] : array( 'Active' );
		$filter .= mlsimport_live_filter_list_segment( $alias( 'StandardStatus' ), $status );
	}

	// Drop the trailing ' and ' left by the last appended clause.
	$filter = preg_replace( '/ and $/', '', $filter );

	// Assemble the query string, starting with provider-specific flags.
	$query = '?';
	if ( 'trestle' === $type ) {
		// Trestle serves spaced enum labels with PrettyEnums=true — the same
		// shape the saved enums (and so the search dropdowns) use, so filters
		// must speak it too.
		$query .= '&PrettyEnums=true';
	}
	if ( 'rapattoni' === $type && ! empty( $params['listing_type'] ) ) {
		$query .= '&Class=' . rawurlencode( (string) current( (array) $params['listing_type'] ) );
	}
	if ( 'brightmls' === $type ) {
		// BrightMLS rejects $expand=Media — media comes from the separate
		// BrightMedia endpoint (same rule as the AWS URL builder).
		$query .= '&$format=json';
	} elseif ( ! empty( $config['expand'] ) ) {
		$query .= '&$expand=' . $config['expand'];
	}

	// Rapattoni has no ListingKey column to order by — its stable fallback is
	// ListingKeyNumeric (same rule as the AWS URL builder).
	$orderby = mlsimport_live_orderby( $params );
	if ( 'rapattoni' === $type && 'ListingKey' === $orderby ) {
		$orderby = 'ListingKeyNumeric';
	}
	// Always request $orderby + $count (the total drives paging/clustering).
	$query .= '&$orderby=' . $orderby;
	$query .= '&$count=true';

	// Page size ($top): 0 means "unset" until the provider rules below apply.
	$limit = isset( $params['limit'] ) ? max( 1, (int) $params['limit'] ) : 0;
	if ( $limit <= 0 && in_array( $type, array( 'realcomp', 'brightmls' ), true ) ) {
		// Realcomp requires an explicit page size; BrightMLS's ~7M-row feed
		// times out without one. Both default to the AWS builder's 25.
		$limit = 25;
	}
	if ( 'rmls' === $type ) {
		// RMLS hard-caps $top at 25.
		$limit = $limit > 0 ? min( $limit, 25 ) : 25;
	}
	if ( $limit > 0 ) {
		// $top + $skip paging (skip only past page 1).
		$query .= '&$top=' . $limit;
		$page   = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		if ( $page > 1 ) {
			$query .= '&$skip=' . ( ( $page - 1 ) * $limit );
		}
	}

	// Append the built $filter (wrapped) only when there is one.
	if ( '' !== $filter ) {
		$query .= '&$filter=(' . $filter . ')';
	}

	return $query;
}

/**
 * The $orderby expression: the standalone SORTABLE columns mapped to their
 * RESO fields (same mapping the reso-map uses for the flat table), with
 * ListingKey as the stable fallback so paging order is always deterministic.
 *
 * @param array $params Standalone filter params (orderby, order).
 * @return string e.g. 'ListPrice desc' or 'ListingKey'.
 */
function mlsimport_live_orderby( array $params ): string {
	$sortable = mlsimport_live_param_vocabulary()['sortable'];

	// Unknown/absent sort column: the stable ListingKey fallback.
	$orderby = isset( $params['orderby'] ) ? (string) $params['orderby'] : '';
	if ( ! isset( $sortable[ $orderby ] ) ) {
		return 'ListingKey';
	}

	// Known column: its RESO field + direction (default desc).
	$order = isset( $params['order'] ) && 'ASC' === strtoupper( (string) $params['order'] ) ? 'asc' : 'desc';
	return $sortable[ $orderby ] . ' ' . $order;
}

/**
 * Resolve a RESO field name to the provider's own name via field_corellation
 * (the per-MLS alias map from the mld_details config; misspelling canonical).
 *
 * @param string $field  Canonical RESO field name.
 * @param array  $config Per-MLS config; field_corellation is a JSON string.
 * @return string Provider field name (unchanged when no alias applies).
 */
function mlsimport_live_field_alias( string $field, array $config ): string {
	// No alias map configured: the field name passes through unchanged.
	if ( empty( $config['field_corellation'] ) ) {
		return $field;
	}
	// Memoize the decoded map on its raw JSON so repeated calls parse once.
	static $memo_raw = null;
	static $memo_map = array();

	$raw = (string) $config['field_corellation'];
	if ( $raw !== $memo_raw ) {
		$decoded  = json_decode( $raw, true );
		$memo_raw = $raw;
		$memo_map = is_array( $decoded ) ? $decoded : array();
	}
	$map = $memo_map;
	// Use the alias only when it's a real non-empty string; else the original.
	if ( is_array( $map ) && isset( $map[ $field ] ) && is_string( $map[ $field ] ) && '' !== $map[ $field ] ) {
		return $map[ $field ];
	}
	return $field;
}

/**
 * The BrightMedia query for one chunk of listing keys. BrightMLS serves media
 * from a separate endpoint (api_media_url); keys go in unquoted, ordered by
 * record + display order — the same request the AWS media fetch builds.
 *
 * @param string[] $keys ListingKeys for this chunk (max 100 per request).
 * @return string Query string beginning with '?'.
 */
function mlsimport_live_brightmls_media_query( array $keys ): string {
	// The media columns the import needs from BrightMedia.
	$select = 'MediaKey,ResourceRecordKey,MediaCategory,MediaType,'
		. 'MediaDisplayOrder,PreferredPhotoYN,MediaURL,MediaURLHiRes,'
		. 'MediaModificationTimestamp';

	// Filter to this key chunk, ordered by record then display order (unquoted
	// keys, per the AWS media fetch).
	return '?$filter=ResourceRecordKey in (' . implode( ',', array_map( 'strval', $keys ) ) . ')'
		. '&$orderby=ResourceRecordKey,MediaDisplayOrder'
		. '&$select=' . $select
		. '&$format=json';
}

/**
 * Does a viewport map request carry visitor search filters? The v1.1 cluster
 * index is unfiltered by design (active listings, whole MLS), so a filtered
 * map keeps the zoom-in state — its counts must never be wrong.
 *
 * @param array $args Whitelisted filter args (already cleaned of empties).
 * @return bool True when any key beyond the viewport/paging set is present.
 */
function mlsimport_live_map_has_filters( array $args ): bool {
	// Viewport + paging keys carry no visitor filter intent.
	$neutral = array( 'lat_min', 'lat_max', 'lng_min', 'lng_max', 'zoom', 'orderby', 'order', 'limit', 'page' );

	// Any key outside that neutral set means the map is filtered.
	return array() !== array_diff( array_keys( $args ), $neutral );
}

/**
 * Quantize a filter set's viewport bbox OUTWARD to three decimals (~110m —
 * mins floor, maxes ceil, the box only grows) BEFORE the query is built, so
 * nearby map pans produce the identical query and reuse one cache entry
 * instead of firing a fresh MLS request per pixel.
 *
 * @param array $params Filter params, possibly carrying a bbox.
 * @return array The params with any bbox edges quantized.
 */
function mlsimport_live_quantize_bbox( array $params ): array {
	// Mins floor, maxes ceil, so quantizing only ever grows the box.
	$edges = array(
		'lat_min' => 'floor',
		'lat_max' => 'ceil',
		'lng_min' => 'floor',
		'lng_max' => 'ceil',
	);
	// Snap each present, numeric edge to 3 decimals via *1000/round/÷1000.
	foreach ( $edges as $edge => $fn ) {
		if ( isset( $params[ $edge ] ) && is_numeric( $params[ $edge ] ) ) {
			$params[ $edge ] = number_format( $fn( (float) $params[ $edge ] * 1000 ) / 1000, 3, '.', '' );
		}
	}
	return $params;
}

/**
 * The /clusters request params for a viewport. The bbox is quantized OUTWARD
 * to two decimals (~1km — mins floor, maxes ceil), so the box only grows,
 * edge listings are never dropped, and nearby pans share one cached answer.
 *
 * @param array $args   Filter args carrying the viewport bbox.
 * @param int   $zoom   Map zoom level.
 * @param int   $mls_id The site's MLS id.
 * @return array|null Ordered param array, or null without a full viewport.
 */
function mlsimport_live_clusters_params( array $args, int $zoom, int $mls_id ) {
	// A partial viewport (any edge missing/non-numeric) can't be asked of the index.
	foreach ( array( 'lat_min', 'lat_max', 'lng_min', 'lng_max' ) as $edge ) {
		if ( ! isset( $args[ $edge ] ) || ! is_numeric( $args[ $edge ] ) ) {
			return null;
		}
	}

	// mls_id + the bbox quantized OUTWARD to 2dp (~1km) + zoom.
	return array(
		'mls_id'  => $mls_id,
		'lat_min' => number_format( floor( (float) $args['lat_min'] * 100 ) / 100, 2, '.', '' ),
		'lat_max' => number_format( ceil( (float) $args['lat_max'] * 100 ) / 100, 2, '.', '' ),
		'lng_min' => number_format( floor( (float) $args['lng_min'] * 100 ) / 100, 2, '.', '' ),
		'lng_max' => number_format( ceil( (float) $args['lng_max'] * 100 ) / 100, 2, '.', '' ),
		'zoom'    => $zoom,
	);
}

/**
 * One list-valued $filter segment: (Field eq 'A' or Field eq 'B') and .
 *
 * @param string $field  RESO/OData field name.
 * @param array  $values Accepted values (any match).
 * @return string Segment ending in ' and ', or '' when no usable values.
 */
function mlsimport_live_filter_list_segment( string $field, array $values ): string {
	// Trim to non-empty string values.
	$clean = array();
	foreach ( $values as $value ) {
		$value = trim( (string) $value );
		if ( '' !== $value ) {
			$clean[] = $value;
		}
	}
	// No usable values: no segment.
	if ( array() === $clean ) {
		return '';
	}

	// Canonical order: an any-match list means the same query in any order,
	// and one spelling means one cache entry.
	sort( $clean );

	// One `Field eq 'value'` clause each (single quotes doubled to escape).
	$clauses = array();
	foreach ( $clean as $value ) {
		$clauses[] = $field . " eq '" . str_replace( "'", "''", $value ) . "'";
	}
	// OR the clauses together and terminate with ' and ' for concatenation.
	return '(' . implode( ' or ', $clauses ) . ') and ';
}
