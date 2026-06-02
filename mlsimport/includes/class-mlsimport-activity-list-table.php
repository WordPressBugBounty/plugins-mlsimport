<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_List_Table implementation for the MLSImport activity history page.
 *
 * Columns: created_at, action, listing, listing_id, listing_key, import_item, source.
 * Shows the last 30 days; 50 rows per page; sortable by created_at (default DESC).
 * Filterable by action and import_item_id via $_GET keys 'mlsimport_action' and 'mlsimport_item'
 * — MUST use these exact keys to stay consistent with admin/partials/mlsimport-history.php.
 *
 * SQL safety rules:
 * - Table name comes ONLY from mlsimport_activity_table_name() — interpolated directly,
 *   never as a $wpdb->prepare placeholder, never from request input.
 * - 'orderby' is whitelisted to 'created_at'; 'order' to 'ASC'|'DESC' (fallback: created_at DESC).
 * - All filter VALUES are passed through $wpdb->prepare with %s / %d.
 */
class Mlsimport_Activity_List_Table extends WP_List_Table {

	/**
	 * Sets up column definitions and table args.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'activity record', 'mlsimport' ),
				'plural'   => __( 'activity records', 'mlsimport' ),
				'ajax'     => false,
			)
		);
	}

	/**
	 * Returns the list of columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'created_at'  => __( 'Date', 'mlsimport' ),
			'action'      => __( 'Action', 'mlsimport' ),
			'listing'     => __( 'Listing', 'mlsimport' ),
			'listing_id'  => __( 'Listing ID', 'mlsimport' ),
			'listing_key' => __( 'ListingKey', 'mlsimport' ),
			'import_item' => __( 'Import Task', 'mlsimport' ),
			'source'      => __( 'Source', 'mlsimport' ),
		);
	}

	/**
	 * Returns the sortable columns.
	 * Only created_at is sortable.
	 *
	 * @return array<string, array>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Returns the CSS badge class string for a given action string.
	 *
	 * PURE static helper — no WordPress calls (unit-testable).
	 * Case/whitespace tolerant: normalizes with strtolower() + trim().
	 *
	 * @param string $action Raw action value.
	 * @return string CSS class string.
	 */
	public static function action_badge_class( string $action ): string {
		$normalized = strtolower( trim( $action ) );

		$known = array( 'added', 'edited', 'deleted' );

		if ( in_array( $normalized, $known, true ) ) {
			return 'mlsimport-activity-action mlsimport-activity-action--' . $normalized;
		}

		return 'mlsimport-activity-action';
	}

	/**
	 * Returns import task options for the filter dropdown.
	 * Queries DISTINCT import_item_id values (excluding 0) and resolves titles.
	 *
	 * @return array<int, string> Map of import_item_id => import_item_title.
	 */
	public function get_import_task_options(): array {
		global $wpdb;

		$table = mlsimport_activity_table_name();

		// Table name interpolated directly — safe, comes only from mlsimport_activity_table_name().
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT DISTINCT import_item_id, MAX(import_item_title) AS import_item_title
			 FROM {$table}
			 WHERE import_item_id != 0
			 GROUP BY import_item_id
			 ORDER BY import_item_title ASC"
		);

		$options = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$id    = (int) $row->import_item_id;
				$title = (string) $row->import_item_title;

				// Prefer the live post title if the post still exists.
				$live_title = get_the_title( $id );
				if ( ! empty( $live_title ) ) {
					$title = $live_title;
				}

