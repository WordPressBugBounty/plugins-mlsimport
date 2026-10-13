<?php
/**
 * AI tools (MCP), standalone mode only: agents and visitors' saved searches.
 *
 * These tools exist only when the site runs in standalone mode (theme 990).
 * Each function is the body of one AI tool, a thin wrapper around the code
 * the site already uses:
 *   - list-agents / update-agent: the agent posts and
 *     Mlsimport_Agent_Metabox::save_fields(), the write of the agent edit
 *     screen. A field that is not named keeps its value (see
 *     mlsimport_mcp_catalog_overlay() in mlsimport-mcp-standalone-listing-edit.php).
 *   - list-saved-searches: the searches visitors saved to get email alerts,
 *     read with Mlsimport_Saved_Search::ids_by_status() and ::get().
 *   - deactivate-saved-search: Mlsimport_Saved_Search::deactivate(), the same
 *     call the visitor's unsubscribe link and the wp-admin row action make.
 *
 * A saved search carries a secret link token (it confirms and unsubscribes
 * without a login). The token is never returned.
 *
 * Public interface:
 *   mlsimport_mcp_standalone_people_tools()            the tools of this file
 *   mlsimport_mcp_list_agents()                        tool: list-agents
 *   mlsimport_mcp_update_agent( $input )               tool: update-agent
 *   mlsimport_mcp_list_saved_searches( $input )        tool: list-saved-searches
 *   mlsimport_mcp_deactivate_saved_search( $input )    tool: deactivate-saved-search
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
function mlsimport_mcp_standalone_people_tools(): array {
	$fields = array(
		'type'                 => 'object',
		'description'          => 'The fields to change, keyed by field name. A field you do not name keeps its value.',
		'additionalProperties' => true,
	);

	return array(
		'list-agents'             => array(
			'label'       => 'List the agents',
			'description' => 'Reads the agents of the site with their saved fields (name, email, phones, office ...).',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_agents',
			'changes'     => false,
		),
		'update-agent'            => array(
			'label'       => 'Change an agent',
			'description' => 'Changes one agent as its edit screen does: the fields (names as list-agents returns them), the name, the bio, the photo and the status. Only what you name changes.',
			'input'       => array(
				'agent_id' => array(
					'type'        => 'integer',
					'description' => 'The agent id, from list-agents.',
				),
				'fields'   => $fields,
			) + mlsimport_mcp_agent_post_inputs(),
			'required'    => array( 'agent_id' ),
			'callback'    => 'mlsimport_mcp_update_agent',
			'changes'     => true,
		),
		'list-saved-searches'     => array(
			'label'       => 'Saved searches of visitors',
			'description' => 'Reads the searches visitors saved to get email alerts: who (name, email), what they search for, whether the alert is active, and when the last email went out.',
			'input'       => array(
				'status' => array(
					'type'        => 'string',
					'enum'        => array( 'active', 'pending', 'unsubscribed' ),
					'description' => 'Default: active. "pending" = not confirmed by email yet.',
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_saved_searches',
			'changes'     => false,
		),
		'deactivate-saved-search' => array(
			'label'       => 'Stop the alerts of a saved search',
			'description' => 'Stops the email alerts of one saved search, as the visitor\'s own unsubscribe link does. The saved search is kept; it just stops sending.',
			'input'       => array(
				'search_id' => array(
					'type'        => 'integer',
					'description' => 'The saved search id, from list-saved-searches.',
				),
			),
			'required'    => array( 'search_id' ),
			'callback'    => 'mlsimport_mcp_deactivate_saved_search',
			'changes'     => true,
		),
	);
}

/**
 * The inputs an agent post itself takes (besides its catalog fields), shared
 * by update-agent and create-agent.
 *
 * @return array<string, array<string, mixed>>
 */
function mlsimport_mcp_agent_post_inputs(): array {
	return array(
		'name'     => array(
			'type'        => 'string',
			'description' => 'The agent\'s name (the post title).',
		),
		'bio'      => array(
			'type'        => 'string',
			'description' => 'The agent\'s bio (the post text).',
		),
		'photo_id' => array(
			'type'        => 'integer',
			'description' => 'The attachment id of the agent\'s photo (an image in the Media Library); 0 removes the photo.',
		),
		'status'   => array(
			'type' => 'string',
			'enum' => array( 'publish', 'draft' ),
		),
	);
}

/**
 * One agent as the tools report it: identity, bio, photo and catalog fields.
 *
 * @param int $agent_id Agent post id.
 * @return array
 */
function mlsimport_mcp_agent_view( int $agent_id ): array {
	$photo_id = (int) get_post_thumbnail_id( $agent_id );
	return array(
		'agent_id' => $agent_id,
		'name'     => get_the_title( $agent_id ),
		'status'   => (string) get_post_status( $agent_id ),
		'url'      => (string) get_permalink( $agent_id ),
		'bio'      => (string) get_post_field( 'post_content', $agent_id ),
		'photo'    => $photo_id > 0 ? array(
			'id'  => $photo_id,
			'url' => (string) wp_get_attachment_url( $photo_id ),
		) : null,
		'fields'   => mlsimport_mcp_catalog_values( 'Mlsimport_Agent_Metabox', $agent_id ),
	);
}

