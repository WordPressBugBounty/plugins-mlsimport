<?php
/**
 * AI tools (MCP): Listing Changes.
 *
 * Each function here is the body of one AI tool. A tool is a thin wrapper
 * around the code of the Listing Changes page (wp-admin):
 *   - listing-changes reads Mlsimport_Listing_Events::query(), the query the
 *     Listing Changes table uses.
 *   - listing-history reads Mlsimport_Listing_Events::for_listing().
 *
 * Event times are stored in UTC and reported in the site's time zone, as the
 * wp-admin table shows them.
 *
 * The daily email's settings live in mlsimport-mcp-notifications.php.
 *
 * Public interface:
 *   mlsimport_mcp_event_tools()                           the tools of this file
 *   mlsimport_mcp_event_row( $row )                       one event as a tool reports it
 *   mlsimport_mcp_listing_changes( $input )               tool: listing-changes
 *   mlsimport_mcp_listing_history( $input )               tool: listing-history
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
function mlsimport_mcp_event_tools(): array {
	$text   = array( 'type' => 'string' );
	$mls_id = array(
		'type'        => 'integer',
		'description' => 'The MLS id of one of the site\'s MLS Connections, from list-mls-connections.',
	);
	$day    = array(
		'type'        => 'string',
		'description' => 'A day as YYYY-MM-DD.',
	);

	return array(
		'listing-changes'              => array(
			'label'       => 'Listing Changes',
			'description' => 'Reads the changes recorded on listings: new listing, price change, status change, removed (under contract or off market), sold, back on market, agent change, content change. Newest first, 50 per page.',
			'input'       => array(
				'event_type' => array(
					'type' => 'string',
					'enum' => array_keys( Mlsimport_Listing_Events::type_labels() ),
				),
				'task_id'    => array(
					'type'        => 'integer',
					'description' => 'Only the listings of this import task.',
				),
				'search'     => array(
					'type'        => 'string',
					'description' => 'Part of the MLS number or the ListingKey.',
				),
				'date_from'  => $day,
				'date_to'    => $day,
				'page'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_listing_changes',
			'changes'     => false,
		),
		'listing-history'              => array(
			'label'       => 'History of one listing',
			'description' => 'Reads every recorded change of one listing, newest first. Needs the MLS id and the ListingKey; both are in the rows of listing-changes.',
			'input'       => array(
				'mls_id'      => $mls_id,
				'listing_key' => $text,
			),
			'required'    => array( 'mls_id', 'listing_key' ),
			'callback'    => 'mlsimport_mcp_listing_history',
			'changes'     => false,
		),
	);
}

/**
 * One listing change as a tool reports it.
 *
 * @param array $row A row of the events table.
 * @return array The change, with its time in the site's time zone.
 */
function mlsimport_mcp_event_row( array $row ): array {
	return array(
		'time'        => get_date_from_gmt( (string) $row['event_at'], 'Y-m-d H:i' ),
		'event_type'  => (string) $row['event_type'],
		'old_value'   => (string) $row['old_value'],
		'new_value'   => (string) $row['new_value'],
		'mls_number'  => (string) $row['listing_id'],
		'listing_key' => (string) $row['listing_key'],
		'mls_id'      => (int) $row['mls_id'],
		'post_id'     => (int) $row['post_id'],
		'task_id'     => (int) $row['task_id'],
	);
}

/**
 * Tool listing-changes: one page of Listing Changes.
 *
 * @param array $input event_type, task_id, search, date_from, date_to, page.
 * @return array The total, the page and the rows.
 */
function mlsimport_mcp_listing_changes( array $input ): array {
	$page = max( 1, (int) ( $input['page'] ?? 1 ) );

	// The Listing Changes table's own query.
	$result = Mlsimport_Listing_Events::query(
		array(
			'event_type' => isset( $input['event_type'] ) ? sanitize_key( (string) $input['event_type'] ) : '',
			'task_id'    => isset( $input['task_id'] ) ? absint( $input['task_id'] ) : 0,
			'search'     => isset( $input['search'] ) ? trim( sanitize_text_field( (string) $input['search'] ) ) : '',
			'date_from'  => (string) ( $input['date_from'] ?? '' ),
			'date_to'    => (string) ( $input['date_to'] ?? '' ),
			'page'       => $page,
		)
	);

	return array(
		'total'       => $result['total'],
		'page'        => $page,
		'total_pages' => (int) ceil( $result['total'] / 50 ),
		'rows'        => array_map( 'mlsimport_mcp_event_row', $result['rows'] ),
	);
}

/**
 * Tool listing-history: every recorded change of one listing.
 *
 * @param array $input {
 *     @type int    $mls_id      The MLS Connection the listing came from.
 *     @type string $listing_key The listing's ListingKey.
 * }
 * @return array The changes, newest first.
 */
function mlsimport_mcp_listing_history( array $input ): array {
	$rows = Mlsimport_Listing_Events::for_listing(
		isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0,
		isset( $input['listing_key'] ) ? sanitize_text_field( (string) $input['listing_key'] ) : ''
	);
	return array( 'rows' => array_map( 'mlsimport_mcp_event_row', $rows ) );
}
