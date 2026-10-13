<?php
/**
 * AI tools (MCP), Elementor sites: add an element to a page's Elementor document.
 *
 * One tool, add-elementor-element, adds a container, a section (with its
 * first column), a column, an Elementor widget (heading, text-editor,
 * image, button, spacer ...) or one of the plugin's widgets anywhere in the
 * document: at the start or end of the page, inside a given element, or
 * right after a given element. This is what lets the AI build a hero: a
 * container with a background, a heading inside it and the search form
 * after the heading.
 *
 * The rules of this file, shared with move-elementor-element:
 *   - where an element may go (mlsimport_mcp_elementor_fits()): a container
 *     at the top level or in a container; a section at the top level or,
 *     as an inner section, in a column; a column in a section; a widget in
 *     a container or a column; nothing inside a widget;
 *   - where the AI asked for (mlsimport_mcp_elementor_placement()): after
 *     wins over parent and position, and the ids must exist on the page;
 *   - settings are checked against the element type's controls
 *     (mlsimport_mcp_elementor_settings_check()); a plugin widget may be
 *     given its page-block options instead, translated as create-page does.
 *
 * Public interface:
 *   mlsimport_mcp_elementor_add_tools()                      the tools of this file
 *   mlsimport_mcp_elementor_placement( $elements, $input )   where the AI asked for
 *   mlsimport_mcp_elementor_fits( $type, $parent )           may this type go there?
 *   mlsimport_mcp_elementor_new_element( $stack, $settings ) a fresh element
 *   mlsimport_mcp_elementor_widget_settings( $stack, $input ) the settings to store
 *   mlsimport_mcp_add_elementor_element( $input )            tool: add-elementor-element
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The placement inputs, shared by add-elementor-element and move-elementor-element.
 *
 * @return array<string, array<string, mixed>> JSON-schema properties.
 */
function mlsimport_mcp_elementor_placement_input(): array {
	return array(
		'parent'   => array(
			'type'        => 'string',
			'description' => 'The id of the element to put it in (a container, section or column). Empty: the top level of the page.',
		),
		'after'    => array(
			'type'        => 'string',
			'description' => 'The id of the element to put it right after, in the same parent. Wins over parent and position.',
		),
		'position' => array(
			'type'        => 'string',
			'enum'        => array( 'start', 'end' ),
			'description' => 'First or last among its siblings (default end).',
		),
	);
}

/**
 * The AI tools of this file, as the tool list expects them.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_elementor_add_tools(): array {
	return array(
		'add-elementor-element' => array(
			'label'       => 'Add an element to an Elementor page',
			'description' => 'Adds one element to a page built with Elementor: a container (flexbox), a section (comes with its first column), a column, an Elementor widget (heading, text-editor, image, button, spacer ...) or a plugin widget (mlsimport-search-form, mlsimport-item-list ...). Place it with parent, after or position; a widget needs a parent container or column. settings are the names from list-elementor-controls (a responsive one takes _tablet / _mobile variants); a plugin widget may take its page-block options instead (names from list-page-blocks). Get the ids with get-elementor-page.',
			'input'       => array_merge(
				array(
					'page_id'  => array(
						'type'        => 'integer',
						'description' => 'The id of the page.',
					),
					'element'  => array(
						'type'        => 'string',
						'description' => 'container, section, column, or a widget name (heading, text-editor, image, button, spacer, mlsimport-search-form ...).',
					),
					'settings' => array(
						'type'                 => 'object',
						'description'          => 'Setting name => value, names from list-elementor-controls.',
						'additionalProperties' => true,
					),
					'options'  => array(
						'type'                 => 'object',
						'description'          => 'For a plugin widget: page-block option => value, names from list-page-blocks.',
						'additionalProperties' => true,
					),
				),
				mlsimport_mcp_elementor_placement_input()
			),
			'required'    => array( 'page_id', 'element' ),
			'callback'    => 'mlsimport_mcp_add_elementor_element',
			'changes'     => true,
		),
	);
}

/**
 * Where the AI asked to put an element.
 *
 * Step by step:
 * 1. after given: that element must exist; its parent becomes the parent.
 * 2. Otherwise parent given: it must exist and is the parent; '' is the top.
 * 3. The answer carries the parent element itself (null at the top) so the
 *    caller can check the type fits.
 *
 * @param array $elements The document tree.
 * @param array $input    The tool input (parent, after, position).
 * @return array|WP_Error [ parent => id, parent_element => array|null, after => id, position => start|end ].
 */
function mlsimport_mcp_elementor_placement( array $elements, array $input ) {
	$after    = sanitize_key( (string) ( $input['after'] ?? '' ) );
	$parent   = sanitize_key( (string) ( $input['parent'] ?? '' ) );
	$position = 'start' === ( $input['position'] ?? '' ) ? 'start' : 'end';
	$unknown  = static function ( string $id ): WP_Error {
		return new WP_Error(
			'mlsimport_elementor_element_unknown_id',
			/* translators: %s: the element id sent. */
			sprintf( __( 'There is no element with the id %s on this page. get-elementor-page lists them.', 'mlsimport' ), $id )
		);
	};

	if ( '' !== $after ) {
		$found = mlsimport_mcp_elementor_tree_find( $elements, $after );
		if ( null === $found ) {
			return $unknown( $after );
		}
		$parent = $found['parent'];
	}
	$parent_element = null;
	if ( '' !== $parent ) {
		$found = mlsimport_mcp_elementor_tree_find( $elements, $parent );
		if ( null === $found ) {
			return $unknown( $parent );
		}
		$parent_element = $found['element'];
	}
	return array(
		'parent'         => $parent,
		'parent_element' => $parent_element,
		'after'          => $after,
		'position'       => $position,
	);
}

