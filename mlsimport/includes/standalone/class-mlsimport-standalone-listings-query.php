<?php
/**
 * Standalone (theme_id 990) listings query executor.
 *
 * Wraps the pure WHERE-builder (Mlsimport_Standalone_Query) with $wpdb->prepare
 * and runs it against the mlsimport_listings flat table, returning full rows plus
 * a total count. Term-join taxonomy filters (features, area, county, label) become
 * correlated wp_term_relationships subqueries — 'or' (carry any chosen term) for
 * locations/labels, 'and' (carry every chosen term, ADR-0004) for features — never
 * tax_query.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-query.php';

/**
 * Executes filtered searches against the standalone listings table.
 */
class Mlsimport_Standalone_Listings_Query {

	/**
	 * The FROM clause every read path shares: the flat table joined to its posts.
	 *
	 * The index table outlives its posts — deleting a property does not reliably
	 * remove its row — so the table alone is NOT authoritative about what exists. It
	 * has to be joined, not trusted. Without the join the LIMIT is spent on rows whose
	 * post is gone: they are selected, then silently vanish when the caller hydrates
	 * through WP_Query, so a slider set to "6" renders 5 and the 6th real listing is
	 * never reached. COUNT(*) overcounts the same way, inflating result totals and
	 * pagination.
	 *
	 * Joining here fixes every read path at once and stays correct however the index
	 * drifts (a manual DB delete, a half-finished import).
	 *
	 * @return string The `FROM ... L INNER JOIN ... P` fragment, alias L for the table.
	 */
	private static function from_live(): string {
		global $wpdb;

		$table = $wpdb->prefix . 'mlsimport_listings';

		// Cross-connection dedupe (issue #282): a flagged duplicate loser
		// carries the 'mlsimport_duplicate_of' meta and must be absent from
		// EVERY front-end surface. Excluding it here hides it from all
		// flat-table read paths (search, sliders, maps, coords) at once.
		return "FROM {$table} L"
			. " INNER JOIN {$wpdb->posts} P"
			. ' ON P.ID = L.post_id'
			. " AND P.post_status = 'publish'"
			. " AND P.post_type = 'mlsimport_property'"
			. " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} DUP"
			. " WHERE DUP.post_id = L.post_id AND DUP.meta_key = 'mlsimport_duplicate_of')";
	}

