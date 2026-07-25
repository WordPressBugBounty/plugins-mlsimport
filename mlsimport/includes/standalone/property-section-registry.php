<?php
/**
 * Standalone (theme_id 990) single-property section manifest + dispatcher.
 *
 * One registry maps a section slug to its metadata (label, render callback,
 * accepted args, required front-end assets). Every page-builder adapter
 * (Shortcode, Gutenberg, Elementor, future builders) loops this manifest, so
 * adding a section is one register call + one render fn and all builders pick
 * it up. The dispatcher is the single place that resolves the render fn,
 * resolves the property and (later) enqueues the section's assets. See
 * docs/adr/0005.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/property-sections.php';
require_once __DIR__ . '/class-mlsimport-property-section-assets.php';
require_once __DIR__ . '/class-mlsimport-property-lead.php';

/**
 * Register a single-property section in the manifest.
 *
 * @param string $slug Section slug (e.g. 'price').
 * @param array  $def  {
 *     @type string   $label  Editor-facing label.
 *     @type callable $render Render fn: ( int $id, array $args ) => string.
 *     @type array    $args   Optional accepted arg keys (behavioral).
 *     @type array    $assets Optional asset handles to enqueue when rendered.
 * }
 * @return void
 */
function mlsimport_register_property_section( string $slug, array $def ): void {
	$registry =& mlsimport_property_section_registry();

	$registry[ $slug ] = array(
		'label'  => isset( $def['label'] ) ? (string) $def['label'] : $slug,
		'render' => isset( $def['render'] ) ? $def['render'] : '',
		'args'   => isset( $def['args'] ) ? (array) $def['args'] : array(),
		'assets' => isset( $def['assets'] ) ? (array) $def['assets'] : array(),
	);
}

/**
 * The registered sections, slug => definition.
 *
 * @return array
 */
function mlsimport_get_property_sections(): array {
	/** Filter the section manifest: add/remove/reorder sections globally. @since 6.3 */
	return (array) apply_filters( 'mlsimport_property_sections', mlsimport_property_section_registry() );
}

/**
 * Render a registered section by slug (the one dispatch point for all builders).
 *
 * @param string $slug Section slug.
 * @param int    $id   Property post ID (0 = current loop post).
 * @param array  $args Behavioral args.
 * @return string HTML, or '' for an unknown slug / uncallable render fn.
 */
function mlsimport_render_property_section( string $slug, int $id = 0, array $args = array() ): string {
	$registry = mlsimport_property_section_registry();
	if ( ! isset( $registry[ $slug ] ) || ! is_callable( $registry[ $slug ]['render'] ) ) {
		return '';
	}

	// Manifest args are per-section defaults (e.g. a slider variant); caller args win.
	$args = array_merge( $registry[ $slug ]['args'], $args );
	/** Filter the resolved section args before render. @since 6.3 */
	$args = (array) apply_filters( 'mlsimport_section_args', $args, $slug, $id );

	// Action slot before the section. Echoed output is buffered so the
	// function still returns one complete string (the return-string contract).
	ob_start();
	/** Inject markup before any section; $slug identifies which. @since 6.3 */
	do_action( 'mlsimport_before_section', $slug, $id, $args );
	/** Inject markup before a section. Dynamic by slug. @since 6.3 */
	do_action( "mlsimport_before_section_{$slug}", $id, $args );
	$before = (string) ob_get_clean();

	$html = (string) call_user_func( $registry[ $slug ]['render'], $id, $args );

	// Data-driven: a section's assets load only when it actually renders output.
	// Gated on the rendered markup, before filters/action slots transform it.
	if ( '' !== $html ) {
		Mlsimport_Property_Section_Assets::enqueue( $registry[ $slug ]['assets'] );
	}

	/** Filter a single section's markup. Dynamic by slug. @since 6.3 */
	$html = apply_filters( "mlsimport_section_{$slug}_html", $html, $id, $args );
	/** Filter any section's markup; $slug identifies which. @since 6.3 */
	$html = apply_filters( 'mlsimport_section_html', $html, $slug, $id, $args );

	// Action slot after the section, buffered to preserve the return contract.
	ob_start();
	/** Inject markup after a section. Dynamic by slug. @since 6.3 */
	do_action( "mlsimport_after_section_{$slug}", $id, $args );
	/** Inject markup after any section; $slug identifies which. @since 6.3 */
	do_action( 'mlsimport_after_section', $slug, $id, $args );
	$after = (string) ob_get_clean();

	return $before . $html . $after;
}

