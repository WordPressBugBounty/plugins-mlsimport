/**
 * mlsimport-multiselect — a self-contained dropdown multi-select.
 *
 * Progressive enhancement over a native <select multiple class="mlsimport-multiselect">:
 * a compact control showing the chosen items as chips, opening a panel with a
 * live search and a checkbox list — the WPResidence search-dropdown feel, but
 * vanilla (no jQuery, no Bootstrap). The native <select> stays in the DOM as the
 * source of truth, so the surrounding form submits the usual name[]=value payload
 * and nothing JS-side is required for submission.
 *
 * Call window.mlsimportMultiselectInit( root ) to enhance selects added later
 * (e.g. a block editor preview); it is idempotent.
 */
( function () {
	'use strict';

	/**
	 * Enhance one native multi-select into the dropdown control.
	 *
	 * @param {HTMLSelectElement} select The .mlsimport-multiselect element.
	 */
	function enhance( select ) {
		// Idempotent: skip a select already enhanced.
		if ( select.dataset.msInit ) {
			return;
		}
		select.dataset.msInit = '1';

		var placeholder = select.getAttribute( 'data-placeholder' ) || 'Select…';

		// Wrapper that holds the native select, the control, and the dropdown.
		var wrap = document.createElement( 'div' );
		wrap.className = 'mlsimport-ms';
		select.parentNode.insertBefore( wrap, select );
		wrap.appendChild( select );
		select.classList.add( 'mlsimport-ms__native' );

		// Visible toggle button showing chips / placeholder.
		var control = document.createElement( 'button' );
		control.type = 'button';
		control.className = 'mlsimport-ms__control';
		control.setAttribute( 'aria-haspopup', 'listbox' );
		control.setAttribute( 'aria-expanded', 'false' );
		wrap.appendChild( control );

		// The pop-open panel.
		var dropdown = document.createElement( 'div' );
		dropdown.className = 'mlsimport-ms__dropdown';
		wrap.appendChild( dropdown );

		// Live search box inside the panel.
		var search = document.createElement( 'input' );
		search.type = 'text';
		search.className = 'mlsimport-ms__search';
		search.placeholder = 'Search…';
		search.setAttribute( 'autocomplete', 'off' );
		dropdown.appendChild( search );

		// Container for the checkbox-style option rows.
		var list = document.createElement( 'div' );
		list.className = 'mlsimport-ms__options';
		dropdown.appendChild( list );

		// Build one row per real <option>, keeping a link back to that option.
		var rows = [];
		Array.prototype.forEach.call( select.options, function ( opt ) {
			if ( '' === opt.value ) {
				return; // A placeholder option carries no selectable value.
			}
			// Row element mirroring the option.
			var row = document.createElement( 'div' );
			row.className = 'mlsimport-ms__option';
			row.setAttribute( 'role', 'option' );

			// Checkmark box + text label.
			var check = document.createElement( 'span' );
			check.className = 'mlsimport-ms__check';
			var label = document.createElement( 'span' );
			label.className = 'mlsimport-ms__label';
			label.textContent = opt.text;
			row.appendChild( check );
			row.appendChild( label );
			list.appendChild( row );

			// Clicking a row toggles the underlying option and repaints.
			row.addEventListener( 'click', function () {
				opt.selected = ! opt.selected;
				sync();
			} );
			rows.push( { opt: opt, row: row } );
		} );

		/** Append the dropdown caret to the control. */
		function addCaret() {
			var caret = document.createElement( 'span' );
			caret.className = 'mlsimport-ms__caret';
			control.appendChild( caret );
		}

		/** Repaint the control (chips/placeholder) and the option checkmarks. */
		function sync() {
			// Rebuild the control from scratch each time.
			control.innerHTML = '';
			// Currently-selected rows.
			var chosen = rows.filter( function ( r ) {
				return r.opt.selected;
			} );
			// Reflect selection state on every option row.
			rows.forEach( function ( r ) {
				r.row.classList.toggle( 'is-selected', r.opt.selected );
			} );

			// Nothing chosen: show the placeholder.
			if ( ! chosen.length ) {
				var ph = document.createElement( 'span' );
				ph.className = 'mlsimport-ms__placeholder';
				ph.textContent = placeholder;
				control.appendChild( ph );
			} else {
				// One removable chip per chosen option.
				chosen.forEach( function ( r ) {
					var chip = document.createElement( 'span' );
					chip.className = 'mlsimport-ms__chip';
					chip.textContent = r.opt.text;

					// The chip's "×" deselects that option (without opening the panel).
					var x = document.createElement( 'span' );
					x.className = 'mlsimport-ms__chip-x';
					x.textContent = '×';
					x.addEventListener( 'click', function ( e ) {
						e.stopPropagation();
						r.opt.selected = false;
						sync();
					} );
					chip.appendChild( x );
					control.appendChild( chip );
				} );
			}
			addCaret();

			// The control is a fixed size — it must never grow the form. When two or
			// more chips don't fit, drop them for a single "multiple selection" label.
			// Measured (not count-based) so it only collapses when they truly overflow.
			if ( chosen.length > 1 && control.clientWidth > 0 && control.scrollWidth > control.clientWidth ) {
				control.innerHTML = '';
				var summary = document.createElement( 'span' );
				summary.className = 'mlsimport-ms__summary';
				summary.textContent = 'multiple selection';
				control.appendChild( summary );
				addCaret();
			}

			// Notify the form (and any listeners) that the selection changed.
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		/**
		 * Show/hide option rows by case-insensitive substring match.
		 * @param {string} term Search term.
		 */
		function filter( term ) {
			term = term.toLowerCase();
			rows.forEach( function ( r ) {
				r.row.style.display = -1 !== r.opt.text.toLowerCase().indexOf( term ) ? '' : 'none';
			} );
		}

		// Set while open when the panel had to escape a clipping ancestor.
		var unfloat = null;

		/** Open the dropdown panel and focus the (reset) search box. */
		function open() {
			wrap.classList.add( 'is-open' );
			control.setAttribute( 'aria-expanded', 'true' );
			// The panel is absolutely positioned inside the field, so any ancestor with
			// overflow hidden/clip crops it — including .wp-block-cover, which core
			// gives `overflow: clip` and which most search-form heroes sit inside.
			// mlsimport-search-popups.js owns the helper; read it at open time so
			// enqueue order between the two scripts does not matter.
			if ( 'function' === typeof window.mlsimportFloatFree ) {
				unfloat = window.mlsimportFloatFree( dropdown, control );
			}
			search.value = '';
			filter( '' );
			search.focus();
		}
		/** Close the dropdown panel. */
		function close() {
			wrap.classList.remove( 'is-open' );
			control.setAttribute( 'aria-expanded', 'false' );
			if ( unfloat ) {
				unfloat();
				unfloat = null;
			}
		}

		// Control click toggles the panel open/closed. The click is deliberately left
		// to bubble to the document handler below, so opening one control closes every
		// other open one (that handler's containment check keeps this one open).
		control.addEventListener( 'click', function () {
			if ( wrap.classList.contains( 'is-open' ) ) {
				close();
			} else {
				open();
			}
		} );
		// Typing in the search box filters the option rows.
		search.addEventListener( 'input', function () {
			filter( search.value );
		} );
		// Clicks inside the panel must not bubble to the document close handler.
		dropdown.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
		} );
		// Close only on a click genuinely outside this control. A containment check
		// (not stopPropagation) is required because the control sits inside a <label>:
		// clicking an option forwards a synthetic click to the hidden native <select>,
		// which is outside the dropdown and would otherwise reach this handler and
		// close the panel after a single pick.
		document.addEventListener( 'click', function ( e ) {
			if ( ! wrap.contains( e.target ) ) {
				close();
			}
		} );
		// Escape closes the panel.
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				close();
			}
		} );

		// Initial paint of the control from the select's current selection.
		sync();
	}

	/**
	 * Enhance every un-initialized multi-select under a root (default: document).
	 *
	 * @param {ParentNode} [root] Scope to search.
	 */
	function init( root ) {
		// Default the search scope to the whole document.
		var scope = root || document;
		Array.prototype.forEach.call( scope.querySelectorAll( 'select.mlsimport-multiselect' ), enhance );
	}

	/**
	 * Enhance the selects present now, then watch for any added later — a block
	 * editor ServerSideRender preview injects its markup asynchronously (and inside
	 * the editor iframe), well after this script's initial pass. enhance() is
	 * idempotent, so re-touching existing selects is a no-op.
	 */
	function start() {
		// Enhance whatever exists now.
		init();
		// Without MutationObserver (or before body exists) there's nothing to watch.
		if ( ! window.MutationObserver || ! document.body ) {
			return;
		}
		// Watch for selects injected later (e.g. block-editor previews).
		new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( m ) {
				Array.prototype.forEach.call( m.addedNodes, function ( node ) {
					if ( 1 !== node.nodeType ) {
						return; // Elements only.
					}
					// The added node may itself be a target, or contain some.
					if ( node.matches && node.matches( 'select.mlsimport-multiselect' ) ) {
						enhance( node );
					} else if ( node.querySelectorAll ) {
						Array.prototype.forEach.call( node.querySelectorAll( 'select.mlsimport-multiselect' ), enhance );
					}
				} );
			} );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	// Start on DOM ready (or immediately if already parsed).
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	// Expose init() so callers can enhance dynamically-added selects.
	window.mlsimportMultiselectInit = init;
}() );
