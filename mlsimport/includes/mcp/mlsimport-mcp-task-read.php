<?php
/**
 * AI tools (MCP): reading Import Tasks and the filters an MLS offers.
 *
 * Each function here is the body of one AI tool, or a helper the task tools
 * share. A tool is a thin wrapper around code the wp-admin screens already use:
 *   - list-filter-values reads Mlsimport_Admin::mlsimport_task_fields(), the
 *     field list the Import Task editor draws its form from.
 *   - list-tasks and get-task read the tasks' saved settings and the Status
 *     badge of the Import Tasks list (mlsimport_task_health_for_task()).
 *
 * How a filter is stored (the editor's own rule): the filter "City" is the
 * post meta 'mlsimport_item_city'; its "Select All" flag is
 * 'mlsimport_item_city_check'.
 *
 * Changing a task lives in mlsimport-mcp-task-write.php.
 *
 * Public interface:
 *   mlsimport_mcp_task_read_tools()               the tools of this file
 *   mlsimport_mcp_task_filter_fields( $mls_id )   the filters an MLS offers
 *   mlsimport_mcp_task_view( $task_id, $full )    one task as a tool reports it
 *   mlsimport_mcp_filter_values_with_labels()     pick-list values as {value, label}
 *   mlsimport_mcp_list_filter_values( $input )    tool: list-filter-values
 *   mlsimport_mcp_list_tasks()                    tool: list-tasks
 *   mlsimport_mcp_get_task( $input )              tool: get-task
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
function mlsimport_mcp_task_read_tools(): array {
	return array(
		'list-filter-values' => array(
			'label'       => 'Filters and allowed values of an MLS',
			'description' => 'Reads the filters an import task can use for one MLS Connection (cities, counties, statuses, property types, postal codes ...) and, for the pick-list filters, the values that MLS accepts, each as {value, label}. Show the client the label; send the value to create-task and update-task (some MLSs use codes as values, for example Centris cities).',
			'input'       => array(
				'mls_id' => array(
					'type'        => 'integer',
					'description' => 'The MLS id of one of the site\'s MLS Connections, from list-mls-connections.',
				),
			),
			'required'    => array( 'mls_id' ),
			'callback'    => 'mlsimport_mcp_list_filter_values',
			'changes'     => false,
		),
		'list-tasks'         => array(
			'label'       => 'List the import tasks',
			'description' => 'Reads every import task: its MLS, whether the hourly sync is on, its health (OK, Sync overdue, Stuck, Import failed ...) and the last sync. Use get-task for the filters of one task.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_tasks',
			'changes'     => false,
		),
		'get-task'           => array(
			'label'       => 'One import task',
			'description' => 'Reads one import task with every saved setting and filter.',
			'input'       => array(
				'task_id' => array(
					'type'        => 'integer',
					'description' => 'The import task id, from list-tasks or create-task.',
				),
			),
			'required'    => array( 'task_id' ),
			'callback'    => 'mlsimport_mcp_get_task',
			'changes'     => false,
		),
	);
}

/**
 * The Import Task filters one MLS Connection offers.
 *
 * Step by step:
 * 1. The MLS must be one of the site's MLS Connections.
 * 2. Its fields must be gathered already; without them there is no list of
 *    filters to show (and the editor's function would print a warning).
 * 3. The field list is the editor's own: Mlsimport_Admin::mlsimport_task_fields().
 *
 * @param int $mls_id The MLS Connection.
 * @return array|WP_Error Field name => definition (label, description, type,
 *                        multiple, values), or the refusal naming the next step.
 */