/**
 * May an element of this type go inside that parent?
 *
 * @param string     $type   container, section, column or widget.
 * @param array|null $holder The parent element, null for the top level.
 * @return true|WP_Error True, or the refusal saying where the type may go.
 */
function mlsimport_mcp_elementor_fits( string $type, ?array $holder ) {
	$parent_type = null === $holder ? '' : (string) ( $holder['elType'] ?? '' );
	$allowed     = array(
		'container' => array( '', 'container' ),
		'section'   => array( '', 'column' ),
		'column'    => array( 'section' ),
		'widget'    => array( 'container', 'column' ),
	);
	if ( in_array( $parent_type, $allowed[ $type ] ?? array(), true ) ) {
		return true;
	}
	$where = array(
		'container' => __( 'at the top level of the page or inside a container', 'mlsimport' ),
		'section'   => __( 'at the top level of the page or, as an inner section, inside a column', 'mlsimport' ),
		'column'    => __( 'inside a section', 'mlsimport' ),
		'widget'    => __( 'inside a container or a column', 'mlsimport' ),
	);
	return new WP_Error(
		'mlsimport_elementor_place_invalid',
		/* translators: 1: the element type, 2: where it may go. */
		sprintf( __( 'A %1$s goes %2$s.', 'mlsimport' ), $type, $where[ $type ] ?? $type )
	);
}

/**
 * A fresh element of the given type with the given settings. A section gets
 * its first column, as the editor gives it one.
 *
 * @param \Elementor\Element_Base $stack    The element type.
 * @param array                   $settings The settings to store.
 * @param bool                    $inner    Nested (an inner section, a nested container).
 * @return array The element.
 */
function mlsimport_mcp_elementor_new_element( $stack, array $settings, bool $inner ): array {
	$type    = (string) $stack->get_type();
	$element = array(
		'id'       => mlsimport_mcp_elementor_element_id(),
		'elType'   => $type,
		'settings' => $settings,
		'elements' => array(),
	);
	if ( 'widget' === $type ) {
		$element['widgetType'] = (string) $stack->get_name();
	} else {
		$element['isInner'] = $inner;
	}
	if ( 'section' === $type ) {
		$element['elements'][] = array(
			'id'       => mlsimport_mcp_elementor_element_id(),
			'elType'   => 'column',
			'settings' => array( '_column_size' => 100 ),
			'elements' => array(),
			'isInner'  => $inner,
		);
	}
	return $element;
}

/**
 * The settings to store for a new element: the settings sent, plus, for a
 * plugin widget, its page-block options translated to Elementor's conventions.
 *
 * @param \Elementor\Element_Base $stack The element type.
 * @param array                   $input The tool input (settings, options).
 * @return array|WP_Error The settings, or the refusal.
 */
function mlsimport_mcp_elementor_widget_settings( $stack, array $input ) {
	$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
	$options  = isset( $input['options'] ) && is_array( $input['options'] ) ? $input['options'] : array();
	if ( ! empty( $options ) ) {
		$slug = str_replace( '-', '_', substr( (string) $stack->get_name(), strlen( 'mlsimport-' ) ) );
		$rows = mlsimport_mcp_page_blocks_input(
			array(
				array(
					'block'   => $slug,
					'options' => $options,
				),
			)
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$settings = mlsimport_mcp_elementor_settings( $slug, $options ) + $settings;
	}
	$checked = mlsimport_mcp_elementor_settings_check( $stack, $settings );
	return is_wp_error( $checked ) ? $checked : $settings;
}

/**
 * Tool add-elementor-element: add one element to a page's Elementor document.
 *
 * Step by step:
 * 1. The page must be built with Elementor; the element type must exist.
 * 2. Work out where it goes and check the type may go there.
 * 3. Check the settings (and translate plugin widget options).
 * 4. Insert into the tree and save through Elementor.
 *
 * @param array $input page_id, element, settings, options, parent, after, position.
 * @return array|WP_Error The new element (id, type, widget, parent), its first column for a section, and the edit link.
 */
function mlsimport_mcp_add_elementor_element( array $input ) {
	// Step 1.
	$document = mlsimport_mcp_elementor_document_of( $input );
	if ( is_wp_error( $document ) ) {
		return $document;
	}
	$stack = mlsimport_mcp_elementor_stack( (string) ( $input['element'] ?? '' ) );
	if ( is_wp_error( $stack ) ) {
		return $stack;
	}

	// Step 2.
	$elements = (array) $document->get_elements_data();
	$place    = mlsimport_mcp_elementor_placement( $elements, $input );
	if ( is_wp_error( $place ) ) {
		return $place;
	}
	$fits = mlsimport_mcp_elementor_fits( (string) $stack->get_type(), $place['parent_element'] );
	if ( is_wp_error( $fits ) ) {
		return $fits;
	}

	// Step 3.
	$settings = mlsimport_mcp_elementor_widget_settings( $stack, $input );
	if ( is_wp_error( $settings ) ) {
		return $settings;
	}

	// Step 4.
	$element = mlsimport_mcp_elementor_new_element( $stack, $settings, '' !== $place['parent'] );
	$saved   = mlsimport_mcp_elementor_commit( $document, (array) mlsimport_mcp_elementor_tree_insert( $elements, $element, $place['parent'], $place['after'], $place['position'] ) );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$answer = array(
		'page_id'  => (int) $document->get_main_id(),
		'element'  => array(
			'id'     => $element['id'],
			'type'   => $element['elType'],
			'widget' => (string) ( $element['widgetType'] ?? '' ),
			'parent' => $place['parent'],
		),
		'edit_url' => (string) $document->get_edit_url(),
	);
	if ( 'section' === $element['elType'] ) {
		$answer['column'] = $element['elements'][0]['id'];
	}
	return $answer;
}
