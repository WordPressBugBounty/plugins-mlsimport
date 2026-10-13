<?php
/**
 * AI tools (MCP): changing an Import Task.
 *
 * update-task changes the settings of an existing Import Task. It stores them
 * through mlsimport_task_save_settings() (includes/mlsimport-task-settings.php),
 * the same function the Import Task editor uses, so there is one save path.
 *
 * Rules owned by this file:
 *   - A pick-list value (a city, a status, a property type ...) is stored the
 *     way the MLS spells it. The AI may send "Orlando"; when the MLS's value
 *     is "ORLANDO" that is what is stored, because the MLS matches the exact
 *     spelling. A value the MLS does not have is refused. create-task uses
 *     the same rule (mlsimport-mcp-tasks.php).
 *   - A setting that is not named in the call keeps its value.
 *   - The task's MLS cannot be changed (the editor's rule): there is no input
 *     for it.
 *
 * Reading a task lives in mlsimport-mcp-task-read.php.
 *
 * Public interface:
 *   mlsimport_mcp_task_write_tools()                          the tools of this file
 *   mlsimport_mcp_match_filter_values( $field, $name, $given ) the MLS's spelling of values
 *   mlsimport_mcp_update_task( $input )                       tool: update-task
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
function mlsimport_mcp_task_write_tools(): array {
	$text    = array( 'type' => 'string' );
	$integer = array( 'type' => 'integer' );

	return array(
		'update-task' => array(
			'label'       => 'Change an import task',
			'description' => 'Changes the settings of an import task. Only the settings you name are changed. Filter names and allowed values come from list-filter-values. The MLS of a task cannot be changed; create a new task for another MLS.',
			'input'       => array(
				'task_id'         => array(
					'type'        => 'integer',
					'description' => 'The import task id, from list-tasks or create-task.',
				),
				'title'           => $text,
				'title_format'    => array(
					'type'        => 'string',
					'description' => 'Title of the imported listings, for example {Address}, {City}.',
				),
				'how_many'        => array(
					'type'        => 'integer',
					'description' => 'How many listings to import; 0 means all.',
				),
				'auto_sync'       => array(
					'type'        => 'boolean',
					'description' => 'Keep the imported listings updated by the hourly sync.',
				),
				'min_price'       => $integer,
				'max_price'       => $integer,
				'property_status' => array(
					'type'        => 'string',
					'enum'        => array( 'publish', 'draft' ),
					'description' => 'WordPress status of the imported listings.',
				),
				'agent_id'        => array(
					'type'        => 'integer',
					'description' => 'The agent (post id) the imported listings are assigned to; 0 removes the agent. On a standalone site the ids come from list-agents.',
				),
				'use_mls_agent'   => array(
					'type'        => 'boolean',
					'description' => 'Standalone sites only: true shows each listing\'s own listing agent from the MLS feed instead of the task\'s agent.',
				),
				'user_id'         => array(
					'type'        => 'integer',
					'description' => 'The WordPress user (id) who owns the imported listings.',
				),
				'status'          => array(
					'type'        => 'string',
					'enum'        => array( 'publish', 'draft' ),
					'description' => 'Status of the task itself. A task in the trash is restored with the status given.',
				),
				'filters'         => array(
					'type'                 => 'object',
					'description'          => 'Filters to set, keyed by the names from list-filter-values. A pick-list filter takes a list of values, a text filter takes text. An empty list or empty text clears the filter.',
					'additionalProperties' => true,
				),
				'select_all'      => array(
					'type'                 => 'object',
					'description'          => 'Turn "select all values" on (true) or off (false) for a pick-list filter, keyed by filter name.',
					'additionalProperties' => array( 'type' => 'boolean' ),
				),
			),
			'required'    => array( 'task_id' ),
			'callback'    => 'mlsimport_mcp_update_task',
			'changes'     => true,
		),
	);
}

/**
 * Turn the values the AI sent for a pick-list filter into the MLS's own spelling.
 *
 * Step by step:
 * 1. Index the filter's allowed values by their lower-case form.
 * 2. Look each sent value up without regard to letter case and take the
 *    MLS's spelling.
 * 3. Refuse when a value is not one the MLS has, naming list-filter-values.
 *
 * @param array    $field One filter definition from mlsimport_mcp_task_filter_fields().
 * @param string   $name  The filter name (for the refusal).
 * @param string[] $given The values the AI sent.
 * @return string[]|WP_Error The values as the MLS spells them, or the refusal.
 */
