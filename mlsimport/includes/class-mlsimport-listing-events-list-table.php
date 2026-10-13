<?php
/**
 * WP_List_Table for the "Listing Changes" admin page (issue #222).
 *
 * Lists Listing Change Events from wp_mlsimport_listing_events: date (the
 * MLS's own change time when the feed sent one), event type, listing (title
 * link while the post exists, else the listing key), MLS #, old value, new
 * value and the import task. 50 rows per page, sortable by date (default DESC).
 *
 * Filters, read from $_GET with keys that MUST match
 * admin/partials/mlsimport-listing-changes.php:
 *   mlsimport_event  — stored event type (one of Mlsimport_Listing_Events::TYPES)
 *   mlsimport_item   — import task post ID
 *   mlsimport_s      — search on listing key or MLS #
 *
 * SQL safety rules (same as Mlsimport_Activity_List_Table):
 * - table name only from Mlsimport_Listing_Events::table_name(), interpolated,
 *   never a prepare placeholder, never from request input;
 * - orderby whitelisted to event_at, order to ASC | DESC;
 * - every filter VALUE goes through $wpdb->prepare.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render Listing Change Events as a standard wp-admin list table.
 */
class Mlsimport_Listing_Events_List_Table extends WP_List_Table {

	/**
	 * Set up singular/plural labels; ajax off (full page reload per action).
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'listing change', 'mlsimport' ),
				'plural'   => __( 'listing changes', 'mlsimport' ),
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns in display order.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'event_at'    => __( 'Date', 'mlsimport' ),
			'event_type'  => __( 'Event', 'mlsimport' ),
			'listing'     => __( 'Listing', 'mlsimport' ),
			'listing_id'  => __( 'MLS #', 'mlsimport' ),
			'old_value'   => __( 'From', 'mlsimport' ),
			'new_value'   => __( 'To', 'mlsimport' ),
			'import_item' => __( 'Import Task', 'mlsimport' ),
		);
	}

	/**
	 * Only the date is sortable.
	 *
	 * @return array<string, array>
	 */
	protected function get_sortable_columns(): array {
		return array( 'event_at' => array( 'event_at', true ) );
	}

	/**
	 * Import task options for the filter dropdown: DISTINCT task_id (not 0)
	 * resolved to the live post title, or the ID when the task is gone.
	 *
	 * @return array<int, string> task_id => title.
	 */
	public function get_import_task_options(): array {
		global $wpdb;
		$table = Mlsimport_Listing_Events::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids     = $wpdb->get_col( "SELECT DISTINCT task_id FROM {$table} WHERE task_id != 0 ORDER BY task_id ASC" );
		$options = array();
		foreach ( (array) $ids as $id ) {
			$id    = (int) $id;
			$title = get_the_title( $id );
			/* translators: %d: import task post ID. */
			$options[ $id ] = '' !== $title ? $title : sprintf( __( 'Task #%d', 'mlsimport' ), $id );
		}
		return $options;
	}

	/**
	 * Read the filters from the page address and fetch the page of rows.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		// Step 1: sanitize the filter inputs (keys shared with the partial).
		$filter_event  = isset( $_GET['mlsimport_event'] ) ? sanitize_key( wp_unslash( $_GET['mlsimport_event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_item   = isset( $_GET['mlsimport_item'] ) ? absint( $_GET['mlsimport_item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_search = isset( $_GET['mlsimport_s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['mlsimport_s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Step 2: whitelist the order (the only sortable column is event_at).
		$order_raw = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Step 3: the one Listing Changes query (Mlsimport_Listing_Events::query()),
		// shared with the listing-changes AI tool. An unknown event type means "all".
		$per_page = 50;
		$result   = Mlsimport_Listing_Events::query(
			array(
				'event_type' => isset( Mlsimport_Listing_Events::type_labels()[ $filter_event ] ) ? $filter_event : '',
				'task_id'    => $filter_item,
				'search'     => $filter_search,
				'order'      => 'ASC' === $order_raw ? 'ASC' : 'DESC',
				'page'       => $this->get_pagenum(),
				'per_page'   => $per_page,
			)
		);

		// Step 4: pager and rows.
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
		$this->items = $result['rows'];

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Date column: the UTC event time shown in the site's timezone.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_event_at( array $item ): string {
		return esc_html( get_date_from_gmt( (string) $item['event_at'], 'Y-m-d H:i' ) );
	}

	/**
	 * Event column: a colour-coded badge with the human label.
	 *
	 * The label is the notification kind, the same wording the daily email
	 * uses: a price change reads Price drop / Price increase, a departure reads
	 * Under contract / Sold / Off market.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_event_type( array $item ): string {
		$kind  = Mlsimport_Listing_Notification_Matcher::kind( $item );
		$label = Mlsimport_Listing_Notification_Matcher::kind_labels()[ $kind ] ?? $kind;
		return '<span class="mlsimport-activity-action mlsimport-listing-event--' . esc_attr( $kind ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * The listing's page on the site, while the post still exists.
	 *
	 * @param array $item Row.
	 * @return string Permalink, or '' when the listing is gone.
	 */
	private static function listing_link( array $item ): string {
		$post_id = (int) ( $item['post_id'] ?? 0 );
		if ( $post_id > 0 && get_post_status( $post_id ) ) {
			return (string) get_permalink( $post_id );
		}
		return '';
	}

