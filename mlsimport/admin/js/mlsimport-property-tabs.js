/**
 * MLS Listing metabox — tab switching, plus the Media tab gallery.
 *
 * Vanilla JS (no build, no jQuery dependency). Clicking a tab shows its pane and
 * remembers the choice so the same tab is active after the post is saved. The
 * Media tab additionally previews the Photos Count cap as it is typed and deletes
 * individual images over AJAX.
 */
( function () {
	'use strict';

	// Cookie name used to remember which metabox tab was last active
	var COOKIE = 'mlsimport_mb_tab';

	/**
	 * Persist the active tab slug in a site-wide cookie.
	 *
	 * @param {string} value - Tab slug to store.
	 */
	function setCookie( value ) {
		document.cookie = COOKIE + '=' + encodeURIComponent( value ) + '; path=/';
	}

	/**
	 * Read the remembered tab slug from the cookie.
	 *
	 * @return {string} Stored slug, or '' when absent.
	 */
	function getCookie() {
		var match = document.cookie.match( new RegExp( '(?:^|; )' + COOKIE + '=([^;]*)' ) );
		return match ? decodeURIComponent( match[ 1 ] ) : '';
	}

	/**
	 * Activate the tab (and matching pane) identified by slug within a metabox.
	 *
	 * @param {Element} box  - The metabox root element.
	 * @param {string}  slug - Tab slug to activate.
	 * @return {boolean} True if a matching tab was found and activated.
	 */
	function activate( box, slug ) {
		// Collect the tab buttons and content panes in this box
		var tabs = box.querySelectorAll( '.mlsimport-mb__tab' );
		var panes = box.querySelectorAll( '.mlsimport-mb__pane' );
		var found = false;

		// Toggle each tab's active state; track whether the slug matched any tab
		tabs.forEach( function ( tab ) {
			var on = tab.getAttribute( 'data-tab' ) === slug;
			tab.classList.toggle( 'is-active', on );
			found = found || on;
		} );
		// No tab matched: leave panes untouched and report failure
		if ( ! found ) {
			return false;
		}
		// Show the pane whose data-pane matches the slug, hide the rest
		panes.forEach( function ( pane ) {
			pane.classList.toggle( 'is-active', pane.getAttribute( 'data-pane' ) === slug );
		} );
		return true;
	}

	// On DOM ready, wire tab clicks and restore the remembered tab
	document.addEventListener( 'DOMContentLoaded', function () {
		// Locate the metabox; nothing to do if it isn't on this screen
		var box = document.querySelector( '.mlsimport-mb' );
		if ( ! box ) {
			return;
		}

		// Delegate clicks so any tab within the box is handled
		box.addEventListener( 'click', function ( e ) {
			// Ignore clicks that didn't land on (or inside) a tab
			var tab = e.target.closest( '.mlsimport-mb__tab' );
			if ( ! tab ) {
				return;
			}
			// Activate the clicked tab and, if successful, remember it
			var slug = tab.getAttribute( 'data-tab' );
			if ( activate( box, slug ) ) {
				setCookie( slug );
			}
		} );

		// Restore the previously active tab from the cookie, if any
		var stored = getCookie();
		if ( stored ) {
			activate( box, stored );
		}

		wireGallery( box );
	} );

	/**
	 * Apply the Photos Count cap to the tiles: the first N stay visible, the rest
	 * are hidden. A blank or zero count means no cap. Mirrors the PHP rule in
	 * mlsimport_property_gallery_ids() so the preview matches what gets published.
	 *
	 * @param {Element} box - The metabox root element.
	 */
	function applyCount( box ) {
		var input = box.querySelector( '#mlsimport_PhotosCount' );
		var tiles = box.querySelectorAll( '.mlsimport-mb__thumb' );
		// A missing input (or no gallery) leaves every tile as the server rendered it
		if ( ! input || ! tiles.length ) {
			return;
		}

		// Anything not a positive number publishes the whole gallery
		var limit = parseInt( input.value, 10 );
		if ( isNaN( limit ) || limit < 1 ) {
			limit = tiles.length;
		}

		// Hide every tile past the limit; show the ones within it
		tiles.forEach( function ( tile, index ) {
			tile.classList.toggle( 'is-beyond-count', index >= limit );
		} );

		// Keep the heading honest about how many of the stored images are published
		var title = box.querySelector( '.mlsimport-mb__gallery-title' );
		if ( title && title.getAttribute( 'data-template' ) ) {
			var shown = Math.min( limit, tiles.length );
			title.textContent = title
				.getAttribute( 'data-template' )
				.replace( '%1$d', shown )
				.replace( '%2$d', tiles.length );
		}
	}

	/**
	 * Wire the Media tab gallery: live Photos Count preview and per-image delete.
	 *
	 * @param {Element} box - The metabox root element.
	 */
	function wireGallery( box ) {
		var gallery = box.querySelector( '.mlsimport-mb__gallery' );
		// Listings with no imported images render no gallery at all
		if ( ! gallery ) {
			return;
		}

		// Re-apply the cap on every keystroke / spinner click
		var count = box.querySelector( '#mlsimport_PhotosCount' );
		if ( count ) {
			count.addEventListener( 'input', function () {
				applyCount( box );
			} );
		}

		// Delegate delete clicks so tiles removed from the DOM need no cleanup
		gallery.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.mlsimport-mb__thumb-del' );
			// Ignore clicks that landed on the thumbnail link or the grid itself
			if ( ! btn ) {
				return;
			}
			e.preventDefault();
			deleteImage( box, gallery, btn );
		} );
	}

	/**
	 * Delete one image over AJAX, then drop its tile and re-apply the cap.
	 *
	 * @param {Element} box     - The metabox root element.
	 * @param {Element} gallery - The gallery wrapper (carries data-post).
	 * @param {Element} btn     - The clicked delete button (carries data-id).
	 */
	function deleteImage( box, gallery, btn ) {
		var cfg = window.mlsimportMetabox;
		// Without the localized config there is no nonce, so no request to make
		if ( ! cfg ) {
			return;
		}
		// Deleting is permanent — make the editor say yes first
		if ( ! window.confirm( cfg.confirm ) ) {
			return;
		}

		var tile = btn.closest( '.mlsimport-mb__thumb' );
		// Disable while in flight so a double-click can't fire two deletes
		btn.disabled = true;

		var body = new URLSearchParams();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'post_id', gallery.getAttribute( 'data-post' ) );
		body.append( 'attach_id', btn.getAttribute( 'data-id' ) );

		window
			.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				// Server refused (bad nonce, no capability, not in this gallery)
				if ( ! res || ! res.success ) {
					throw new Error( 'refused' );
				}
				// Gone for good: drop the tile, then re-cap the survivors
				tile.remove();
				// The server may have promoted a new featured image — reflect it
				markFeatured( box, res.data ? res.data.featured : 0 );
				applyCount( box );
			} )
			.catch( function () {
				btn.disabled = false;
				window.alert( cfg.failed );
			} );
	}

	/**
	 * Move the "Featured" badge to the tile the server now reports as featured.
	 *
	 * @param {Element} box - The metabox root element.
	 * @param {number}  id  - Attachment id of the new featured image (0 = none).
	 */
	function markFeatured( box, id ) {
		var tiles = box.querySelectorAll( '.mlsimport-mb__thumb' );
		tiles.forEach( function ( tile ) {
			var isFeatured = id && tile.getAttribute( 'data-id' ) === String( id );
			var badge = tile.querySelector( '.mlsimport-mb__thumb-badge' );

			tile.classList.toggle( 'is-featured', !! isFeatured );

			// Add the badge when this tile just became featured
			if ( isFeatured && ! badge ) {
				badge = document.createElement( 'span' );
				badge.className = 'mlsimport-mb__thumb-badge';
				badge.textContent = window.mlsimportMetabox ? window.mlsimportMetabox.featured : 'Featured';
				tile.appendChild( badge );
			// Remove a stale badge from a tile that no longer holds the role
			} else if ( ! isFeatured && badge ) {
				badge.remove();
			}
		} );
	}
} )();