	/**
	 * Run a filtered search.
	 *
	 * @param array $params Consumer filter params (see Mlsimport_Standalone_Query).
	 * @return array{rows:array,total:int} Matching rows and the total match count.
	 */
	public static function search( array $params ): array {
		global $wpdb;

		/** Filter the consumer query params before building SQL. @since 6.3 */
		$params = (array) apply_filters( 'mlsimport_listings_query_params', $params );

		$from = self::from_live();
		list( $where_sql, $body_args ) = self::full_where( $params );

		// Total = full match count, independent of LIMIT.
		$count_sql = "SELECT COUNT(*) {$from} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( self::prepare( $count_sql, $body_args ) );

		// Page query: body + whitelisted ORDER BY + LIMIT/OFFSET.
		$sql   = "SELECT L.* {$from} WHERE {$where_sql}";
		$order = Mlsimport_Standalone_Query::order_clause( $params );
		if ( '' !== $order ) {
			$sql .= " ORDER BY {$order}";
		}
		$limit = Mlsimport_Standalone_Query::limit_clause( $params );
		if ( '' !== $limit['sql'] ) {
			$sql      .= ' ' . $limit['sql'];
			$body_args = array_merge( $body_args, $limit['args'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( self::prepare( $sql, $body_args ) );

		/** Filter the final result set. @since 6.3 */
		return (array) apply_filters(
			'mlsimport_listings_results',
			array(
				'rows'  => $rows,
				'total' => $total,
			),
			$params
		);
	}

	/**
	 * Lightweight coordinate-only fetch for the viewport map: every matching
	 * listing's post_id + lat/lng (no per-post meta), so the server can count and
	 * grid-cluster thousands of points cheaply, then fetch full card data only for
	 * the lone listing in each single-occupancy cell.
	 *
	 * @param array $params Consumer filter params (incl. the lat/lng bbox).
	 * @return array<int,object> Rows with ->post_id, ->lat and ->lng.
	 */
	public static function coords( array $params ): array {
		global $wpdb;

		/** Filter the consumer query params before building SQL. @since 6.3 */
		$params = (array) apply_filters( 'mlsimport_listings_query_params', $params );

		$from = self::from_live();
		list( $where_sql, $body_args ) = self::full_where( $params );

		$sql = "SELECT L.post_id, L.latitude AS lat, L.longitude AS lng {$from} WHERE {$where_sql} AND L.latitude IS NOT NULL AND L.longitude IS NOT NULL";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( self::prepare( $sql, $body_args ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Overall coordinate bounds for a filter set (no bbox) — the page block embeds
	 * this so the map can fit to all matching listings on first paint, then fetch
	 * markers for whatever viewport that produces.
	 *
	 * @param array $params Consumer filter params.
	 * @return array{lat_min:float,lat_max:float,lng_min:float,lng_max:float}|null Null when no matches have coordinates.
	 */
	public static function bounds( array $params ): ?array {
		global $wpdb;

		/** Short-circuit the overall bounds (live mode answers from the MLS here). @since 6.4 */
		$pre = apply_filters( 'mlsimport_map_bounds_pre', null, $params );
		if ( is_array( $pre ) ) {
			return $pre;
		}

		/** Filter the consumer query params before building SQL. @since 6.3 */
		$params = (array) apply_filters( 'mlsimport_listings_query_params', $params );

		$from = self::from_live();
		list( $where_sql, $body_args ) = self::full_where( $params );

		$sql = "SELECT MIN(L.latitude) AS lat_min, MAX(L.latitude) AS lat_max, MIN(L.longitude) AS lng_min, MAX(L.longitude) AS lng_max {$from} WHERE {$where_sql} AND L.latitude IS NOT NULL AND L.longitude IS NOT NULL";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( self::prepare( $sql, $body_args ) );

		if ( ! $row || null === $row->lat_min ) {
			return null;
		}

		return array(
			'lat_min' => (float) $row->lat_min,
			'lat_max' => (float) $row->lat_max,
			'lng_min' => (float) $row->lng_min,
			'lng_max' => (float) $row->lng_max,
		);
	}

	/**
	 * Build the placeholder-safe WHERE fragment shared by search/coords/bounds:
	 * the column WHERE plus the correlated term-join subqueries. Params must be
	 * pre-filtered through mlsimport_listings_query_params by the caller.
	 *
	 * @param array $params Filter params.
	 * @return array{0:string,1:array} [ where_sql, bind_args ].
	 */
	private static function full_where( array $params ): array {
		global $wpdb;

		$where = Mlsimport_Standalone_Query::build_where( $params );

		// Advanced escape hatch: callbacks may rewrite the WHERE fragment but MUST
		// preserve the { where:string, args:array } placeholder-safe shape — values
		// still bind via $wpdb->prepare() at the call site. Malformed shapes are ignored.
		$filtered_where = apply_filters( 'mlsimport_listings_query_where', $where, $params );
		if ( is_array( $filtered_where ) && isset( $filtered_where['where'] ) && is_string( $filtered_where['where'] ) && isset( $filtered_where['args'] ) && is_array( $filtered_where['args'] ) ) {
			$where = $filtered_where;
		}

		// The listings table is always aliased L so the term subqueries can
		// correlate on L.post_id. Column clauses use unqualified names.
		$where_sql = $where['where'];
		$body_args = $where['args'];

		// Term-join taxonomy filters as correlated subqueries, AND-ed onto the WHERE.
		// Safe by construction: $table / $wpdb->term_relationships are fixed names,
		// every ttid binds as %d, and the AND-mode match count is an int literal.
		foreach ( self::resolve_term_groups( $params ) as $group ) {
			if ( empty( $group['ttids'] ) ) {
				continue;
			}
			$ph = implode( ', ', array_fill( 0, count( $group['ttids'] ), '%d' ) );
			if ( 'and' === $group['mode'] ) {
				// Must carry every chosen term (amenities — ADR-0004).
				$where_sql .= " AND (SELECT COUNT(DISTINCT tr.term_taxonomy_id) FROM {$wpdb->term_relationships} tr WHERE tr.object_id = L.post_id AND tr.term_taxonomy_id IN ({$ph})) = " . count( $group['ttids'] );
			} else {
				// Must carry any chosen term (locations, labels).
				$where_sql .= " AND L.post_id IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id IN ({$ph}))";
			}
			$body_args = array_merge( $body_args, $group['ttids'] );
		}

		// Combined "Location" filter: ONE typed value matched against every place a
		// listing can be. city/zip are fast columns; area/county have no column, so
		// they resolve to term_taxonomy_ids and match through the same correlated
		// subquery the term-join filters use; a street address rides the FULLTEXT
		// column, which already indexes UnparsedAddress (Standalone_Reindex).
		//
		// The arms are OR-ed, not AND-ed: the visitor said "somewhere called this",
		// and a value that is a city is never also a county. It lives here rather than
		// in the pure build_where() because the area/county arm needs $wpdb, and an OR
		// group split across two layers would silently become an AND.
		$location = isset( $params['location'] ) ? trim( (string) $params['location'] ) : '';
		if ( '' !== $location ) {
			// Bound as a quoted phrase so BOOLEAN MODE reads the whole address as one
			// literal — unquoted, a '-' in "123 Smith-Marsh Rd" would mean "exclude".
			$arms   = array( 'L.city = %s', 'L.zip = %s', 'MATCH(L.search_text) AGAINST (%s IN BOOLEAN MODE)' );
			$values = array( $location, $location, '"' . str_replace( '"', '', $location ) . '"' );

			$ttids = self::location_ttids( $location );
			if ( $ttids ) {
				$ph     = implode( ', ', array_fill( 0, count( $ttids ), '%d' ) );
				$arms[] = "L.post_id IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id IN ({$ph}))";
				$values = array_merge( $values, $ttids );
			}

			$where_sql .= ' AND (' . implode( ' OR ', $arms ) . ')';
			$body_args  = array_merge( $body_args, $values );
		}

		// Agent filter: keep only the listings linked to that agent post. The link is
		// the mlsimport_list_agent_id meta the importer writes (the same one the agent
		// profile reads), so it is a postmeta subquery, not a table column. Safe by
		// construction: the meta key is a fixed literal and the agent id binds as %d.
		$agent = isset( $params['agent'] ) ? (int) $params['agent'] : 0;
		if ( $agent > 0 ) {
			$where_sql .= " AND L.post_id IN (SELECT pm.post_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = 'mlsimport_list_agent_id' AND pm.meta_value = %d)";
			$body_args[] = $agent;
		}

		return array( $where_sql, $body_args );
	}

	/**
	 * Prepare a SQL string when it carries args, else return it unchanged.
	 *
	 * @param string $sql  SQL with %d/%f/%s placeholders only.
	 * @param array  $args Bind values.
	 * @return string
	 */
	private static function prepare( string $sql, array $args ): string {
		global $wpdb;
		if ( empty( $args ) ) {
			return $sql;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->prepare( $sql, $args );
	}

	/**
	 * The term_taxonomy_ids of every area/county term whose NAME is exactly the
	 * submitted location — the two location taxonomies with no fast column. Exact,
	 * not LIKE: the autocomplete hands back a term name verbatim, and a substring
	 * match would pull "Naples" into "East Naples". No match = no arm.
	 *
	 * @param string $value Submitted location.
	 * @return int[]
	 */
	private static function location_ttids( string $value ): array {
		if ( ! class_exists( 'Mlsimport_Page_Block_Search_Fields' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => Mlsimport_Page_Block_Search_Fields::location_taxonomies(),
				'hide_empty' => false,
				'name'       => $value,
				'fields'     => 'tt_ids',
			)
		);
		return is_wp_error( $terms ) ? array() : array_map( 'intval', (array) $terms );
	}

	/**
	 * Resolve each submitted term-join taxonomy filter (features, area, county,
	 * label) into a group: its term_taxonomy_ids plus the join mode ('and'/'or')
	 * the WHERE applies. Unknown slugs are dropped; groups with no submitted value
	 * are skipped. The param => { tax, join } set comes from the search-field
	 * catalog so the query stays in sync with the form.
	 *
	 * @param array $params Filter params.
	 * @return array<int,array{ttids:int[],mode:string}>
	 */
	private static function resolve_term_groups( array $params ): array {
		$map = class_exists( 'Mlsimport_Page_Block_Search_Fields' )
			? Mlsimport_Page_Block_Search_Fields::term_join_params()
			: array( 'features' => array( 'tax' => 'mlsimport_feature', 'join' => 'and' ) );

		// Resolve each term-join param into a { ttids, mode } group.
		$groups = array();
		foreach ( $map as $param => $info ) {
			// Skip taxonomies the consumer didn't filter on.
			if ( empty( $params[ $param ] ) ) {
				continue;
			}
			// Map each submitted slug to its term_taxonomy_id (unknown slugs drop out).
			$ttids = array();
			foreach ( (array) $params[ $param ] as $slug ) {
				$term = get_term_by( 'slug', (string) $slug, $info['tax'] );
				if ( $term ) {
					$ttids[] = (int) $term->term_taxonomy_id;
				}
			}
			// Features are the one AND-matched group; let a filter adjust the resolved ttids.
			if ( 'features' === $param ) {
				/** Filter the resolved feature term_taxonomy_ids (AND-matched). @since 6.3 */
				$ttids = array_map( 'intval', (array) apply_filters( 'mlsimport_listings_feature_ttids', $ttids, $params ) );
			}
			// Record the resolved ids plus the join mode ('and'/'or') the WHERE will apply.
			$groups[] = array(
				'ttids' => $ttids,
				'mode'  => $info['join'],
			);
		}

		return $groups;
	}
}
