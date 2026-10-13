<?php
/**
 * AI tools (MCP): the site's menus (classic menus, Appearance -> Menus).
 *
 * The tools work on every site, theme mode or standalone. They use the same
 * WordPress functions Appearance -> Menus uses (wp_get_nav_menus(),
 * wp_get_nav_menu_items(), the theme's nav_menu_locations), so a menu changed
 * here looks the same in wp-admin.
 *
 * Block themes (Twenty Twenty-Five ...) have no menu locations; their header
 * shows Navigation blocks instead. The tools still read and change the
 * classic menus there, and say the theme has no menu locations.
 *
 * How an item is described to the AI (mlsimport_mcp_menu_item_summary()):
 *   item_id, title, type (page, post, a taxonomy like category, or custom),
 *   object_id (the page, post or term; 0 for a custom link), url,
 *   parent (the item_id it sits under; 0 at the top), position (1-based).
 *
 * Public interface:
 *   mlsimport_mcp_menu_tools()                 the tools of this file
 *   mlsimport_mcp_menu_item_summary( $item )   one menu item, as the tools answer it
 *   mlsimport_mcp_menu_summary( $menu )        one menu with its items and locations
 *   mlsimport_mcp_list_menus( $input )         tool: list-menus
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
function mlsimport_mcp_menu_tools(): array {
	return array(
		'list-menus'  => array(
			'label'       => 'List the menus',
			'description' => 'Reads the site\'s menus (Appearance -> Menus): each menu with its items in order (item_id, title, type, url, parent item, position) and the theme locations it is shown in, plus every menu location the theme has. A block theme has no menu locations; its header uses Navigation blocks.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_menus',
			'changes'     => false,
		),
		'create-menu' => array(
			'label'       => 'Create a menu',
			'description' => 'Creates an empty menu and, optionally, shows it in theme locations from list-menus. A menu shown in a location replaces the menu that was there. On a block theme create it with type navigation: that is the kind its header shows. Add items with add-menu-item.',
			'input'       => array(
				'name'      => array(
					'type'        => 'string',
					'description' => 'The menu name. A classic menu name must not be used by another menu.',
				),
				'type'      => mlsimport_mcp_menu_type_input(),
				'locations' => mlsimport_mcp_menu_locations_input(),
			),
			'required'    => array( 'name' ),
			'callback'    => 'mlsimport_mcp_create_menu',
			'changes'     => true,
		),
		'update-menu' => array(
			'label'       => 'Rename a menu or change where it shows',
			'description' => 'Renames a menu from list-menus and/or sets the theme locations it is shown in. locations is the full list: the menu leaves any location not named (an empty list takes it out of all), and a named location shows this menu instead of the one it had. Leave locations out to keep them.',
			'input'       => array(
				'menu_id'   => array(
					'type'        => 'integer',
					'description' => 'The menu id, from list-menus.',
				),
				'type'      => mlsimport_mcp_menu_type_input(),
				'name'      => array(
					'type'        => 'string',
					'description' => 'The new name. It must not be used by another menu.',
				),
				'locations' => mlsimport_mcp_menu_locations_input(),
			),
			'required'    => array( 'menu_id' ),
			'callback'    => 'mlsimport_mcp_update_menu',
			'changes'     => true,
		),
	);
}

/**
 * The "locations" input, shared by create-menu and update-menu.
 *
 * @return array JSON-schema property.
 */
function mlsimport_mcp_menu_locations_input(): array {
	return array(
		'type'        => 'array',
		'items'       => array( 'type' => 'string' ),
		'description' => 'Theme location slugs from list-menus to show this menu in.',
	);
}

/**
 * Check that every named location is one the theme registers.
 *
 * @param array $locations Location slugs.
 * @return WP_Error|null The refusal naming the unknown location, or null.
 */
function mlsimport_mcp_menu_check_locations( array $locations ) {
	$registered = get_registered_nav_menus();
	foreach ( $locations as $location ) {
		if ( ! isset( $registered[ (string) $location ] ) ) {
			return new WP_Error(
				'mlsimport_menu_location_unknown',
				sprintf(
					/* translators: %s: location slug. */
					__( 'The theme has no menu location "%s". Read the locations with list-menus.', 'mlsimport' ),
					(string) $location
				)
			);
		}
	}
	return null;
}

/**
 * Show a menu in theme locations, as Appearance -> Menus -> Manage Locations does.
 *
 * Locations not named keep the menu they have.
 *
 * @param int   $menu_id   Menu id.
 * @param array $locations Location slugs, already checked.
 * @return void
 */
function mlsimport_mcp_menu_assign_locations( int $menu_id, array $locations ): void {
	$assigned = get_theme_mod( 'nav_menu_locations', array() );
	foreach ( $locations as $location ) {
		$assigned[ (string) $location ] = $menu_id;
	}
	set_theme_mod( 'nav_menu_locations', $assigned );
}

/**
 * Tool create-menu: a new empty menu, optionally shown in theme locations.
 *
 * Step by step:
 * 1. Check the locations first, so a refusal creates nothing.
 * 2. Create the menu (WordPress refuses a name already used).
 * 3. Show it in the locations and answer with the new menu.
 *
 * @param array $input name, locations.
 * @return array|WP_Error The new menu, or the refusal.
 */
