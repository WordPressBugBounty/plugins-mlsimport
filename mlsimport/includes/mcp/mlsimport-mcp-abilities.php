<?php
/**
 * AI tools (MCP): the list of tools and their registration as WordPress abilities.
 *
 * This file owns WHICH tools exist and how each one is described to the AI.
 * The tool bodies and their descriptions live in the per-area files of this
 * folder; mlsimport_mcp_tool_areas() names them. Every tool:
 *   - is a WordPress ability in the category "mlsimport", named
 *     "mlsimport/<tool>";
 *   - is allowed for administrators only (manage_options), checked on every call;
 *   - carries the readonly / destructive / idempotent hints the AI app shows;
 *   - when it changes something, is recorded in AI Activity.
 *
 * The description of each tool says what must exist first. There are no MCP
 * prompts: the order of steps is carried by these descriptions and by
 * refusals that name the next step.
 *
 * Add-ons can add their own tools with the 'mlsimport_mcp_abilities' filter.
 *
 * Public interface:
 *   mlsimport_mcp_tool_areas()            the per-area functions that list tools
 *   mlsimport_mcp_ability_definitions()   the tools, keyed by tool name
 *   mlsimport_mcp_register_category()     hook: wp_abilities_api_categories_init
 *   mlsimport_mcp_register_abilities()    hook: wp_abilities_api_init
 *   mlsimport_mcp_can_use_tools()         the permission every tool checks
 *   mlsimport_mcp_create_server()         hook: mcp_adapter_init
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The functions that list the tools, one per area file of this folder.
 *
 * @return string[] Function names; each returns tools keyed by tool name.
 */
function mlsimport_mcp_tool_areas(): array {
	$areas = array(
		'mlsimport_mcp_account_tools',
		'mlsimport_mcp_connection_tools',
		'mlsimport_mcp_connection_manage_tools',
		'mlsimport_mcp_mapping_tools',
		'mlsimport_mcp_task_tools',
		'mlsimport_mcp_task_read_tools',
		'mlsimport_mcp_task_write_tools',
		'mlsimport_mcp_import_tools',
		'mlsimport_mcp_import_control_tools',
		'mlsimport_mcp_duplicates_tools',
		'mlsimport_mcp_history_tools',
		'mlsimport_mcp_event_tools',
		'mlsimport_mcp_notification_tools',
		'mlsimport_mcp_tools_tab_tools',
		'mlsimport_mcp_site_tools',
		'mlsimport_mcp_destructive_tools',
		'mlsimport_mcp_menu_tools',
		'mlsimport_mcp_menu_item_tools',
		'mlsimport_mcp_menu_item_edit_tools',
		'mlsimport_mcp_menu_delete_tools',
		'mlsimport_mcp_menu_location_tools',
		'mlsimport_mcp_page_tools',
		'mlsimport_mcp_front_page_tools',
		'mlsimport_mcp_page_delete_tools',
		'mlsimport_mcp_media_tools',
		'mlsimport_mcp_site_identity_tools',
		'mlsimport_mcp_post_tools',
		'mlsimport_mcp_post_manage_tools',
		'mlsimport_mcp_custom_css_tools',
	);

	// The Elementor tools exist only while Elementor is active: they read and
	// write a page's Elementor document through Elementor itself.
	if ( class_exists( '\Elementor\Plugin' ) ) {
		$areas[] = 'mlsimport_mcp_elementor_tools';
		$areas[] = 'mlsimport_mcp_elementor_controls_tools';
		$areas[] = 'mlsimport_mcp_elementor_add_tools';
		$areas[] = 'mlsimport_mcp_elementor_edit_tools';
		$areas[] = 'mlsimport_mcp_elementor_page_tools';
	}

	// The standalone tools exist only when the site runs in standalone mode
	// (theme 990): only there does the plugin own the property pages, the
	// agents, the design settings and the page blocks.
	if ( mlsimport_is_standalone_mode() ) {
		$areas[] = 'mlsimport_mcp_standalone_listing_tools';
		$areas[] = 'mlsimport_mcp_standalone_listing_edit_tools';
		$areas[] = 'mlsimport_mcp_standalone_listing_delete_tools';
		$areas[] = 'mlsimport_mcp_standalone_people_tools';
		$areas[] = 'mlsimport_mcp_standalone_people_write_tools';
		$areas[] = 'mlsimport_mcp_standalone_site_tools';
		$areas[] = 'mlsimport_mcp_standalone_page_tools';
		$areas[] = 'mlsimport_mcp_standalone_page_edit_tools';
	}
	return $areas;
}

