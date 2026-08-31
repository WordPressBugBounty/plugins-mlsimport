<?php
/**
 * Standalone (theme_id 990) custom post types and taxonomies.
 *
 * Registers the two standalone CPTs (mlsimport_property, mlsimport_agent) and
 * the flat mlsimport_-prefixed taxonomies (§7). Per ADR-0003 these are ALWAYS
 * registered with show_in_rest so the data layer works in every mode — but the
 * admin UI AND the public URL surface (public/has_archive/rewrite) follow
 * standalone mode. Outside 990 the CPTs must not own front-end URLs at all: a
 * CPT archive rewrite out-ranks WordPress's page rule, so any base they claim
 * is taken away from a page or theme CPT already using it (#206). Inside 990
 * the property base is property_slug() — 'listing' by default and admin-
 * settable, never the heavily contested 'properties'. maybe_flush_rewrites()
 * keeps the cached rewrite_rules option in sync when the mode or base changes.
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
	 * The default property URL base. See property_slug() for why it is not
	 * 'properties'.
	 */
	private const DEFAULT_PROPERTY_SLUG = 'listing';

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
	 * One-time repair for packed taxonomy terms (issue #290).
	 *
	 * Before 7.1.2 a multi-enum RESO field arriving as one comma-glued string
	 * ("Back Yard,Corners Marked") became ONE term whose sanitized slug glued
	 * every value together — a dead-end archive every feature chip linked to.
	 * The importer now splits such values at write time; this walks the terms
	 * that pre-fix imports already created and repairs them in place.
	 *
	 * Step by step, per plugin taxonomy:
	 *   1. Find terms whose name contains a comma (the packed ones).
	 *   2. For every post carrying a packed term, append the individual parts
	 *      as their own terms (created on the fly by wp_set_object_terms).
	 *   3. Delete the packed term — wp_delete_term also detaches it everywhere.
	 *
	 * @return int Number of packed terms split (0 on a clean site).
	 */
	public static function split_packed_terms(): int {
		$split = 0;
		foreach ( self::taxonomy_slugs() as $taxonomy ) {
			// Step 1 - every term in the taxonomy, attached or not.
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				// Only packed names need repair.
				if ( false === strpos( $term->name, ',' ) ) {
					continue;
				}
				// The individual values hidden inside the packed name.
				$parts = array_filter( array_map( 'trim', explode( ',', $term->name ) ), 'strlen' );

				// Step 2 - re-file every post carrying the packed term under the
				// individual parts (append, so its other terms are kept).
				$post_ids = get_objects_in_term( $term->term_id, $taxonomy );
				foreach ( ( is_array( $post_ids ) ? $post_ids : array() ) as $post_id ) {
					wp_set_object_terms( (int) $post_id, $parts, $taxonomy, true );
				}

				// Step 3 - remove the packed term (and its dead-end archive).
				wp_delete_term( $term->term_id, $taxonomy );
				$split++;
			}
		}
		return $split;
	}

	/**
	 * Run split_packed_terms() once per site, guarded by an option so the term
	 * sweep doesn't repeat on every admin_init.
	 *
	 * @return void
	 */
	public static function maybe_split_packed_terms(): void {
		// Already repaired: nothing to do.
		if ( get_option( 'mlsimport_packed_terms_split' ) ) {
			return;
		}
		self::split_packed_terms();
		// Remember the repair ran so this stays a one-time migration.
		update_option( 'mlsimport_packed_terms_split', 1 );
	}

	/**
	 * The URL base the property archive and single permalinks sit on.
	 *
	 * Deliberately NOT 'properties' (#206): a CPT archive rewrite out-ranks
	 * WordPress's page rule, so that base silently swallows any page or theme
	 * property CPT already using it. 'listing' is the default; the admin can
	 * move it from the standalone settings when even that collides.
	 *
	 * @return string The rewrite slug, never empty.
	 */
	public static function property_slug(): string {
		// Step 1 - read the admin's choice, defaulting to the safe base.
		$slug = (string) mlsimport_standalone_option( 'property_url_slug', self::DEFAULT_PROPERTY_SLUG );

		// Step 2 - normalise it the way WordPress normalises any permalink part,
		// so 'Homes For Sale' becomes a working base instead of a broken one.
		$slug = sanitize_title( $slug );

		// Step 3 - a setting that normalises to nothing would leave the archive
		// with no base at all, so fall back rather than register it.
		return '' !== $slug ? $slug : self::DEFAULT_PROPERTY_SLUG;
	}

	/**
	 * Register the CPTs and taxonomies. Hook to init in production.
	 *
	 * @return void
	 */
	public static function register(): void {
		// ADR-0003: always registered (REST/data layer), but both the admin UI
		// and the front-end URL surface follow standalone mode. Outside 990 the
		// CPTs must not be public and must pass rewrite => false: an archive
		// rewrite beats WordPress's page rule and silently hijacks whatever
		// page or theme CPT already sits on that base (#206).
		$standalone = function_exists( 'mlsimport_is_standalone_mode' ) && mlsimport_is_standalone_mode();
		$show_ui    = $standalone;

		// Same brand icon as the Import Tasks menu.
		$menu_icon = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL . 'img/mlsimport_menu.png' : 'dashicons-admin-home';

		/** Filter the property CPT registration args. @since 6.3 */
		register_post_type(
			'mlsimport_property',
			apply_filters(
				'mlsimport_property_post_type_args',
				array(
					'public'       => $standalone,
					'show_ui'      => $show_ui,
					'show_in_menu' => $show_ui,
					'show_in_rest' => true,
					'menu_icon'    => $menu_icon,
					'has_archive'  => $standalone,
					'rewrite'      => $standalone ? array( 'slug' => self::property_slug() ) : false,
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
					'public'       => $standalone,
					'show_ui'      => $show_ui,
					'show_in_menu' => $show_ui,
					'show_in_rest' => true,
					'menu_icon'    => $menu_icon,
					'has_archive'  => $standalone,
					// Core keys rewrite generation off this arg, not off 'public'.
					'rewrite'      => $standalone,
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
						'public'       => $standalone,
						'show_ui'      => $show_ui,
						'show_in_menu' => $show_ui,
						'show_in_rest' => true,
						// Core keys rewrite generation off this arg, not off 'public'.
						'rewrite'      => $standalone,
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

	/**
	 * Drop the cached rewrite rules whenever the stored mode signature no
	 * longer matches the current standalone mode (#206).
	 *
	 * The CPT archive rule only exists in standalone mode since the fix above,
	 * and its base is now a setting, but WordPress caches compiled rules in the
	 * rewrite_rules option — so a mode switch, a base change, or updating to
	 * this version on a site where the stale rule already hijacked a
	 * /properties/ page all leave wrong rules in the DB. Deleting the option makes WordPress lazily
	 * rebuild the rules on the next request, after init has registered the
	 * CPTs with the correct args for the current mode. Hooked to init after
	 * register(); on matching signatures (every ordinary request) it is a
	 * single get_option and does nothing.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrites(): void {
		// Signature = fix revision + current mode + the archive base. The slug is
		// part of it because an admin changing the base from the settings screen
		// changes the rules register() produces, exactly like a mode switch does.
		// Bump 'v2' if rewrite-affecting args change again.
		$standalone = function_exists( 'mlsimport_is_standalone_mode' ) && mlsimport_is_standalone_mode();
		$signature  = 'v2:' . ( $standalone ? '990' : 'other' ) . ':' . self::property_slug();

		if ( get_option( 'mlsimport_rewrite_mode' ) === $signature ) {
			return;
		}
		delete_option( 'rewrite_rules' );
		update_option( 'mlsimport_rewrite_mode', $signature );
	}
}
