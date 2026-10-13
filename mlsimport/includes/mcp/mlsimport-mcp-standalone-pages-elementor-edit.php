<?php
/**
 * AI tools (MCP), standalone mode only: the Elementor side of reading and
 * changing the page blocks a page already has.
 *
 * An Elementor page is its document: a tree of containers (or sections and
 * columns) holding widgets. The plugin's widgets are the ones whose
 * widgetType is mlsimport-<slug with dashes> for a registered page block.
 * This file walks the tree in page order, counting only those widgets, so a
 * "position" (1 is the first plugin block on the page) means the same to
 * page-blocks, update-page-block and remove-page-block. It also translates
 * between the AI's names and Elementor's: the widget settings follow the
 * conventions of mlsimport_mcp_elementor_settings() (see
 * mlsimport-mcp-standalone-pages-elementor.php), read back here; the style
 * options (mlsimport_page_block_style_options( $slug )) are the widget's
 * Style tab controls, written and read in the value shapes those controls
 * store. An option with no native control lives in the widget's hidden
 * mlsimport_styles setting (option => value), which the widget hands to the
 * dispatcher as the `styles` arg so the scoped CSS applies it, as on a
 * block editor page.
 *
 * Public interface:
 *   mlsimport_mcp_elementor_options( $slug, $settings )     widget settings -> options
 *   mlsimport_mcp_elementor_styles( $slug, $settings )      widget settings -> styles
 *   mlsimport_mcp_elementor_style_settings( $slug, $styles, $settings ) styles -> widget settings
 *   mlsimport_mcp_elementor_page_blocks( $page_id )         the plugin widgets of a page
 *   mlsimport_mcp_elementor_write( $page_id, $position, $edit ) change or remove one
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page block slug of an Elementor element, or '' for any other element.
 *
 * @param array $element One element of the document tree.
 * @return string The slug, for example item_list, or ''.
 */
function mlsimport_mcp_elementor_slug( array $element ): string {
	$type = (string) ( $element['widgetType'] ?? '' );
	if ( 'widget' !== ( $element['elType'] ?? '' ) || 0 !== strpos( $type, 'mlsimport-' ) ) {
		return '';
	}
	$slug = str_replace( '-', '_', substr( $type, strlen( 'mlsimport-' ) ) );
	return isset( mlsimport_get_page_blocks()[ $slug ] ) ? $slug : '';
}

/**
 * Walk the document tree, visiting the plugin's widgets in page order.
 *
 * Step by step:
 * 1. For a plugin widget, call $visit( &$element, $slug ). The visitor may
 *    change the element in place; when it returns false the widget is removed.
 * 2. Then walk the element's children. A container or column emptied by a
 *    removal is removed too, so an "add, then remove" leaves no blank box.
 *
 * @param array    $elements The elements, changed in place.
 * @param callable $visit    function ( array &$element, string $slug ): bool.
 * @return void
 */
function mlsimport_mcp_elementor_walk( array &$elements, callable $visit ): void {
	foreach ( array_keys( $elements ) as $index ) {
		$slug = mlsimport_mcp_elementor_slug( $elements[ $index ] );
		if ( '' !== $slug && false === $visit( $elements[ $index ], $slug ) ) {
			unset( $elements[ $index ] );
			continue;
		}
		if ( ! empty( $elements[ $index ]['elements'] ) ) {
			mlsimport_mcp_elementor_walk( $elements[ $index ]['elements'], $visit );
			if ( empty( $elements[ $index ]['elements'] ) ) {
				unset( $elements[ $index ] );
			}
		}
	}
	$elements = array_values( $elements );
}

/**
 * The block options of a widget, read back from its Elementor settings: the
 * reverse of mlsimport_mcp_elementor_settings().
 *
 * Step by step:
 * 1. Every option of the block whose control holds a value: a toggle reads
 *    as true/false, the rest as stored.
 * 2. For the search-bar widgets, the Order dropdown reads as orderby/order
 *    and the per-field switchers as the search_fields comma list ('none'
 *    when every field is off; absent when every field is on).
 *
 * @param string $slug     Page block slug.
 * @param array  $settings The widget's stored settings.
 * @return array Option name => value.
 */
