<?php
/**
 * Standalone design settings in the WordPress Customizer (theme_id 990).
 *
 * A second editor over the SAME mlsimport_standalone_options as the React
 * settings page — Appearance → Customize → "MLSImport Design". The whole panel is
 * GENERATED from mlsimport_standalone_settings_schema(); there is no per-field
 * wiring here, so a field added to the registry appears in the Customizer
 * automatically. Colour previews live (postMessage); everything else reloads the
 * preview (refresh). See specs/customizer-standalone-design-settings.md.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-settings.php';

const MLSIMPORT_CUSTOMIZER_PANEL = 'mlsimport_design';

/**
 * Register the MLSImport Design panel, one section per schema tab and one control
 * per field. Standalone mode only — in integrated theme mode the active theme
 * owns the front-end look and there is nothing for us to expose.
 *
 * @param WP_Customize_Manager $wp_customize The Customizer manager.
 * @return void
 */
function mlsimport_customizer_register( $wp_customize ): void {
	// Standalone (990) only — in theme mode the active theme owns the look.
	if ( ! function_exists( 'mlsimport_is_standalone_mode' ) || ! mlsimport_is_standalone_mode() ) {
		return;
	}

	// WP_Customize_Control is only loaded now, so the custom control is defined lazily.
	require_once __DIR__ . '/class-mlsimport-customize-sections-control.php';

	// The arranger draws itself from a JS Underscore template (render_content() is
	// empty). Registering the control TYPE makes WordPress print that template into
	// the footer, so the control renders in ANY section — not only whichever one
	// happens to be expanded on load. Without this the Arrange Sections / Arrange
	// Fields controls come up blank.
	if ( class_exists( 'Mlsimport_Customize_Sections_Control' ) ) {
		$wp_customize->register_control_type( 'Mlsimport_Customize_Sections_Control' );
	}

	// The root "MLSImport Design" panel that holds the schema-driven sections.
	$wp_customize->add_panel(
		MLSIMPORT_CUSTOMIZER_PANEL,
		array(
			'title'       => __( 'MLSImport Design', 'mlsimport' ),
			'description' => __( 'Appearance of your listings, cards, search, maps, property and agent pages. These are the same settings as the MLS Import Design Settings screen.', 'mlsimport' ),
			'priority'    => 130,
		)
	);

	// A field's Property Page sub-tab (pp_general, pp_layout, …) lives in the
	// settings-page presentation map. A section whose fields carry sub-tabs (the
	// Property Page) becomes its OWN panel with one section per sub-tab, so the
	// Customizer drills down — click "Property Page" → its sub-tabs → a sub-tab's
	// settings, with the back arrow — exactly like the settings page's nested menu.
	// (The Customizer can't nest a panel inside a panel, so the Property Page panel
	// sits at the root next to "MLSImport Design"; a flat "one section per sub-tab"
	// would instead dump every field into one screen.)
	// Presentation map (per-field sub-tab) and the sub-tab id => title list.
	$ui      = function_exists( 'mlsimport_standalone_settings_ui' ) ? mlsimport_standalone_settings_ui() : array();
	$subtabs = function_exists( 'mlsimport_standalone_settings_subtabs' ) ? mlsimport_standalone_settings_subtabs() : array();

	// Helper: add one Customizer section under $panel and its per-field controls.
	$add_section = function ( $section_id, $title, $fields, $panel ) use ( $wp_customize ) {
		$wp_customize->add_section(
			$section_id,
			array(
				'title' => $title,
				'panel' => $panel,
			)
		);
		// One control per field in the section.
		foreach ( $fields as $field ) {
			mlsimport_customizer_add_field( $wp_customize, $section_id, $field );
		}
	};

	$priority = 131; // Property Page panel sits right after "MLSImport Design".
	// Walk the schema, turning each section into a Customizer section or drill-down panel.
	foreach ( mlsimport_standalone_settings_schema() as $section ) {
		// Bucket the section's fields by sub-tab (empty string = no sub-tab).
		$by_subtab   = array();
		$has_subtabs = false;
		foreach ( $section['fields'] as $field ) {
			// This field's sub-tab id from the presentation map ('' when none).
			$sub = isset( $ui[ $field['key'] ]['subtab'] ) ? (string) $ui[ $field['key'] ]['subtab'] : '';
			// Any sub-tab present flips this section into drill-down mode.
			if ( '' !== $sub ) {
				$has_subtabs = true;
			}
			$by_subtab[ $sub ][] = $field;
		}

		// Flat section: no sub-tabs, so all fields live in one Customizer section.
		if ( ! $has_subtabs ) {
			$add_section( MLSIMPORT_CUSTOMIZER_PANEL . '_' . $section['id'], $section['title'], $section['fields'], MLSIMPORT_CUSTOMIZER_PANEL );
			continue;
		}

		// This section drills down: its own panel, one section per sub-tab.
		$sub_panel = MLSIMPORT_CUSTOMIZER_PANEL . '_' . $section['id'];
		$wp_customize->add_panel(
			$sub_panel,
			array(
				'title'    => $section['title'],
				'priority' => $priority++,
			)
		);
		// One section per sub-tab that actually has fields, in sub-tab order.
		foreach ( $subtabs as $sub_id => $sub_title ) {
			// Skip sub-tabs with no fields in this section.
			if ( empty( $by_subtab[ $sub_id ] ) ) {
				continue;
			}
			$add_section( $sub_panel . '_' . $sub_id, $sub_title, $by_subtab[ $sub_id ], $sub_panel );
		}
		// Any field without a sub-tab still gets a home inside the sub-panel.
		if ( ! empty( $by_subtab[''] ) ) {
			$add_section( $sub_panel . '_misc', $section['title'], $by_subtab[''], $sub_panel );
		}
	}
}

