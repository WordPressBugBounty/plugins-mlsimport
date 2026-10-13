<?php
/**
 * AI tools (MCP): the items of a menu (classic menus, Appearance -> Menus).
 *
 * An item points at a page or post (type = the post type), at a term
 * (type = the taxonomy, for example category), or is a custom link
 * (type = custom, with a url). It can sit under another item of the same menu
 * (parent) and has a position (1-based, in the order the menu shows).
 *
 * Items are written with wp_update_nav_menu_item(), the function
 * Appearance -> Menus saves with. A position is kept the way the menu screen
 * keeps it: every item of the menu is numbered again 1, 2, 3 ... so no two
 * items share a number.
 *
 * Public interface:
 *   mlsimport_mcp_menu_item_tools()                         the tools of this file
 *   mlsimport_mcp_menu_require( $menu_id )                  the menu, or the refusal
 *   mlsimport_mcp_menu_place_item( $menu_id, $item_id, $position )   renumber with the item at $position
 *   mlsimport_mcp_add_menu_item( $input )                   tool: add-menu-item
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
function mlsimport_mcp_menu_item_tools(): array {
	return array(
		'add-menu-item' => array(
			'label'       => 'Add a menu item',
			'description' => 'Adds one item to a menu from list-menus: a page or post (type page / post / another post type, with object_id), a category or other term (type = the taxonomy, with object_id), or a custom link (type custom, with url and title). Without a title a page, post or term item shows its own name. parent puts it under another item of the same menu; position puts it at that place (1 = first), otherwise it goes last.',
			'input'       => array(
				'menu_id'   => array(
					'type'        => 'integer',
					'description' => 'The menu id, from list-menus.',
				),
				'menu_type' => mlsimport_mcp_menu_type_input(),
				'type'      => array(
					'type'        => 'string',
					'description' => 'page, post (or another post type), a taxonomy such as category, or custom.',
				),
				'object_id' => array(
					'type'        => 'integer',
					'description' => 'The page, post or term id. Not used for custom.',
				),
				'url'       => array(
					'type'        => 'string',
					'description' => 'The link of a custom item.',
				),
				'title'     => array(
					'type'        => 'string',
					'description' => 'The text shown in the menu.',
				),
				'parent'    => array(
					'type'        => 'integer',
					'description' => 'The item_id of the item to sit under, from the same menu. 0 or left out: top level.',
				),
				'position'  => array(
					'type'        => 'integer',
					'description' => 'The place in the menu, 1 = first. Left out: last.',
				),
			),
			'required'    => array( 'menu_id', 'type' ),
			'callback'    => 'mlsimport_mcp_add_menu_item',
			'changes'     => true,
		),
	);
}

/**
 * The menu with this id, or the refusal.
 *
 * @param int $menu_id Menu id.
 * @return WP_Term|WP_Error
 */
function mlsimport_mcp_menu_require( int $menu_id ) {
	$menu = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;
	if ( ! $menu ) {
		return new WP_Error( 'mlsimport_menu_unknown', __( 'There is no menu with that id. Read the menus with list-menus.', 'mlsimport' ) );
	}
	return $menu;
}

/**
 * Number every item of a menu 1, 2, 3 ... with one item at a chosen place.
 *
 * Step by step:
 * 1. Read the items in menu order and take the moved item out.
 * 2. Put it back at $position (clamped to the first / last place).
 * 3. Write the new numbers, only where a number changed.
 *
 * @param int $menu_id  Menu id.
 * @param int $item_id  The item to place.
 * @param int $position 1-based place.
 * @return void
 */
function mlsimport_mcp_menu_place_item( int $menu_id, int $item_id, int $position ): void {
	// Step 1: the order today, without the moved item.
	$items = (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
	$order = array();
	$now   = array();
	foreach ( $items as $item ) {
		$now[ (int) $item->ID ] = (int) $item->menu_order;
		if ( (int) $item->ID !== $item_id ) {
			$order[] = (int) $item->ID;
		}
	}

	// Step 2: the item at its place.
	$index = max( 0, min( $position - 1, count( $order ) ) );
	array_splice( $order, $index, 0, array( $item_id ) );

	// Step 3: the numbers.
	foreach ( $order as $number => $id ) {
		if ( ( $now[ $id ] ?? 0 ) !== $number + 1 ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'menu_order' => $number + 1,
				)
			);
		}
	}
}

/**
 * Check the parent: 0, or an item of the same menu.
 *
 * @param int $menu_id   Menu id.
 * @param int $parent_id Parent item id.
 * @return WP_Error|null
 */
