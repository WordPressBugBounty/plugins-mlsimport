<?php
/**
 * Import History: the one query that reads the activity table for a screen.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Import History (MLSImport > Import History) lists the rows of the activity
 * table (one row per listing added, edited or deleted) filtered by action,
 * import task and listing number, newest first, 50 per page. Two callers read
 * that list and both use this file, so there is one query:
 *
 *   - the wp-admin table: Mlsimport_Activity_List_Table::prepare_items()
 *     (includes/class-mlsimport-activity-list-table.php) passes the filters it
 *     read from the page address;
 *   - the AI tool import-history (includes/mcp/mlsimport-mcp-history.php)
 *     passes the filters the AI sent.
 *
 * The query was moved here from the wp-admin table unchanged; the optional
 * date filters are the only addition.
 *
 * Public interface:
 *   mlsimport_activity_query( $args )   the matching rows and their total
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The actions Import History records, the only values its action filter
 * accepts (the screen's filter and the import-history AI tool).
 *
 * @return string[]
 */
function mlsimport_activity_actions(): array {
	return array( 'added', 'edited', 'deleted' );
}

/**
 * Read one page of Import History.
 *
 * Step by step:
 * 1. Rows older than 30 days are never shown (they are pruned daily anyway).
 * 2. Build the WHERE clause from the filters; every value is prepared. The
 *    table name comes only from mlsimport_activity_table_name() and the sort
 *    direction is one of two fixed words, so both are safe to interpolate.
 * 3. Count the matching rows (for the pager).
 * 4. Fetch the requested page.
 *
 * @param array $args {
 *     Every key is optional.
 *
 *     @type string $action    'added' | 'edited' | 'deleted'; anything else = all.
 *     @type int    $task_id   Import Task post id; 0 = all.
 *     @type string $search    Part of the MLS #, the ListingKey or the post id.
 *     @type string $date_from First day to include, 'Y-m-d' (site time).
 *     @type string $date_to   Last day to include, 'Y-m-d' (site time).
 *     @type string $order     'ASC' | 'DESC' (default DESC = newest first).
 *     @type int    $page      Page number, from 1.
 *     @type int    $per_page  Rows per page (default 50).
 * }
 * @return array{total:int, rows:array<int, array<string, mixed>>}
 */
function mlsimport_activity_query( array $args ): array {
	global $wpdb;

	$table    = mlsimport_activity_table_name();
	$action   = (string) ( $args['action'] ?? '' );
	$task_id  = (int) ( $args['task_id'] ?? 0 );
	$search   = (string) ( $args['search'] ?? '' );
	$order    = 'ASC' === ( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
	$per_page = max( 1, (int) ( $args['per_page'] ?? 50 ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

	// Step 1: 30-day cutoff (WP local time).
	$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );

	// Step 2: the WHERE clause.
	$where = $wpdb->prepare( 'WHERE created_at >= %s', $cutoff );

	if ( in_array( $action, mlsimport_activity_actions(), true ) ) {
		$where .= $wpdb->prepare( ' AND action = %s', $action );
	}

	if ( $task_id > 0 ) {
		$where .= $wpdb->prepare( ' AND import_item_id = %d', $task_id );
	}

	// Match the search term against the MLS #, the ListingKey, or the numeric Listing ID.
	if ( '' !== $search ) {
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= $wpdb->prepare( ' AND ( listing_mls_id LIKE %s OR listing_key LIKE %s OR CAST(listing_id AS CHAR) LIKE %s )', $like, $like, $like );
	}

	// Optional whole days, in the same site-local time the rows are stored in.
	if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args['date_from'] ?? '' ) ) ) {
		$where .= $wpdb->prepare( ' AND created_at >= %s', $args['date_from'] . ' 00:00:00' );
	}
	if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args['date_to'] ?? '' ) ) ) {
		$where .= $wpdb->prepare( ' AND created_at <= %s', $args['date_to'] . ' 23:59:59' );
	}

	// Step 3: total for the pager.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

	// Step 4: the page. Table name and order are fixed words — safe to interpolate.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql  = $wpdb->prepare(
		"SELECT * FROM {$table} {$where} ORDER BY created_at {$order} LIMIT %d OFFSET %d",
		$per_page,
		( $page - 1 ) * $per_page
	);
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $sql, ARRAY_A );

	return array(
		'total' => $total,
		'rows'  => $rows ? $rows : array(),
	);
}
