<?php
/**
 * Standalone (theme_id 990) AJAX filter/paginate endpoint (M6).
 *
 * The half-map / grid filters POST here; the handler verifies a nonce and returns
 * the rendered cards + the full total so the JS can repaint the results column
 * and pager without a page reload. The payload builder is separated from the
 * nonce/JSON shell so it can be exercised in isolation.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-render.php';
require_once __DIR__ . '/class-mlsimport-standalone-shortcodes.php';

/**
 * Handles the nonce-protected listings filter AJAX request.
 */
class Mlsimport_Standalone_Ajax {

	const ACTION           = 'mlsimport_filter';
	const MARKERS_ACTION   = 'mlsimport_markers';
	const LOCATIONS_ACTION = 'mlsimport_locations';

	/** How many suggestions one Location autocomplete response may carry. */
	const LOCATION_LIMIT = 10;

	/**
	 * Register the logged-in and logged-out AJAX handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_' . self::MARKERS_ACTION, array( __CLASS__, 'handle_markers' ) );
		add_action( 'wp_ajax_nopriv_' . self::MARKERS_ACTION, array( __CLASS__, 'handle_markers' ) );
		add_action( 'wp_ajax_' . self::LOCATIONS_ACTION, array( __CLASS__, 'handle_locations' ) );
		add_action( 'wp_ajax_nopriv_' . self::LOCATIONS_ACTION, array( __CLASS__, 'handle_locations' ) );
	}

	/**
	 * AJAX entry point: verify nonce, send the payload as JSON.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_ajax_referer( self::ACTION, 'nonce' );
		// Request is whitelisted+cast in payload(); unslash for mapping only.
		$request = isset( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_send_json_success( self::payload( (array) $request ) );
	}

	/**
	 * Build the filter response: rendered cards + the full match total. Only
	 * whitelisted filter keys are honoured (via the shortcode att mapping); the
	 * query layer binds every value, so raw request input is injection-safe.
	 *
	 * @param array $request Raw request params.
	 * @return array{html:string,total:int}
	 */
	public static function payload( array $request ): array {
		$args          = Mlsimport_Standalone_Shortcodes::atts_to_args( $request );
		$args['limit'] = Mlsimport_Standalone_Render::grid_limit( $args );
		$page          = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$args['page']  = $page;
		$data          = Mlsimport_Standalone_Render::prepare( $args );

		return array(
			'html'  => Mlsimport_Standalone_Render::render_cards( $data ),
			'total' => (int) $data['total'],
			'pager' => Mlsimport_Pagination::render( (int) $data['total'], (int) $args['limit'], $page ),
		);
	}

	/**
	 * AJAX entry point for map-pan: verify nonce, send markers as JSON.
	 *
	 * @return void
	 */
	public static function handle_markers(): void {
		check_ajax_referer( self::MARKERS_ACTION, 'nonce' );
		$request = isset( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_send_json_success( self::markers_payload( (array) $request ) );
	}

	/**
	 * Build the viewport map response for the (whitelisted) filter request. The
	 * request carries the current map bbox (lat_min/lat_max/lng_min/lng_max, part
	 * of the filter att whitelist) and zoom; the render layer returns individual
	 * markers when the in-view count is small, or grid clusters when it is large.
	 *
	 * @param array $request Raw request params.
	 * @return array{type:string,total:int,markers?:array,clusters?:array}
	 */
	public static function markers_payload( array $request ): array {
		$args = Mlsimport_Standalone_Shortcodes::atts_to_args( $request );
		$zoom = isset( $request['zoom'] ) ? (int) $request['zoom'] : 0;

		return Mlsimport_Standalone_Render::map_payload( $args, $zoom );
	}

	/**
	 * AJAX entry point for the Location field's autocomplete.
	 *
	 * @return void
	 */
	public static function handle_locations(): void {
		check_ajax_referer( self::LOCATIONS_ACTION, 'nonce' );
		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		wp_send_json_success( self::locations_payload( $term ) );
	}

	/**
	 * Suggestions for a partially typed location: the cities and ZIPs the fast
	 * table holds, plus the area and county terms — the same four places the
	 * WHERE-builder's location clause searches, so nothing is ever suggested that
	 * the search cannot then match.
	 *
	 * @param string $term Partial input; under two characters returns nothing.
	 * @return array<int,array{value:string,type:string}>
	 */
	public static function locations_payload( string $term ): array {
		$term = trim( $term );
		// One or zero characters would match most of the index — not a suggestion.
		if ( mb_strlen( $term ) < 2 ) {
			return array();
		}

		$out = array_merge(
			self::location_column_matches( 'city', $term, __( 'City', 'mlsimport' ) ),
			self::location_term_matches( $term ),
			self::location_column_matches( 'zip', $term, __( 'ZIP', 'mlsimport' ) )
		);

		/** Filter the Location autocomplete suggestions. @since 6.4 */
		$out = (array) apply_filters( 'mlsimport_location_suggestions', $out, $term );

		return array_slice( array_values( $out ), 0, self::LOCATION_LIMIT );
	}

	/**
	 * Distinct values of one fast-table location column starting with the typed
	 * text. Joined to wp_posts because the index table outlives its posts, and a
	 * suggestion drawn from an orphaned row would return zero results when picked.
	 *
	 * @param string $column 'city' or 'zip' — a fixed literal, never request input.
	 * @param string $term   Partial input.
	 * @param string $type   Human label for the suggestion's tag.
	 * @return array<int,array{value:string,type:string}>
	 */
	private static function location_column_matches( string $column, string $term, string $type ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'mlsimport_listings';
		$like  = $wpdb->esc_like( $term ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is a fixed literal from the caller.
				"SELECT DISTINCT L.{$column} FROM {$table} L INNER JOIN {$wpdb->posts} P ON P.ID = L.post_id AND P.post_status = 'publish' AND P.post_type = 'mlsimport_property' WHERE L.{$column} LIKE %s ORDER BY L.{$column} LIMIT %d",
				$like,
				self::LOCATION_LIMIT
			)
		);

		$out = array();
		foreach ( (array) $rows as $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$out[] = array( 'value' => (string) $value, 'type' => $type );
		}
		return $out;
	}

	/**
	 * Area and county terms matching the typed text — the two location taxonomies
	 * with no fast column of their own.
	 *
	 * @param string $term Partial input.
	 * @return array<int,array{value:string,type:string}>
	 */
	private static function location_term_matches( string $term ): array {
		if ( ! class_exists( 'Mlsimport_Page_Block_Search_Fields' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => Mlsimport_Page_Block_Search_Fields::location_taxonomies(),
				'hide_empty' => true,
				'search'     => $term,
				'number'     => self::LOCATION_LIMIT,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		// The tag shows which taxonomy the term came from, so "Collier" reads as a
		// county and not as another city.
		$labels = array(
			'mlsimport_area'   => __( 'Area', 'mlsimport' ),
			'mlsimport_county' => __( 'County', 'mlsimport' ),
		);

		$out = array();
		foreach ( (array) $terms as $t ) {
			$out[] = array(
				'value' => (string) $t->name,
				'type'  => isset( $labels[ $t->taxonomy ] ) ? $labels[ $t->taxonomy ] : (string) $t->taxonomy,
			);
		}
		return $out;
	}
}
