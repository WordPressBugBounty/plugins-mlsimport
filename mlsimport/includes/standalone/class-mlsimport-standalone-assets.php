<?php
/**
 * Standalone (theme_id 990) front-end assets (M6).
 *
 * Registers the self-contained BEM stylesheet and the AJAX filter/paginate
 * script, localizing the admin-ajax URL + nonce. The stylesheet can be turned
 * off by sites that prefer to style everything themselves:
 *   add_filter( 'mlsimport_standalone_styles', '__return_false' );
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the standalone front-end CSS/JS.
 */
class Mlsimport_Standalone_Assets {

	/**
	 * Register + enqueue the front-end assets.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$url = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );
		$ver = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : '1';

		if ( apply_filters( 'mlsimport_standalone_styles', true ) ) {
			wp_enqueue_style( 'mlsimport-listings', $url . 'public/css/mlsimport-listings.css', array(), $ver );
			wp_enqueue_style( 'mlsimport-multiselect', $url . 'public/css/mlsimport-multiselect.css', array(), $ver );
			wp_enqueue_style( 'mlsimport-search-popups', $url . 'public/css/mlsimport-search-popups.css', array(), $ver );
			// Re-theme the listing-grid accent (--mli-accent) from the "Main Color"
			// design setting; printed after the stylesheet so it wins the cascade.
			// listings.css loads on every front-end page, so this also seeds the
			// override globally for any widget/block that inherits the accent tokens.
			if ( function_exists( 'mlsimport_standalone_attach_brand_color' ) ) {
				mlsimport_standalone_attach_brand_color( 'mlsimport-listings' );
			}
		}

		// Dropdown multi-select for the search-form taxonomy fields.
		wp_enqueue_script( 'mlsimport-multiselect', $url . 'public/js/mlsimport-multiselect.js', array(), $ver, true );
		// Range sliders (price, area, lot, year) + beds/baths tile popups for the search-form column fields.
		wp_enqueue_script( 'mlsimport-search-popups', $url . 'public/js/mlsimport-search-popups.js', array(), $ver, true );
		// City / area / county / ZIP suggestions for the search form's Location field.
		wp_enqueue_script( 'mlsimport-location-autocomplete', $url . 'public/js/mlsimport-location-autocomplete.js', array(), $ver, true );
		wp_localize_script(
			'mlsimport-location-autocomplete',
			'MLSImportLocations',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'action'  => Mlsimport_Standalone_Ajax::LOCATIONS_ACTION,
				'nonce'   => wp_create_nonce( Mlsimport_Standalone_Ajax::LOCATIONS_ACTION ),
			)
		);
		// The Sort control is a .mlsimport-interest wrapper (see the results toolbar in
		// class-mlsimport-standalone-render.php), so it needs the same enhancer the
		// property lead form uses. The script is self-contained and enhances any
		// .mlsimport-interest on the page; the panel markup it builds reuses
		// .mlsimport-location__list, which mlsimport-listings.css already provides here.
		wp_enqueue_script( 'mlsimport-property-interest', $url . 'public/js/mlsimport-property-interest.js', array(), $ver, true );
		wp_enqueue_script( 'mlsimport-listings', $url . 'public/js/mlsimport-listings.js', array(), $ver, true );
		wp_localize_script(
			'mlsimport-listings',
			'MLSImportListings',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'action'  => Mlsimport_Standalone_Ajax::ACTION,
				'nonce'   => wp_create_nonce( Mlsimport_Standalone_Ajax::ACTION ),
			)
		);

		// Favorites: the card hearts, the single-page Save button and the "My saved
		// properties" view. Loaded on every front-end page (like the listings assets)
		// so a saved heart hydrates wherever a card or the single page appears. The
		// bootstrap carries the logged-in user's saved list for one-pass hydration.
		if ( apply_filters( 'mlsimport_standalone_styles', true ) ) {
			wp_enqueue_style( 'mlsimport-favorites', $url . 'public/css/mlsimport-favorites.css', array( 'mlsimport-listings' ), $ver );
		}
		wp_enqueue_script( 'mlsimport-favorites', $url . 'public/js/mlsimport-favorites.js', array(), $ver, true );
		if ( class_exists( 'Mlsimport_Favorites' ) ) {
			wp_localize_script( 'mlsimport-favorites', 'MLSImportFav', Mlsimport_Favorites::bootstrap() );
		}

		// Elementor's editor renders canvas widgets over AJAX and injects the HTML into
		// the preview iframe, so a widget's own render-time enqueue never reaches the
		// iframe document (the same quirk enqueue_editor() works around for Gutenberg).
		// Load the section stylesheet up front in preview mode instead — otherwise the
		// Featured Property card and the property sections preview unstyled.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->preview )
			&& \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			Mlsimport_Property_Section_Assets::enqueue();
			// The Half Map widget's split layout AND its floating map controls (the
			// mobile List/Map switch, the Draw/Clear tools) are styled by this sheet.
			// Without it the preview shows those as unstyled theme buttons and the
			// desktop-only "hide the mobile switch" rule never runs — four stray buttons.
			// Style only (Leaflet/JS don't run in the editor); the handle is registered
			// by the enqueue() call above via ensure_registered().
			wp_enqueue_style( 'mlsimport-half-map' );
		}
	}

	/**
	 * Load the front-end stylesheets into the block editor so the dynamic blocks'
	 * ServerSideRender previews (listings, page blocks, single-property sections)
	 * match the front end. The blocks render identical HTML in both contexts but
	 * the editor never loads the front-end CSS, so the preview shows raw markup.
	 *
	 * Hooked on enqueue_block_assets (not enqueue_block_editor_assets) because that
	 * is the hook WordPress injects into the editor's iframe; guarded to the editor
	 * so the front end — which already enqueues these on render — isn't double-loaded.
	 *
	 * @return void
	 */
	public static function enqueue_editor(): void {
		if ( ! is_admin() || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		$url = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );
		$ver = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : '1';

		// Card grid, search form, toolbar, pager — listings + page blocks.
		if ( apply_filters( 'mlsimport_standalone_styles', true ) ) {
			wp_enqueue_style( 'mlsimport-listings', $url . 'public/css/mlsimport-listings.css', array(), $ver );
			wp_enqueue_style( 'mlsimport-multiselect', $url . 'public/css/mlsimport-multiselect.css', array(), $ver );
			wp_enqueue_style( 'mlsimport-search-popups', $url . 'public/css/mlsimport-search-popups.css', array(), $ver );
			// Card hearts appear in the SSR card previews — style them in the editor too.
			wp_enqueue_style( 'mlsimport-favorites', $url . 'public/css/mlsimport-favorites.css', array( 'mlsimport-listings' ), $ver );
			if ( function_exists( 'mlsimport_standalone_attach_brand_color' ) ) {
				mlsimport_standalone_attach_brand_color( 'mlsimport-listings' );
			}
		}

		// The search-form block's multi-selects render as dropdowns in the preview too.
		wp_enqueue_script( 'mlsimport-multiselect', $url . 'public/js/mlsimport-multiselect.js', array(), $ver, true );
		// And its range sliders + beds/baths tile popups enhance in the preview too.
		wp_enqueue_script( 'mlsimport-search-popups', $url . 'public/js/mlsimport-search-popups.js', array(), $ver, true );

		// Single-property section shell + agent profile + vendored gallery/map/slider
		// CSS, so every section and page block previews styled.
		Mlsimport_Property_Section_Assets::ensure_registered();
		foreach ( array( Mlsimport_Property_Section_Assets::BASE_STYLE, 'mlsimport-agent', 'mlsimport-splide', 'mlsimport-glightbox', 'mlsimport-leaflet' ) as $handle ) {
			wp_enqueue_style( $handle );
		}

		// The section/page-block shell stylesheet (BASE_STYLE) also by EXPLICIT URL
		// under an editor-only handle: handle-only enqueues of pre-registered styles
		// do NOT reach the block-editor iframe (same quirk as the scripts below), so
		// the handle enqueue above styles only the outer document. Without this the
		// Featured Property / section SSR previews render unstyled in the editor.
		wp_enqueue_style( 'mlsimport-property-sections-editor', $url . 'public/css/mlsimport-property-sections.css', array(), $ver );
		// Re-theme the section tokens (--mlsimport-accent) in the editor iframe too,
		// on the URL-based handle that actually reaches it.
		if ( function_exists( 'mlsimport_standalone_attach_brand_color' ) ) {
			mlsimport_standalone_attach_brand_color( 'mlsimport-property-sections-editor' );
		}

		// Half Map block: the split list/map layout, by explicit URL so it reaches
		// the editor iframe (the SSR preview shows the panes side by side; Leaflet
		// itself doesn't run in the editor, so the map pane previews empty).
		wp_enqueue_style( 'mlsimport-half-map-editor', $url . 'public/css/mlsimport-half-map.css', array(), $ver );

		// Content Slider block: load Splide + the slider init by EXPLICIT URL (under
		// editor-only handles) so they reach the block-editor iframe. Handle-only
		// enqueues of pre-registered scripts do NOT reach the iframe here; the
		// multiselect script above proves the URL form does. The slider script
		// observes the DOM and mounts Splide on the async-injected SSR preview.
		wp_enqueue_style( 'mlsimport-splide-editor', $url . 'public/vendor/splide/splide.min.css', array(), $ver );
		wp_enqueue_script( 'mlsimport-splide-editor', $url . 'public/vendor/splide/splide.min.js', array(), $ver, true );
		wp_enqueue_script( 'mlsimport-content-slider-editor', $url . 'public/js/mlsimport-property-slider.js', array( 'mlsimport-splide-editor' ), $ver, true );
	}
}
