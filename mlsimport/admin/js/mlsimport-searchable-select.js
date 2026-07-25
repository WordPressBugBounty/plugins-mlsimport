/**
 * Searchable City/County multi-select.
 *
 * The field stays a native <select multiple> listbox showing the full list (as
 * it was before). Three enhancements:
 *  - a text input above it filters the options live;
 *  - a plain click toggles an option, so multiple items can be picked without
 *    holding Ctrl/Cmd (the native listbox default);
 *  - the currently selected items are mirrored as removable chips above the list.
 *
 * The underlying <select multiple> is the source of truth — it submits the same
 * name[]-array payload and no save logic changes.
 */
( function () {
	'use strict';

	/**
	 * Find the native <select> that a search input controls (its next element).
	 *
	 * @param {Element} input The .mlsimport-select-search text input.
	 * @return {HTMLSelectElement|null}
	 */
	function selectFor( input ) {
		// Walk forward through siblings until the <select> is found
		var node = input.nextElementSibling;
		while ( node && 'SELECT' !== node.tagName ) {
			node = node.nextElementSibling;
		}
		return node;
	}

	/**
	 * Find the chips container that mirrors a given <select> (a preceding sibling).
	 *
	 * @param {HTMLSelectElement} select The searchable select.
	 * @return {Element|null}
	 */
	function chipsFor( select ) {
		// Walk backward through siblings until the chips container is found
		var node = select.previousElementSibling;
		while ( node && ! node.classList.contains( 'mlsimport-selected-chips' ) ) {
			node = node.previousElementSibling;
		}
		return node;
	}

	/**
	 * Show only options whose label contains the typed term.
	 *
	 * @param {Element} input The search input that fired.
	 */
	function filter( input ) {
		// Resolve the controlled select; nothing to do without it
		var select = selectFor( input );
		if ( ! select ) {
			return;
		}
		// Normalise the search term and test each option's label
		var term = input.value.toLowerCase();
		var options = select.options;
		for ( var i = 0; i < options.length; i++ ) {
			// Show options whose text contains the term; hide the rest (two ways, for cross-browser)
			var match = -1 !== options[ i ].text.toLowerCase().indexOf( term );
			options[ i ].hidden = ! match;
			options[ i ].style.display = match ? '' : 'none';
		}
	}

	/**
	 * Rebuild the chip row from the select's current selection.
	 *
	 * @param {HTMLSelectElement} select The searchable select.
	 */
	function renderChips( select ) {
		// Resolve the chips container; nothing to do without it
		var box = chipsFor( select );
		if ( ! box ) {
			return;
		}
		// Clear the row, then rebuild one chip per selected option
		box.innerHTML = '';
		var chosen = select.selectedOptions;
		for ( var i = 0; i < chosen.length; i++ ) {
			var option = chosen[ i ];

			// Chip wrapper, tagged with the option value for later removal
			var chip = document.createElement( 'span' );
			chip.className = 'mlsimport-chip';
			chip.setAttribute( 'data-value', option.value );

			// Visible label text
			var label = document.createElement( 'span' );
			label.className = 'mlsimport-chip-label';
			label.textContent = option.text;

			// "×" remove button (accessible label)
			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'mlsimport-chip-remove';
			remove.setAttribute( 'aria-label', 'Remove ' + option.text );
			remove.textContent = '×';

			// Assemble the chip and append it to the row
			chip.appendChild( label );
			chip.appendChild( remove );
			box.appendChild( chip );
		}
	}

	/** Render chips for every searchable select on the page. */
	function renderAll() {
		// Refresh chips for each searchable select present in the DOM
		var selects = document.querySelectorAll( 'select.mlsimport-searchable-select' );
		for ( var i = 0; i < selects.length; i++ ) {
			renderChips( selects[ i ] );
		}
	}

	// Live-filter options as the user types in a search box
	document.addEventListener( 'input', function ( event ) {
		// Only react to the searchable-select search inputs
		var target = event.target;
		if ( target && target.classList && target.classList.contains( 'mlsimport-select-search' ) ) {
			filter( target );
		}
	} );

	// Plain click toggles an option — no Ctrl/Cmd needed for multi-select.
	document.addEventListener( 'mousedown', function ( event ) {
		// Only react to clicks on <option> elements
		var option = event.target;
		if ( ! option || 'OPTION' !== option.tagName ) {
			return;
		}
		// Ensure the option belongs to a searchable select
		var select = option.closest( 'select.mlsimport-searchable-select' );
		if ( ! select ) {
			return;
		}
		// Block the native "replace selection" behaviour, then toggle.
		event.preventDefault();
		option.selected = ! option.selected;
		// Preserve scroll position across the focus() that the toggle needs
		var scroll = select.scrollTop;
		select.focus();
		select.scrollTop = scroll;
		// Notify listeners (chip renderer, form) that the selection changed
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );

	// Removing a chip deselects its option in the underlying select.
	document.addEventListener( 'click', function ( event ) {
		// Only react to clicks on a chip's remove button
		var button = event.target;
		if ( ! button || ! button.classList || ! button.classList.contains( 'mlsimport-chip-remove' ) ) {
			return;
		}
		// Resolve the chip and its container
		var box = button.closest( '.mlsimport-selected-chips' );
		var chip = button.closest( '.mlsimport-chip' );
		if ( ! box || ! chip ) {
			return;
		}
		// Walk forward from the chips box to the associated searchable select
		var select = box.nextElementSibling;
		while ( select && ! ( 'SELECT' === select.tagName && select.classList.contains( 'mlsimport-searchable-select' ) ) ) {
			select = select.nextElementSibling;
		}
		if ( ! select ) {
			return;
		}
		// Deselect the option matching the chip's stored value
		var value = chip.getAttribute( 'data-value' );
		var options = select.options;
		for ( var i = 0; i < options.length; i++ ) {
			if ( options[ i ].value === value ) {
				options[ i ].selected = false;
				break;
			}
		}
		// Notify listeners so the chip row re-renders without this item
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );

	// Keep chips in sync with any selection change (toggle, Ctrl+click, removal).
	document.addEventListener( 'change', function ( event ) {
		// Only rebuild chips for searchable selects
		var select = event.target;
		if ( select && 'SELECT' === select.tagName && select.classList &&
			select.classList.contains( 'mlsimport-searchable-select' ) ) {
			renderChips( select );
		}
	} );

	// Initial chip render: run now, or wait for DOM ready if still parsing
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', renderAll );
	} else {
		renderAll();
	}
}() );
