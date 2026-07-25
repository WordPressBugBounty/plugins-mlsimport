<?php
/**
 * Standalone (theme_id 990) single-property section blocks (generic adapter).
 *
 * One adapter that loops the section manifest and registers a dynamic Gutenberg
 * block (mlsimport/property-<slug>) per entry, whose render_callback hands off to
 * the one dispatcher — so block, shortcode and Elementor widget emit identical
 * markup. A single editor script registers every block in the editor with the
 * brand icon. Adding a section needs no change here. See docs/adr/0005.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/property-section-registry.php';

/**
 * Registers a dynamic block per registered property section.
 */
class Mlsimport_Property_Section_Blocks {

	/**
	 * Register one dynamic block for every manifest entry.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		mlsimport_register_builtin_property_sections();
		$editor_script = self::register_editor_script();

		foreach ( mlsimport_get_property_sections() as $slug => $def ) {
			$block_name = 'mlsimport/property-' . str_replace( '_', '-', $slug );
			if ( WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
				continue;
			}
			register_block_type(
				$block_name,
				array(
					'api_version'     => 2,
					'attributes'      => array( 'id' => array( 'type' => 'number', 'default' => 0 ) ),
					'render_callback' => static function ( $attributes ) use ( $slug ) {
						return mlsimport_render_property_section( $slug, isset( $attributes['id'] ) ? (int) $attributes['id'] : 0 );
					},
					'editor_script'   => $editor_script,
				)
			);
		}
	}

	/**
	 * Register + localize the shared editor script that registers all section
	 * blocks in the editor. Returns the handle (or '' if scripts unavailable).
	 *
	 * @return string
	 */
	private static function register_editor_script(): string {
		if ( ! function_exists( 'wp_register_script' ) ) {
			return '';
		}

		$handle = 'mlsimport-property-sections-block';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			$url = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL . 'admin/js/mlsimport-property-sections-block.js' : '';
			wp_register_script(
				$handle,
				$url,
				array( 'wp-blocks', 'wp-element', 'wp-server-side-render' ),
				defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : false,
				true
			);

			$sections = array();
			foreach ( mlsimport_get_property_sections() as $slug => $def ) {
				$sections[] = array(
					'slug'  => str_replace( '_', '-', $slug ),
					'title' => $def['label'],
				);
			}
			$icon = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL . 'img/mlsimport_menu.png' : '';
			wp_localize_script( $handle, 'MLSImportSections', $sections );
			wp_add_inline_script( $handle, 'window.MLSImportSectionsIcon=' . wp_json_encode( $icon ) . ';', 'before' );
		}

		return $handle;
	}
}
