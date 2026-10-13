<?php
/**
 * AI tools (MCP), standalone mode only: read, change and remove the page
 * blocks a page already has.
 *
 * Three tools for the owner who had the AI add a block and now wants it
 * different: page-blocks reads what is on the page, update-page-block
 * changes one block's options (the names of list-page-blocks) and its look
 * (the style options of mlsimport_page_block_style_options(): colors and
 * sizes of the listing cards), remove-page-block takes one block away. The
 * AI never says which builder the page uses; the page says it, as in
 * add-page-blocks: an Elementor page is read and written through its
 * document (mlsimport-mcp-standalone-pages-elementor-edit.php), any other
 * page through its block content (mlsimport-mcp-standalone-pages-gutenberg.php).
 * A block is named by its position: 1 is the first plugin block on the page,
 * in page order, as page-blocks lists them. This file holds the tool list,
 * the shared checks and the read tool; the two change tools are in
 * mlsimport-mcp-standalone-pages-update.php.
 *
 * Public interface:
 *   mlsimport_mcp_standalone_page_edit_tools()             the tools of this file
 *   mlsimport_mcp_page( $input )                           the page of page_id, or the refusal
 *   mlsimport_mcp_page_block_styles_input( $slug, $styles ) check the styles sent
 *   mlsimport_mcp_page_block_rows( $page )                 the plugin blocks of a page
 *   mlsimport_mcp_page_blocks( $input )                    tool: page-blocks
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file and of mlsimport-mcp-standalone-pages-update.php,
 * as the tool list expects them.
 *
 * Each entry: label, description, input, required, callback, changes. See
 * mlsimport_mcp_ability_definitions() in mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_standalone_page_edit_tools(): array {
	$page_id  = array(
		'type'        => 'integer',
		'description' => 'The id of the page.',
	);
	$position = array(
		'type'        => 'integer',
		'description' => 'The position of the block on the page, from page-blocks (1 is the first plugin block).',
	);
	return array(
		'page-blocks'       => array(
			'label'       => 'Blocks on a page',
			'description' => 'Reads the plugin\'s page blocks on one page, in page order: each with its position, its options, its style options (colors and sizes of the listing cards) and their current values. Use it before update-page-block or remove-page-block. The page builder is read from the page itself.',
			'input'       => array( 'page_id' => $page_id ),
			'required'    => array( 'page_id' ),
			'callback'    => 'mlsimport_mcp_page_blocks',
			'changes'     => false,
		),
		'update-page-block' => array(
			'label'       => 'Change a block on a page',
			'description' => 'Changes one plugin block on a page, found by its position from page-blocks. The options you name change (names from list-page-blocks), the styles you name change (names and current values from page-blocks: a color is a hex value like #2a7f62, a size a whole number of pixels, an empty value removes that style). Everything else stays. Works the same on an Elementor page and on a block editor page.',
			'input'       => array(
				'page_id'  => $page_id,
				'position' => $position,
				'options'  => array(
					'type'                 => 'object',
					'description'          => 'Option name => new value.',
					'additionalProperties' => true,
				),
				'styles'   => array(
					'type'                 => 'object',
					'description'          => 'Style option => new value, or "" to remove it.',
					'additionalProperties' => true,
				),
			),
			'required'    => array( 'page_id', 'position' ),
			'callback'    => 'mlsimport_mcp_update_page_block',
			'changes'     => true,
		),
		'remove-page-block' => array(
			'label'       => 'Remove a block from a page',
			'description' => 'Removes one plugin block from a page, found by its position from page-blocks. The rest of the page stays.',
			'input'       => array(
				'page_id'  => $page_id,
				'position' => $position,
			),
			'required'    => array( 'page_id', 'position' ),
			'callback'    => 'mlsimport_mcp_remove_page_block',
			'changes'     => true,
			'destructive' => true,
		),
	);
}

/**
 * The page named by page_id, or the refusal.
 *
 * @param array $input The tool input (page_id).
 * @return WP_Post|WP_Error The page, or mlsimport_page_unknown.
 */
function mlsimport_mcp_page( array $input ) {
	$page = get_post( (int) ( $input['page_id'] ?? 0 ) );
	if ( ! $page || 'page' !== $page->post_type ) {
		return new WP_Error( 'mlsimport_page_unknown', __( 'There is no page with that id.', 'mlsimport' ) );
	}
	return $page;
}