function mlsimport_mcp_task_filter_fields( int $mls_id ) {
	global $mlsimport;

	// Step 1: a known MLS Connection.
	$refusal = mlsimport_mcp_require_connection( $mls_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: its fields are gathered.
	if ( '' === (string) mlsimport_get_connection_option( 'mlsimport_mls_metadata_mls_enums', '', $mls_id ) ) {
		return new WP_Error(
			'mlsimport_metadata_missing',
			__( 'The fields of this MLS are not gathered yet. Gather them with gather-metadata first.', 'mlsimport' )
		);
	}

	// Step 3: the editor's field list.
	return $mlsimport->admin->mlsimport_task_fields( $mls_id );
}

/**
 * The allowed values of one pick-list filter, each with a readable label.
 *
 * The value is what the task stores and what create-task / update-task
 * accept (the MLS enum key). The label is the enum's own name for it, read
 * with the same rule the Import Task editor uses for its dropdowns
 * (mlsimport_enum_option_label()): Centris stores City as code => name, so
 * "839" gets the label "Montréal"; a name => name MLS gets the same word twice.
 *
 * @param array $values   The allowed values (enum keys), in the form's order.
 * @param array $enum_map The MLS enum list of that field (key => name), or
 *                        an empty array when the MLS has none for it.
 * @return array<int, array{value: string, label: string}>
 */
function mlsimport_mcp_filter_values_with_labels( array $values, array $enum_map ): array {
	$labelled = array();
	foreach ( $values as $value ) {
		$labelled[] = array(
			'value' => (string) $value,
			'label' => mlsimport_enum_option_label( (string) $value, $enum_map ),
		);
	}
	return $labelled;
}

/**
 * Tool list-filter-values: the filters and allowed values of one MLS.
 *
 * Step by step:
 * 1. The editor's field list for that MLS (or the refusal).
 * 2. The MLS enum lists, so each pick-list value can carry its label.
 * 3. One entry per filter of the task form, in the form's order.
 *
 * @param array $input {
 *     @type int $mls_id The MLS Connection.
 * }
 * @return array|WP_Error The filters: name, label, help text, kind and, for a
 *                        pick list, the allowed values as {value, label}.
 */
function mlsimport_mcp_list_filter_values( array $input ) {
	// Step 1: the editor's field list.
	$mls_id = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$fields = mlsimport_mcp_task_filter_fields( $mls_id );
	if ( is_wp_error( $fields ) ) {
		return $fields;
	}

	// Step 2: the enum lists the field list was built from (key => name).
	$enums = json_decode( (string) mlsimport_get_connection_option( 'mlsimport_mls_metadata_mls_enums', '', $mls_id ), true );
	$enums = is_array( $enums ) ? (array) ( $enums['global_array']['PropertyEnums'] ?? array() ) : array();

	// Step 3: one entry per filter of the task form, in the form's order.
	$filters = array();
	foreach ( $fields as $name => $field ) {
		$filter = array(
			'name'        => (string) $name,
			'label'       => trim( (string) $field['label'] ),
			'description' => wp_strip_all_tags( (string) $field['description'] ),
			'type'        => 'select' === $field['type'] ? 'select' : 'text',
		);
		if ( 'select' === $field['type'] ) {
			$filter['multiple'] = 'yes' === $field['multiple'];
			$filter['values']   = mlsimport_mcp_filter_values_with_labels(
				(array) $field['values'],
				is_array( $enums[ $name ] ?? null ) ? $enums[ $name ] : array()
			);
		}
		$filters[] = $filter;
	}

	return array(
		'mls_id'  => $mls_id,
		'filters' => $filters,
	);
}

/**
 * One Import Task as a tool reports it.
 *
 * @param int  $task_id Import Task post id.
 * @param bool $full    True to include every saved setting and filter
 *                      (get-task); false for the short row of list-tasks.
 * @return array The task.
 */
function mlsimport_mcp_task_view( int $task_id, bool $full ): array {
	$mls_id     = mlsimport_task_mls_id( $task_id );
	$connection = Mlsimport_Connections::get( $mls_id );

	// The short row: identity, MLS, hourly sync, health badge, last sync.
	$view = array(
		'task_id'   => $task_id,
		'title'     => get_the_title( $task_id ),
		'status'    => (string) get_post_status( $task_id ),
		'mls_id'    => $mls_id,
		'mls_name'  => null === $connection ? '' : (string) $connection['mls_name'],
		'auto_sync' => 1 === (int) get_post_meta( $task_id, 'mlsimport_item_stat_cron', true ),
		'health'    => mlsimport_task_health_for_task( $task_id ),
		'last_sync' => (string) get_post_meta( $task_id, 'mlsimport_last_date', true ),
	);
	if ( ! $full ) {
		return $view;
	}

	// The general settings of the task form.
	$view['how_many']        = (string) get_post_meta( $task_id, 'mlsimport_item_how_many', true );
	$view['title_format']    = (string) get_post_meta( $task_id, 'mlsimport_item_title_format', true );
	$view['min_price']       = (string) get_post_meta( $task_id, 'mlsimport_item_min_price', true );
	$view['max_price']       = (string) get_post_meta( $task_id, 'mlsimport_item_max_price', true );
	$view['property_status'] = (string) get_post_meta( $task_id, 'mlsimport_item_property_status', true );

	// Who the imported listings belong to: the agent post, the owner user, and
	// (standalone) whether each listing shows its own MLS agent instead.
	$agent_id             = (int) get_post_meta( $task_id, 'mlsimport_item_agent', true );
	$user                 = get_userdata( (int) get_post_meta( $task_id, 'mlsimport_item_property_user', true ) );
	$view['agent']        = $agent_id > 0 && get_post( $agent_id ) ? array(
		'id'   => $agent_id,
		'name' => get_the_title( $agent_id ),
	) : null;
	$view['user']         = $user ? array(
		'id'    => (int) $user->ID,
		'login' => (string) $user->user_login,
	) : null;
	$view['use_mls_agent'] = ! empty( get_post_meta( $task_id, 'mlsimport_item_use_mls_agent', true ) );

	// The filters that have a value, under the names list-filter-values gives.
	$view['filters']    = array();
	$view['select_all'] = array();
	$fields             = mlsimport_mcp_task_filter_fields( $mls_id );
	foreach ( is_wp_error( $fields ) ? array() : array_keys( $fields ) as $name ) {
		$meta_key = strtolower( 'mlsimport_item_' . $name );
		$value    = get_post_meta( $task_id, $meta_key, true );
		if ( '' !== $value && array() !== $value ) {
			$view['filters'][ $name ] = $value;
		}
		if ( 1 === (int) get_post_meta( $task_id, $meta_key . '_check', true ) ) {
			$view['select_all'][] = $name;
		}
	}
	return $view;
}

/**
 * Tool list-tasks: every Import Task with its MLS and health.
 *
 * @return array The tasks, newest first.
 */
function mlsimport_mcp_list_tasks(): array {
	// Every Import Task that is not in the trash.
	$task_ids = get_posts(
		array(
			'post_type'   => 'mlsimport_item',
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	$tasks = array();
	foreach ( $task_ids as $task_id ) {
		$tasks[] = mlsimport_mcp_task_view( (int) $task_id, false );
	}
	return array( 'tasks' => $tasks );
}

/**
 * Tool get-task: one Import Task with every saved setting and filter.
 *
 * @param array $input {
 *     @type int $task_id Import Task to read.
 * }
 * @return array|WP_Error The task, or the refusal.
 */
function mlsimport_mcp_get_task( array $input ) {
	$task_id = isset( $input['task_id'] ) ? (int) $input['task_id'] : 0;
	$refusal = mlsimport_mcp_require_task( $task_id );
	if ( null !== $refusal ) {
		return $refusal;
	}
	return mlsimport_mcp_task_view( $task_id, true );
}
