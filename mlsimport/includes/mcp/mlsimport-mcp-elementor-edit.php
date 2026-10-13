<?php
/**
 * AI tools (MCP), Elementor sites: change, move and remove any element of a
 * page's Elementor document by its id.
 *
 * Three tools for the owner who had the AI build something and wants it
 * different: update-elementor-element lays new settings over an element
 * (desktop, tablet and mobile values alike), move-elementor-element puts it
 * somewhere else on the page (same placement rules as add-elementor-element)
 * and remove-elementor-element takes it away with everything inside it.
 * The ids come from get-elementor-page. Every change goes through
 * Elementor's document save, so the page's CSS is rebuilt on the next visit.
 *
 * Public interface:
 *   mlsimport_mcp_elementor_edit_tools()                  the tools of this file
 *   mlsimport_mcp_elementor_target( $elements, $input )   the element named by element_id
 *   mlsimport_mcp_elementor_stack_of( $element )          the element type of a stored element
 *   mlsimport_mcp_update_elementor_element( $input )      tool: update-elementor-element
 *   mlsimport_mcp_move_elementor_element( $input )        tool: move-elementor-element
 *   mlsimport_mcp_remove_elementor_element( $input )      tool: remove-elementor-element
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
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_elementor_edit_tools(): array {
	$page_id    = array(
		'type'        => 'integer',
		'description' => 'The id of the page.',
	);
	$element_id = array(
		'type'        => 'string',
		'description' => 'The id of the element, from get-elementor-page.',
	);
	return array(
		'update-elementor-element' => array(
			'label'       => 'Change an element of an Elementor page',
			'description' => 'Changes the settings of one element of a page built with Elementor (any container, section, column or widget), found by its id from get-elementor-page. The settings you name change (names from list-elementor-controls; a responsive one takes _tablet / _mobile variants), a null value removes one, everything else stays. For a plugin widget the settings are Elementor\'s (a toggle is "yes" or ""); update-page-block takes the page-block names instead.',
			'input'       => array(
				'page_id'    => $page_id,
				'element_id' => $element_id,
				'settings'   => array(
					'type'                 => 'object',
					'description'          => 'Setting name => new value, or null to remove it.',
					'additionalProperties' => true,
				),
			),
			'required'    => array( 'page_id', 'element_id', 'settings' ),
			'callback'    => 'mlsimport_mcp_update_elementor_element',
			'changes'     => true,
		),
		'move-elementor-element'   => array(
			'label'       => 'Move an element on an Elementor page',
			'description' => 'Moves one element of a page built with Elementor, with everything inside it, to another place on the page: into a parent (at the start or the end) or right after another element. The same rules as add-elementor-element say where each type may go.',
			'input'       => array_merge(
				array(
					'page_id'    => $page_id,
					'element_id' => $element_id,
				),
				mlsimport_mcp_elementor_placement_input()
			),
			'required'    => array( 'page_id', 'element_id' ),
			'callback'    => 'mlsimport_mcp_move_elementor_element',
			'changes'     => true,
		),
		'remove-elementor-element' => array(
			'label'       => 'Remove an element from an Elementor page',
			'description' => 'Removes one element of a page built with Elementor, found by its id from get-elementor-page, together with everything inside it (a container goes with its widgets, a section with its columns). The rest of the page stays.',
			'input'       => array(
				'page_id'    => $page_id,
				'element_id' => $element_id,
			),
			'required'    => array( 'page_id', 'element_id' ),
			'callback'    => 'mlsimport_mcp_remove_elementor_element',
			'changes'     => true,
		),
	);
}

/**
 * The element named by element_id, with its parent id, or the refusal.
 *
 * @param array $elements The document tree.
 * @param array $input    The tool input (element_id).
 * @return array|WP_Error [ 'element' => array, 'parent' => id ], or mlsimport_elementor_element_unknown_id.
 */
function mlsimport_mcp_elementor_target( array $elements, array $input ) {
	$id    = sanitize_key( (string) ( $input['element_id'] ?? '' ) );
	$found = mlsimport_mcp_elementor_tree_find( $elements, $id );
	if ( null === $found ) {
		return new WP_Error(
			'mlsimport_elementor_element_unknown_id',
			/* translators: %s: the element id sent. */
			sprintf( __( 'There is no element with the id %s on this page. get-elementor-page lists them.', 'mlsimport' ), $id )
		);
	}
	return $found;
}

/**
 * The element type (for its controls) of an element stored on a page.
 *
 * @param array $element The stored element.
 * @return \Elementor\Element_Base|WP_Error The type, or the refusal when its
 *                                          widget is not registered on this site.
 */
function mlsimport_mcp_elementor_stack_of( array $element ) {
	$name  = 'widget' === ( $element['elType'] ?? '' ) ? (string) ( $element['widgetType'] ?? '' ) : (string) ( $element['elType'] ?? '' );
	$stack = mlsimport_mcp_elementor_stack( $name );
	if ( is_wp_error( $stack ) ) {
		return new WP_Error(
			'mlsimport_elementor_widget_unregistered',
			/* translators: %s: the widget type. */
			sprintf( __( 'The widget type %s is not registered on this site (its plugin may be inactive), so its settings cannot be checked.', 'mlsimport' ), $name )
		);
	}
	return $stack;
}

