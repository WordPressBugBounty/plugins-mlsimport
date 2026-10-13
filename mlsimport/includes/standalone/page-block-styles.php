<?php
/**
 * Page-block styles: the look of one page block, set on that block.
 *
 * Every page block has style options (page-block-style-options.php): plain
 * names like price_color, columns or button_background, each with a kind of
 * value, the CSS it writes and, when one exists, the Elementor Style tab
 * control that does the same. The names are the ones the AI tools
 * (update-page-block) accept and the block editor stores, so the AI's words
 * mean the same on both builders.
 *
 * In the block editor the styles live in the block's `styles` attribute (an
 * object: option => value), registered here on every page block. At render
 * time this file wraps the block's markup in a div with a unique class and
 * prints the CSS of the set options scoped to that class, through the
 * mlsimport_page_block_html filter of the dispatcher. On Elementor the
 * widget's own CSS file applies the Style tab controls; only an option with
 * no native control reaches this wrap (through the widget's hidden
 * mlsimport_styles setting).
 *
 * Public interface:
 *   mlsimport_page_block_style_blocks()                       the blocks that have styles
 *   mlsimport_page_block_style_options( $slug )               the style options of one block
 *   mlsimport_page_block_style_keys( $slug )                  their names
 *   mlsimport_page_block_style_value( $slug, $key, $value )   a checked value, or null
 *   mlsimport_page_block_styles_css( $slug, $styles, $scope ) the CSS of the set options
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/standalone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/page-block-style-options.php';

/**
 * The page blocks that have style options: every registered page block
 * with a style table.
 *
 * @return string[] Block slugs.
 */
function mlsimport_page_block_style_blocks(): array {
	$slugs = array();
	foreach ( array_keys( mlsimport_get_page_blocks() ) as $slug ) {
		if ( ! empty( mlsimport_page_block_style_table( (string) $slug ) ) ) {
			$slugs[] = (string) $slug;
		}
	}
	return $slugs;
}

/**
 * The style options of one block: name => row (label, kind, selector, css,
 * elementor, control); see page-block-style-options.php.
 *
 * @param string $slug Page block slug.
 * @return array<string, array<string, string>>
 */
function mlsimport_page_block_style_options( string $slug ): array {
	return mlsimport_page_block_style_table( $slug );
}

/**
 * The names of the style options of one block.
 *
 * @param string $slug Page block slug.
 * @return string[] Option names; [] for a block without styles.
 */
function mlsimport_page_block_style_keys( string $slug ): array {
	return array_keys( mlsimport_page_block_style_table( $slug ) );
}

/**
 * Check one style value: a color must be a hex color (#rgb or #rrggbb), a
 * number a whole number of pixels, and columns 1 to 6.
 *
 * @param string $slug  Page block slug.
 * @param string $key   Option name.
 * @param mixed  $value The value as sent or stored.
 * @return string|int|null The clean value, or null when it is not acceptable.
 */
function mlsimport_page_block_style_value( string $slug, string $key, $value ) {
	$option = mlsimport_page_block_style_table( $slug )[ $key ] ?? null;
	if ( ! $option ) {
		return null;
	}
	if ( 'color' === $option['kind'] ) {
		return 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $value ) ? strtolower( (string) $value ) : null;
	}
	if ( ! is_numeric( $value ) || (float) $value < 0 ) {
		return null;
	}
	$number = (int) $value;
	return 'columns' === $option['kind'] && ( $number < 1 || $number > 6 ) ? null : $number;
}

/**
 * The CSS of the set style options, each rule scoped to the wrapper class.
 * An option with several selectors (comma separated) gets one rule each.
 *
 * @param string $slug   Page block slug.
 * @param array  $styles Option name => value, as stored on the block.
 * @param string $scope  The class of the wrapper div.
 * @return string CSS rules; '' when no option is set.
 */
function mlsimport_page_block_styles_css( string $slug, array $styles, string $scope ): string {
	$rules = array();
	foreach ( mlsimport_page_block_style_table( $slug ) as $key => $option ) {
		$value = isset( $styles[ $key ] ) ? mlsimport_page_block_style_value( $slug, $key, $styles[ $key ] ) : null;
		if ( null === $value ) {
			continue;
		}
		foreach ( explode( ',', $option['selector'] ) as $selector ) {
			$rules[] = '.' . $scope . ' ' . trim( $selector ) . '{' . sprintf( $option['css'], $value ) . '}';
		}
	}
	return implode( '', $rules );
}

