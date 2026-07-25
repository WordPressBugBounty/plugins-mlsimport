<?php
// Guard: block direct web access — only load when WordPress is bootstrapped.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


/**
 * Registers the plugin's custom post type(s).
 *
 * File role: defines the `mlsimport_item` ("Import Tasks") custom post type — each post is
 * one import task configuration processed by the cron/import pipeline. create_custom_post_type()
 * builds the CPT arguments and delegates to register_single_post_type(), which calls
 * register_post_type(); optional custom capabilities are wired through assign_capabilities().
 *
 * @author cretu
 */
class Mlsimport_Item {


	// put your code here


	/**
	 * Constructor — no initialization required.
	 */
	public function __construct() {
	}

	/**
	 * Build the arguments for and register a single custom post type.
	 *
	 * Assembles the labels and args arrays from a $fields definition, optionally applies a
	 * custom rewrite rule and a fine-grained capability map, registers the post type, then
	 * detaches the default category/post_tag taxonomies from mlsimport_item.
	 *
	 * @param array $fields Post-type definition (slug, singular/plural labels, args, flags).
	 * @return void
	 */
	private function register_single_post_type( $fields ) {

		// Human-readable admin labels, derived from the singular/plural names in $fields.
		$labels = array(
			'name'                  => $fields['plural'],
			'singular_name'         => $fields['singular'],
			'menu_name'             => $fields['menu_name'],
			'new_item'              => sprintf( __( 'New %s', 'mlsimport' ), $fields['singular'] ),
			'add_new_item'          => sprintf( __( 'Add new %s', 'mlsimport' ), $fields['singular'] ),
			'edit_item'             => sprintf( __( 'Edit %s', 'mlsimport' ), $fields['singular'] ),
			'view_item'             => sprintf( __( 'View %s', 'mlsimport' ), $fields['singular'] ),
			'view_items'            => sprintf( __( 'View %s', 'mlsimport' ), $fields['plural'] ),
			'search_items'          => sprintf( __( 'Search %s', 'mlsimport' ), $fields['plural'] ),
			'not_found'             => sprintf( __( 'No %s found', 'mlsimport' ), strtolower( $fields['plural'] ) ),
			'not_found_in_trash'    => sprintf( __( 'No %s found in trash', 'mlsimport' ), strtolower( $fields['plural'] ) ),
			'all_items'             => sprintf( __( 'All %s', 'mlsimport' ), $fields['plural'] ),
			'archives'              => sprintf( __( '%s Archives', 'mlsimport' ), $fields['singular'] ),
			'attributes'            => sprintf( __( '%s Attributes', 'mlsimport' ), $fields['singular'] ),
			'insert_into_item'      => sprintf( __( 'Insert into %s', 'mlsimport' ), strtolower( $fields['singular'] ) ),
			'uploaded_to_this_item' => sprintf( __( 'Uploaded to this %s', 'mlsimport' ), strtolower( $fields['singular'] ) ),

			/* Labels for hierarchical post types only. */
			'parent_item'           => sprintf( __( 'Parent %s', 'mlsimport' ), $fields['singular'] ),
			'parent_item_colon'     => sprintf( __( 'Parent %s:', 'mlsimport' ), $fields['singular'] ),

			/* Custom archive label.  Must filter 'post_type_archive_title' to use. */
			'archive_title'         => $fields['plural'],
		);

		// register_post_type() arguments; each flag falls back to a sensible default when unset in $fields.
		$args = array(
			'labels'              => $labels,
			'description'         => ( isset( $fields['description'] ) ) ? $fields['description'] : '',
			'public'              => ( isset( $fields['public'] ) ) ? $fields['public'] : true,
			'publicly_queryable'  => ( isset( $fields['publicly_queryable'] ) ) ? $fields['publicly_queryable'] : true,
			'exclude_from_search' => ( isset( $fields['exclude_from_search'] ) ) ? $fields['exclude_from_search'] : false,
			'show_ui'             => ( isset( $fields['show_ui'] ) ) ? $fields['show_ui'] : true,
			'show_in_menu'        => ( isset( $fields['show_in_menu'] ) ) ? $fields['show_in_menu'] : true,
			'query_var'           => ( isset( $fields['query_var'] ) ) ? $fields['query_var'] : true,
			'show_in_admin_bar'   => ( isset( $fields['show_in_admin_bar'] ) ) ? $fields['show_in_admin_bar'] : true,
			'capability_type'     => ( isset( $fields['capability_type'] ) ) ? $fields['capability_type'] : 'post',
			'map_meta_cap'        => ! empty( $fields['map_meta_cap'] ),
			'has_archive'         => ( isset( $fields['has_archive'] ) ) ? $fields['has_archive'] : true,
			'hierarchical'        => ( isset( $fields['hierarchical'] ) ) ? $fields['hierarchical'] : true,
			'supports'            => ( isset( $fields['supports'] ) ) ? $fields['supports'] : array(
				'title',
				'editor',
				'excerpt',
				'author',
				'thumbnail',
				'comments',
				'trackbacks',
				'custom-fields',
				'revisions',
				'page-attributes',
				'post-formats',
			),
			'menu_position'       => ( isset( $fields['menu_position'] ) ) ? $fields['menu_position'] : 21,
			'menu_icon'           => ( isset( $fields['menu_icon'] ) ) ? $fields['menu_icon'] : 'dashicons-admin-generic',
			'show_in_nav_menus'   => ( isset( $fields['show_in_nav_menus'] ) ) ? $fields['show_in_nav_menus'] : true,
			'taxonomies'          => array( 'category', 'post_tag' ),
		);

		// Apply a custom permalink rewrite rule when the definition supplies one.
		if ( isset( $fields['rewrite'] ) ) {

			/**
			 *  Add $this->plugin_name as translatable in the permalink structure,
			 *  to avoid conflicts with other plugins which may use customers as well.
			 */
			$args['rewrite'] = $fields['rewrite'];
		}

		// When custom capabilities are requested, replace the default cap set with a granular map.
		if ( $fields['custom_caps'] ) {

			/**
			 * Provides more precise control over the capabilities than the defaults.  By default, WordPress
			 * will use the 'capability_type' argument to build these capabilities.  More often than not,
			 * this results in many extra capabilities that you probably don't need.  The following is how
			 * I set up capabilities for many post types, which only uses three basic capabilities you need
			 * to assign to roles: 'manage_examples', 'edit_examples', 'create_examples'.  Each post type
			 * is unique though, so you'll want to adjust it to fit your needs.
			 *
			 * @link https://gist.github.com/creativembers/6577149
			 * @link http://justintadlock.com/archives/2010/07/10/meta-capabilities-for-custom-post-types
			 */
			$args['capabilities'] = array(

				// Meta capabilities
				'edit_post'              => 'edit_' . strtolower( $fields['singular'] ),
				'read_post'              => 'read_' . strtolower( $fields['singular'] ),
				'delete_post'            => 'delete_' . strtolower( $fields['singular'] ),

				// Primitive capabilities used outside of map_meta_cap():
				'edit_posts'             => 'edit_' . strtolower( $fields['plural'] ),
				'edit_others_posts'      => 'edit_others_' . strtolower( $fields['plural'] ),
				'publish_posts'          => 'publish_' . strtolower( $fields['plural'] ),
				'read_private_posts'     => 'read_private_' . strtolower( $fields['plural'] ),

				// Primitive capabilities used within map_meta_cap():
				'delete_posts'           => 'delete_' . strtolower( $fields['plural'] ),
				'delete_private_posts'   => 'delete_private_' . strtolower( $fields['plural'] ),
				'delete_published_posts' => 'delete_published_' . strtolower( $fields['plural'] ),
				'delete_others_posts'    => 'delete_others_' . strtolower( $fields['plural'] ),
				'edit_private_posts'     => 'edit_private_' . strtolower( $fields['plural'] ),
				'edit_published_posts'   => 'edit_published_' . strtolower( $fields['plural'] ),
				'create_posts'           => 'edit_' . strtolower( $fields['plural'] ),

			);

			/**
			 * Adding map_meta_cap will map the meta correctly.
			 *
			 * @link https://wordpress.stackexchange.com/questions/108338/capabilities-and-custom-post-types/108375#108375
			 */
			$args['map_meta_cap'] = true;

			/**
			 * Assign capabilities to users
			 * Without this, users - also admins - can not see post type.
			 */
			$this->assign_capabilities( $args['capabilities'], $fields['custom_caps_users'] );
		}

		// Register the post type with WordPress using the assembled slug and args.
		register_post_type( $fields['slug'], $args );

		// Dedicated capability type: only administrators receive the caps.
		if ( ! empty( $fields['map_meta_cap'] ) ) {
			$this->grant_admin_capabilities( $fields['slug'] );
		}

		/**
		 * Register Taxnonmies if any
		 *
		 * @link https://codex.wordpress.org/Function_Reference/register_taxonomy
		 */
		// Import tasks are not taxonomy-organized: detach the default tag and category taxonomies.
		unregister_taxonomy_for_object_type( 'post_tag', 'mlsimport_item' );
		unregister_taxonomy_for_object_type( 'category', 'mlsimport_item' );

	}



