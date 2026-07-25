/**
 * Location field autocomplete for the standalone search form.
 *
 * Attaches to every input[data-mlsimport-location] and fills the sibling
 * .mlsimport-location__list with city / area / county / ZIP suggestions from the
 * mlsimport_locations endpoint. Picking one only writes the text into the input —
 * the WHERE-builder does the OR across all four, so the form's GET contract is
 * unchanged and the field still works with this script absent.
 *
 * Plain ES5, no build step, no dependencies.
 */
( function () {
	var cfg = window.MLSImportLocations;
	if ( ! cfg || ! cfg.ajaxurl ) {
		return;
	}

	var MIN_CHARS = 2;
	var DEBOUNCE  = 200;

	/**
	 * Wire one location input to its suggestion list.
	 *
	 * @param {HTMLInputElement} input - The text input.
	 */
	function attach( input ) {
		var list = input.parentNode ? input.parentNode.querySelector( '.mlsimport-location__list' ) : null;
		if ( ! list ) {
			return;
		}

		var timer   = null;
		var request = null;
		var active  = -1;

		function close() {
			list.hidden = true;
			list.innerHTML = '';
			active = -1;
		}

		// Move the highlight and mirror it onto the input, so Enter picks what the
		// visitor sees highlighted rather than whatever they half-typed.
		function highlight( next ) {
			var options = list.querySelectorAll( '.mlsimport-location__option' );
			if ( ! options.length ) {
				return;
			}
			if ( active >= 0 && options[ active ] ) {
				options[ active ].classList.remove( 'is-active' );
			}
			active = ( next + options.length ) % options.length;
			options[ active ].classList.add( 'is-active' );
		}

		function choose( value ) {
			input.value = value;
			close();
		}

		function render( items ) {
			if ( ! items.length ) {
				close();
				return;
			}
			list.innerHTML = '';
			items.forEach( function ( item ) {
				var li = document.createElement( 'li' );
				li.className = 'mlsimport-location__option';
				li.setAttribute( 'role', 'option' );

				var text = document.createElement( 'span' );
				text.textContent = item.value;
				li.appendChild( text );

				var tag = document.createElement( 'span' );
				tag.className = 'mlsimport-location__type';
				tag.textContent = item.type;
				li.appendChild( tag );

				// mousedown, not click: blur fires first on click and would close the
				// list out from under the pointer before the pick registers.
				li.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault();
					choose( item.value );
				} );
				list.appendChild( li );
			} );
			active = -1;
			list.hidden = false;
		}

		function fetchSuggestions( term ) {
			if ( request ) {
				request.abort();
			}
			request = new XMLHttpRequest();
			request.open( 'GET', cfg.ajaxurl + '?action=' + encodeURIComponent( cfg.action ) + '&nonce=' + encodeURIComponent( cfg.nonce ) + '&term=' + encodeURIComponent( term ), true );
			request.onload = function () {
				if ( request.status < 200 || request.status >= 300 ) {
					return;
				}
				var payload;
				try {
					payload = JSON.parse( request.responseText );
				} catch ( e ) {
					return;
				}
				if ( payload && payload.success && Array.isArray( payload.data ) ) {
					render( payload.data );
				}
			};
			request.send();
		}

		input.addEventListener( 'input', function () {
			var term = input.value.trim();
			window.clearTimeout( timer );
			if ( term.length < MIN_CHARS ) {
				close();
				return;
			}
			timer = window.setTimeout( function () {
				fetchSuggestions( term );
			}, DEBOUNCE );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( list.hidden ) {
				return;
			}
			if ( 'ArrowDown' === e.key ) {
				e.preventDefault();
				highlight( active + 1 );
			} else if ( 'ArrowUp' === e.key ) {
				e.preventDefault();
				highlight( active - 1 );
			} else if ( 'Enter' === e.key && active >= 0 ) {
				// Only swallow Enter when a suggestion is highlighted; otherwise the
				// visitor is submitting their own typed text and the form must submit.
				e.preventDefault();
				var option = list.querySelectorAll( '.mlsimport-location__option' )[ active ];
				if ( option ) {
					choose( option.firstChild.textContent );
				}
			} else if ( 'Escape' === e.key ) {
				close();
			}
		} );

		input.addEventListener( 'blur', close );
	}

	document.querySelectorAll( 'input[data-mlsimport-location]' ).forEach( attach );
} )();
