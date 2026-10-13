<?php
/**
 * AI tools (MCP): the Tools tab, the site environment and the setup wizard.
 *
 * Each function here is the body of one AI tool. A tool is a thin wrapper
 * around the plain functions the Tools tab itself uses
 * (includes/mlsimport-admin-tools.php, includes/mlsimport-settings-transfer.php):
 *   - export-settings   -> mlsimport_export_settings_payload()
 *   - import-settings   -> mlsimport_import_settings_json()
 *   - get-tool-switches -> mlsimport_tools_switches()
 *   - set-tool-switches -> mlsimport_tools_save_switches()
 *   - clear-cache       -> mlsimport_tools_clear_cache()
 *   - list-delete-terms -> mlsimport_get_custom_post_type_taxonomies() and
 *                          mlsimport_tools_taxonomy_terms()
 *
 * The exported settings hold the field mapping and sync settings; they hold
 * no login, password or MLS credential.
 *
 * import-settings overwrites the field mapping of every connection the
 * document names, so it needs two calls like the destructive tools
 * (mlsimport-mcp-confirm.php): the first call imports nothing and answers
 * with what would be replaced and a confirmation code.
 *
 * Deleting properties by term lives in mlsimport-mcp-destructive.php.
 *
 * The site environment and the setup wizard live in mlsimport-mcp-site.php.
 *
 * Public interface:
 *   mlsimport_mcp_tools_tab_tools()              the tools of this file
 *   mlsimport_mcp_export_settings()              tool: export-settings
 *   mlsimport_mcp_import_settings_preview( $s )  what a document would replace
 *   mlsimport_mcp_import_settings( $input )      tool: import-settings
 *   mlsimport_mcp_get_tool_switches()            tool: get-tool-switches
 *   mlsimport_mcp_set_tool_switches( $input )    tool: set-tool-switches
 *   mlsimport_mcp_clear_cache()                  tool: clear-cache
 *   mlsimport_mcp_list_delete_terms( $input )    tool: list-delete-terms
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
function mlsimport_mcp_tools_tab_tools(): array {
	$boolean = array( 'type' => 'boolean' );
	$read    = static function ( string $label, string $description, string $callback ): array {
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
		'export-settings'   => $read( 'Export the plugin settings', 'Reads the field mapping and sync settings of every MLS Connection as one settings document, the same one the Tools tab exports. It holds no login or credential. Give it to import-settings on another site.', 'mlsimport_mcp_export_settings' ),
		'import-settings'   => array(
			'label'       => 'Import plugin settings',
			'description' => 'Restores the field mapping and sync settings from a settings document made by export-settings. It replaces the current field mapping and sync settings of the MLS Connections named in the document. Needs two calls: the first call imports nothing and returns what would be replaced and a confirmation_code; tell the client, and only after they confirm call again with the same document and that code.',
			'input'       => array(
				'settings'          => array(
					'type'        => 'object',
					'description' => 'The settings document, exactly as export-settings returned it.',
				),
				'confirmation_code' => array(
					'type'        => 'string',
					'description' => 'The confirmation_code returned by the first call. Leave out on the first call.',
				),
			),
			'required'    => array( 'settings' ),
			'callback'    => 'mlsimport_mcp_import_settings',
			'changes'     => true,
			'destructive' => true,
		),
		'get-tool-switches' => $read( 'Tools tab switches', 'Reads the Tools tab switches: system logs on or off, property history on or off, and the theme the plugin works with.', 'mlsimport_mcp_get_tool_switches' ),
		'set-tool-switches' => array(
			'label'       => 'Change the Tools tab switches',
			'description' => 'Changes the Tools tab switches. Only the switches you name are changed. System logs should be on only while looking for a problem. theme_id is the theme the plugin stores listings for (see site-environment for the list); change it only when the client says the detected theme is wrong, because listings already imported stay in the old theme\'s format.',
			'input'       => array(
				'system_logs'      => $boolean,
				'property_history' => $boolean,
				'theme_id'         => array( 'type' => 'integer' ),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_set_tool_switches',
			'changes'     => true,
		),
		'clear-cache'       => array(
			'label'       => 'Clear the plugin cache',
			'description' => 'Clears the plugin\'s cached data (the saved sign-in token and cached MLS field lists). Nothing is lost: the plugin signs in and reads the fields again when needed. No listing, task or setting is touched.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_clear_cache',
			'changes'     => true,
		),
		'list-delete-terms' => array(
			'label'       => 'Categories that properties can be deleted by',
			'description' => 'Reads the taxonomies of the properties (city, county, status, type ...) and, when one taxonomy is named, its terms with how many properties each has. Use the taxonomy and term slugs with delete-properties-by-term.',
			'input'       => array(
				'taxonomy' => array(
					'type'        => 'string',
					'description' => 'A taxonomy slug from this tool\'s own list. Leave out to list the taxonomies.',
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_delete_terms',
			'changes'     => false,
		),
	);
}

/**
 * Tool export-settings: the Tools tab's settings document.
 *
 * @return array The settings document.
 */
