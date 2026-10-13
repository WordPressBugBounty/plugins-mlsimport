<?php
/**
 * AI tools (MCP): Import Tasks.
 *
 * Each function here is the body of one AI tool. A tool is a thin wrapper:
 * it reads its input, calls the plugin function that already does the work,
 * and returns that function's result. The task itself is created by
 * mlsimport_create_initial_import_item() (includes/mlsimport-onboarding.php),
 * the same function the setup wizard uses.
 *
 * Rule owned by this file: an Import Task's MLS is never guessed. With one
 * MLS Connection the MLS may be left out. With two or more it is required,
 * and the tool refuses so the AI asks the client which MLS.
 *
 * Cities and property types are stored the way the MLS spells them; that
 * rule lives in mlsimport_mcp_match_filter_values() (mlsimport-mcp-task-write.php).
 *
 * Public interface:
 *   mlsimport_mcp_task_tools()   the tools of this file
 *   mlsimport_mcp_create_task( $input )   tool: create-task
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file (Import Tasks), as the tool list expects them.
 *
 * Each entry: label, description, input, required, callback, changes. See
 * mlsimport_mcp_ability_definitions() in mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_task_tools(): array {
	$text    = array( 'type' => 'string' );
	$integer = array( 'type' => 'integer' );
	$strings = array(
		'type'  => 'array',
		'items' => array( 'type' => 'string' ),
	);

	return array(
		'create-task' => array(
			'label'       => 'Create an import task',
			'description' => 'Creates an import task: which listings to import. Needs an MLS connection (add-mls-connection). When the site has more than one MLS connection, mls_id is required: ask the client which MLS, never guess. Every filter of the task can be set here in filters, keyed by the names from list-filter-values (StandardStatus, PropertySubType, PostalCode ...); cities and property_types are shortcuts for the City and PropertyType filters. Values must be ones that MLS has.',
			'input'       => array(
				'title'          => $text,
				'cities'         => $strings,
				'property_types' => $strings,
				'filters'        => array(
					'type'                 => 'object',
					'description'          => 'Filters to set, keyed by the names from list-filter-values. A pick-list filter takes a list of values, a text filter takes text.',
					'additionalProperties' => true,
				),
				'select_all'     => array(
					'type'                 => 'object',
					'description'          => 'Turn "select all values" on (true) for a pick-list filter, keyed by filter name.',
					'additionalProperties' => array( 'type' => 'boolean' ),
				),
				'min_price'      => $integer,
				'max_price'      => $integer,
				'auto_sync'      => array(
					'type'        => 'boolean',
					'description' => 'Keep the imported listings updated by the hourly sync.',
				),
				'mls_id'         => $integer,
				'agent_id'       => array(
					'type'        => 'integer',
					'description' => 'The agent (post id) the imported listings are assigned to; 0 for none. On a standalone site the ids come from list-agents.',
				),
				'user_id'        => array(
					'type'        => 'integer',
					'description' => 'The WordPress user (id) who owns the imported listings.',
				),
			),
			'required'    => array( 'title' ),
			'callback'    => 'mlsimport_mcp_create_task',
			'changes'     => true,
		),
	);
}

/**
 * Check the agent and the owner user a task tool was sent.
 *
 * Step by step:
 * 1. An agent must be a published post of the theme's agent post type; 0
 *    (or an empty value) means no agent.
 * 2. A user must exist.
 * 3. Answer with the task meta to store, only for the inputs that were sent.
 *
 * @param array $input The tool input (agent_id, user_id).
 * @return array|WP_Error Meta key => value for the sent inputs, or the refusal.
 */
function mlsimport_mcp_task_people_input( array $input ) {
	global $mlsimport;
	$posted = array();
	if ( isset( $input['agent_id'] ) ) {
		$agent_id = (int) $input['agent_id'];
		$agent    = $agent_id > 0 ? get_post( $agent_id ) : null;
		$type     = $mlsimport->admin->env_data->get_agent_post_type();
		if ( $agent_id > 0 && ( ! $agent || $type !== $agent->post_type || 'publish' !== $agent->post_status ) ) {
			return new WP_Error(
				'mlsimport_agent_unknown',
				/* translators: %d: the agent id sent. */
				sprintf( __( 'There is no published agent with the id %d. On a standalone site list-agents has the ids.', 'mlsimport' ), $agent_id )
			);
		}
		$posted['mlsimport_item_agent'] = $agent_id > 0 ? (string) $agent_id : '';
	}
	if ( isset( $input['user_id'] ) ) {
		$user_id = (int) $input['user_id'];
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'mlsimport_user_unknown',
				/* translators: %d: the user id sent. */
				sprintf( __( 'There is no WordPress user with the id %d.', 'mlsimport' ), $user_id )
			);
		}
		$posted['mlsimport_item_property_user'] = (string) $user_id;
	}
	return $posted;
}

/**
 * Tool create-task: create an Import Task with the setup wizard's inputs.
 *
 * @param array $input {
 *     @type string   $title          Task title.
 *     @type string[] $cities         Cities to import from.
 *     @type string[] $property_types Property types to import.
 *     @type int      $min_price      Lowest price (default 0).
 *     @type int      $max_price      Highest price (default 10000000).
 *     @type bool     $auto_sync      Keep the listings updated by the hourly sync.
 *     @type int      $mls_id         The MLS Connection the task is for. Optional
 *                                    with one MLS Connection, required with more.
 * }
 * @return array|WP_Error The new task id and the MLS it is bound to, or the refusal.
 */
