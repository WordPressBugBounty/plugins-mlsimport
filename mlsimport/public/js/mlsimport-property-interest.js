/**
 * The "I'm interested in" dropdown — progressive enhancement over the native
 * <select name="mlsimport_interest"> in the property lead forms.
 *
 * A native select's option list is drawn by the operating system and cannot be
 * styled, so it never matched the rest of the site however the closed box was
 * themed. This replaces the closed box with a button and the list with ordinary
 * markup, reusing the .mlsimport-location__list / __option panel the search
 * form's Location autocomplete already uses — so the two dropdowns match by
 * construction rather than by hand-tuned values.
 *
 * The native select STAYS in the DOM (hidden once enhanced) and remains the
 * submitted control: every pick writes through to it. So the lead handler is
 * untouched, and with JS off the form degrades to the plain select. Enhancement
 * is keyed off the wrapper gaining .is-enhanced, which is also what hides the
 * native control — CSS alone never hides a control the user might still need.
 *
 * No jQuery.
 */
( function () {
	'use strict';

	// Wrapper emitted by mlsimport_property_lead_interest_field().
	var ROOT = '.mlsimport-interest';

	/**
	 * Build the button + listbox for one wrapper and wire its behaviour.
	 * @param {Element} root The .mlsimport-interest wrapper.
	 */
	function enhance( root ) {
		// Enhance once, and only when there is a native select to mirror.
		if ( root.classList.contains( 'is-enhanced' ) ) {
			return;
		}
		var select = root.querySelector( 'select' );
		if ( ! select ) {
			return;
		}

		// The trigger reports the current choice; the placeholder option's text is
		// the resting label, so the control reads the same before and after JS.
		var trigger = document.createElement( 'button' );
		trigger.type = 'button';
		trigger.className = 'mlsimport-interest__trigger';
		trigger.setAttribute( 'aria-haspopup', 'listbox' );
		trigger.setAttribute( 'aria-expanded', 'false' );

		var label = document.createElement( 'span' );
		label.className = 'mlsimport-interest__label';
		trigger.appendChild( label );

		// The panel reuses the Location autocomplete's classes verbatim.
		var list = document.createElement( 'ul' );
		list.className = 'mlsimport-location__list mlsimport-interest__list';
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;

		// One option per <option>, carrying its index so selection is a plain lookup.
		var options = [];
		Array.prototype.forEach.call( select.options, function ( opt, i ) {
			var li = document.createElement( 'li' );
			li.className = 'mlsimport-location__option';
			li.setAttribute( 'role', 'option' );
			li.setAttribute( 'data-index', String( i ) );
			li.textContent = opt.textContent;
			list.appendChild( li );
			options.push( li );
		} );

		/**
		 * Mirror the select's current value onto the trigger and the listbox.
		 */
		function paint() {
			var opt = select.options[ select.selectedIndex ];
			label.textContent = opt ? opt.textContent : '';
			// The placeholder option has an empty value — style it as unfilled.
			root.classList.toggle( 'is-placeholder', ! select.value );
			options.forEach( function ( li, i ) {
				var on = i === select.selectedIndex;
				li.classList.toggle( 'is-selected', on );
				li.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
		}

		/**
		 * Open or close the panel.
		 * @param {boolean} open Whether the panel should be open.
		 */
		function setOpen( open ) {
			list.hidden = ! open;
			root.classList.toggle( 'is-open', open );
			trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				// Start the keyboard walk from the current choice.
				highlight( select.selectedIndex );
			}
		}

		// Index currently highlighted by the keyboard (-1 = none).
		var active = -1;

		/**
		 * Move the keyboard highlight, clamped to the option range.
		 * @param {number} i Target index.
		 */
		function highlight( i ) {
			if ( active > -1 && options[ active ] ) {
				options[ active ].classList.remove( 'is-active' );
			}
			active = Math.max( 0, Math.min( options.length - 1, i ) );
			options[ active ].classList.add( 'is-active' );
			// Keep the highlighted row inside the scrollable panel.
			if ( options[ active ].scrollIntoView ) {
				options[ active ].scrollIntoView( { block: 'nearest' } );
			}
		}

		/**
		 * Commit an option: write through to the native select, repaint, close.
		 * @param {number} i Option index to select.
		 */
		function choose( i ) {
			select.selectedIndex = i;
			// Fire change so anything listening on the real control still hears it.
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			paint();
			setOpen( false );
			trigger.focus();
		}

		trigger.addEventListener( 'click', function () {
			setOpen( list.hidden );
		} );

		// Pointer selection. Using mousedown would fire before the click that closes
		// an already-open panel, so click is the one that behaves.
		list.addEventListener( 'click', function ( e ) {
			var li = e.target.closest ? e.target.closest( '.mlsimport-location__option' ) : null;
			if ( li ) {
				choose( parseInt( li.getAttribute( 'data-index' ), 10 ) || 0 );
			}
		} );

		// Keyboard: the listbox conventions a native select already answers to.
		trigger.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
				e.preventDefault();
				if ( list.hidden ) {
					setOpen( true );
					return;
				}
				highlight( active + ( 'ArrowDown' === e.key ? 1 : -1 ) );
			} else if ( 'Enter' === e.key || ' ' === e.key ) {
				// Enter on a closed control opens it; on an open one it commits.
				e.preventDefault();
				if ( list.hidden ) {
					setOpen( true );
				} else {
					choose( active );
				}
			} else if ( 'Escape' === e.key && ! list.hidden ) {
				e.preventDefault();
				setOpen( false );
			}
		} );

		// Any click outside closes the panel.
		document.addEventListener( 'click', function ( e ) {
			if ( ! root.contains( e.target ) ) {
				setOpen( false );
			}
		} );

		// Insert the widget, then flag the wrapper — the flag is what hides the
		// native select, so it is set only once the replacement is really in place.
		root.appendChild( trigger );
		root.appendChild( list );
		paint();
		root.classList.add( 'is-enhanced' );
	}

	/**
	 * Enhance every interest dropdown inside a scope.
	 * @param {Element|Document} [scope] Root to search; defaults to document.
	 */
	function init( scope ) {
		var node = scope && scope.querySelectorAll ? scope : document;
		Array.prototype.forEach.call( node.querySelectorAll( ROOT ), enhance );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init( document );
		} );
	} else {
		init( document );
	}
} )();
