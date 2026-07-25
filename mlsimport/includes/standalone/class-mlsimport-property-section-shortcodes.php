<?php
/**
 * Standalone (theme_id 990) single-property section shortcodes (generic adapter).
 *
 * One adapter that loops the section manifest and registers a
 * [mlsimport_property_<slug>] shortcode for every entry. Each shortcode resolves
 * the property (optional id att; otherwise the loop post) and hands off to the
 * one dispatcher — so the shortcode, block and Elementor widget all emit the
 * same markup. Adding a section needs no change here. See docs/adr/0005.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/property-section-registry.php';

/**
 * Registers a shortcode per registered property section.
 */
class Mlsimport_Property_Section_Shortcodes {

	/**
	 * Register one shortcode for every manifest entry.
	 *
	 * @return void
	 */
	public static function register(): void {
		mlsimport_register_builtin_property_sections();

		foreach ( array_keys( mlsimport_get_property_sections() ) as $slug ) {
			add_shortcode(
				'mlsimport_property_' . $slug,
				static function ( $atts ) use ( $slug ) {
					return self::render( $slug, $atts );
				}
			);
		}
	}

	/**
	 * Map shortcode atts to a section render.
	 *
	 * @param string       $slug Section slug.
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( string $slug, $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'mlsimport_property_' . $slug );
		return mlsimport_render_property_section( $slug, (int) $atts['id'] );
	}
}
