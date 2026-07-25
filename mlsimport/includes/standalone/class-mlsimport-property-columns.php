<?php
/**
 * Standalone (theme_id 990) property list-table columns.
 *
 * Makes wp-admin edit.php?post_type=mlsimport_property mirror the WPResidence
 * property list: Thumbnail + Title + Location, Type, Status and Price. The
 * WPResidence dashboard list also has an Actions column — intentionally omitted
 * here, since wp-admin already provides row actions under the title.
 *
 * Columns map to the standalone taxonomies (Mlsimport_Standalone_Cpt) and the
 * ListPrice meta written by the metabox; Price is formatted with the same
 * mlsimport_format_price() helper the front end uses.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the property list-table columns.
 */
class Mlsimport_Property_Columns {

	/**
	 * Register the admin hooks. Hooked on init; only wires up in the admin.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'manage_mlsimport_property_posts_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_edit-mlsimport_property_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'manage_mlsimport_property_posts_custom_column', array( __CLASS__, 'render' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'orderby_price' ) );
		add_filter( 'posts_clauses', array( __CLASS__, 'orderby_status' ), 10, 2 );
	}

	/**
	 * Build the column set, mirroring the WPResidence property list order:
	 * Property (thumb + title), Location, Type, Status, Price — then the native
	 * Date. The native checkbox and title are kept; everything else is replaced.
	 *
	 * @param array $columns Default columns (cb, title, taxonomies, date).
	 * @return array
	 */
	public static function columns( $columns ): array {
		return array(
			'cb'                  => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'mlsimport_thumb'     => esc_html__( 'Photo', 'mlsimport' ),
			'title'               => esc_html__( 'Property', 'mlsimport' ),
			'mlsimport_location'  => esc_html__( 'Location', 'mlsimport' ),
			'mlsimport_type'      => esc_html__( 'Type', 'mlsimport' ),
			'mlsimport_status'    => esc_html__( 'Status', 'mlsimport' ),
			'mlsimport_price'     => esc_html__( 'Price', 'mlsimport' ),
			'mlsimport_ids'       => esc_html__( 'IDs', 'mlsimport' ),
			'date'                => isset( $columns['date'] ) ? $columns['date'] : esc_html__( 'Date', 'mlsimport' ),
		);
	}

	/**
	 * Mark the ID, Price and Status columns sortable. The map values become the
	 * `orderby` query var when a header is clicked: `ID` is handled natively by
	 * WP_Query, while `mlsimport_price` and `mlsimport_status` are resolved by
	 * orderby_price()/orderby_status() below.
	 *
	 * @param array $columns Default sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ): array {
		$columns['mlsimport_ids']    = 'ID';
		$columns['mlsimport_price']  = 'mlsimport_price';
		$columns['mlsimport_status'] = 'mlsimport_status';
		return $columns;
	}

	/**
	 * Sort the property list by ListPrice when the Price header is clicked.
	 * Uses meta_value_num so prices order numerically, not as strings.
	 *
	 * @param WP_Query $query Current query.
	 * @return void
	 */
	public static function orderby_price( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'mlsimport_property' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( 'mlsimport_price' !== $query->get( 'orderby' ) ) {
			return;
		}
		$query->set( 'meta_key', 'mlsimport_ListPrice' );
		$query->set( 'orderby', 'meta_value_num' );
	}

