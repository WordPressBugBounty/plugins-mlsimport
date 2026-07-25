/**
 * mlsimport-search-popups — WPResidence-style dropdown popups for the standalone
 * search form, vanilla (no jQuery, no Bootstrap):
 *
 *   .mlsimport-range — a dual-handle slider + min/max inputs that feed a pair of
 *                      hidden fields. One component for every range column (price,
 *                      living area, lot size, year built); data-format ('money' |
 *                      'number' | 'year') and data-unit drive how values render.
 *   .mlsimport-bedsbaths — one popup with a Beds row and a Baths row of "N+"
 *                          minimum tiles, each feeding its own hidden field
 *                          (beds / baths).
 *
 * The hidden inputs are the source of truth and carry the real query params, so the
 * surrounding form submits the usual payload and nothing JS-side is needed to submit.
 * An untouched range (min floor / max ceiling) and an unselected tile submit empty, so
 * a filter only applies once the user narrows it.
 *
 * Call window.mlsimportSearchPopupsInit( root ) to enhance markup added later
 * (e.g. a block editor ServerSideRender preview); it is idempotent.
 */
( function () {
	'use strict';

	/**
	 * Format a slider value for display. 'money' → "$1,234,567"; 'year' → "1985"
	 * (no grouping); 'number' → "1,234 ft²". compact gives the short toggle form
	 * ($900K, 1M ft²); years are never compacted. Mirrors the PHP
	 * mlsimport_format_range_value().
	 */
	function formatRange( n, format, unit, compact ) {
		// Work with whole numbers only.
		n = Math.round( n );
		// Years render bare, with no grouping/prefix/suffix.
		if ( 'year' === format ) {
			return String( n );
		}
		// Money gets a "$ " prefix; non-money units get a trailing unit suffix.
		var prefix = 'money' === format ? '$ ' : '';
		var suffix = ( 'money' !== format && unit ) ? ' ' + unit : '';
		if ( compact ) {
			// Millions → e.g. "$1.3M" (one decimal only when not whole).
			if ( n >= 1000000 ) {
				var m = n / 1000000;
				return prefix + ( m % 1 ? m.toFixed( 1 ) : m.toFixed( 0 ) ) + 'M' + suffix;
			}
			// Thousands → e.g. "900K".
			if ( n >= 1000 ) {
				return prefix + Math.round( n / 1000 ) + 'K' + suffix;
			}
		}
		// Default: grouped full number, e.g. "1,234,567".
		return prefix + n.toLocaleString( 'en-US' ) + suffix;
	}

	/** Parse "$1,234" / "1,234 ft²" / "1985" to an integer; NaN-safe → 0. */
	function digits( s ) {
		// Strip every non-digit, then parse; treat NaN as 0.
		var n = parseInt( String( s ).replace( /[^0-9]/g, '' ), 10 );
		return isNaN( n ) ? 0 : n;
	}

	/** A readable slider step: ~1% of the range, snapped to a round figure. */
	function niceStep( span ) {
		// Target roughly 1% of the span.
		var raw = span / 100;
		if ( raw <= 1 ) {
			return 1;
		}
		// Snap to the nearest power-of-ten multiple for a round step value.
		var pow = Math.pow( 10, Math.floor( Math.log( raw ) / Math.LN10 ) );
		return Math.max( 1, Math.round( raw / pow ) * pow );
	}

	// Shared dismissal: every enhanced component registers its wrapper + close
	// callback here, and ONE set of document listeners closes every open component
	// except the one being interacted with. Using pointerdown/focusin (not click)
	// and containment via .closest() means opening another popup, clicking another
	// field, or tabbing away all auto-close any open popup — even when a toggle or
	// control stops click propagation.
	var dismissables = [];
	var dismissWired = false;

	/** Register a component (its wrapper + close) with the shared dismiss listeners. */
	function registerDismiss( root, close ) {
		// Track this component so the shared listeners can close it.
		dismissables.push( { root: root, close: close } );
		// Wire the document-level listeners exactly once, on first registration.
		if ( dismissWired ) {
			return;
		}
		dismissWired = true;

		// Close every registered popup except the one containing the event target.
		function closeOthers( e ) {
			var inside = e.target && e.target.closest ? e.target.closest( '.mlsimport-range, .mlsimport-bedsbaths' ) : null;
			dismissables.forEach( function ( c ) {
				if ( c.root !== inside ) {
					c.close();
				}
			} );
		}
		// Pointer and focus moving elsewhere both dismiss other open popups.
		document.addEventListener( 'pointerdown', closeOthers );
		document.addEventListener( 'focusin', closeOthers );
		// Escape closes every open popup.
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				dismissables.forEach( function ( c ) {
					c.close();
				} );
			}
		} );
	}

	/**
	 * Enhance one .mlsimport-range component (price, living area, lot size, year
	 * built — distinguished only by data-format / data-unit on the wrapper).
	 *
	 * @param {HTMLElement} root The .mlsimport-range wrapper.
	 */
	function enhanceRange( root ) {
		// Idempotency guard: never enhance the same wrapper twice.
		if ( root.dataset.mliInit ) {
			return;
		}
		root.dataset.mliInit = '1';

		// Grab every sub-element of the range component.
		var toggle  = root.querySelector( '.mlsimport-range__toggle' );
		var slider  = root.querySelector( '.mlsimport-range__slider' );
		var range   = root.querySelector( '.mlsimport-range__range' );
		var hMin    = root.querySelector( '.mlsimport-range__handle--min' );
		var hMax    = root.querySelector( '.mlsimport-range__handle--max' );
		var dMin    = root.querySelector( '.mlsimport-range__display--min' );
		var dMax    = root.querySelector( '.mlsimport-range__display--max' );
		var vMin    = root.querySelector( '.mlsimport-range__value-min' );
		var vMax    = root.querySelector( '.mlsimport-range__value-max' );
		// Abort if the essential pieces are missing.
		if ( ! toggle || ! slider || ! vMin || ! vMax ) {
			return;
		}

		// Display format ('money'|'number'|'year') and unit label for this column.
		var format = root.getAttribute( 'data-format' ) || 'number';
		var unit   = root.getAttribute( 'data-unit' ) || '';

		// Rail bounds and step, read from the slider (step falls back to a computed nice step).
		var min  = digits( slider.getAttribute( 'data-min' ) );
		var max  = digits( slider.getAttribute( 'data-max' ) ) || ( min + 1 );
		var step = digits( slider.getAttribute( 'data-step' ) ) || niceStep( max - min );

		// Current selection: hidden values when set, else the full span.
		var lo = '' !== vMin.value ? digits( vMin.value ) : min;
		var hi = '' !== vMax.value ? digits( vMax.value ) : max;

		// Constrain a value to the rail bounds.
		function clamp( v ) {
			return Math.min( max, Math.max( min, v ) );
		}
		// Snap a value to the nearest step increment.
		function snap( v ) {
			return Math.round( v / step ) * step;
		}
		// Convert a value to a 0–100 percentage position along the rail.
		function pct( v ) {
			return ( ( v - min ) / ( max - min ) ) * 100;
		}

		/** Repaint handles, range fill, min/max inputs, hidden values and the toggle. */
		function paint() {
			// Position both handles and the fill bar between them.
			hMin.style.left  = pct( lo ) + '%';
			hMax.style.left  = pct( hi ) + '%';
			range.style.left  = pct( lo ) + '%';
			range.style.width = ( pct( hi ) - pct( lo ) ) + '%';

			// Mirror the current values into the editable min/max display inputs.
			dMin.value = formatRange( lo, format, unit, false );
			dMax.value = formatRange( hi, format, unit, false );

			// Untouched edges submit empty so the filter is not applied.
			vMin.value = lo > min ? String( lo ) : '';
			vMax.value = hi < max ? String( hi ) : '';

			// Toggle label shows the compact range when narrowed, else the default text.
			var narrowed = ( lo > min || hi < max );
			toggle.textContent = narrowed ? formatRange( lo, format, unit, true ) + ' – ' + formatRange( hi, format, unit, true ) : ( toggle.getAttribute( 'data-default' ) || 'Any' );
			// Flag the untouched state so CSS can grey the placeholder text the way the
			// multiselect greys its own "Any" — without this the label rendered in full
			// --mli-text and Price looked filled in next to an empty ZIP Code.
			toggle.classList.toggle( 'is-empty', ! narrowed );
		}

		/** Value under a pointer/touch X, relative to the slider rail. */
		function valueAt( clientX ) {
			// Convert the pointer X into a 0–1 ratio across the rail, then to a snapped value.
			var rect = slider.getBoundingClientRect();
			var ratio = ( clientX - rect.left ) / ( rect.width || 1 );
			return clamp( snap( min + ratio * ( max - min ) ) );
		}

		/** Wire pointer-drag and keyboard control for one handle. */
		function drag( handle, isMin ) {
			// Begin dragging: capture the pointer so moves keep tracking outside the handle.
			handle.addEventListener( 'pointerdown', function ( e ) {
				e.preventDefault();
				handle.setPointerCapture( e.pointerId );

				// While dragging, update the near edge without crossing the far edge.
				function move( ev ) {
					var v = valueAt( ev.clientX );
					if ( isMin ) {
						lo = Math.min( v, hi );
					} else {
						hi = Math.max( v, lo );
					}
					paint();
				}
				// Release: drop the capture and detach the move/up listeners.
				function up( ev ) {
					handle.releasePointerCapture( e.pointerId );
					handle.removeEventListener( 'pointermove', move );
					handle.removeEventListener( 'pointerup', up );
				}
				handle.addEventListener( 'pointermove', move );
				handle.addEventListener( 'pointerup', up );
			} );

			// Keyboard: arrows nudge the handle by one step, clamped against the other edge.
			handle.addEventListener( 'keydown', function ( e ) {
				var d = ( 'ArrowRight' === e.key || 'ArrowUp' === e.key ) ? step : ( ( 'ArrowLeft' === e.key || 'ArrowDown' === e.key ) ? -step : 0 );
				if ( ! d ) {
					return;
				}
				e.preventDefault();
				if ( isMin ) {
					lo = clamp( Math.min( lo + d, hi ) );
				} else {
					hi = clamp( Math.max( hi + d, lo ) );
				}
				paint();
			} );
		}
		// Activate both handles.
		drag( hMin, true );
		drag( hMax, false );

		/** Read the editable display inputs back into lo/hi, swapping if inverted. */
		function commitDisplay() {
			lo = clamp( digits( dMin.value ) );
			hi = clamp( digits( dMax.value ) );
			// Keep lo ≤ hi even if the user typed them the wrong way round.
			if ( lo > hi ) {
				var t = lo; lo = hi; hi = t;
			}
			paint();
		}
		dMin.addEventListener( 'change', commitDisplay );
		dMax.addEventListener( 'change', commitDisplay );

		// Open the popup (repaint so handle positions use the now-visible rail width).
		function open() {
			root.classList.add( 'is-open' );
			toggle.setAttribute( 'aria-expanded', 'true' );
			paint(); // rail has width now that the popup is visible.
		}
		// Close the popup.
		function close() {
			root.classList.remove( 'is-open' );
			toggle.setAttribute( 'aria-expanded', 'false' );
		}
		// Toggle button flips open/closed (stop propagation so the shared dismiss doesn't fire).
		toggle.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			root.classList.contains( 'is-open' ) ? close() : open();
		} );
		// "Done" closes; "Reset" restores the full span.
		root.querySelector( '.mlsimport-range__done' ).addEventListener( 'click', close );
		root.querySelector( '.mlsimport-range__reset' ).addEventListener( 'click', function () {
			lo = min;
			hi = max;
			paint();
		} );
		// Register with the shared outside-click/Escape dismissal.
		registerDismiss( root, close );

		// Initial paint.
		paint();
	}

	/**
	 * Enhance one .mlsimport-bedsbaths component — a single popup with a Beds row and
	 * a Baths row of "N+" minimum tiles, each feeding its own hidden input.
	 *
	 * @param {HTMLElement} root The .mlsimport-bedsbaths wrapper.
	 */
	function enhanceBedsBaths( root ) {
		// Idempotency guard: never enhance the same wrapper twice.
		if ( root.dataset.mliInit ) {
			return;
		}
		root.dataset.mliInit = '1';

		// The toggle button and popup body are both required.
		var toggle = root.querySelector( '.mlsimport-bedsbaths__toggle' );
		var popup  = root.querySelector( '.mlsimport-bedsbaths__popup' );
		if ( ! toggle || ! popup ) {
			return;
		}

		// group ('beds'/'baths') => its hidden input.
		var values = {};
		Array.prototype.forEach.call( root.querySelectorAll( '.mlsimport-bedsbaths__value' ), function ( inp ) {
			values[ inp.getAttribute( 'data-group' ) ] = inp;
		} );

		/** Sync tile selection classes and rebuild the toggle label from hidden values. */
		function paint() {
			// Mark the tile matching each group's hidden value as selected.
			Array.prototype.forEach.call( root.querySelectorAll( '.mlsimport-bedsbaths__grid' ), function ( grid ) {
				var inp = values[ grid.getAttribute( 'data-group' ) ];
				var v   = inp ? inp.value : '';
				Array.prototype.forEach.call( grid.querySelectorAll( '.mlsimport-bedsbaths__item' ), function ( it ) {
					it.classList.toggle( 'is-selected', it.getAttribute( 'data-value' ) === v && '' !== v );
				} );
			} );

			// Build the toggle label from whichever of beds/baths are set.
			var parts = [];
			if ( values.beds && values.beds.value ) {
				parts.push( values.beds.value + '+ bd' );
			}
			if ( values.baths && values.baths.value ) {
				parts.push( values.baths.value + '+ ba' );
			}
			toggle.textContent = parts.length ? parts.join( ' · ' ) : ( toggle.getAttribute( 'data-default' ) || 'Beds | Baths' );
			// See the range toggle: grey the untouched label to match the multiselect.
			toggle.classList.toggle( 'is-empty', ! parts.length );
		}

		// Wire each tile: clicking sets its group's minimum, clicking again clears it.
		Array.prototype.forEach.call( root.querySelectorAll( '.mlsimport-bedsbaths__grid' ), function ( grid ) {
			var group = grid.getAttribute( 'data-group' );
			Array.prototype.forEach.call( grid.querySelectorAll( '.mlsimport-bedsbaths__item' ), function ( it ) {
				it.addEventListener( 'click', function () {
					var inp = values[ group ];
					if ( ! inp ) {
						return;
					}
					var v = it.getAttribute( 'data-value' );
					inp.value = ( inp.value === v ) ? '' : v; // toggle off when re-clicked.
					paint();
				} );
			} );
		} );

		// Close the popup.
		function close() {
			root.classList.remove( 'is-open' );
			toggle.setAttribute( 'aria-expanded', 'false' );
		}
		// Toggle button flips open/closed (stop propagation so the shared dismiss doesn't fire).
		toggle.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			root.classList.toggle( 'is-open' );
			toggle.setAttribute( 'aria-expanded', root.classList.contains( 'is-open' ) ? 'true' : 'false' );
		} );
		// "Done" closes; "Reset" clears every group's hidden value.
		root.querySelector( '.mlsimport-bedsbaths__done' ).addEventListener( 'click', close );
		root.querySelector( '.mlsimport-bedsbaths__reset' ).addEventListener( 'click', function () {
			Object.keys( values ).forEach( function ( k ) {
				values[ k ].value = '';
			} );
			paint();
		} );
		// Register with the shared outside-click/Escape dismissal.
		registerDismiss( root, close );

		// Initial paint.
		paint();
	}

	/**
	 * Enhance every un-initialized popup component under a root (default: document).
	 *
	 * @param {ParentNode} [root] Scope to search.
	 */
	function init( root ) {
		// Default to the whole document when no scope is given.
		var scope = root || document;
		// Enhance every range and beds/baths component found in scope.
		Array.prototype.forEach.call( scope.querySelectorAll( '[data-mlsimport-range]' ), enhanceRange );
		Array.prototype.forEach.call( scope.querySelectorAll( '[data-mlsimport-bedsbaths]' ), enhanceBedsBaths );
	}

	/**
	 * Enhance now, then watch for components added later — a block editor preview
	 * injects markup asynchronously (and inside the editor iframe). Both enhancers
	 * are idempotent, so re-touching existing nodes is a no-op.
	 */
	function start() {
		// Enhance what's already in the DOM.
		init();
		// Without MutationObserver support there's nothing more to watch.
		if ( ! window.MutationObserver || ! document.body ) {
			return;
		}
		// Watch for later-injected components and enhance them as they land.
		new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( m ) {
				Array.prototype.forEach.call( m.addedNodes, function ( node ) {
					// Only element nodes can hold components.
					if ( 1 !== node.nodeType ) {
						return;
					}
					// The node may itself be a component, or merely contain some.
					if ( node.matches && node.matches( '[data-mlsimport-range]' ) ) {
						enhanceRange( node );
					} else if ( node.matches && node.matches( '[data-mlsimport-bedsbaths]' ) ) {
						enhanceBedsBaths( node );
					} else if ( node.querySelectorAll ) {
						init( node );
					}
				} );
			} );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	// Defer start until the DOM is ready; run immediately if it already is.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	// Expose init() so callers can enhance markup added after page load.
	window.mlsimportSearchPopupsInit = init;
}() );
