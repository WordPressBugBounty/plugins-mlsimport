<?php
/**
 * AI tools (MCP): delete a whole menu.
 *
 * Like every tool that deletes, delete-menu needs two calls, enforced by the
 * plugin (mlsimport-mcp-confirm.php): the first call deletes nothing and
 * answers with the menu's name, how many items it has and the theme locations
 * that show it, plus a one-time confirmation code; the second call, with that
 * code and the same inputs, deletes.
 *
 * The delete is wp_delete_nav_menu(), what Appearance -> Menus -> Delete Menu
 * runs: the menu, its items, and its place in the theme locations go. The
 * pages, posts and terms the items pointed at stay.
 *
 * Public interface:
 *   mlsimport_mcp_menu_delete_tools()      the tools of this file
 *   mlsimport_mcp_delete_menu( $input )    tool: delete-menu
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
function mlsimport_mcp_menu_delete_tools(): array {
	return array(
		'delete-menu' => array(
			'label'       => 'Delete a menu',
			'description' => 'Deletes one menu from list-menus with all its items; the theme locations that showed it are left empty. The pages, posts and terms the items linked to stay on the site. Needs two calls: the first call deletes nothing and returns what would be deleted and a confirmation_code; tell the client, and only after they confirm call again with the same inputs and that code.',
			'input'       => array(
				'menu_id'           => array(
					'type'        => 'integer',
					'description' => 'The menu id, from list-menus.',
				),
				'type'              => mlsimport_mcp_menu_type_input(),
				'confirmation_code' => array(
					'type'        => 'string',
					'description' => 'The confirmation_code returned by the first call. Leave out on the first call.',
				),
			),
			'required'    => array( 'menu_id' ),
			'callback'    => 'mlsimport_mcp_delete_menu',
			'changes'     => true,
			'destructive' => true,
		),
	);
}

/**
 * Tool delete-menu: one menu and its items (two calls).
 *
 * Step by step:
 * 1. The menu must exist.
 * 2. Say exactly what goes: name, item count, locations. Without a valid code
 *    stop here (the confirmation gate answers the preview or the refusal).
 * 3. Confirmed: delete the menu the way Appearance -> Menus does.
 *
 * @param array $input menu_id, confirmation_code.
 * @return array|WP_Error The preview, "deleted", or the refusal.
 */
function mlsimport_mcp_delete_menu( array $input ) {
	// A block theme's Navigation menu goes its own way (mlsimport-mcp-navigation-write.php).
	$kind = mlsimport_mcp_menu_kind( $input );
	if ( is_wp_error( $kind ) ) {
		return $kind;
	}
	if ( 'navigation' === $kind ) {
		return mlsimport_mcp_navigation_delete( $input );
	}

	// Step 1: the menu.
	$menu = mlsimport_mcp_menu_require( (int) ( $input['menu_id'] ?? 0 ) );
	if ( is_wp_error( $menu ) ) {
		return $menu;
	}
	$summary = mlsimport_mcp_menu_summary( $menu );

	// Step 2: the preview / the code.
	$pending = mlsimport_mcp_confirm_or_preview(
		'delete-menu',
		$input,
		sprintf(
			/* translators: 1: menu name, 2: menu id, 3: number of items, 4: theme locations. */
			__( 'The menu "%1$s" (%2$d) and its %3$d item(s). Theme locations that show it and will be left empty: %4$s. The pages, posts and terms it links to stay.', 'mlsimport' ),
			$summary['name'],
			$summary['menu_id'],
			count( $summary['items'] ),
			empty( $summary['locations'] ) ? __( 'none', 'mlsimport' ) : implode( ', ', $summary['locations'] )
		)
	);
	if ( null !== $pending ) {
		return $pending;
	}

	// Step 3: the delete.
	$deleted = wp_delete_nav_menu( (int) $menu->term_id );
	if ( is_wp_error( $deleted ) || ! $deleted ) {
		return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'mlsimport_menu_delete_failed', __( 'WordPress could not delete the menu.', 'mlsimport' ) );
	}
	return array(
		'deleted' => true,
		'menu_id' => (int) $menu->term_id,
	);
}
