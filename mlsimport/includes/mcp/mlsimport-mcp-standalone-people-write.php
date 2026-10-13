<?php
/**
 * AI tools (MCP), standalone mode only: adding and removing agents, removing
 * a visitor's saved search, and sending the saved-search alerts now.
 *
 * The tool create-agent makes an agent post the way "Add New" on the Agents screen
 * does (name, bio, photo, catalog fields of Mlsimport_Agent_Metabox).
 * delete-agent deletes one for good and hands the listings that pointed at
 * it back to the MLS feed's own agent, so no lead is lost. delete-saved-
 * search is the Delete row action of the Saved Searches list.
 * send-saved-search-alert runs the daily alert job now, or mails one search
 * now. Every delete needs two calls (mlsimport-mcp-confirm.php). The agent
 * view and the agent post inputs are shared with update-agent
 * (mlsimport-mcp-standalone-people.php).
 *
 * Public interface:
 *   mlsimport_mcp_standalone_people_write_tools()   the tools of this file
 *   mlsimport_mcp_create_agent( $input )            tool: create-agent
 *   mlsimport_mcp_delete_agent( $input )            tool: delete-agent
 *   mlsimport_mcp_delete_saved_search( $input )     tool: delete-saved-search
 *   mlsimport_mcp_send_saved_search_alert( $input ) tool: send-saved-search-alert
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
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_standalone_people_write_tools(): array {
	$two_calls = ' Needs two calls: the first call deletes nothing and returns what would be deleted and a confirmation_code; tell the client, and only after they confirm call again with the same inputs and that code.';
	$code      = array(
		'confirmation_code' => array(
			'type'        => 'string',
			'description' => 'The confirmation_code returned by the first call. Leave out on the first call.',
		),
	);
	$agent_id  = array(
		'type'        => 'integer',
		'description' => 'The agent id, from list-agents.',
	);
	$search_id = array(
		'type'        => 'integer',
		'description' => 'The saved search id, from list-saved-searches.',
	);

	return array(
		'create-agent'            => array(
			'label'       => 'Add an agent',
			'description' => 'Adds an agent to the site, as "Add New" on the Agents screen does: the name is required; the bio, the photo (an attachment id of an image in the Media Library), the status (published unless draft) and the fields of the agent edit screen (names as list-agents returns them) are optional.',
			'input'       => array(
				'fields' => array(
					'type'                 => 'object',
					'description'          => 'The agent fields, keyed by field name (ListAgentEmail, ListAgentMobilePhone, JobTitle, ListOfficeName ...).',
					'additionalProperties' => true,
				),
			) + mlsimport_mcp_agent_post_inputs(),
			'required'    => array( 'name' ),
			'callback'    => 'mlsimport_mcp_create_agent',
			'changes'     => true,
		),
		'delete-agent'            => array(
			'label'       => 'Delete an agent',
			'description' => 'Deletes one agent for good; it cannot be restored. Listings assigned to that agent go back to showing the MLS feed\'s own listing agent, and their leads go to the site\'s lead recipient.' . $two_calls,
			'input'       => array( 'agent_id' => $agent_id ) + $code,
			'required'    => array( 'agent_id' ),
			'callback'    => 'mlsimport_mcp_delete_agent',
			'changes'     => true,
			'destructive' => true,
		),
		'delete-saved-search'     => array(
			'label'       => 'Delete a saved search',
			'description' => 'Deletes one visitor\'s saved search for good, as the Delete action of the Saved Searches list does; the visitor gets no more alerts and nothing of theirs is kept. To only stop the alerts and keep the record, use deactivate-saved-search.' . $two_calls,
			'input'       => array( 'search_id' => $search_id ) + $code,
			'required'    => array( 'search_id' ),
			'callback'    => 'mlsimport_mcp_delete_saved_search',
			'changes'     => true,
			'destructive' => true,
		),
		'send-saved-search-alert' => array(
			'label'       => 'Send the saved search alerts now',
			'description' => 'With search_id: mails that one saved search now with the listings added or changed since the last daily run; nothing is sent when none match. Without: runs the daily alert job now for every active saved search (the sends are queued, like the daily run).',
			'input'       => array( 'search_id' => $search_id ),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_send_saved_search_alert',
			'changes'     => true,
		),
	);
}

/**
 * Tool create-agent: add an agent.
 *
 * Step by step:
 * 1. The name is required; the fields must be catalog fields (checked with
 *    the overlay helper on an empty post, so an unknown name is refused
 *    before anything exists).
 * 2. Make the post, then write the post inputs and the fields the same way
 *    update-agent does. A refused photo removes the post again.
 *
 * @param array $input name, bio, photo_id, status, fields.
 * @return array|WP_Error The new agent (as list-agents shows it), or the refusal.
 */
