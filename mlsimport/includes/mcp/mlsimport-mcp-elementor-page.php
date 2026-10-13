<?php
/**
 * AI tools (MCP), Elementor sites: the page-level side of an Elementor page.
 *
 * Two tools. update-elementor-page sets what belongs to the page rather than
 * to an element: the page template (Elementor Full Width or Canvas, so a
 * hero is not boxed in by the theme's content width), whether the theme's
 * page title is hidden, and Elementor's page settings (the page background
 * and the rest of the document's own controls). regenerate-elementor-css
 * rebuilds the page's CSS file at once, or clears every Elementor CSS file
 * so each is rebuilt on its next visit: the step the editor's Update button
 * does, for changes made through other means.
 *
 * Public interface:
 *   mlsimport_mcp_elementor_page_tools()               the tools of this file
 *   mlsimport_mcp_elementor_templates()                the page templates and their meaning
 *   mlsimport_mcp_update_elementor_page( $input )      tool: update-elementor-page
 *   mlsimport_mcp_regenerate_elementor_css( $input )   tool: regenerate-elementor-css
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
function mlsimport_mcp_elementor_page_tools(): array {
	return array(
		'update-elementor-page'    => array(
			'label'       => 'Change the page settings of an Elementor page',
			'description' => 'Changes what belongs to the page itself on a page built with Elementor: the page template (default: the theme\'s page template, boxed by its content width; elementor_header_footer: Elementor Full Width, the theme\'s header and footer with full-width content; elementor_canvas: Elementor Canvas, a blank page without header or footer), hide_title (hide the theme\'s page title) and the page settings (names from list-elementor-controls with element "page": background_background, background_color, background_image, padding ...). A part you do not name keeps its value; a null setting removes it.',
			'input'       => array(
				'page_id'    => array(
					'type'        => 'integer',
					'description' => 'The id of the page.',
				),
				'template'   => array(
					'type'        => 'string',
					'enum'        => array( 'default', 'elementor_header_footer', 'elementor_canvas', 'elementor_theme' ),
					'description' => 'default, elementor_header_footer (Elementor Full Width), elementor_canvas (Elementor Canvas) or elementor_theme.',
				),
				'hide_title' => array(
					'type'        => 'boolean',
					'description' => 'Hide the theme\'s page title on this page.',
				),
				'settings'   => array(
					'type'                 => 'object',
					'description'          => 'Page setting => value, or null to remove it.',
					'additionalProperties' => true,
				),
			),
			'required'    => array( 'page_id' ),
			'callback'    => 'mlsimport_mcp_update_elementor_page',
			'changes'     => true,
		),
		'regenerate-elementor-css' => array(
			'label'       => 'Rebuild Elementor CSS',
			'description' => 'Rebuilds the Elementor CSS file of one page right away (page_id), or without page_id clears every Elementor CSS file so each page is rebuilt on its next visit. The element and page tools already drop the changed page\'s CSS; use this when a page shows stale styles.',
			'input'       => array(
				'page_id' => array(
					'type'        => 'integer',
					'description' => 'The page to rebuild; leave out to clear every page.',
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_regenerate_elementor_css',
			'changes'     => true,
		),
	);
}

/**
 * The page templates an Elementor page may use, with what each means.
 *
 * @return array<string, string> Template value => meaning.
 */
function mlsimport_mcp_elementor_templates(): array {
	return array(
		'default'                 => __( 'the theme\'s page template', 'mlsimport' ),
		'elementor_header_footer' => __( 'Elementor Full Width', 'mlsimport' ),
		'elementor_canvas'        => __( 'Elementor Canvas, no header or footer', 'mlsimport' ),
		'elementor_theme'         => __( 'the theme\'s template, chosen by Elementor', 'mlsimport' ),
	);
}

/**
 * Tool update-elementor-page: the template, hide_title and page settings.
 *
 * Step by step:
 * 1. The page must be built with Elementor; something must be sent.
 * 2. A template must be one of the four; a setting name must be a control
 *    of the document (its page settings).
 * 3. Lay the change over the stored page settings: hide_title is 'yes' or
 *    '' to Elementor, a null setting is removed, the template travels in
 *    the same settings (Elementor writes it to _wp_page_template).
 * 4. Save through Elementor's document save, elements untouched, and answer
 *    with the page as it is now (get-elementor-page's answer).
 *
 * @param array $input page_id, template, hide_title, settings.
 * @return array|WP_Error The page answer, or the refusal.
 */
function mlsimport_mcp_update_elementor_page( array $input ) {
	// Step 1.
	$document = mlsimport_mcp_elementor_document_of( $input );
	if ( is_wp_error( $document ) ) {
		return $document;
	}
	$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
	if ( ! isset( $input['template'] ) && ! isset( $input['hide_title'] ) && empty( $settings ) ) {
		return new WP_Error( 'mlsimport_elementor_settings_missing', __( 'Name something to change: template, hide_title or settings.', 'mlsimport' ) );
	}

	// Step 2.
	$templates = mlsimport_mcp_elementor_templates();
	if ( isset( $input['template'] ) && ! isset( $templates[ (string) $input['template'] ] ) ) {
		$names = array();
		foreach ( $templates as $value => $meaning ) {
			$names[] = $value . ' (' . $meaning . ')';
		}
		return new WP_Error(
			'mlsimport_elementor_template_unknown',
			/* translators: %s: the templates and their meaning. */
			sprintf( __( 'That is not a page template. The templates are: %s.', 'mlsimport' ), implode( ', ', $names ) )
		);
	}
	$checked = mlsimport_mcp_elementor_settings_check( $document, $settings );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}

	// Step 3.
	$page_id = (int) $document->get_main_id();
	$stored  = get_post_meta( $page_id, '_elementor_page_settings', true );
	$stored  = is_array( $stored ) ? $stored : array();
	foreach ( $settings as $name => $value ) {
		unset( $stored[ $name ] );
		if ( null !== $value ) {
			$stored[ $name ] = $value;
		}
	}
	if ( isset( $input['hide_title'] ) ) {
		$stored['hide_title'] = $input['hide_title'] ? 'yes' : '';
	}
	$stored['template'] = isset( $input['template'] ) ? (string) $input['template'] : (string) get_post_meta( $page_id, '_wp_page_template', true );

	// Step 4.
	if ( ! $document->save( array( 'settings' => $stored ) ) ) {
		return new WP_Error( 'mlsimport_elementor_save_failed', __( 'Elementor did not accept the page settings.', 'mlsimport' ) );
	}
	return mlsimport_mcp_elementor_page_answer( \Elementor\Plugin::instance()->documents->get( $page_id, false ) );
}

/**
 * Tool regenerate-elementor-css: rebuild one page's CSS now, or clear all.
 *
 * @param array $input The tool input: page_id (absent clears every page's CSS).
 * @return array|WP_Error The page and its CSS link, or regenerated: all.
 */
function mlsimport_mcp_regenerate_elementor_css( array $input ) {
	if ( empty( $input['page_id'] ) ) {
		\Elementor\Plugin::instance()->files_manager->clear_cache();
		return array(
			'regenerated' => 'all',
			'note'        => __( 'Every Elementor CSS file was cleared; each page is rebuilt on its next visit.', 'mlsimport' ),
		);
	}
	$document = mlsimport_mcp_elementor_document_of( $input );
	if ( is_wp_error( $document ) ) {
		return $document;
	}
	$page_id = (int) $document->get_main_id();
	$css     = \Elementor\Core\Files\CSS\Post::create( $page_id );
	$css->update();
	return array(
		'page_id' => $page_id,
		'css_url' => (string) $css->get_url(),
	);
}
