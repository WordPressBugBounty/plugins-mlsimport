<?php
/**
 * AI tools (MCP): stopping and restarting an Import Run.
 *
 * Each function here is the body of one AI tool, a thin wrapper around the
 * methods the Import Task screen itself uses:
 *   - stop-import calls Mlsimport_Admin::mlsimport_stop_import_for_task(),
 *     the Stop button's sequence.
 *   - revive-import calls Mlsimport_Admin::mlsimport_revive_import_for_task(),
 *     the watchdog the progress poll runs: it restarts the background worker
 *     of an import that went silent, and does nothing otherwise.
 *
 * Starting an import and reading its status live in mlsimport-mcp-imports.php.
 *
 * Public interface:
 *   mlsimport_mcp_import_control_tools()    the tools of this file
 *   mlsimport_mcp_stop_import( $input )     tool: stop-import
 *   mlsimport_mcp_revive_import( $input )   tool: revive-import
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
function mlsimport_mcp_import_control_tools(): array {
	$task_id = array(
		'type'        => 'integer',
		'description' => 'The import task id returned by create-task.',
	);

	return array(
		'stop-import'         => array(
			'label'       => 'Stop an import',
			'description' => 'Asks the running import of a task to stop. The listings already imported stay.',
			'input'       => array( 'task_id' => $task_id ),
			'required'    => array( 'task_id' ),
			'callback'    => 'mlsimport_mcp_stop_import',
			'changes'     => true,
		),
		'revive-import'       => array(
			'label'       => 'Restart a stalled import',
			'description' => 'Restarts the background worker of an import that went silent (import-status shows it running but the handled count no longer moves). Does nothing when the import is still active or already finished.',
			'input'       => array( 'task_id' => $task_id ),
			'required'    => array( 'task_id' ),
			'callback'    => 'mlsimport_mcp_revive_import',
			'changes'     => true,
		),
	);
}

/**
 * Tool stop-import: ask the running import of one Import Task to stop.
 *
 * @param array $input {
 *     @type int $task_id Import Task whose import to stop.
 * }
 * @return array|WP_Error Whether a running import was found and asked to stop.
 */
function mlsimport_mcp_stop_import( array $input ) {
	global $mlsimport;
	$task_id = isset( $input['task_id'] ) ? (int) $input['task_id'] : 0;

	// Step 1: the task must exist.
	$refusal = mlsimport_mcp_require_task( $task_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: the Stop button's own sequence.
	$stopped = $mlsimport->admin->mlsimport_stop_import_for_task( $task_id );

	return array(
		'stopped' => $stopped,
		'note'    => $stopped
			? __( 'The import was asked to stop. It stops after the listing it is working on.', 'mlsimport' )
			: __( 'No import of this task is running.', 'mlsimport' ),
	);
}

/**
 * Tool revive-import: restart the worker of an import that went silent.
 *
 * @param array $input {
 *     @type int $task_id Import Task whose import to restart.
 * }
 * @return array|WP_Error Whether a worker was queued, and why not.
 */
function mlsimport_mcp_revive_import( array $input ) {
	global $mlsimport;
	$task_id = isset( $input['task_id'] ) ? (int) $input['task_id'] : 0;

	// Step 1: the task must exist.
	$refusal = mlsimport_mcp_require_task( $task_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: the progress poll's own watchdog.
	$revive = $mlsimport->admin->mlsimport_revive_import_for_task( $task_id );

	// Step 3: the reason in plain words.
	$notes = array(
		''                => __( 'A new background worker was queued; the import continues where it stopped.', 'mlsimport' ),
		'no_active_run'   => __( 'This task has no import in progress.', 'mlsimport' ),
		'recent_activity' => __( 'The import is still active; nothing to restart.', 'mlsimport' ),
		'stalled'         => __( 'The server ended the import worker several times at the same listing, so the import was marked failed. See import-status for the reason.', 'mlsimport' ),
	);
	$reason = (string) ( $revive['reason'] ?? '' );

	return array(
		'restarted' => true === ( $revive['revived'] ?? false ),
		'reason'    => $reason,
		'note'      => $notes[ $reason ] ?? '',
	);
}
