<?php
/**
 * AI tools (MCP), Elementor sites: the settings an Elementor element takes.
 *
 * Elementor describes every element type (container, section, column, each
 * widget) by its controls: the panel's settings, with a type (color, slider,
 * dimensions, select ...), a tab (content, style, advanced) and, for a
 * responsive one, a value per device stored under the control name with a
 * device suffix (padding, padding_tablet, padding_mobile). The AI writes
 * element settings in exactly these names, so this file lists them
 * (list-elementor-controls) and checks the names the write tools are sent.
 *
 * Elementor's own detail handled here and nowhere else: outside its editor
 * Elementor keeps the Style and Advanced tab controls in a separate store
 * and get_controls() merges them only while
 * Performance::set_use_style_controls( true ) is on (what Elementor's CSS
 * writer does). The device variants are registered only when responsive
 * control duplication is on, so a name is also accepted as a responsive
 * control plus an active device suffix.
 *
 * Public interface:
 *   mlsimport_mcp_elementor_controls_tools()              the tools of this file
 *   mlsimport_mcp_elementor_stack( $element )             the element type, or the refusal
 *   mlsimport_mcp_elementor_devices()                     the non-desktop devices
 *   mlsimport_mcp_elementor_control_rows( $stack )        the settings, one row each
 *   mlsimport_mcp_elementor_settings_check( $stack, $settings )  refuse unknown names
 *   mlsimport_mcp_list_elementor_controls( $input )       tool: list-elementor-controls
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Control types that are panel furniture, not settings. */
const MLSIMPORT_MCP_ELEMENTOR_UI_CONTROLS = array( 'section', 'tab', 'tabs', 'heading', 'divider', 'raw_html', 'notice', 'alert', 'deprecated_notice', 'button' );

/**
 * The AI tools of this file, as the tool list expects them.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_elementor_controls_tools(): array {
	return array(
		'list-elementor-controls' => array(
			'label'       => 'Settings of an Elementor element type',
			'description' => 'Lists the settings an Elementor element type takes (its controls, Style and Advanced tabs included): the name to use in add-elementor-element and update-elementor-element, its type, its tab, its choices and whether it is responsive. A responsive setting takes a value per device: the name alone is desktop, the name plus _tablet or _mobile the other devices. element is container, section, column or a widget name such as heading, text-editor, image, button, spacer or mlsimport-search-form.',
			'input'       => array(
				'element' => array(
					'type'        => 'string',
					'description' => 'container, section, column, or a widget name (heading, text-editor, image, button, spacer, mlsimport-search-form ...).',
				),
				'search'  => array(
					'type'        => 'string',
					'description' => 'Only the settings whose name or label contains this text.',
				),
			),
			'required'    => array( 'element' ),
			'callback'    => 'mlsimport_mcp_list_elementor_controls',
			'changes'     => false,
		),
	);
}

/**
 * The Elementor element type (container, section, column or a widget) named
 * by the AI, or the refusal naming the types that exist.
 *
 * @param string $element The element name as sent.
 * @return \Elementor\Element_Base|WP_Error
 */
function mlsimport_mcp_elementor_stack( string $element ) {
	$elementor = \Elementor\Plugin::instance();
	$element   = sanitize_key( $element );
	$stack     = $elementor->elements_manager->get_element_types( $element );
	if ( ! $stack ) {
		$stack = $elementor->widgets_manager->get_widget_types( $element );
	}
	if ( $stack ) {
		return $stack;
	}

	// The refusal names the structural types, the core widgets a hero needs
	// and the plugin's own widgets.
	$names = array( 'container', 'section', 'column', 'heading', 'text-editor', 'image', 'button', 'spacer' );
	foreach ( array_keys( (array) $elementor->widgets_manager->get_widget_types() ) as $widget ) {
		if ( 0 === strpos( (string) $widget, 'mlsimport-' ) ) {
			$names[] = (string) $widget;
		}
	}
	return new WP_Error(
		'mlsimport_elementor_element_unknown',
		/* translators: 1: the name sent, 2: the element types that exist. */
		sprintf( __( '"%1$s" is not an Elementor element type. Use one of: %2$s, or any other registered widget name.', 'mlsimport' ), $element, implode( ', ', $names ) )
	);
}

/**
 * The active devices other than desktop (tablet, mobile ...), the suffixes a
 * responsive setting takes.
 *
 * @return string[]
 */
function mlsimport_mcp_elementor_devices(): array {
	$devices = (array) \Elementor\Plugin::instance()->breakpoints->get_active_devices_list( array( 'reverse' => true ) );
	return array_values( array_diff( $devices, array( 'desktop' ) ) );
}

/**
 * Every control of an element type, Style and Advanced tabs included.
 *
 * @param \Elementor\Element_Base $stack The element type.
 * @return array Control name => control args, as Elementor registers them.
 */
function mlsimport_mcp_elementor_all_controls( $stack ): array {
	\Elementor\Core\Frontend\Performance::set_use_style_controls( true );
	$controls = (array) $stack->get_controls();
	\Elementor\Core\Frontend\Performance::set_use_style_controls( false );
	return $controls;
}

