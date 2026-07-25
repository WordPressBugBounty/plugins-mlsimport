/**
 * Standalone property sub-nav. Highlights the link for the section currently in
 * view (scrollspy), hides any link whose target section isn't on the page, and
 * drives the prev/next arrows when the chips are too many to fit.
 * Smooth scrolling + the sticky offset are handled in CSS. No jQuery.
 */
( function () {
	/**
	 * The arrows on either side of the strip. They only exist when the chips
	 * overflow, so the strip is measured — never assumed — and re-measured when the
	 * viewport changes.
	 *
	 * @param {Element} nav The scrolling sub-nav element.
	 * @return {void}
	 */
	function initArrows( nav ) {
		// Need the strip wrapper that holds the arrows.
		var strip = nav.closest( '[data-subnav-strip]' );
		if ( ! strip ) {
			return;
		}

		// Both arrow controls must be present.
		var prev = strip.querySelector( '[data-subnav-prev]' );
		var next = strip.querySelector( '[data-subnav-next]' );
		if ( ! prev || ! next ) {
			return;
		}

		// Recompute overflow state and per-arrow disabled state from current scroll.
		function sync() {
			// A hair of tolerance: fractional layout widths make scrollWidth exceed
			// clientWidth by a sub-pixel on strips that actually fit.
			var overflows = nav.scrollWidth - nav.clientWidth > 1;
			strip.classList.toggle( 'is-scrollable', overflows );
			// Nothing more to do when the strip fits.
			if ( ! overflows ) {
				return;
			}
			// Disable each arrow at its respective end of the scroll range.
			var max = nav.scrollWidth - nav.clientWidth;
			prev.disabled = nav.scrollLeft <= 1;
			next.disabled = nav.scrollLeft >= max - 1;
		}

		// Scroll the strip by ~80% of a screenful in the given direction.
		function step( dir ) {
			// Most of a screenful, so the chip at the edge stays visible as an anchor.
			nav.scrollBy( { left: dir * nav.clientWidth * 0.8, behavior: 'smooth' } );
		}

		// Wire the arrow clicks and keep arrow state in sync on scroll.
		prev.addEventListener( 'click', function () {
			step( -1 );
		} );
		next.addEventListener( 'click', function () {
			step( 1 );
		} );
		nav.addEventListener( 'scroll', sync );

		// Measured at DOMContentLoaded the strip can still be mid-layout (fonts, CSS),
		// reporting scrollWidth === clientWidth and disabling both arrows forever — a
		// disabled arrow can never fire the scroll event that would re-enable it.
		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( sync ).observe( nav );
		}
		// Re-sync after full load, and once immediately.
		window.addEventListener( 'load', sync );
		sync();
	}

	/**
	 * Initialise one sub-nav: wire its arrows and set up scrollspy highlighting.
	 *
	 * @param {Element} nav The sub-nav element (`[data-mlsimport-subnav]`).
	 * @return {void}
	 */
	function init( nav ) {
		// Set up the overflow arrows first.
		initArrows( nav );

		// Collect the chip links and build a section-id → link map.
		var links   = nav.querySelectorAll( '.mlsimport-property-subnav__link' );
		var map     = {};
		var targets = [];

		Array.prototype.forEach.call( links, function ( a ) {
			// Each link points at a section id via data-target.
			var id = a.getAttribute( 'data-target' );
			var el = id ? document.getElementById( id ) : null;
			if ( el ) {
				map[ id ] = a;
				targets.push( el );
			} else {
				// No matching section on this page — drop the link.
				a.style.display = 'none';
			}
		} );

		// Scrollspy needs IntersectionObserver support and at least one target section.
		if ( ! ( 'IntersectionObserver' in window ) || ! targets.length ) {
			return;
		}

		// Observer that highlights the link of whichever section is currently intersecting.
		var obs = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					// Only react to sections entering the spy band.
					if ( ! entry.isIntersecting ) {
						return;
					}
					// Clear all active states, then mark the link for this section active.
					Object.keys( map ).forEach( function ( key ) {
						map[ key ].classList.remove( 'is-active' );
					} );
					var active = map[ entry.target.id ];
					if ( active ) {
						active.classList.add( 'is-active' );
					}
				} );
			},
			// Spy band: below the sticky header (-96px top) and only the top third of the viewport.
			{ rootMargin: '-96px 0px -65% 0px', threshold: 0 }
		);

		// Observe every mapped section.
		targets.forEach( function ( t ) {
			obs.observe( t );
		} );
	}

	/**
	 * Boot every sub-nav instance on the page.
	 *
	 * @return {void}
	 */
	function boot() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-mlsimport-subnav]' ),
			init
		);
	}

	// Defer boot until the DOM is ready; run immediately if it already is.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
