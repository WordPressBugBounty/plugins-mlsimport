/**
 * Mounts a Splide slider on every standalone property gallery slider.
 *
 * Each slider element carries data-mlsimport-slider="<variant>"; the variant
 * maps to a Splide options preset. The classic and vertical variants sit inside
 * a [data-mlsimport-slider-wrap] next to a thumbnail strip
 * ([data-mlsimport-slider-thumbs]); those mount as a synced main+thumbnail pair
 * (the WpResidence pattern). Multi and full are plain carousels. No jQuery;
 * mobile swipe is built in.
 */
( function () {
	/**
	 * Return the Splide options preset for the main slider of a given variant.
	 *
	 * @param {string} variant Slider variant ('vertical' | 'multi' | 'full' | 'classic').
	 * @return {Object} Splide options for the main carousel.
	 */
	function mainOptions( variant ) {
		switch ( variant ) {
			case 'vertical':
				// Thumbnail strip runs down the side (see thumbOptions + CSS).
				return { type: 'slide', perPage: 1, pagination: false, arrows: true };
			case 'multi':
				return { type: 'slide', perPage: 3, gap: '0.5rem', pagination: false, arrows: true, breakpoints: { 640: { perPage: 1 } } };
			case 'full':
				return { type: 'fade', rewind: true, cover: true, heightRatio: 0.5, pagination: true, arrows: true };
			case 'classic':
			default:
				// Thumbnails are the only navigation under a classic slider.
				return { type: 'fade', rewind: true, pagination: false, arrows: true };
		}
	}

	/**
	 * Return the Splide options preset for the thumbnail navigation strip of a variant.
	 *
	 * @param {string} variant Slider variant ('vertical' vs. everything else).
	 * @return {Object} Splide options for the thumbnail carousel.
	 */
	function thumbOptions( variant ) {
		// Shared navigation-strip defaults for every variant.
		// No focus:'center' — with fewer thumbs than perPage it centres the whole
		// strip, floating it in the middle instead of aligning to the main image
		// (top for the vertical strip, left for the horizontal one).
		var base = { isNavigation: true, gap: '0.5rem', rewind: true, pagination: false, arrows: false };
		if ( variant === 'vertical' ) {
			// Vertical: strip runs top-to-bottom down the side of the main image.
			base.direction = 'ttb';
			base.height = '30rem';
			base.perPage = 5;
			base.fixedWidth = '7rem';
			// Below 720px the wrap stacks into a column (see CSS), so the strip
			// has to run horizontally instead of down the side — otherwise it
			// stays a tall vertical column. mediaQuery defaults to 'max'.
			base.breakpoints = {
				720: { direction: 'ltr', height: 'auto', fixedWidth: '5rem', fixedHeight: '4rem', perPage: 6 }
			};
		} else {
			// Classic (horizontal) strip along the bottom.
			base.perPage = 6;
			base.fixedHeight = '110px';
			base.breakpoints = { 640: { perPage: 4 } };
		}
		return base;
	}

	/**
	 * Mount one gallery slider, wiring a synced thumbnail strip when the variant has one.
	 *
	 * @param {Element} mainEl The main slider element (`[data-mlsimport-slider]`).
	 * @return {void}
	 */
	function mountSlider( mainEl ) {
		// Resolve the variant and locate the sibling thumbnail strip, if any.
		var variant = mainEl.getAttribute( 'data-mlsimport-slider' ) || 'classic';
		var wrap = mainEl.closest( '[data-mlsimport-slider-wrap]' );
		var thumbsEl = wrap ? wrap.querySelector( '[data-mlsimport-slider-thumbs]' ) : null;

		// Build the main carousel from its variant preset.
		var main = new window.Splide( mainEl, mainOptions( variant ) );

		// If an un-mounted thumbnail strip exists, mount the main+thumb pair as a synced unit.
		if ( thumbsEl && ! thumbsEl.hasAttribute( 'data-mlsimport-mounted' ) ) {
			thumbsEl.setAttribute( 'data-mlsimport-mounted', '1' );
			var thumbs = new window.Splide( thumbsEl, thumbOptions( variant ) );
			// sync() before mount() links the carousels in both directions.
			main.sync( thumbs );
			main.mount();
			thumbs.mount();
		} else {
			// No thumbnails: just mount the main carousel.
			main.mount();
		}
	}

	// Content Slider page block: a plain multi-card carousel of property cards
	// (3/2/1 per view, arrows, loop) — the WpResidence content-slider behaviour.
	// The Category Slider reuses this mount but sets its per-view via data-per-page.
	/**
	 * Mount a Content/Category Slider page block as a plain multi-card carousel.
	 *
	 * @param {Element} el The content/category slider element.
	 * @return {void}
	 */
	function mountContent( el ) {
		// Per-view count from data-per-page, defaulting to 3.
		var perPage = parseInt( el.getAttribute( 'data-per-page' ), 10 );
		if ( ! perPage || perPage < 1 ) {
			perPage = 3;
		}
		// Gap between cards, with a sensible default.
		var gap = el.getAttribute( 'data-gap' ) || '1.5rem';
		// Only 'loop' when there are MORE slides than fit in one view. Loop CLONES
		// slides to fill the track, so with <= perPage slides it would duplicate them
		// (2 picked categories rendering as a repeating 14). 'slide' shows exactly the
		// real slides, no clones.
		var slides = el.querySelectorAll( '.splide__slide:not(.splide__slide--clone)' ).length;
		new window.Splide( el, {
			type: slides > perPage ? 'loop' : 'slide',
			perPage: perPage,
			perMove: 1,
			gap: gap,
			pagination: false,
			arrows: true,
			breakpoints: {
				980: { perPage: Math.min( perPage, 2 ) },
				640: { perPage: 1 }
			}
		} ).mount();
	}

	/**
	 * Mount every not-yet-mounted gallery slider and content/category slider on the page.
	 *
	 * @return {void}
	 */
	function mountAll() {
		// Nothing to mount without the Splide library.
		if ( typeof window.Splide === 'undefined' ) {
			return;
		}
		// Gallery sliders: mark mounted, then wire up main + thumbnails.
		Array.prototype.forEach.call( document.querySelectorAll( '.mlsimport-property-slider:not([data-mlsimport-mounted])' ), function ( node ) {
			node.setAttribute( 'data-mlsimport-mounted', '1' );
			mountSlider( node );
		} );
		// Content/Category slider page blocks: mark mounted, then mount as card carousels.
		Array.prototype.forEach.call( document.querySelectorAll( '.mlsimport-content-slider:not([data-mlsimport-mounted]), .mlsimport-category-slider:not([data-mlsimport-mounted])' ), function ( node ) {
			node.setAttribute( 'data-mlsimport-mounted', '1' );
			mountContent( node );
		} );
	}

	/**
	 * Initialise: mount what's present now, and keep mounting sliders injected later.
	 *
	 * @return {void}
	 */
	function init() {
		mountAll();
		// Sliders can arrive after load — a block editor ServerSideRender preview
		// injects markup asynchronously. Watch the DOM and mount them when they land.
		if ( window.MutationObserver && document.body ) {
			new MutationObserver( mountAll ).observe( document.body, { childList: true, subtree: true } );
		}
	}

	// Defer init until the DOM is ready; run immediately if it already is.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
