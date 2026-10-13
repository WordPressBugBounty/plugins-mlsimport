<?php
/**
 * AI tools (MCP): the items of a block-theme Navigation menu.
 *
 * The tools add-menu-item, update-menu-item and remove-menu-item come here when the
 * menu is a Navigation menu (mlsimport_mcp_item_is_navigation()). An item is
 * one block of the menu, numbered in menu order by list-menus
 * (mlsimport-mcp-navigation.php); the whole menu is rewritten on every change
 * (mlsimport_mcp_navigation_save()), so the numbers can shift and the AI
 * reads list-menus again before the next change.
 *
 * Links are written with the attributes the Site Editor writes:
 *   a page or post   {"label","type":"page","id","url","kind":"post-type"}
 *   a term           {"label","type":"category","id","url","kind":"taxonomy"}
 *   a custom link    {"label","url","kind":"custom"}
 *
 * Public interface:
 *   mlsimport_mcp_item_is_navigation( $input )          true when the call names a Navigation menu
 *   mlsimport_mcp_navigation_add_item( $input )         add-menu-item
 *   mlsimport_mcp_navigation_update_item( $input )      update-menu-item
 *   mlsimport_mcp_navigation_remove_item( $input )      remove-menu-item
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True when an item call names a Navigation menu (menu_id, with menu_type or by its id).
 *
 * @param array $input The tool input.
 * @return bool
 */
function mlsimport_mcp_item_is_navigation( array $input ): bool {
	return isset( $input['menu_id'] ) && 'navigation' === mlsimport_mcp_menu_kind( $input, 'menu_type' );
}

/**
 * True when a node is a link or a submenu (the items that have a title and a link).
 *
 * @param array $node A node of mlsimport_mcp_navigation_nodes().
 * @return bool
 */
function mlsimport_mcp_navigation_is_link( array $node ): bool {
	return in_array( $node['name'], array( 'core/navigation-link', 'core/navigation-submenu' ), true );
}

/**
 * The link attributes of a new item, from the same inputs add-menu-item takes for a classic menu.
 *
 * @param array $input type, object_id, url, title.
 * @return array|WP_Error label, type, id, url, kind; or the refusal.
 */
function mlsimport_mcp_navigation_link_attrs( array $input ) {
	$target = mlsimport_mcp_menu_item_target( $input );
	if ( is_wp_error( $target ) ) {
		return $target;
	}
	$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
	if ( 'custom' === $target['menu-item-type'] ) {
		return array(
			'label' => $title,
			'url'   => $target['menu-item-url'],
			'kind'  => 'custom',
		);
	}
	$id = (int) $target['menu-item-object-id'];
	if ( 'post_type' === $target['menu-item-type'] ) {
		$name = get_the_title( $id );
		$url  = get_permalink( $id );
	} else {
		$term = get_term( $id, $target['menu-item-object'] );
		$name = $term->name;
		$url  = get_term_link( $term );
	}
	return array(
		'label' => '' !== $title ? $title : (string) $name,
		'type'  => $target['menu-item-object'],
		'id'    => $id,
		'url'   => (string) $url,
		'kind'  => 'post_type' === $target['menu-item-type'] ? 'post-type' : 'taxonomy',
	);
}

/**
 * Check a parent: 0, or a link of this menu that is not the item itself nor under it.
 *
 * @param array $nodes     The menu's nodes.
 * @param int   $parent_id The wanted parent.
 * @param int   $item      The item being placed (0 for a new one).
 * @return WP_Error|null
 */
function mlsimport_mcp_navigation_check_parent( array $nodes, int $parent_id, int $item = 0 ) {
	if ( 0 === $parent_id ) {
		return null;
	}
	if ( ! isset( $nodes[ $parent_id ] ) || ! mlsimport_mcp_navigation_is_link( $nodes[ $parent_id ] ) ) {
		return new WP_Error( 'mlsimport_menu_parent_unknown', __( 'The parent must be the item_id of a link of the same menu, from list-menus.', 'mlsimport' ) );
	}
	// Up from the parent to the top: meeting the item itself would make a loop.
	for ( $up = $parent_id; 0 !== $up; $up = (int) $nodes[ $up ]['parent'] ) {
		if ( $up === $item ) {
			return new WP_Error( 'mlsimport_menu_parent_self', __( 'An item cannot sit under itself or under one of its own items.', 'mlsimport' ) );
		}
	}
	return null;
}