/**
 * Wrap a block's markup in a div that carries its styles.
 *
 * Step by step:
 * 1. Only a block with a non-empty `styles` arg is wrapped; a page builder
 *    that applies the styles itself never passes that arg.
 * 2. The wrapper class comes from the styles themselves, so two blocks with
 *    the same styles share one class and a changed style gets a new one.
 * 3. The scoped CSS is printed just before the markup. The values were
 *    checked (hex color or whole number), so the CSS needs no escaping.
 *
 * @param string $html The block's markup.
 * @param string $slug Page block slug.
 * @param array  $args The resolved block args.
 * @return string The markup, wrapped when it has styles.
 */
function mlsimport_page_block_styles_wrap( $html, string $slug, array $args ): string {
	$html   = (string) $html;
	$styles = isset( $args['styles'] ) && is_array( $args['styles'] ) ? $args['styles'] : array();
	if ( '' === $html || empty( $styles ) ) {
		return $html;
	}
	$scope = 'mlsimport-styled-' . substr( md5( $slug . (string) wp_json_encode( $styles ) ), 0, 8 );
	$css   = mlsimport_page_block_styles_css( $slug, $styles, $scope );
	if ( '' === $css ) {
		return $html;
	}
	return '<div class="' . esc_attr( $scope ) . '"><style>' . $css . '</style>' . $html . '</div>';
}
add_filter( 'mlsimport_page_block_html', 'mlsimport_page_block_styles_wrap', 10, 3 );

/**
 * Give every page block of the block editor the `styles` attribute, so the
 * editor keeps it when a page is saved by hand (the editor scripts declare
 * the same attribute).
 *
 * @param array  $args Block type registration args.
 * @param string $name Block name, for example mlsimport/item-list.
 * @return array The args, with the attribute on the blocks that have styles.
 */
function mlsimport_page_block_styles_attribute( array $args, string $name ): array {
	foreach ( mlsimport_page_block_style_blocks() as $slug ) {
		if ( 'mlsimport/' . str_replace( '_', '-', $slug ) === $name ) {
			$args['attributes']['styles'] = array(
				'type'    => 'object',
				'default' => array(),
			);
		}
	}
	return $args;
}
add_filter( 'register_block_type_args', 'mlsimport_page_block_styles_attribute', 10, 2 );

/**
 * Register the block editor script of the "Look" sidebar panel and hand it
 * every block's style options (name, label, kind), keyed by block name.
 *
 * Step by step:
 * 1. Runs early on init so the two editor scripts that register the page
 *    blocks (Mlsimport_Standalone_Block, Mlsimport_Page_Block_Blocks) can
 *    list this handle as a dependency.
 * 2. The script is versioned by file time so an edit busts the editor cache.
 * 3. The options reach the script as MLSImportBlockStyleOptions:
 *    { 'mlsimport/item-list': [ { key, label, kind }, ... ], ... }.
 *
 * @return void
 */
function mlsimport_page_block_styles_editor_script(): void {
	if ( ! function_exists( 'wp_register_script' ) ) {
		return;
	}
	$url  = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );
	$path = defined( 'MLSIMPORT_PLUGIN_PATH' ) ? MLSIMPORT_PLUGIN_PATH . 'admin/js/mlsimport-page-block-styles.js' : '';
	$ver  = ( $path && file_exists( $path ) ) ? (string) filemtime( $path ) : ( defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : '1' );
	wp_register_script(
		'mlsimport-page-block-styles',
		$url . 'admin/js/mlsimport-page-block-styles.js',
		array( 'wp-element', 'wp-components', 'wp-block-editor' ),
		$ver,
		true
	);
	$options = array();
	foreach ( mlsimport_page_block_style_blocks() as $slug ) {
		$rows = array();
		foreach ( mlsimport_page_block_style_table( $slug ) as $key => $option ) {
			$rows[] = array(
				'key'   => $key,
				'label' => $option['label'],
				'kind'  => $option['kind'],
			);
		}
		$options[ 'mlsimport/' . str_replace( '_', '-', $slug ) ] = $rows;
	}
	wp_localize_script( 'mlsimport-page-block-styles', 'MLSImportBlockStyleOptions', $options );
}
add_action( 'init', 'mlsimport_page_block_styles_editor_script', 5 );
