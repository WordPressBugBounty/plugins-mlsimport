<?php
/**
 * Standalone (theme_id 990) render orchestrator.
 *
 * Bridges the listings search to the front-end: runs one flat-table query, then
 * primes the matching posts with a single WP_Query(post__in, orderby=post__in)
 * so order is preserved and get_permalink()/thumbnail hit cache. Cards read
 * scalars from the listings row (returned keyed by post_id) — display-only meta
 * stays in postmeta. Markup lives in templates that consume this data.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-listings-query.php';
require_once __DIR__ . '/class-mlsimport-standalone-template.php';
require_once __DIR__ . '/class-mlsimport-standalone-settings.php';
require_once __DIR__ . '/class-mlsimport-pagination.php';
require_once __DIR__ . '/class-mlsimport-map-clusterer.php';
require_once __DIR__ . '/listing-card.php';

/**
 * Prepares ordered, cache-primed listing data for the front-end templates.
 */
class Mlsimport_Standalone_Render {

	/**
	 * Default page size for the full listings grid UI (the search-form + results
	 * surface). Page blocks that select a fixed set pass their own count.
	 */
	const DEFAULT_PER_PAGE = 12;

	/**
	 * Resolve the page size for the listings grid: an explicit positive limit wins,
	 * otherwise the (filterable) default. Shared by render_grid and the AJAX repaint
	 * so the first paint and every subsequent page use the same size.
	 *
	 * @param array $args Render args.
	 * @return int
	 */
	public static function grid_limit( array $args ): int {
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		if ( $limit > 0 ) {
			return $limit;
		}
		// Design Settings → General → "No. of Properties per Page" is the site default;
		// DEFAULT_PER_PAGE only applies when that option is unset or non-positive.
		$configured = function_exists( 'mlsimport_standalone_option' ) ? (int) mlsimport_standalone_option( 'properties_per_page', 0 ) : 0;
		$default    = $configured > 0 ? $configured : self::DEFAULT_PER_PAGE;

		/** Filter the default listings grid page size. @since 6.4 */
		return max( 1, (int) apply_filters( 'mlsimport_listings_default_per_page', $default ) );
	}

	/**
	 * Resolve the grid's cards-per-row: an explicit columns arg (2/3/4) wins,
	 * otherwise 3. Emitted as the --mli-cols custom property on the grid wrapper
	 * (see render_grid). The archive template passes the site's "Cards per row"
	 * setting here; page-builder blocks pass nothing and stay at the default 3.
	 *
	 * @param array $args Render args.
	 * @return int One of 2, 3, 4.
	 */
	public static function grid_columns( array $args ): int {
		$columns = isset( $args['columns'] ) ? (int) $args['columns'] : 0;
		return in_array( $columns, array( 2, 3, 4 ), true ) ? $columns : 3;
	}

	/**
	 * Options for the results toolbar's Sort select: value => label. Sourced from the
	 * settings registry's "Order by" field so the visitor-facing list and the admin's
	 * default-order list can never drift apart. The registry's 'default' becomes the
	 * empty value, i.e. "no explicit sort" — which lets the site default apply.
	 *
	 * @return array<string,string>
	 */
	private static function sort_options(): array {
		$registry = function_exists( 'mlsimport_standalone_field_registry' ) ? mlsimport_standalone_field_registry() : array();
		$options  = isset( $registry['order_by']['options'] ) ? (array) $registry['order_by']['options'] : array();

		$result = array();
		foreach ( $options as $value => $label ) {
			$result[ 'default' === $value ? '' : (string) $value ] = (string) $label;
		}

		return $result;
	}

