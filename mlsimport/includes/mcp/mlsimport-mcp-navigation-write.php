<?php
/**
 * AI tools (MCP): writing block-theme Navigation menus (create, rename, delete).
 *
 * The menu tools take a "type": classic (Appearance -> Menus, the default)
 * or navigation (a block theme's Navigation menu, see
 * mlsimport-mcp-navigation.php). This file decides which one a call means
 * (mlsimport_mcp_menu_kind()) and does the navigation side of create-menu,
 * update-menu and delete-menu; the items are in mlsimport-mcp-navigation-items.php.
 *
 * A Navigation menu is saved by writing its blocks back as block markup
 * (mlsimport_mcp_navigation_save()): an item with children becomes a
 * navigation-submenu block, one without a navigation-link block, and any
 * other block (page-list ...) is written back as it was.
 *
 * Public interface:
 *   mlsimport_mcp_menu_kind( $input )                 'classic' or 'navigation', or the refusal
 *   mlsimport_mcp_navigation_require( $menu_id )      the Navigation menu, or the refusal
 *   mlsimport_mcp_navigation_save( $post, $nodes, $children )   write the blocks back, answer the menu
 *   mlsimport_mcp_navigation_create( $input )         create-menu, type navigation
 *   mlsimport_mcp_navigation_update( $input )         update-menu, type navigation
 *   mlsimport_mcp_navigation_delete( $input )         delete-menu, type navigation
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "type" input shared by the menu tools.
 *
 * @return array JSON-schema property.
 */
function mlsimport_mcp_menu_type_input(): array {
	return array(
		'type'        => 'string',
		'enum'        => array( 'classic', 'navigation' ),
		'description' => 'classic (Appearance -> Menus, the default) or navigation (a block theme\'s Navigation menu), as list-menus shows the menu.',
	);
}

/**
 * Which kind of menu a call means.
 *
 * Step by step:
 * 1. A type in the input decides.
 * 2. Otherwise the menu_id: a classic menu with that id means classic, a
 *    Navigation menu with that id means navigation. When both exist the AI
 *    has to say which; when neither does, classic (its refusal says so).
 *
 * @param array  $input The tool input (type or menu_type, menu_id).
 * @param string $key   The input that names the kind: 'type', or 'menu_type' on the item tools.
 * @return string|WP_Error 'classic' or 'navigation', or the refusal.
 */
function mlsimport_mcp_menu_kind( array $input, string $key = 'type' ) {
	// Step 1: the type given.
	$type = sanitize_key( (string) ( $input[ $key ] ?? '' ) );
	if ( in_array( $type, array( 'classic', 'navigation' ), true ) ) {
		return $type;
	}

	// Step 2: the id.
	$menu_id    = (int) ( $input['menu_id'] ?? 0 );
	$classic    = $menu_id > 0 && false !== wp_get_nav_menu_object( $menu_id );
	$navigation = $menu_id > 0 && 'wp_navigation' === get_post_type( $menu_id );
	if ( $classic && $navigation ) {
		return new WP_Error( 'mlsimport_menu_type_needed', __( 'Both a classic menu and a Navigation menu have that menu_id. Say which with type: classic or navigation.', 'mlsimport' ) );
	}
	return $navigation ? 'navigation' : 'classic';
}

/**
 * The published Navigation menu with this id, or the refusal.
 *
 * @param int $menu_id Post id.
 * @return WP_Post|WP_Error
 */
function mlsimport_mcp_navigation_require( int $menu_id ) {
	$post = $menu_id > 0 ? get_post( $menu_id ) : null;
	if ( ! $post || 'wp_navigation' !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error( 'mlsimport_menu_unknown', __( 'There is no Navigation menu with that id. Read the menus with list-menus.', 'mlsimport' ) );
	}
	return $post;
}

/**
 * Write the blocks of a Navigation menu back and answer the menu.
 *
 * @param WP_Post $post     The Navigation menu.
 * @param array   $nodes    index => name, attrs, block (see mlsimport_mcp_navigation_nodes()).
 * @param array   $children parent index => child indexes in order.
 * @return array|WP_Error The menu as list-menus shows it, or the refusal.
 */
