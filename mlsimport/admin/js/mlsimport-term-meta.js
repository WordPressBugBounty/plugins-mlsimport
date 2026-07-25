/**
 * Term featured-image picker for the plugin taxonomy term screens.
 *
 * Opens the native WordPress media modal, writes the chosen attachment id into
 * the hidden .mlsimport-term-image__id input, and shows a preview. "Remove"
 * clears the id and hides the preview. Works on both the Add and Edit term
 * screens (the Add form is cleared/rebuilt over AJAX, so bind by delegation).
 */
( function ( $ ) {
	'use strict';

	// Single reusable media-modal instance (recreated per open below).
	var frame = null;

	// "Set featured image" click — delegated so it works on the AJAX-rebuilt Add form too.
	$( document ).on( 'click', '.mlsimport-term-image__set', function ( e ) {
		// Don't let the button submit / follow a link.
		e.preventDefault();

		// The wrapper holding this row's hidden id input, preview and remove button.
		var $wrap = $( this ).closest( '.mlsimport-term-image__wrap' );

		// Open a fresh media frame limited to a single image selection.
		frame = wp.media( {
			title: 'Select featured image',
			button: { text: 'Use this image' },
			library: { type: 'image' },
			multiple: false
		} );

		// When the user confirms a selection, copy the attachment into this row.
		frame.on( 'select', function () {
			// First (only) selected attachment as a plain object.
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			// Prefer the medium-size URL for the preview; fall back to the full URL.
			var src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;

			// Store the chosen attachment id in the hidden input that gets saved.
			$wrap.find( '.mlsimport-term-image__id' ).val( attachment.id );
			// Show the preview image.
			$wrap.find( '.mlsimport-term-image__preview' ).attr( 'src', src ).css( 'display', 'block' );
			// Reveal the remove button now that an image is set.
			$wrap.find( '.mlsimport-term-image__remove' ).css( 'display', 'inline-block' );
		} );

		// Show the media modal.
		frame.open();
	} );

	// "Remove image" click — clears the stored id and hides the preview.
	$( document ).on( 'click', '.mlsimport-term-image__remove', function ( e ) {
		// Don't let the button submit / follow a link.
		e.preventDefault();
		// The wrapper for this row.
		var $wrap = $( this ).closest( '.mlsimport-term-image__wrap' );
		// Clear the stored attachment id.
		$wrap.find( '.mlsimport-term-image__id' ).val( '' );
		// Blank and hide the preview image.
		$wrap.find( '.mlsimport-term-image__preview' ).attr( 'src', '' ).css( 'display', 'none' );
		// Hide the remove button itself.
		$( this ).css( 'display', 'none' );
	} );
} )( jQuery );
