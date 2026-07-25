<?php
/**
 * Standalone (theme_id 990) custom post types and taxonomies.
 *
 * Registers the two standalone CPTs (mlsimport_property, mlsimport_agent) and
 * the flat mlsimport_-prefixed taxonomies (§7). Per ADR-0003 these are ALWAYS
 * registered with show_in_rest; the show_ui / write-path gating on mode 990 is
 * M0 plumbing and is layered on later.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the standalone CPTs and taxonomies.
 */
class Mlsimport_Standalone_Cpt {

	/**
	 * The flat taxonomies (§7), all attached to mlsimport_property. Flat
	 * (hierarchical=false) — term trees confuse non-technical realtors.
	 */
	private const TAXONOMIES = array(
		'mlsimport_property_type'        => 'Property Types',
		'mlsimport_listing_type'         => 'Listing Types',
		'mlsimport_status'               => 'Statuses',
		'mlsimport_city'                 => 'Cities',
		'mlsimport_area'                 => 'Areas',
		'mlsimport_county'               => 'Counties',
		'mlsimport_state'                => 'States',
		'mlsimport_zip'                  => 'ZIP Codes',
		'mlsimport_high_school_district' => 'High School Districts',
		'mlsimport_feature'              => 'Features',
		'mlsimport_label'                => 'Labels',
	);

	/**
	 * The plugin taxonomy slugs, after the mlsimport_taxonomies filter — the
	 * single source of truth for anything that needs to iterate the taxonomies
	 * (e.g. per-term settings).
	 *
	 * @return string[]
	 */
	public static function taxonomy_slugs(): array {
		return array_keys( (array) apply_filters( 'mlsimport_taxonomies', self::TAXONOMIES ) );
	}

	/**
	 * The plugin taxonomies as slug => label, after the mlsimport_taxonomies
	 * filter — the single source for anything needing a labelled taxonomy list
	 * (e.g. the Category widgets' taxonomy picker).
	 *
	 * @return array<string,string>
	 */
	public static function taxonomy_labels(): array {
		return (array) apply_filters( 'mlsimport_taxonomies', self::TAXONOMIES );
	}

	/**
	 * Register the CPTs and taxonomies. Hook to init in production.
	 *
	 * @return void
	 */
	public static function register(): void {
		// ADR-0003: always registered (public/REST), but the admin UI only shows
		// in standalone mode so the standalone catalog doesn't clutter other themes.
		$show_ui = function_exists( 'mlsimport_is_standalone_mode' ) && mlsimport_is_standalone_mode();

		// Same brand icon as the Import Tasks menu.
		$menu_icon = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL . 'img/mlsimport_menu.png' : 'dashicons-admin-home';

		/** Filter the property CPT registration args. @since 6.3 */
		register_post_type(
			'mlsimport_property',
			apply_filters(
				'mlsimport_property_post_type_args',
				array(
					'public'       => true,
					'show_ui'      => $show_ui,
					'show_in_menu' => $show_ui,
					'show_in_rest' => true,
					'menu_icon'    => $menu_icon,
					'has_archive'  => true,
					'rewrite'      => array( 'slug' => 'properties' ),
					'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'comments', 'author' ),
					'labels'       => array( 'name' => 'MLS Properties' ),
					// Group above core Comments (25) so all MLSImport menus stay together.
					'menu_position' => 22,
				)
			)
		);

		/** Filter the agent CPT registration args. @since 6.3 */
		register_post_type(
			'mlsimport_agent',
			apply_filters(
				'mlsimport_agent_post_type_args',
				array(
					'public'       => true,
					'show_ui'      => $show_ui,
					'show_in_menu' => $show_ui,
					'show_in_rest' => true,
					'menu_icon'    => $menu_icon,
					'has_archive'  => true,
					'supports'     => array( 'title', 'editor', 'thumbnail' ),
					'labels'       => array( 'name' => 'Real Estate Agents' ),
					'menu_position' => 23,
				)
			)
		);

		/** Filter the standalone taxonomy list (slug => label). @since 6.3 */
		$taxonomies = (array) apply_filters( 'mlsimport_taxonomies', self::TAXONOMIES );
		// Register each taxonomy against the property CPT with the shared arg set.
		foreach ( $taxonomies as $taxonomy => $label ) {
			/** Filter a taxonomy's registration args. @since 6.3 */
			register_taxonomy(
				$taxonomy,
				'mlsimport_property',
				apply_filters(
					'mlsimport_taxonomy_args',
					array(
						'public'       => true,
						'show_ui'      => $show_ui,
						'show_in_menu' => $show_ui,
						'show_in_rest' => true,
						'hierarchical' => false,
						'labels'       => array( 'name' => $label ),
					),
					$taxonomy
				)
			);
		}

		/** Fires after the standalone CPTs + taxonomies are registered. @since 6.3 */
		do_action( 'mlsimport_registered_cpts' );
	}
}
