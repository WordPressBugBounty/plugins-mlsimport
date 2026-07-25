<?php
/**
 * Standalone (theme_id 990) page-block shortcodes (generic adapter).
 *
 * One adapter that loops the page-block manifest and registers a
 * [mlsimport_<slug>] shortcode for every entry. Each shortcode maps its atts
 * (defaulted from the block's arg schema) and hands off to the one dispatcher — so
 * shortcode, Gutenberg block and Elementor widget all emit identical markup.
 * Adding a block needs no change here. See docs/adr/0007.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/page-block-registry.php';

/**
 * Registers a shortcode per registered page block.
 */
class Mlsimport_Page_Block_Shortcodes {

	/**
	 * Register one shortcode for every manifest entry.
	 *
	 * @return void
	 */
	public static function register(): void {
		// One [mlsimport_<slug>] shortcode per manifest entry.
		foreach ( mlsimport_get_page_blocks() as $slug => $def ) {
			add_shortcode(
				'mlsimport_' . $slug,
				static function ( $atts ) use ( $slug ) {
					return self::render( $slug, $atts );
				}
			);
		}
	}

	/**
	 * Map shortcode atts (defaulted from the schema) to a page-block render.
	 *
	 * @param string       $slug Block slug.
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( string $slug, $atts ): string {
		$atts = shortcode_atts( mlsimport_page_block_defaults( $slug ), (array) $atts, 'mlsimport_' . $slug );
		return mlsimport_render_page_block( $slug, $atts );
	}
}
