<?php
/**
 * AI tools (MCP): the four tools that delete something.
 *
 * Every tool here needs two calls, enforced by the plugin
 * (mlsimport-mcp-confirm.php): the first call deletes nothing and answers
 * with exactly what would be deleted plus a one-time confirmation code; the
 * second call, with that code and the same inputs, deletes.
 *
 * Each tool body is a thin wrapper around the function wp-admin already uses:
 *   - remove-mls-connection     -> mlsimport_connections_remove_execute()
 *                                  (the Connections screen's Remove action).
 *   - clear-field-data          -> mlsimport_tools_clear_fields_data()
 *                                  (the Tools tab's "Clear Field Data" button).
 *   - delete-task               -> wp_trash_post() (the task goes to the trash,
 *                                  as the Import Tasks list does it).
 *   - delete-properties-by-term -> mlsimport_tools_delete_properties_batch()
 *                                  (the Tools tab's "Delete Properties" tool,
 *                                  20 properties per batch).
 *
 * What is never deleted by a tool: the MLSImport Account and Connected AI Apps.
 *
 * Public interface:
 *   mlsimport_mcp_destructive_tools()                   the tools of this file
 *   mlsimport_mcp_remove_mls_connection( $input )       tool: remove-mls-connection
 *   mlsimport_mcp_clear_field_data( $input )            tool: clear-field-data
 *   mlsimport_mcp_delete_task( $input )                 tool: delete-task
 *   mlsimport_mcp_delete_properties_by_term( $input )   tool: delete-properties-by-term
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
 * Each entry: label, description, input, required, callback, changes,
 * destructive. See mlsimport_mcp_ability_definitions() in
 * mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_destructive_tools(): array {
	$two_calls = ' Needs two calls: the first call deletes nothing and returns what would be deleted and a confirmation_code; tell the client, and only after they confirm call again with the same inputs and that code.';
	$code      = array(
		'type'        => 'string',
		'description' => 'The confirmation_code returned by the first call. Leave out on the first call.',
	);
	$mls_id    = array(
		'type'        => 'integer',
		'description' => 'The MLS id of one of the site\'s MLS Connections, from list-mls-connections.',
	);
	$tool      = static function ( string $label, string $description, array $input, array $required, string $callback ) use ( $two_calls, $code ): array {
		return array(
			'label'       => $label,
			'description' => $description . $two_calls,
			'input'       => $input + array( 'confirmation_code' => $code ),
			'required'    => $required,
			'callback'    => $callback,
			'changes'     => true,
			'destructive' => true,
		);
	};

	return array(
		'remove-mls-connection'     => $tool(
			'Remove an MLS connection',
			'Removes one MLS Connection: its credentials, field mapping and gathered fields are deleted. Import tasks of that MLS stop running. The listings already imported stay on the site.',
			array( 'mls_id' => $mls_id ),
			array( 'mls_id' ),
			'mlsimport_mcp_remove_mls_connection'
		),
		'clear-field-data'          => $tool(
			'Clear the field data of an MLS connection',
			'Deletes the saved field mapping of one MLS Connection, so it starts again from the theme defaults the next time its fields are gathered (gather-metadata). Labels and choices made for that MLS are lost. Listings are not touched.',
			array( 'mls_id' => $mls_id ),
			array( 'mls_id' ),
			'mlsimport_mcp_clear_field_data'
		),
		'delete-task'               => $tool(
			'Delete an import task',
			'Moves one import task to the trash (update-task with a status restores it). With permanently true the task is deleted for good instead. The listings it imported stay on the site, but they are no longer updated by that task.',
			array(
				'task_id'     => array(
					'type'        => 'integer',
					'description' => 'The import task id, from list-tasks.',
				),
				'permanently' => array(
					'type'        => 'boolean',
					'description' => 'True to delete the task for good instead of moving it to the trash.',
				),
			),
			array( 'task_id' ),
			'mlsimport_mcp_delete_task'
		),
		'delete-properties-by-term' => $tool(
			'Delete properties by category',
			'Permanently deletes the imported properties that have one of the chosen terms of a taxonomy (for example every property in the city Miami). Taxonomy and term slugs come from list-delete-terms. A large delete answers with "remaining" and a new confirmation_code: call again with it until "done" is true.',
			array(
				'taxonomy' => array(
					'type'        => 'string',
					'description' => 'Taxonomy slug from list-delete-terms.',
				),
				'terms'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Term slugs from list-delete-terms.',
				),
			),
			array( 'taxonomy', 'terms' ),
			'mlsimport_mcp_delete_properties_by_term'
		),
	);
}

/**
 * Tool remove-mls-connection: remove one MLS Connection (two calls).
 *
 * @param array $input mls_id, confirmation_code.
 * @return array|WP_Error The preview, "removed", or the refusal.
 */
