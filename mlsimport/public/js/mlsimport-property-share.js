/**
 * Standalone property share/print buttons. Copy-link writes the URL to the
 * clipboard; print opens the browser print dialog. When a button has a
 * [data-copy-label] child, only that label text is swapped so any icon survives.
 * The Save button is a real, persisted favorite handled by mlsimport-favorites.js.
 * No jQuery.
 */
( function () {
	/**
	 * Set a button's visible label, preferring an inner label element so its icon survives.
	 *
	 * @param {Element} btn      The button element.
	 * @param {string}  selector Selector for the inner label element.
	 * @param {string}  text     New label text.
	 * @return {void}
	 */
	function setLabel( btn, selector, text ) {
		// Write to the inner label element when present, else replace the button's whole text.
		var label = btn.querySelector( selector );
		if ( label ) {
			label.textContent = text;
		} else {
			btn.textContent = text;
		}
	}

	/**
	 * Close every open share popup, optionally sparing one.
	 *
	 * @param {Element|null} keep Wrapper to leave open.
	 * @return {void}
	 */
	function closeMenus( keep ) {
		var open = document.querySelectorAll( '.mlsimport-property-title-bar__share.is-open' );
		for ( var i = 0; i < open.length; i++ ) {
			if ( open[ i ] === keep ) {
				continue;
			}
			open[ i ].classList.remove( 'is-open' );
			// Keep the button's aria state in sync with the visible state.
			var btn = open[ i ].querySelector( '[data-mlsimport-share-toggle]' );
			if ( btn ) {
				btn.setAttribute( 'aria-expanded', 'false' );
			}
		}
	}

	// Delegated click handler for the share toggle, copy-link and print buttons.
	document.addEventListener( 'click', function ( e ) {
		// Ignore events without a usable target.
		var t = e.target;
		if ( ! t || ! t.closest ) {
			return;
		}

		// Share toggle: open/close its own popup, closing any other first.
		var toggle = t.closest( '[data-mlsimport-share-toggle]' );
		if ( toggle ) {
			e.preventDefault();
			var wrap = toggle.closest( '.mlsimport-property-title-bar__share' );
			closeMenus( wrap );
			if ( wrap ) {
				var isOpen = wrap.classList.toggle( 'is-open' );
				toggle.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
			}
			return;
		}

		// Any click outside an open popup closes it.
		if ( ! t.closest( '.mlsimport-property-share-menu' ) ) {
			closeMenus( null );
		}

		// Copy-link button: write the URL to the clipboard and flash a "Copied" label.
		var copy = t.closest( '[data-mlsimport-copy]' );
		if ( copy ) {
			e.preventDefault();
			// The URL to copy is carried on the button's data attribute.
			var url = copy.getAttribute( 'data-mlsimport-copy' );
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url );
			}
			// Ignore repeat clicks while flashing, else "Copied" becomes the stored label.
			if ( copy.dataset.copyFlashing ) {
				return;
			}
			copy.dataset.copyFlashing = '1';
			// Remember the current label so it can be restored after the flash.
			var prev = ( copy.querySelector( '[data-copy-label]' ) || copy ).textContent;
			setLabel( copy, '[data-copy-label]', 'Copied' );
			// Restore the original label after 1.5s.
			setTimeout( function () {
				setLabel( copy, '[data-copy-label]', prev );
				delete copy.dataset.copyFlashing;
			}, 1500 );
			return;
		}

		// Print button: open the dedicated print document in a popup, which prints
		// itself on load. Falls back to printing the current page when no print
		// URL is available.
		var print = t.closest( '[data-mlsimport-print]' );
		if ( print ) {
			e.preventDefault();
			var printUrl = print.getAttribute( 'data-mlsimport-print' );
			if ( printUrl ) {
				window.open( printUrl, 'mlsimportPrint', 'width=760,height=900' );
			} else {
				window.print();
			}
		}
	} );

	// Escape closes an open popup.
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			closeMenus( null );
		}
	} );
} )();