function mlsimport_mcp_create_menu( array $input ) {
	// A block theme's Navigation menu goes its own way (mlsimport-mcp-navigation-write.php).
	if ( 'navigation' === mlsimport_mcp_menu_kind( $input ) ) {
		return mlsimport_mcp_navigation_create( $input );
	}

	// Step 1: the locations.
	$locations = array_map( 'strval', (array) ( $input['locations'] ?? array() ) );
	$refusal   = mlsimport_mcp_menu_check_locations( $locations );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: the menu.
	$menu_id = wp_create_nav_menu( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
	if ( is_wp_error( $menu_id ) ) {
		return $menu_id;
	}

	// Step 3: the locations, then the menu as list-menus shows it.
	mlsimport_mcp_menu_assign_locations( (int) $menu_id, $locations );
	return mlsimport_mcp_menu_summary( wp_get_nav_menu_object( $menu_id ) );
}

/**
 * Tool update-menu: rename a menu and/or set exactly where it shows.
 *
 * Step by step:
 * 1. The menu and the named locations must exist; a refusal changes nothing.
 * 2. Rename (WordPress refuses a name another menu uses).
 * 3. When locations is given: take the menu out of every location not named,
 *    then show it in the named ones.
 * 4. Answer with the menu as list-menus shows it.
 *
 * @param array $input menu_id, name, locations.
 * @return array|WP_Error The menu, or the refusal.
 */
function mlsimport_mcp_update_menu( array $input ) {
	// A block theme's Navigation menu goes its own way (mlsimport-mcp-navigation-write.php).
	$kind = mlsimport_mcp_menu_kind( $input );
	if ( is_wp_error( $kind ) ) {
		return $kind;
	}
	if ( 'navigation' === $kind ) {
		return mlsimport_mcp_navigation_update( $input );
	}

	// Step 1: the checks.
	$menu = mlsimport_mcp_menu_require( (int) ( $input['menu_id'] ?? 0 ) );
	if ( is_wp_error( $menu ) ) {
		return $menu;
	}
	$menu_id   = (int) $menu->term_id;
	$locations = isset( $input['locations'] ) ? array_map( 'strval', (array) $input['locations'] ) : null;
	if ( null !== $locations ) {
		$refusal = mlsimport_mcp_menu_check_locations( $locations );
		if ( null !== $refusal ) {
			return $refusal;
		}
	}

	// Step 2: the name.
	if ( isset( $input['name'] ) ) {
		$renamed = wp_update_nav_menu_object( $menu_id, array( 'menu-name' => sanitize_text_field( (string) $input['name'] ) ) );
		if ( is_wp_error( $renamed ) ) {
			return $renamed;
		}
	}

	// Step 3: the locations, as a full list.
	if ( null !== $locations ) {
		$assigned = get_theme_mod( 'nav_menu_locations', array() );
		foreach ( $assigned as $location => $shown ) {
			if ( (int) $shown === $menu_id && ! in_array( (string) $location, $locations, true ) ) {
				$assigned[ $location ] = 0;
			}
		}
		set_theme_mod( 'nav_menu_locations', $assigned );
		mlsimport_mcp_menu_assign_locations( $menu_id, $locations );
	}

	// Step 4: the answer.
	return mlsimport_mcp_menu_summary( wp_get_nav_menu_object( $menu_id ) );
}

/**
 * One menu item, as the tools answer it.
 *
 * @param WP_Post $item A menu item set up by wp_setup_nav_menu_item().
 * @return array item_id, title, type, object_id, url, parent, position.
 */
function mlsimport_mcp_menu_item_summary( $item ): array {
	// A custom link has no object; a page, post or term item names its type.
	$type = 'custom' === $item->type ? 'custom' : (string) $item->object;

	return array(
		'item_id'   => (int) $item->ID,
		'title'     => (string) $item->title,
		'type'      => $type,
		'object_id' => 'custom' === $type ? 0 : (int) $item->object_id,
		'url'       => (string) $item->url,
		'parent'    => (int) $item->menu_item_parent,
		'position'  => (int) $item->menu_order,
	);
}

/**
 * One menu with its items and the locations it is shown in.
 *
 * @param WP_Term $menu A nav_menu term.
 * @return array menu_id, name, locations, items.
 */
function mlsimport_mcp_menu_summary( $menu ): array {
	// Step 1: the theme locations this menu is assigned to.
	$locations = array();
	foreach ( get_nav_menu_locations() as $location => $menu_id ) {
		if ( (int) $menu_id === (int) $menu->term_id ) {
			$locations[] = (string) $location;
		}
	}

	// Step 2: the items, in menu order (wp_get_nav_menu_items() sorts them).
	$items = array();
	foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
		$items[] = mlsimport_mcp_menu_item_summary( $item );
	}

	return array(
		'menu_id'   => (int) $menu->term_id,
		'name'      => (string) $menu->name,
		'type'      => 'classic',
		'locations' => $locations,
		'items'     => $items,
	);
}

/**
 * Tool list-menus: every menu, and every menu location of the theme.
 *
 * @param array $input Unused.
 * @return array menus, locations, note (when the theme has no locations).
 */
function mlsimport_mcp_list_menus( array $input ): array {
	unset( $input );

	// Step 1: every menu, with its items: the classic ones, then the
	// block-theme Navigation menus (mlsimport-mcp-navigation.php).
	$menus = array();
	foreach ( wp_get_nav_menus() as $menu ) {
		$menus[] = mlsimport_mcp_menu_summary( $menu );
	}
	foreach ( mlsimport_mcp_navigation_posts() as $post ) {
		$menus[] = mlsimport_mcp_navigation_summary( $post );
	}

	// Step 2: every location the theme registers, with the menu shown there.
	$answer = array(
		'menus'     => $menus,
		'locations' => mlsimport_mcp_menu_locations(),
	);

	// Step 3: a block theme has no locations; say which menu its header shows.
	if ( wp_is_block_theme() ) {
		$answer['note'] = __( 'This is a block theme: it has no menu locations and does not show classic menus. Its header shows the Navigation menu marked shown_in_header (type navigation); change that one, or create one with type navigation.', 'mlsimport' );
	}
	return $answer;
}