/**
 * The tools, keyed by tool name.
 *
 * Each entry: label, description, input (JSON-schema properties), required
 * (input names), callback (the tool body), changes (true when the tool
 * changes something; false for a read) and, optionally, destructive (true
 * when the tool deletes something).
 *
 * @return array<string, array<string, mixed>>
 */
function mlsimport_mcp_ability_definitions(): array {
	// Step 1: collect the tools of every area. Each area file owns the
	// description of its own tools, next to the tool bodies.
	$definitions = array();
	foreach ( mlsimport_mcp_tool_areas() as $area_tools ) {
		$definitions += call_user_func( $area_tools );
	}

	/**
	 * Filters the AI tools MLSImport offers.
	 *
	 * @param array<string, array<string, mixed>> $definitions Tools keyed by tool name.
	 */
	return apply_filters( 'mlsimport_mcp_abilities', $definitions );
}

/**
 * The permission every tool checks: administrators only.
 *
 * @return bool
 */
function mlsimport_mcp_can_use_tools(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Register the "mlsimport" ability category.
 *
 * @return void
 */
function mlsimport_mcp_register_category(): void {
	wp_register_ability_category(
		'mlsimport',
		array(
			'label'       => __( 'MLSImport', 'mlsimport' ),
			'description' => __( 'Set up and operate the MLSImport plugin.', 'mlsimport' ),
		)
	);
}

/**
 * Register every tool as a WordPress ability.
 *
 * Step by step, for each tool:
 * 1. Build the input schema from the tool's input properties.
 * 2. Wrap the tool body so a changing tool is recorded in AI Activity. The
 *    record holds which tool was called, never the values passed to it.
 * 3. Register the ability with the administrators-only permission and the
 *    readonly / destructive / idempotent hints.
 *
 * @return void
 */
function mlsimport_mcp_register_abilities(): void {
	foreach ( mlsimport_mcp_ability_definitions() as $tool => $definition ) {
		// Step 1: the input schema. A tool without inputs declares none.
		$args = array(
			'label'               => $definition['label'],
			'description'         => $definition['description'],
			'category'            => 'mlsimport',
			'permission_callback' => 'mlsimport_mcp_can_use_tools',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => ! $definition['changes'],
					'destructive' => ! empty( $definition['destructive'] ),
					'idempotent'  => ! $definition['changes'],
				),
			),
		);
		if ( ! empty( $definition['input'] ) ) {
			$args['input_schema'] = array(
				'type'       => 'object',
				'properties' => $definition['input'],
				'required'   => $definition['required'],
			);
		}

		// Step 2: the body, with the AI Activity record for changing tools.
		$callback                 = $definition['callback'];
		$changes                  = (bool) $definition['changes'];
		$args['execute_callback'] = static function ( $input = array() ) use ( $tool, $callback, $changes ) {
			if ( $changes ) {
				mlsimport_ai_activity_record( $tool );
			}
			return call_user_func( $callback, is_array( $input ) ? $input : array() );
		};

		// Step 3: register.
		wp_register_ability( 'mlsimport/' . $tool, $args );
	}
}

/**
 * Create the one MLSImport MCP server at /wp-json/mlsimport/mcp.
 *
 * Hooked on mcp_adapter_init. The server lists only MLSImport's tools and is
 * open to administrators only; each tool checks the same capability again.
 *
 * @param \WP\MCP\Core\McpAdapter $adapter The adapter instance.
 * @return void
 */
function mlsimport_mcp_create_server( $adapter ): void {
	$tools = array();
	foreach ( array_keys( mlsimport_mcp_ability_definitions() ) as $tool ) {
		$tools[] = 'mlsimport/' . $tool;
	}

	$adapter->create_server(
		'mlsimport',
		'mlsimport',
		'mcp',
		'MLSImport',
		'Set up and operate the MLSImport plugin on this WordPress site. Claude asks before every change unless Skip approvals is turned on for this connector, once, in Claude > Settings > Connectors > MLSImport.',
		MLSIMPORT_VERSION,
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		$tools,
		array(),
		array(),
		'mlsimport_mcp_can_use_tools'
	);
}
