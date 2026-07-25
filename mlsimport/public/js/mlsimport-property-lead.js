/**
 * Submits the standalone property lead forms over AJAX (no page reload).
 *
 * Every form[data-mlsimport-lead] posts its fields plus the shared action to
 * admin-ajax. The nonce + honeypot travel as form fields. On success, if a
 * [data-lead-success] block follows the form (booking panels), the form is
 * swapped out for it; otherwise the inline status message stands. No jQuery.
 */
( function () {
	/**
	 * Post one lead form to admin-ajax and reflect the result inline (message / success swap).
	 *
	 * @param {HTMLFormElement} form The lead form being submitted.
	 * @return {void}
	 */
	function submit( form ) {
		// Cache the status line and submit button; build the payload from the form fields.
		var status = form.querySelector( '.mlsimport-property-lead-form__status' );
		var button = form.querySelector( 'button[type="submit"]' );
		var data   = new FormData( form );
		// Attach the shared AJAX action name that routes to the PHP handler.
		data.append( 'action', 'mlsimport_property_lead' );

		// Disable the button to prevent double submits while the request is in flight.
		if ( button ) {
			button.disabled = true;
		}
		// Clear any prior status message.
		if ( status ) {
			status.textContent = '';
		}

		// POST to the localized ajaxurl (empty string falls back to same-origin current URL).
		fetch( ( window.MLSImportLead && window.MLSImportLead.ajaxurl ) || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			// Parse the JSON envelope from admin-ajax.
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				// Show the server-supplied message when present.
				var msg = res && res.data && res.data.message ? res.data.message : '';
				if ( status ) {
					status.textContent = msg;
				}
				// On success, reset the form and, if a sibling success block exists, swap it in.
				if ( res && res.success ) {
					form.reset();
					var success = form.parentNode && form.parentNode.querySelector
						? form.parentNode.querySelector( '[data-lead-success]' )
						: null;
					if ( success ) {
						form.style.display = 'none';
						success.hidden = false;
					}
				}
			} )
			// Network / parse failure: show a generic retry message.
			.catch( function () {
				if ( status ) {
					status.textContent = 'Network error. Please try again.';
				}
			} )
			// Always re-enable the button once the request settles.
			.then( function () {
				if ( button ) {
					button.disabled = false;
				}
			} );
	}

	// Delegated submit handler: intercept only tagged lead forms and send them via AJAX.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( form && form.matches && form.matches( 'form[data-mlsimport-lead]' ) ) {
			e.preventDefault();
			submit( form );
		}
	} );
} )();
