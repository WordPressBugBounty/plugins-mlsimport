<?php
/**
 * AI tools (MCP): MLS metadata and field mapping (the Field Options tab).
 *
 * Each function here is the body of one AI tool. A tool is a thin wrapper
 * around code the Field Options tab already uses:
 *   - gather-metadata runs mlsimport_connections_seed_execute(), the drawer's
 *     "pull the MLS fields and fill the mapping" step (it refuses an MLS that
 *     is not connected, then runs mlsimport_gather_connection_metadata()).
 *   - get-field-mapping reads mlsimport_active_field_configuration(), the
 *     list the import itself reads.
 *   - change-field-mapping sends one command through
 *     mlsimport_change_field_configuration(), the function the drag-and-drop
 *     screen saves with. The command needs the revision get-field-mapping
 *     returned, so the AI cannot overwrite a newer edit made in wp-admin.
 *
 * What "field mapping" means: for every field the MLS sends, whether it is
 * imported, whether only administrators see it, its label, and where it is
 * stored (a post meta key or a taxonomy).
 *
 * Deleting the stored values of fields lives in mlsimport-mcp-destructive.php.
 *
 * Public interface:
 *   mlsimport_mcp_mapping_tools()                 the tools of this file
 *   mlsimport_mcp_gather_metadata( $input )       tool: gather-metadata
 *   mlsimport_mcp_get_field_mapping( $input )     tool: get-field-mapping
 *   mlsimport_mcp_change_field_mapping( $input )  tool: change-field-mapping
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
function mlsimport_mcp_mapping_tools(): array {
	$text   = array( 'type' => 'string' );
	$mls_id = array(
		'type'        => 'integer',
		'description' => 'The MLS id of one of the site\'s MLS Connections, from list-mls-connections.',
	);

	return array(
		'gather-metadata'      => array(
			'label'       => 'Re-read the fields of an MLS',
			'description' => 'Reads the fields and the filter values again from the MLS for one MLS Connection and updates its field mapping: new fields the theme knows are switched on, existing choices are kept.',
			'input'       => array( 'mls_id' => $mls_id ),
			'required'    => array( 'mls_id' ),
			'callback'    => 'mlsimport_mcp_gather_metadata',
			'changes'     => true,
		),
		'get-field-mapping'    => array(
			'label'       => 'Field mapping of an MLS connection',
			'description' => 'Reads the field mapping of one MLS Connection: for each MLS field whether it is imported, whether only administrators see it, its label and where it is stored (post meta or taxonomy). Also returns the revision that change-field-mapping needs. The list is long; use search to narrow it.',
			'input'       => array(
				'mls_id'        => $mls_id,
				'search'        => array(
					'type'        => 'string',
					'description' => 'Part of a field name or label.',
				),
				'only_imported' => array(
					'type'        => 'boolean',
					'description' => 'True to list only the fields that are imported.',
				),
			),
			'required'    => array( 'mls_id' ),
			'callback'    => 'mlsimport_mcp_get_field_mapping',
			'changes'     => false,
		),
		'change-field-mapping' => array(
			'label'       => 'Change the field mapping',
			'description' => 'Changes the field mapping of one MLS Connection. Needs the revision from get-field-mapping; when the mapping changed in the meantime the call is refused and you read it again. Three kinds of change: "set" one property of one field; "bulk" switch import or admin on/off for several fields; "move" a field before or after another. Changes apply to listings imported from now on.',
			'input'       => array(
				'mls_id'   => $mls_id,
				'revision' => array(
					'type'        => 'integer',
					'description' => 'The revision returned by get-field-mapping.',
				),
				'type'     => array(
					'type' => 'string',
					'enum' => array( 'set', 'bulk', 'move' ),
				),
				'field'    => array(
					'type'        => 'string',
					'description' => 'The MLS field name (for set and move).',
				),
				'fields'   => array(
					'type'        => 'array',
					'items'       => $text,
					'description' => 'The MLS field names (for bulk).',
				),
				'property' => array(
					'type'        => 'string',
					'enum'        => array( 'import', 'admin', 'label', 'postmeta', 'taxonomy' ),
					'description' => 'What to change (for set and bulk; bulk takes import or admin only).',
				),
				'value'    => array(
					'type'        => array( 'string', 'boolean', 'integer' ),
					'description' => 'The new value: true/false for import and admin, text for label, postmeta and taxonomy.',
				),
				'anchor'   => array(
					'type'        => 'string',
					'description' => 'The field to move next to (for move).',
				),
				'position' => array(
					'type' => 'string',
					'enum' => array( 'before', 'after' ),
				),
			),
			'required'    => array( 'mls_id', 'revision', 'type' ),
			'callback'    => 'mlsimport_mcp_change_field_mapping',
			'changes'     => true,
		),
	);
}

/**
 * Tool gather-metadata: re-read the fields of one MLS and update its mapping.
 *
 * @param array $input {
 *     @type int $mls_id The MLS Connection.
 * }
 * @return array|WP_Error "gathered", or the plugin's own reason.
 */
