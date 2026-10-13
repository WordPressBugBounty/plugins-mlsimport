<?php
/**
 * AI tools (MCP): reading block-theme Navigation menus.
 *
 * A block theme (Twenty Twenty-Five ...) has no classic menu locations. Its
 * header holds a Navigation block, and the links of that block live in a
 * wp_navigation post whose content is block markup:
 *
 *   <!-- wp:navigation-link {"label":"About","type":"page","id":2,"url":"...","kind":"post-type"} /-->
 *   <!-- wp:navigation-submenu {"label":"More","url":"...","kind":"custom"} -->
 *     <!-- wp:navigation-link {"label":"Child","url":"...","kind":"custom"} /-->
 *   <!-- /wp:navigation-submenu -->
 *   <!-- wp:page-list /-->
 *
 * The menu tools (list-menus, add-menu-item ...) treat such a post as a menu of
 * type "navigation": its menu_id is the post id, and its items are the blocks
 * numbered 1, 2, 3 ... in menu order (depth first). A submenu is an item with
 * children; any other block (page-list, home-link ...) is an item too, of that
 * block's type, so it can be seen and removed.
 *
 * Which Navigation menu the header shows (mlsimport_mcp_navigation_shown()):
 * the one the header's Navigation block refers to (ref), or, when the block
 * has no ref, the most recently published one, which is what WordPress falls
 * back to.
 *
 * Public interface:
 *   mlsimport_mcp_navigation_posts()                        every published Navigation menu
 *   mlsimport_mcp_navigation_nodes( $content )              the blocks flattened: nodes + children per parent
 *   mlsimport_mcp_navigation_item_summary( $index, $node, $position )   one item, as the tools answer it
 *   mlsimport_mcp_navigation_summary( $post )               one Navigation menu with its items
 *   mlsimport_mcp_navigation_shown()                        the id of the menu the header shows, or 0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every published Navigation menu, newest first (the order WordPress falls back in).
 *
 * @return WP_Post[]
 */
function mlsimport_mcp_navigation_posts(): array {
	return get_posts(
		array(
			'post_type'   => 'wp_navigation',
			'post_status' => 'publish',
			'orderby'     => 'date',
			'order'       => 'DESC',
			'numberposts' => 50,
		)
	);
}

/**
 * The blocks of a Navigation menu, flattened and numbered in menu order.
 *
 * Step by step, depth first: every real block gets the next number; a
 * submenu's inner blocks follow it with the submenu as their parent. Other
 * blocks keep their inner blocks untouched inside 'block'.
 *
 * @param string $content The post content (block markup).
 * @return array{nodes: array<int, array>, children: array<int, int[]>}
 *         nodes: index => name, attrs, parent, block; children: parent index (0 = top) => child indexes in order.
 */
function mlsimport_mcp_navigation_nodes( string $content ): array {
	$nodes    = array();
	$children = array();
	$walk     = static function ( array $blocks, int $parent_index ) use ( &$walk, &$nodes, &$children ): void {
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue; // Whitespace between blocks.
			}
			$index                       = count( $nodes ) + 1;
			$nodes[ $index ]             = array(
				'name'   => (string) $block['blockName'],
				'attrs'  => (array) ( $block['attrs'] ?? array() ),
				'parent' => $parent_index,
				'block'  => $block,
			);
			$children[ $parent_index ][] = $index;
			if ( 'core/navigation-submenu' === $block['blockName'] ) {
				$walk( (array) ( $block['innerBlocks'] ?? array() ), $index );
			}
		}
	};
	$walk( parse_blocks( $content ), 0 );
	return array(
		'nodes'    => $nodes,
		'children' => $children,
	);
}

/**
 * One item of a Navigation menu, as the tools answer it.
 *
 * @param int   $index    The item's number (item_id).
 * @param array $node     name, attrs, parent.
 * @param int   $position Place among its siblings, 1 = first.
 * @return array item_id, title, type, object_id, url, parent, position.
 */