	/**
	 * The search form's visible field keys for $args, or null when every field
	 * shows (the default, and backward compatible). The listings block/shortcode
	 * pass search_fields as a comma list (or array) of the field keys to keep — an
	 * unset/empty value means "show all". search-form.php reads this to render only
	 * the enabled fields; it is pure display config and never touches the query.
	 *
	 * @param array $args Render args.
	 * @return string[]|null Enabled field keys, or null for "all".
	 */
	public static function visible_fields( array $args ): ?array {
		$result = null;
		if ( isset( $args['search_fields'] ) && '' !== $args['search_fields'] && array() !== $args['search_fields'] ) {
			// A non-empty selection lists exactly the fields to keep. Keys that match no
			// real field (e.g. the "none" marker the block stores when every field is
			// switched off) drop out, leaving an empty allow-list — i.e. show no fields.
			$raw    = is_array( $args['search_fields'] ) ? $args['search_fields'] : explode( ',', (string) $args['search_fields'] );
			$result = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', $raw ) ), 'strlen' ) );
		}
		/** Filter the search form's visible field keys; null shows every field. @since 6.4 */
		$result = apply_filters( 'mlsimport_listings_visible_fields', $result, $args );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Resolve filter args into the data a card grid renders.
	 *
	 * @param array $args Consumer filter params (see Mlsimport_Standalone_Query).
	 * @return array{posts:WP_Post[],rows:array<int,object>,total:int}
	 */
	public static function prepare( array $args ): array {
		/** Filter the inbound render args. @since 6.3 */
		$args   = (array) apply_filters( 'mlsimport_render_args', $args );
		$result = Mlsimport_Standalone_Listings_Query::search( $args );

		// Collect the matched post IDs (in row order) and index each row by post_id so
		// the card template can look up its scalars without another query.
		$ids        = array();
		$rows_by_id = array();
		foreach ( $result['rows'] as $row ) {
			$post_id                = (int) $row->post_id;
			$ids[]                  = $post_id;
			$rows_by_id[ $post_id ] = $row;
		}

		// Prime the posts in one query, preserving the flat-table order via post__in.
		$posts = array();
		if ( $ids ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'mlsimport_property',
					'post__in'       => $ids,
					'orderby'        => 'post__in',
					'posts_per_page' => count( $ids ),
					'no_found_rows'  => true,
				)
			);
			$posts = $query->posts;
		}

		$payload = array(
			'posts' => $posts,
			'rows'  => $rows_by_id,
			'total' => (int) $result['total'],
		);