function mlsimport_mcp_create_agent( array $input ) {
	$name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
	if ( '' === $name ) {
		return new WP_Error( 'mlsimport_agent_name_missing', __( 'An agent needs a name.', 'mlsimport' ) );
	}
	$fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
	$values = mlsimport_mcp_catalog_overlay( 'Mlsimport_Agent_Metabox', 0, $fields );
	if ( is_wp_error( $values ) ) {
		return $values;
	}
	$agent_id = wp_insert_post(
		array(
			'post_type'   => 'mlsimport_agent',
			'post_title'  => $name,
			'post_status' => isset( $input['status'] ) && 'draft' === $input['status'] ? 'draft' : 'publish',
		),
		true
	);
	if ( is_wp_error( $agent_id ) ) {
		return $agent_id;
	}
	$refusal = mlsimport_mcp_agent_post_write(
		(int) $agent_id,
		array_diff_key(
			$input,
			array(
				'name'   => 1,
				'status' => 1,
			)
		)
	);
	if ( null !== $refusal ) {
		wp_delete_post( (int) $agent_id, true );
		return $refusal;
	}
	Mlsimport_Agent_Metabox::save_fields( (int) $agent_id, $values );
	return mlsimport_mcp_agent_view( (int) $agent_id );
}

/**
 * The listings assigned to one agent (the Lead Agent picker's link).
 *
 * @param int $agent_id Agent post id.
 * @return int[] Property post ids.
 */
function mlsimport_mcp_agent_listings( int $agent_id ): array {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'   => 'mlsimport_property',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => 'mlsimport_list_agent_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => (string) $agent_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		)
	);
}

/**
 * Tool delete-agent: delete one agent for good (two calls).
 *
 * @param array $input agent_id, confirmation_code.
 * @return array|WP_Error The preview, "deleted", or the refusal.
 */
function mlsimport_mcp_delete_agent( array $input ) {
	$agent_id = isset( $input['agent_id'] ) ? (int) $input['agent_id'] : 0;
	$refusal  = mlsimport_mcp_require_post( $agent_id, 'mlsimport_agent', __( 'That is not an agent. Find the agent id with list-agents.', 'mlsimport' ) );
	if ( null !== $refusal ) {
		return $refusal;
	}
	$listings = mlsimport_mcp_agent_listings( $agent_id );
	$pending  = mlsimport_mcp_confirm_or_preview(
		'delete-agent',
		$input,
		sprintf(
			/* translators: 1: agent name, 2: agent id, 3: number of listings. */
			__( 'The agent "%1$s" (%2$d) is deleted for good; it cannot be restored. %3$d listings assigned to this agent go back to the MLS feed\'s listing agent.', 'mlsimport' ),
			get_the_title( $agent_id ),
			$agent_id,
			count( $listings )
		)
	);
	if ( null !== $pending ) {
		return $pending;
	}
	// The listings first, so none keeps pointing at a post that is gone.
	foreach ( $listings as $post_id ) {
		Mlsimport_Property_Metabox::save_lead_agent( $post_id, Mlsimport_Property_Metabox::LEAD_AGENT_FEED );
	}
	if ( ! wp_delete_post( $agent_id, true ) ) {
		return new WP_Error( 'mlsimport_agent_not_deleted', __( 'The agent could not be deleted.', 'mlsimport' ) );
	}
	return array(
		'deleted'             => true,
		'agent_id'            => $agent_id,
		'listings_reassigned' => count( $listings ),
	);
}

