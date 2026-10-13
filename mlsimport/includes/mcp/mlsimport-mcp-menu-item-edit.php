<?php
/**
 * AI tools (MCP): change or remove one item of a menu.
 *
 * WordPress' wp_update_nav_menu_item() resets every field it is not given, so a change
 * starts from all of the item's current fields (mlsimport_mcp_menu_item_args())
 * and overrides only the ones the AI named. That is how the title of a page
 * item can change while it keeps pointing at the same page.
 *
 * Public interface:
 *   mlsimport_mcp_menu_item_edit_tools()          the tools of this file
 *   mlsimport_mcp_menu_item_require( $item_id )   the item and its menu id, or the refusal
 *   mlsimport_mcp_menu_item_args( $item )         the item's current fields, as wp_update_nav_menu_item() takes them
 *   mlsimport_mcp_update_menu_item( $input )      tool: update-menu-item
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
function mlsimport_mcp_menu_item_edit_tools(): array {
	$item_id = array(
		'type'        => 'integer',
		'description' => 'The item_id, from list-menus.',
	);
	$menu_id = array(
		'type'        => 'integer',
		'description' => 'The menu_id, from list-menus. Needed for an item of a Navigation menu (type navigation), whose item_id is its number in that menu.',
	);

	return array(
		'update-menu-item' => array(
			'label'       => 'Change a menu item',
			'description' => 'Changes one menu item from list-menus. Only the fields you name change: title, url (custom links only), parent (an item_id of the same menu, 0 = top level) and position (1 = first). A page, post or term item keeps pointing at the same page, post or term. For an item of a Navigation menu give menu_id too.',
			'input'       => array(
				'item_id'   => $item_id,
				'menu_id'   => $menu_id,
				'menu_type' => mlsimport_mcp_menu_type_input(),
				'title'     => array(
					'type'        => 'string',
					'description' => 'The text shown in the menu. Empty: a page, post or term item shows its own name again.',
				),
				'url'       => array(
					'type'        => 'string',
					'description' => 'The new link of a custom item.',
				),
				'parent'    => array(
					'type'        => 'integer',
					'description' => 'The item_id to sit under, from the same menu; 0 = top level.',
				),
				'position'  => array(
					'type'        => 'integer',
					'description' => 'The place in the menu, 1 = first.',
				),
			),
			'required'    => array( 'item_id' ),
			'callback'    => 'mlsimport_mcp_update_menu_item',
			'changes'     => true,
		),
		'remove-menu-item' => array(
			'label'       => 'Remove a menu item',
			'description' => 'Removes one item from its menu. The items under it move up one level, into its place. The page, post or term it pointed at stays on the site. For an item of a Navigation menu give menu_id too.',
			'input'       => array(
				'item_id'   => $item_id,
				'menu_id'   => $menu_id,
				'menu_type' => mlsimport_mcp_menu_type_input(),
			),
			'required'    => array( 'item_id' ),
			'callback'    => 'mlsimport_mcp_remove_menu_item',
			'changes'     => true,
			'destructive' => true,
		),
	);
}

/**
 * The menu item with this id and the id of its menu, or the refusal.
 *
 * @param int $item_id Item id.
 * @return array|WP_Error array( WP_Post item set up as a menu item, int menu_id ).
 */
function mlsimport_mcp_menu_item_require( int $item_id ) {
	$menus = $item_id > 0 && is_nav_menu_item( $item_id ) ? wp_get_object_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) ) : array();
	if ( empty( $menus ) || is_wp_error( $menus ) ) {
		return new WP_Error( 'mlsimport_menu_item_unknown', __( 'There is no menu item with that item_id. Read the items with list-menus.', 'mlsimport' ) );
	}
	return array( wp_setup_nav_menu_item( get_post( $item_id ) ), (int) $menus[0] );
}

/**
 * The item's current fields, as wp_update_nav_menu_item() takes them.
 *
 * @param WP_Post $item A menu item set up by wp_setup_nav_menu_item().
 * @return array
 */
function mlsimport_mcp_menu_item_args( $item ): array {
	return array(
		'menu-item-db-id'       => (int) $item->ID,
		'menu-item-object-id'   => (int) $item->object_id,
		'menu-item-object'      => (string) $item->object,
		'menu-item-parent-id'   => (int) $item->menu_item_parent,
		'menu-item-position'    => (int) $item->menu_order,
		'menu-item-type'        => (string) $item->type,
		'menu-item-title'       => (string) $item->post_title,
		'menu-item-url'         => (string) $item->url,
		'menu-item-description' => (string) $item->description,
		'menu-item-attr-title'  => (string) $item->attr_title,
		'menu-item-target'      => (string) $item->target,
		'menu-item-classes'     => implode( ' ', (array) $item->classes ),
		'menu-item-xfn'         => (string) $item->xfn,
		'menu-item-status'      => 'publish',
	);
}

