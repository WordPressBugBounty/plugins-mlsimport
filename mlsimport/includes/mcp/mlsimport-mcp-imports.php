<?php
/**
 * AI tools (MCP): Import Runs.
 *
 * Each function here is the body of one AI tool. A tool is a thin wrapper:
 * it reads its input, calls the plugin function that already does the work,
 * and returns that function's result.
 *   - run-import starts the run with mlsimport_start_manual_import()
 *     (includes/mlsimport-manual-import-start.php), the same function the
 *     Import Task screen and the setup wizard use. The run is a normal manual
 *     Import Run.
 *   - import-status reads Mlsimport_Import_Task_Execution::status(), the same
 *     record the wp-admin progress bar reads.
 *
 * A web chat cannot wait in the background. run-import therefore answers at
 * once with "started", and import-status answers a later "is it done?". The
 * AI checks once and reports; it does not wait in a loop.
 *
 *
 *   - count-task-listings asks the MLS through
 *     Mlsimport_Admin::mlsimport_make_listing_requests(), the same request the
 *     Import Task screen uses to show how many listings match.
 *
 * Stopping and restarting an import live in mlsimport-mcp-import-control.php.
 *
 * Public interface:
 *   mlsimport_mcp_import_tools()   the tools of this file
 *   mlsimport_mcp_count_task_listings( $input )   tool: count-task-listings
 *   mlsimport_mcp_run_import( $input )      tool: run-import
 *   mlsimport_mcp_import_status( $input )   tool: import-status
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file (Import Runs), as the tool list expects them.
 *
 * Each entry: label, description, input, required, callback, changes. See
 * mlsimport_mcp_ability_definitions() in mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_import_tools(): array {
	$task_id = array(
		'type'        => 'integer',
		'description' => 'The import task id returned by create-task.',
	);

	return array(
		'count-task-listings' => array(
			'label'       => 'Count the listings a task matches',
			'description' => 'Reads how many listings at the MLS match an import task. Needs a task from create-task.',
			'input'       => array( 'task_id' => $task_id ),
			'required'    => array( 'task_id' ),
			'callback'    => 'mlsimport_mcp_count_task_listings',
			'changes'     => false,
		),
		'run-import' => array(
			'label'       => 'Start an import',
			'description' => 'Starts importing listings for a task in the background and answers at once with "started". how_many is required: ask the client how many listings to import. Do not wait in a loop afterwards; tell the client the import started and that they can ask for the status.',
			'input'       => array(
				'task_id'  => $task_id,
				'how_many' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'How many listings to import.',
				),
			),
			'required'    => array( 'task_id', 'how_many' ),
			'callback'    => 'mlsimport_mcp_run_import',
			'changes'     => true,
		),
		'import-status' => array(
			'label'       => 'Import status',
			'description' => 'Reads the state of a task\'s last import: handled out of expected while running, and found, saved, failed and any error when finished. Call it once when the client asks whether the import is done, and report; do not poll in a loop.',
			'input'       => array( 'task_id' => $task_id ),
			'required'    => array( 'task_id' ),
			'callback'    => 'mlsimport_mcp_import_status',
			'changes'     => false,
		),
	);
}

/**
 * Refuse anything that is not an Import Task, naming the next step.
 *
 * @param int $task_id Import Task post id from the tool input.
 * @return WP_Error|null The refusal, or null when the id is an Import Task.
 */
function mlsimport_mcp_require_task( int $task_id ) {
	if ( 'mlsimport_item' === get_post_type( $task_id ) ) {
		return null;
	}
	return new WP_Error(
		'mlsimport_task_not_found',
		__( 'That import task does not exist. Create one with create-task first.', 'mlsimport' )
	);
}

/**
 * Tool count-task-listings: how many listings an Import Task matches.
 *
 * @param array $input {
 *     @type int $task_id Import Task to count for.
 * }
 * @return array|WP_Error The matching count, or the plugin's own reason.
 */
