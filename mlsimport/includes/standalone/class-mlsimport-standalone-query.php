<?php
/**
 * Standalone (theme_id 990) listings WHERE-clause builder.
 *
 * Translates consumer filter params into a parameterized WHERE fragment for the
 * mlsimport_listings table plus the args array for $wpdb->prepare(). Pure: only
 * placeholders touch the SQL, values go in args. No WordPress, no DB.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds parameterized WHERE clauses for the standalone listings query.
 */
class Mlsimport_Standalone_Query {

	/**
	 * Numeric range filters: param => [ column-with-operator placeholder, cast ].
	 * Evaluated in this order, so the produced clause order is deterministic.
	 */
	private const NUMERIC = array(
		'price_min'  => array( 'price >= %d', 'int' ),
		'price_max'  => array( 'price <= %d', 'int' ),
		'beds'       => array( 'bedrooms >= %d', 'int' ),
		'baths'      => array( 'bathrooms >= %f', 'float' ),
		'sqft_min'   => array( 'living_area >= %d', 'int' ),
		'sqft_max'   => array( 'living_area <= %d', 'int' ),
		'lot_min'    => array( 'lot_size >= %d', 'int' ),
		'lot_max'    => array( 'lot_size <= %d', 'int' ),
		'year_min'   => array( 'year_built >= %d', 'int' ),
		'year_max'   => array( 'year_built <= %d', 'int' ),
		'hoa_max'    => array( 'hoa_fee <= %d', 'int' ),
		'dom_max'    => array( 'days_on_market <= %d', 'int' ),
		'garage_min' => array( 'garage_spaces >= %d', 'int' ),
		'stories'    => array( 'stories >= %d', 'int' ),
	);

	/**
	 * Exact-match string filters: param => column. Evaluated after numerics.
	 */
	private const EQUALITY = array(
		'subdivision' => 'subdivision',
	);

	/**
	 * Date lower-bound filters: param => "column >= %s". The value binds as a
	 * string in MySQL DATETIME form (the column's format).
	 */
	private const DATE_MIN = array(
		'list_date_min' => 'list_date >= %s',
	);

	/**
	 * Columns the result set may be sorted by. orderby values outside this set
	 * are ignored (orderby cannot be a bound parameter, so it must be whitelisted).
	 */
	private const SORTABLE = array(
		'price',
		'bedrooms',
		'bathrooms',
		'living_area',
		'lot_size',
		'year_built',
		'list_date',
		'days_on_market',
		'modification_timestamp',
	);

	/**
	 * Friendly sort tokens => (column, direction). This is the vocabulary every
	 * surface speaks: the Design Settings "Order by" option, the results toolbar's
	 * Sort select, and the page blocks' sort control. A column direction is not
	 * expressible as a bare column name, which is why "Price" alone cannot mean
	 * anything useful — the token carries both halves. Raw column names still pass
	 * through order_clause unchanged (SORTABLE guards them), so pre-existing saved
	 * values keep working.
	 */
	public const SORT_TOKENS = array(
		'price_high'    => array( 'price', 'desc' ),
		'price_low'     => array( 'price', 'asc' ),
		'newest'        => array( 'list_date', 'desc' ),
		'oldest'        => array( 'list_date', 'asc' ),
		'newest_edited' => array( 'modification_timestamp', 'desc' ),
		'oldest_edited' => array( 'modification_timestamp', 'asc' ),
		'beds_high'     => array( 'bedrooms', 'desc' ),
		'beds_low'      => array( 'bedrooms', 'asc' ),
		'baths_high'    => array( 'bathrooms', 'desc' ),
		'baths_low'     => array( 'bathrooms', 'asc' ),
	);

	/**
	 * Multi-value IN filters: param => column. A scalar is treated as one value.
	 */
	private const IN_LIST = array(
		'status'        => 'status',
		'property_type' => 'property_type',
		'listing_type'  => 'listing_type',
		'city'          => 'city',
		'state'         => 'state',
		'zip'           => 'zip',
	);

