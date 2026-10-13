<?php
/**
 * AI tools (MCP), standalone mode only: add page blocks to an existing page.
 *
 * The tool add-page-blocks (defined in mlsimport-mcp-standalone-pages.php)
 * appends the plugin's page blocks to the end of a page that already exists.
 * The AI does not say which builder the page uses; the page says it:
 *   - a page built with Elementor (its document is marked as such) gets a new
 *     Elementor container holding one MLSImport widget per block, after the
 *     containers it already has, through Elementor's own document save;
 *   - any other page gets one block comment per page block after its content,
 *     the markup the block editor saves itself.
 * The checks (page exists, block exists, option exists) and the value rules
 * are those of create-page, so the two tools never disagree.
 *
 * Public interface:
 *   mlsimport_mcp_add_page_blocks( $input )    tool: add-page-blocks
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool add-page-blocks: append page blocks to the end of an existing page.
 *
 * Step by step:
 * 1. Refuse an unknown page, block or option before anything is written.
 * 2. A page built with Elementor gets the blocks as widgets in a new
 *    container at the end of its document; any other page gets the block
 *    comments after its content.
 * 3. Answer with the page id, its preview link and the edit link of its builder.
 *
 * @param array $input {
 *     @type int   $page_id The page to add to.
 *     @type array $blocks  List of { block, options }.
 * }
 * @return array|WP_Error The page id and links, or the refusal.
 */
function mlsimport_mcp_add_page_blocks( array $input ) {
	// Step 1: the page and every block and option must exist.
	$page = get_post( (int) ( $input['page_id'] ?? 0 ) );
	if ( ! $page || 'page' !== $page->post_type ) {
		return new WP_Error( 'mlsimport_page_unknown', __( 'There is no page with that id.', 'mlsimport' ) );
	}
	$rows = mlsimport_mcp_page_blocks_input( isset( $input['blocks'] ) && is_array( $input['blocks'] ) ? $input['blocks'] : array() );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}

	// Step 2: the page's own builder decides the format.
	if ( mlsimport_mcp_elementor_page( (int) $page->ID ) ) {
		$edit_url = mlsimport_mcp_elementor_append( (int) $page->ID, $rows );
	} else {
		$content = array( $page->post_content );
		foreach ( $rows as $row ) {
			$content[] = mlsimport_mcp_gutenberg_block( $row['slug'], $row['options'] );
		}
		$edit_url = wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => wp_slash( implode( "\n\n", array_filter( $content, 'strlen' ) ) ),
			),
			true
		);
		if ( ! is_wp_error( $edit_url ) ) {
			$edit_url = (string) get_edit_post_link( $page->ID, 'raw' );
		}
	}
	if ( is_wp_error( $edit_url ) ) {
		return $edit_url;
	}

	// Step 3: the answer: where to look at it and where to edit it.
	return array(
		'page_id'  => (int) $page->ID,
		'url'      => (string) get_permalink( $page->ID ),
		'edit_url' => $edit_url,
	);
}