function mlsimport_mcp_elementor_options( string $slug, array $settings ): array {
	$options = array();
	foreach ( (array) mlsimport_get_page_blocks()[ $slug ]['args'] as $key => $arg ) {
		$control = Mlsimport_Page_Block_Elementor::control_key( (string) $key );
		$value   = $settings[ $control ] ?? null;
		// A toggle that was set reads as on/off ('' is off, not unset).
		if ( 'toggle' === ( $arg['type'] ?? '' ) ) {
			if ( null !== $value ) {
				$options[ $key ] = 'yes' === $value;
			}
			continue;
		}
		if ( null === $value || '' === $value || array() === $value ) {
			continue;
		}
		$options[ $key ] = $value;
	}
	if ( ! in_array( $slug, MLSIMPORT_MCP_ELEMENTOR_SEARCH_BAR_BLOCKS, true ) ) {
		return $options;
	}

	unset( $options['orderby'], $options['order'], $options['search_fields'] );
	if ( ! empty( $settings['mlsimport_sort'] ) ) {
		$sort               = explode( '|', (string) $settings['mlsimport_sort'] );
		$options['orderby'] = $sort[0];
		$options['order']   = $sort[1] ?? 'desc';
	}
	$on  = array();
	$set = false;
	foreach ( array_keys( Mlsimport_Page_Block_Search_Fields::labels() ) as $field ) {
		if ( ! isset( $settings[ 'mlsimport_field_' . $field ] ) ) {
			continue;
		}
		$set = true;
		if ( 'yes' === $settings[ 'mlsimport_field_' . $field ] ) {
			$on[] = $field;
		}
	}
	if ( $set && count( $on ) < count( Mlsimport_Page_Block_Search_Fields::labels() ) ) {
		$options['search_fields'] = empty( $on ) ? 'none' : implode( ',', $on );
	}
	return $options;
}

/**
 * The style options of a widget, read from its Style tab controls.
 *
 * A color control holds the string; a slider { size }; a dimensions control
 * { top, ... } (the four corners are written equal, so top stands for all);
 * a typography group holds the size in <group>_font_size. An option with no
 * native control is read from the hidden mlsimport_styles setting.
 *
 * @param string $slug     Page block slug.
 * @param array  $settings The widget's stored settings.
 * @return array Style option => value, only the set ones.
 */
function mlsimport_mcp_elementor_styles( string $slug, array $settings ): array {
	$styles = array();
	$hidden = isset( $settings['mlsimport_styles'] ) && is_array( $settings['mlsimport_styles'] ) ? $settings['mlsimport_styles'] : array();
	foreach ( mlsimport_page_block_style_options( $slug ) as $key => $option ) {
		if ( '' === $option['elementor'] ) {
			$value = $hidden[ $key ] ?? null;
		} else {
			$control = $option['elementor'] . ( 'font' === $option['control'] ? '_font_size' : '' );
			$value   = $settings[ $control ] ?? null;
		}
		if ( is_array( $value ) ) {
			$value = $value['size'] ?? $value['top'] ?? null;
		}
		if ( null === $value || '' === $value ) {
			continue;
		}
		$styles[ $key ] = 'color' === $option['kind'] ? (string) $value : (int) $value;
	}
	return $styles;
}

/**
 * Lay style options over a widget's settings, in the controls' value shapes.
 *
 * A null value removes the control's value (the style goes back to the
 * theme's). A typography group also needs its switch set to 'custom' or
 * Elementor ignores the size. An option with no native control is written
 * to the hidden mlsimport_styles setting (removed from it when null).
 *
 * @param string $slug     Page block slug.
 * @param array  $styles   Style option => checked value, or null to remove.
 * @param array  $settings The widget's settings.
 * @return array The settings with the styles applied.
 */
