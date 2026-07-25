<?php
/**
 * Standalone (theme_id 990) single-property section assets.
 *
 * Registers the front-end CSS/JS each section can use and enqueues them
 * on demand: the dispatcher calls enqueue() with a section's declared asset
 * handles only when that section actually renders, so Splide/Leaflet/etc. load
 * only on pages that use them (decision 6/8). Handles are registered idempotently
 * so enqueue works regardless of hook order. See docs/adr/0005.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and on-demand enqueues single-property section assets.
 */
class Mlsimport_Property_Section_Assets {

	/**
	 * The base stylesheet handle, enqueued whenever any section renders.
	 */
	const BASE_STYLE = 'mlsimport-property-sections';

	/**
	 * Register all section asset handles once. Idempotent.
	 *
	 * @return void
	 */
	public static function ensure_registered(): void {
		static $done = false;
		if ( $done || ! function_exists( 'wp_register_style' ) ) {
			return;
		}
		$done = true;

		$url = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : '';
		$ver = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : false;

		wp_register_style( self::BASE_STYLE, $url . 'public/css/mlsimport-property-sections.css', array(), $ver );
		// Re-theme the section tokens from the "Main Color" design setting on EVERY
		// page that loads the base style — property pages, page blocks, the agent
		// profile (enqueued directly in agent-sections.php) and the editor preview.
		// Attached at REGISTRATION so all enqueue paths get it, not just enqueue().
		if ( function_exists( 'mlsimport_standalone_attach_brand_color' ) ) {
			mlsimport_standalone_attach_brand_color( self::BASE_STYLE );
		}

		// Single-agent profile styles — layered on the base tokens/section shell.
		wp_register_style( 'mlsimport-agent', $url . 'public/css/mlsimport-agent.css', array( self::BASE_STYLE ), $ver );

		// Vendored Splide (gallery/sliders) — registered, enqueued only on demand.
		wp_register_style( 'mlsimport-splide', $url . 'public/vendor/splide/splide.min.css', array(), $ver );
		wp_register_script( 'mlsimport-splide', $url . 'public/vendor/splide/splide.min.js', array(), $ver, true );
		wp_register_script( 'mlsimport-property-slider', $url . 'public/js/mlsimport-property-slider.js', array( 'mlsimport-splide' ), $ver, true );

		// Vendored GLightbox (gallery/slider click-to-zoom) — registered, enqueued on demand.
		wp_register_style( 'mlsimport-glightbox', $url . 'public/vendor/glightbox/glightbox.min.css', array(), $ver );
		wp_register_script( 'mlsimport-glightbox', $url . 'public/vendor/glightbox/glightbox.min.js', array(), $ver, true );
		wp_register_script( 'mlsimport-property-lightbox', $url . 'public/js/mlsimport-property-lightbox.js', array( 'mlsimport-glightbox' ), $ver, true );

		// Vendored Leaflet + map init (map section) — registered, enqueued on demand.
		wp_register_style( 'mlsimport-leaflet', $url . 'public/vendor/leaflet/leaflet.css', array(), $ver );
		wp_register_script( 'mlsimport-leaflet', $url . 'public/vendor/leaflet/leaflet.js', array(), $ver, true );
		wp_register_script( 'mlsimport-property-map', $url . 'public/js/mlsimport-property-map.js', array(), $ver, true );

		// Multi-marker map for the "Map with Listings" page block — reuses Leaflet.
		wp_register_script( 'mlsimport-page-block-map', $url . 'public/js/mlsimport-page-block-map.js', array(), $ver, true );

		// Half Map page block: split listings/map surface. The coordinator depends on
		// the page-block map script (which exposes node.mlsimportMap on query maps).
		wp_register_style( 'mlsimport-half-map', $url . 'public/css/mlsimport-half-map.css', array(), $ver );
		wp_register_script( 'mlsimport-half-map', $url . 'public/js/mlsimport-half-map.js', array( 'mlsimport-page-block-map' ), $ver, true );

		// The "I'm interested in" dropdown widget. Declared as a dependency of the lead
		// script rather than as a section asset, so it loads exactly where lead forms
		// load — including the booking rail's Ask a Question panel — with no section
		// declaration to keep in sync.
		wp_register_script( 'mlsimport-property-interest', $url . 'public/js/mlsimport-property-interest.js', array(), $ver, true );

		// Lead forms — AJAX submit; localize the admin-ajax URL.
		wp_register_script( 'mlsimport-property-lead', $url . 'public/js/mlsimport-property-lead.js', array( 'mlsimport-property-interest' ), $ver, true );
		wp_localize_script(
			'mlsimport-property-lead',
			'MLSImportLead',
			array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) )
		);

		// Mortgage calculator — browser-only.
		wp_register_script( 'mlsimport-property-calculator', $url . 'public/js/mlsimport-property-calculator.js', array(), $ver, true );

		// Share / print buttons — browser-only.
		wp_register_script( 'mlsimport-property-share', $url . 'public/js/mlsimport-property-share.js', array(), $ver, true );

		// Details-as-tabs toggling — browser-only.
		wp_register_script( 'mlsimport-property-tabs', $url . 'public/js/mlsimport-property-tabs.js', array(), $ver, true );

		// Sticky in-page sub-nav (scrollspy + smooth scroll) — browser-only.
		wp_register_script( 'mlsimport-property-subnav', $url . 'public/js/mlsimport-property-subnav.js', array(), $ver, true );

		// Booking sidebar tour/question tab toggle — browser-only.
		wp_register_script( 'mlsimport-property-booking', $url . 'public/js/mlsimport-property-booking.js', array(), $ver, true );
	}

	/**
	 * Enqueue the base stylesheet plus a section's declared asset handles.
	 *
	 * @param string[] $handles Section asset handles from the manifest.
	 * @return void
	 */
	public static function enqueue( array $handles = array() ): void {
		if ( ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}
		self::ensure_registered();

		wp_enqueue_style( self::BASE_STYLE );

		foreach ( $handles as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_enqueue_script( $handle );
			}
			if ( wp_style_is( $handle, 'registered' ) ) {
				wp_enqueue_style( $handle );
			}
		}
	}

	/**
	 * Pre-enqueue the single-property page's section assets into the <head>.
	 *
	 * The single templates print the header (firing wp_head) before any section
	 * renders, so the per-section on-demand enqueue in the render dispatcher lands
	 * too late for the head — WordPress prints those styles in the footer via
	 * print_late_styles(), and the page paints unstyled markup that snaps into
	 * place once the footer CSS/JS loads (issue #172, the gallery/section flash).
	 *
	 * Hooked on wp_enqueue_scripts, this resolves the exact sections the single
	 * template will render — the locked hero, the "Arrange Sections" content
	 * column and the booking sidebar — and enqueues their declared assets while
	 * the head is still open. It reuses the same per-section asset manifests as
	 * the on-demand path (which stays in place and no-ops for the already-queued
	 * handles), so a section never styles itself twice and conditional vendors
	 * (Leaflet, GLightbox) still load only when their section is active.
	 *
	 * @return void
	 */
	public static function enqueue_for_single(): void {
		if ( ! function_exists( 'is_singular' ) || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		// Both the stored single (is_singular) and the live virtual route render
		// the same section stack, so cover both.
		$is_live = function_exists( 'get_query_var' ) && '' !== (string) get_query_var( 'mlsimport_live_listing' );
		if ( ! $is_live && ! is_singular( 'mlsimport_property' ) ) {
			return;
		}

		if ( function_exists( 'mlsimport_register_builtin_property_sections' ) ) {
			mlsimport_register_builtin_property_sections();
		}
		if ( ! function_exists( 'mlsimport_property_section_registry' ) ) {
			return;
		}

		$id     = $is_live ? 0 : (int) get_queried_object_id();
		$active = function_exists( 'mlsimport_standalone_active_sections' )
			? mlsimport_standalone_active_sections()
			: array();
		/** This filter is documented in templates/single-mlsimport-property.php */
		$active = (array) apply_filters( 'mlsimport_single_property_sections', $active, $id );

		// The locked hero + content column + booking sidebar — the full set the
		// single template renders.
		$slugs    = array_merge( array( 'breadcrumbs', 'property_gallery', 'title_bar', 'subnav' ), $active, array( 'booking' ) );
		$registry = mlsimport_property_section_registry();
		$handles  = array();
		foreach ( array_unique( $slugs ) as $slug ) {
			if ( isset( $registry[ $slug ]['assets'] ) ) {
				$handles = array_merge( $handles, (array) $registry[ $slug ]['assets'] );
			}
		}

		self::enqueue( array_unique( $handles ) );
	}
}