		/** Filter the prepared listings payload. @since 6.3 */
		return (array) apply_filters( 'mlsimport_prepared_listings', $payload, $args );
	}

	/**
	 * Render the listings grid HTML for $args by including the (theme-overridable)
	 * card template once per matching post.
	 *
	 * @param array $args Consumer filter params.
	 * @return string Concatenated card HTML, or '' when there are no matches.
	 */
	public static function render( array $args ): string {
		return self::render_cards( self::prepare( $args ) );
	}

	/**
	 * Render the full listings UI: search form + results grid (cards) — the
	 * droppable surface for the shortcode/block/widget. AJAX repaints the grid.
	 *
	 * @param array $args Consumer filter params.
	 * @return string
	 */
	public static function render_grid( array $args ): string {
		// Page the grid by default (a listings surface should never dump every row).
		// The resolved limit is written back into $args so the search-form carries it
		// (hidden field) and the AJAX repaint keeps the same page size.
		$args['limit'] = self::grid_limit( $args );
		$page          = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$args['page']  = $page;
		$cols          = self::grid_columns( $args );

		$data            = self::prepare( $args );
		$cards           = self::render_cards( $data );
		$search_template = Mlsimport_Standalone_Template::locate( 'search-form.php' );
		$total           = (int) $data['total'];

		// The form is always rendered (it carries the filters for the AJAX repaint);
		// hide_search_form only adds a modifier the CSS uses to visually hide it, so a
		// "results only" surface still paginates the same search without a reload.
		$wrap_class = 'mlsimport-listings' . ( ! empty( $args['hide_search_form'] ) ? ' mlsimport-listings--no-form' : '' );

		ob_start();
		echo '<div class="' . esc_attr( $wrap_class ) . '">';
		do_action( 'mlsimport_before_listings', $args, $total );
		do_action( 'mlsimport_before_search_form', $args );
		include $search_template; // Uses $args.
		do_action( 'mlsimport_after_search_form', $args );
		echo '<div class="mlsimport-results">';
		// Count + sort share one toolbar row above the grid: count left, sort right.
		// The Sort control lives HERE, not among the search filters, but still drives the
		// same AJAX repaint — mlsimport-listings.js reads select[name="orderby"] from this
		// toolbar (it is outside form.mlsimport-search, so FormData never sees it).
		echo '<div class="mlsimport-results__toolbar">';
		echo '<div class="mlsimport-results__meta">' . esc_html( $total . ' ' . _n( 'result', 'results', $total, 'mlsimport' ) ) . '</div>';
		$visible = self::visible_fields( $args );
		if ( null === $visible || in_array( 'sort', $visible, true ) ) {
			// selected() on server render so the DOM value matches the preset; otherwise the
			// select shows its first option and the next AJAX repaint silently sorts by that.
			$orderby = Mlsimport_Standalone_Query::sort_token( $args );
			// A DIV, not a LABEL: mlsimport-property-interest.js replaces the select with
			// a <button>, and a button inside a label swallows its own clicks to the
			// label's activation behaviour. The label text becomes a plain span instead.
			//
			// The .mlsimport-interest wrapper is what that enhancer looks for. It hides
			// the native select (only once enhanced) and builds a button + listbox from
			// the options, so Sort gets the same dropdown as the property page's
			// "I'm interested in" — including a styled option list, which a native
			// select can never have. The select stays in the DOM and stays the value
			// mlsimport-listings.js reads, so the AJAX repaint is untouched and a page
			// with no JS still shows a working control.
			echo '<div class="mlsimport-results__sort">';
			echo '<span class="mlsimport-results__sort-label">' . esc_html__( 'Sort by', 'mlsimport' ) . '</span>';
			echo '<span class="mlsimport-interest mlsimport-results__sort-control">';
			echo '<select name="orderby">';
			foreach ( self::sort_options() as $mli_value => $mli_label ) {
				echo '<option value="' . esc_attr( $mli_value ) . '"' . selected( $orderby, $mli_value, false ) . '>' . esc_html( $mli_label ) . '</option>';
			}
			echo '</select>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		do_action( 'mlsimport_before_results', $args, $total );
		echo '<div class="mlsimport-results__grid" style="--mli-cols:' . esc_attr( (string) $cols ) . ';">';
		if ( '' !== $cards ) {
			echo $cards; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card.php escapes each field at source.
		} else {
			$empty = esc_html__( 'No listings match your search.', 'mlsimport' );
			/** Filter the empty-results message. @since 6.3 */
			echo wp_kses_post( apply_filters( 'mlsimport_no_results_message', '<p class="mlsimport-results__empty">' . $empty . '</p>', $args ) );
		}
		echo '</div>';
		// Pager container — the AJAX layer repaints its inner <nav> on filter/paginate.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Mlsimport_Pagination::render returns escaped markup.
		echo '<div class="mlsimport-results__pager">' . Mlsimport_Pagination::render( $total, (int) $args['limit'], $page ) . '</div>';
		do_action( 'mlsimport_after_results', $args, $total );
		echo '</div></div>';
		return (string) ob_get_clean();
	}

	/** Default cap on individual markers before the map switches to clusters. */
	const MAP_MARKER_CAP = 400;

	/**
	 * Map marker data for $args: one entry per matching listing that has
	 * coordinates. Honours every filter (incl. the lat/lng bounding box).
	 *
	 * @param array $args Consumer filter params.
	 * @return array<int,array{post_id:int,lat:float,lng:float,price:float|null,title:string,url:string,image:string,beds:string,baths:string,area:string}>
	 */
	public static function markers( array $args ): array {
		/** Filter the map marker set. @since 6.3 */
		return (array) apply_filters( 'mlsimport_map_markers', self::markers_from_data( self::prepare( $args ) ), $args );
	}

	/**
	 * Map markers for an explicit, ordered set of property post IDs (the page
	 * block's hand-picked "by IDs" selection). Mirrors cards_for_posts() so a
	 * hand-picked map honours the chosen listings instead of the whole feed.
	 *
	 * @param int[] $ids Property post IDs.
	 * @return array
	 */
	public static function markers_for_posts( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = $wpdb->prefix . 'mlsimport_listings';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id IN ({$placeholders})", $ids ) );

		$rows_by_id = array();
		foreach ( $rows as $row ) {
			$rows_by_id[ (int) $row->post_id ] = $row;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mlsimport_property',
				'post__in'       => $ids,
				'orderby'        => 'post__in',
				'posts_per_page' => count( $ids ),
				'no_found_rows'  => true,
			)
		);

		return self::markers_from_data(
			array(
				'posts' => $query->posts,
				'rows'  => $rows_by_id,
			)
		);
	}

	/**
	 * Build the marker array from an already-prepared { posts, rows } set. One
	 * builder so the query, by-IDs and viewport paths all emit identical markers.
	 *
	 * @param array $data { posts: WP_Post[], rows: array<int,object> }.
	 * @return array
	 */
	private static function markers_from_data( array $data ): array {
		$markers = array();

		// Spec formatter — mirrors mlsimport_format_amount() so the info-card
		// beds/baths/area read the same as the single-property map, without a
		// cross-file dependency on property-sections.php from this class.
		$fmt = static function ( $value ) {
			if ( null === $value || '' === $value ) {
				return '';
			}
			$f = (float) $value;
			return ( $f === (float) (int) $f ) ? number_format( $f ) : number_format( $f, 1 );
		};

		foreach ( $data['posts'] as $post ) {
			$row = isset( $data['rows'][ $post->ID ] ) ? $data['rows'][ $post->ID ] : null;
			if ( null === $row || null === $row->latitude || null === $row->longitude ) {
				continue;
			}

			// Clean RESO address for the info-card heading — same source the
			// listing cards use (mlsimport_card_view). The raw post title is an
			// importer concatenation ("indian river 1475 ... ,Eugene,Lane,Residential")
			// and is only the fallback when no UnparsedAddress is stored.
			$title = trim( (string) get_post_meta( $post->ID, 'mlsimport_UnparsedAddress', true ) );
			if ( '' === $title ) {
				$title = get_the_title( $post->ID );
			}

			$markers[] = array(
				'post_id' => (int) $post->ID,
				'lat'     => (float) $row->latitude,
				'lng'     => (float) $row->longitude,
				'price'   => null !== $row->price ? (float) $row->price : null,
				'title'   => $title,
				'url'     => (string) get_permalink( $post->ID ),
				'image'   => (string) ( get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: '' ),
				'beds'    => $fmt( isset( $row->bedrooms ) ? $row->bedrooms : null ),
				'baths'   => $fmt( isset( $row->bathrooms ) ? $row->bathrooms : null ),
				// Whole-number ft² (like the listing card + single-property popup); $fmt
				// would leak a fractional area (it keeps a decimal for non-integers).
				'area'    => ( isset( $row->living_area ) && null !== $row->living_area && '' !== $row->living_area ) ? number_format_i18n( (float) $row->living_area ) : '',
			);
		}

		return $markers;
	}

	/**
	 * Viewport map payload: given filter args (carrying the current lat/lng bbox)
	 * and the map zoom, grid-cluster every in-view listing into zoom-sized cells.
	 * A cell holding one listing returns a price pin (full card data); a cell
	 * holding several returns a counted bubble. Both arrays travel together, so
	 * dense areas collapse to bubbles while isolated listings stay pins — at every
	 * zoom, for any catalog size. The cell count is bounded by the viewport area,
	 * so the browser never receives more than a screenful of objects.
	 *
	 * @param array $args Filter args incl. lat_min/lat_max/lng_min/lng_max.
	 * @param int   $zoom Leaflet zoom level (grid sizing).
	 * @return array{type:string,total:int,markers:array,clusters:array,bounds:?array}
	 */
	public static function map_payload( array $args, int $zoom ): array {
		/** Short-circuit the viewport map payload (live mode answers from the MLS here). @since 6.4 */
		$pre = apply_filters( 'mlsimport_map_payload_pre', null, $args, $zoom );
		if ( is_array( $pre ) ) {
			return $pre;
		}

		// The map shows the whole in-view set (grid-clustered), never the grid's page
		// size — drop any limit/page so coords and markers can't disagree on the count.
		unset( $args['limit'], $args['page'] );

		$coords = Mlsimport_Standalone_Listings_Query::coords( $args );
		$total  = count( $coords );

		// The filter's OVERALL bounds (ignoring the viewport bbox), so a client that
		// just changed the filter can refit the map to the whole result set. Computed
		// from the args minus the bbox keys — otherwise bounds() would just echo the
		// current viewport. Cheap (one indexed MIN/MAX) and ignored on plain panning.
		$nogeo = $args;
		unset( $nogeo['lat_min'], $nogeo['lat_max'], $nogeo['lng_min'], $nogeo['lng_max'] );
		$bounds = Mlsimport_Standalone_Listings_Query::bounds( $nogeo );

		// Split the grid cells: single-occupancy cells become pins (fetch full card
		// data for just those lone listings), multi-occupancy cells become bubbles.
		// The "Use the Pin Cluster" + "Maximum zoom" design settings decide whether a
		// dense cell clusters at all — when off (or zoomed past the cut-over) every
		// cell's listings render as individual price pins.
		$cluster  = mlsimport_standalone_map_cluster_enabled( $zoom );
		$single   = array();
		$clusters = array();
		foreach ( Mlsimport_Map_Clusterer::grid( $coords, $zoom ) as $cell ) {
			// A crowded cell becomes a counted bubble only when clustering is on;
			// otherwise every listing in it is drawn as its own pin.
			if ( $cluster && $cell['count'] > 1 ) {
				$clusters[] = array(
					'lat'   => $cell['lat'],
					'lng'   => $cell['lng'],
					'count' => $cell['count'],
				);
			} else {
				foreach ( $cell['ids'] as $id ) {
					$single[] = (int) $id;
				}
			}
		}

		$markers = $single ? self::markers_for_posts( $single ) : array();
		/** Filter the viewport map's individual (single-cell) markers. @since 6.3 */
		$markers = (array) apply_filters( 'mlsimport_map_markers', $markers, $args );

		return array(
			'type'     => $clusters ? 'clusters' : 'markers',
			'total'    => $total,
			'markers'  => $markers,
			'clusters' => $clusters,
			'bounds'   => $bounds,
		);
	}

	/**
	 * Render the card grid from an already-prepared data set (one query reused
	 * by both the page render and the AJAX payload).
	 *
	 * @param array  $data        Output of prepare().
	 * @param string $slide_class When set, each card is wrapped in a <li> with this
	 *                            class (e.g. 'splide__slide' for the content slider).
	 * @return string Concatenated card HTML, or '' when there are no posts.
	 */
	public static function render_cards( array $data, string $slide_class = '' ): string {
		if ( empty( $data['posts'] ) ) {
			return '';
		}

		// One resolver picks the card design (v1/v2/v3) from settings, so every
		// listing grid that reaches render_cards is styled consistently.
		$card_name = function_exists( 'mlsimport_standalone_card_template' ) ? mlsimport_standalone_card_template() : 'card.php';
		$template  = Mlsimport_Standalone_Template::locate( $card_name );

		$out = '';
		foreach ( $data['posts'] as $post ) {
			$row = isset( $data['rows'][ $post->ID ] ) ? $data['rows'][ $post->ID ] : null;
			// Buffer each card individually so the before/after action slots and
			// the per-card filter compose cleanly into one returned string.
			ob_start();
			do_action( 'mlsimport_before_listing_card', $post, $row );
			include $template;
			do_action( 'mlsimport_after_listing_card', $post, $row );
			$card_html = (string) ob_get_clean();
			/** Filter one card's HTML. @since 6.3 */
			$card_html = (string) apply_filters( 'mlsimport_listing_card_html', $card_html, $post, $row );
			$out      .= '' !== $slide_class ? '<li class="' . esc_attr( $slide_class ) . '">' . $card_html . '</li>' : $card_html;
		}
		return $out;
	}

	/**
	 * Render cards for an explicit, ordered set of property post IDs (e.g. an
	 * agent's listings, linked by meta rather than the flat search). Fetches each
	 * post's listings row so the card shows its scalars.
	 *
	 * @param int[]  $ids         Property post IDs, in display order.
	 * @param string $slide_class When set, each card is wrapped in a <li> with this class.
	 * @return string
	 */
	public static function cards_for_posts( array $ids, string $slide_class = '' ): string {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return '';
		}

		$table        = $wpdb->prefix . 'mlsimport_listings';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id IN ({$placeholders})", $ids ) );

		$rows_by_id = array();
		foreach ( $rows as $row ) {
			$rows_by_id[ (int) $row->post_id ] = $row;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mlsimport_property',
				'post__in'       => $ids,
				'orderby'        => 'post__in',
				'posts_per_page' => count( $ids ),
				'no_found_rows'  => true,
			)
		);

		return self::render_cards(
			array(
				'posts' => $query->posts,
				'rows'  => $rows_by_id,
				'total' => count( $query->posts ),
			),
			$slide_class
		);
	}
}
