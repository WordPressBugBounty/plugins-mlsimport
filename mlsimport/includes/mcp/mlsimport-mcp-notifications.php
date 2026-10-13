<?php
/**
 * AI tools (MCP): the daily listing-changes email.
 *
 * Each function here is the body of one AI tool, a thin wrapper around the
 * code of the Notifications section of the Listing Changes page (wp-admin):
 *   - get-notification-settings reads Mlsimport_Listing_Notification_Settings
 *     (get() for the global part, connection() for each MLS).
 *   - update-notification-settings saves the global part with save(); a part
 *     that is not named keeps its value.
 *   - update-notification-filter saves one MLS's switch (save()) and filter
 *     (save_filter()), or forgets the filter (reset_filter()).
 *   - send-digest-now runs Mlsimport_Listing_Notification_Digest::run_for(),
 *     the daily job, for one MLS right away.
 *
 * Reading the listing changes themselves lives in mlsimport-mcp-events.php.
 *
 * Public interface:
 *   mlsimport_mcp_notification_tools()                    the tools of this file
 *   mlsimport_mcp_get_notification_settings()             tool: get-notification-settings
 *   mlsimport_mcp_update_notification_settings( $input )  tool: update-notification-settings
 *   mlsimport_mcp_update_notification_filter( $input )    tool: update-notification-filter
 *   mlsimport_mcp_send_digest_now( $input )               tool: send-digest-now
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
function mlsimport_mcp_notification_tools(): array {
	$strings = array(
		'type'  => 'array',
		'items' => array( 'type' => 'string' ),
	);
	$mls_id  = array(
		'type'        => 'integer',
		'description' => 'The MLS id of one of the site\'s MLS Connections, from list-mls-connections.',
	);

	return array(
		'get-notification-settings'    => array(
			'label'       => 'Listing change email settings',
			'description' => 'Reads the settings of the daily listing-changes email: which kinds of change are mailed, to which addresses and, per MLS Connection, whether it is on and its filter.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_get_notification_settings',
			'changes'     => false,
		),
		'update-notification-settings' => array(
			'label'       => 'Change the listing change email settings',
			'description' => 'Changes which kinds of change are mailed and to which addresses (one setup for the whole site). A part you do not name keeps its value. The kinds are listed by get-notification-settings.',
			'input'       => array(
				'event_types' => $strings,
				'emails'      => $strings,
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_update_notification_settings',
			'changes'     => true,
		),
		'update-notification-filter'   => array(
			'label'       => 'Change the email settings of one MLS',
			'description' => 'For one MLS Connection: turns the daily email on or off, and sets which listings count (cities, neighborhoods, zip codes, property types, statuses, price range). Name a filter and it replaces the whole saved filter of that MLS. reset_filter: true goes back to the filter taken from the import tasks.',
			'input'       => array(
				'mls_id'       => $mls_id,
				'enabled'      => array( 'type' => 'boolean' ),
				'filter'       => array(
					'type'        => 'object',
					'description' => 'The filter: city, neighborhood, zip, property_type, status (each a list of text), min_price, max_price (numbers; 0 = no limit). A part left out is "no restriction".',
					'properties'  => array(
						'city'          => $strings,
						'neighborhood'  => $strings,
						'zip'           => $strings,
						'property_type' => $strings,
						'status'        => $strings,
						'min_price'     => array( 'type' => 'number' ),
						'max_price'     => array( 'type' => 'number' ),
					),
				),
				'reset_filter' => array( 'type' => 'boolean' ),
			),
			'required'    => array( 'mls_id' ),
			'callback'    => 'mlsimport_mcp_update_notification_filter',
			'changes'     => true,
		),
		'send-digest-now'              => array(
			'label'       => 'Send the listing change email now',
			'description' => 'Sends the listing-changes email of one MLS Connection right now instead of waiting for the daily job. It mails the changes since the last email; when there are none, or the email is off for that MLS, nothing is sent. This sends real email.',
			'input'       => array( 'mls_id' => $mls_id ),
			'required'    => array( 'mls_id' ),
			'callback'    => 'mlsimport_mcp_send_digest_now',
			'changes'     => true,
		),
	);
}

/**
 * Tool get-notification-settings: the daily email's settings.
 *
 * @return array The kinds that can be mailed, the ticked kinds, the addresses,
 *               and each MLS Connection's switch and filter.
 */
