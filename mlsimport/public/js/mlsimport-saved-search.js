/**
 * Saved Search — the "Save this search" button and its modal (Standalone mode).
 *
 * The server prints the button + a native <dialog> into the Search Results
 * block's toolbar (Mlsimport_Saved_Search_Front::toolbar_button). This script:
 *
 *   1. opens the dialog when the button is clicked;
 *   2. on submit, gathers the CURRENT filters from the grid's own search form
 *      (.mlsimport-search — the same state carrier the AJAX repaint reads) plus
 *      the modal's name / email / consent, and POSTs them to admin-ajax;
 *   3. shows the server's answer inside the dialog (success: "check your
 *      email"; error: e.g. "Choose at least one filter first").
 *
 * The server validates everything again (consent, filters, email) — nothing
 * here is trusted. Config arrives as window.MLSImportSavedSearch.
 */
( function () {
	'use strict';

	var cfg = window.MLSImportSavedSearch || null;
	if ( ! cfg ) {
		return;
	}

	/**
	 * Build the request body: envelope + current filters + the modal's fields.
	 * FormData keeps multi-selects intact (name="city[]" repeats per value).
	 *
	 * @param {HTMLFormElement|null} searchForm The grid's search form, if any.
	 * @param {HTMLFormElement}      modalForm  The modal's form.
	 * @return {FormData}
	 */
	function buildBody( searchForm, modalForm ) {
		var body = new FormData();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		// The page this search is saved from — the server rebuilds the final
		// "see all" link from its path plus the saved criteria.
		body.append( 'results_url', window.location.href );

		// Current filters. Empty controls are skipped; envelope names never pass.
		if ( searchForm ) {
			new FormData( searchForm ).forEach( function ( value, name ) {
				if ( value === '' || name === 'action' || name === 'nonce' ) {
					return;
				}
				body.append( name, value );
			} );
		}
		// Name, email, consent, honeypot.
		new FormData( modalForm ).forEach( function ( value, name ) {
			body.append( name, value );
		} );
		return body;
	}

	/**
	 * Wire one Save button to its dialog.
	 *
	 * @param {HTMLElement} button The [data-mlsimport-save-search] button.
	 */
	function init( button ) {
		var root    = button.closest( '.mlsimport-listings' );
		var dialog  = root ? root.querySelector( '[data-mlsimport-save-search-dialog]' ) : null;
		// No <dialog> support (very old browsers): leave the button inert.
		if ( ! dialog || typeof dialog.showModal !== 'function' ) {
			button.hidden = true;
			return;
		}
		var form    = dialog.querySelector( 'form' );
		var message = dialog.querySelector( '.mlsimport-save-search__message' );
		var submit  = dialog.querySelector( '.mlsimport-save-search__submit' );

		button.addEventListener( 'click', function () {
			message.textContent = '';
			message.className   = 'mlsimport-save-search__message';
			submit.disabled     = false;
			dialog.showModal();
		} );

		// Two closers share the attribute: the top-right "x" and the Cancel button.
		dialog.querySelectorAll( '[data-mlsimport-save-search-cancel]' ).forEach( function ( closer ) {
			closer.addEventListener( 'click', function () {
				dialog.close();
			} );
		} );

		form.addEventListener( 'submit', function ( e ) {
			// method="dialog" would just close it; we post over AJAX instead.
			e.preventDefault();
			submit.disabled = true;

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', cfg.ajaxurl );
			xhr.onload = function () {
				var res = null;
				try {
					res = JSON.parse( xhr.responseText );
				} catch ( err ) {
					res = null;
				}
				var ok = !! ( res && res.success );
				message.textContent = res && res.data && res.data.message ? res.data.message : cfg.error;
				message.className   = 'mlsimport-save-search__message ' + ( ok ? 'is-success' : 'is-error' );
				// After a success the form is done; after an error let them fix and retry.
				submit.disabled = ok;
			};
			xhr.onerror = function () {
				message.textContent = cfg.error;
				message.className   = 'mlsimport-save-search__message is-error';
				submit.disabled     = false;
			};
			xhr.send( buildBody( root.querySelector( '.mlsimport-search' ), form ) );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-mlsimport-save-search]' ), init );
	} );
}() );