/**
 * Register the setting + control for one schema field. The setting is bound to the
 * multidimensional option key mlsimport_standalone_options[<key>], so the
 * Customizer writes the very same option the settings page and front end read —
 * one source of truth, no divergence.
 *
 * @param WP_Customize_Manager $wp_customize The Customizer manager.
 * @param string               $section_id   The Customizer section id.
 * @param array                $field        A schema field.
 * @return void
 */
function mlsimport_customizer_add_field( $wp_customize, string $section_id, array $field ): void {
	// Bind the setting to the multidimensional option key options[<key>].
	$key        = $field['key'];
	$setting_id = MLSIMPORT_STANDALONE_OPTION . '[' . $key . ']';
	$defaults   = mlsimport_standalone_option_defaults();

	// Register the setting: option storage, default, cap, transport, per-field sanitizer.
	$wp_customize->add_setting(
		$setting_id,
		array(
			'type'              => 'option',
			'default'           => isset( $defaults[ $key ] ) ? $defaults[ $key ] : '',
			'capability'        => 'manage_options',
			// Colours preview live; everything else refreshes the preview.
			'transport'         => 'postMessage' === $field['transport'] ? 'postMessage' : 'refresh',
			'sanitize_callback' => function ( $value ) use ( $key ) {
				// Reuse the exact save-path sanitizer, per field.
				$clean = mlsimport_sanitize_standalone_options( array( $key => $value ) );
				return $clean[ $key ];
			},
		)
	);

	// Shared control args; each type below adds its own specifics.
	$control_args = array(
		'label'   => $field['label'],
		'section' => $section_id,
		'settings' => $setting_id,
	);

	// Pick the control class/type from the field's declared type.
	switch ( $field['type'] ) {
		case 'color':
			// Native colour picker.
			$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $setting_id, $control_args ) );
			break;

		case 'int': // mls_logo_id — an attachment id chosen through the media modal.
			$wp_customize->add_control(
				new WP_Customize_Media_Control(
					$wp_customize,
					$setting_id,
					$control_args + array( 'mime_type' => 'image' )
				)
			);
			break;

		case 'select':
			// Dropdown built from the field's options map.
			$wp_customize->add_control(
				$setting_id,
				$control_args + array(
					'type'    => 'select',
					'choices' => is_array( $field['options'] ) ? $field['options'] : array(),
				)
			);
			break;

		case 'textarea':
		case 'html':
			// Both render as a plain textarea control.
			$wp_customize->add_control( $setting_id, $control_args + array( 'type' => 'textarea' ) );
			break;

		case 'number':
			// Numeric input.
			$wp_customize->add_control( $setting_id, $control_args + array( 'type' => 'number' ) );
			break;

		case 'email':
			// Email input.
			$wp_customize->add_control( $setting_id, $control_args + array( 'type' => 'email' ) );
			break;

		case 'sections':
			// Resolve the choice catalog from the field's callable, if any.
			$catalog = array();
			if ( ! empty( $field['catalog'] ) && is_callable( $field['catalog'] ) ) {
				$catalog = (array) call_user_func( $field['catalog'] );
			}
			// Custom reorder/enable-disable arranger control. It also learns which
			// slugs the field's default keeps disabled, so a catalog entry the saved
			// value never mentions is shown where the sanitizer will put it on save.
			$wp_customize->add_control(
				new Mlsimport_Customize_Sections_Control(
					$wp_customize,
					$setting_id,
					$control_args + array(
						'catalog'          => $catalog,
						'default_inactive' => isset( $field['default']['inactive'] ) ? array_values( (array) $field['default']['inactive'] ) : array(),
					)
				)
			);
			break;

		default: // text.
			// Fallback single-line text input.
			$wp_customize->add_control( $setting_id, $control_args + array( 'type' => 'text' ) );
			break;
	}
}