function mlsimport_mcp_remove_mls_connection( array $input ) {
	// Step 1: a known MLS Connection.
	$mls_id  = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$refusal = mlsimport_mcp_require_connection( $mls_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: say exactly what goes and what stays, with the screen's counts.
	$record   = Mlsimport_Connections::get( $mls_id );
	$activity = mlsimport_connections_activity()[ $mls_id ] ?? array( 'tasks' => 0, 'listings' => 0 );
	$pending  = mlsimport_mcp_confirm_or_preview(
		'remove-mls-connection',
		$input,
		sprintf(
			/* translators: 1: MLS name, 2: MLS id, 3: number of import tasks, 4: number of listings. */
			__( 'The MLS Connection "%1$s" (%2$d): its credentials, field mapping and gathered fields. Its %3$d import task(s) stay but stop running. Its %4$d imported listing(s) stay on the site.', 'mlsimport' ),
			$record['mls_name'],
			$mls_id,
			(int) $activity['tasks'],
			(int) $activity['listings']
		)
	);
	if ( null !== $pending ) {
		return $pending;
	}

	// Step 3: confirmed - the Connections screen's Remove action.
	$outcome = mlsimport_connections_remove_execute( $mls_id );
	if ( ! $outcome['success'] ) {
		return new WP_Error( 'mlsimport_' . $outcome['code'], (string) $outcome['message'] );
	}
	return array(
		'deleted' => true,
		'mls_id'  => $mls_id,
	);
}

/**
 * Tool clear-field-data: forget one MLS Connection's field mapping (two calls).
 *
 * @param array $input mls_id, confirmation_code.
 * @return array|WP_Error The preview, "cleared", or the refusal.
 */
function mlsimport_mcp_clear_field_data( array $input ) {
	// Step 1: a known MLS Connection.
	$mls_id  = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$refusal = mlsimport_mcp_require_connection( $mls_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: what would be deleted.
	$record  = Mlsimport_Connections::get( $mls_id );
	$pending = mlsimport_mcp_confirm_or_preview(
		'clear-field-data',
		$input,
		sprintf(
			/* translators: 1: MLS name, 2: MLS id. */
			__( 'The saved field mapping of "%1$s" (%2$d): which fields are imported, their labels and where they are stored. Listings are not touched.', 'mlsimport' ),
			$record['mls_name'],
			$mls_id
		)
	);
	if ( null !== $pending ) {
		return $pending;
	}

	// Step 3: confirmed - the Tools tab's "Clear Field Data", for this MLS.
	mlsimport_tools_clear_fields_data( $mls_id );
	return array(
		'deleted' => true,
		'mls_id'  => $mls_id,
		'note'    => __( 'The field mapping is cleared. Build it again from the theme defaults with gather-metadata.', 'mlsimport' ),
	);
}

/**
 * Tool delete-task: move one Import Task to the trash, or delete it for
 * good (two calls).
 *
 * @param array $input task_id, permanently, confirmation_code.
 * @return array|WP_Error The preview, "deleted", or the refusal.
 */
function mlsimport_mcp_delete_task( array $input ) {
	// Step 1: the task must exist.
	$task_id = isset( $input['task_id'] ) ? (int) $input['task_id'] : 0;
	$refusal = mlsimport_mcp_require_task( $task_id );
	if ( null !== $refusal ) {
		return $refusal;
	}
	$for_good = ! empty( $input['permanently'] );

	// Step 2: what would be deleted.
	$pending = mlsimport_mcp_confirm_or_preview(
		'delete-task',
		$input,
		sprintf(
			$for_good
				/* translators: 1: task title, 2: task id. */
				? __( 'The import task "%1$s" (%2$d) is deleted for good; it cannot be restored. The listings it imported stay on the site but are no longer updated by it.', 'mlsimport' )
				/* translators: 1: task title, 2: task id. */
				: __( 'The import task "%1$s" (%2$d) goes to the trash. The listings it imported stay on the site but are no longer updated by it.', 'mlsimport' ),
			get_the_title( $task_id ),
			$task_id
		)
	);
	if ( null !== $pending ) {
		return $pending;
	}

	// Step 3: confirmed - to the trash as the Import Tasks list does it, or
	// deleted for good as its "Delete Permanently" does.
	$done = $for_good ? wp_delete_post( $task_id, true ) : wp_trash_post( $task_id );
	if ( ! $done ) {
		return new WP_Error( 'mlsimport_task_not_deleted', __( 'The import task could not be deleted.', 'mlsimport' ) );
	}
	return array(
		'deleted' => true,
		'task_id' => $task_id,
	);
}

/**
 * Tool delete-properties-by-term: delete the properties of chosen terms (two calls).
 *
 * Step by step:
 * 1. The taxonomy must be one of the properties' taxonomies and every term
 *    must exist in it.
 * 2. First call: count the matching properties and answer with the preview.
 * 3. Confirmed: delete one of the Tools tab's batches (20 properties), so
 *    the call never times out. The AI calls again until done, as the Tools
 *    tab's own button does.
 * 4. When properties remain, hand out a new code so the AI can continue
 *    without asking the client again for the same delete.
 *
 * @param array $input taxonomy, terms, confirmation_code.
 * @return array|WP_Error The preview, the progress, or the refusal.
 */
function mlsimport_mcp_delete_properties_by_term( array $input ) {
	global $mlsimport;

	// Step 1: validate the taxonomy and the terms.
	$taxonomy   = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
	$terms      = isset( $input['terms'] ) ? array_values( array_filter( array_map( 'sanitize_title', (array) $input['terms'] ), 'strlen' ) ) : array();
	$taxonomies = (array) mlsimport_get_custom_post_type_taxonomies( $mlsimport->admin->env_data->get_property_post_type() );
	$names      = array();
	foreach ( $terms as $slug ) {
		$term = isset( $taxonomies[ $taxonomy ] ) ? get_term_by( 'slug', $slug, $taxonomy ) : false;
		if ( ! $term ) {
			$names = array();
			break;
		}
		$names[] = $term->name;
	}
	if ( empty( $names ) ) {
		return new WP_Error( 'mlsimport_terms_unknown', __( 'Name a taxonomy of the properties and at least one of its terms, by slug. See them with list-delete-terms.', 'mlsimport' ) );
	}

	// Step 2: the preview, with the real count.
	$total   = (int) mlsimport_tools_properties_by_term( $taxonomy, $terms, 1 )->found_posts;
	$pending = mlsimport_mcp_confirm_or_preview(
		'delete-properties-by-term',
		$input,
		sprintf(
			/* translators: 1: number of properties, 2: taxonomy label, 3: term names. */
			__( '%1$d properties in %2$s: %3$s. They are deleted permanently, with their images.', 'mlsimport' ),
			$total,
			$taxonomies[ $taxonomy ],
			implode( ', ', $names )
		)
	);
	if ( null !== $pending ) {
		return $pending;
	}

	// Step 3: confirmed - one of the Tools tab's batches (20 properties).
	$answer = mlsimport_tools_delete_properties_batch( $taxonomy, $terms );

	// Step 4: more to delete - hand out a new code for the next batch.
	if ( ! $answer['done'] ) {
		$answer['confirmation_code'] = mlsimport_mcp_confirmation_issue( 'delete-properties-by-term', $input );
		$answer['note']              = __( 'More properties remain. Call again with the same inputs and this confirmation_code until done is true. A property that was not imported by MLSImport is never deleted: when "remaining" stops going down, stop calling.', 'mlsimport' );
	}
	return $answer;
}
