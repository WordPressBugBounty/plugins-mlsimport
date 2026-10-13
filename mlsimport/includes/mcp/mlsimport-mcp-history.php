<?php
/**
 * AI tools (MCP): Import History and the plugin's log files.
 *
 * Each function here is the body of one AI tool. A tool is a thin wrapper
 * around what wp-admin already shows:
 *   - import-history reads mlsimport_activity_query()
 *     (includes/mlsimport-activity-query.php), the query the Import History
 *     table uses: one row per listing added, edited or deleted, last 30 days.
 *   - activity-summary reads mlsimport_get_activity_summary()
 *     (includes/mlsimport-activity-log.php), the numbers of the wp-admin
 *     activity banner.
 *   - read-log reads one of the daily log files the plugin writes when
 *     "system logs" are on. The file path comes from mlsimport_log_file_path()
 *     (mlsimport.php), the same function the log writer uses.
 *
 * Import History is about listings only. What the AI itself changed is in
 * AI Activity (wp-admin, Tools tab), not here.
 *
 * Public interface:
 *   mlsimport_mcp_history_tools()              the tools of this file
 *   mlsimport_mcp_import_history( $input )     tool: import-history
 *   mlsimport_mcp_activity_summary( $input )   tool: activity-summary
 *   mlsimport_mcp_read_log( $input )           tool: read-log
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file, as the tool list expects them.
 *
 * Each entry: label, description, input, required, callback, changes. See
 * mlsimport_mcp_ability_definitions() in mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_history_tools(): array {
	$day = array(
		'type'        => 'string',
		'description' => 'A day as YYYY-MM-DD.',
	);

	return array(
		'import-history'   => array(
			'label'       => 'Import History',
			'description' => 'Reads Import History: which listings were added, edited or deleted, by which import task, in the last 30 days. Newest first, 50 per page.',
			'input'       => array(
				'action'    => array(
					'type' => 'string',
					'enum' => mlsimport_activity_actions(),
				),
				'task_id'   => array(
					'type'        => 'integer',
					'description' => 'Only the listings of this import task.',
				),
				'search'    => array(
					'type'        => 'string',
					'description' => 'Part of the MLS number, the ListingKey or the WordPress post id.',
				),
				'date_from' => $day,
				'date_to'   => $day,
				'page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_import_history',
			'changes'     => false,
		),
		'activity-summary' => array(
			'label'       => 'Activity summary',
			'description' => 'Reads how many listings were added, edited and deleted in the last hours (24 by default), in total and per import task.',
			'input'       => array(
				'hours' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 720,
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_activity_summary',
			'changes'     => false,
		),
		'read-log'         => array(
			'label'       => 'Read a log',
			'description' => 'Reads the last lines of one of the plugin\'s daily logs: "import" (manual imports), "cron" (the hourly sync) or "delete" (deleted listings). Logs exist only for days on which "system logs" were on (get-tool-switches).',
			'input'       => array(
				'log'   => array(
					'type' => 'string',
					'enum' => array( 'import', 'cron', 'delete' ),
				),
				'date'  => array(
					'type'        => 'string',
					'description' => 'The day as YYYY-MM-DD (UTC). Default: today.',
				),
				'lines' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 300,
					'description' => 'How many lines from the end (default 100).',
				),
			),
			'required'    => array( 'log' ),
			'callback'    => 'mlsimport_mcp_read_log',
			'changes'     => false,
		),
	);
}

/**
 * Tool import-history: one page of Import History.
 *
 * @param array $input action, task_id, search, date_from, date_to, page.
 * @return array The total, the page and the rows.
 */