function mlsimport_mcp_elementor_style_settings( string $slug, array $styles, array $settings ): array {
	$options = mlsimport_page_block_style_options( $slug );
	$hidden  = isset( $settings['mlsimport_styles'] ) && is_array( $settings['mlsimport_styles'] ) ? $settings['mlsimport_styles'] : array();
	foreach ( $styles as $key => $value ) {
		$control = $options[ $key ]['elementor'];
		$shape   = $options[ $key ]['control'];
		if ( '' === $control ) {
			unset( $hidden[ $key ] );
			if ( null !== $value ) {
				$hidden[ $key ] = $value;
			}
			$settings['mlsimport_styles'] = $hidden;
		} elseif ( 'font' === $shape ) {
			unset( $settings[ $control . '_typography' ], $settings[ $control . '_font_size' ] );
			if ( null !== $value ) {
				$settings[ $control . '_typography' ] = 'custom';
				$settings[ $control . '_font_size' ]  = array(
					'unit'  => 'px',
					'size'  => $value,
					'sizes' => array(),
				);
			}
		} elseif ( null === $value ) {
			unset( $settings[ $control ] );
		} elseif ( 'color' === $shape ) {
			$settings[ $control ] = $value;
		} elseif ( 'slider' === $shape ) {
			$settings[ $control ] = array(
				'unit'  => 'px',
				'size'  => $value,
				'sizes' => array(),
			);
		} else {
			$settings[ $control ] = array(
				'unit'     => 'px',
				'top'      => (string) $value,
				'right'    => (string) $value,
				'bottom'   => (string) $value,
				'left'     => (string) $value,
				'isLinked' => true,
			);
		}
	}
	return $settings;
}

/**
 * The plugin's page blocks of an Elementor page, in page order.
 *
 * @param int $page_id The page.
 * @return array List of [ slug, options, styles ].
 */
function mlsimport_mcp_elementor_page_blocks( int $page_id ): array {
	$rows     = array();
	$document = \Elementor\Plugin::instance()->documents->get( $page_id );
	$elements = $document ? (array) $document->get_elements_data() : array();
	mlsimport_mcp_elementor_walk(
		$elements,
		static function ( array &$element, string $slug ) use ( &$rows ): bool {
			$settings = (array) ( $element['settings'] ?? array() );
			$rows[]   = array(
				'slug'    => $slug,
				'options' => mlsimport_mcp_elementor_options( $slug, $settings ),
				'styles'  => mlsimport_mcp_elementor_styles( $slug, $settings ),
			);
			return true;
		}
	);
	return $rows;
}

/**
 * Change or remove the plugin widget at one position of an Elementor page.
 *
 * Step by step:
 * 1. Walk the document; the plugin widget at $position is handed to
 *    $edit( $element, $slug ), which returns the changed element, or null
 *    to remove it.
 * 2. Save through Elementor's document save with the elements only, so the
 *    page settings stay; Elementor drops its CSS file of the page, so the
 *    next visit rebuilds it with the new styles.
 *
 * @param int      $page_id  The page.
 * @param int      $position 1 is the first plugin block on the page.
 * @param callable $edit     function ( array $element, string $slug ): ?array.
 * @return string|WP_Error The Elementor edit link, or the failure.
 */
function mlsimport_mcp_elementor_write( int $page_id, int $position, callable $edit ) {
	$document = \Elementor\Plugin::instance()->documents->get( $page_id );
	$elements = $document ? (array) $document->get_elements_data() : array();
	$seen     = 0;
	mlsimport_mcp_elementor_walk(
		$elements,
		static function ( array &$element, string $slug ) use ( &$seen, $position, $edit ): bool {
			if ( ++$seen !== $position ) {
				return true;
			}
			$changed = $edit( $element, $slug );
			if ( null === $changed ) {
				return false;
			}
			$element = $changed;
			return true;
		}
	);
	$saved = $document && $document->save( array( 'elements' => $elements ) );
	if ( ! $saved ) {
		return new WP_Error( 'mlsimport_elementor_save_failed', __( 'Elementor did not accept the page document.', 'mlsimport' ) );
	}
	return (string) $document->get_edit_url();
}