function mlsimport_mcp_create_task( array $input ) {
	$connections = Mlsimport_Connections::all();
	$mls_id      = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;

	// Step 1: a task needs an MLS Connection. Name the next step.
	if ( empty( $connections ) ) {
		return new WP_Error(
			'mlsimport_no_mls_connection',
			__( 'No MLS Connection yet. Add one with add-mls-connection first.', 'mlsimport' )
		);
	}

	// Step 2: a named MLS must be one of this site's MLS Connections.
	if ( $mls_id > 0 && ! isset( $connections[ $mls_id ] ) ) {
		return new WP_Error(
			'mlsimport_mls_unknown',
			sprintf(
				/* translators: %s: list of MLS Connections as "name (id)". */
				__( 'That MLS is not connected on this site. The MLS Connections are: %s.', 'mlsimport' ),
				mlsimport_mcp_connection_labels( $connections )
			)
		);
	}

	// Step 3: with two or more MLS Connections the MLS is never guessed.
	if ( 0 === $mls_id && count( $connections ) > 1 ) {
		return new WP_Error(
			'mlsimport_mls_required',
			sprintf(
				/* translators: %s: list of MLS Connections as "name (id)". */
				__( 'This site has more than one MLS Connection. Say which MLS the task is for: %s.', 'mlsimport' ),
				mlsimport_mcp_connection_labels( $connections )
			)
		);
	}

	// Step 4: cities, property types and every other filter are stored the
	// way the MLS spells them (the MLS matches the exact spelling); a value or
	// a filter the MLS does not have is refused before anything is created.
	$picked     = array(
		'City'         => isset( $input['cities'] ) ? array_filter( (array) $input['cities'], 'strlen' ) : array(),
		'PropertyType' => isset( $input['property_types'] ) ? array_filter( (array) $input['property_types'], 'strlen' ) : array(),
	);
	$filters    = isset( $input['filters'] ) && is_array( $input['filters'] ) ? $input['filters'] : array();
	$select_all = isset( $input['select_all'] ) && is_array( $input['select_all'] ) ? $input['select_all'] : array();
	if ( ! empty( $picked['City'] ) || ! empty( $picked['PropertyType'] ) || ! empty( $filters ) || ! empty( $select_all ) ) {
		$fields = mlsimport_mcp_task_filter_fields( $mls_id > 0 ? $mls_id : (int) array_key_first( $connections ) );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		foreach ( $picked as $name => $values ) {
			$picked[ $name ] = mlsimport_mcp_match_filter_values( $fields[ $name ] ?? array(), $name, $values );
			if ( is_wp_error( $picked[ $name ] ) ) {
				return $picked[ $name ];
			}
		}
		$checked = mlsimport_mcp_task_filters_posted( $fields, $filters, $select_all );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
	}

	// Step 5: the agent and the owner user, when named, must exist.
	$people = mlsimport_mcp_task_people_input( $input );
	if ( is_wp_error( $people ) ) {
		return $people;
	}

	// Step 6: hand the wizard function the same data its own form sends. The
	// price defaults are the wizard's defaults.
	$task_id = mlsimport_create_initial_import_item(
		array(
			'import_title'    => isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '',
			'property_status' => 'publish',
			'agent_id'        => $people['mlsimport_item_agent'] ?? '',
			'property_user'   => $people['mlsimport_item_property_user'] ?? '',
			'min_price'       => isset( $input['min_price'] ) ? (string) absint( $input['min_price'] ) : '0',
			'max_price'       => isset( $input['max_price'] ) ? (string) absint( $input['max_price'] ) : '10000000',
			'property_cities' => $picked['City'],
			'property_types'  => $picked['PropertyType'],
			'auto_update'     => empty( $input['auto_sync'] ) ? 0 : 1,
			'mls_id'          => $mls_id,
		)
	);

	// Step 7: WordPress could not create the task.
	if ( is_wp_error( $task_id ) || ! $task_id ) {
		return new WP_Error( 'mlsimport_task_not_created', __( 'The import task could not be created.', 'mlsimport' ) );
	}

	// Step 8: the other filters go on through update-task, the one place that
	// lays filters over a task's settings (they were checked in step 4).
	if ( ! empty( $filters ) || ! empty( $select_all ) ) {
		$saved = mlsimport_mcp_update_task(
			array(
				'task_id'    => (int) $task_id,
				'filters'    => $filters,
				'select_all' => $select_all,
			)
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
	}

	// Step 9: report the task and the MLS it is bound to for life.
	return array(
		'task_id' => (int) $task_id,
		'mls_id'  => mlsimport_task_mls_id( (int) $task_id ),
	);
}

/**
 * The MLS Connections as one readable list: "Stellar (103), Metro (205)".
 *
 * Used by refusals so the AI can ask the client which MLS they mean.
 *
 * @param array $connections Registry records keyed by mls_id.
 * @return string
 */
function mlsimport_mcp_connection_labels( array $connections ): string {
	$labels = array();
	foreach ( $connections as $mls_id => $record ) {
		$labels[] = sprintf( '%s (%d)', (string) ( $record['mls_name'] ?? '' ), (int) $mls_id );
	}
	return implode( ', ', $labels );
}