function mlsimport_mcp_import_history( array $input ): array {
	$page = max( 1, (int) ( $input['page'] ?? 1 ) );

	// The Import History table's own query.
	$result = mlsimport_activity_query(
		array(
			'action'    => isset( $input['action'] ) ? sanitize_key( (string) $input['action'] ) : '',
			'task_id'   => isset( $input['task_id'] ) ? absint( $input['task_id'] ) : 0,
			'search'    => isset( $input['search'] ) ? trim( sanitize_text_field( (string) $input['search'] ) ) : '',
			'date_from' => (string) ( $input['date_from'] ?? '' ),
			'date_to'   => (string) ( $input['date_to'] ?? '' ),
			'page'      => $page,
		)
	);

	// The columns the wp-admin table shows.
	$rows = array();
	foreach ( $result['rows'] as $row ) {
		$rows[] = array(
			'time'       => (string) $row['created_at'],
			'action'     => (string) $row['action'],
			'mls_number' => (string) $row['listing_mls_id'],
			'title'      => (string) $row['listing_title'],
			'url'        => (string) $row['listing_url'],
			'status'     => (string) $row['listing_status'],
			'post_id'    => (int) $row['listing_id'],
			'task_id'    => (int) $row['import_item_id'],
			'task'       => (string) $row['import_item_title'],
			'source'     => (string) $row['source'],
			'reason'     => (string) $row['reason_code'],
		);
	}

	return array(
		'total'       => $result['total'],
		'page'        => $page,
		'total_pages' => (int) ceil( $result['total'] / 50 ),
		'rows'        => $rows,
	);
}

/**
 * Tool activity-summary: listings added, edited and deleted in the last hours.
 *
 * @param array $input {
 *     @type int $hours The window in hours (default 24).
 * }
 * @return array Totals and the same counts per import task.
 */
function mlsimport_mcp_activity_summary( array $input ): array {
	$hours   = min( 720, max( 1, (int) ( $input['hours'] ?? 24 ) ) );
	$summary = mlsimport_get_activity_summary( $hours );

	// One row per import task instead of a map keyed by task id.
	$by_task = array();
	foreach ( $summary['by_item'] as $task_id => $counts ) {
		$by_task[] = array( 'task_id' => (int) $task_id ) + $counts;
	}

	return array(
		'hours'   => $hours,
		'totals'  => $summary['totals'],
		'by_task' => $by_task,
	);
}

/**
 * Tool read-log: the last lines of one daily log file.
 *
 * Step by step:
 * 1. The log kind is one of three fixed words and the day must look like a
 *    date, so the file path can never leave the logs folder.
 * 2. The path is built by the log writer's own function.
 * 3. Only the end of the file is read; a log can be large.
 *
 * @param array $input log, date, lines.
 * @return array|WP_Error The lines, or the refusal.
 */
function mlsimport_mcp_read_log( array $input ) {
	// Step 1: validate the kind and the day.
	$log  = (string) ( $input['log'] ?? '' );
	$date = (string) ( $input['date'] ?? gmdate( 'Y-m-d' ) );
	if ( ! in_array( $log, array( 'import', 'cron', 'delete' ), true ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return new WP_Error( 'mlsimport_log_unknown', __( 'Name the log (import, cron or delete) and the day as YYYY-MM-DD.', 'mlsimport' ) );
	}

	// Step 2: the writer's path for that kind and day.
	$path = mlsimport_log_file_path( 'import' === $log ? 'normal' : $log, $date );
	if ( ! is_readable( $path ) ) {
		return array(
			'log'   => $log,
			'date'  => $date,
			'lines' => array(),
			'note'  => __( 'There is no log for that day. Logs are written only while "system logs" are on.', 'mlsimport' ),
		);
	}

	// Step 3: the last lines, read from at most the last 200 KB of the file.
	$size  = (int) filesize( $path );
	$tail  = (string) file_get_contents( $path, false, null, max( 0, $size - 200000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	$lines = preg_split( '/\r\n|\n|\r/', trim( $tail ) );
	$count = min( 300, max( 1, (int) ( $input['lines'] ?? 100 ) ) );

	return array(
		'log'   => $log,
		'date'  => $date,
		'lines' => array_slice( $lines, -$count ),
	);
}
