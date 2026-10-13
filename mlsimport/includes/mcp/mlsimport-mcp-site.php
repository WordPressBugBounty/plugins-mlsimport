<?php
/**
 * AI tools (MCP): the site environment and the setup wizard.
 *
 * Each function here is the body of one AI tool, a thin wrapper around what
 * the plugin already knows:
 *   - site-environment: mlsimport_resolve_theme_id() (the theme mode), the
 *     versions, the server's memory and time limits, and the number of import
 *     jobs waiting in the background queue (the execution module's own count).
 *   - onboarding-status: the setup wizard's saved step and "completed" flag.
 *   - run-test-import: mlsimport_run_test_import() (includes/mlsimport-onboarding.php),
 *     the wizard's 5-listing test import.
 *
 * The wizard's screens themselves are not tools: the AI does the same work
 * with the account, connection, mapping and task tools.
 *
 * Public interface:
 *   mlsimport_mcp_site_tools()                 the tools of this file
 *   mlsimport_mcp_site_environment()           tool: site-environment
 *   mlsimport_mcp_onboarding_status()          tool: onboarding-status
 *   mlsimport_mcp_run_test_import( $input )    tool: run-test-import
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
function mlsimport_mcp_site_tools(): array {
	$read = static function ( string $label, string $description, string $callback ): array {
		return array(
			'label'       => $label,
			'description' => $description,
			'input'       => array(),
			'required'    => array(),
			'callback'    => $callback,
			'changes'     => false,
		);
	};

	return array(
		'site-environment'  => $read( 'Site environment', 'Reads how the site is set up for MLSImport: the theme mode (Standalone, WpResidence, Houzez, Real Homes, WpEstate), plugin, WordPress and PHP versions, the server\'s memory and time limits, and how many import jobs wait in the background queue.', 'mlsimport_mcp_site_environment' ),
		'onboarding-status' => $read( 'Setup wizard status', 'Reads whether the wp-admin setup wizard was completed and which step it is on. The wizard itself is not run from here; the same work is done with the account, connection, mapping and task tools.', 'mlsimport_mcp_onboarding_status' ),
		'run-test-import'   => array(
			'label'       => 'Run a 5-listing test import',
			'description' => 'Starts the setup wizard\'s test: imports up to 5 listings of an import task in the background, to prove the setup works. It also sets the task to import 5 listings. Follow it with import-status.',
			'input'       => array(
				'task_id' => array(
					'type'        => 'integer',
					'description' => 'The import task id, from list-tasks or create-task.',
				),
			),
			'required'    => array( 'task_id' ),
			'callback'    => 'mlsimport_mcp_run_test_import',
			'changes'     => true,
		),
	);
}

/**
 * Tool site-environment: how the site is set up for MLSImport.
 *
 * @return array Theme mode, versions, server limits, background queue.
 */
function mlsimport_mcp_site_environment(): array {
	global $mlsimport;
	$theme_id = mlsimport_resolve_theme_id();

	// Import jobs waiting in Action Scheduler (the execution module's own count).
	$environment = new Mlsimport_Import_Task_Execution_WordPress_Environment( $mlsimport->admin );

	return array(
		'theme_id'              => $theme_id,
		'theme_mode'            => (string) ( MLSIMPORT_THEME[ $theme_id ] ?? '' ),
		'standalone'            => mlsimport_is_standalone_mode(),
		'supported_themes'      => MLSIMPORT_THEME,
		'plugin_version'        => MLSIMPORT_VERSION,
		'wordpress_version'     => get_bloginfo( 'version' ),
		'php_version'           => PHP_VERSION,
		'wp_memory_limit'       => (string) WP_MEMORY_LIMIT,
		'php_memory_limit'      => (string) ini_get( 'memory_limit' ),
		'max_execution_time'    => (int) ini_get( 'max_execution_time' ),
		'import_jobs_waiting'   => $environment->pending_worker_actions(),
		'recommended_minimums'  => __( 'At least 256MB of memory; max_execution_time 0 (unlimited) or at least 600 seconds.', 'mlsimport' ),
	);
}

/**
 * Tool onboarding-status: where the wp-admin setup wizard stands.
 *
 * Reads the saved values only; nothing is changed (the wizard's own
 * "current step" function also saves, so it is not used here).
 *
 * @return array completed, current_step and the wizard's steps in order.
 */
function mlsimport_mcp_onboarding_status(): array {
	$steps = array();
	foreach ( mlsimport_get_steps() as $step => $definition ) {
		$steps[] = array(
			'step'  => (string) $step,
			'title' => (string) $definition['title'],
		);
	}

	return array(
		'completed'    => (bool) get_option( 'mlsimport_onboarding_completed', false ),
		'current_step' => (string) get_option( 'mlsimport_onboarding_current_step', '' ),
		'steps'        => $steps,
	);
}

/**
 * Tool run-test-import: the wizard's 5-listing test import for one task.
 *
 * @param array $input {
 *     @type int $task_id Import Task to test.
 * }
 * @return array|WP_Error "started" with the run id, or the refusal.
 */
function mlsimport_mcp_run_test_import( array $input ) {
	$task_id = isset( $input['task_id'] ) ? (int) $input['task_id'] : 0;
	$refusal = mlsimport_mcp_require_task( $task_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// The wizard's own test: at most 5 listings, counted at the MLS first.
	$start = mlsimport_run_test_import( $task_id );
	if ( true !== $start['started'] ) {
		return new WP_Error( 'mlsimport_import_not_started', $start['message'] );
	}
	return array(
		'state'    => 'started',
		'run_id'   => $start['run_id'],
		'task_id'  => $task_id,
		'expected' => (int) $start['found'],
		'note'     => __( 'The test import runs in the background. Use import-status once when the client asks whether it is done; do not wait in a loop.', 'mlsimport' ),
	);
}