function mlsimport_mcp_menu_check_parent( int $menu_id, int $parent_id ) {
	if ( 0 === $parent_id || ( is_nav_menu_item( $parent_id ) && has_term( $menu_id, 'nav_menu', $parent_id ) ) ) {
		return null;
	}
	return new WP_Error( 'mlsimport_menu_parent_unknown', __( 'The parent must be an item_id of the same menu, from list-menus.', 'mlsimport' ) );
}

/**
 * What the item points at, as wp_update_nav_menu_item() takes it.
 *
 * @param array $input type, object_id, url.
 * @return array|WP_Error menu-item-type / -object / -object-id / -url, or the refusal.
 */
function mlsimport_mcp_menu_item_target( array $input ) {
	$type      = sanitize_key( (string) ( $input['type'] ?? '' ) );
	$object_id = (int) ( $input['object_id'] ?? 0 );

	// A custom link: only its url.
	if ( 'custom' === $type ) {
		$url = esc_url_raw( (string) ( $input['url'] ?? '' ) );
		if ( '' === $url ) {
			return new WP_Error( 'mlsimport_menu_url_missing', __( 'A custom link needs a url.', 'mlsimport' ) );
		}
		return array(
			'menu-item-type' => 'custom',
			'menu-item-url'  => $url,
		);
	}

	// A page, post or other post type: a post of that type that is not trashed.
	if ( post_type_exists( $type ) ) {
		$post = get_post( $object_id );
		if ( ! $post || $type !== $post->post_type || 'trash' === $post->post_status ) {
			return new WP_Error( 'mlsimport_menu_object_unknown', sprintf( /* translators: %s: post type. */ __( 'There is no %s with that object_id.', 'mlsimport' ), $type ) );
		}
		return array(
			'menu-item-type'      => 'post_type',
			'menu-item-object'    => $type,
			'menu-item-object-id' => $object_id,
		);
	}

	// A term of a taxonomy.
	if ( taxonomy_exists( $type ) ) {
		$term = get_term( $object_id, $type );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'mlsimport_menu_object_unknown', sprintf( /* translators: %s: taxonomy. */ __( 'There is no %s term with that object_id.', 'mlsimport' ), $type ) );
		}
		return array(
			'menu-item-type'      => 'taxonomy',
			'menu-item-object'    => $type,
			'menu-item-object-id' => $object_id,
		);
	}

	return new WP_Error( 'mlsimport_menu_type_unknown', __( 'type must be custom, a post type such as page or post, or a taxonomy such as category.', 'mlsimport' ) );
}

/**
 * Tool add-menu-item: one item added to a menu.
 *
 * Step by step:
 * 1. The menu, the target and the parent must be right; a refusal adds nothing.
 * 2. Add the item, last.
 * 3. When a position is given, move it there and renumber the menu.
 * 4. Answer with the item as list-menus shows it.
 *
 * @param array $input menu_id, type, object_id, url, title, parent, position.
 * @return array|WP_Error The new item, or the refusal.
 */
function mlsimport_mcp_add_menu_item( array $input ) {
	// A block theme's Navigation menu goes its own way (mlsimport-mcp-navigation-items.php).
	$kind = mlsimport_mcp_menu_kind( $input, 'menu_type' );
	if ( is_wp_error( $kind ) ) {
		return $kind;
	}
	if ( 'navigation' === $kind ) {
		return mlsimport_mcp_navigation_add_item( $input );
	}

	// Step 1: the checks.
	$menu = mlsimport_mcp_menu_require( (int) ( $input['menu_id'] ?? 0 ) );
	if ( is_wp_error( $menu ) ) {
		return $menu;
	}
	$target = mlsimport_mcp_menu_item_target( $input );
	if ( is_wp_error( $target ) ) {
		return $target;
	}
	$parent  = (int) ( $input['parent'] ?? 0 );
	$refusal = mlsimport_mcp_menu_check_parent( (int) $menu->term_id, $parent );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: the item (position 0 = WordPress puts it last).
	$item_id = wp_update_nav_menu_item(
		(int) $menu->term_id,
		0,
		$target + array(
			'menu-item-title'     => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
			'menu-item-parent-id' => $parent,
			'menu-item-status'    => 'publish',
		)
	);
	if ( is_wp_error( $item_id ) ) {
		return $item_id;
	}

	// Step 3: the place.
	if ( ! empty( $input['position'] ) ) {
		mlsimport_mcp_menu_place_item( (int) $menu->term_id, (int) $item_id, (int) $input['position'] );
	}

	// Step 4: the answer.
	return mlsimport_mcp_menu_item_summary( wp_setup_nav_menu_item( get_post( $item_id ) ) );
}
