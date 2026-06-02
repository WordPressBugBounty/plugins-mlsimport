/**
 * MLS Import — deactivation exit survey.
 *
 * Intercepts the "Deactivate" link for the MLS Import plugin on the Plugins
 * screen, shows a short survey modal, sends the chosen reason to the server,
 * then proceeds with the original deactivation. Submitting or skipping both
 * complete the deactivation — the user is never trapped.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.mlsimport_deact_survey || {};

	$( function () {

		// The Deactivate link inside the MLS Import plugin's row.
		var $link = $( 'tr[data-plugin="' + cfg.plugin_basename + '"] span.deactivate a' );
		if ( ! $link.length ) {
			return;
		}

		var deactivateUrl = '';

		// --- build the modal once ------------------------------------------
		var optionsHtml = '';
		$.each( cfg.options || {}, function ( value, label ) {
			optionsHtml +=
				'<label class="mlsimport-es-option">' +
					'<input type="radio" name="mlsimport_exit_reason" value="' + value + '"> ' +
					'<span>' + label + '</span>' +
				'</label>';
		} );

		var $modal = $(
			'<div id="mlsimport-exit-survey-modal" class="mlsimport-es-hidden">' +
				'<div class="mlsimport-es-backdrop"></div>' +
				'<div class="mlsimport-es-card" role="dialog" aria-modal="true">' +
					'<h2 class="mlsimport-es-title">' + ( cfg.i18n.title || '' ) + '</h2>' +
					'<p class="mlsimport-es-intro">' + ( cfg.i18n.intro || '' ) + '</p>' +
					'<div class="mlsimport-es-options">' + optionsHtml + '</div>' +
					'<textarea class="mlsimport-es-other mlsimport-es-hidden" rows="3" ' +
						'placeholder="' + ( cfg.i18n.other_placeholder || '' ) + '"></textarea>' +
					'<div class="mlsimport-es-actions">' +
						'<button type="button" class="button button-primary mlsimport-es-submit" disabled>' +
							( cfg.i18n.submit || '' ) +
						'</button>' +
						'<a href="#" class="mlsimport-es-skip">' + ( cfg.i18n.skip || '' ) + '</a>' +
					'</div>' +
				'</div>' +
			'</div>'
		);
		$( 'body' ).append( $modal );

		var $submit = $modal.find( '.mlsimport-es-submit' );
		var $other  = $modal.find( '.mlsimport-es-other' );

		// --- intercept the Deactivate click --------------------------------
		$link.on( 'click', function ( e ) {
			e.preventDefault();
			deactivateUrl = $( this ).attr( 'href' );
			$modal.removeClass( 'mlsimport-es-hidden' );
		} );

		// Selecting a reason enables Submit; "other" reveals the text field.
		$modal.on( 'change', 'input[name="mlsimport_exit_reason"]', function () {
			$submit.prop( 'disabled', false );
			$other.toggleClass( 'mlsimport-es-hidden', this.value !== 'other' );
		} );

		function goDeactivate() {
			if ( deactivateUrl ) {
				window.location.href = deactivateUrl;
			}
		}

		// Submit: send the answer, then deactivate regardless of the result.
		$submit.on( 'click', function () {
			$submit.prop( 'disabled', true );
			$.post( cfg.ajax_url, {
				action:   'mlsimport_exit_survey_submit',
				security: cfg.nonce,
				reason:   $modal.find( 'input[name="mlsimport_exit_reason"]:checked' ).val() || '',
				details:  $other.val() || ''
			} ).always( goDeactivate );
		} );

		// Skip: no data sent, deactivate immediately.
		$modal.on( 'click', '.mlsimport-es-skip', function ( e ) {
			e.preventDefault();
			goDeactivate();
		} );

	} );

}( jQuery ) );