function mlsimport_mcp_navigation_item_summary( int $index, array $node, int $position ): array {
	$attrs = $node['attrs'];
	$link  = in_array( $node['name'], array( 'core/navigation-link', 'core/navigation-submenu' ), true );
	// A link names what it points at (page, post, category ...) or is custom;
	// any other block (page-list ...) is an item of that block's type.
	if ( $link ) {
		$type = ( 'custom' === ( $attrs['kind'] ?? 'custom' ) || empty( $attrs['type'] ) ) ? 'custom' : (string) $attrs['type'];
	} else {
		$type = str_replace( 'core/', '', $node['name'] );
	}
	return array(
		'item_id'   => $index,
		'title'     => $link ? (string) ( $attrs['label'] ?? '' ) : '',
		'type'      => $type,
		'object_id' => $link && 'custom' !== $type ? (int) ( $attrs['id'] ?? 0 ) : 0,
		'url'       => $link ? (string) ( $attrs['url'] ?? '' ) : '',
		'parent'    => (int) $node['parent'],
		'position'  => $position,
	);
}

/**
 * One Navigation menu with its items, as list-menus answers it.
 *
 * @param WP_Post $post A wp_navigation post.
 * @return array menu_id, name, type, locations, shown_in_header, items.
 */
function mlsimport_mcp_navigation_summary( $post ): array {
	$tree  = mlsimport_mcp_navigation_nodes( (string) $post->post_content );
	$items = array();
	foreach ( $tree['nodes'] as $index => $node ) {
		$position = array_search( $index, $tree['children'][ $node['parent'] ], true );
		$items[]  = mlsimport_mcp_navigation_item_summary( $index, $node, (int) $position + 1 );
	}
	return array(
		'menu_id'         => (int) $post->ID,
		'name'            => (string) $post->post_title,
		'type'            => 'navigation',
		'locations'       => array(),
		'shown_in_header' => mlsimport_mcp_navigation_shown() === (int) $post->ID,
		'items'           => $items,
	);
}

/**
 * The Navigation blocks inside a list of blocks, patterns followed.
 *
 * @param array $blocks Parsed blocks.
 * @return array[] The attrs of every core/navigation block, in order.
 */
function mlsimport_mcp_navigation_blocks_in( array $blocks ): array {
	$found = array();
	foreach ( $blocks as $block ) {
		if ( 'core/navigation' === ( $block['blockName'] ?? '' ) ) {
			$found[] = (array) ( $block['attrs'] ?? array() );
		} elseif ( 'core/pattern' === ( $block['blockName'] ?? '' ) ) {
			$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( (string) ( $block['attrs']['slug'] ?? '' ) );
			$found   = array_merge( $found, mlsimport_mcp_navigation_blocks_in( $pattern ? parse_blocks( (string) $pattern['content'] ) : array() ) );
		} else {
			$found = array_merge( $found, mlsimport_mcp_navigation_blocks_in( (array) ( $block['innerBlocks'] ?? array() ) ) );
		}
	}
	return $found;
}

/**
 * The id of the Navigation menu the block theme's header shows, or 0.
 *
 * Step by step:
 * 1. Only a block theme has a header template part with a Navigation block.
 * 2. The first Navigation block in the header: with a ref, that menu (when
 *    it is published); without a ref, WordPress shows the most recently
 *    published Navigation menu.
 *
 * @return int
 */
function mlsimport_mcp_navigation_shown(): int {
	// Step 1: the header part.
	if ( ! wp_is_block_theme() ) {
		return 0;
	}
	$header = get_block_template( get_stylesheet() . '//header', 'wp_template_part' );
	$blocks = $header ? mlsimport_mcp_navigation_blocks_in( parse_blocks( (string) $header->content ) ) : array();
	if ( empty( $blocks ) ) {
		return 0;
	}

	// Step 2: the menu that block shows.
	if ( isset( $blocks[0]['ref'] ) ) {
		$post = get_post( (int) $blocks[0]['ref'] );
		return $post && 'wp_navigation' === $post->post_type && 'publish' === $post->post_status ? (int) $post->ID : 0;
	}
	$recent = mlsimport_mcp_navigation_posts();
	return empty( $recent ) ? 0 : (int) $recent[0]->ID;
}
