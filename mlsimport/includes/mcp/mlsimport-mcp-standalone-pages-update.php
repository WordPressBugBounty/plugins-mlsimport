<?php
/**
 * AI tools (MCP), standalone mode only: change or remove one page block of a
 * page.
 *
 * The two change tools listed in mlsimport-mcp-standalone-pages-edit.php:
 * update-page-block lays new options and/or styles over one block,
 * remove-page-block takes one block off the page. Both find the block by its
 * position (1 is the first plugin block on the page, as page-blocks lists
 * them), check everything before the page is touched, write through the
 * page's own builder (the Elementor document or the block content) and
 * answer with the page's blocks as they are afterwards.
 *
 * Public interface:
 *   mlsimport_mcp_page_block_at( $page, $input )   the block at the asked position
 *   mlsimport_mcp_update_page_block( $input )      tool: update-page-block
 *   mlsimport_mcp_remove_page_block( $input )      tool: remove-page-block
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin block at the asked position of a page, or the refusal.
 *
 * @param WP_Post $page  The page.
 * @param array   $input The tool input (position).
 * @return array|WP_Error [ slug, options, styles ], or mlsimport_block_position_unknown.
 */
function mlsimport_mcp_page_block_at( WP_Post $page, array $input ) {
	$rows     = mlsimport_mcp_page_block_rows( $page );
	$position = (int) ( $input['position'] ?? 0 );
	if ( $position < 1 || $position > count( $rows ) ) {
		return new WP_Error(
			'mlsimport_block_position_unknown',
			empty( $rows )
				? __( 'This page has no plugin block; add-page-blocks adds one.', 'mlsimport' )
				/* translators: %d: how many plugin blocks the page has. */
				: sprintf( __( 'This page has %d plugin blocks; see their positions with page-blocks.', 'mlsimport' ), count( $rows ) )
		);
	}
	return $rows[ $position - 1 ];
}

/**
 * Tool update-page-block: change the options and/or the styles of one block.
 *
 * Step by step:
 * 1. The page and the position must exist, and at least one option or style
 *    must be named; the options and the styles are checked before anything
 *    is written (unknown names and bad values are refused).
 * 2. The page's builder decides the write: the Elementor widget gets the
 *    options as Elementor settings and the styles as Style tab controls laid
 *    over its settings; the block editor block gets the options as typed
 *    attributes and the styles in its `styles` attribute. A null style
 *    removes that style.
 * 3. Answer with the page's blocks as they are now.
 *
 * @param array $input {
 *     @type int   $page_id  The page.
 *     @type int   $position 1 is the first plugin block.
 *     @type array $options  Option name => value.
 *     @type array $styles   Style option => value, '' to remove.
 * }
 * @return array|WP_Error The page's blocks as now, or the refusal.
 */
function mlsimport_mcp_update_page_block( array $input ) {
	// Step 1: everything is checked before the page is touched.
	$page = mlsimport_mcp_page( $input );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	$row = mlsimport_mcp_page_block_at( $page, $input );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$options = isset( $input['options'] ) && is_array( $input['options'] ) ? $input['options'] : array();
	$styles  = isset( $input['styles'] ) && is_array( $input['styles'] ) ? $input['styles'] : array();
	if ( empty( $options ) && empty( $styles ) ) {
		return new WP_Error( 'mlsimport_block_change_missing', __( 'Name at least one option or style to change.', 'mlsimport' ) );
	}
	if ( ! empty( $options ) ) {
		$checked = mlsimport_mcp_page_blocks_input(
			array(
				array(
					'block'   => $row['slug'],
					'options' => $options,
				),
			)
		);
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$options = $checked[0]['options'];
	}
	$styles = mlsimport_mcp_page_block_styles_input( $row['slug'], $styles );
	if ( is_wp_error( $styles ) ) {
		return $styles;
	}

	// Step 2: the builder's own write.
	if ( mlsimport_mcp_elementor_page( (int) $page->ID ) ) {
		$written = mlsimport_mcp_elementor_write(
			(int) $page->ID,
			(int) $input['position'],
			static function ( array $element, string $slug ) use ( $options, $styles ): array {
				$settings            = array_merge( (array) ( $element['settings'] ?? array() ), mlsimport_mcp_elementor_settings( $slug, $options ) );
				$element['settings'] = mlsimport_mcp_elementor_style_settings( $slug, $styles, $settings );
				return $element;
			}
		);
	} else {
		$written = mlsimport_mcp_gutenberg_write(
			(int) $page->ID,
			(int) $input['position'],
			static function ( array $block, string $slug ) use ( $options, $styles ): array {
				$block['attrs'] = array_merge( (array) ( $block['attrs'] ?? array() ), mlsimport_mcp_gutenberg_attributes( $slug, $options ) );
				$current        = isset( $block['attrs']['styles'] ) && is_array( $block['attrs']['styles'] ) ? $block['attrs']['styles'] : array();
				foreach ( $styles as $key => $value ) {
					if ( null === $value ) {
						unset( $current[ $key ] );
					} else {
						$current[ $key ] = $value;
					}
				}
				unset( $block['attrs']['styles'] );
				if ( ! empty( $current ) ) {
					$block['attrs']['styles'] = $current;
				}
				return $block;
			}
		);
	}
	if ( is_wp_error( $written ) ) {
		return $written;
	}

	// Step 3: the page as it is now.
	return mlsimport_mcp_page_blocks( $input );
}

/**
 * Tool remove-page-block: take one block off a page.
 *
 * @param array $input {
 *     @type int $page_id  The page.
 *     @type int $position 1 is the first plugin block.
 * }
 * @return array|WP_Error The page's blocks as now, or the refusal.
 */
function mlsimport_mcp_remove_page_block( array $input ) {
	$page = mlsimport_mcp_page( $input );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	$row = mlsimport_mcp_page_block_at( $page, $input );
	if ( is_wp_error( $row ) ) {
		return $row;
	}
	$remove  = static function (): ?array {
		return null;
	};
	$written = mlsimport_mcp_elementor_page( (int) $page->ID )
		? mlsimport_mcp_elementor_write( (int) $page->ID, (int) $input['position'], $remove )
		: mlsimport_mcp_gutenberg_write( (int) $page->ID, (int) $input['position'], $remove );
	if ( is_wp_error( $written ) ) {
		return $written;
	}
	return mlsimport_mcp_page_blocks( $input );
}
