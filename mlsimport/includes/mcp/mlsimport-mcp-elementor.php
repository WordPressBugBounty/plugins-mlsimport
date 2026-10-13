<?php
/**
 * AI tools (MCP), Elementor sites: read a page's whole Elementor document.
 *
 * The plugin's page-block tools (page-blocks, update-page-block ...) see
 * only the plugin's own widgets. The tools of this folder's Elementor files
 * see the whole document: every container, section, column and widget, so
 * the AI can build a hero section, put the search form inside it and style
 * any element. They exist only while Elementor is active (see
 * mlsimport_mcp_tool_areas() in mlsimport-mcp-abilities.php).
 *
 * The document is Elementor's tree (_elementor_data): elements with an id
 * (7 hex characters), elType (container, section, column, widget), settings,
 * elements (the children) and, for a widget, widgetType. The tools hand the
 * tree to the AI as a flat list in page order, each element naming its
 * parent, so any element can be targeted by its id. Elementor's own document
 * save writes every change (the editor's Update button), so Elementor sets
 * its meta and drops the page's CSS file, rebuilt on the next visit.
 *
 * Public interface:
 *   mlsimport_mcp_elementor_tools()                    the tools of this file
 *   mlsimport_mcp_elementor_document_of( $input )         the page's document, or the refusal
 *   mlsimport_mcp_elementor_flat( $elements )          the tree as a flat list
 *   mlsimport_mcp_elementor_page_answer( $document )   the answer of the read tools
 *   mlsimport_mcp_elementor_commit( $document, $elements ) save a changed tree through Elementor
 *   mlsimport_mcp_get_elementor_page( $input )         tool: get-elementor-page
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file (reading an Elementor page), as the tool list
 * expects them.
 *
 * Each entry: label, description, input, required, callback, changes. See
 * mlsimport_mcp_ability_definitions() in mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_elementor_tools(): array {
	return array(
		'get-elementor-page' => array(
			'label'       => 'Read an Elementor page',
			'description' => 'Reads the whole Elementor document of a page: every container, section, column and widget in page order, each with its id, type, widget, settings, parent and position among its siblings, plus the page template, hide_title and the page settings. Use it before add-elementor-element, update-elementor-element, move-elementor-element or remove-elementor-element: they target elements by id.',
			'input'       => array(
				'page_id' => array(
					'type'        => 'integer',
					'description' => 'The id of the page (list-pages).',
				),
			),
			'required'    => array( 'page_id' ),
			'callback'    => 'mlsimport_mcp_get_elementor_page',
			'changes'     => false,
		),
	);
}

/**
 * The Elementor document of the page named by page_id, or the refusal.
 *
 * Step by step:
 * 1. The page must exist (mlsimport_mcp_page()).
 * 2. The page must be built with Elementor: the editor marks it on its first
 *    save, create-page (builder elementor) marks it too.
 *
 * @param array $input The tool input (page_id).
 * @return \Elementor\Core\Base\Document|WP_Error The document, or the refusal.
 */
function mlsimport_mcp_elementor_document_of( array $input ) {
	$page = mlsimport_mcp_page( $input );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	if ( ! mlsimport_mcp_elementor_page( (int) $page->ID ) ) {
		return new WP_Error(
			'mlsimport_page_not_elementor',
			__( 'This page is not built with Elementor. Open it with Elementor once (Edit with Elementor), or on a standalone site create one with create-page and builder elementor.', 'mlsimport' )
		);
	}
	return \Elementor\Plugin::instance()->documents->get( (int) $page->ID );
}

/**
 * The document tree as a flat list in page order.
 *
 * Step by step, depth first:
 * 1. Each element becomes one row: id, type (its elType), widget (the
 *    widgetType, '' for a container, section or column), parent (the id of
 *    the element holding it, '' at the top level), position (1 is the first
 *    among its siblings) and its settings as stored.
 * 2. Its children follow it, with the element as their parent.
 *
 * @param array  $elements The elements of one level of the tree.
 * @param string $parent_id The id of the element holding this level ('' at the top).
 * @return array List of rows.
 */
function mlsimport_mcp_elementor_flat( array $elements, string $parent_id = '' ): array {
	$rows     = array();
	$position = 0;
	foreach ( $elements as $element ) {
		$element = (array) $element;
		++$position;
		$rows[] = array(
			'id'       => (string) ( $element['id'] ?? '' ),
			'type'     => (string) ( $element['elType'] ?? '' ),
			'widget'   => (string) ( $element['widgetType'] ?? '' ),
			'parent'   => $parent_id,
			'position' => $position,
			'settings' => (array) ( $element['settings'] ?? array() ),
		);
		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$rows = array_merge( $rows, mlsimport_mcp_elementor_flat( $element['elements'], (string) ( $element['id'] ?? '' ) ) );
		}
	}
	return $rows;
}

/**
 * The answer of the read tools: the page, its template, its page settings
 * and the flat list of its elements.
 *
 * The template is the page's WordPress template (_wp_page_template):
 * default (the theme's page template), elementor_header_footer (Elementor
 * Full Width), elementor_canvas (Elementor Canvas, no header or footer) or
 * elementor_theme. hide_title and the other page settings come from
 * Elementor's page settings meta.
 *
 * @param \Elementor\Core\Base\Document $document The page's document.
 * @return array The answer.
 */
function mlsimport_mcp_elementor_page_answer( $document ): array {
	$page_id  = (int) $document->get_main_id();
	$settings = get_post_meta( $page_id, '_elementor_page_settings', true );
	$settings = is_array( $settings ) ? $settings : array();
	$template = (string) get_post_meta( $page_id, '_wp_page_template', true );
	return array(
		'page_id'    => $page_id,
		'title'      => (string) get_the_title( $page_id ),
		'url'        => (string) get_permalink( $page_id ),
		'edit_url'   => (string) $document->get_edit_url(),
		'template'   => '' === $template ? 'default' : $template,
		'hide_title' => 'yes' === ( $settings['hide_title'] ?? '' ),
		'settings'   => $settings,
		'elements'   => mlsimport_mcp_elementor_flat( (array) $document->get_elements_data() ),
	);
}

/**
 * Save a changed tree through Elementor's document save (the editor's Update).
 *
 * @param \Elementor\Core\Base\Document $document The page's document.
 * @param array                         $elements The whole tree to store.
 * @return true|WP_Error
 */
function mlsimport_mcp_elementor_commit( $document, array $elements ) {
	if ( ! $document->save( array( 'elements' => $elements ) ) ) {
		return new WP_Error( 'mlsimport_elementor_save_failed', __( 'Elementor did not accept the page document.', 'mlsimport' ) );
	}
	return true;
}

/**
 * Tool get-elementor-page: the whole Elementor document of a page.
 *
 * @param array $input The tool input (page_id).
 * @return array|WP_Error The page, its settings and its elements, or the refusal.
 */
function mlsimport_mcp_get_elementor_page( array $input ) {
	$document = mlsimport_mcp_elementor_document_of( $input );
	if ( is_wp_error( $document ) ) {
		return $document;
	}
	return mlsimport_mcp_elementor_page_answer( $document );
}
