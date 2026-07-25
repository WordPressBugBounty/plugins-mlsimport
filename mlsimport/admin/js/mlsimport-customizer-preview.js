/**
 * Customizer PREVIEW JS (runs inside the preview iframe).
 *
 * Gives the accent colour instant live preview: when brand_color changes it
 * re-applies the same CSS custom properties the server prints in
 * mlsimport_standalone_brand_color_css() — so the preview matches exactly what
 * publishing will produce. Every other setting uses the 'refresh' transport and
 * reloads the preview server-side, so only the colour is handled here.
 */
( function ( wp ) {
	'use strict';

	// Bail if the Customizer API is unavailable in this context.
	if ( ! wp || ! wp.customize ) {
		return;
	}

	// Id of the <style> element this script owns inside the preview <head>.
	var STYLE_ID = 'mlsimport-customizer-accent';

	/**
	 * Build the accent CSS custom-property block for a given colour.
	 *
	 * Mirrors mlsimport_standalone_brand_color_css() on the server so the live
	 * preview matches the published result. Derives hover/darker variants via
	 * CSS color-mix().
	 *
	 * @param  {string} value Accent colour (any CSS colour value).
	 * @return {string} CSS text defining the accent custom properties.
	 */
	function accentCss( value ) {
		return ':root{'
			+ '--mlsimport-accent:' + value + ';'
			+ '--mlsimport-accent-hover:color-mix(in srgb,' + value + ' 85%,#fff);'
			+ '--mlsimport-accent2:color-mix(in srgb,' + value + ' 80%,#000);'
			+ '--mlsimport-accent2-hover:color-mix(in srgb,' + value + ' 70%,#000);'
			+ '--mlsimport-secondary:' + value + ';'
			+ '--mlsimport-good:' + value + ';'
			+ '}'
			// :root included so cards rendered outside a grid wrapper (Similar Listings
			// on a property page) get the accent too — mirrors the saved-output rule in
			// mlsimport_standalone_brand_color_css(); the two MUST stay identical or the
			// preview shows a colour the published page won't.
			+ ':root,.mlsimport-listings,.mlsimport-page-block{--mli-accent:' + value + ';}';
	}

	// Live-update the accent whenever the brand_color setting changes.
	wp.customize( 'mlsimport_standalone_options[brand_color]', function ( setting ) {
		setting.bind( function ( value ) {
			// Look up our injected style element (may not exist yet).
			var el = document.getElementById( STYLE_ID );
			// Empty value: remove any existing override and stop.
			if ( ! value ) {
				if ( el ) {
					el.parentNode.removeChild( el );
				}
				return;
			}
			// Create the style element on first use.
			if ( ! el ) {
				el = document.createElement( 'style' );
				el.id = STYLE_ID;
				document.head.appendChild( el );
			}
			// Write the freshly computed accent CSS into it.
			el.textContent = accentCss( value );
		} );
	} );
} )( window.wp );
