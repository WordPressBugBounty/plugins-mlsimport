<?php
/**
 * Standalone (theme_id 990) page-block Elementor adapter (generic).
 *
 * Registers one distinct Elementor widget per page block (panel parity with the
 * WPResidence widgets), all backed by one base class. The base reads its slug from
 * an overridden METHOD (not the constructor — Elementor rebuilds widgets with an
 * empty constructor, the ADR-0005 trap), builds its controls from the block's arg
 * schema, and delegates render to the one dispatcher. render_settings() holds the
 * testable core so it runs without Elementor loaded. See docs/adr/0007.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/page-block-registry.php';

/**
 * Registers and backs the generic Elementor page-block widgets.
 */
class Mlsimport_Page_Block_Elementor {

	/**
	 * The Elementor panel category every MLSImport widget lands in.
	 */
	const CATEGORY = 'mlsimport';

	/**
	 * Hook the Elementor widget registration. Inert when Elementor is absent.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widgets' ) );
		add_action( 'elementor/elements/categories_registered', array( __CLASS__, 'register_category' ) );
		add_action( 'elementor/editor/after_enqueue_styles', array( __CLASS__, 'enqueue_editor_styles' ) );
	}

	/**
	 * Our own panel category, so the widgets group together instead of scattering
	 * through Elementor's "General" tab (the WPResidence pack does the same).
	 *
	 * @param mixed $elements_manager Elementor\Elements_Manager.
	 * @return void
	 */
	public static function register_category( $elements_manager ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$properties = array(
			'title' => __( 'MLSImport Widgets', 'mlsimport' ),
			'icon'  => 'fa fa-home',
		);

		// Elementor's add_category() only appends, and its $categories list is a
		// private property, so the built-in "Basic"/"General" groups always come
		// first. Reorder the list via reflection to put MLSImport at the top of
		// the widget panel. Fall back to a normal (appended) registration if the
		// private property can't be reached on this Elementor version.
		try {
			$prop = new \ReflectionProperty( $elements_manager, 'categories' );
			$prop->setAccessible( true );
			$existing = $prop->getValue( $elements_manager );
			$existing = is_array( $existing ) ? $existing : array();
			unset( $existing[ self::CATEGORY ] );
			$prop->setValue( $elements_manager, array( self::CATEGORY => $properties ) + $existing );
		} catch ( \ReflectionException $e ) {
			$elements_manager->add_category( self::CATEGORY, $properties );
		}
	}

	/**
	 * Editor-only CSS that stamps the "MLSImport" badge on our widget tiles (the
	 * .mlsimport-note class every get_icon() prepends).
	 *
	 * @return void
	 */
	public static function enqueue_editor_styles(): void {
		wp_enqueue_style(
			'mlsimport-elementor-editor',
			MLSIMPORT_PLUGIN_URL . 'admin/css/mlsimport-elementor-editor.css',
			array(),
			MLSIMPORT_VERSION
		);
	}

	/**
	 * Register one widget per manifest entry with Elementor's widget manager.
	 *
	 * @param mixed $widgets_manager Elementor\Widgets_Manager.
	 * @return void
	 */
	public static function register_widgets( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		require_once __DIR__ . '/elementor/class-mlsimport-elementor-page-block-widget.php';
		// Guard against an unexpected manager shape before calling register().
		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}
		// One widget instance per manifest entry, handed to Elementor's registry.
		foreach ( mlsimport_elementor_page_block_widgets() as $widget ) {
			$widgets_manager->register( $widget );
		}
	}

	/**
	 * The label for a block slug (for the widget title).
	 *
	 * @param string $slug Block slug.
	 * @return string
	 */
	public static function label( string $slug ): string {
		$blocks = mlsimport_get_page_blocks();
		return isset( $blocks[ $slug ]['label'] ) ? (string) $blocks[ $slug ]['label'] : $slug;
	}

	/**
	 * The arg schema for a block slug (for control building).
	 *
	 * @param string $slug Block slug.
	 * @return array
	 */
	public static function schema( string $slug ): array {
		$blocks = mlsimport_get_page_blocks();
		return isset( $blocks[ $slug ]['args'] ) ? (array) $blocks[ $slug ]['args'] : array();
	}

	/**
	 * The Elementor control name for an arg-schema key.
	 *
	 * Every key is used as-is except `id`. Elementor keeps a widget's settings in a
	 * Backbone model, and Backbone treats the attribute `id` as the model's server
	 * id: once it holds any value (even '') the model is no longer "new", so on
	 * delete Backbone tries a server DELETE, finds no URL, throws
	 * 'A "url" property or function must be specified' and the widget stays on the
	 * page. So a schema `id` (Featured Property's Property ID) is exposed to
	 * Elementor as `property_id` — the same name the Property Section widget uses.
	 * The shortcode and Gutenberg surfaces keep `id`; only Elementor has this trap.
	 *
	 * @param string $key Arg-schema key.
	 * @return string Control name safe to register with Elementor.
	 */
	public static function control_key( string $key ): string {
		return 'id' === $key ? 'property_id' : $key;
	}

	/**
	 * Move a value saved under a schema key Elementor must not hold (see
	 * control_key()) to its control name, and drop the old key.
	 *
	 * Pages saved before the rename still carry `id` in their stored settings.
	 * Elementor never strips keys it has no control for, so without this the old
	 * `id` would load back into the editor model and the widget would still be
	 * undeletable. Called on the settings the editor receives, so the next save
	 * persists the new key.
	 *
	 * @param string $slug     Block slug.
	 * @param array  $settings Stored Elementor settings.
	 * @return array Settings with every renamed key migrated.
	 */
	public static function migrate_settings( string $slug, array $settings ): array {
		// Step 1: walk the schema; only a key whose control name differs needs moving.
		foreach ( array_keys( self::schema( $slug ) ) as $key ) {
			$control = self::control_key( $key );
			if ( $control === $key || ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			// Step 2: carry the old value across unless the new key already has one.
			if ( ! isset( $settings[ $control ] ) || '' === $settings[ $control ] ) {
				$settings[ $control ] = $settings[ $key ];
			}
			// Step 3: drop the old key so it never reaches the editor's Backbone model.
			unset( $settings[ $key ] );
		}
		return $settings;
	}

	/**
	 * Render from a slug + an Elementor settings array (the widget's
	 * get_settings_for_display()). Pure delegation to the dispatcher — the
	 * testable core of every page-block widget.
	 *
	 * @param string $slug     Block slug.
	 * @param array  $settings Elementor control values.
	 * @return string
	 */
	public static function render_settings( string $slug, array $settings ): string {
		// A page saved before the `id` → `property_id` rename still stores the old key;
		// fold it in first so the front end keeps showing the chosen property.
		$settings = self::migrate_settings( $slug, $settings );
		$args     = array();
		// Pull only the schema's known keys out of the widget's control values, so
		// stray Elementor settings never reach the dispatcher.
		foreach ( self::schema( $slug ) as $key => $field ) {
			// The control may be registered under a different name than the schema key.
			$control = self::control_key( $key );
			if ( ! isset( $settings[ $control ] ) ) {
				continue;
			}
			$value = $settings[ $control ];
			// A MEDIA control hands back { url, id }; every render fn expects the plain
			// URL string the Gutenberg media picker stores, so flatten it here — the one
			// place the two builders' value shapes have to be reconciled.
			if ( is_array( $value ) && isset( $value['url'] ) ) {
				$value = (string) $value['url'];
			}
			$args[ $key ] = $value;
		}
		return mlsimport_render_page_block( $slug, $args );
	}
}
