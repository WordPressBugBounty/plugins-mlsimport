<?php
/**
 * Standalone (theme_id 990) single-property section Elementor widget (adapter).
 *
 * One generic Elementor widget, "MLSImport Property Section", with a Section
 * picker control (options from the manifest) + a property-id control. Its render
 * delegates to the one dispatcher, so Elementor emits the same markup as the
 * shortcode and the block.
 *
 * Why a picker rather than one widget per manifest entry: Elementor rebuilds a
 * widget from its registered prototype's CLASS with empty constructor args, so a
 * slug baked in via the constructor is lost at render time. A single widget with
 * a Section control is the robust, no-eval equivalent. The render logic lives in
 * render_settings() so it is testable without Elementor loaded. See docs/adr/0005
 * (controls are behavioral-only; styling is our own CSS).
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/property-section-registry.php';

/**
 * Registers and backs the generic Elementor property-section widget.
 */
class Mlsimport_Property_Section_Elementor {

	/**
	 * Hook the Elementor widget registration. Inert when Elementor is absent.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Register the generic widget with Elementor's widget manager.
	 *
	 * @param mixed $widgets_manager Elementor\Widgets_Manager.
	 * @return void
	 */
	public static function register_widget( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		mlsimport_register_builtin_property_sections();
		require_once __DIR__ . '/elementor/class-mlsimport-elementor-property-section-widget.php';
		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( new Mlsimport_Elementor_Property_Section_Widget() );
		}
	}

	/**
	 * Section picker options for the widget control: slug => label.
	 *
	 * @return array
	 */
	public static function section_options(): array {
		mlsimport_register_builtin_property_sections();
		$options = array();
		foreach ( mlsimport_get_property_sections() as $slug => $def ) {
			$options[ $slug ] = $def['label'];
		}
		return $options;
	}

	/**
	 * Render from a settings array (the widget's get_settings_for_display()).
	 * Pure delegation to the dispatcher — the testable core of the widget.
	 *
	 * @param array $settings Elementor control values.
	 * @return string
	 */
	public static function render_settings( array $settings ): string {
		$slug = isset( $settings['section'] ) ? (string) $settings['section'] : '';
		if ( '' === $slug ) {
			return '';
		}
		$id = isset( $settings['property_id'] ) ? (int) $settings['property_id'] : 0;
		return mlsimport_render_property_section( $slug, $id );
	}
}