	/**
	 * Build a WHERE fragment + prepare() args from consumer filter params.
	 *
	 * @param array $params Filter params.
	 * @return array{where:string,args:array} Parameterized clause and its args.
	 */
	public static function build_where( array $params ): array {
		// Accumulate one placeholder clause per active filter, with its bound value
		// pushed to $args in lockstep so clause order and arg order always agree.
		$clauses = array();
		$args    = array();

		// Numeric range filters: emit the fixed "column OP %d/%f" clause and bind the
		// value cast to the type the column expects (int or float).
		foreach ( self::NUMERIC as $param => $spec ) {
			// Skip any range bound the consumer did not submit.
			if ( ! isset( $params[ $param ] ) ) {
				continue;
			}
			// $spec is [ clause-with-placeholder, cast ]; the clause SQL is fixed.
			list( $sql, $cast ) = $spec;
			$clauses[]          = $sql;
			// Bind the value coerced to the declared type.
			$args[]             = 'int' === $cast ? (int) $params[ $param ] : (float) $params[ $param ];
		}

		// Exact-match string filters: "column = %s" with the value bound as a string.
		foreach ( self::EQUALITY as $param => $column ) {
			// Skip filters not submitted.
			if ( ! isset( $params[ $param ] ) ) {
				continue;
			}
			$clauses[] = $column . ' = %s';
			$args[]    = (string) $params[ $param ];
		}

		foreach ( self::DATE_MIN as $param => $sql ) {
			$value = isset( $params[ $param ] ) ? trim( (string) $params[ $param ] ) : '';
			// Only a real Y-m-d date may enter a DATETIME comparison. The inspector
			// preset is a free-text box, so it can hold anything; a non-date like 'r'
			// would make MySQL raise "Incorrect DATETIME value" and dump the query on
			// the page. The live path guards identically (includes/live/live-params.php).
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				continue;
			}
			$clauses[] = $sql;
			$args[]    = $value;
		}

		// Multi-value IN filters: "column IN (%s, %s, ...)" with one placeholder per
		// submitted value. A scalar is normalized to a one-element list.
		foreach ( self::IN_LIST as $param => $column ) {
			// Skip filters not submitted.
			if ( ! isset( $params[ $param ] ) ) {
				continue;
			}
			// Coerce a scalar or array of values into a flat, re-indexed list.
			$values = array_values( (array) $params[ $param ] );
			// An empty list contributes nothing.
			if ( empty( $values ) ) {
				continue;
			}
			// Build the IN clause with exactly count($values) %s placeholders.
			$clauses[] = $column . ' IN (' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')';
			// Bind each value (as a string) in the same order as the placeholders.
			foreach ( $values as $value ) {
				$args[] = (string) $value;
			}
		}

		// Full-text keyword search against the FULLTEXT search_text column, only when
		// a non-blank query was supplied. Bound as one %s in BOOLEAN mode.
		if ( isset( $params['keywords'] ) && '' !== trim( (string) $params['keywords'] ) ) {
			$clauses[] = 'MATCH(search_text) AGAINST (%s IN BOOLEAN MODE)';
			$args[]    = (string) $params['keywords'];
		}

		// Bounding-box (viewport) filter: applied only when all four corners are present,
		// so a partial box can't produce a half-open range.
		$bbox = array( 'lat_min', 'lat_max', 'lng_min', 'lng_max' );
		if ( count( array_intersect_key( $params, array_flip( $bbox ) ) ) === count( $bbox ) ) {
			$clauses[] = 'latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f';
			$args[]    = (float) $params['lat_min'];
			$args[]    = (float) $params['lat_max'];
			$args[]    = (float) $params['lng_min'];
			$args[]    = (float) $params['lng_max'];
		}

		// Draw-on-map polygon search: keep only listings whose point falls inside the
		// drawn shape. The polygon's own bounding box (indexed lat/lng range) prunes
		// first, then ST_Contains refines — the canonical two-step geo filter.
		if ( isset( $params['polygon'] ) ) {
			$polygon = self::polygon_clause( (string) $params['polygon'] );
			if ( null !== $polygon ) {
				$clauses[] = $polygon['sql'];
				$args      = array_merge( $args, $polygon['args'] );
			}
		}

		// No active filters — emit the always-true placeholder so callers can always
		// splice "WHERE {where}" without special-casing an empty filter set.
		if ( empty( $clauses ) ) {
			return array(
				'where' => '1=1',
				'args'  => $args,
			);
		}

