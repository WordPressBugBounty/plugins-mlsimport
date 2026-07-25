<?php
/**
 * Elementor widget: MLSImport Property Section (generic).
 *
 * Loaded only when Elementor is active (extends \Elementor\Widget_Base). A
 * Section picker + property-id control; rendering delegates to the dispatcher
 * via Mlsimport_Property_Section_Elementor::render_settings().
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Generic single-property section widget.
 */
class Mlsimport_Elementor_Property_Section_Widget extends \Elementor\Widget_Base {

	/**
	 * Widget machine name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'mlsimport-property-section';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'MLSImport Property Section', 'mlsimport' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'mlsimport-note eicon-single-page';
	}

	/**
	 * Editor panel categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( Mlsimport_Page_Block_Elementor::CATEGORY );
	}

	/**
	 * Hidden from the widget panel. Property sections are internals of the single
	 * template, not something a user drops on a page — the same call the Gutenberg
	 * side makes by not registering the property-* blocks at all. It stays
	 * registered (not skipped) so any page that already placed one keeps rendering.
	 *
	 * @return bool
	 */
	public function show_in_panel() {
		return false;
	}

	/**
	 * Behavioral controls: which section, which property.
	 *
	 * @return void
	 */
	protected function register_controls() {
		// Open the widget's single controls section.
		$this->start_controls_section(
			'mlsimport_section',
			array( 'label' => __( 'Property Section', 'mlsimport' ) )
		);

		// Section picker: which property section this widget renders (options come
		// from the section registry; defaults to the price section).
		$this->add_control(
			'section',
			array(
				'label'   => __( 'Section', 'mlsimport' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => Mlsimport_Property_Section_Elementor::section_options(),
				'default' => 'price',
			)
		);

		// Optional explicit property ID; 0 means render the current property.
		$this->add_control(
			'property_id',
			array(
				'label'       => __( 'Property ID (optional)', 'mlsimport' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'description' => __( 'Leave 0 to use the current property.', 'mlsimport' ),
			)
		);

		// Close the controls section.
		$this->end_controls_section();
	}

	/**
	 * Render via the shared dispatcher.
	 *
	 * @return void
	 */
	protected function render() {
		echo Mlsimport_Property_Section_Elementor::render_settings( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- section render fns escape at source.
	}
}
