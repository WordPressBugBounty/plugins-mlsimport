/**
 * MLSImport standalone (theme_id 990) front-end filtering.
 * Submits the search form to admin-ajax, repaints the results grid + pager
 * without a page reload. No dependencies. Config is localized as MLSImportListings.
 */
( function () {
	'use strict';

	// Bail entirely if the localized config never got printed.
	if ( typeof window.MLSImportListings === 'undefined' ) {
		return;
	}

	// Localized config: { action, nonce, ajaxurl }.
	var cfg = window.MLSImportListings;

	/**
	 * forEach helper that works on array-like NodeLists.
	 * @param {ArrayLike} list Items to iterate.
	 * @param {Function}  fn   Callback per item.
	 */
	function each( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	}

	// FormData captures every selected option of a multi-select; a name ending in []
	// (e.g. state[]) is grouped under its base key (state) as an array so toBody can
	// re-emit state[]=a&state[]=b — without this only the first option would be sent.
	/**
	 * Gather the AJAX request params from the search form, page, and sort control.
	 * @param {HTMLFormElement} form       The search form (may be null).
	 * @param {number}          page       1-based page number.
	 * @param {HTMLSelectElement} sortSelect Toolbar sort control (outside the form).
	 * @return {Object} Param map (arrays for []-suffixed multi fields).
	 */
	function collectParams( form, page, sortSelect ) {
		// Seed with the AJAX envelope.
		var params = {
			action: cfg.action,
			nonce: cfg.nonce,
			page: page || 1
		};
		if ( form ) {
			// Fold each form control into params, mirroring half-map's formFilters.
			new FormData( form ).forEach( function ( value, name ) {
				// Skip empty values and the envelope keys.
				if ( value === '' || name === 'action' || name === 'nonce' || name === 'page' ) {
					return;
				}
				// Base key + whether this was a []-suffixed multi field.
				var key   = name.replace( /\[\]$/, '' );
				var multi = key !== name;
				// Merge repeated keys into an array; else set scalar / single-item array.
				if ( Object.prototype.hasOwnProperty.call( params, key ) ) {
					if ( ! Array.isArray( params[ key ] ) ) {
						params[ key ] = [ params[ key ] ];
					}
					params[ key ].push( value );
				} else {
					params[ key ] = multi ? [ value ] : value;
				}
			} );
		}
		// The Sort control sits in the results toolbar, OUTSIDE the search form, so
		// FormData( form ) never captures it — read it in so refine/paginate keep the sort.
		if ( sortSelect && sortSelect.value !== '' ) {
			params.orderby = sortSelect.value;
		}
		return params;
	}

	/**
	 * Serialize a param map to an application/x-www-form-urlencoded body.
	 * @param {Object} params Param map (values may be arrays).
	 * @return {string} Encoded body string.
	 */
	function toBody( params ) {
		var parts = [];
		Object.keys( params ).forEach( function ( k ) {
			var v = params[ k ];
			// Arrays re-emit as repeated key[]=item pairs.
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) {
					parts.push( encodeURIComponent( k + '[]' ) + '=' + encodeURIComponent( item ) );
				} );
			} else {
				parts.push( encodeURIComponent( k ) + '=' + encodeURIComponent( v ) );
			}
		} );
		return parts.join( '&' );
	}

	// One skeleton card mirrors the real card's box (.mlsimport-listing-card) so the
	// grid keeps its exact columns and sizing while cards load; the media block and
	// text lines shimmer (styled in mlsimport-listings.css).
	/**
	 * @param {number} count How many skeleton cards to build.
	 * @return {string} Concatenated skeleton-card markup.
	 */
	function skeletonCards( count ) {
		// Markup for a single shimmering placeholder card.
		var one = '<article class="mlsimport-listing-card mlsimport-skeleton-card" aria-hidden="true">' +
			'<div class="mlsimport-listing-card__media mlsimport-skeleton__box"></div>' +
			'<div class="mlsimport-listing-card__body">' +
			'<span class="mlsimport-skeleton__line mlsimport-skeleton__line--price"></span>' +
			'<span class="mlsimport-skeleton__line mlsimport-skeleton__line--title"></span>' +
			'<span class="mlsimport-skeleton__line mlsimport-skeleton__line--specs"></span>' +
			'</div></article>';
		// Repeat the single card `count` times.
		var out = '';
		for ( var i = 0; i < count; i++ ) {
			out += one;
		}
		return out;
	}

	// Show as many placeholders as cards currently on screen so the grid height
	// doesn't jump; on a first load with no cards yet, fall back to the page size.
	/**
	 * @param {Element}         grid The results grid (may be null).
	 * @param {HTMLFormElement} form The search form (read for the page limit).
	 * @return {number} Skeleton count, capped at 24.
	 */
	function skeletonCount( grid, form ) {
		// Prefer matching the number of cards already rendered.
		var existing = grid ? grid.querySelectorAll( '.mlsimport-listing-card' ).length : 0;
		if ( existing > 0 ) {
			return Math.min( existing, 24 );
		}
		// First load: fall back to the form's limit, else a small default of 6.
		var limitEl = form ? form.querySelector( '[name="limit"]' ) : null;
		var n = limitEl ? parseInt( limitEl.value, 10 ) : 0;
		return n > 0 ? Math.min( n, 24 ) : 6;
	}

	/**
	 * Paint the server response into the grid, result meta, and pager.
	 * @param {Element} root The .mlsimport-listings block root.
	 * @param {Object}  data Response payload ({ html, total, pager }).
	 */
	function render( root, data ) {
		// Locate the three output regions.
		var grid = root.querySelector( '.mlsimport-results__grid' );
		var meta = root.querySelector( '.mlsimport-results__meta' );
		var pager = root.querySelector( '.mlsimport-results__pager' );
		if ( grid ) {
			// Cards markup, or an empty-state message.
			grid.innerHTML = data.html || '<p class="mlsimport-results__empty">No listings match your search.</p>';
			// Let listeners (e.g. favorites hydration) re-process the fresh cards.
			grid.dispatchEvent( new CustomEvent( 'mlsimport:cards-rendered', { bubbles: true } ) );
		}
		if ( meta ) {
			// Result count with singular/plural "result(s)".
			meta.textContent = ( data.total || 0 ) + ' result' + ( 1 === data.total ? '' : 's' );
		}
		if ( pager ) {
			pager.innerHTML = data.pager || '';
		}
	}

	/**
	 * Fire the AJAX search and repaint the block with skeletons in the interim.
	 * @param {Element}         root The block root.
	 * @param {HTMLFormElement} form The search form.
	 * @param {number}          page 1-based page to fetch.
	 */
	function request( root, form, page ) {
		// Results wrapper, grid, and (out-of-form) sort control.
		var results    = root.querySelector( '.mlsimport-results' );
		var grid       = root.querySelector( '.mlsimport-results__grid' );
		var sortSelect = root.querySelector( '.mlsimport-results__toolbar select[name="orderby"]' );
		// Flag the block as loading (for CSS state).
		if ( results ) {
			results.classList.add( 'is-loading' );
		}
		// Swap real cards for shimmering skeletons for the duration of the fetch
		// (count read before the swap, so it reflects what was on screen).
		if ( grid ) {
			grid.innerHTML = skeletonCards( skeletonCount( grid, form ) );
		}

		// Build and send the urlencoded POST.
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', cfg.ajaxurl );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		// Failure paths must clear the skeletons too, or they shimmer forever.
		function fail() {
			if ( grid ) {
				grid.innerHTML = '<p class="mlsimport-results__empty">Could not load listings. Please try again.</p>';
			}
		}
		// On response: clear loading, parse JSON, render on success else fail.
		xhr.onload = function () {
			if ( results ) {
				results.classList.remove( 'is-loading' );
			}
			try {
				var res = JSON.parse( xhr.responseText );
				if ( res && res.success ) {
					render( root, res.data );
				} else {
					fail();
				}
			} catch ( e ) {
				fail();
			}
		};
		// On transport error: clear loading and show the failure message.
		xhr.onerror = function () {
			if ( results ) {
				results.classList.remove( 'is-loading' );
			}
			fail();
		};
		// Encode all params and send.
		xhr.send( toBody( collectParams( form, page, sortSelect ) ) );
	}

	/**
	 * Wire one listings block: submit, sort change, and pager clicks.
	 * @param {Element} root The .mlsimport-listings block root.
	 */
	function init( root ) {
		// The search form and the toolbar sort control.
		var form = root.querySelector( '.mlsimport-search' );
		var sort = root.querySelector( '.mlsimport-results__toolbar select[name="orderby"]' );

		// Form submit refines from page 1 (no reload).
		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				request( root, form, 1 );
			} );
		}

		// The toolbar Sort is not inside the form, so a form submit is neither required nor
		// intuitive above the results — changing it re-sorts (repaints from page 1) at once.
		if ( sort ) {
			sort.addEventListener( 'change', function () {
				request( root, form, 1 );
			} );
		}

		// Delegated pager clicks fetch the requested page.
		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.mlsimport-pager__btn' ) : null;
			if ( btn && btn.dataset.page ) {
				e.preventDefault();
				request( root, form, parseInt( btn.dataset.page, 10 ) || 1 );
			}
		} );
	}

	// Initialise every listings block once the DOM is ready.
	document.addEventListener( 'DOMContentLoaded', function () {
		each( document.querySelectorAll( '.mlsimport-listings' ), init );
	} );
}() );