		// AND every collected clause together into the final fragment.
		return array(
			'where' => implode( ' AND ', $clauses ),
			'args'  => $args,
		);
	}

	/**
	 * Translate a drawn polygon into a parameterized point-in-polygon clause.
	 *
	 * Input is the front-end's compact ring: "lng lat,lng lat,..." (one vertex per
	 * comma group, space-separated). Every coordinate is cast to float, so the WKT
	 * string we assemble can only ever contain numbers — it then binds as a single
	 * %s placeholder, leaving no path for injection. Fewer than three valid vertices
	 * yields null (not a polygon). The ring is auto-closed for ST_GeomFromText.
	 *
	 * @param string $raw Compact "lng lat,lng lat,..." ring from the map.
	 * @return array{sql:string,args:array}|null Clause + args, or null when invalid.
	 */
	private static function polygon_clause( string $raw ): ?array {
		// Parse the compact ring: split on commas into vertices, then each vertex on
		// whitespace into its two coordinates. Only well-formed numeric pairs are kept.
		$points = array();
		foreach ( explode( ',', $raw ) as $pair ) {
			$xy = preg_split( '/\s+/', trim( $pair ) );
			// A valid vertex is exactly two numeric tokens; cast both to float.
			if ( is_array( $xy ) && 2 === count( $xy ) && is_numeric( $xy[0] ) && is_numeric( $xy[1] ) ) {
				$points[] = array( (float) $xy[0], (float) $xy[1] ); // [ lng, lat ].
			}
		}
		// Fewer than three vertices cannot describe a polygon.
		if ( count( $points ) < 3 ) {
			return null;
		}

		// Close the ring (POLYGON requires first vertex == last).
		if ( $points[0] !== end( $points ) ) {
			$points[] = $points[0];
		}

		// Split the vertices into their longitude/latitude columns for the bounding box.
		$lngs = array_column( $points, 0 );
		$lats = array_column( $points, 1 );
		// Assemble the WKT POLYGON literal from the (numeric-only) vertices.
		$wkt  = 'POLYGON((' . implode( ',', array_map(
			static function ( $p ) {
				return $p[0] . ' ' . $p[1];
			},
			$points
		) ) . '))';

		return array(
			'sql'  => 'longitude BETWEEN %f AND %f AND latitude BETWEEN %f AND %f AND ST_Contains(ST_GeomFromText(%s), POINT(longitude, latitude))',
			'args' => array( min( $lngs ), max( $lngs ), min( $lats ), max( $lats ), $wkt ),
		);
	}

	/**
	 * The tiebreaker that makes every ordering a TOTAL one.
	 *
	 * A sort column alone is not a total order: rows that tie on it have no defined
	 * relative order, and MySQL is free to return that tied group differently for each
	 * LIMIT/OFFSET query. Paginating such a sort duplicates and drops rows — the same
	 * listing comes back on page 1 AND page 2 while its tie-partner appears on neither,
	 * so a visitor sees some properties twice and some never at all. post_id is unique,
	 * so appending it makes the order total and every page a real slice.
	 */
	private const TIEBREAK = 'L.post_id DESC';

	/**
	 * Build a safe ordering fragment, always ending in the unique-column tiebreaker so
	 * the result is a TOTAL order and LIMIT/OFFSET paging is stable. orderby must be a
	 * whitelisted column (it cannot be a bound parameter); anything else falls back to
	 * the tiebreaker alone — an unordered paginated grid is non-deterministic in exactly
	 * the same way a tied one is, so "no sort chosen" still needs a defined order.
	 *
	 * @param array $params Filter params (orderby, order).
	 * @return string Ordering fragment (never empty).
	 */
	public static function order_clause( array $params ): string {
		// No sort requested by the visitor/block: fall back to the site's configured
		// default order. This is the single point every surface funnels through, so
		// the setting applies to the archive, taxonomy archives, AJAX repaints and
		// page blocks alike without each one re-reading the option.
		$orderby = isset( $params['orderby'] ) && ! is_array( $params['orderby'] ) ? (string) $params['orderby'] : '';
		if ( '' === $orderby ) {
			$orderby = self::default_sort();
		}

		// A friendly token carries its own direction and wins over any order param;
		// a raw column name still takes its direction from order (default DESC).
		if ( isset( self::SORT_TOKENS[ $orderby ] ) ) {
			list( $orderby, $params['order'] ) = self::SORT_TOKENS[ $orderby ];
		}

		$order = '';
		if ( in_array( $orderby, self::SORTABLE, true ) ) {
			$dir   = isset( $params['order'] ) && 'asc' === strtolower( (string) $params['order'] ) ? 'ASC' : 'DESC';
			$order = $orderby . ' ' . $dir;
		}

		// Advanced hook: a callback may rewrite the ordering, but the result is
		// re-validated against the column whitelist below so SQL stays safe.
		if ( function_exists( 'apply_filters' ) ) {
			/** Filter the ORDER BY fragment (re-validated after). @since 6.3 */
			$order = (string) apply_filters( 'mlsimport_listings_order', $order, $params );
		}

		$order = self::validate_order( $order );

		return '' === $order ? self::TIEBREAK : $order . ', ' . self::TIEBREAK;
	}

	/**
	 * Resolve query sort params to the friendly token used by the public toolbar.
	 *
	 * The query contract intentionally accepts both modern tokens (``newest``)
	 * and legacy block attributes (``orderby=list_date`` + ``order=desc``).
	 * The toolbar select can only submit tokens, so a raw saved pair must be
	 * translated before rendering; otherwise the browser falls back to its empty
	 * Default option and the next AJAX page loses the saved ordering (issue #314).
	 * A raw pair with no equivalent public option returns an empty token rather
	 * than inventing a value the select and request handler do not understand.
	 *
	 * @param array $params Filter params (orderby, order).
	 * @return string Friendly sort token, or '' when no public option represents it.
	 */
	public static function sort_token( array $params ): string {
		$orderby = isset( $params['orderby'] ) && ! is_array( $params['orderby'] ) ? (string) $params['orderby'] : '';
		if ( '' === $orderby ) {
			return self::default_sort();
		}
		if ( isset( self::SORT_TOKENS[ $orderby ] ) ) {
			return $orderby;
		}

		$direction = isset( $params['order'] ) && ! is_array( $params['order'] ) && 'asc' === strtolower( (string) $params['order'] ) ? 'asc' : 'desc';
		foreach ( self::SORT_TOKENS as $token => $sort ) {
			if ( $orderby === $sort[0] && $direction === $sort[1] ) {
				return $token;
			}
		}

		return '';
	}

	/**
	 * The sort token configured in Design Settings → General → "Order by", or ''
	 * when set to Default (no ordering beyond the tiebreaker). Also read by the
	 * results toolbar so the Sort select shows the configured order as selected.
	 *
	 * @return string Sort token, or '' for none.
	 */
	public static function default_sort(): string {
		if ( ! function_exists( 'mlsimport_standalone_option' ) ) {
			return '';
		}
		$token = (string) mlsimport_standalone_option( 'order_by', '' );

		return isset( self::SORT_TOKENS[ $token ] ) ? $token : '';
	}

	/**
	 * Whitelist-validate a "<column> <ASC|DESC>" fragment; anything else yields ''.
	 *
	 * @param string $order Candidate ordering fragment.
	 * @return string
	 */
	private static function validate_order( string $order ): string {
		$order = trim( $order );
		if ( '' === $order ) {
			return '';
		}
		$parts  = preg_split( '/\s+/', $order );
		$column = $parts[0];
		$dir    = isset( $parts[1] ) ? strtoupper( $parts[1] ) : 'DESC';
		if ( count( $parts ) > 2 || ! in_array( $column, self::SORTABLE, true ) || ! in_array( $dir, array( 'ASC', 'DESC' ), true ) ) {
			return '';
		}
		return $column . ' ' . $dir;
	}

	/**
	 * Build a "LIMIT %d OFFSET %d" fragment + args from limit/page. No limit
	 * (limit absent or <= 0) yields an empty fragment (the caller returns all rows).
	 *
	 * @param array $params Filter params (limit, page).
	 * @return array{sql:string,args:array}
	 */
	public static function limit_clause( array $params ): array {
		$limit = isset( $params['limit'] ) ? (int) $params['limit'] : 0;
		if ( function_exists( 'apply_filters' ) ) {
			/** Filter the page size (results per page). @since 6.3 */
			$limit = (int) apply_filters( 'mlsimport_listings_per_page', $limit, $params );
		}
		if ( $limit <= 0 ) {
			return array(
				'sql'  => '',
				'args' => array(),
			);
		}

		$page   = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		$offset = ( $page - 1 ) * $limit;

		return array(
			'sql'  => 'LIMIT %d OFFSET %d',
			'args' => array( $limit, $offset ),
		);
	}
}