function mlsimport_mcp_export_settings(): array {
	return array( 'settings' => mlsimport_export_settings_payload() );
}

/**
 * Which MLS Connections a settings document would overwrite, as a sentence.
 *
 * Step by step:
 * 1. A document with the per-connection block names its connections by id;
 *    the site's name is added when the id is one of the site's connections.
 * 2. A document without that block is a legacy flat export: it restores
 *    into the current connection.
 *
 * @param array $settings The settings document.
 * @return string Plain sentence: exactly what would be replaced.
 */
function mlsimport_mcp_import_settings_preview( array $settings ): string {
	// Step 1 + 2: the connection ids the importer would write to.
	$ids = isset( $settings['mlsimport_connections_settings'] ) && is_array( $settings['mlsimport_connections_settings'] )
		? array_keys( $settings['mlsimport_connections_settings'] )
		: array( mlsimport_current_mls_id() );

	$named = array();
	foreach ( $ids as $mls_id ) {
		$record  = Mlsimport_Connections::get( (int) $mls_id );
		$named[] = null === $record ? (string) (int) $mls_id : sprintf( '%s (%d)', $record['mls_name'], (int) $mls_id );
	}

	return sprintf(
		/* translators: %s: the MLS Connections, as "name (id)", comma separated. */
		__( 'The field mapping and sync settings of these MLS Connections are replaced by the document: %s. The global import options in the document replace the current ones too. Listings are not touched.', 'mlsimport' ),
		implode( ', ', $named )
	);
}

/**
 * Tool import-settings: restore a settings document (two calls).
 *
 * Step by step:
 * 1. The document must be a non-empty object, as the Tools tab's own
 *    importer requires, before anything else happens.
 * 2. First call: import nothing; answer with what would be replaced and a
 *    confirmation code (the destructive tools' gate, mlsimport-mcp-confirm.php).
 * 3. Confirmed: the Tools tab's own importer.
 *
 * @param array $input {
 *     @type array  $settings          The document export-settings returned.
 *     @type string $confirmation_code The code the first call returned.
 * }
 * @return array|WP_Error The preview, "imported", or the refusal.
 */