	/**
	 * Sort the property list by the mlsimport_status term name when the Status
	 * header is clicked. Taxonomy terms can't be ordered via WP_Query's orderby,
	 * so join the term tables and order by term name in the SQL clauses.
	 *
	 * @param array    $clauses SQL clause fragments.
	 * @param WP_Query $query   Current query.
	 * @return array
	 */
	public static function orderby_status( $clauses, $query ): array {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $clauses;
		}
		if ( 'mlsimport_property' !== $query->get( 'post_type' ) ) {
			return $clauses;
		}
		if ( 'mlsimport_status' !== $query->get( 'orderby' ) ) {
			return $clauses;
		}
		global $wpdb;
		$clauses['join']   .= " LEFT JOIN {$wpdb->term_relationships} mlsimport_status_tr ON ( {$wpdb->posts}.ID = mlsimport_status_tr.object_id )"
			. " LEFT JOIN {$wpdb->term_taxonomy} mlsimport_status_tt ON ( mlsimport_status_tr.term_taxonomy_id = mlsimport_status_tt.term_taxonomy_id AND mlsimport_status_tt.taxonomy = 'mlsimport_status' )"
			. " LEFT JOIN {$wpdb->terms} mlsimport_status_t ON ( mlsimport_status_tt.term_id = mlsimport_status_t.term_id )";
		$order              = ( 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ) ? 'ASC' : 'DESC';
		$clauses['orderby'] = "mlsimport_status_t.name $order";
		$clauses['groupby'] = "{$wpdb->posts}.ID";
		return $clauses;
	}

	/**
	 * Render one custom column cell for a property.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Property post ID.
	 * @return void
	 */
	public static function render( $column, $post_id ): void {
		switch ( $column ) {
			case 'mlsimport_thumb':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, array( 56, 56 ), array( 'class' => 'mlsimport-col-thumb__img' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
				} else {
					echo '<span class="mlsimport-col-thumb__ph" aria-hidden="true"></span>';
				}
				break;

			case 'mlsimport_location':
				self::tax_rows(
					$post_id,
					array(
						'mlsimport_area'   => __( 'Area', 'mlsimport' ),
						'mlsimport_city'   => __( 'City', 'mlsimport' ),
						'mlsimport_zip'    => __( 'ZIP', 'mlsimport' ),
						'mlsimport_county' => __( 'County', 'mlsimport' ),
						'mlsimport_state'  => __( 'State', 'mlsimport' ),
					)
				);
				break;

			case 'mlsimport_type':
				self::tax_rows(
					$post_id,
					array(
						'mlsimport_property_type' => __( 'Type', 'mlsimport' ),
						'mlsimport_listing_type'  => __( 'Listing Type', 'mlsimport' ),
					)
				);
				break;

			case 'mlsimport_status':
				$status = self::terms( $post_id, array( 'mlsimport_status' ) );
				echo '' !== $status
					? '<span class="mlsimport-col-status">' . wp_kses_post( $status ) . '</span>'
					: '<span class="mlsimport-col-empty">&mdash;</span>';
				break;

			case 'mlsimport_price':
				$price = get_post_meta( $post_id, 'mlsimport_ListPrice', true );
				if ( '' !== $price && null !== $price && function_exists( 'mlsimport_format_price' ) ) {
					echo '<strong class="mlsimport-col-price">' . esc_html( mlsimport_format_price( $price ) ) . '</strong>';
				} else {
					echo '<span class="mlsimport-col-empty">&mdash;</span>';
				}
				break;

			case 'mlsimport_ids':
				self::id_row( __( 'Local ID', 'mlsimport' ), (string) absint( $post_id ) );
				self::id_row( __( 'MLS ID', 'mlsimport' ), (string) get_post_meta( $post_id, 'mlsimport_ListingId', true ) );
				self::id_row( __( 'Listing Key', 'mlsimport' ), (string) get_post_meta( $post_id, 'mlsimport_ListingKey', true ) );
				break;
		}
	}

	/**
	 * Render one labelled identifier row inside the IDs column. An empty value
	 * shows an em-dash so the row stays aligned with its label.
	 *
	 * @param string $label Row label (e.g. "MLS ID").
	 * @param string $value Stored identifier.
	 * @return void
	 */
	private static function id_row( string $label, string $value ): void {
		echo '<span class="mlsimport-col-id-row"><span class="mlsimport-col-id-label">'
			. esc_html( $label ) . '</span>'
			. ( '' !== $value
				? '<code class="mlsimport-col-id">' . esc_html( $value ) . '</code>'
				: '<span class="mlsimport-col-empty">&mdash;</span>' )
			. '</span>';
	}

	/**
	 * Comma-join the term names a property has across one or more taxonomies,
	 * preserving the given taxonomy order. Empty taxonomies are skipped.
	 *
	 * @param int      $post_id    Property post ID.
	 * @param string[] $taxonomies Taxonomy slugs, in display order.
	 * @return string Escaped-safe comma list, or '' when none.
	 */
	private static function terms( int $post_id, array $taxonomies ): string {
		return implode( ', ', self::term_links( $post_id, $taxonomies ) );
	}

	/**
	 * Render one labelled row per taxonomy (label + that taxonomy's term names),
	 * preserving the given order. A taxonomy with no terms shows an em-dash so
	 * each row stays aligned with its label.
	 *
	 * @param int                  $post_id Property post ID.
	 * @param array<string,string> $map     Taxonomy slug => row label, in order.
	 * @return void
	 */
	private static function tax_rows( int $post_id, array $map ): void {
		// One labelled row per taxonomy, in the map's order.
		foreach ( $map as $taxonomy => $label ) {
			// Comma-join this taxonomy's term names ('' when the post has none).
			$value = implode( ', ', self::term_links( $post_id, array( $taxonomy ) ) );
			echo '<span class="mlsimport-col-id-row"><span class="mlsimport-col-id-label">'
				. esc_html( $label ) . '</span>'
				. ( '' !== $value
					? '<span class="mlsimport-col-tax">' . wp_kses_post( $value ) . '</span>'
					: '<span class="mlsimport-col-empty">&mdash;</span>' )
				. '</span>';
		}
	}

	/**
	 * Collect the term names a property has across one or more taxonomies, each
	 * linked to its public term archive, preserving the given taxonomy order.
	 * Empty taxonomies are skipped, as is any term whose link can't be built.
	 *
	 * @param int      $post_id    Property post ID.
	 * @param string[] $taxonomies Taxonomy slugs, in display order.
	 * @return string[] Anchor markup, one per term.
	 */
	private static function term_links( int $post_id, array $taxonomies ): array {
		$links = array();
		// Walk each taxonomy in order, appending its linked term names.
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			// get_the_terms() returns false/WP_Error when the post has no terms — skip.
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$url     = get_term_link( $term );
					$links[] = is_wp_error( $url )
						? esc_html( $term->name )
						: '<a href="' . esc_url( $url ) . '">' . esc_html( $term->name ) . '</a>';
				}
			}
		}
		return $links;
	}

	/**
	 * Enqueue the tiny column stylesheet on the property list screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		if ( 'mlsimport_property' !== get_current_screen()->post_type ) {
			return;
		}
		$base = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );
		$ver  = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : false;
		wp_enqueue_style( 'mlsimport-property-columns', $base . 'admin/css/mlsimport-property-columns.css', array(), $ver );
	}
}
