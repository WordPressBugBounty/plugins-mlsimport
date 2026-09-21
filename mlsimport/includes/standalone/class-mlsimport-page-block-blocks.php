<?php
/**
 * Standalone (theme_id 990) page-block Gutenberg adapter (generic).
 *
 * One adapter that loops the page-block manifest and registers a dynamic,
 * server-rendered Gutenberg block (mlsimport/<slug>) per entry, whose
 * render_callback hands off to the one dispatcher — so block, shortcode and
 * Elementor widget emit identical markup. A single editor script registers every
 * block in the editor and builds its inspector controls from the arg schema.
 * Adding a block needs no change here. See docs/adr/0007.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/page-block-registry.php';

/**
 * Registers a dynamic block per registered page block.
 */
class Mlsimport_Page_Block_Blocks {

	/**
	 * Register one dynamic block for every manifest entry.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$editor_script = self::register_editor_script();

		foreach ( mlsimport_get_page_blocks() as $slug => $def ) {
			// Half Map, Search Results, Content Slider, Property List and Map with Listings
			// are owned by Mlsimport_Standalone_Block so they reuse the rich listings
			// inspector (the "Initial filter" panel + per-field toggles) instead of one raw
			// text input per key. Skip them here to avoid a double register.
			if ( 'half_map' === $slug || 'property_list_filters' === $slug || 'content_slider' === $slug || 'item_list' === $slug || 'map_listings' === $slug ) {
				continue;
			}
			$block_name = 'mlsimport/' . str_replace( '_', '-', $slug );
			if ( WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
				continue;
			}
			register_block_type(
				$block_name,
				array(
					'api_version'     => 2,
					'attributes'      => self::attributes( $def['args'] ),
					'render_callback' => static function ( $attributes ) use ( $slug ) {
						return mlsimport_render_page_block( $slug, (array) $attributes );
					},
					'editor_script'   => $editor_script,
				)
			);
		}
	}

	/**
	 * Block attribute schema derived from a block's arg schema.
	 *
	 * @param array $args Arg schema.
	 * @return array
	 */
	private static function attributes( array $args ): array {
		$attributes = array();
		foreach ( $args as $key => $schema ) {
			$type = isset( $schema['type'] ) ? $schema['type'] : 'text';
			if ( 'number' === $type ) {
				$attributes[ $key ] = array( 'type' => 'number', 'default' => isset( $schema['default'] ) ? (float) $schema['default'] : 0 );
			} elseif ( 'toggle' === $type ) {
				$attributes[ $key ] = array( 'type' => 'boolean', 'default' => ! empty( $schema['default'] ) );
			} elseif ( 'repeater' === $type ) {
				$attributes[ $key ] = array( 'type' => 'array', 'default' => isset( $schema['default'] ) ? (array) $schema['default'] : array() );
			} else {
				// Includes 'multiselect': like every taxonomy filter it stores a comma
				// list of term ids as a string (the FormTokenField serializes to it).
				$attributes[ $key ] = array( 'type' => 'string', 'default' => isset( $schema['default'] ) ? (string) $schema['default'] : '' );
			}
		}
		return $attributes;
	}

	/**
	 * Register + localize the shared editor script that registers all page blocks
	 * in the editor. Returns the handle (or '' if scripts unavailable).
	 *
	 * @return string
	 */
	private static function register_editor_script(): string {
		if ( ! function_exists( 'wp_register_script' ) ) {
			return '';
		}

		$handle = 'mlsimport-page-block-blocks';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			$url = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL . 'admin/js/mlsimport-page-block-blocks.js' : '';
			// Version by file mtime so a JS edit busts the editor cache even when the
			// plugin version constant is unchanged — otherwise the browser keeps the
			// stale script and ignores fixes (e.g. the repeater array-attribute fix).
			$path = defined( 'MLSIMPORT_PLUGIN_PATH' ) ? MLSIMPORT_PLUGIN_PATH . 'admin/js/mlsimport-page-block-blocks.js' : '';
			$ver  = ( $path && file_exists( $path ) ) ? (string) filemtime( $path ) : ( defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : false );
			wp_register_script(
				$handle,
				$url,
				array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ),
				$ver,
				true
			);

			$blocks = array();
			foreach ( mlsimport_get_page_blocks() as $slug => $def ) {
				// Half Map, Search Results, Content Slider, Property List and Map with Listings
				// register via Mlsimport_Standalone_Block's editor script; if this generic
				// script also registered them, the second registerBlockType would be a no-op
				// warning. Keep them out of this manifest.
				if ( 'half_map' === $slug || 'property_list_filters' === $slug || 'content_slider' === $slug || 'item_list' === $slug || 'map_listings' === $slug ) {
					continue;
				}
				$blocks[] = array(
					'slug'  => str_replace( '_', '-', $slug ),
					'title' => $def['label'],
					'args'  => self::js_controls( $def['args'] ),
				);
			}
			$icon = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL . 'img/mlsimport_menu.png' : '';
			wp_localize_script( $handle, 'MLSImportPageBlocks', $blocks );
			wp_add_inline_script( $handle, 'window.MLSImportPageBlocksIcon=' . wp_json_encode( $icon ) . ';', 'before' );
		}

		return $handle;
	}

	/**
	 * Reduce a block's arg schema to the data the editor JS needs to build a
	 * control per field (key, type, label, default, options).
	 *
	 * @param array $args Arg schema.
	 * @return array
	 */
	private static function js_controls( array $args ): array {
		$controls = array();
		foreach ( $args as $key => $schema ) {
			$type = isset( $schema['type'] ) ? $schema['type'] : 'text';

			// A multiselect (the Category widgets' per-taxonomy pickers, or the Team
			// Directory agent picker) resolves its options only in the admin editor,
			// where the picker is shown; the front end reads the saved picks and never
			// needs the list. Source is a taxonomy's terms or a post type's posts. An
			// empty option set (no terms/posts yet) gets no picker.
			if ( 'multiselect' === $type && ( isset( $schema['taxonomy'] ) || isset( $schema['post_type'] ) ) ) {
				if ( ! is_admin() ) {
					$options = array();
				} elseif ( isset( $schema['taxonomy'] ) ) {
					$options = mlsimport_category_term_options( (string) $schema['taxonomy'] );
				} else {
					$options = mlsimport_post_type_options( (string) $schema['post_type'] );
				}
				if ( is_admin() && empty( $options ) ) {
					continue;
				}
				$controls[] = array(
					'key'     => $key,
					'type'    => 'multiselect',
					'label'   => isset( $schema['label'] ) ? $schema['label'] : $key,
					'default' => '',
					'options' => $options,
				);
				continue;
			}

			$control = array(
				'key'     => $key,
				'type'    => $type,
				'label'   => isset( $schema['label'] ) ? $schema['label'] : $key,
				'default' => isset( $schema['default'] ) ? $schema['default'] : '',
				'options' => isset( $schema['options'] ) ? $schema['options'] : null,
			);
			// A repeater carries its row sub-field schema so the editor can build a
			// control per column.
			if ( isset( $schema['fields'] ) ) {
				$control['fields'] = self::js_controls( (array) $schema['fields'] );
			}
			// A show-only-when rule ({ other_key: value|values }, Elementor's format), so
			// the editor hides e.g. a row's slider bounds on non-range fields.
			if ( ! empty( $schema['condition'] ) ) {
				$control['condition'] = (array) $schema['condition'];
			}
			$controls[] = $control;
		}
		return $controls;
	}
}