function mlsimport_mcp_import_settings( array $input ) {
	// Step 1: a document at all.
	$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
	if ( empty( $settings ) ) {
		return new WP_Error( 'mlsimport_settings_invalid', __( 'Paste a valid JSON object exported by MLSImport.', 'mlsimport' ) );
	}

	// Step 2: the first call is a preview; a wrong code is a refusal.
	$would_replace = mlsimport_mcp_import_settings_preview( $settings );
	$pending       = mlsimport_mcp_confirm_or_preview( 'import-settings', $input, $would_replace );
	if ( is_wp_error( $pending ) ) {
		return $pending;
	}
	if ( null !== $pending ) {
		return array(
			'imported'           => false,
			'would_replace'      => $would_replace,
			'confirmation_code'  => $pending['confirmation_code'],
			'code_valid_seconds' => $pending['code_valid_seconds'],
			'note'               => __( 'Nothing was imported. Tell the client exactly what would be replaced and ask them to confirm. Only after they say yes, call this tool again with the same document and this confirmation_code.', 'mlsimport' ),
		);
	}

	// Step 3: confirmed - the Tools tab's own importer (JSON text in, as the
	// tab sends it; it refuses anything that is not a JSON object).
	$result = mlsimport_import_settings_json( (string) wp_json_encode( $settings ) );
	if ( ! $result['success'] ) {
		return new WP_Error( 'mlsimport_settings_invalid', __( 'Paste a valid JSON object exported by MLSImport.', 'mlsimport' ) );
	}
	return array( 'imported' => true );
}

/**
 * Tool get-tool-switches: the Tools tab switches.
 *
 * @return array system_logs, property_history, theme_id, theme_name.
 */
function mlsimport_mcp_get_tool_switches(): array {
	$switches = mlsimport_tools_switches();
	return array(
		'system_logs'      => 1 === $switches['logs'],
		'property_history' => 1 === $switches['history'],
		'theme_id'         => $switches['theme'],
		'theme_name'       => (string) ( MLSIMPORT_THEME[ $switches['theme'] ] ?? '' ),
	);
}

/**
 * Tool set-tool-switches: change the Tools tab switches that are named.
 *
 * @param array $input system_logs, property_history, theme_id.
 * @return array|WP_Error The switches as saved, or the refusal.
 */
function mlsimport_mcp_set_tool_switches( array $input ) {
	$switches = array();
	if ( isset( $input['system_logs'] ) ) {
		$switches['logs'] = empty( $input['system_logs'] ) ? 0 : 1;
	}
	if ( isset( $input['property_history'] ) ) {
		$switches['history'] = empty( $input['property_history'] ) ? 0 : 1;
	}
	if ( isset( $input['theme_id'] ) ) {
		// Only a theme the plugin supports (the Tools tab's own dropdown list).
		if ( ! isset( MLSIMPORT_THEME[ (int) $input['theme_id'] ] ) ) {
			return new WP_Error( 'mlsimport_theme_unknown', __( 'That theme id is not supported. See the list with site-environment.', 'mlsimport' ) );
		}
		$switches['theme'] = (int) $input['theme_id'];
	}

	// The Tools tab's own save.
	mlsimport_tools_save_switches( $switches );
	return mlsimport_mcp_get_tool_switches();
}

/**
 * Tool clear-cache: the Tools tab's "Clear Plugin Cached Data" button.
 *
 * @return array "cleared".
 */
function mlsimport_mcp_clear_cache(): array {
	mlsimport_tools_clear_cache();
	return array( 'cleared' => true );
}

/**
 * Tool list-delete-terms: the property taxonomies, or the terms of one.
 *
 * @param array $input {
 *     @type string $taxonomy A taxonomy slug; leave out to list the taxonomies.
 * }
 * @return array|WP_Error The taxonomies, or the terms of the named one.
 */
function mlsimport_mcp_list_delete_terms( array $input ) {
	global $mlsimport;

	// The taxonomies of the property post type: the Tools tab's own list.
	$taxonomies = (array) mlsimport_get_custom_post_type_taxonomies( $mlsimport->admin->env_data->get_property_post_type() );
	$taxonomy   = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
	if ( '' === $taxonomy ) {
		return array( 'taxonomies' => $taxonomies );
	}

	if ( ! isset( $taxonomies[ $taxonomy ] ) ) {
		return new WP_Error( 'mlsimport_taxonomy_unknown', __( 'That is not a taxonomy of the properties. Call list-delete-terms without a taxonomy to see them.', 'mlsimport' ) );
	}
	return array(
		'taxonomy' => $taxonomy,
		'terms'    => mlsimport_tools_taxonomy_terms( $taxonomy ),
	);
}
