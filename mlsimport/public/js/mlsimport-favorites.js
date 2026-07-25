/**
 * Standalone property favorites — one module driving both surfaces (the single-
 * property Save button and the listing-card hearts) off one shared store.
 *
 * A favorite is a { k: ListingKey, p: postId } pair, deduped by k. Source of truth:
 *   - anonymous: localStorage (authoritative; the toggle is a pure client write)
 *   - logged-in: user meta on the server (each toggle is a nonce'd AJAX write); the
 *     server seeds MLSImportFav.list so we can flip saved hearts with no round-trip.
 * On login the anon localStorage list is merged up into user meta, then cleared, so
 * there is exactly one source of truth per auth state.
 *
 * Hydration is uniform: the server always prints hearts "unsaved"; this module flips
 * the saved ones — on load, and again after every AJAX grid repaint (it listens for
 * the `mlsimport:cards-rendered` event the listings/half-map grids dispatch).
 *
 * Config (wp_localize_script): MLSImportFav = { ajaxurl, nonce, loggedIn, list,
 * i18n:{ save, saved } }. No jQuery.
 */
( function () {
	'use strict';

	// Config injected via wp_localize_script; fall back to inert defaults if absent.
	var CFG = window.MLSImportFav || { ajaxurl: '', nonce: '', loggedIn: false, list: [], i18n: {} };
	// localStorage key holding the anonymous favorites list.
	var STORE_KEY = 'mlsimport_favorites';
	// Hard cap on stored favorites.
	var MAX = 500;
	// Localised UI strings ({ save, saved }).
	var I18N = CFG.i18n || {};

	// In-memory authoritative list of { k, p } for this page + a key lookup set.
	var state = [];
	var keys = Object.create( null );

	/* ------------------------------------------------------------------ *
	 * Store
	 * ------------------------------------------------------------------ */

	/**
	 * Read + parse the anonymous favorites list from localStorage.
	 * @return {Array} Parsed list, or [] on any error / missing data.
	 */
	function readLocal() {
		try {
			// Read the raw JSON string (may be null if never written).
			var raw = window.localStorage.getItem( STORE_KEY );
			// Parse it, defaulting to an empty array.
			var arr = raw ? JSON.parse( raw ) : [];
			// Guard against non-array payloads.
			return Array.isArray( arr ) ? arr : [];
		} catch ( e ) {
			// Storage disabled or malformed JSON — behave as empty.
			return [];
		}
	}

	/**
	 * Persist the current in-memory state to localStorage (anonymous store).
	 */
	function writeLocal() {
		try {
			window.localStorage.setItem( STORE_KEY, JSON.stringify( state ) );
		} catch ( e ) {
			/* storage full / disabled — the in-memory state still works for this page. */
		}
	}

	/**
	 * Remove the anonymous favorites list from localStorage (used after login merge).
	 */
	function clearLocal() {
		try {
			window.localStorage.removeItem( STORE_KEY );
		} catch ( e ) {}
	}

	// Normalise any list to unique { k, p } (dedupe by k, cap at MAX).
	/**
	 * @param {Array} list Raw favorites entries.
	 * @return {Array} Cleaned list of unique { k:string, p:int } up to MAX.
	 */
	function normalise( list ) {
		var out = [];
		var seen = Object.create( null );
		// Walk the input, stopping once MAX unique entries are collected.
		for ( var i = 0; i < list.length && out.length < MAX; i++ ) {
			var e = list[ i ];
			// Skip anything that isn't an object entry.
			if ( ! e || typeof e !== 'object' ) {
				continue;
			}
			// Listing key is the dedupe identity; coerce to string.
			var k = e.k ? String( e.k ) : '';
			// Drop empty or already-seen keys.
			if ( ! k || seen[ k ] ) {
				continue;
			}
			seen[ k ] = true;
			// Store with a normalised integer post id (0 when unknown).
			out.push( { k: k, p: e.p ? parseInt( e.p, 10 ) || 0 : 0 } );
		}
		return out;
	}

	/**
	 * Replace the in-memory state and rebuild the key lookup set.
	 * @param {Array} list Raw favorites entries to normalise into state.
	 */
	function setState( list ) {
		state = normalise( list );
		keys = Object.create( null );
		// Index every stored key for O(1) has() lookups.
		for ( var i = 0; i < state.length; i++ ) {
			keys[ state[ i ].k ] = true;
		}
	}

	/**
	 * @param {string} k Listing key.
	 * @return {boolean} Whether the key is currently favorited.
	 */
	function has( k ) {
		return !! keys[ k ];
	}

	/**
	 * Add a favorite (prepended) if not already present.
	 * @param {string} k Listing key.
	 * @param {number} p Post id.
	 */
	function add( k, p ) {
		// No-op if already favorited.
		if ( has( k ) ) {
			return;
		}
		// Newest first, and index the key.
		state.unshift( { k: k, p: p || 0 } );
		keys[ k ] = true;
	}

	/**
	 * Remove a favorite by key if present.
	 * @param {string} k Listing key.
	 */
	function remove( k ) {
		// No-op if not favorited.
		if ( ! has( k ) ) {
			return;
		}
		// Filter it out of state and drop its index entry.
		state = state.filter( function ( e ) {
			return e.k !== k;
		} );
		delete keys[ k ];
	}

	/* ------------------------------------------------------------------ *
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * POST a form-encoded admin-ajax request and resolve its JSON.
	 * @param {string} action WP ajax action name.
	 * @param {Object} params Extra body params.
	 * @param {string} [nonce] Nonce override (defaults to CFG.nonce).
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	function post( action, params, nonce ) {
		// Build the urlencoded body: action + nonce + caller params.
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', nonce || CFG.nonce );
		Object.keys( params || {} ).forEach( function ( key ) {
			body.set( key, params[ key ] );
		} );
		// Send credentialed so the WP auth cookie rides along.
		return fetch( CFG.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Controls (hydration + click)
	 * ------------------------------------------------------------------ */

	// Reflect a single control's saved state (class, aria, optional label).
	/**
	 * @param {Element} btn  Favorite control element.
	 * @param {boolean} saved Whether the listing is favorited.
	 */
	function paint( btn, saved ) {
		btn.classList.toggle( 'is-saved', saved );
		btn.setAttribute( 'aria-pressed', saved ? 'true' : 'false' );
		var label = btn.querySelector( '[data-fav-label]' );
		if ( label ) {
			label.textContent = saved ? ( I18N.saved || 'Saved' ) : ( I18N.save || 'Save' );
		}
	}

	// Flip every control for a key across the page (card + single page in lockstep).
	/**
	 * @param {string} k Listing key to repaint everywhere it appears.
	 */
	function paintKey( k ) {
		// Current saved state for the key.
		var saved = has( k );
		// Every control bound to this listing key.
		var nodes = document.querySelectorAll( '[data-mlsimport-fav][data-listing-key="' + cssEscape( k ) + '"]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			paint( nodes[ i ], saved );
		}
	}

	// Flip all controls inside a scope (default = document) from the store.
	/**
	 * @param {Element} [scope] Root to search; defaults to document.
	 */
	function hydrate( scope ) {
		// Use the given scope only if it can query; else the whole document.
		var root = scope && scope.querySelectorAll ? scope : document;
		var nodes = root.querySelectorAll( '[data-mlsimport-fav]' );
		// Paint each control from the store's current state.
		for ( var i = 0; i < nodes.length; i++ ) {
			var k = nodes[ i ].getAttribute( 'data-listing-key' ) || '';
			paint( nodes[ i ], k ? has( k ) : false );
		}
	}

	/**
	 * Escape a value for safe use inside an attribute-value CSS selector.
	 * @param {string} v Raw value.
	 * @return {string} Escaped value (native CSS.escape when available).
	 */
	function cssEscape( v ) {
		// Prefer the native implementation.
		if ( window.CSS && window.CSS.escape ) {
			return window.CSS.escape( v );
		}
		// Fallback: escape quotes and backslashes only.
		return String( v ).replace( /["\\]/g, '\\$&' );
	}

	/**
	 * Handle a click on a favorite control: optimistic flip, then persist.
	 * @param {Element} btn The clicked favorite control.
	 */
	function onToggle( btn ) {
		// Listing key + post id carried on the control.
		var k = btn.getAttribute( 'data-listing-key' ) || '';
		var p = parseInt( btn.getAttribute( 'data-id' ) || '0', 10 ) || 0;
		// Nothing to toggle without a key.
		if ( ! k ) {
			return;
		}
		// Remember prior state to know the intended operation and how to revert.
		var wasSaved = has( k );

		// Optimistic: flip the store + every control for this key immediately.
		if ( wasSaved ) {
			remove( k );
		} else {
			add( k, p );
		}
		paintKey( k );

		if ( CFG.loggedIn ) {
			// User meta is authoritative; persist and revert on failure.
			post( 'mlsimport_fav_toggle', { op: wasSaved ? 'remove' : 'save', k: k, p: p } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						throw new Error( 'toggle failed' );
					}
				} )
				.catch( function () {
					if ( wasSaved ) {
						add( k, p );
					} else {
						remove( k );
					}
					paintKey( k );
				} );
		} else {
			// Anonymous: localStorage is the store.
			writeLocal();
		}

		// If we just un-saved a card inside the saved view, drop it from that list.
		// Removing the node is only the optimistic half: the pager still carries the
		// old total and the slot the card vacated should be backfilled from the next
		// page. Re-load the current page so both follow. The store was already updated
		// above and loadSaved posts it, so this needs no wait on the toggle write.
		if ( wasSaved ) {
			var savedView = btn.closest( '[data-mlsimport-saved]' );
			if ( savedView ) {
				var card = btn.closest( '.mlsimport-listing-card' );
				if ( card ) {
					card.parentNode.removeChild( card );
				}
				reflectSavedEmptiness( savedView );
				loadSaved( savedView, currentSavedPage( savedView ) );
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * Merge on login
	 * ------------------------------------------------------------------ */

	/**
	 * On first logged-in load, push any anonymous localStorage favorites into
	 * user meta, adopt the merged server list, then clear local storage.
	 * @return {Promise<boolean>} True if a merge happened.
	 */
	function mergeOnLogin() {
		// Nothing to merge if the anon store is empty.
		var local = readLocal();
		if ( ! local.length ) {
			return Promise.resolve( false );
		}
		// Send the anon list; server returns the authoritative merged list.
		return post( 'mlsimport_fav_merge', { list: JSON.stringify( local ) } )
			.then( function ( res ) {
				if ( res && res.success && res.data && Array.isArray( res.data.list ) ) {
					setState( res.data.list );
				}
				clearLocal();
				hydrate( document );
				return true;
			} )
			.catch( function () {
				return false;
			} );
	}

	/* ------------------------------------------------------------------ *
	 * Saved view ("My saved properties")
	 * ------------------------------------------------------------------ */

	// Get a nonce that a full-page cache can't have staled: logged-in pages aren't
	// cached (bootstrap nonce is fine); anonymous saved views mint a fresh one.
	function freshNonce() {
		if ( CFG.loggedIn ) {
			return Promise.resolve( CFG.nonce );
		}
		return post( 'mlsimport_fav_nonce', {} )
			.then( function ( res ) {
				return res && res.success && res.data && res.data.nonce ? res.data.nonce : CFG.nonce;
			} )
			.catch( function () {
				return CFG.nonce;
			} );
	}

	/**
	 * Toggle the "no saved properties" empty-state message for a saved view.
	 * @param {Element} container The saved-view root element.
	 */
	function reflectSavedEmptiness( container ) {
		// Grid and empty-message nodes within the view.
		var grid = container.querySelector( '[data-mlsimport-saved-grid]' );
		var empty = container.querySelector( '[data-mlsimport-saved-empty]' );
		// Empty when there's no grid or it holds no cards.
		var isEmpty = ! grid || ! grid.querySelector( '.mlsimport-listing-card' );
		if ( empty ) {
			empty.hidden = ! isEmpty;
		}
	}

	/**
	 * Read a saved view's page size.
	 * @param {Element} container The saved-view root element.
	 * @return {number} Cards per page (defaults to 12).
	 */
	function savedPerPage( container ) {
		return parseInt( container.getAttribute( 'data-per-page' ) || '12', 10 ) || 12;
	}

	/**
	 * The page a saved view should show now, clamped to the page count the current
	 * store implies — so unsaving the last card on the last page falls back a page
	 * instead of requesting one that no longer exists.
	 * @param {Element} container The saved-view root element.
	 * @return {number} 1-based page number.
	 */
	function currentSavedPage( container ) {
		var page  = parseInt( container.getAttribute( 'data-current-page' ) || '1', 10 ) || 1;
		var pages = Math.max( 1, Math.ceil( state.length / savedPerPage( container ) ) );
		return Math.min( Math.max( 1, page ), pages );
	}

	/**
	 * Fetch and render one page of saved-property cards for a saved view.
	 * @param {Element} container The saved-view root element.
	 * @param {number}  page      1-based page number.
	 */
	function loadSaved( container, page ) {
		// Page size + the view's loading / empty / grid / pager nodes.
		var perPage = savedPerPage( container );

		// Remember what page this view is showing, so a later unsave can re-render
		// the same page rather than silently snapping back to page 1.
		page = page || 1;
		container.setAttribute( 'data-current-page', String( page ) );
		var loading = container.querySelector( '[data-mlsimport-saved-loading]' );
		var empty = container.querySelector( '[data-mlsimport-saved-empty]' );
		var grid = container.querySelector( '[data-mlsimport-saved-grid]' );
		var pager = container.querySelector( '[data-mlsimport-saved-pager]' );

		// No favorites at all: show empty, clear grid/pager, and bail.
		if ( ! state.length ) {
			if ( loading ) {
				loading.hidden = true;
			}
			if ( empty ) {
				empty.hidden = false;
			}
			if ( grid ) {
				grid.innerHTML = '';
			}
			if ( pager ) {
				pager.innerHTML = '';
			}
			return;
		}

		// Show the loading indicator and hide the empty message while fetching.
		if ( loading ) {
			loading.hidden = false;
		}
		if ( empty ) {
			empty.hidden = true;
		}

		// Mint a cache-safe nonce, then request the page of saved cards.
		freshNonce().then( function ( nonce ) {
			return post( 'mlsimport_saved_cards', {
				list: JSON.stringify( state ),
				page: page || 1,
				per_page: perPage
			}, nonce );
		} ).then( function ( res ) {
			// Fetch settled: hide the loading indicator.
			if ( loading ) {
				loading.hidden = true;
			}
			// Bail on a failed / empty response — but reveal the empty message first,
			// so the view shows a clear "no saved properties" state instead of sitting
			// blank under a now-hidden loading line.
			if ( ! res || ! res.success || ! res.data ) {
				if ( empty ) {
					empty.hidden = false;
				}
				return;
			}
			var data = res.data;

			// Prune off-market entries the resolver confirmed gone (two-key miss),
			// then persist the prune to the authoritative store: localStorage for
			// anon, or a remove write per key for logged-in (reuses the toggle
			// writer — no read endpoint doing writes, boundary stays clean).
			if ( Array.isArray( data.prune ) && data.prune.length ) {
				data.prune.forEach( function ( k ) {
					remove( k );
					if ( CFG.loggedIn ) {
						post( 'mlsimport_fav_toggle', { op: 'remove', k: k, p: 0 } );
					}
				} );
				if ( ! CFG.loggedIn ) {
					writeLocal();
				}
			}

			// The requested page can fall past the end once entries are pruned or
			// unsaved (the client counts un-resolved keys the server drops). The
			// server reports the real total — step back to the last real page and
			// re-render rather than leaving an empty grid under a live pager.
			var total    = data.total || 0;
			var lastPage = Math.max( 1, Math.ceil( total / perPage ) );
			if ( total > 0 && page > lastPage ) {
				loadSaved( container, lastPage );
				return;
			}

			// Paint the returned cards markup.
			if ( grid ) {
				grid.innerHTML = data.html || '';
			}
			// Paint the returned pager markup.
			if ( pager ) {
				pager.innerHTML = data.pager || '';
			}
			// Show the empty message only when the server reports zero total.
			if ( empty ) {
				empty.hidden = ( data.total || 0 ) > 0;
			}
			// Injected saved cards are saved by definition — flip their hearts.
			hydrate( grid || container );
		} ).catch( function () {
			// On network error clear the loading indicator and reveal the empty
			// message, so the view never sits blank with no explanation.
			if ( loading ) {
				loading.hidden = true;
			}
			if ( empty ) {
				empty.hidden = false;
			}
		} );
	}

	// Pager clicks inside a saved view are client-driven: intercept and re-load the
	// requested page instead of navigating (the list lives on the client).
	/**
	 * @param {Event} e Delegated click event.
	 */
	function onSavedPagerClick( e ) {
		var link = e.target.closest ? e.target.closest( '[data-mlsimport-saved-pager] a' ) : null;
		if ( ! link ) {
			return;
		}
		var container = link.closest( '[data-mlsimport-saved]' );
		if ( ! container ) {
			return;
		}
		// Stop the browser from following the pager link.
		e.preventDefault();
		// Parse the target page from the link's mlsimport_page query param.
		var page = 1;
		try {
			var url = new URL( link.href, window.location.origin );
			page = parseInt( url.searchParams.get( 'mlsimport_page' ) || '1', 10 ) || 1;
		} catch ( err ) {}
		// Re-render that page and scroll the view into view.
		loadSaved( container, page );
		container.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	/**
	 * Kick off loading page 1 for every saved view on the page.
	 */
	function initSavedViews() {
		var views = document.querySelectorAll( '[data-mlsimport-saved]' );
		for ( var i = 0; i < views.length; i++ ) {
			loadSaved( views[ i ], 1 );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Boot
	 * ------------------------------------------------------------------ */

	/**
	 * Module bootstrap: seed the store, hydrate controls, wire delegated events.
	 */
	function init() {
		// Seed the store from the authoritative source for this auth state.
		if ( CFG.loggedIn ) {
			setState( Array.isArray( CFG.list ) ? CFG.list : [] );
		} else {
			setState( readLocal() );
		}

		hydrate( document );

		// Merge anon → account on the first logged-in load, then paint the result.
		var ready = CFG.loggedIn ? mergeOnLogin() : Promise.resolve( false );
		ready.then( function () {
			initSavedViews();
		} );

		// Delegated toggle for both surfaces (works for cards injected later).
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-mlsimport-fav]' ) : null;
			if ( btn ) {
				e.preventDefault();
				onToggle( btn );
			}
		} );

		// Client-driven pagination inside saved views.
		document.addEventListener( 'click', onSavedPagerClick );

		// Re-hydrate saved hearts after every AJAX grid repaint.
		document.addEventListener( 'mlsimport:cards-rendered', function ( e ) {
			hydrate( e.target && e.target.querySelectorAll ? e.target : document );
		} );
	}

	// Run init once the DOM is ready (or immediately if it already is).
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
