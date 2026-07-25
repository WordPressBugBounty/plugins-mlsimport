/**
 * Renders the standalone property map with Leaflet + OpenStreetMap. Each map
 * element carries data-lat/data-lng plus the listing's price/image/facts; the marker
 * is a "price pin" pill and clicking it opens an info-card. No jQuery.
 */
( function () {
	/**
	 * HTML-escape a value for safe interpolation into marker/card markup.
	 *
	 * @param {*} value Any value; null/undefined become an empty string.
	 * @return {string} The escaped string.
	 */
	function esc( value ) {
		return String( value == null ? '' : value ).replace( /[&<>"]/g, function ( ch ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ ch ];
		} );
	}

	/**
	 * Abbreviate a raw price into a compact pin label.
	 *
	 * "$150,000" → "$150K", "$1,250,000" → "$1.3M". Pure; falls back to '' when empty.
	 *
	 * @param {string|number} raw Raw price value.
	 * @return {string} Compact price label, or '' when non-positive/invalid.
	 */
	function shortPrice( raw ) {
		// Reject non-positive / non-finite prices.
		var n = parseFloat( raw );
		if ( ! isFinite( n ) || n <= 0 ) {
			return '';
		}
		// Millions: one decimal only when not a whole million.
		if ( n >= 1e6 ) {
			return '$ ' + ( n / 1e6 ).toFixed( n % 1e6 ? 1 : 0 ).replace( /\.0$/, '' ) + 'M';
		}
		// Thousands: round to the nearest K.
		if ( n >= 1e3 ) {
			return '$ ' + Math.round( n / 1e3 ) + 'K';
		}
		// Under a thousand: plain rounded dollars.
		return '$ ' + Math.round( n );
	}

	/**
	 * Read all listing fields off a map node's data-* attributes into a plain object.
	 *
	 * @param {Element} node The map container element.
	 * @return {Object} Parsed listing data (lat/lng as numbers, rest as strings).
	 */
	function readData( node ) {
		// Small helper: read an attribute, defaulting to empty string.
		var get = function ( key ) {
			return node.getAttribute( key ) || '';
		};
		return {
			lat: parseFloat( get( 'data-lat' ) ),
			lng: parseFloat( get( 'data-lng' ) ),
			title: get( 'data-title' ),
			url: get( 'data-url' ),
			image: get( 'data-image' ),
			price: get( 'data-price' ),
			priceRaw: get( 'data-price-raw' ),
			beds: get( 'data-beds' ),
			baths: get( 'data-baths' ),
			area: get( 'data-area' ),
			tile: get( 'data-tile' ),
			zoom: parseInt( get( 'data-zoom' ), 10 )
		};
	}

	/**
	 * Build the price-pin marker markup for a listing.
	 *
	 * @param {Object} d Listing data from {@link readData}.
	 * @return {string} HTML for the pin pill (falls back to a bullet when no price).
	 */
	function pinHTML( d ) {
		// Prefer the abbreviated price, then the formatted price, then a bullet placeholder.
		var label = shortPrice( d.priceRaw ) || d.price || '•';
		return '<span class="mlsimport-map-pin">' + esc( label ) + '</span>';
	}

	/**
	 * Build the "3 beds · 3 baths · 3,043 ft²" facts line for the info card.
	 *
	 * @param {Object} d Listing data from {@link readData}.
	 * @return {string} The joined facts line (empty when no facts present).
	 */
	function factsLine( d ) {
		// Spell the specs out to match the listing card's line ("3 beds · 3 baths ·
		// 3,043 ft²") rather than the abbreviated BD/BA. Area is already whole-number
		// (rounded server-side, like the card).
		var parts = [];
		// Beds: singular/plural on the count.
		if ( '' !== d.beds ) {
			parts.push( esc( d.beds ) + ( parseFloat( d.beds ) === 1 ? ' bed' : ' beds' ) );
		}
		// Baths: singular/plural on the count.
		if ( '' !== d.baths ) {
			parts.push( esc( d.baths ) + ( parseFloat( d.baths ) === 1 ? ' bath' : ' baths' ) );
		}
		// Area in square feet, when present.
		if ( '' !== d.area ) {
			parts.push( esc( d.area ) + ' ft²' );
		}
		return parts.join( ' · ' );
	}

	/**
	 * Build the full info-card markup (media, title, price, facts) for a listing.
	 *
	 * @param {Object} d Listing data from {@link readData}.
	 * @return {string} The info-card HTML.
	 */
	function cardHTML( d ) {
		// Title as a link when a URL is present, otherwise a plain span.
		var title = d.url
			? '<a class="mlsimport-map-card__title" href="' + esc( d.url ) + '">' + esc( d.title ) + '</a>'
			: '<span class="mlsimport-map-card__title">' + esc( d.title ) + '</span>';
		// Optional media block, rendered as a background image when an image URL exists.
		var media = d.image
			? '<div class="mlsimport-map-card__media" role="img" aria-label="' + esc( d.title ) + '" style="background-image:url(\'' + esc( d.image ) + '\')"></div>'
			: '';
		// Facts line (beds/baths/area).
		var facts = factsLine( d );
		// Assemble the card, omitting price/facts blocks when empty.
		return '<div class="mlsimport-map-card">'
			+ media
			+ '<div class="mlsimport-map-card__body">'
			+ title
			+ ( d.price ? '<div class="mlsimport-map-card__price">' + esc( d.price ) + '</div>' : '' )
			+ ( facts ? '<div class="mlsimport-map-card__facts">' + facts + '</div>' : '' )
			+ '</div>'
			+ '</div>';
	}

	/**
	 * Render the map with Leaflet + OpenStreetMap tiles and a price-pin marker + popup.
	 *
	 * @param {Element} node Map container element.
	 * @param {Object}  d    Listing data from {@link readData}.
	 * @return {void}
	 */
	function initLeaflet( node, d ) {
		// Bail if the Leaflet library is unavailable.
		var L = window.L;
		if ( typeof L === 'undefined' ) {
			return;
		}
		// Cooperative gestures: don't hijack the page scroll. Wheel-zoom is OFF until
		// the visitor clicks into the map, and turns back off when the mouse leaves —
		// so scrolling the page past the map scrolls the page, not the map.
		// Create the map centred on the listing at the admin's default zoom (data-zoom,
		// the "Default Maps zoom" setting); fall back to 14 if it is absent/invalid.
		// Enable wheel-zoom on click, disable on mouse-out.
		var zoom = ( isFinite( d.zoom ) && d.zoom > 0 ) ? d.zoom : 14;
		var map = L.map( node, { scrollWheelZoom: false } ).setView( [ d.lat, d.lng ], zoom );
		map.on( 'click', function () { map.scrollWheelZoom.enable(); } );
		map.on( 'mouseout', function () { map.scrollWheelZoom.disable(); } );
		// Add the tile layer (custom tile URL or the default OSM tiles).
		L.tileLayer( d.tile || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; OpenStreetMap contributors'
		} ).addTo( map );

		// Build the HTML price-pin as a divIcon marker.
		var icon = L.divIcon( {
			className: 'mlsimport-map-pin-wrap',
			html: pinHTML( d ),
			iconSize: null
		} );
		// Place the marker and attach the info-card popup.
		var marker = L.marker( [ d.lat, d.lng ], { icon: icon } ).addTo( map );
		marker.bindPopup( cardHTML( d ), {
			className: 'mlsimport-map-popup',
			minWidth: 260,
			maxWidth: 340,
			// Sit just above the price pin — ~3px gap between the pin and the card.
			offset: [ 0, -24 ]
		} );
		// Open the card immediately.
		marker.openPopup();
	}

	/**
	 * Mount every not-yet-initialised map on the page.
	 *
	 * @return {void}
	 */
	function init() {
		// Select map containers that haven't been mounted yet.
		var nodes = document.querySelectorAll( '[data-mlsimport-map]:not([data-mlsimport-mounted])' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			// Mark mounted so a second init() pass skips it.
			node.setAttribute( 'data-mlsimport-mounted', '1' );
			// Parse the listing data; skip nodes without valid coordinates.
			var d = readData( node );
			if ( isNaN( d.lat ) || isNaN( d.lng ) ) {
				return;
			}
			initLeaflet( node, d );
		} );
	}

	// Defer init until the DOM is ready; run immediately if it already is.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