function mlsimport_mcp_match_filter_values( array $field, string $name, array $given ) {
	// Step 1: the allowed values, by lower-case form.
	$allowed = array();
	foreach ( (array) ( $field['values'] ?? array() ) as $value ) {
		$allowed[ strtolower( (string) $value ) ] = (string) $value;
	}

	// Step 2 + 3: the MLS's spelling of each sent value, or the refusal.
	$matched = array();
	foreach ( $given as $value ) {
		$key = strtolower( trim( (string) $value ) );
		if ( ! isset( $allowed[ $key ] ) ) {
			return new WP_Error(
				'mlsimport_filter_value_unknown',
				sprintf(
					/* translators: 1: the value sent, 2: the filter name. */
					__( '"%1$s" is not a value of the filter %2$s at this MLS. See the allowed values with list-filter-values.', 'mlsimport' ),
					sanitize_text_field( (string) $value ),
					$name
				)
			);
		}
		$matched[] = $allowed[ $key ];
	}
	return array_values( array_unique( $matched ) );
}

/**
 * The filters the AI sent, as the task form posts them.
 *
 * Step by step:
 * 1. Every name must be a filter of the task's MLS.
 * 2. A text filter is stored as text (a list becomes comma-separated text).
 *    A pick-list filter takes the MLS's spelling of each value; a single
 *    choice stores one value or '' to clear; a cleared list is "not sent"
 *    (null here), as a browser sends an empty multi-select, so the save
 *    function resets it.
 * 3. select_all switches the "select all values" box of a pick-list filter.
 *
 * @param array $fields     The MLS's filters, from mlsimport_mcp_task_filter_fields().
 * @param array $filters    Filter name => value(s).
 * @param array $select_all Filter name => bool.
 * @return array|WP_Error Form key => value, or null for "not sent"; or the refusal.
 */