	/**
	 * One table row. A row whose listing still exists carries its link in
	 * `data-href`: the page script (mlsimport-listing-changes.php) sends a
	 * click anywhere on the row to that listing.
	 *
	 * @param array $item Row.
	 * @return void
	 */
	public function single_row( $item ): void {
		$link = self::listing_link( (array) $item );
		echo '' !== $link ? '<tr class="mlsimport-listing-row--link" data-href="' . esc_url( $link ) . '">' : '<tr>';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Listing column: title link while the post exists, else the listing key.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_listing( array $item ): string {
		$link  = self::listing_link( $item );
		$title = '' !== $link ? get_the_title( (int) $item['post_id'] ) : '';
		if ( '' !== $title ) {
			return '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
		}
		return esc_html( (string) ( $item['listing_key'] ?? '' ) );
	}

	/**
	 * From column: the old value, formatted by event type.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_old_value( array $item ): string {
		return esc_html( self::format_value( (string) $item['event_type'], (string) $item['old_value'], true ) );
	}

	/**
	 * To column: the new value, formatted by event type.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_new_value( array $item ): string {
		return esc_html( self::format_value( (string) $item['event_type'], (string) $item['new_value'], false ) );
	}

	/**
	 * Import task column: edit link while the task exists, else its title / ID.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_import_item( array $item ): string {
		$task_id = (int) ( $item['task_id'] ?? 0 );
		if ( 0 === $task_id ) {
			return esc_html__( 'Unknown import task', 'mlsimport' );
		}
		$title = get_the_title( $task_id );
		if ( '' === $title ) {
			/* translators: %d: import task post ID. */
			return esc_html( sprintf( __( 'Task #%d', 'mlsimport' ), $task_id ) );
		}
		$edit = get_post_status( $task_id ) ? get_edit_post_link( $task_id ) : '';
		return $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
	}

	/**
	 * Turn a stored value into what the administrator reads.
	 *
	 * Prices go through the site's price formatter (standalone) or a plain
	 * thousands format; statuses are shown in readable words ("Active Under
	 * Contract", not the stored "activeundercontract"); an empty new
	 * value on a Removal reads "no longer on the MLS"; other blanks show a dash.
	 *
	 * @param string $type   Event type.
	 * @param string $value  Stored value.
	 * @param bool   $is_old Whether this is the From side.
	 * @return string Display text.
	 */
	public static function format_value( string $type, string $value, bool $is_old ): string {
		// Prices: both sides of a price change, and the closing price of a sale.
		if ( in_array( $type, array( 'price_change', 'sold' ), true ) && is_numeric( $value ) ) {
			return function_exists( 'mlsimport_format_price' ) ? mlsimport_format_price( $value ) : number_format( (float) $value );
		}
		if ( '' === $value ) {
			return 'removed' === $type && ! $is_old ? __( 'no longer on the MLS', 'mlsimport' ) : '—';
		}
		// Statuses are stored as comparison keys; show them in readable words.
		if ( in_array( $type, array( 'status_change', 'removed', 'back_on_market', 'new_listing' ), true ) ) {
			return mlsimport_status_label( $value );
		}
		return $value;
	}

	/**
	 * Fallback renderer for plain columns (listing_id).
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column slug.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		$value = (string) ( $item[ $column_name ] ?? '' );
		return esc_html( '' !== $value ? $value : '—' );
	}

	/**
	 * Empty-state message.
	 *
	 * @return void
	 */
	public function no_items(): void {
		echo esc_html__( 'No listing changes recorded yet.', 'mlsimport' );
	}
}