/**
 * Check the styles the AI sent for one block.
 *
 * Step by step:
 * 1. Every name must be a style option of that block; a wrong one is refused
 *    naming the options the block has (or that it has none).
 * 2. An empty value means "remove this style" and becomes null.
 * 3. Any other value must be acceptable for its kind (a hex color, a whole
 *    number of pixels, columns 1 to 6).
 *
 * @param string $slug   Page block slug.
 * @param array  $styles Style option => value, as sent.
 * @return array|WP_Error Style option => clean value or null, or the refusal.
 */
function mlsimport_mcp_page_block_styles_input( string $slug, array $styles ) {
	$allowed = mlsimport_page_block_style_keys( $slug );
	$unknown = array_diff( array_map( 'strval', array_keys( $styles ) ), $allowed );
	if ( ! empty( $unknown ) ) {
		return new WP_Error(
			'mlsimport_style_unknown',
			empty( $allowed )
				/* translators: %s: the block. */
				? sprintf( __( 'The %s block has no style options.', 'mlsimport' ), $slug )
				/* translators: 1: the block, 2: the unknown style options, 3: the style options of that block. */
				: sprintf( __( 'The %1$s block has no style option %2$s. Its style options are: %3$s.', 'mlsimport' ), $slug, implode( ', ', array_map( 'sanitize_text_field', $unknown ) ), implode( ', ', $allowed ) )
		);
	}

	$checked = array();
	foreach ( $styles as $key => $value ) {
		if ( null === $value || '' === $value ) {
			$checked[ $key ] = null;
			continue;
		}
		$clean = mlsimport_page_block_style_value( $slug, (string) $key, $value );
		if ( null === $clean ) {
			$kind = mlsimport_page_block_style_options( $slug )[ $key ]['kind'];
			return new WP_Error(
				'mlsimport_style_value_invalid',
				/* translators: 1: the style option, 2: what it needs. */
				sprintf( __( '%1$s needs %2$s.', 'mlsimport' ), $key, 'color' === $kind ? __( 'a hex color like #2a7f62', 'mlsimport' ) : ( 'columns' === $kind ? __( 'a whole number from 1 to 6', 'mlsimport' ) : __( 'a whole number of pixels', 'mlsimport' ) ) )
			);
		}
		$checked[ $key ] = $clean;
	}
	return $checked;
}

/**
 * The plugin's blocks of a page, read from the page's own builder.
 *
 * @param WP_Post $page The page.
 * @return array List of [ slug, options, styles ], in page order.
 */
function mlsimport_mcp_page_block_rows( WP_Post $page ): array {
	return mlsimport_mcp_elementor_page( (int) $page->ID )
		? mlsimport_mcp_elementor_page_blocks( (int) $page->ID )
		: mlsimport_mcp_gutenberg_page_blocks( (string) $page->post_content );
}

/**
 * Tool page-blocks: the plugin's blocks on a page, with options and styles.
 *
 * @param array $input {
 *     @type int $page_id The page.
 * }
 * @return array|WP_Error The page, its builder, its links and its blocks.
 */
function mlsimport_mcp_page_blocks( array $input ) {
	$page = mlsimport_mcp_page( $input );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	$elementor = mlsimport_mcp_elementor_page( (int) $page->ID );
	$manifest  = mlsimport_get_page_blocks();

	$blocks = array();
	foreach ( mlsimport_mcp_page_block_rows( $page ) as $index => $row ) {
		$style_options = array();
		foreach ( mlsimport_page_block_style_options( $row['slug'] ) as $key => $option ) {
			$style_options[ $key ] = $option['label'];
		}
		$blocks[] = array(
			'position'      => $index + 1,
			'block'         => $row['slug'],
			'label'         => (string) $manifest[ $row['slug'] ]['label'],
			'options'       => $row['options'],
			'styles'        => $row['styles'],
			'style_options' => $style_options,
		);
	}

	$edit_url = $elementor ? (string) \Elementor\Plugin::instance()->documents->get( $page->ID )->get_edit_url() : (string) get_edit_post_link( $page->ID, 'raw' );
	return array(
		'page_id'  => (int) $page->ID,
		'title'    => $page->post_title,
		'builder'  => $elementor ? 'elementor' : 'gutenberg',
		'url'      => (string) get_permalink( $page->ID ),
		'edit_url' => $edit_url,
		'blocks'   => $blocks,
		'note'     => empty( $blocks ) ? __( 'This page has no plugin block yet; add-page-blocks adds one.', 'mlsimport' ) : __( 'Change a block with update-page-block (its position, the options and/or the styles), remove one with remove-page-block.', 'mlsimport' ),
	);
}