/**
 * Register the plugin's built-in sections. Idempotent.
 *
 * @return void
 */
function mlsimport_register_builtin_property_sections(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	// slug => [ label, render fn, default args, asset handles ].
	$lightbox = array( 'mlsimport-glightbox', 'mlsimport-property-lightbox' );
	$slider   = array_merge( array( 'mlsimport-splide', 'mlsimport-property-slider' ), $lightbox );
	$lead     = array( 'mlsimport-property-lead' );
	$sections = array(
		'price'             => array( __( 'Property Price', 'mlsimport' ), 'mlsimport_property_price' ),
		'price_info'        => array( __( 'Additional Price Info', 'mlsimport' ), 'mlsimport_property_price_info' ),
		'title'             => array( __( 'Property Title', 'mlsimport' ), 'mlsimport_property_title' ),
		'title_bar'         => array( __( 'Title Bar (price + actions)', 'mlsimport' ), 'mlsimport_property_title_bar', array(), array( 'mlsimport-property-share' ) ),
		'status'            => array( __( 'Property Status', 'mlsimport' ), 'mlsimport_property_status' ),
		'address'           => array( __( 'Property Address', 'mlsimport' ), 'mlsimport_property_address' ),
		'header'            => array( __( 'Header Section', 'mlsimport' ), 'mlsimport_property_header' ),
		'subnav'            => array( __( 'Section Navigation', 'mlsimport' ), 'mlsimport_property_subnav', array(), array( 'mlsimport-property-subnav' ) ),
		'breadcrumbs'       => array( __( 'Breadcrumbs', 'mlsimport' ), 'mlsimport_property_breadcrumbs' ),
		'overview'          => array( __( 'Overview', 'mlsimport' ), 'mlsimport_property_overview' ),
		'features'          => array( __( 'Features', 'mlsimport' ), 'mlsimport_property_features' ),

		// Containers — each renders an ordered list of ANY registered sections.
		'tabs'              => array( __( 'Sections as Tabs', 'mlsimport' ), 'mlsimport_property_tabs', array(), array( 'mlsimport-property-tabs' ) ),
		'accordion'         => array( __( 'Sections as Accordion', 'mlsimport' ), 'mlsimport_property_accordion' ),
		'description'       => array( __( 'Description', 'mlsimport' ), 'mlsimport_property_description' ),
		'content'           => array( __( 'Content', 'mlsimport' ), 'mlsimport_property_content' ),
		'excerpt'           => array( __( 'Excerpt', 'mlsimport' ), 'mlsimport_property_excerpt' ),

		// Media — one render fn, layout/variant baked per slug.
		'property_gallery'  => array( __( 'Property Gallery', 'mlsimport' ), 'mlsimport_property_media', array(), $slider ),
		'featured_image'    => array( __( 'Featured Image', 'mlsimport' ), 'mlsimport_property_featured_image' ),
		'gallery'           => array( __( 'Gallery', 'mlsimport' ), 'mlsimport_property_gallery', array( 'layout' => 'grid' ), $lightbox ),
		'gallery_masonry'   => array( __( 'Masonry Gallery v1', 'mlsimport' ), 'mlsimport_property_gallery', array( 'layout' => 'masonry' ), $lightbox ),
		'gallery_masonry_v2' => array( __( 'Masonry Gallery v2', 'mlsimport' ), 'mlsimport_property_gallery', array( 'layout' => 'masonry_v2' ), $lightbox ),
		'slider_classic'    => array( __( 'Classic Slider', 'mlsimport' ), 'mlsimport_property_gallery', array( 'layout' => 'slider', 'variant' => 'classic' ), $slider ),
		'slider_vertical'   => array( __( 'Vertical Slider', 'mlsimport' ), 'mlsimport_property_gallery', array( 'layout' => 'slider', 'variant' => 'vertical' ), $slider ),
		'slider_multi'      => array( __( 'Multi Image Slider', 'mlsimport' ), 'mlsimport_property_gallery', array( 'layout' => 'slider', 'variant' => 'multi' ), $slider ),
		'slider_full'       => array( __( 'Full Width Slider', 'mlsimport' ), 'mlsimport_property_gallery', array( 'layout' => 'slider', 'variant' => 'full' ), $slider ),

		// Location (manages its own provider-specific assets).
		'map'               => array( __( 'Map', 'mlsimport' ), 'mlsimport_property_map' ),

		// Agent / contact / lead capture.
		'agent_card'        => array( __( 'Agent Card', 'mlsimport' ), 'mlsimport_property_agent_card' ),
		'mobile_agent_bar'  => array( __( 'Mobile Agent Bar (sticky)', 'mlsimport' ), 'mlsimport_property_mobile_agent_bar' ),
		'agent_contact'     => array( __( 'Agent Contact Form', 'mlsimport' ), 'mlsimport_property_lead_form', array( 'variant' => 'contact' ), $lead ),
		'agent_form'        => array( __( 'Agent Form Section', 'mlsimport' ), 'mlsimport_property_lead_form', array( 'variant' => 'form' ), $lead ),
		'agent_form_sidebar' => array( __( 'Agent Form (sidebar)', 'mlsimport' ), 'mlsimport_property_lead_form', array( 'variant' => 'sidebar' ), $lead ),
		'schedule_tour'     => array( __( 'Schedule a Tour', 'mlsimport' ), 'mlsimport_property_lead_form', array( 'variant' => 'tour' ), $lead ),
		'booking'           => array( __( 'Booking Sidebar', 'mlsimport' ), 'mlsimport_property_booking', array(), array( 'mlsimport-property-lead', 'mlsimport-property-booking' ) ),
		'calculator'        => array( __( 'Mortgage Calculator', 'mlsimport' ), 'mlsimport_property_calculator', array(), array( 'mlsimport-property-calculator' ) ),

		// Engagement / media.
		'virtual_tour'      => array( __( 'Virtual Tour', 'mlsimport' ), 'mlsimport_property_embed', array( 'source' => 'virtual_tour' ) ),
		'video'             => array( __( 'Video', 'mlsimport' ), 'mlsimport_property_embed', array( 'source' => 'video' ) ),
		'share'             => array( __( 'Share / Print', 'mlsimport' ), 'mlsimport_property_share', array(), array( 'mlsimport-property-share' ) ),
		'attribution'       => array( __( 'MLS Attribution', 'mlsimport' ), 'mlsimport_property_attribution' ),
		'similar'           => array( __( 'Similar Listings', 'mlsimport' ), 'mlsimport_property_similar' ),
	);

	// The nine field sections. One render fn, the slug baked in per section — the
	// same variant pattern the galleries use.
	foreach ( mlsimport_property_field_section_titles() as $slug => $title ) {
		$sections[ $slug ] = array( $title, 'mlsimport_property_field_section', array( 'section' => $slug ) );
	}

	foreach ( $sections as $slug => $def ) {
		mlsimport_register_property_section(
			$slug,
			array(
				'label'  => $def[0],
				'render' => $def[1],
				'args'   => isset( $def[2] ) ? $def[2] : array(),
				'assets' => isset( $def[3] ) ? $def[3] : array(),
			)
		);
	}
}

/**
 * Internal: the by-reference registry store.
 *
 * @return array
 */
function &mlsimport_property_section_registry(): array {
	static $registry = array();
	return $registry;
}