function mlsimport_mcp_gather_metadata( array $input ) {
	$mls_id = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;

	// The drawer's seed step: refuses an unknown MLS, else gathers.
	$seed = mlsimport_connections_seed_execute( $mls_id );
	if ( ! $seed['success'] ) {
		return mlsimport_mcp_require_connection( 0 );
	}
	if ( ! $seed['seeded'] ) {
		return new WP_Error( 'mlsimport_' . $seed['code'], (string) $seed['message'] );
	}
	return array(
		'gathered' => true,
		'mls_id'   => $mls_id,
	);
}

/**
 * Tool get-field-mapping: the field mapping of one MLS Connection.
 *
 * @param array $input {
 *     @type int    $mls_id        The MLS Connection.
 *     @type string $search        Part of a field name or label.
 *     @type bool   $only_imported List only imported fields.
 * }
 * @return array|WP_Error The revision, the fields, and the taxonomies a field
 *                        can be stored in.
 */
function mlsimport_mcp_get_field_mapping( array $input ) {
	$mls_id  = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$refusal = mlsimport_mcp_require_connection( $mls_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 1: the mapping of that connection, fields the MLS has now only.
	// Refreshed, so a change made earlier in this request is included.
	$configuration = mlsimport_active_field_configuration( true, $mls_id );
	$imported      = (array) ( $configuration['mls-fields'] ?? array() );
	if ( empty( $imported ) ) {
		return new WP_Error(
			'mlsimport_metadata_missing',
			__( 'The fields of this MLS are not gathered yet. Gather them with gather-metadata first.', 'mlsimport' )
		);
	}

	// Step 2: one row per field, narrowed by the search and the imported flag.
	$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
	$fields = array();
	foreach ( $imported as $field => $is_imported ) {
		$label = (string) ( $configuration['mls-fields-label'][ $field ] ?? '' );
		if ( '' !== $search && false === stripos( $field, $search ) && false === stripos( $label, $search ) ) {
			continue;
		}
		if ( ! empty( $input['only_imported'] ) && 1 !== (int) $is_imported ) {
			continue;
		}
		$fields[] = array(
			'field'    => (string) $field,
			'import'   => 1 === (int) $is_imported,
			'admin'    => 1 === (int) ( $configuration['mls-fields-admin'][ $field ] ?? 0 ),
			'label'    => $label,
			'postmeta' => (string) ( $configuration['mls-fields-map-postmeta'][ $field ] ?? '' ),
			'taxonomy' => (string) ( $configuration['mls-fields-map-taxonomy'][ $field ] ?? '' ),
		);
	}

	return array(
		'mls_id'     => $mls_id,
		'revision'   => (int) ( $configuration[ Mlsimport_Field_Configuration::REVISION_KEY ] ?? 0 ),
		'total'      => count( $fields ),
		'fields'     => $fields,
		'taxonomies' => array_keys( mlsimport_field_configuration_taxonomies() ),
	);
}

/**
 * Tool change-field-mapping: send one change command for one MLS Connection.
 *
 * @param array $input See mlsimport_mcp_mapping_tools().
 * @return array|WP_Error The saved values of the touched fields and the new
 *                        revision, or the module's own reason for refusing.
 */
function mlsimport_mcp_change_field_mapping( array $input ) {
	$mls_id  = isset( $input['mls_id'] ) ? (int) $input['mls_id'] : 0;
	$refusal = mlsimport_mcp_require_connection( $mls_id );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 1: the command, with exactly the keys the screen's command has.
	$command = array();
	foreach ( array( 'type', 'field', 'fields', 'property', 'value', 'anchor', 'position' ) as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$command[ $key ] = $input[ $key ];
		}
	}

	// Step 2: the screen's own save. It validates the revision, the fields,
	// the property and the taxonomy before it stores anything.
	$result = mlsimport_change_field_configuration( $mls_id, max( 0, (int) ( $input['revision'] ?? 0 ) ), $command );

	// Step 3: a refusal carries the module's code and message. A stale
	// revision names the next step.
	if ( ! $result['success'] ) {
		$message = (string) $result['error']['message'];
		if ( 'stale_revision' === $result['error']['code'] ) {
			$message = __( 'The field mapping was changed since you read it. Read it again with get-field-mapping and repeat the change with the new revision.', 'mlsimport' );
		}
		return new WP_Error( 'mlsimport_' . $result['error']['code'], $message );
	}

	unset( $result['success'] );
	return $result;
}
