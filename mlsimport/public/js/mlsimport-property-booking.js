/**
 * Standalone property booking sidebar.
 *  - Toggles the Schedule-a-Tour / Ask-a-Question panels.
 *  - Drives the tour picker: day and time are single-select button groups; the
 *    current choice is composed into the hidden mlsimport_tour_date field
 *    ("Mon 09 Jun at 9:00 AM") so it travels with the lead.
 * Submission + the success swap are handled by mlsimport-property-lead.js.
 * No jQuery.
 */
( function () {
	/**
	 * Return the currently selected (`.is-active`) element matching `selector` within `root`.
	 *
	 * @param {Element} root     Booking widget root to search inside.
	 * @param {string}  selector Base CSS selector; `.is-active` is appended.
	 * @return {Element|null} The active element, or null when none is selected.
	 */
	function active( root, selector ) {
		return root.querySelector( selector + '.is-active' );
	}

	/**
	 * Compose the human-readable tour summary from the active day/time buttons and
	 * write it into the hidden `[data-tour-summary]` field so it travels with the lead.
	 *
	 * @param {Element} root Booking widget root.
	 * @return {void}
	 */
	function composeTour( root ) {
		// Bail out when this widget has no hidden summary field to populate.
		var field = root.querySelector( '[data-tour-summary]' );
		if ( ! field ) {
			return;
		}
		// Find the currently selected day and time buttons (may be null).
		var dayBtn  = active( root, '.mlsimport-property-booking__day' );
		var timeBtn = active( root, '.mlsimport-property-booking__time' );

		// Read the day label (falling back to the raw day value) and the time value.
		var day  = dayBtn ? ( dayBtn.getAttribute( 'data-label' ) || dayBtn.getAttribute( 'data-day' ) ) : '';
		var time = timeBtn ? timeBtn.getAttribute( 'data-time' ) : '';

		// Build the summary string, only including the parts that are actually chosen.
		var parts = [];
		if ( day ) {
			parts.push( day );
		}
		if ( time ) {
			parts.push( 'at ' + time );
		}
		// Join into e.g. "Mon 09 Jun at 9:00 AM" and store on the hidden field.
		field.value = parts.join( ' ' );
	}

	/**
	 * Make `btn` the single active choice within its button group, then re-derive the tour summary.
	 *
	 * @param {Element} root         Booking widget root.
	 * @param {Element} btn          The button that should become active.
	 * @param {string}  groupSelector CSS selector matching all buttons in the group.
	 * @return {void}
	 */
	function selectInGroup( root, btn, groupSelector ) {
		// Toggle `is-active` on only the clicked button; clear it on the rest of the group.
		Array.prototype.forEach.call( root.querySelectorAll( groupSelector ), function ( b ) {
			b.classList.toggle( 'is-active', b === btn );
		} );
		// Refresh the composed tour summary to reflect the new selection.
		composeTour( root );
	}

	// Single delegated click handler for the whole document; scoped per booking widget below.
	document.addEventListener( 'click', function ( e ) {
		// Ignore events without a usable target element.
		var t = e.target;
		if ( ! t || ! t.closest ) {
			return;
		}
		// Only act on clicks that land inside a booking widget.
		var root = t.closest( '[data-mlsimport-booking]' );
		if ( ! root ) {
			return;
		}

		// Tab click: switch the active Schedule-a-Tour / Ask-a-Question panel.
		var tab = t.closest( '.mlsimport-property-booking__tab' );
		if ( tab ) {
			var key = tab.getAttribute( 'data-tab' );
			// Mark the clicked tab active and update its ARIA selected state; clear the others.
			Array.prototype.forEach.call( root.querySelectorAll( '.mlsimport-property-booking__tab' ), function ( b ) {
				var on = b === tab;
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			// Show the panel whose `data-panel` matches the tab's key; hide the rest.
			Array.prototype.forEach.call( root.querySelectorAll( '.mlsimport-property-booking__panel' ), function ( panel ) {
				var on = panel.getAttribute( 'data-panel' ) === key;
				panel.classList.toggle( 'is-active', on );
				panel.hidden = ! on;
			} );
			return;
		}

		// Day-strip arrows: scroll by one full page of days.
		var arrow = t.closest( '.mlsimport-property-booking__arrow' );
		if ( arrow ) {
			e.preventDefault();
			// Scroll the day strip by one visible width, direction depending on the arrow.
			var strip = root.querySelector( '[data-days]' );
			if ( strip ) {
				var back = arrow.hasAttribute( 'data-days-prev' );
				strip.scrollLeft += ( back ? -1 : 1 ) * strip.clientWidth;
			}
			return;
		}

		// Day / time button click: select it within its own group.
		var groups = [ '.mlsimport-property-booking__day', '.mlsimport-property-booking__time' ];
		for ( var i = 0; i < groups.length; i++ ) {
			// Did the click fall on a button belonging to this group?
			var btn = t.closest( groups[ i ] );
			if ( btn ) {
				e.preventDefault();
				selectInGroup( root, btn, groups[ i ] );
				return;
			}
		}
	} );

	/**
	 * Grey out an arrow that has nowhere left to scroll, so the strip's extent is
	 * legible without the scrollbar we removed.
	 */
	function syncArrows( root ) {
		// Need the strip and both arrows present to manage their disabled state.
		var strip = root.querySelector( '[data-days]' );
		var prev  = root.querySelector( '[data-days-prev]' );
		var next  = root.querySelector( '[data-days-next]' );
		if ( ! strip || ! prev || ! next ) {
			return;
		}

		// Disable an arrow once the strip is scrolled to that end (within a 1px slack).
		var update = function () {
			var max = strip.scrollWidth - strip.clientWidth;
			prev.disabled = strip.scrollLeft <= 1;
			next.disabled = strip.scrollLeft >= max - 1;
		};

		// Re-evaluate on scroll and on viewport resize.
		strip.addEventListener( 'scroll', update );
		window.addEventListener( 'resize', update );

		// At DOMContentLoaded the strip can still measure as un-scrollable (webfonts
		// and CSS have not settled), which would disable both arrows permanently —
		// a disabled arrow can never fire the scroll event that would re-enable it.
		// Re-check whenever the strip's box actually changes.
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( update ).observe( strip );
		}
		// Also re-check once all assets (webfonts/CSS) have finished loading.
		window.addEventListener( 'load', update );

		// Run once immediately for the initial arrow state.
		update();
	}

	/**
	 * Initialise every booking widget on the page: seed its tour summary and wire the arrows.
	 *
	 * @return {void}
	 */
	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-mlsimport-booking]' ), function ( root ) {
			composeTour( root );
			syncArrows( root );
		} );
	}

	// Defer init until the DOM is ready; run immediately if it already is.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
