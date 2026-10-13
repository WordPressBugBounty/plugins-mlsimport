<?php
/**
 * AI tools (MCP): the theme's menu locations (Appearance -> Menus -> Manage Locations).
 *
 * A classic theme registers locations (primary, footer ...) and shows the
 * classic menu assigned to each. A block theme registers none: its header
 * shows a Navigation menu instead (mlsimport-mcp-navigation.php), and a
 * classic menu assigned to a location there shows nowhere.
 *
 * Public interface:
 *   mlsimport_mcp_menu_location_tools()        the tools of this file
 *   mlsimport_mcp_menu_locations()             every location with the menu it shows
 *   mlsimport_mcp_list_menu_locations( $input ) tool: list-menu-locations
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
function mlsimport_mcp_menu_location_tools(): array {
	return array(
		'list-menu-locations'  => array(
			'label'       => 'List the menu locations',
			'description' => 'Reads the theme\'s menu locations (Appearance -> Menus -> Manage Locations): each with its slug, label and the classic menu_id it shows (0 = none), plus header_navigation, the Navigation menu a block theme\'s header shows (0 = none). A block theme has no locations.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_menu_locations',
			'changes'     => false,
		),
		'assign-menu-location' => array(
			'label'       => 'Show a classic menu in a theme location',
			'description' => 'Shows a classic menu from list-menus in one theme location from list-menu-locations, as Appearance -> Menus -> Manage Locations does. The menu that was in that location is replaced at once; the answer names it (replaced), so the same tool can put it back. On a block theme this shows nothing: its header uses a Navigation menu.',
			'input'       => array(
				'menu_id'  => array(
					'type'        => 'integer',
					'description' => 'The classic menu id, from list-menus.',
				),
				'location' => array(
					'type'        => 'string',
					'description' => 'The location slug, from list-menu-locations.',
				),
			),
			'required'    => array( 'menu_id', 'location' ),
			'callback'    => 'mlsimport_mcp_assign_menu_location',
			'changes'     => true,
		),
	);
}

/**
 * Tool assign-menu-location: one classic menu into one location.
 *
 * Step by step:
 * 1. The menu must be a classic menu and the location one the theme has.
 * 2. Remember the menu that is there now, then assign.
 * 3. Answer with the location, the menu, and the menu replaced (0 = none).
 *
 * @param array $input menu_id, location.
 * @return array|WP_Error location, menu_id, replaced; or the refusal.
 */
function mlsimport_mcp_assign_menu_location( array $input ) {
	// Step 1: the checks.
	$menu = mlsimport_mcp_menu_require( (int) ( $input['menu_id'] ?? 0 ) );
	if ( is_wp_error( $menu ) ) {
		return $menu;
	}
	$location = sanitize_key( (string) ( $input['location'] ?? '' ) );
	$refusal  = mlsimport_mcp_menu_check_locations( array( $location ) );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2: the menu there now, then the assignment.
	$replaced = (int) ( get_nav_menu_locations()[ $location ] ?? 0 );
	mlsimport_mcp_menu_assign_locations( (int) $menu->term_id, array( $location ) );

	// Step 3: the answer.
	return array(
		'location' => $location,
		'menu_id'  => (int) $menu->term_id,
		'replaced' => $replaced === (int) $menu->term_id ? 0 : $replaced,
	);
}

/**
 * Every location the theme registers, with the classic menu shown there.
 *
 * @return array[] location, label, menu_id.
 */
function mlsimport_mcp_menu_locations(): array {
	$assigned  = get_nav_menu_locations();
	$locations = array();
	foreach ( get_registered_nav_menus() as $location => $label ) {
		$locations[] = array(
			'location' => (string) $location,
			'label'    => (string) $label,
			'menu_id'  => (int) ( $assigned[ $location ] ?? 0 ),
		);
	}
	return $locations;
}

/**
 * Tool list-menu-locations.
 *
 * @param array $input Unused.
 * @return array locations, header_navigation, note (block theme).
 */
function mlsimport_mcp_list_menu_locations( array $input ): array {
	unset( $input );
	$answer = array(
		'locations'         => mlsimport_mcp_menu_locations(),
		'header_navigation' => mlsimport_mcp_navigation_shown(),
	);
	if ( wp_is_block_theme() ) {
		$answer['note'] = __( 'This is a block theme: a classic menu in a location shows nowhere. The header shows the Navigation menu header_navigation (0 = none); see list-menus.', 'mlsimport' );
	}
	return $answer;
}