/**
 * Enqueue the custom "arrange sections" control JS + CSS in the Customizer
 * controls pane (the left panel, not the preview). Standalone mode only.
 *
 * @return void
 */
function mlsimport_customizer_enqueue_controls(): void {
	// Standalone (990) only.
	if ( ! function_exists( 'mlsimport_is_standalone_mode' ) || ! mlsimport_is_standalone_mode() ) {
		return;
	}
	// Cache-busting version and plugin base URL.
	$ver = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : '1';
	$url = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );

	// Controls-pane script + stylesheet for the arranger control.
	wp_enqueue_script(
		'mlsimport-customizer-controls',
		$url . 'admin/js/mlsimport-customizer-controls.js',
		array( 'jquery', 'customize-controls', 'wp-api-fetch' ),
		$ver,
		true
	);
	wp_enqueue_style(
		'mlsimport-customizer-controls',
		$url . 'admin/css/mlsimport-customizer-controls.css',
		array(),
		$ver
	);

	// Land the preview on the listings archive so the accent colour and card style
	// are visible the moment the panel opens (see the preview-landing controls JS).
	// Pass the listings archive URL to JS so the preview lands there on open.
	$archive = function_exists( 'get_post_type_archive_link' ) ? get_post_type_archive_link( 'mlsimport_property' ) : '';
	wp_add_inline_script(
		'mlsimport-customizer-controls',
		'window.mlsimportCustomizer = ' . wp_json_encode( array( 'archiveUrl' => $archive ? $archive : '' ) ) . ';',
		'before'
	);
}

/**
 * Enqueue the accent live-preview script INSIDE the preview iframe. Standalone
 * mode only.
 *
 * @return void
 */
function mlsimport_customizer_enqueue_preview(): void {
	// Standalone (990) only.
	if ( ! function_exists( 'mlsimport_is_standalone_mode' ) || ! mlsimport_is_standalone_mode() ) {
		return;
	}
	// Cache-busting version and plugin base URL.
	$ver = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : '1';
	$url = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );

	// Accent live-preview script, loaded inside the preview iframe.
	wp_enqueue_script(
		'mlsimport-customizer-preview',
		$url . 'admin/js/mlsimport-customizer-preview.js',
		array( 'customize-preview' ),
		$ver,
		true
	);
}

// Wire the panel registration and the two enqueue passes into the Customizer.
if ( function_exists( 'add_action' ) ) {
	add_action( 'customize_register', 'mlsimport_customizer_register' );
	add_action( 'customize_controls_enqueue_scripts', 'mlsimport_customizer_enqueue_controls' );
	add_action( 'customize_preview_init', 'mlsimport_customizer_enqueue_preview' );
}
