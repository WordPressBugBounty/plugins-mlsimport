<?php
/**
 * AI tools (MCP), standalone mode only: the block editor side of reading and
 * changing the page blocks a page already has.
 *
 * A block editor page is its content: a list of blocks, some nested inside
 * others (a group, columns). The plugin's page blocks are the ones named
 * mlsimport/<slug with dashes> whose slug is a registered page block. This
 * file walks that list, in page order, counting only the plugin's blocks, so
 * a "position" (1 is the first plugin block on the page) means the same to
 * page-blocks, update-page-block and remove-page-block. A change is written
 * back by serializing the walked list again, the markup the editor saves.
 *
 * Public interface:
 *   mlsimport_mcp_gutenberg_page_blocks( $content )        the plugin blocks of a page
 *   mlsimport_mcp_gutenberg_write( $page_id, $position, $edit ) change or remove one
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page block slug of a parsed block, or '' for any other block.
 *
 * @param array $block One parsed block (parse_blocks()).
 * @return string The slug, for example item_list, or ''.
 */
function mlsimport_mcp_gutenberg_slug( array $block ): string {
	$name = (string) ( $block['blockName'] ?? '' );
	if ( 0 !== strpos( $name, 'mlsimport/' ) ) {
		return '';
	}
	$slug = str_replace( '-', '_', substr( $name, strlen( 'mlsimport/' ) ) );
	return isset( mlsimport_get_page_blocks()[ $slug ] ) ? $slug : '';
}

/**
 * Walk the parsed blocks, nested ones included, visiting the plugin's blocks
 * in page order.
 *
 * Step by step:
 * 1. For a plugin block, call $visit( &$block, $slug ). The visitor may change
 *    the block in place; when it returns false the block is removed.
 * 2. A removed nested block also loses its placeholder in the parent's
 *    innerContent (a null entry per inner block), or the parent would
 *    serialize its remaining children against the wrong placeholders.
 * 3. Then walk the block's own inner blocks.
 *
 * @param array    $blocks Parsed blocks, changed in place.
 * @param callable $visit  function ( array &$block, string $slug ): bool.
 * @return void
 */
function mlsimport_mcp_gutenberg_walk( array &$blocks, callable $visit ): void {
	foreach ( array_keys( $blocks ) as $index ) {
		$slug = mlsimport_mcp_gutenberg_slug( $blocks[ $index ] );
		if ( '' !== $slug && false === $visit( $blocks[ $index ], $slug ) ) {
			unset( $blocks[ $index ] );
			continue;
		}
		if ( ! empty( $blocks[ $index ]['innerBlocks'] ) ) {
			$before = count( $blocks[ $index ]['innerBlocks'] );
			mlsimport_mcp_gutenberg_walk( $blocks[ $index ]['innerBlocks'], $visit );
			$removed = $before - count( $blocks[ $index ]['innerBlocks'] );
			// Drop one null placeholder per removed child, from the end (the order
			// of the remaining children is unchanged, only their count).
			$content = (array) ( $blocks[ $index ]['innerContent'] ?? array() );
			for ( $i = count( $content ) - 1; $i >= 0 && $removed > 0; $i-- ) {
				if ( null === $content[ $i ] ) {
					unset( $content[ $i ] );
					--$removed;
				}
			}
			$blocks[ $index ]['innerContent'] = array_values( $content );
		}
	}
	$blocks = array_values( $blocks );
}

/**
 * The plugin's page blocks of a block editor page, in page order.
 *
 * @param string $content The page content (post_content).
 * @return array List of [ slug, options, styles ]; options are the block's
 *               saved attributes without the styles, styles its `styles` attribute.
 */
function mlsimport_mcp_gutenberg_page_blocks( string $content ): array {
	$rows   = array();
	$blocks = parse_blocks( $content );
	mlsimport_mcp_gutenberg_walk(
		$blocks,
		static function ( array &$block, string $slug ) use ( &$rows ): bool {
			$attrs  = (array) ( $block['attrs'] ?? array() );
			$styles = isset( $attrs['styles'] ) && is_array( $attrs['styles'] ) ? $attrs['styles'] : array();
			unset( $attrs['styles'] );
			$rows[] = array(
				'slug'    => $slug,
				'options' => $attrs,
				'styles'  => $styles,
			);
			return true;
		}
	);
	return $rows;
}

/**
 * Change or remove the plugin block at one position of a block editor page.
 *
 * Step by step:
 * 1. Parse the page content and walk it; the plugin block at $position is
 *    handed to $edit( $block, $slug ), which returns the changed block, or
 *    null to remove it.
 * 2. Serialize the blocks again (the editor's own format) and save the page.
 *    The content is slashed, as wp_update_post() expects, so the JSON of the
 *    attributes keeps its escapes.
 *
 * @param int      $page_id  The page.
 * @param int      $position 1 is the first plugin block on the page.
 * @param callable $edit     function ( array $block, string $slug ): ?array.
 * @return string|WP_Error The page's edit link, or the failure.
 */
function mlsimport_mcp_gutenberg_write( int $page_id, int $position, callable $edit ) {
	$blocks = parse_blocks( (string) get_post_field( 'post_content', $page_id ) );
	$seen   = 0;
	mlsimport_mcp_gutenberg_walk(
		$blocks,
		static function ( array &$block, string $slug ) use ( &$seen, $position, $edit ): bool {
			if ( ++$seen !== $position ) {
				return true;
			}
			$changed = $edit( $block, $slug );
			if ( null === $changed ) {
				return false;
			}
			$block = $changed;
			return true;
		}
	);
	$saved = wp_update_post(
		array(
			'ID'           => $page_id,
			'post_content' => wp_slash( serialize_blocks( $blocks ) ),
		),
		true
	);
	return is_wp_error( $saved ) ? $saved : (string) get_edit_post_link( $page_id, 'raw' );
}
