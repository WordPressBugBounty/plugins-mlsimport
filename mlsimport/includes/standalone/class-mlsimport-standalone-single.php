<?php
/**
 * Standalone (theme_id 990) single/archive template routing (M6).
 *
 * Routes single + archive views of mlsimport_property to the plugin's bundled
 * (theme-overridable) templates, so the listing detail page works on any theme.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-template.php';

/**
 * Swaps in the standalone single/archive templates via template_include.
 */
class Mlsimport_Standalone_Single {

	/**
	 * Use a bundled template for standalone single/archive views (template_include).
	 *
	 * @param string $template The template WordPress resolved.
	 * @return string
	 */
	public static function template_include( $template ) {
		/** Filter the standalone template routing decision. @since 6.3 */
		return apply_filters( 'mlsimport_template_include', self::resolve( $template ), $template );
	}

	/**
	 * Resolve the bundled template for the current standalone view, or the
	 * original template when this isn't a standalone view.
	 *
	 * @param string $template The template WordPress resolved.
	 * @return string
	 */
	private static function resolve( $template ) {
		// Single property detail page -> bundled single-property template.
		if ( is_singular( 'mlsimport_property' ) ) {
			$located = Mlsimport_Standalone_Template::locate( 'single-mlsimport-property.php' );
			if ( file_exists( $located ) ) {
				return $located;
			}
		}

		// Property archive OR any of the plugin's property taxonomy archives.
		if ( is_post_type_archive( 'mlsimport_property' ) || is_tax( Mlsimport_Standalone_Cpt::taxonomy_slugs() ) ) {
			$located = Mlsimport_Standalone_Template::locate( 'archive-mlsimport-property.php' );
			if ( file_exists( $located ) ) {
				return $located;
			}
		}

		// Single agent profile page -> bundled single-agent template.
		if ( is_singular( 'mlsimport_agent' ) ) {
			$located = Mlsimport_Standalone_Template::locate( 'single-mlsimport-agent.php' );
			if ( file_exists( $located ) ) {
				return $located;
			}
		}

		// Agent archive (directory) -> bundled agent-archive template.
		if ( is_post_type_archive( 'mlsimport_agent' ) ) {
			$located = Mlsimport_Standalone_Template::locate( 'archive-mlsimport-agent.php' );
			if ( file_exists( $located ) ) {
				return $located;
			}
		}

		// Not a standalone view (or the bundled file is missing): leave WP's choice.
		return $template;
	}
}