function mlsimport_mcp_count_task_listings( array $input ) {
	global $mlsimport;
	$task_id = isset( $input['task_id'] ) ? (int) $input['task_id'] : 0;

	// Step 1: the task must exist.
	$refusal = mlsimport_mcp_require_task( $task_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: one count request to the MLS with the task's filters.
	$answer = $mlsimport->admin->mlsimport_make_listing_requests( $task_id );

	// Step 3: an answer without a count carries the plugin's reason.
	if ( ! isset( $answer['results'] ) ) {
		return new WP_Error(
			'mlsimport_count_failed',
			isset( $answer['message'] ) && '' !== (string) $answer['message']
				? (string) $answer['message']
				: __( 'The MLS did not answer the count request.', 'mlsimport' )
		);
	}

	return array(
		'task_id'  => $task_id,
		'matching' => (int) $answer['results'],
	);
}

/**
 * Tool run-import: start importing listings for one Import Task.
 *
 * The inputs are only the task and how many listings to import. "How many"
 * is required: there is no silent "import everything". The matching count is
 * never taken from the AI; the start function counts at the MLS itself.
 *
 * @param array $input {
 *     @type int $task_id  Import Task to run.
 *     @type int $how_many How many listings to import.
 * }
 * @return array|WP_Error "started" with the run id and counts, or the refusal.
 */
function mlsimport_mcp_run_import( array $input ) {
	$task_id  = isset( $input['task_id'] ) ? (int) $input['task_id'] : 0;
	$how_many = isset( $input['how_many'] ) ? (int) $input['how_many'] : 0;

	// Step 1: the task must exist.
	$refusal = mlsimport_mcp_require_task( $task_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: "how many" is required.
	if ( $how_many < 1 ) {
		return new WP_Error(
			'mlsimport_how_many_required',
			__( 'Say how many listings to import. Use count-task-listings to see how many match the task.', 'mlsimport' )
		);
	}

	// Step 3: the plugin's single manual-start function counts at the MLS
	// (no count is passed), takes the run slot and queues the worker.
	$start = mlsimport_start_manual_import( $task_id, $how_many );
	if ( true !== $start['started'] ) {
		return new WP_Error( 'mlsimport_import_not_started', $start['message'] );
	}

	// Step 4: answer at once. The import itself runs in the background.
	return array(
		'state'    => 'started',
		'run_id'   => $start['run_id'],
		'task_id'  => $task_id,
		'expected' => (int) $start['found'],
		'note'     => __( 'The import runs in the background. Use import-status once when the client asks whether it is done; do not wait in a loop.', 'mlsimport' ),
	);
}

/**
 * Tool import-status: the state of an Import Task's last Import Run.
 *
 * @param array $input {
 *     @type int $task_id Import Task to report on.
 * }
 * @return array|WP_Error State, handled out of expected, and the final
 *                        result (found, saved, failed, error) when finished.
 */
function mlsimport_mcp_import_status( array $input ) {
	global $mlsimport;
	$task_id = isset( $input['task_id'] ) ? (int) $input['task_id'] : 0;

	// Step 1: read the one shared progress record of the task.
	$progress = $mlsimport->admin->mlsimport_import_task_execution()->status( $task_id );

	// Step 2: a task that never ran has no record.
	if ( empty( $progress ) ) {
		return array(
			'state'    => 'none',
			'finished' => false,
			'note'     => __( 'This task has no import yet. Start one with run-import.', 'mlsimport' ),
		);
	}

	// Step 3: report it. Finished means completed, stopped or failed, the same
	// rule the wp-admin progress bar uses.
	$state = (string) ( $progress['state'] ?? '' );
	return array(
		'state'    => $state,
		'finished' => Mlsimport_Import_Task_Execution::is_finished_state( $state ),
		'handled'  => (int) ( $progress['handled'] ?? 0 ),
		'expected' => (int) ( $progress['expected'] ?? 0 ),
		'error'    => (string) ( $progress['error'] ?? '' ),
		'result'   => isset( $progress['result'] ) && is_array( $progress['result'] ) ? $progress['result'] : array(),
	);
}
