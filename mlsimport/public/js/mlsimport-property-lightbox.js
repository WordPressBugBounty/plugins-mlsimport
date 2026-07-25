/**
 * Click-to-zoom lightbox for standalone property galleries and sliders.
 *
 * Every gallery/slider image is wrapped in <a class="mlsimport-glightbox"
 * data-gallery="<group>" href="<full-size>">. A delegated click handler opens a
 * GLightbox instance built from that group's images and jumps to the clicked one.
 *
 * Delegation (rather than GLightbox's own selector binding) is deliberate: the
 * 'classic' Splide slider runs in loop mode and clones slides, so the same image
 * appears more than once in the DOM. We build each lightbox from the NON-clone
 * anchors only (no duplicate slides) and resolve a clicked clone back to the real
 * image by its href — so clones open the lightbox too, at the correct index.
 */
( function () {
	// Nothing to do if the GLightbox library never loaded.
	if ( typeof window.GLightbox === 'undefined' ) {
		return;
	}

	// group id -> { gl: GLightbox instance, hrefs: [full-size url, ...] }.
	var cache = {};

	/**
	 * Collect the non-clone lightbox anchors for one gallery group.
	 *
	 * @param {string} group The `data-gallery` group id.
	 * @return {Array<Element>} Real (non-Splide-clone) anchor elements for the group.
	 */
	function realAnchors( group ) {
		var out = [];
		// Every candidate lightbox anchor on the page.
		var all = document.querySelectorAll( 'a.mlsimport-glightbox' );
		Array.prototype.forEach.call( all, function ( a ) {
			// Keep only anchors belonging to the requested group.
			if ( a.getAttribute( 'data-gallery' ) !== group ) {
				return;
			}
			// Skip Splide loop clones — they'd duplicate slides in the lightbox.
			if ( a.closest( '.splide__slide--clone' ) ) {
				return;
			}
			out.push( a );
		} );
		return out;
	}

	/**
	 * Build (or return the cached) GLightbox instance and href list for a gallery group.
	 *
	 * @param {string} group The `data-gallery` group id.
	 * @return {{gl: Object, hrefs: Array<string>}} The instance and its ordered full-size URLs.
	 */
	function instanceFor( group ) {
		// Reuse an already-built instance for this group.
		if ( cache[ group ] ) {
			return cache[ group ];
		}
		// Build the element list from the real anchors, recording each href in parallel.
		var anchors  = realAnchors( group );
		var hrefs    = [];
		var elements = anchors.map( function ( a ) {
			var href = a.getAttribute( 'href' );
			hrefs.push( href );
			return { href: href, type: 'image', title: a.getAttribute( 'data-title' ) || '' };
		} );
		// Instantiate GLightbox from the assembled elements with looping/zoom/drag enabled.
		var gl = window.GLightbox( {
			elements: elements,
			loop: true,
			touchNavigation: true,
			zoomable: true,
			draggable: true,
		} );
		// Cache and return the instance alongside its href index.
		cache[ group ] = { gl: gl, hrefs: hrefs };
		return cache[ group ];
	}

	// Delegated click: open the lightbox for any gallery anchor, resolving clones by href.
	document.addEventListener( 'click', function ( e ) {
		// Find the nearest lightbox anchor for the click, if any.
		var link = e.target.closest ? e.target.closest( 'a.mlsimport-glightbox' ) : null;
		if ( ! link ) {
			return;
		}
		// Anchor must declare a gallery group.
		var group = link.getAttribute( 'data-gallery' );
		if ( ! group ) {
			return;
		}
		e.preventDefault();
		// Get the group's lightbox; bail if it has no images.
		var inst = instanceFor( group );
		if ( ! inst.hrefs.length ) {
			return;
		}
		// Map the clicked href back to its real index (clones share hrefs); default to first.
		var idx = inst.hrefs.indexOf( link.getAttribute( 'href' ) );
		inst.gl.openAt( idx < 0 ? 0 : idx );
	} );
}() );