/**
 * Write the agent post inputs (name, bio, photo, status) that were sent.
 *
 * Step by step:
 * 1. A photo must be an image attachment; 0 removes the photo. Checked
 *    before anything is written.
 * 2. Title, text and status go through wp_update_post; the photo through
 *    the featured image, which is where the agent card reads it.
 *
 * @param int   $agent_id Agent post id.
 * @param array $input    The tool input.
 * @return WP_Error|null The refusal, or null when written.
 */
function mlsimport_mcp_agent_post_write( int $agent_id, array $input ) {
	$photo_id = isset( $input['photo_id'] ) ? (int) $input['photo_id'] : null;
	if ( null !== $photo_id && $photo_id > 0 && ! wp_attachment_is_image( $photo_id ) ) {
		return new WP_Error(
			'mlsimport_photo_unknown',
			/* translators: %d: the attachment id sent. */
			sprintf( __( 'There is no image with the attachment id %d in the Media Library.', 'mlsimport' ), $photo_id )
		);
	}
	$post = array();
	if ( isset( $input['name'] ) && '' !== trim( (string) $input['name'] ) ) {
		$post['post_title'] = sanitize_text_field( (string) $input['name'] );
	}
	if ( isset( $input['bio'] ) ) {
		$post['post_content'] = wp_kses_post( (string) $input['bio'] );
	}
	if ( isset( $input['status'] ) && in_array( $input['status'], array( 'publish', 'draft' ), true ) ) {
		$post['post_status'] = $input['status'];
	}
	if ( ! empty( $post ) ) {
		wp_update_post( wp_slash( array( 'ID' => $agent_id ) + $post ) );
	}
	if ( null !== $photo_id ) {
		if ( $photo_id > 0 ) {
			set_post_thumbnail( $agent_id, $photo_id );
		} else {
			delete_post_thumbnail( $agent_id );
		}
	}
	return null;
}

/**
 * Tool list-agents: the agents with their saved fields.
 *
 * @return array The agents.
 */
function mlsimport_mcp_list_agents(): array {
	$agent_ids = get_posts(
		array(
			'post_type'   => 'mlsimport_agent',
			'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	$agents = array();
	foreach ( $agent_ids as $agent_id ) {
		$agents[] = mlsimport_mcp_agent_view( (int) $agent_id );
	}
	return array( 'agents' => $agents );
}

/**
 * Tool update-agent: change one agent as its edit screen does.
 *
 * @param array $input agent_id, fields, name, bio, photo_id, status.
 * @return array|WP_Error The agent as saved, or the refusal.
 */
function mlsimport_mcp_update_agent( array $input ) {
	$agent_id = isset( $input['agent_id'] ) ? (int) $input['agent_id'] : 0;
	$refusal  = mlsimport_mcp_require_post( $agent_id, 'mlsimport_agent', __( 'That is not an agent. Find the agent id with list-agents.', 'mlsimport' ) );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 1: the saved values with the named fields laid over them.
	$fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
	$values = mlsimport_mcp_catalog_overlay( 'Mlsimport_Agent_Metabox', $agent_id, $fields );
	if ( is_wp_error( $values ) ) {
		return $values;
	}

	// Step 2: the post inputs (checked first), then the edit screen's own write.
	$refusal = mlsimport_mcp_agent_post_write( $agent_id, $input );
	if ( null !== $refusal ) {
		return $refusal;
	}
	if ( ! empty( $fields ) ) {
		Mlsimport_Agent_Metabox::save_fields( $agent_id, $values );
	}

	return mlsimport_mcp_agent_view( $agent_id );
}

/**
 * Tool list-saved-searches: the visitors' saved searches of one status.
 *
 * @param array $input {
 *     @type string $status 'active' (default) | 'pending' | 'unsubscribed'.
 * }
 * @return array The saved searches, without their link tokens.
 */
function mlsimport_mcp_list_saved_searches( array $input ): array {
	$status = isset( $input['status'] ) && in_array( $input['status'], array( 'pending', 'unsubscribed' ), true ) ? (string) $input['status'] : 'active';

	$searches = array();
	foreach ( Mlsimport_Saved_Search::ids_by_status( $status ) as $search_id ) {
		$search = Mlsimport_Saved_Search::get( (int) $search_id );
		if ( null === $search ) {
			continue;
		}
		// The link token is a secret of the visitor: never returned.
		unset( $search['token'] );
		$searches[] = $search;
	}

	return array(
		'status'   => $status,
		'total'    => count( $searches ),
		'searches' => $searches,
	);
}

/**
 * Tool deactivate-saved-search: stop the alerts of one saved search.
 *
 * @param array $input {
 *     @type int $search_id The saved search.
 * }
 * @return array|WP_Error The saved search with its new status, or the refusal.
 */
function mlsimport_mcp_deactivate_saved_search( array $input ) {
	$search_id = isset( $input['search_id'] ) ? (int) $input['search_id'] : 0;
	if ( null === Mlsimport_Saved_Search::get( $search_id ) ) {
		return new WP_Error( 'mlsimport_search_not_found', __( 'That is not a saved search. Find the id with list-saved-searches.', 'mlsimport' ) );
	}

	// The unsubscribe link's own call.
	Mlsimport_Saved_Search::deactivate( $search_id );

	return array(
		'search_id' => $search_id,
		'status'    => (string) get_post_meta( $search_id, 'mlsimport_ss_status', true ),
	);
}