/**
 * Put an item among the children of a parent, at a place.
 *
 * @param array    $children   parent => child indexes (changed in place).
 * @param int      $index      The item.
 * @param int      $old_parent Where it is now (-1 for a new item).
 * @param int      $new_parent Where it goes.
 * @param int|null $position   1 = first; null = last.
 * @return void
 */
function mlsimport_mcp_navigation_place( array &$children, int $index, int $old_parent, int $new_parent, $position ): void {
	if ( $old_parent >= 0 ) {
		$children[ $old_parent ] = array_values( array_diff( (array) ( $children[ $old_parent ] ?? array() ), array( $index ) ) );
	}
	$siblings = (array) ( $children[ $new_parent ] ?? array() );
	$at       = null === $position ? count( $siblings ) : max( 0, min( (int) $position - 1, count( $siblings ) ) );
	array_splice( $siblings, $at, 0, array( $index ) );
	$children[ $new_parent ] = $siblings;
}

/**
 * The number an item will have once the menu is written back (depth first).
 *
 * @param array $children parent => child indexes.
 * @param int   $index    The item.
 * @return int
 */
function mlsimport_mcp_navigation_new_index( array $children, int $index ): int {
	$number = 0;
	$walk   = static function ( int $parent_index ) use ( &$walk, &$number, $children, $index ) {
		foreach ( (array) ( $children[ $parent_index ] ?? array() ) as $child ) {
			++$number;
			if ( $child === $index ) {
				return true;
			}
			if ( $walk( $child ) ) {
				return true;
			}
		}
		return false;
	};
	$walk( 0 );
	return $number;
}

/**
 * Add-menu-item on a Navigation menu.
 *
 * Step by step:
 * 1. The menu, the link and the parent must be right; a refusal adds nothing.
 * 2. A new node, placed under its parent at the position (or last).
 * 3. Write the menu back and answer with the new item.
 *
 * @param array $input menu_id, type, object_id, url, title, parent, position.
 * @return array|WP_Error The new item, or the refusal.
 */