				$options[ $id ] = $title;
			}
		}

		return $options;
	}

	/**
	 * Prepares the list of items for display.
	 * Reads filter keys 'mlsimport_action' and 'mlsimport_item' from $_GET.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		global $wpdb;

		$table = mlsimport_activity_table_name();

		// 30-day cutoff (WP local time).
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );

		// --- Sanitize filter inputs ---
		// Filter GET key: 'mlsimport_action' (consistent with history partial form).
		$filter_action = isset( $_GET['mlsimport_action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( $_GET['mlsimport_action'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		// Filter GET key: 'mlsimport_item' (consistent with history partial form).
		$filter_item = isset( $_GET['mlsimport_item'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? absint( $_GET['mlsimport_item'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 0;

		// Search GET key: 'mlsimport_s' — matches Listing ID or ListingKey (consistent with history partial form).
		$filter_search = isset( $_GET['mlsimport_s'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? trim( sanitize_text_field( wp_unslash( $_GET['mlsimport_s'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		// --- Whitelist orderby and order ---
		$allowed_orderby = array( 'created_at' );
		$orderby_raw = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = in_array( $orderby_raw, $allowed_orderby, true ) ? $orderby_raw : 'created_at';

		$order_raw = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = in_array( $order_raw, array( 'ASC', 'DESC' ), true ) ? $order_raw : 'DESC';

		// --- Build WHERE clause ---
		// Table name from mlsimport_activity_table_name() — interpolated directly, never a placeholder.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$where = $wpdb->prepare( 'WHERE created_at >= %s', $cutoff );

		if ( ! empty( $filter_action ) && in_array( $filter_action, array( 'added', 'edited', 'deleted' ), true ) ) {
			$where         .= $wpdb->prepare( ' AND action = %s', $filter_action );
		}

		if ( $filter_item > 0 ) {
			$where .= $wpdb->prepare( ' AND import_item_id = %d', $filter_item );
		}

		// Match the search term against either the numeric Listing ID or the ListingKey.
		if ( '' !== $filter_search ) {
			$like   = '%' . $wpdb->esc_like( $filter_search ) . '%';
			$where .= $wpdb->prepare( ' AND ( listing_key LIKE %s OR CAST(listing_id AS CHAR) LIKE %s )', $like, $like );
		}

		// --- Count total items for pagination ---
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

		// --- Pagination ---
		$per_page = 50;
		$current_page = $this->get_pagenum();

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);

		$offset = ( $current_page - 1 ) * $per_page;

		// --- Fetch items ---
		// Table name interpolated directly — safe.
		// orderby/order whitelisted above — safe to interpolate.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$items = $wpdb->get_results( $sql, ARRAY_A );

		$this->items = $items ? $items : array();

		// Set column headers.
		$columns               = $this->get_columns();
		$hidden_columns        = array();
		$sortable_columns      = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden_columns, $sortable_columns );
	}

	/**
	 * Renders the 'created_at' column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_created_at( array $item ): string {
		return esc_html( $item['created_at'] );
	}

	/**
	 * Renders the 'action' column — a color-coded badge.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_action( array $item ): string {
		$action = isset( $item['action'] ) ? (string) $item['action'] : '';
		$class  = self::action_badge_class( $action );

		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $action ) . '</span>';
	}

	/**
	 * Renders the 'listing' column — linked when the post still exists, else plain text.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_listing( array $item ): string {
		$listing_id    = (int) ( $item['listing_id'] ?? 0 );
		$listing_title = (string) ( $item['listing_title'] ?? '' );
		$listing_url   = (string) ( $item['listing_url'] ?? '' );

		// Link only when the post still exists.
		if ( $listing_id > 0 && get_post_status( $listing_id ) ) {
			return '<a href="' . esc_url( $listing_url ) . '">' . esc_html( $listing_title ) . '</a>';
		}

		return esc_html( $listing_title );
	}

	/**
	 * Renders the 'listing_id' column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_listing_id( array $item ): string {
		return esc_html( (string) ( $item['listing_id'] ?? '' ) );
	}

	/**
	 * Renders the 'listing_key' column.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_listing_key( array $item ): string {
		return esc_html( (string) ( $item['listing_key'] ?? '' ) );
	}

	/**
	 * Renders the 'import_item' column — linked when the post still exists, else plain text.
	 * import_item_id = 0 renders as "Unknown import task".
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_import_item( array $item ): string {
		$import_item_id    = (int) ( $item['import_item_id'] ?? 0 );
		$import_item_title = (string) ( $item['import_item_title'] ?? '' );

		if ( 0 === $import_item_id ) {
			return esc_html__( 'Unknown import task', 'mlsimport' );
		}

		// Link only when the post still exists.
		if ( get_post_status( $import_item_id ) ) {
			$edit_url = get_edit_post_link( $import_item_id );
			if ( $edit_url ) {
				return '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $import_item_title ) . '</a>';
			}
		}

		return esc_html( $import_item_title );
	}

	/**
	 * Renders the 'source' column with friendly, properly-cased labels.
	 * 'cron' -> "Automatically", 'manual' -> "Manual"; other values are capitalized.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_source( array $item ): string {
		$source = (string) ( $item['source'] ?? '' );
		$labels = array(
			'cron'   => __( 'Automatically', 'mlsimport' ),
			'manual' => __( 'Manual', 'mlsimport' ),
		);
		$display = isset( $labels[ $source ] ) ? $labels[ $source ] : ucfirst( $source );
		return esc_html( $display );
	}

	/**
	 * Default column renderer (fallback).
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column slug.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	/**
	 * Renders the empty-state message when no items are found.
	 *
	 * @return void
	 */
	public function no_items(): void {
		echo esc_html__( 'No activity recorded in the last 30 days.', 'mlsimport' );
	}
}