function mlsimport_mcp_get_notification_settings(): array {
	$settings = Mlsimport_Listing_Notification_Settings::get();

	// One row per MLS Connection: the saved filter, else the one taken from
	// its import tasks (what the wp-admin page shows too).
	$connections = array();
	foreach ( Mlsimport_Connections::all() as $mls_id => $record ) {
		$rules = Mlsimport_Listing_Notification_Settings::connection( (int) $mls_id );
		unset( $rules['last_event_id'] );
		$connections[] = array(
			'mls_id'   => (int) $mls_id,
			'mls_name' => (string) $record['mls_name'],
		) + $rules;
	}

	return array(
		'available_event_types' => Mlsimport_Listing_Notification_Matcher::kind_labels(),
		'event_types'           => $settings['event_types'],
		'emails'                => $settings['emails'],
		'connections'           => $connections,
	);
}

/**
 * Tool update-notification-settings: the kinds mailed and the addresses.
 *
 * @param array $input {
 *     @type string[] $event_types The kinds to mail (replaces the list).
 *     @type string[] $emails      The addresses (replaces the list).
 * }
 * @return array The settings as saved (see get-notification-settings).
 */
function mlsimport_mcp_update_notification_settings( array $input ): array {
	// The page's save replaces both lists, so a list that was not named is
	// sent back unchanged.
	$current = Mlsimport_Listing_Notification_Settings::get();
	Mlsimport_Listing_Notification_Settings::save(
		array(
			'event_types' => isset( $input['event_types'] ) ? (array) $input['event_types'] : $current['event_types'],
			'emails'      => isset( $input['emails'] ) ? (array) $input['emails'] : $current['emails'],
		)
	);
	return mlsimport_mcp_get_notification_settings();
}

/**
 * Tool update-notification-filter: one MLS Connection's switch and filter.
 *
 * Step by step:
 * 1. The MLS must be one of the site's MLS Connections.
 * 2. The switch, when named: saved through the page's save(), together with
 *    the two global lists as they are (save() replaces them).
 * 3. The filter: reset_filter forgets it; a named filter replaces it.
 *
 * @param array $input mls_id, enabled, filter, reset_filter.
 * @return array|WP_Error The settings as saved, or the refusal.
 */
function mlsimport_mcp_update_notification_filter( array $input ) {
	// Step 1: a known MLS Connection.
	$mls_id  = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$refusal = mlsimport_mcp_require_connection( $mls_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: the on / off switch.
	if ( isset( $input['enabled'] ) ) {
		$current = Mlsimport_Listing_Notification_Settings::get();
		Mlsimport_Listing_Notification_Settings::save(
			array(
				'event_types' => $current['event_types'],
				'emails'      => $current['emails'],
				'connections' => array( $mls_id => empty( $input['enabled'] ) ? array() : array( 'enabled' => 1 ) ),
			)
		);
	}

	// Step 3: the filter.
	if ( ! empty( $input['reset_filter'] ) ) {
		Mlsimport_Listing_Notification_Settings::reset_filter( $mls_id );
	} elseif ( isset( $input['filter'] ) && is_array( $input['filter'] ) ) {
		Mlsimport_Listing_Notification_Settings::save_filter( $mls_id, $input['filter'] );
	}

	return mlsimport_mcp_get_notification_settings();
}

/**
 * Tool send-digest-now: run the daily email job for one MLS Connection now.
 *
 * @param array $input {
 *     @type int $mls_id The MLS Connection.
 * }
 * @return array|WP_Error "done", or the refusal.
 */
function mlsimport_mcp_send_digest_now( array $input ) {
	$mls_id  = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$refusal = mlsimport_mcp_require_connection( $mls_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// The daily job's own step for one MLS.
	$record = Mlsimport_Connections::get( $mls_id );
	Mlsimport_Listing_Notification_Digest::run_for( $mls_id, (string) $record['mls_name'] );

	return array(
		'done' => true,
		'note' => __( 'The daily email job ran for this MLS. An email goes out only when the email is on for the MLS, an address is saved, and there are matching changes since the last email.', 'mlsimport' ),
	);
}