/**
 * Tool update-menu-item: change the named fields of one item.
 *
 * Step by step:
 * 1. The item must exist; a url only on a custom link; a parent only from
 *    the same menu and never the item itself. A refusal changes nothing.
 * 2. Start from all current fields and override the named ones.
 * 3. Save, then move the item to its position when one is given.
 * 4. Answer with the item as list-menus shows it.
 *
 * @param array $input item_id, title, url, parent, position.
 * @return array|WP_Error The item, or the refusal.
 */
function mlsimport_mcp_update_menu_item( array $input ) {
	// An item of a Navigation menu goes its own way (mlsimport-mcp-navigation-items.php).
	if ( mlsimport_mcp_item_is_navigation( $input ) ) {
		return mlsimport_mcp_navigation_update_item( $input );
	}

	// Step 1: the checks.
	$found = mlsimport_mcp_menu_item_require( (int) ( $input['item_id'] ?? 0 ) );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	list( $item, $menu_id ) = $found;
	if ( isset( $input['url'] ) && 'custom' !== $item->type ) {
		return new WP_Error( 'mlsimport_menu_url_not_custom', __( 'Only a custom link has a url. A page, post or term item links to its page, post or term.', 'mlsimport' ) );
	}
	if ( isset( $input['parent'] ) ) {
		$parent  = (int) $input['parent'];
		$refusal = $parent === (int) $item->ID
			? new WP_Error( 'mlsimport_menu_parent_self', __( 'An item cannot sit under itself.', 'mlsimport' ) )
			: mlsimport_mcp_menu_check_parent( $menu_id, $parent );
		if ( null !== $refusal ) {
			return $refusal;
		}
	}

	// Step 2: current fields, then the named ones.
	$args = mlsimport_mcp_menu_item_args( $item );
	if ( isset( $input['title'] ) ) {
		$args['menu-item-title'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( isset( $input['url'] ) ) {
		$args['menu-item-url'] = esc_url_raw( (string) $input['url'] );
	}
	if ( isset( $input['parent'] ) ) {
		$args['menu-item-parent-id'] = (int) $input['parent'];
	}

	// Step 3: save and place.
	$saved = wp_update_nav_menu_item( $menu_id, (int) $item->ID, $args );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	if ( ! empty( $input['position'] ) ) {
		mlsimport_mcp_menu_place_item( $menu_id, (int) $item->ID, (int) $input['position'] );
	}

	// Step 4: the answer.
	return mlsimport_mcp_menu_item_summary( wp_setup_nav_menu_item( get_post( (int) $item->ID ) ) );
}

/**
 * Tool remove-menu-item: one item out of its menu.
 *
 * Step by step:
 * 1. The item must exist.
 * 2. The items directly under it move to its parent, so none is left
 *    pointing at an item that is gone.
 * 3. Delete the item (only the menu item; its page, post or term stays).
 * 4. Number the menu 1, 2, 3 ... again (placing the first item first does it).
 *
 * @param array $input item_id.
 * @return array|WP_Error removed, item_id, menu_id; or the refusal.
 */
function mlsimport_mcp_remove_menu_item( array $input ) {
	// An item of a Navigation menu goes its own way (mlsimport-mcp-navigation-items.php).
	if ( mlsimport_mcp_item_is_navigation( $input ) ) {
		return mlsimport_mcp_navigation_remove_item( $input );
	}

	// Step 1: the item.
	$found = mlsimport_mcp_menu_item_require( (int) ( $input['item_id'] ?? 0 ) );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	list( $item, $menu_id ) = $found;

	// Step 2: its children move up one level.
	foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $other ) {
		if ( (int) $other->menu_item_parent === (int) $item->ID ) {
			update_post_meta( (int) $other->ID, '_menu_item_menu_item_parent', (string) (int) $item->menu_item_parent );
		}
	}

	// Step 3: the item.
	wp_delete_post( (int) $item->ID, true );

	// Step 4: the numbers.
	$left = (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
	if ( ! empty( $left ) ) {
		mlsimport_mcp_menu_place_item( $menu_id, (int) $left[0]->ID, 1 );
	}

	return array(
		'removed' => true,
		'item_id' => (int) $item->ID,
		'menu_id' => $menu_id,
	);
}