function mlsimport_mcp_task_filters_posted( array $fields, array $filters, array $select_all ) {
	// Step 1: the names.
	foreach ( array_keys( $filters + $select_all ) as $name ) {
		if ( ! isset( $fields[ $name ] ) ) {
			return new WP_Error(
				'mlsimport_filter_unknown',
				sprintf(
					/* translators: %s: the filter name sent. */
					__( '"%s" is not a filter of this MLS. See the filter names with list-filter-values.', 'mlsimport' ),
					sanitize_text_field( (string) $name )
				)
			);
		}
	}

	// Step 2: the values.
	$posted = array();
	foreach ( $filters as $name => $value ) {
		$key = strtolower( 'mlsimport_item_' . $name );
		if ( 'select' !== $fields[ $name ]['type'] ) {
			$posted[ $key ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			continue;
		}
		$values = mlsimport_mcp_match_filter_values( $fields[ $name ], (string) $name, array_filter( (array) $value, 'strlen' ) );
		if ( is_wp_error( $values ) ) {
			return $values;
		}
		if ( 'yes' !== $fields[ $name ]['multiple'] ) {
			$posted[ $key ] = empty( $values ) ? '' : $values[0];
		} else {
			$posted[ $key ] = empty( $values ) ? null : $values;
		}
	}

	// Step 3: the "select all" boxes.
	foreach ( $select_all as $name => $on ) {
		$posted[ strtolower( 'mlsimport_item_' . $name . '_check' ) ] = empty( $on ) ? '0' : '1';
	}
	return $posted;
}

/**
 * Tool update-task: change the settings of an Import Task.
 *
 * Step by step:
 * 1. The task must exist.
 * 2. Start from the task's current settings, in the shape the task form
 *    sends them, so everything that is not named keeps its value.
 * 3. Lay the named general settings over them.
 * 4. Lay the named filters over them: the filter must exist for the task's
 *    MLS; pick-list values take the MLS's spelling. Any refusal happens here,
 *    before anything is stored.
 * 5. Store through the editor's own save function, then rename the task when
 *    a title was sent.
 * 6. Answer with the task as it is saved now.
 *
 * @param array $input See mlsimport_mcp_task_write_tools().
 * @return array|WP_Error The saved task (as get-task), or the refusal.
 */
function mlsimport_mcp_update_task( array $input ) {
	// Step 1: the task.
	$task_id = isset( $input['task_id'] ) ? (int) $input['task_id'] : 0;
	$refusal = mlsimport_mcp_require_task( $task_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: the current settings, keyed like the task form's fields.
	$posted = array();
	foreach ( mlsimport_task_setting_keys() as $key ) {
		// 'mlsimport_item_city[]' is the form's second name for the city
		// meta; filling it too would write the old cities over the new ones.
		if ( sanitize_key( $key ) !== $key ) {
			continue;
		}
		$current = get_post_meta( $task_id, $key, true );
		if ( '' !== $current ) {
			$posted[ $key ] = $current;
		}
	}

	// Step 3: the general settings that were named.
	$general = array(
		'title_format'    => 'mlsimport_item_title_format',
		'how_many'        => 'mlsimport_item_how_many',
		'min_price'       => 'mlsimport_item_min_price',
		'max_price'       => 'mlsimport_item_max_price',
		'property_status' => 'mlsimport_item_property_status',
	);
	foreach ( $general as $name => $key ) {
		if ( isset( $input[ $name ] ) ) {
			$posted[ $key ] = (string) $input[ $name ];
		}
	}
	if ( isset( $input['auto_sync'] ) ) {
		$posted['mlsimport_item_stat_cron'] = empty( $input['auto_sync'] ) ? '0' : '1';
	}
	// The agent and the owner user, when named, must exist (mlsimport-mcp-tasks.php).
	$people = mlsimport_mcp_task_people_input( $input );
	if ( is_wp_error( $people ) ) {
		return $people;
	}
	$posted = array_merge( $posted, $people );
	if ( isset( $input['use_mls_agent'] ) ) {
		// A blank key: "not sent" clears it, as an unticked checkbox does.
		unset( $posted['mlsimport_item_use_mls_agent'] );
		if ( ! empty( $input['use_mls_agent'] ) ) {
			$posted['mlsimport_item_use_mls_agent'] = '1';
		}
	}

	// Step 4: the filters that were named (the same helper create-task uses).
	$filters    = isset( $input['filters'] ) && is_array( $input['filters'] ) ? $input['filters'] : array();
	$select_all = isset( $input['select_all'] ) && is_array( $input['select_all'] ) ? $input['select_all'] : array();
	if ( ! empty( $filters ) || ! empty( $select_all ) ) {
		$fields = mlsimport_mcp_task_filter_fields( mlsimport_task_mls_id( $task_id ) );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$extra = mlsimport_mcp_task_filters_posted( $fields, $filters, $select_all );
		if ( is_wp_error( $extra ) ) {
			return $extra;
		}
		foreach ( $extra as $key => $value ) {
			if ( null === $value ) {
				unset( $posted[ $key ] );
			} else {
				$posted[ $key ] = $value;
			}
		}
	}

	// Step 5: the one save of an Import Task's settings, then the title and
	// the task's own status (a trashed task comes back with that status).
	mlsimport_task_save_settings( $task_id, $posted );
	$post = array();
	if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
		$post['post_title'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( isset( $input['status'] ) && in_array( $input['status'], array( 'publish', 'draft' ), true ) ) {
		if ( 'trash' === get_post_status( $task_id ) ) {
			wp_untrash_post( $task_id );
		}
		$post['post_status'] = $input['status'];
	}
	if ( ! empty( $post ) ) {
		wp_update_post( array( 'ID' => $task_id ) + $post );
	}

	// Step 6: the task as saved.
	return mlsimport_mcp_task_view( $task_id, true );
}
