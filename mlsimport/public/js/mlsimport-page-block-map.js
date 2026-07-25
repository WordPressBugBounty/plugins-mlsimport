/**
 * Renders the "Map with Listings" page block with Leaflet + OpenStreetMap, in
 * two data modes:
 *
 *   - data-mode="ids"   — a hand-picked, small set embedded in data-markers; every
 *                         marker is drawn once and the map fits to them.
 *   - data-mode="query" — a filter that may match thousands of listings. The block
 *                         embeds only the overall bounds + the filter; the map fits
 *                         to the bounds, then fetches markers for the CURRENT
 *                         viewport over AJAX. The server returns individual price
 *                         pins when the in-view count is small, or counted clusters
 *                         when it is large — so the browser never loads the whole
 *                         feed. Panning/zooming refetches (debounced).
 *
 * No jQuery. Info-card markup matches the single-property map (mlsimport-property-map.js).
 */
( function () {
	/**
	 * HTML-escape a value for safe insertion into markup.
	 * @param {*} value Any value (null/undefined become '').
	 * @return {string} Escaped string.
	 */
	function esc( value ) {
		return String( value == null ? '' : value ).replace( /[&<>"]/g, function ( ch ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ ch ];
		} );
	}

	// 150000 → "$150K", 1250000 → "$1.3M". Falls back to '' when empty/invalid.
	/**
	 * @param {*} raw Price value.
	 * @return {string} Abbreviated price, or '' when non-positive/invalid.
	 */
	function shortPrice( raw ) {
		var n = parseFloat( raw );
		// Reject empty / non-positive / non-numeric.
		if ( ! isFinite( n ) || n <= 0 ) {
			return '';
		}
		// Millions: 1 decimal unless a round million.
		if ( n >= 1e6 ) {
			return '$ ' + ( n / 1e6 ).toFixed( n % 1e6 ? 1 : 0 ).replace( /\.0$/, '' ) + 'M';
		}
		// Thousands: rounded K.
		if ( n >= 1e3 ) {
			return '$ ' + Math.round( n / 1e3 ) + 'K';
		}
		// Below 1000: plain rounded dollars.
		return '$ ' + Math.round( n );
	}

	/**
	 * @param {*} raw Price value.
	 * @return {string} Full grouped price ("$ 1,250,000"), or '' when invalid.
	 */
	function fullPrice( raw ) {
		var n = parseFloat( raw );
		if ( ! isFinite( n ) || n <= 0 ) {
			return '';
		}
		return '$ ' + n.toLocaleString( 'en-US' );
	}

	/**
	 * @param {Object} m Marker record.
	 * @return {string} Price-pin markup (a bullet when there's no price).
	 */
	function pinHTML( m ) {
		return '<span class="mlsimport-map-pin">' + esc( shortPrice( m.price ) || '•' ) + '</span>';
	}

	// 4928 → "4.9k", 120 → "120". Keeps the cluster bubble readable at any count.
	/**
	 * @param {number} count Listings in the cluster.
	 * @return {string} Compact label.
	 */
	function clusterLabel( count ) {
		return count >= 1000 ? ( Math.round( count / 100 ) / 10 ) + 'k' : String( count );
	}

	/**
	 * @param {number} count Listings in the cluster.
	 * @return {string} Size bucket 'sm' | 'md' | 'lg' for bubble styling.
	 */
	function clusterSize( count ) {
		return count >= 1000 ? 'lg' : ( count >= 100 ? 'md' : 'sm' );
	}

	/**
	 * @param {number} count Listings in the cluster.
	 * @return {string} Cluster-bubble markup.
	 */
	function clusterHTML( count ) {
		return '<span class="mlsimport-map-cluster mlsimport-map-cluster--' + clusterSize( count ) + '">'
			+ esc( clusterLabel( count ) ) + '</span>';
	}

	/**
	 * Build the beds/baths/area facts string for an info card.
	 * @param {Object} m Marker record.
	 * @return {string} " · "-joined facts.
	 */
	function factsLine( m ) {
		// Spell the specs out to match the listing card + the single-property map
		// popup ("3 beds · 3 baths · 3,043 ft²"), not the abbreviated BD/BA.
		var parts = [];
		// Beds (singular/plural).
		if ( m.beds ) {
			parts.push( esc( m.beds ) + ( parseFloat( m.beds ) === 1 ? ' bed' : ' beds' ) );
		}
		// Baths (singular/plural).
		if ( m.baths ) {
			parts.push( esc( m.baths ) + ( parseFloat( m.baths ) === 1 ? ' bath' : ' baths' ) );
		}
		// Living area in square feet.
		if ( m.area ) {
			parts.push( esc( m.area ) + ' ft²' );
		}
		return parts.join( ' · ' );
	}

	// Image + title + price + beds/baths/area — same info-card the single-property
	// map renders, so every map surface shows the identical infobox.
	/**
	 * Build the info-card (popup) markup for a marker.
	 * @param {Object} m Marker record.
	 * @return {string} Card HTML.
	 */
	function cardHTML( m ) {
		// Title links to the listing when a URL is present.
		var title = m.url
			? '<a class="mlsimport-map-card__title" href="' + esc( m.url ) + '">' + esc( m.title ) + '</a>'
			: '<span class="mlsimport-map-card__title">' + esc( m.title ) + '</span>';
		// Optional media block using the image as a CSS background.
		var media = m.image
			? '<div class="mlsimport-map-card__media" role="img" aria-label="' + esc( m.title ) + '" style="background-image:url(\'' + esc( m.image ) + '\')"></div>'
			: '';
		// Formatted price and specs line.
		var price = fullPrice( m.price );
		var facts = factsLine( m );
		return '<div class="mlsimport-map-card">'
			+ media
			+ '<div class="mlsimport-map-card__body">'
			+ title
			+ ( price ? '<div class="mlsimport-map-card__price">' + esc( price ) + '</div>' : '' )
			+ ( facts ? '<div class="mlsimport-map-card__facts">' + facts + '</div>' : '' )
			+ '</div>'
			+ '</div>';
	}

	/**
	 * Parse a JSON-valued data-* attribute.
	 * @param {Element} node Element carrying the attribute.
	 * @param {string}  attr Attribute name.
	 * @return {*} Parsed value, or null when missing/invalid.
	 */
	function readJSON( node, attr ) {
		var raw = node.getAttribute( attr );
		// Missing attribute → null.
		if ( ! raw ) {
			return null;
		}
		// Tolerate malformed JSON by returning null.
		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Filter a marker list to those with finite lat/lng.
	 * @param {Array} list Raw markers.
	 * @return {Array} Markers with usable coordinates.
	 */
	function validMarkers( list ) {
		return ( list || [] ).filter( function ( m ) {
			return m && isFinite( parseFloat( m.lat ) ) && isFinite( parseFloat( m.lng ) );
		} );
	}

	// The v1 live-mode map state: more in-view listings than the marker cap and
	// no cluster source, so the payload asks the visitor to zoom in. The notice
	// is an overlay child of the map container — the map itself is untouched.
	/**
	 * Show or remove the "zoom in to view listings" overlay on the map container.
	 * @param {Element} node Map container.
	 * @param {boolean} on   Whether to show the notice.
	 */
	function setZoomNotice( node, on ) {
		var el = node.querySelector( '.mlsimport-map__zoom-notice' );
		// Turning it off: remove the overlay if present, then bail.
		if ( ! on ) {
			if ( el && el.parentNode ) {
				el.parentNode.removeChild( el );
			}
			return;
		}
		// Turning it on: create the overlay on first use.
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.className = 'mlsimport-map__zoom-notice';
			node.appendChild( el );
		}
		// Localised text (falls back to English default).
		el.textContent = ( window.MLSImportMap && window.MLSImportMap.zoomInText ) || 'Zoom in to view listings';
	}

	/**
	 * Debounce a function to fire once after `wait` ms of quiet.
	 * @param {Function} fn   Function to debounce.
	 * @param {number}   wait Quiet period in ms.
	 * @return {Function} Debounced wrapper.
	 */
	function debounce( fn, wait ) {
		var t;
		return function () {
			// Preserve call context/args for the deferred invocation.
			var ctx = this;
			var args = arguments;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( ctx, args );
			}, wait );
		};
	}

	// Replace a filters object's contents in place, so closures that captured the
	// reference (the map's viewport refresh) see the new filter without re-init.
	// Used by the Half Map coordinator via node.mlsimportMap.setFilters().
	/**
	 * @param {Object} target Filters object to mutate in place.
	 * @param {Object} next   New filter values to copy in.
	 */
	function replaceFilters( target, next ) {
		// Drop every existing key.
		Object.keys( target ).forEach( function ( k ) {
			delete target[ k ];
		} );
		// Copy in the replacement keys.
		Object.keys( next || {} ).forEach( function ( k ) {
			target[ k ] = next[ k ];
		} );
	}

	// ---- Viewport AJAX (query mode) -----------------------------------------
	// Serialize filters + bbox + zoom into an admin-ajax POST body. Filters may be
	// scalars or arrays (multi-select); arrays submit as key[]=v so the PHP
	// whitelist maps them like the search form does.
	/**
	 * Serialize filters + bounding box + zoom into the admin-ajax POST body.
	 * @param {Object} cfg     Localized config ({ action, nonce, ajaxurl }).
	 * @param {Object} filters Filter key -> value (arrays for multi-selects).
	 * @param {Object} box     { latMin, latMax, lngMin, lngMax } viewport.
	 * @param {number} zoom    Current map zoom level.
	 * @return {string} Encoded body string.
	 */
	function requestBody( cfg, filters, box, zoom ) {
		// Fixed envelope: action, nonce, zoom, and the four bbox edges.
		var parts = [
			'action=' + encodeURIComponent( cfg.action || 'mlsimport_markers' ),
			'nonce=' + encodeURIComponent( cfg.nonce || '' ),
			'zoom=' + encodeURIComponent( zoom ),
			'lat_min=' + encodeURIComponent( box.latMin ),
			'lat_max=' + encodeURIComponent( box.latMax ),
			'lng_min=' + encodeURIComponent( box.lngMin ),
			'lng_max=' + encodeURIComponent( box.lngMax )
		];
		// Append each filter: arrays as key[]=v, scalars as key=v (skip empties).
		Object.keys( filters || {} ).forEach( function ( key ) {
			var val = filters[ key ];
			if ( Array.isArray( val ) ) {
				val.forEach( function ( v ) {
					parts.push( encodeURIComponent( key + '[]' ) + '=' + encodeURIComponent( v ) );
				} );
			} else if ( val != null && val !== '' ) {
				parts.push( encodeURIComponent( key ) + '=' + encodeURIComponent( val ) );
			}
		} );
		return parts.join( '&' );
	}

	/**
	 * POST the current viewport to the markers endpoint; invoke cb with the payload.
	 * @param {Object}   cfg     Localized config.
	 * @param {Object}   filters Active filters.
	 * @param {Object}   box     Viewport bbox.
	 * @param {number}   zoom    Map zoom level.
	 * @param {Function} cb      Called with res.data on a successful response.
	 */
	function fetchViewport( cfg, filters, box, zoom, cb ) {
		// No AJAX URL configured — nothing to fetch.
		if ( ! cfg.ajaxurl ) {
			return;
		}
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', cfg.ajaxurl );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onreadystatechange = function () {
			// Wait for the request to complete.
			if ( xhr.readyState !== 4 ) {
				return;
			}
			// Only parse 2xx responses; swallow malformed JSON.
			if ( xhr.status >= 200 && xhr.status < 300 ) {
				try {
					var res = JSON.parse( xhr.responseText );
					if ( res && res.success && res.data ) {
						cb( res.data );
					}
				} catch ( e ) {} // eslint-disable-line no-empty
			}
		};
		xhr.send( requestBody( cfg, filters, box, zoom ) );
	}

	// ---- Leaflet ------------------------------------------------------------
	/**
	 * Build the map with Leaflet + OpenStreetMap tiles.
	 * @param {Element} node     Map container.
	 * @param {string}  mode     'ids' or 'query'.
	 * @param {Array}   embedded Pre-validated markers (ids mode).
	 * @param {Object}  filters  Active filters (query mode).
	 * @param {Object}  cfg      Localized config.
	 */
	function initLeaflet( node, mode, embedded, filters, cfg ) {
		var L = window.L;
		// Leaflet library absent — cannot render.
		if ( typeof L === 'undefined' ) {
			return;
		}
		// Cooperative gestures: wheel-zoom off until the map is clicked, so scrolling
		// the page past the map scrolls the page, not the map. (Half Map reuses this.)
		var map  = L.map( node, { scrollWheelZoom: false } );
		map.on( 'click', function () { map.scrollWheelZoom.enable(); } );
		map.on( 'mouseout', function () { map.scrollWheelZoom.disable(); } );
		// Tile source: block-configured override or the default OSM tiles.
		var tile = node.getAttribute( 'data-tile' ) || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
		L.tileLayer( tile, { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' } ).addTo( map );

		// A single layer group we clear and repopulate on each viewport refresh.
		var layer = L.layerGroup().addTo( map );

		/**
		 * Add a price-pin marker (with info-card popup) for a listing.
		 * @param {Object} m Marker record.
		 */
		function addMarker( m ) {
			var pos  = [ parseFloat( m.lat ), parseFloat( m.lng ) ];
			// Custom HTML pin via divIcon.
			var icon = L.divIcon( { className: 'mlsimport-map-pin-wrap', html: pinHTML( m ), iconSize: null } );
			L.marker( pos, { icon: icon } ).addTo( layer )
				.bindPopup( cardHTML( m ), { className: 'mlsimport-map-popup', minWidth: 260, maxWidth: 340, offset: [ 0, -24 ] } );
		}

		/**
		 * Add a cluster bubble that zooms in when clicked.
		 * @param {Object} cl Cluster record ({ lat, lng, count }).
		 */
		function addCluster( cl ) {
			var pos  = [ parseFloat( cl.lat ), parseFloat( cl.lng ) ];
			var icon = L.divIcon( { className: 'mlsimport-map-cluster-wrap', html: clusterHTML( cl.count ), iconSize: null } );
			// Clicking a cluster zooms in two levels (capped at max zoom).
			L.marker( pos, { icon: icon } ).addTo( layer ).on( 'click', function () {
				map.setView( pos, Math.min( map.getZoom() + 2, 19 ) );
			} );
		}

		// ids mode: draw the fixed set and fit the map to them.
		if ( mode === 'ids' ) {
			var pts = [];
			embedded.forEach( function ( m ) {
				addMarker( m );
				pts.push( [ parseFloat( m.lat ), parseFloat( m.lng ) ] );
			} );
			// Single point: center at the admin's default zoom (map_zoom); many: fit their bounds.
			if ( pts.length === 1 ) {
				map.setView( pts[ 0 ], cfg.zoom || 14 );
			} else if ( pts.length > 1 ) {
				map.fitBounds( pts, { padding: [ 36, 36 ] } );
			}
			return;
		}

		// query mode: fit to the overall bounds, then load each viewport.
		var b = readJSON( node, 'data-bounds' );
		if ( b ) {
			map.fitBounds( [ [ b.lat_min, b.lng_min ], [ b.lat_max, b.lng_max ] ], { padding: [ 30, 30 ] } );
		} else if ( typeof cfg.startLat === 'number' && typeof cfg.startLng === 'number' ) {
			// No listings to fit to: fall back to the admin's configured Starting Point
			// + default zoom (map_start_lat/lng, map_zoom) instead of the whole world.
			map.setView( [ cfg.startLat, cfg.startLng ], cfg.zoom || 11 );
		} else {
			// No bounds and no starting point set: show the whole world.
			map.setView( [ 0, 0 ], 2 );
		}

		/**
		 * Fetch and render markers/clusters for the current viewport.
		 * @param {boolean} refit When true, refit to the server's overall bounds.
		 */
		var refresh = function ( refit ) {
			// Current viewport edges → the request bbox.
			var bounds = map.getBounds();
			fetchViewport( cfg, filters, {
				latMin: bounds.getSouth(),
				latMax: bounds.getNorth(),
				lngMin: bounds.getWest(),
				lngMax: bounds.getEast()
			}, map.getZoom(), function ( data ) {
				// Clear the previous render and reflect the zoom-in state.
				layer.clearLayers();
				setZoomNotice( node, data.type === 'zoom_in' );
				// A viewport payload carries both arrays at once: counted bubbles for
				// dense grid cells and price pins for lone listings. Render both (the
				// zoom-in hint is the one state with neither).
				if ( data.type !== 'zoom_in' ) {
					( data.clusters || [] ).forEach( addCluster );
					validMarkers( data.markers ).forEach( addMarker );
				}
				// After a filter change, refit to the filter's overall bounds; the
				// follow-up moveend reloads markers for the new viewport.
				if ( refit && data.bounds ) {
					map.fitBounds(
						[ [ data.bounds.lat_min, data.bounds.lng_min ], [ data.bounds.lat_max, data.bounds.lng_max ] ],
						{ padding: [ 30, 30 ] }
					);
				}
			} );
		};

		// Reload markers after each pan/zoom settles (debounced), plus an initial load.
		map.on( 'moveend', debounce( function () { refresh( false ); }, 250 ) );
		refresh( false );

		// ---- Draw-on-map (polygon) ------------------------------------------
		// A custom click-to-vertex drawer (no Leaflet.draw dependency): each map
		// click drops a vertex; clicking the highlighted start vertex (>=3 points)
		// closes the ring and hands the coordinator the lat/lng list. The drawn
		// shape persists on its own layer until clearDraw() removes it.
		// Dedicated layer for the in-progress / finished drawn shape.
		var drawLayer   = L.layerGroup().addTo( map );
		var drawSession = null;

		/** Cancel any active draw session and wipe the drawn shape. */
		function clearDraw() {
			if ( drawSession ) {
				drawSession.cancel();
			}
			drawLayer.clearLayers();
		}

		/**
		 * Begin a click-to-vertex polygon draw.
		 * @param {Function} onComplete Called with the ring's [{lat,lng}] on close.
		 */
		function startDraw( onComplete ) {
			// Reset any prior drawing.
			clearDraw();
			// Collected vertices, the connecting dashed line, and vertex markers.
			var pts       = [];
			var line      = L.polyline( [], { color: '#2563eb', weight: 2, dashArray: '6,4' } ).addTo( drawLayer );
			var verts     = L.layerGroup().addTo( drawLayer );
			var container = map.getContainer();
			container.classList.add( 'mlsimport-map--drawing' );

			/** Detach handlers and clear the drawing cursor state. */
			function teardown() {
				map.off( 'click', onClick );
				container.classList.remove( 'mlsimport-map--drawing' );
				drawSession = null;
			}
			/** Close the ring (needs >=3 points) and hand coords to the caller. */
			function finish() {
				if ( pts.length < 3 ) {
					return;
				}
				// Swap the working shapes for a filled polygon.
				drawLayer.clearLayers();
				L.polygon( pts, { color: '#2563eb', weight: 2, fillColor: '#2563eb', fillOpacity: 0.08 } ).addTo( drawLayer );
				teardown();
				onComplete( pts.map( function ( p ) {
					return { lat: p.lat, lng: p.lng };
				} ) );
			}
			/** Each map click drops a vertex. @param {Object} e Leaflet click event. */
			function onClick( e ) {
				// Record the vertex and extend the line.
				pts.push( e.latlng );
				line.setLatLngs( pts );
				// The first vertex is drawn larger/filled as the ring-close target.
				var first  = pts.length === 1;
				var marker = L.circleMarker( e.latlng, {
					radius: first ? 6 : 4,
					color: '#2563eb',
					fillColor: first ? '#2563eb' : '#fff',
					fillOpacity: 1,
					weight: 2
				} ).addTo( verts );
				if ( first ) {
					// Clicking the start vertex (once a triangle exists) closes the ring.
					marker.on( 'click', function ( ev ) {
						if ( pts.length >= 3 ) {
							L.DomEvent.stop( ev );
							finish();
						}
					} );
				}
			}

			// Listen for vertex clicks; expose a cancel handle for clearDraw().
			map.on( 'click', onClick );
			drawSession = { cancel: function () {
				drawLayer.clearLayers();
				teardown();
			} };
		}

		// Handle for a coordinator (Half Map): push new filters and refit, fix the
		// map size after its container was revealed (mobile List/Map toggle), or run
		// the polygon drawer.
		node.mlsimportMap = {
			setFilters: function ( next ) {
				replaceFilters( filters, next );
				refresh( true );
			},
			invalidate: function () {
				// Revealed from a hidden pane (mobile toggle): resize, then refit to
				// the filter so the map isn't stuck on the zero-size initial view.
				map.invalidateSize();
				refresh( true );
			},
			startDraw: startDraw,
			clearDraw: clearDraw
		};
	}

	/**
	 * Mount every not-yet-mounted map block.
	 */
	function init() {
		// Every map block that hasn't been initialised yet.
		var nodes = document.querySelectorAll( '.mlsimport-page-block--map .mlsimport-map:not([data-mlsimport-mounted])' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			// Mark mounted so a re-run skips it.
			node.setAttribute( 'data-mlsimport-mounted', '1' );

			// Resolve mode, config, and the mode-specific embedded data.
			var mode     = node.getAttribute( 'data-mode' ) === 'ids' ? 'ids' : 'query';
			var cfg      = window.MLSImportMap || {};
			var embedded = mode === 'ids' ? validMarkers( readJSON( node, 'data-markers' ) ) : [];
			var filters  = mode === 'query' ? ( readJSON( node, 'data-filters' ) || {} ) : {};

			// ids mode with nothing to show: skip.
			if ( mode === 'ids' && ! embedded.length ) {
				return;
			}

			initLeaflet( node, mode, embedded, filters, cfg );
		} );
	}

	// Mount on DOM ready (or immediately if already parsed).
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
