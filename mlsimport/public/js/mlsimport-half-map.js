/**
 * MLSImport Half Map coordinator (theme_id 990).
 *
 * The list pane filters + paginates over AJAX on its own (mlsimport-listings.js).
 * This thin layer bridges the SAME search form to the map pane and runs the
 * mobile List/Map toggle:
 *
 *   - On the search form's submit, push the form's filter values to the map via
 *     node.mlsimportMap.setFilters() (exposed by mlsimport-page-block-map.js on
 *     query-mode maps). The list and the map read the identical form, so the two
 *     panes can never disagree. We do NOT preventDefault — the listings script
 *     owns the list repaint.
 *   - The List/Map tabs (mobile only) flip which pane is on screen, and call the
 *     map's invalidate() so Leaflet resizes correctly once revealed.
 *   - Draw area: the map's startDraw() lets the user trace a polygon; on close we
 *     write its ring into a hidden "polygon" input in the SAME search form and fire
 *     the form's submit, so the list (FormData) and the map (formFilters) both pick
 *     up the polygon exactly like any other filter — the panes can't disagree.
 *
 * Depends on mlsimport-page-block-map.js. No other dependencies.
 */
( function () {
	'use strict';

	/**
	 * forEach helper that works on array-like NodeLists.
	 * @param {ArrayLike} list Items to iterate.
	 * @param {Function}  fn   Callback per item.
	 */
	function each( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

	// Mirror mlsimport-listings.js collectParams so the map filters on exactly the
	// same values the list does — minus the AJAX envelope (action/nonce/page).
	// FormData captures every selected option of a multi-select; a name ending in []
	// (e.g. state[]) is grouped under its base key (state) as an array, which the
	// map's requestBody serializes back to state[]=a&state[]=b.
	/**
	 * Extract the search form's filter values (minus the AJAX envelope) as the
	 * object shape the map's setFilters() expects.
	 * @param {HTMLFormElement} form The shared search form.
	 * @return {Object} Filter key -> value (arrays for []-suffixed multi fields).
	 */
	function formFilters( form ) {
		var filters = {};
		// No form, no filters.
		if ( ! form ) {
			return filters;
		}
		// FormData yields one entry per selected control; fold them into `filters`.
		new FormData( form ).forEach( function ( value, name ) {
			// Drop the AJAX envelope fields and empty values.
			if ( name === 'action' || name === 'nonce' || name === 'page' || value === '' ) {
				return;
			}
			// Strip a trailing [] to get the base key; presence of [] marks a multi.
			var key   = name.replace( /\[\]$/, '' );
			var multi = key !== name;
			// Merge repeated keys into an array; else set scalar (or single-item array).
			if ( Object.prototype.hasOwnProperty.call( filters, key ) ) {
				if ( ! Array.isArray( filters[ key ] ) ) {
					filters[ key ] = [ filters[ key ] ];
				}
				filters[ key ].push( value );
			} else {
				filters[ key ] = multi ? [ value ] : value;
			}
		} );
		return filters;
	}

	/**
	 * Resolve the map control API exposed on the .mlsimport-map node.
	 * @param {Element} root The half-map block root.
	 * @return {Object|null} The map handle, or null if not present.
	 */
	function mapHandle( root ) {
		var node = root.querySelector( '.mlsimport-map' );
		return node && node.mlsimportMap ? node.mlsimportMap : null;
	}

	/**
	 * On search-form submit, push the current filters to the map pane.
	 * @param {Element} root The half-map block root.
	 */
	function initSync( root ) {
		var form = root.querySelector( '.mlsimport-search' );
		// Nothing to sync without a search form.
		if ( ! form ) {
			return;
		}
		// Do NOT preventDefault — listings.js owns the list repaint.
		form.addEventListener( 'submit', function () {
			var handle = mapHandle( root );
			if ( handle ) {
				handle.setFilters( formFilters( form ) );
			}
		} );
	}

	/**
	 * Wire the mobile List/Map tabs that flip which pane is visible.
	 * @param {Element} root The half-map block root.
	 */
	function initToggle( root ) {
		var tabs = root.querySelectorAll( '.mlsimport-half-map__tab' );
		// No tabs (desktop layout) — nothing to wire.
		if ( ! tabs.length ) {
			return;
		}
		each( tabs, function ( tab ) {
			tab.addEventListener( 'click', function () {
				// Which pane this tab selects.
				var showMap = tab.getAttribute( 'data-view' ) === 'map';
				// Flip the root class that CSS uses to show map vs list.
				root.classList.toggle( 'is-view-map', showMap );
				// Mark the clicked tab active, the others inactive.
				each( tabs, function ( t ) {
					t.classList.toggle( 'is-active', t === tab );
				} );
				// The map sizes to a hidden (zero-height) container; fix it on reveal.
				if ( showMap ) {
					var handle = mapHandle( root );
					if ( handle && handle.invalidate ) {
						handle.invalidate();
					}
				}
			} );
		} );
	}

	// Find (or lazily create) the hidden polygon field in the search form, so a
	// drawn ring travels with every list/map request like a native filter value.
	/**
	 * @param {HTMLFormElement} form The search form.
	 * @return {HTMLInputElement} The hidden polygon input (created if absent).
	 */
	function polygonInput( form ) {
		var el = form.querySelector( 'input[name="polygon"]' );
		// Create the hidden field on first use.
		if ( ! el ) {
			el = document.createElement( 'input' );
			el.type = 'hidden';
			el.name = 'polygon';
			form.appendChild( el );
		}
		return el;
	}

	// One synthetic submit drives both panes: listings.js repaints the list and
	// initSync() above pushes the same form (polygon included) to the map.
	/**
	 * @param {HTMLFormElement} form The search form to (re)submit.
	 */
	function submitForm( form ) {
		form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	}

	/**
	 * Wire the draw-area controls (start/cancel a polygon, clear it).
	 * @param {Element} root The half-map block root.
	 */
	function initDraw( root ) {
		// Draw start/clear buttons and the shared search form.
		var startBtn = root.querySelector( '[data-draw="start"]' );
		var clearBtn = root.querySelector( '[data-draw="clear"]' );
		var form     = root.querySelector( '.mlsimport-search' );
		// Need at least a start button and a form.
		if ( ! startBtn || ! form ) {
			return;
		}

		// Remember the idle button label and track drawing state.
		var idleLabel = startBtn.textContent;
		var drawing   = false;

		/**
		 * Return the start button to its idle (not-drawing) appearance.
		 */
		function resetStart() {
			drawing = false;
			startBtn.classList.remove( 'is-drawing' );
			startBtn.textContent = idleLabel;
		}

		startBtn.addEventListener( 'click', function () {
			var handle = mapHandle( root );
			// Map must support drawing.
			if ( ! handle || ! handle.startDraw ) {
				return;
			}
			// A second click while drawing cancels the in-progress shape.
			if ( drawing ) {
				handle.clearDraw();
				resetStart();
				return;
			}
			// Enter drawing mode and swap the button to its "drawing" label.
			drawing = true;
			startBtn.classList.add( 'is-drawing' );
			startBtn.textContent = startBtn.getAttribute( 'data-label-drawing' ) || idleLabel;
			// Begin drawing; the callback fires with the finished ring's coords.
			handle.startDraw( function ( coords ) {
				resetStart();
				// Compact "lng lat,lng lat,..." ring — the server parses it back to floats.
				polygonInput( form ).value = coords.map( function ( p ) {
					return p.lng + ' ' + p.lat;
				} ).join( ',' );
				// Reveal the clear button now that a shape exists.
				if ( clearBtn ) {
					clearBtn.classList.remove( 'is-hidden' );
				}
				// Push the polygon to both panes via one submit.
				submitForm( form );
			} );
		} );

		// Clear button erases the drawn shape and re-runs the search.
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				var handle = mapHandle( root );
				if ( handle && handle.clearDraw ) {
					handle.clearDraw();
				}
				resetStart();
				// Wipe the polygon value and hide the clear button.
				polygonInput( form ).value = '';
				clearBtn.classList.add( 'is-hidden' );
				submitForm( form );
			} );
		}
	}

	// Full-bleed pin: the CSS makes the block 100vw wide, but a pure-CSS breakout
	// centers it on its PARENT column — wrong when the theme puts the page content in
	// an off-center column (a page with a sidebar). Measure the block's containing
	// block (its parent's content-left) and pull the block left by exactly that, so
	// it pins to the viewport's left edge on any template. Set as an INLINE
	// `margin-left ... !important`: a block theme's constrained-layout rule overrides
	// a plugin stylesheet !important, but not an element inline !important. Recomputed
	// on resize.
	/**
	 * Pin the block's left edge to the viewport by pulling it left by its
	 * containing block's content-left (works even in off-center columns).
	 * @param {Element} root The half-map block root.
	 */
	function pinFullBleed( root ) {
		var parent = root.parentElement;
		// Need a parent to measure against.
		if ( ! parent ) {
			return;
		}
		// Drop OUR OWN overrides before measuring. The parent's geometry can depend on
		// its children — a shrink-to-fit or constrained-layout parent sizes and
		// positions around them — so measuring while last run's margin-left and width
		// are still applied feeds this function's output back into its own input. Over
		// repeated calls (resize, and the ResizeObserver below) the offset drifts and
		// settles at margin-left:0, which leaves the block sitting at its parent's
		// inset while still being clientWidth wide: it then hangs off the right edge by
		// exactly that inset and the horizontal scrollbar comes back. Resetting first
		// makes each run measure the same clean baseline, so the result is idempotent.
		root.style.removeProperty( 'margin-left' );
		root.style.removeProperty( 'width' );
		root.style.removeProperty( 'max-width' );

		// Measure where the block ITSELF naturally sits, not where we calculate its
		// parent's content box to start. Deriving the offset from the parent's
		// border+padding assumes nothing else contributes to the inset — but a wrapper
		// element, a transform, or a theme's constrained-layout rule can all shift the
		// block without touching the parent's own padding, and then the computed offset
		// is wrong and the block lands off-centre. Its own rect already accounts for
		// every one of those, whatever they are.
		var ownLeft = root.getBoundingClientRect().left;
		// Only pin when there is actually an inset to cancel. On a full-width template
		// the block already starts at x=0, and writing `margin-left: 0 !important`
		// there is not a no-op: it is an inline !important that outranks every rule a
		// theme might legitimately use to position this block, for no benefit. The
		// reset above already cleared any earlier value, so skipping leaves the
		// element with no inline margin at all.
		if ( 0 !== ownLeft ) {
			// Inline !important margin-left wins over block-theme constrained layout.
			root.style.setProperty( 'margin-left', ( -ownLeft ) + 'px', 'important' );
		}
		// The CSS fallback width is 100vw, which INCLUDES the vertical scrollbar. Now
		// that the margin above pins the left edge to x=0, a 100vw width runs past the
		// content edge by exactly the scrollbar width and puts a permanent horizontal
		// scrollbar at the bottom of the page. clientWidth excludes the scrollbar.
		root.style.setProperty( 'width', document.documentElement.clientWidth + 'px', 'important' );
		root.style.setProperty( 'max-width', document.documentElement.clientWidth + 'px', 'important' );

		// Verify, don't trust. Everything above is a prediction about how the browser
		// will lay the block out, and a prediction can be wrong in a template we have
		// never seen — a positioned ancestor, a transform, a flex/grid parent that
		// re-resolves the margin. So read the result back and correct any residual
		// against the two things that actually matter: the left edge must be at 0, and
		// the right edge must not pass the viewport's content edge. This is what makes
		// the block unable to cause a horizontal scrollbar regardless of the theme.
		// NOTE the sub-pixel handling here. Browsers draw a horizontal scrollbar for a
		// fractional overhang — a right edge at clientWidth + 0.5px is enough — and
		// fractional layout is normal at odd viewport widths and at any browser zoom
		// that is not 100%. Rounding these comparisons would report "no overflow" on
		// exactly the machines that show the bar, so the drift is corrected at full
		// precision and the width is trimmed with ceil, never round.
		var applied = root.getBoundingClientRect();
		if ( 0 !== applied.left ) {
			var pinned = -ownLeft - applied.left;
			// Same rule as above: no inline override unless it earns its place.
			if ( 0 === pinned ) {
				root.style.removeProperty( 'margin-left' );
			} else {
				root.style.setProperty( 'margin-left', pinned + 'px', 'important' );
			}
		}
		var overhang = root.getBoundingClientRect().right - document.documentElement.clientWidth;
		if ( overhang > 0 ) {
			var corrected = document.documentElement.clientWidth - Math.ceil( overhang );
			root.style.setProperty( 'width', corrected + 'px', 'important' );
			root.style.setProperty( 'max-width', corrected + 'px', 'important' );
		}
	}

	/**
	 * Initialise every half-map block on the page.
	 */
	function init() {
		each( document.querySelectorAll( '.mlsimport-half-map' ), function ( root ) {
			pinFullBleed( root );
			initSync( root );
			initToggle( root );
			initDraw( root );
		} );
	}

	// Recompute the full-bleed offset on resize (debounced ~150ms).
	var resizeTimer;
	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( function () {
			each( document.querySelectorAll( '.mlsimport-half-map' ), function ( root ) {
				pinFullBleed( root );
				// pinFullBleed sets the pane widths from JS, which lands AFTER Leaflet
				// has already re-measured on the raw resize event. Without this the map
				// keeps the pre-resize size and renders a stale/blank strip down its
				// right edge. Re-measure now that the final width is applied.
				var handle = mapHandle( root );
				if ( handle && handle.invalidate ) {
					handle.invalidate();
				}
			} );
		}, 150 );
	} );

	// The `resize` event above only fires when the WINDOW changes. But the width
	// available to the block also changes when the vertical scrollbar appears or
	// disappears — which happens on its own as results render and the page grows or
	// shrinks. pinFullBleed() measures documentElement.clientWidth, so a scrollbar
	// arriving after init leaves the block 15px too wide and the horizontal
	// scrollbar comes back. Watch the document element and re-pin whenever the
	// usable width actually changes.
	if ( window.ResizeObserver ) {
		var lastWidth = document.documentElement.clientWidth;
		new ResizeObserver( function () {
			var width = document.documentElement.clientWidth;
			// Only act on a real width change — re-pinning sets an element width,
			// which can feed back into this observer and loop otherwise.
			if ( width === lastWidth ) {
				return;
			}
			lastWidth = width;
			each( document.querySelectorAll( '.mlsimport-half-map' ), function ( root ) {
				pinFullBleed( root );
				var handle = mapHandle( root );
				if ( handle && handle.invalidate ) {
					handle.invalidate();
				}
			} );
		} ).observe( document.documentElement );
	}

	// Run init once the DOM is ready (or immediately if it already is).
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