/**
 * The settings of an element type, one row each, for the AI.
 *
 * Step by step:
 * 1. Read every control (mlsimport_mcp_elementor_all_controls()).
 * 2. Leave out the panel furniture (sections, headings, dividers ...) and
 *    the device variants of a responsive control: the base row says it is
 *    responsive and the device suffixes are the same for every control.
 * 3. One row: name, type, label, tab, section, responsive, the choices of a
 *    select or choose control, and the default when it has one.
 *
 * @param \Elementor\Element_Base $stack The element type.
 * @return array List of rows, in panel order.
 */
function mlsimport_mcp_elementor_control_rows( $stack ): array {
	$controls = mlsimport_mcp_elementor_all_controls( $stack );
	$devices  = mlsimport_mcp_elementor_devices();
	$rows     = array();
	foreach ( $controls as $name => $control ) {
		$name = (string) $name;
		$type = (string) ( $control['type'] ?? '' );
		if ( in_array( $type, MLSIMPORT_MCP_ELEMENTOR_UI_CONTROLS, true ) ) {
			continue;
		}
		foreach ( $devices as $device ) {
			$suffix = '_' . $device;
			if ( substr( $name, -strlen( $suffix ) ) === $suffix && isset( $controls[ substr( $name, 0, -strlen( $suffix ) ) ] ) ) {
				continue 2;
			}
		}
		$row = array(
			'name'       => $name,
			'type'       => $type,
			'label'      => wp_strip_all_tags( (string) ( $control['label'] ?? '' ) ),
			'tab'        => (string) ( $control['tab'] ?? '' ),
			'section'    => (string) ( $control['section'] ?? '' ),
			'responsive' => isset( $control['responsive'] ),
		);
		if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
			$row['options'] = array();
			foreach ( $control['options'] as $value => $option ) {
				$row['options'][ $value ] = wp_strip_all_tags( (string) ( is_array( $option ) ? ( $option['title'] ?? $value ) : $option ) );
			}
		}
		if ( isset( $control['default'] ) && '' !== $control['default'] && array() !== $control['default'] ) {
			$row['default'] = $control['default'];
		}
		$rows[] = $row;
	}
	return $rows;
}

/**
 * Refuse settings whose names are not controls of the element type.
 *
 * A name is known when it is a control, a responsive control plus an active
 * device suffix, or one of Elementor's own bookkeeping keys (__dynamic__,
 * __globals__), which start with two underscores.
 *
 * @param \Elementor\Element_Base $stack    The element type.
 * @param array                   $settings Setting name => value, as sent.
 * @return true|WP_Error True, or the refusal naming the unknown settings.
 */
function mlsimport_mcp_elementor_settings_check( $stack, array $settings ) {
	$controls = mlsimport_mcp_elementor_all_controls( $stack );
	$devices  = mlsimport_mcp_elementor_devices();
	$unknown  = array();
	foreach ( array_keys( $settings ) as $name ) {
		$name = (string) $name;
		if ( isset( $controls[ $name ] ) || 0 === strpos( $name, '__' ) ) {
			continue;
		}
		$known = false;
		foreach ( $devices as $device ) {
			$base  = preg_replace( '/_' . preg_quote( $device, '/' ) . '$/', '', $name );
			$known = $known || ( $base !== $name && isset( $controls[ $base ]['responsive'] ) );
		}
		if ( ! $known ) {
			$unknown[] = sanitize_text_field( $name );
		}
	}
	if ( empty( $unknown ) ) {
		return true;
	}
	return new WP_Error(
		'mlsimport_elementor_setting_unknown',
		/* translators: 1: the element type, 2: the unknown settings. */
		sprintf( __( 'A %1$s has no setting %2$s. list-elementor-controls names its settings.', 'mlsimport' ), $stack->get_name(), implode( ', ', $unknown ) )
	);
}

/**
 * Tool list-elementor-controls: the settings of an element type.
 *
 * @param array $input The tool input: element (container, section, column or a widget name), search.
 * @return array|WP_Error The element, its settings and the device suffixes, or the refusal.
 */
function mlsimport_mcp_list_elementor_controls( array $input ) {
	$stack = mlsimport_mcp_elementor_stack( (string) ( $input['element'] ?? '' ) );
	if ( is_wp_error( $stack ) ) {
		return $stack;
	}
	$search = strtolower( trim( (string) ( $input['search'] ?? '' ) ) );
	$rows   = mlsimport_mcp_elementor_control_rows( $stack );
	if ( '' !== $search ) {
		$rows = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $search ): bool {
					return false !== strpos( strtolower( $row['name'] . ' ' . $row['label'] ), $search );
				}
			)
		);
	}
	return array(
		'element'  => $stack->get_name(),
		'devices'  => mlsimport_mcp_elementor_devices(),
		'controls' => $rows,
		'note'     => __( 'A responsive setting takes one value per device: the name alone for desktop, the name plus _tablet or _mobile for the others.', 'mlsimport' ),
	);
}