/**
 * Tool update-elementor-element: lay settings over one element.
 *
 * Step by step:
 * 1. The page, the element and its type must exist; settings must be sent.
 * 2. Every setting name must be a control of the type.
 * 3. A null removes the setting, anything else replaces it; the rest stay.
 * 4. Save through Elementor and answer with the element as it is now.
 *
 * @param array $input page_id, element_id, settings.
 * @return array|WP_Error The element row, or the refusal.
 */
function mlsimport_mcp_update_elementor_element( array $input ) {
	// Step 1.
	$document = mlsimport_mcp_elementor_document_of( $input );
	if ( is_wp_error( $document ) ) {
		return $document;
	}
	$elements = (array) $document->get_elements_data();
	$found    = mlsimport_mcp_elementor_target( $elements, $input );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
	if ( empty( $settings ) ) {
		return new WP_Error( 'mlsimport_elementor_settings_missing', __( 'Name at least one setting to change. list-elementor-controls names them.', 'mlsimport' ) );
	}
	$stack = mlsimport_mcp_elementor_stack_of( $found['element'] );
	if ( is_wp_error( $stack ) ) {
		return $stack;
	}

	// Step 2.
	$checked = mlsimport_mcp_elementor_settings_check( $stack, $settings );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}

	// Step 3.
	$element = $found['element'];
	$stored  = (array) ( $element['settings'] ?? array() );
	foreach ( $settings as $name => $value ) {
		unset( $stored[ $name ] );
		if ( null !== $value ) {
			$stored[ $name ] = $value;
		}
	}
	$element['settings'] = $stored;

	// Step 4.
	$saved = mlsimport_mcp_elementor_commit( $document, mlsimport_mcp_elementor_tree_replace( $elements, (string) $element['id'], $element ) );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$rows = array_column( mlsimport_mcp_elementor_flat( (array) $document->get_elements_data() ), null, 'id' );
	return array(
		'page_id'  => (int) $document->get_main_id(),
		'element'  => $rows[ $element['id'] ] ?? array(),
		'edit_url' => (string) $document->get_edit_url(),
	);
}

/**
 * Tool move-elementor-element: put one element somewhere else on the page.
 *
 * Step by step:
 * 1. The page and the element must exist; the place asked for must exist.
 * 2. The element may not move into itself or anything inside it.
 * 3. Its type must be allowed in the new parent (mlsimport_mcp_elementor_fits()).
 * 4. Take it out of the tree, put it in the new place, save.
 *
 * @param array $input page_id, element_id, parent, after, position.
 * @return array|WP_Error The element row as it is now, or the refusal.
 */
function mlsimport_mcp_move_elementor_element( array $input ) {
	// Step 1.
	$document = mlsimport_mcp_elementor_document_of( $input );
	if ( is_wp_error( $document ) ) {
		return $document;
	}
	$elements = (array) $document->get_elements_data();
	$found    = mlsimport_mcp_elementor_target( $elements, $input );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	$place = mlsimport_mcp_elementor_placement( $elements, $input );
	if ( is_wp_error( $place ) ) {
		return $place;
	}
	$element = $found['element'];

	// Step 2.
	if ( '' !== $place['parent'] && mlsimport_mcp_elementor_tree_contains( $element, $place['parent'] ) ) {
		return new WP_Error( 'mlsimport_elementor_move_loop', __( 'An element cannot move into itself or into something inside it.', 'mlsimport' ) );
	}

	// Step 3.
	$fits = mlsimport_mcp_elementor_fits( (string) ( $element['elType'] ?? '' ), $place['parent_element'] );
	if ( is_wp_error( $fits ) ) {
		return $fits;
	}

	// Step 4. A container or section is an inner one when it has a parent.
	if ( isset( $element['isInner'] ) ) {
		$element['isInner'] = '' !== $place['parent'];
	}
	$without = mlsimport_mcp_elementor_tree_remove( $elements, (string) $element['id'] );
	$saved   = mlsimport_mcp_elementor_commit( $document, (array) mlsimport_mcp_elementor_tree_insert( $without, $element, $place['parent'], $place['after'], $place['position'] ) );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$rows = array_column( mlsimport_mcp_elementor_flat( (array) $document->get_elements_data() ), null, 'id' );
	return array(
		'page_id'  => (int) $document->get_main_id(),
		'element'  => $rows[ $element['id'] ] ?? array(),
		'edit_url' => (string) $document->get_edit_url(),
	);
}

/**
 * Tool remove-elementor-element: take one element, and all inside it, away.
 *
 * @param array $input page_id, element_id.
 * @return array|WP_Error The removed id and how many elements went with it, or the refusal.
 */
function mlsimport_mcp_remove_elementor_element( array $input ) {
	$document = mlsimport_mcp_elementor_document_of( $input );
	if ( is_wp_error( $document ) ) {
		return $document;
	}
	$elements = (array) $document->get_elements_data();
	$found    = mlsimport_mcp_elementor_target( $elements, $input );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	$id    = (string) $found['element']['id'];
	$saved = mlsimport_mcp_elementor_commit( $document, mlsimport_mcp_elementor_tree_remove( $elements, $id ) );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return array(
		'page_id'          => (int) $document->get_main_id(),
		'removed'          => $id,
		'elements_removed' => count( mlsimport_mcp_elementor_flat( array( $found['element'] ) ) ),
		'edit_url'         => (string) $document->get_edit_url(),
	);
}