/**
 * Tool delete-saved-search: delete one saved search for good (two calls).
 *
 * @param array $input search_id, confirmation_code.
 * @return array|WP_Error The preview, "deleted", or the refusal.
 */
function mlsimport_mcp_delete_saved_search( array $input ) {
	$search_id = isset( $input['search_id'] ) ? (int) $input['search_id'] : 0;
	$search    = Mlsimport_Saved_Search::get( $search_id );
	if ( null === $search ) {
		return new WP_Error( 'mlsimport_search_not_found', __( 'That is not a saved search. Find the id with list-saved-searches.', 'mlsimport' ) );
	}
	$pending = mlsimport_mcp_confirm_or_preview(
		'delete-saved-search',
		$input,
		sprintf(
			/* translators: 1: visitor name, 2: visitor email, 3: saved search id. */
			__( 'The saved search of %1$s (%2$s), id %3$d, is deleted for good; the visitor gets no more alerts.', 'mlsimport' ),
			$search['name'],
			$search['email'],
			$search_id
		)
	);
	if ( null !== $pending ) {
		return $pending;
	}
	if ( ! wp_delete_post( $search_id, true ) ) {
		return new WP_Error( 'mlsimport_search_not_deleted', __( 'The saved search could not be deleted.', 'mlsimport' ) );
	}
	return array(
		'deleted'   => true,
		'search_id' => $search_id,
	);
}

/**
 * Tool send-saved-search-alert: mail one saved search now, or run the daily
 * job now.
 *
 * @param array $input search_id (optional).
 * @return array|WP_Error What was sent or queued, or the refusal.
 */
function mlsimport_mcp_send_saved_search_alert( array $input ) {
	$search_id = isset( $input['search_id'] ) ? (int) $input['search_id'] : 0;
	if ( 0 === $search_id ) {
		Mlsimport_Saved_Search_Alerts::run_daily();
		$changed = count( (array) get_option( Mlsimport_Saved_Search_Alerts::DELTA, array() ) );
		return array(
			'changed_listings' => $changed,
			'queued'           => $changed > 0 ? count( Mlsimport_Saved_Search::ids_by_status( 'active' ) ) : 0,
			'note'             => $changed > 0 ? __( 'One send is queued per active saved search; each mails only when its criteria match the changed listings.', 'mlsimport' ) : __( 'No listing was added or changed since the last run, so nothing is sent.', 'mlsimport' ),
		);
	}
	$search = Mlsimport_Saved_Search::get( $search_id );
	if ( null === $search ) {
		return new WP_Error( 'mlsimport_search_not_found', __( 'That is not a saved search. Find the id with list-saved-searches.', 'mlsimport' ) );
	}
	if ( 'active' !== $search['status'] ) {
		return new WP_Error( 'mlsimport_search_not_active', sprintf( /* translators: %s: the saved search status. */ __( 'Only an active saved search is mailed; this one is %s.', 'mlsimport' ), $search['status'] ) );
	}
	$before = $search['last_sent'];
	Mlsimport_Saved_Search_Alerts::send_one( $search_id );
	$after = (string) get_post_meta( $search_id, 'mlsimport_ss_last_sent', true );
	return array(
		'search_id' => $search_id,
		'sent'      => '' !== $after && $after !== $before,
		'last_sent' => $after,
		'note'      => '' !== $after && $after !== $before ? '' : __( 'Nothing matched: no listing added or changed since the last daily run fits this search, so no email was sent.', 'mlsimport' ),
	);
}
