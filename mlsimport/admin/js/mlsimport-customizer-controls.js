/**
 * Customizer control JS for the MLSImport "arrange sections" fields.
 *
 * Registers the wp.customize.controlConstructor for the PHP control type
 * 'mlsimport_sections'. Renders an Enabled and a Disabled list; each item can move
 * up/down within its list or flip between lists. Every change writes the same
 * { active:[...], inactive:[...] } object back to the setting, which is the shared
 * mlsimport_standalone_options[...] key. See class-mlsimport-customize-sections-control.php.
 */
( function ( wp, $ ) {
	'use strict';

	// Bail if the Customizer API is not present (script loaded out of context).
	if ( ! wp || ! wp.customize ) {
		return;
	}

	// Land the preview on the listings archive so accent + card style are visible
	// on open. Only redirect away from the home page, and only once, so it never
	// fights the user navigating the preview to a property/agent page.
	wp.customize.bind( 'ready', function () {
		// Nothing to do without a configured archive URL.
		var cfg = window.mlsimportCustomizer || {};
		if ( ! cfg.archiveUrl ) {
			return;
		}
		var previewer = wp.customize.previewer;
		// Current preview URL and the site home URL, trailing slash ignored.
		var current = previewer.previewUrl.get();
		var home = ( wp.customize.settings && wp.customize.settings.url && wp.customize.settings.url.home ) || '';
		// Only redirect when the preview is sitting on the home page.
		if ( home && current.replace( /\/$/, '' ) === home.replace( /\/$/, '' ) ) {
			previewer.previewUrl.set( cfg.archiveUrl );
		}
	} );

	// Custom control constructor for the PHP 'mlsimport_sections' control type.
	wp.customize.controlConstructor.mlsimport_sections = wp.customize.Control.extend( {
		/**
		 * Initialise control state from params and render the two lists.
		 */
		ready: function () {
			var control = this;

			// Build a slug -> label lookup from the catalog of available sections.
			control.catalog = control.params.catalog || [];
			control.labelFor = {};
			control.catalog.forEach( function ( c ) {
				control.labelFor[ c.slug ] = c.label;
			} );

			// Seed working state from the saved value (copied so we never mutate params).
			var value = control.params.mlsValue || { active: [], inactive: [] };
			control.state = {
				active: ( value.active || [] ).slice(),
				inactive: ( value.inactive || [] ).slice()
			};

			// Surface any catalog choice the saved value never mentions (e.g. a
			// section added after the user last saved) so it is never hidden. It
			// lands where the field's default puts it — Disabled for the slugs in
			// defaultInactive (the Tabs/Accordion containers), Enabled otherwise —
			// which is where the PHP sanitizer will put it on save.
			var known = control.state.active.concat( control.state.inactive );
			var off = control.params.defaultInactive || [];
			control.catalog.forEach( function ( c ) {
				if ( known.indexOf( c.slug ) === -1 ) {
					control.state[ off.indexOf( c.slug ) === -1 ? 'active' : 'inactive' ].push( c.slug );
				}
			} );

			control.renderLists();
		},

		/**
		 * Re-render both lists from current state and re-bind their buttons.
		 */
		renderLists: function () {
			var control = this;
			// Empty both list containers before repopulating.
			var $active = control.container.find( '.mlsimport-arranger__active' ).empty();
			var $inactive = control.container.find( '.mlsimport-arranger__inactive' ).empty();

			// Append one list item per slug in each list, tracking index + length.
			control.state.active.forEach( function ( slug, i ) {
				$active.append( control.itemHtml( slug, 'active', i, control.state.active.length ) );
			} );
			control.state.inactive.forEach( function ( slug, i ) {
				$inactive.append( control.itemHtml( slug, 'inactive', i, control.state.inactive.length ) );
			} );

			control.wire();
		},

		/**
		 * Build the HTML for one arranger row.
		 *
		 * @param  {string} slug Section slug.
		 * @param  {string} list Which list this row belongs to ('active'/'inactive').
		 * @param  {number} i    Row index within its list.
		 * @param  {number} len  Total rows in the list (to decide up/down buttons).
		 * @return {string} List-item markup.
		 */
		itemHtml: function ( slug, list, i, len ) {
			// Human label (fall back to the slug), and the enable/disable toggle glyph.
			var label = this.labelFor[ slug ] || slug;
			var moveLabel = 'active' === list ? '✗' : '✓'; // ✗ disable / ✓ enable
			// Up button only when not first; down button only when not last.
			var up = i > 0 ? '<button type="button" class="button-link mlsimport-arranger__up" aria-label="Move up">↑</button>' : '';
			var down = i < len - 1 ? '<button type="button" class="button-link mlsimport-arranger__down" aria-label="Move down">↓</button>' : '';
			return '<li class="mlsimport-arranger__item" data-slug="' + slug + '" data-list="' + list + '">'
				+ '<span class="mlsimport-arranger__label">' + label + '</span>'
				+ '<span class="mlsimport-arranger__actions">' + up + down
				+ '<button type="button" class="button-link mlsimport-arranger__move" aria-label="Toggle">' + moveLabel + '</button>'
				+ '</span></li>';
		},

		/**
		 * Bind up/down/toggle click handlers on every rendered row.
		 */
		wire: function () {
			var control = this;
			control.container.find( '.mlsimport-arranger__item' ).each( function () {
				// Read the row's slug and which list it lives in from data attributes.
				var $item = $( this );
				var slug = $item.data( 'slug' );
				var list = $item.data( 'list' );

				// Move up one position within the same list.
				$item.find( '.mlsimport-arranger__up' ).on( 'click', function () {
					control.move( list, slug, -1 );
				} );
				// Move down one position within the same list.
				$item.find( '.mlsimport-arranger__down' ).on( 'click', function () {
					control.move( list, slug, 1 );
				} );
				// Toggle the row between the active and inactive lists.
				$item.find( '.mlsimport-arranger__move' ).on( 'click', function () {
					control.flip( list, slug );
				} );
			} );
		},

		/**
		 * Reorder a slug within its list by delta positions, then sync.
		 *
		 * @param {string} list  List key ('active'/'inactive').
		 * @param {string} slug  Slug to move.
		 * @param {number} delta -1 to move up, +1 to move down.
		 */
		move: function ( list, slug, delta ) {
			var arr = this.state[ list ];
			var idx = arr.indexOf( slug );
			var to = idx + delta;
			// Ignore if the slug is missing or the target index is out of bounds.
			if ( idx === -1 || to < 0 || to >= arr.length ) {
				return;
			}
			// Remove from the old index and re-insert at the target index.
			arr.splice( idx, 1 );
			arr.splice( to, 0, slug );
			this.sync();
		},

		/**
		 * Move a slug from one list to the other (enable <-> disable), then sync.
		 *
		 * @param {string} from Source list key ('active'/'inactive').
		 * @param {string} slug Slug to flip.
		 */
		flip: function ( from, slug ) {
			// Destination is the opposite list.
			var to = 'active' === from ? 'inactive' : 'active';
			var idx = this.state[ from ].indexOf( slug );
			// Nothing to do if the slug isn't in the source list.
			if ( idx === -1 ) {
				return;
			}
			// Remove from source, append to destination.
			this.state[ from ].splice( idx, 1 );
			this.state[ to ].push( slug );
			this.sync();
		},

		/**
		 * Write current state back to the Customizer setting and re-render.
		 */
		sync: function () {
			// A fresh object so the Customizer sees the value as changed.
			this.setting.set( {
				active: this.state.active.slice(),
				inactive: this.state.inactive.slice()
			} );
			this.renderLists();
		}
	} );
} )( window.wp, window.jQuery );