	/**
	 * Grant a post type's primitive capabilities to the administrator role.
	 *
	 * Import Tasks use a dedicated capability type so ordinary post
	 * capabilities (author/editor) never reach them; the caps therefore have
	 * to be granted explicitly, and only administrators get them. Meta caps
	 * (edit_post/read_post/delete_post) are skipped — map_meta_cap resolves
	 * those to the primitives at check time. Idempotent: roles are only
	 * written when a capability is actually missing.
	 *
	 * @param string $slug Registered post type slug.
	 * @return void
	 */
	private function grant_admin_capabilities( $slug ) {
		$role      = get_role( 'administrator' );
		$post_type = get_post_type_object( $slug );
		if ( ! $role || ! $post_type ) {
			return;
		}

		$meta_caps = array( 'edit_post', 'read_post', 'delete_post' );
		foreach ( (array) $post_type->cap as $core_cap => $capability ) {
			if ( in_array( $core_cap, $meta_caps, true ) || 'read' === $capability ) {
				continue;
			}
			if ( ! $role->has_cap( $capability ) ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * Assign capabilities to users
	 *
	 * Grants every capability in the map to each named role so those roles can see and manage
	 * the custom post type (without this even administrators cannot access it).
	 *
	 * @link https://codex.wordpress.org/Function_Reference/register_post_type
	 * @link https://typerocket.com/ultimate-guide-to-custom-post-types-in-wordpress/
	 *
	 * @param array $caps_map Map of WordPress cap key => custom capability string.
	 * @param array $users    Role slugs (e.g. 'administrator') to receive the capabilities.
	 * @return void
	 */
	public function assign_capabilities( $caps_map, $users ) {

		// Loop over each target role slug.
		foreach ( $users as $user ) {
			$user_role = get_role( $user );

			// Add every mapped capability to that role.
			foreach ( $caps_map as $cap_map_key => $capability ) {
				$user_role->add_cap( $capability );
			}
		}
	}




	/**
	 * Create post types.
	 *
	 * Defines the mlsimport_item ("Import Tasks") post type configuration and registers it.
	 * Typically hooked to 'init'. Built as an array of definitions so more CPTs can be added
	 * to the loop later.
	 *
	 * @return void
	 */
	public function create_custom_post_type() {

		/**
		 * This is not all the fields, only what I find important. Feel free to change this function ;)
		 *
		 * @link https://codex.wordpress.org/Function_Reference/register_post_type
		 *
		 * For more info on fields:
		 * @link https://github.com/JoeSz/WordPress-Plugin-Boilerplate-Tutorial/blob/9fb56794bc1f8aebfe04e99b15881db0c4bc61bd/mlsimport/includes/class-mlsimport-post_types.php#L230
		 */

		// Base slug used in the permalink rewrite structure below.
		$custom_slug = 'mlsimport';

		// One definition per custom post type; currently just the mlsimport_item task type.
		$post_types_fields = array(
			array(
				'slug'                => 'mlsimport_item',
				'singular'            => __( 'Import Task', 'mlsimport' ),
				'plural'              => __( 'Import Tasks', 'mlsimport' ),
				'menu_name'           => __( 'Import Tasks', 'mlsimport' ),
				'description'         => __( 'Import Task', 'mlsimport' ),
				'has_archive'         => true,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-tag',
				'rewrite'             => array(
					'slug'       => $custom_slug,
					'with_front' => true,
					'pages'      => true,
					'feeds'      => true,
					'ep_mask'    => EP_PERMALINK,
				),
				'menu_position'       => 21,
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'query_var'           => true,
				'show_in_admin_bar'   => true,
				'show_in_nav_menus'   => false,
				// Admin-only boundary: dedicated caps, granted solely to administrators.
				'capability_type'     => array( 'mlsimport_item', 'mlsimport_items' ),
				'map_meta_cap'        => true,
				'supports'            => array(
					'title',

				),
				'custom_caps'         => false,
				'custom_caps_users'   => array(
					'administrator',
				),
				'taxonomies'          => array(),
				'menu_icon'           => MLSIMPORT_PLUGIN_URL . '/img/mlsimport_menu.png',
			),
		);

		// loop torugh custom post type array and register
		// Register each defined post type in turn.
		foreach ( $post_types_fields as $fields ) {
			$this->register_single_post_type( $fields );
		}
	}
}
