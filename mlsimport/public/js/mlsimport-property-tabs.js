/**
 * Standalone property "Details as Tabs" — toggles panels on tab click.
 * Accessible: updates aria-selected and panel [hidden]. No jQuery.
 */
( function () {
	/**
	 * Show the panel matching `key` and update ARIA selected state within one tab group.
	 *
	 * @param {Element} root The tab-group root (`[data-mlsimport-tabs]`).
	 * @param {string}  key  The `data-tab` / `data-panel` key to activate.
	 * @return {void}
	 */
	function activate( root, key ) {
		// Collect the group's tabs and panels.
		var tabs   = root.querySelectorAll( '.mlsimport-property-tabs__tab' );
		var panels = root.querySelectorAll( '.mlsimport-property-tabs__panel' );

		// Mark only the matching tab as selected for assistive tech.
		Array.prototype.forEach.call( tabs, function ( tab ) {
			tab.setAttribute( 'aria-selected', tab.getAttribute( 'data-tab' ) === key ? 'true' : 'false' );
		} );
		// Reveal the matching panel; hide the rest via the `hidden` attribute.
		Array.prototype.forEach.call( panels, function ( panel ) {
			if ( panel.getAttribute( 'data-panel' ) === key ) {
				panel.removeAttribute( 'hidden' );
			} else {
				panel.setAttribute( 'hidden', '' );
			}
		} );
	}

	// Delegated click: activate the clicked tab within its own group.
	document.addEventListener( 'click', function ( e ) {
		// Find the nearest tab element for the click, if any.
		var tab = e.target && e.target.closest ? e.target.closest( '.mlsimport-property-tabs__tab' ) : null;
		if ( ! tab ) {
			return;
		}
		// Scope to the tab's group and activate its key.
		var root = tab.closest( '[data-mlsimport-tabs]' );
		if ( root ) {
			activate( root, tab.getAttribute( 'data-tab' ) );
		}
	} );
} )();
