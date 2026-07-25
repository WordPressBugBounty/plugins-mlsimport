<?php
/**
 * Customizer control for the "arrange sections" fields (the sections type):
 * property_sections, agent_sections, overview_fields, archive_search_fields.
 *
 * Renders two lists — Enabled and Disabled — the user reorders (up/down) and moves
 * items between. The value is the same {active:[...],inactive:[...]} object the
 * settings page and front end use, so the arrangers round-trip through the one
 * option. Defined lazily (on customize_register) because WP_Customize_Control is
 * only loaded in the Customizer.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Customize_Control' ) ) {
	return;
}

/**
 * A reorder + enable/disable list control backed by a sections-type setting.
 */
class Mlsimport_Customize_Sections_Control extends WP_Customize_Control {

	/**
	 * The JS control type — matches the wp.customize.controlConstructor key.
	 *
	 * @var string
	 */
	public $type = 'mlsimport_sections';

	/**
	 * The slug => label catalog of every choice this control offers.
	 *
	 * @var array<string,string>
	 */
	public $catalog = array();

	/**
	 * Hand the catalog and the current value to the JS control.
	 *
	 * @return void
	 */
	public function to_json() {
		// Let the base control export label/description/etc first.
		parent::to_json();

		// Flatten the slug => label catalog into an ordered [{slug,label}] list for JS.
		$catalog = array();
		foreach ( $this->catalog as $slug => $label ) {
			$catalog[] = array( 'slug' => $slug, 'label' => $label );
		}
		$this->json['catalog'] = $catalog;

		// Current setting value; decode a JSON string into an array.
		$value = $this->value();
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}
		// Normalise to the {active:[...],inactive:[...]} shape the JS control expects.
		$this->json['mlsValue'] = array(
			'active'   => isset( $value['active'] ) ? array_values( (array) $value['active'] ) : array(),
			'inactive' => isset( $value['inactive'] ) ? array_values( (array) $value['inactive'] ) : array(),
		);
	}

	/**
	 * The content is drawn by the JS Underscore template; nothing to render here.
	 *
	 * @return void
	 */
	public function render_content() {}

	/**
	 * The Underscore template for the control. Two labelled lists plus per-item
	 * move/reorder buttons; the JS keeps a hidden input in sync with the setting.
	 *
	 * @return void
	 */
	public function content_template() {
		?>
		<# if ( data.label ) { #><span class="customize-control-title">{{ data.label }}</span><# } #>
		<# if ( data.description ) { #><span class="description customize-control-description">{{{ data.description }}}</span><# } #>
		<div class="mlsimport-arranger">
			<p class="mlsimport-arranger__heading"><?php esc_html_e( 'Enabled', 'mlsimport' ); ?></p>
			<ul class="mlsimport-arranger__list mlsimport-arranger__active"></ul>
			<p class="mlsimport-arranger__heading"><?php esc_html_e( 'Disabled', 'mlsimport' ); ?></p>
			<ul class="mlsimport-arranger__list mlsimport-arranger__inactive"></ul>
		</div>
		<?php
	}
}