function mlsimport_mcp_navigation_save( $post, array $nodes, array $children ) {
	// One block per node, children first (depth first), links and submenus rebuilt.
	$build = static function ( int $parent_index ) use ( &$build, $nodes, $children ): array {
		$blocks = array();
		foreach ( (array) ( $children[ $parent_index ] ?? array() ) as $index ) {
			$node  = $nodes[ $index ];
			$inner = $build( $index );
			if ( ! in_array( $node['name'], array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
				$blocks[] = $node['block']; // Another block (page-list ...): as it was.
				continue;
			}
			$blocks[] = array(
				'blockName'    => empty( $inner ) ? 'core/navigation-link' : 'core/navigation-submenu',
				'attrs'        => $node['attrs'],
				'innerBlocks'  => $inner,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, count( $inner ), null ),
			);
		}
		return $blocks;
	};
	$saved = wp_update_post(
		array(
			'ID'           => (int) $post->ID,
			'post_content' => serialize_blocks( $build( 0 ) ),
		),
		true
	);
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return mlsimport_mcp_navigation_summary( get_post( (int) $post->ID ) );
}

/**
 * Create-menu, type navigation: a new, empty Navigation menu.
 *
 * @param array $input name, locations (refused: a Navigation menu has none).
 * @return array|WP_Error The menu, or the refusal.
 */
function mlsimport_mcp_navigation_create( array $input ) {
	if ( ! empty( $input['locations'] ) ) {
		return new WP_Error( 'mlsimport_menu_no_locations', __( 'A Navigation menu has no theme locations: a block theme\'s header shows the Navigation menu its Navigation block refers to, or the newest one. Create it without locations.', 'mlsimport' ) );
	}
	$menu_id = wp_insert_post(
		array(
			'post_type'    => 'wp_navigation',
			'post_status'  => 'publish',
			'post_title'   => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'post_content' => '',
		),
		true
	);
	if ( is_wp_error( $menu_id ) ) {
		return $menu_id;
	}
	return mlsimport_mcp_navigation_summary( get_post( (int) $menu_id ) );
}

/**
 * Update-menu, type navigation: rename.
 *
 * @param array $input menu_id, name, locations (refused).
 * @return array|WP_Error The menu, or the refusal.
 */
function mlsimport_mcp_navigation_update( array $input ) {
	$post = mlsimport_mcp_navigation_require( (int) ( $input['menu_id'] ?? 0 ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( isset( $input['locations'] ) ) {
		return new WP_Error( 'mlsimport_menu_no_locations', __( 'A Navigation menu has no theme locations; only its name can change here.', 'mlsimport' ) );
	}
	if ( isset( $input['name'] ) ) {
		$saved = wp_update_post(
			array(
				'ID'         => (int) $post->ID,
				'post_title' => sanitize_text_field( (string) $input['name'] ),
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
	}
	return mlsimport_mcp_navigation_summary( get_post( (int) $post->ID ) );
}

/**
 * Delete-menu, type navigation: the menu and its items (two calls).
 *
 * @param array $input menu_id, confirmation_code.
 * @return array|WP_Error The preview, "deleted", or the refusal.
 */
function mlsimport_mcp_navigation_delete( array $input ) {
	$post = mlsimport_mcp_navigation_require( (int) ( $input['menu_id'] ?? 0 ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$summary = mlsimport_mcp_navigation_summary( $post );
	$pending = mlsimport_mcp_confirm_or_preview(
		'delete-menu',
		$input,
		sprintf(
			/* translators: 1: menu name, 2: menu id, 3: number of items, 4: yes or no. */
			__( 'The Navigation menu "%1$s" (%2$d) and its %3$d item(s). The header shows it now: %4$s. The pages, posts and terms it links to stay.', 'mlsimport' ),
			$summary['name'],
			$summary['menu_id'],
			count( $summary['items'] ),
			$summary['shown_in_header'] ? __( 'yes', 'mlsimport' ) : __( 'no', 'mlsimport' )
		)
	);
	if ( null !== $pending ) {
		return $pending;
	}
	if ( ! wp_delete_post( (int) $post->ID, true ) ) {
		return new WP_Error( 'mlsimport_menu_delete_failed', __( 'WordPress could not delete the menu.', 'mlsimport' ) );
	}
	return array(
		'deleted' => true,
		'menu_id' => (int) $post->ID,
	);
}