function mlsimport_mcp_navigation_add_item( array $input ) {
	// Step 1: the checks.
	$post = mlsimport_mcp_navigation_require( (int) ( $input['menu_id'] ?? 0 ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$attrs = mlsimport_mcp_navigation_link_attrs( $input );
	if ( is_wp_error( $attrs ) ) {
		return $attrs;
	}
	$tree    = mlsimport_mcp_navigation_nodes( (string) $post->post_content );
	$parent  = (int) ( $input['parent'] ?? 0 );
	$refusal = mlsimport_mcp_navigation_check_parent( $tree['nodes'], $parent );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: the node, in place.
	$index                   = count( $tree['nodes'] ) + 1;
	$tree['nodes'][ $index ] = array(
		'name'   => 'core/navigation-link',
		'attrs'  => $attrs,
		'parent' => $parent,
		'block'  => null,
	);
	mlsimport_mcp_navigation_place( $tree['children'], $index, -1, $parent, empty( $input['position'] ) ? null : (int) $input['position'] );

	// Step 3: the save and the item.
	$number = mlsimport_mcp_navigation_new_index( $tree['children'], $index );
	$answer = mlsimport_mcp_navigation_save( $post, $tree['nodes'], $tree['children'] );
	return is_wp_error( $answer ) ? $answer : $answer['items'][ $number - 1 ];
}

/**
 * Update-menu-item on a Navigation menu: only the named fields change.
 *
 * Step by step:
 * 1. The item must be a link (a page-list block has nothing to change); a
 *    url only on a custom link; a parent never the item itself or under it.
 * 2. Title and url on the node; parent and/or position move it.
 * 3. Write the menu back and answer with the item.
 *
 * @param array $input menu_id, item_id, title, url, parent, position.
 * @return array|WP_Error The item, or the refusal.
 */
function mlsimport_mcp_navigation_update_item( array $input ) {
	// Step 1: the checks.
	$post = mlsimport_mcp_navigation_require( (int) ( $input['menu_id'] ?? 0 ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$tree  = mlsimport_mcp_navigation_nodes( (string) $post->post_content );
	$index = (int) ( $input['item_id'] ?? 0 );
	if ( ! isset( $tree['nodes'][ $index ] ) ) {
		return new WP_Error( 'mlsimport_menu_item_unknown', __( 'There is no item with that item_id in this menu. Read the items with list-menus.', 'mlsimport' ) );
	}
	$node = $tree['nodes'][ $index ];
	if ( ! mlsimport_mcp_navigation_is_link( $node ) ) {
		return new WP_Error( 'mlsimport_menu_item_not_link', sprintf( /* translators: %s: block type. */ __( 'This item is a %s block: it has no title, link or parent to change. remove-menu-item removes it.', 'mlsimport' ), str_replace( 'core/', '', $node['name'] ) ) );
	}
	if ( isset( $input['url'] ) && 'custom' !== ( $node['attrs']['kind'] ?? 'custom' ) ) {
		return new WP_Error( 'mlsimport_menu_url_not_custom', __( 'Only a custom link has a url. A page, post or term item links to its page, post or term.', 'mlsimport' ) );
	}
	$parent = isset( $input['parent'] ) ? (int) $input['parent'] : (int) $node['parent'];
	if ( isset( $input['parent'] ) ) {
		$refusal = $parent === $index
			? new WP_Error( 'mlsimport_menu_parent_self', __( 'An item cannot sit under itself.', 'mlsimport' ) )
			: mlsimport_mcp_navigation_check_parent( $tree['nodes'], $parent, $index );
		if ( null !== $refusal ) {
			return $refusal;
		}
	}

	// Step 2: the fields, then the place.
	if ( isset( $input['title'] ) ) {
		$tree['nodes'][ $index ]['attrs']['label'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( isset( $input['url'] ) ) {
		$tree['nodes'][ $index ]['attrs']['url'] = esc_url_raw( (string) $input['url'] );
	}
	if ( isset( $input['parent'] ) || ! empty( $input['position'] ) ) {
		$tree['nodes'][ $index ]['parent'] = $parent;
		mlsimport_mcp_navigation_place( $tree['children'], $index, (int) $node['parent'], $parent, empty( $input['position'] ) ? null : (int) $input['position'] );
	}

	// Step 3: the save and the item.
	$number = mlsimport_mcp_navigation_new_index( $tree['children'], $index );
	$answer = mlsimport_mcp_navigation_save( $post, $tree['nodes'], $tree['children'] );
	return is_wp_error( $answer ) ? $answer : $answer['items'][ $number - 1 ];
}

/**
 * Remove-menu-item on a Navigation menu: the item goes, its children take its place.
 *
 * @param array $input menu_id, item_id.
 * @return array|WP_Error removed, item_id, menu_id; or the refusal.
 */
function mlsimport_mcp_navigation_remove_item( array $input ) {
	$post = mlsimport_mcp_navigation_require( (int) ( $input['menu_id'] ?? 0 ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$tree  = mlsimport_mcp_navigation_nodes( (string) $post->post_content );
	$index = (int) ( $input['item_id'] ?? 0 );
	if ( ! isset( $tree['nodes'][ $index ] ) ) {
		return new WP_Error( 'mlsimport_menu_item_unknown', __( 'There is no item with that item_id in this menu. Read the items with list-menus.', 'mlsimport' ) );
	}

	// Its children move up into its place; then the node is gone.
	$parent = (int) $tree['nodes'][ $index ]['parent'];
	$place  = (int) array_search( $index, $tree['children'][ $parent ], true );
	$kids   = (array) ( $tree['children'][ $index ] ?? array() );
	foreach ( $kids as $kid ) {
		$tree['nodes'][ $kid ]['parent'] = $parent;
	}
	array_splice( $tree['children'][ $parent ], $place, 1, $kids );
	unset( $tree['children'][ $index ], $tree['nodes'][ $index ] );

	$answer = mlsimport_mcp_navigation_save( $post, $tree['nodes'], $tree['children'] );
	if ( is_wp_error( $answer ) ) {
		return $answer;
	}
	return array(
		'removed' => true,
		'item_id' => $index,
		'menu_id' => (int) $post->ID,
	);
}
