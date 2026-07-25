/**
 * MLSImport — admin settings/import screen behaviour.
 *
 * Drives the plugin's admin UI: shows/hides the credential fieldsets that match
 * the selected MLS provider, starts/stops imports (global and per-import-task),
 * polls the server for import log/progress, clears caches, batch-deletes
 * properties by taxonomy term, and provides the front-end MLS autocomplete.
 * All server calls go through admin-ajax (ajaxurl / mlsimport_vars.ajax_url).
 *
 * Provider id ranges used by the mlsimport_is_* helpers below decide which
 * credential fields are relevant for the chosen MLS.
 */
jQuery( document ).ready(
	function ($) {
		'use strict';
	
		// Handles for the two polling timers (global import + per-item import).
		var log_refresh_interval;
		var log_refresh_interval_per_item;
		// Poll intervals in milliseconds.
		var timer          = 2000;
		var timer_per_item = 4000;

		// If the Import tab is the active tab on load, begin polling the global import log.
		if (jQuery( '#nav-tab-import' ).hasClass( 'nav-tab-active' )) {
			log_refresh_interval = setInterval( mlsimport_log_interval, timer );
		}

               // Start checking logs only after an import actually begins
               // to avoid showing a completed message on initial page load.

		//mlsimport_autocomplte_mls_selection();
		/**
		* Show / hide extra input field - cities and counties
		*/

		mslimport_show_extra_options();

		/**
		* Show / hide input tokens on load
		*/
		mlsimport_token_on_load();

		/**
		* Show / hide input tokens on change
		*/

		// When the chosen MLS changes, reveal only the credential fieldset(s) that
		// provider needs and hide the rest. Each branch below matches one provider
		// family (by numeric id range) and shows its fields while hiding all others.
		jQuery( '#mlsimport_mls_name' ).on(
			'change',
			function (event) {

				// Numeric id of the selected MLS drives which branch runs.
				var selected_value = jQuery( '#mlsimport_mls_name' ).val();
				selected_value     = parseInt( selected_value );

		
			
                                if ( mlsimport_is_brightmls( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_id, .fieldset_mlsimport_tresle_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).show();
                                } else if ( mlsimport_is_connectmls( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_id, .fieldset_mlsimport_tresle_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).show();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();
                                } else if ( mlsimport_is_realtorca( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_id, .fieldset_mlsimport_tresle_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).show();
                                        jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();
                                } else if ( mlsimport_is_paragon( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_id, .fieldset_mlsimport_tresle_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).show();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();
                                } else if ( mlsimport_is_rapattoni( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_id,.fieldset_mlsimport_tresle_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).show();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();
                                } else if ( mlsimport_is_trestle( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();

                                        jQuery( '.fieldset_mlsimport_tresle_client_id' ).show();
                                        jQuery( '.fieldset_mlsimport_tresle_client_secret' ).show();
                                } else {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).show();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();

                                        jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                                }

			}
		);

		/**
		* Stop Import per item
		*/

		// Stop a single import task: halt client-side polling, then tell the server to stop.
		jQuery( '#mlsimport_stop_item' ).on(
			'click',
			function () {
				console.log( 'mlsimport-stop' );
				// Import task post id and the shared item-actions nonce.
				var post_id = jQuery( this ).attr( 'data-post_id' );
				var nonce = jQuery('#mlsimport_item_actions').val();
				// stop refreshing logs immediately on the client side
				if (typeof log_refresh_interval_per_item !== 'undefined') {
						clearInterval( log_refresh_interval_per_item );
				}
				jQuery.ajax(
					{
						type: 'POST',
						url: ajaxurl,
						data: {
							'action'            :   'mlsimport_stop_import_per_item',
							'post_id'           :   post_id,
							'security'			:	nonce
							// AJAX action + task id + nonce for the stop request.
						},
                                                success: function (data) {
                                                        console.log( data );
                                                       jQuery( '#mlsimport_item_status' ).empty().append( 'Import stopped!' );
                                                },
                                                error: function (errorThrown) {
                                                        console.log( errorThrown );
                                                }
                                        }
				);// end ajax
			}
		);

		/**
		* Start Import per item
		*/

		// Start a single import task. Two triggers share this handler: the normal
		// "start" button and the onboarding "run test" button (which forces a small
		// 5-item onboarding import). Kicks off the per-item log poll, then the import.
		jQuery( '#mlsimport-start_item,#mlsimport-run-test' ).on(
			'click',
			function (event) {
				console.log( 'mlsimport-start' );
				// Gather the task's identifiers and requested batch size from the DOM.
				var ajaxurl     = mlsimport_vars.ajax_url;
				var post_id     = jQuery( this ).attr( 'data-post_id' );
				var post_number = jQuery( this ).attr( 'data-post-number' );
				var how_many    = jQuery( '#mlsimport_item_how_many' ).val();
				var is_onboard  = 0;
				var nonce 		= jQuery('#mlsimport_item_actions').val();
				// Reset the status line to a "starting" message.
				jQuery( '#mlsimport_item_status' ).empty();
				jQuery( '#mlsimport_item_status' ).append( "Starting the import. Please stand by!" );


				// Onboarding "run test" path: disable the button, show a spinner and a
				// progress bar, and force a fixed 5-item onboarding import.
				if (event.target.id === 'mlsimport-run-test') {
					// Do something only when #mlsimport-run-test is clicked
		

							
					jQuery(this).prop('disabled', true);
					jQuery('#mlsimport-test-spinner').show();
					jQuery('.mlsimport-status-message')
						.removeClass('pending')
						.addClass('progress')
						.html('<p><?php _e("Starting import... Please wait.", "mlsimport"); ?></p>' +
						'<div class="mlsimport-progress-bar"><div class="mlsimport-progress-bar-inner" style="width: 20%"></div></div>');

					how_many=5;	
					is_onboard=1;


				} else {
					// Handle #mlsimport-start_item click
					console.log('Start item clicked');
				}

			


				clearInterval( log_refresh_interval_per_item );
				log_refresh_interval_per_item = setInterval( mlsimport_log_interval_per_item, timer_per_item );

			
				jQuery.ajax(
					{
						type: 'POST',
						url: ajaxurl,
						data: {
							'action'            :   'mlsimport_move_files_per_item',
							'post_id'           :   post_id,
							'how_many'          :   how_many,
							'post_number'       :   post_number,
							'is_onboard'		:	is_onboard,
							'security'			:	nonce,
						},
                                                success: function (data) {
                                                        console.log( data );
                                                        if ( data && data.success === false && data.message ) {
                                                                jQuery( '#mlsimport_item_status' ).empty().append( data.message );
                                                                jQuery( '#mlsimport-start_item,#mlsimport-run-test' ).prop( 'disabled', true );
                                                        }

                                                },
                                                error: function (errorThrown) {
                                                        console.log( errorThrown );
                                                        var message = '';
                                                        if ( errorThrown.responseJSON && errorThrown.responseJSON.message ) {
                                                                message = errorThrown.responseJSON.message;
                                                        } else if ( errorThrown.responseText ) {
                                                                try {
                                                                        var parsed = JSON.parse( errorThrown.responseText );
                                                                        message = parsed.message || errorThrown.statusText;
                                                                } catch (e) {
                                                                        message = errorThrown.statusText;
                                                                }
                                                        } else {
                                                                message = errorThrown.statusText;
                                                        }
                                                        jQuery( '#mlsimport_item_status' ).empty().append( message );
                                                        jQuery( '#mlsimport-start_item,#mlsimport-run-test' ).prop( 'disabled', true );
                                                }
                                        }
                                );// end ajax

			}
		);

		/**
		* delete cache
		*/

                // Clear cache tool: ask the server to delete cached MLS data.
                jQuery( '#mlsimport-clear-cache' ).on(
                        'click',
                        function () {
                                var ajaxurl = mlsimport_vars.ajax_url;
                                var nonce  = jQuery('#mlsimport_tool_actions').val();

				jQuery( '#mlsimport-clear-cache' ).val( 'Deleting...' );
				
				jQuery.ajax(
					{
						type: 'POST',
						url: ajaxurl,
						data: {
							'action'            :   'mlsimport_delete_cache',
							'security'							:	nonce
						},
						success: function (data) {
							console.log( data );
							jQuery( '#mlsimport-clear-cache' ).val( 'Deleted!' );

						},
						error: function (errorThrown) {
							console.log( errorThrown );
						}
					}
                                );// end ajax
                        }
                );

                // Clear fields data tool: ask the server to wipe the stored field mapping data.
                jQuery( '#mlsimport-clear-fields-data' ).on(
                        'click',
                        function () {
                                var ajaxurl = mlsimport_vars.ajax_url;
                                var nonce  = jQuery('#mlsimport_tool_actions').val();

                                jQuery( '#mlsimport-clear-fields-data' ).val( 'Deleting...' );

                                jQuery.ajax(
                                        {
                                                type: 'POST',
                                                url: ajaxurl,
                                                data: {
                                                        'action'            :   'mlsimport_clear_fields_data',
                                                        'security'             :   nonce
                                                },
                                                success: function (data) {
                                                        console.log( data );
                                                        jQuery( '#mlsimport-clear-fields-data' ).val( 'Deleted!' );

                                                },
                                                error: function (errorThrown) {
                                                        console.log( errorThrown );
                                                }
                                        }
                                );// end ajax
                        }
                );

		/**
		* delete properties
		*/

		// Load taxonomy terms when taxonomy is selected
		// Populates the dependent term dropdown via AJAX for the chosen taxonomy.
		jQuery( '#mlsimport_delete_category' ).on( 'change', function () {
			var taxonomy = jQuery( this ).val();
			var $termSelect = jQuery( '#mlsimport_delete_category_term' );
			var nonce = jQuery( '#mlsimport_tool_actions' ).val();

			// Clear and disable the term select until fresh terms arrive.
			$termSelect.empty().prop( 'disabled', true );

			// No taxonomy chosen: prompt the user and stop.
			if ( ! taxonomy ) {
				$termSelect.append( '<option value="" disabled>Select a taxonomy first</option>' );
				return;
			}

			$termSelect.append( '<option value="" disabled>Loading...</option>' );

			jQuery.ajax( {
				type: 'POST',
				url: mlsimport_vars.ajax_url,
				dataType: 'json',
				data: {
					action: 'mlsimport_get_taxonomy_terms',
					taxonomy: taxonomy,
					security: nonce
				},
				success: function ( response ) {
					$termSelect.empty();
					if ( response.success && response.data.length ) {
						jQuery.each( response.data, function ( i, term ) {
							$termSelect.append(
								'<option value="' + term.slug + '">' + term.name + ' (' + term.count + ')</option>'
							);
						} );
						$termSelect.prop( 'disabled', false );
					} else {
						$termSelect.append( '<option value="" disabled>No terms found</option>' );
					}
				},
				error: function () {
					$termSelect.empty().append( '<option value="" disabled>Error loading terms</option>' );
				}
			} );
		} );

		// Batched delete
		// Global flag the Stop button flips to end the recursive batch loop.
		window.mlsimportDeleteStopped = false;

		/**
		 * Delete matching properties one server batch at a time, recursing until done.
		 *
		 * @param {string} taxonomy     Selected taxonomy slug.
		 * @param {Array}  terms        Selected term slugs to match.
		 * @param {string} nonce        Tool-actions nonce.
		 * @param {number} totalDeleted Running count carried across batches.
		 */
		function mlsimportDeleteBatch( taxonomy, terms, nonce, totalDeleted ) {
			// Abort the loop if the user pressed Stop.
			if ( window.mlsimportDeleteStopped ) {
				jQuery( '#mlsimport-delete-notification' ).text( 'Stopped. Deleted ' + totalDeleted + ' properties.' );
				mlsimportDeleteResetUI();
				return;
			}

			jQuery.ajax( {
				type: 'POST',
				url: mlsimport_vars.ajax_url,
				dataType: 'json',
				data: {
					action: 'mlsimport_delete_properties',
					mlsimport_delete_category: taxonomy,
					'mlsimport_delete_category_term[]': terms,
					security: nonce
				},
				success: function ( response ) {
					if ( ! response.success ) {
						jQuery( '#mlsimport-delete-notification' ).text( response.data || 'Error' );
						mlsimportDeleteResetUI();
						return;
					}

					// Accumulate deleted count and derive an overall percentage for the bar.
					var data = response.data;
					totalDeleted += data.deleted;
					var totalProperties = totalDeleted + data.remaining;
					var pct = totalProperties > 0 ? Math.round( ( totalDeleted / totalProperties ) * 100 ) : 100;

					jQuery( '#mlsimport-delete-progress-bar' ).css( 'width', pct + '%' );
					jQuery( '#mlsimport-delete-progress-text' ).text( totalDeleted + ' / ' + totalProperties + ' deleted' );
					jQuery( '#mlsimport-delete-notification' ).text( 'Deleting... ' + totalDeleted + ' deleted so far.' );

					// Server signals completion, otherwise recurse for the next batch.
					if ( data.done ) {
						jQuery( '#mlsimport-delete-notification' ).text( 'Done! Deleted ' + totalDeleted + ' properties.' );
						jQuery( '#mlsimport-delete-progress-bar' ).css( 'width', '100%' );
						mlsimportDeleteResetUI();
					} else {
						mlsimportDeleteBatch( taxonomy, terms, nonce, totalDeleted );
					}
				},
				error: function ( xhr ) {
					console.log( xhr );
					jQuery( '#mlsimport-delete-notification' ).text( 'Error during deletion. Deleted ' + totalDeleted + ' so far.' );
					mlsimportDeleteResetUI();
				}
			} );
		}

		/**
		 * Restore the delete controls to their idle state (show start, hide stop).
		 */
		function mlsimportDeleteResetUI() {
			jQuery( '#mlsimport-delete-prop' ).show();
			jQuery( '#mlsimport-delete-stop' ).hide();
		}

		// Start button: validate selections, confirm, then kick off the batch loop.
		jQuery( '#mlsimport-delete-prop' ).on( 'click', function () {
			var taxonomy = jQuery( '#mlsimport_delete_category' ).val();
			var terms = jQuery( '#mlsimport_delete_category_term' ).val();
			var nonce = jQuery( '#mlsimport_tool_actions' ).val();

			if ( ! taxonomy ) {
				jQuery( '#mlsimport-delete-notification' ).text( 'Please select a taxonomy.' );
				return;
			}
			if ( ! terms || ! terms.length ) {
				jQuery( '#mlsimport-delete-notification' ).text( 'Please select at least one term.' );
				return;
			}

			// Require explicit confirmation before an irreversible bulk delete.
			if ( ! confirm( 'Are you sure you want to delete all properties matching the selected terms? This cannot be undone.' ) ) {
				return;
			}

			// Reset stop flag and switch the UI into the "deleting" state.
			window.mlsimportDeleteStopped = false;
			jQuery( '#mlsimport-delete-prop' ).hide();
			jQuery( '#mlsimport-delete-stop' ).show();
			jQuery( '#mlsimport-delete-progress' ).show();
			jQuery( '#mlsimport-delete-progress-bar' ).css( 'width', '0%' );
			jQuery( '#mlsimport-delete-progress-text' ).text( 'Starting...' );
			jQuery( '#mlsimport-delete-notification' ).text( 'Deleting...' );

			mlsimportDeleteBatch( taxonomy, terms, nonce, 0 );
		} );

		// Stop button: set the flag so the batch loop ends after the current request.
		jQuery( '#mlsimport-delete-stop' ).on( 'click', function () {
			window.mlsimportDeleteStopped = true;
			jQuery( '#mlsimport-delete-notification' ).text( 'Stopping after current batch...' );
		} );

		/**
		* Stop import
		*/

		// Stop the global (all-tasks) import: tell the server to stop moving files,
		// re-show the start button and stop the log poll.
		jQuery( '#mlsimport_stop' ).on(
			'click',
			function () {
				var ajaxurl = mlsimport_vars.ajax_url;

				console.log( 'stop files' );
				jQuery.ajax(
					{
						type: 'POST',
						url: ajaxurl,
						data: {
							'action'            :   'mlsimport_stop_moving_files',
						},
						success: function (data) {
							console.log( data );
							jQuery( '#aws-move-start' ).show();
							clearInterval( log_refresh_interval );

						},
						error: function (errorThrown) {
							console.log( errorThrown );
						}
					}
				);// end ajax
			}
		);

		/**
		* Start Import
		*/

		// Start the global import: show the progress UI, trigger the server move,
		// then (re)start polling the global log.
		jQuery( '#mlsimport-start' ).on(
			'click',
			function () {
				console.log( 'mlsimport-start' );
				var ajaxurl = mlsimport_vars.ajax_url;
				aws_show_progress();

				jQuery.ajax(
					{
						type: 'POST',
						url: ajaxurl,
						data: {
							'action'            :   'mlsimport_move_files'
						},
						success: function (data) {
							console.log( data );
							console.log( 'starting loggers' );
							clearInterval( log_refresh_interval );
							log_refresh_interval = setInterval( mlsimport_log_interval, timer );

						},
						error: function (errorThrown) {
							console.log( errorThrown );
						}
					}
				);// end ajax

			}
		);

		/**
		 * Log import
		 *
		 * Polled on an interval during a global import: fetches the latest log text
		 * and remaining-file count, updates the log container and progress bar, and
		 * stops the poll when the server reports the run is done.
		 */

		function mlsimport_log_interval()
		{

			// Total files to process (from the progress element) is the bar's denominator.
			var progress_total = jQuery( '#mlsimport_monster_myProgress' ).attr( 'data-total' );
			progress_total     = parseInt( progress_total );
			var remain_images  = progress_total;
			var done_images    = 0;
			var bar_width      = 0;
			jQuery.ajax(
				{
					type: 'POST',
					url: ajaxurl,
					dataType: 'json',
					data: {
						'action'            :   'mlsimport_move_files_to_aws_logger',
					},
					success: function (data) {
						
						// Done and no more logs: mark COMPLETED and stop polling.
						if (data.is_done === 'done' && data.logs === '' ) {
							jQuery( '#log_container' ).prepend( 'COMPLETED' );
							jQuery( '#log_container' ).append( 'COMPLETED' );
							clearInterval( log_refresh_interval );
						} else if (data.logs !== '') {
							// Still running: replace the log text and remaining-file count.
							jQuery( '#log_container' ).empty().prepend( data.logs );
							jQuery( '#aws_more_files' ).empty().text( data.current_files_no );

							// Derive processed count and update the progress bar width.
							remain_images = parseInt( data.current_files_no );
							done_images   = progress_total - remain_images;

							bar_width = done_images * 100 / progress_total;
							bar_width = parseFloat( bar_width );

							jQuery( '#mlsimport_myBar' ).css( 'width',bar_width + '%' );
						}
					},
					error: function (errorThrown) {
						console.log( errorThrown );
					}
				}
			);// end ajax
		}

		/**
		 * Log import per item
		 *
		 * Polled on an interval during a single import task: reads progress/total,
		 * updates that task's status text and progress bar, and stops the poll when
		 * the server reports the task is done.
		 */

		function mlsimport_log_interval_per_item()
		{
				console.log( 'mlsimport_log_interval_per_item' );
				// Task id and item-actions nonce for the logger request.
				var item_id = jQuery( '#mlsimport-start_item' ).attr( 'data-post_id' );
				var nonce = jQuery('#mlsimport_item_actions').val();
				jQuery.ajax(
					{
						type: 'POST',
						url: ajaxurl,
						dataType: 'json',
						data: {
							'action'            :   'mlsimport_logger_per_item',
							'post_id'           :   item_id,
							'security'			:	nonce
						},
                                                success: function (data) {
                                                        console.log( data );
                                                        // Imported-so-far vs total, used to size the progress bar.
                                                        var progress = parseInt( data.mlsimport_progress_properties );
                                                        var total    = parseInt( data.mlsimport_task_to_import );
                                                        // Only update the bar when both numbers are valid.
                                                        if ( ! isNaN( progress ) && ! isNaN( total ) && total > 0 ) {
                                                                var width = progress * 100 / total;
                                                                jQuery( '#mlsimport_item_progress .mlsimport-progress-bar-inner' ).css( 'width', width + '%' );
                                                        }
                                                       // Finished (done, or no more logs): stop the poll and show the final status.
                                                       if (data.is_done === 'done' || data.logs === '' ) {
                                                                console.log( 'kill interval' );

                                                                clearInterval( log_refresh_interval_per_item );
                                                                var message = (data.status === 'completed' && progress > 0) ? "Import completed!" : "Ready to import!";
                                                                jQuery( '#mlsimport_item_status' ).empty().append( message );
                                                                jQuery( '#mlsimport_item_progress .mlsimport-progress-bar-inner' ).css( 'width', '100%' );

                                                        }else if(data.is_done==='wip'){
                                                                // Work-in-progress: show current property number and memory usage.
                                                                console.log('we do wip');
                                                                jQuery( '#mlsimport_item_status' ).empty().append( 'Importing property: '+data.mlsimport_progress_properties+' of '+data.mlsimport_task_to_import+'. Memory used: '+data.memory+' MB.' );

							} else if (data.logs !== '') {
								// Otherwise surface whatever raw log text the server returned.
								console.log('we do logs');
								jQuery( '#mlsimport_item_status' ).empty().append( data.logs );

								
								jQuery('.mlsimport-import-summary').append(
									'<p><strong><?php _e("Status:", "mlsimport"); ?></strong> ' +
									'<span class="mlsimport-status-success">'+data.logs+'/span></p>'
								);

							}
						},
						error: function (errorThrown) {
							console.log( errorThrown );
						}
					}
				);// end ajax
		}

		/**
		 * SHow progress bar -
		 *
		 * Prepares the global import progress UI: clears the log, resolves how many
		 * files remain to move, reveals the progress wrapper and hides the start button.
		 */

		function aws_show_progress()
		{

			// Start with an empty log container.
			jQuery( '#log_container' ).empty();

			// Read the count of files to move from the primary counter element.
			var files_to_move = jQuery( '#aws_move' ).text();
			files_to_move     = parseInt( files_to_move );

			console.log( 'files_to_move ' + files_to_move );

			// Fall back to the secondary counter if the primary one is not a number.
			if ( isNaN( parseFloat( files_to_move ) )  ) {
				files_to_move = jQuery( '#aws_more_files' ).text();
			}
			console.log( 'files_to_move2 ' + files_to_move );
			files_to_move = parseInt( files_to_move );

			jQuery( '#mlsimport_myProgress_wrapper' ).show();
			jQuery( '.aws_to_move' ).empty().html( '<strong>We start importing. Please wait.</strong>' );
			jQuery( '#aws-move-start' ).hide();
		}

		/**
		* Check / unchechek fields
		*/

		// Select-all / select-none buttons for the import field checkboxes. The
		// data-import value on the clicked button decides which set to (un)check.
		jQuery( '.mls_import_selec_all_class' ).on(
			'click',
			function () {
				var trigger_type = jQuery( this ).attr( 'data-import' );
				console.log( 'trigger type ' + trigger_type );

				// Branch on the button's mode: check/uncheck the import or admin field group.
				if (trigger_type === 'import_select') {
	
					jQuery( '.mlsimport_select_import_all' ).prop( 'checked', true );
				} else if (trigger_type === 'import_select_none') {
	
					jQuery( '.mlsimport_select_import_all' ).prop( 'checked', false );
				} else if (trigger_type === 'import_admin') {
					jQuery( '.mlsimport_select_import_admin_all' ).prop( 'checked', true );
				} else if (trigger_type === 'import_admin_none') {
					jQuery( '.mlsimport_select_import_admin_all' ).prop( 'checked', false );
				}
			}
		);

	}
);



/**
 * Wire the "show extra options" toggles (e.g. cities/counties inputs).
 *
 * Each hidden-field button toggles the visibility of the input wrapper inside
 * its parent fieldset.
 */
function mslimport_show_extra_options()
{
	// On click, find the enclosing fieldset and toggle its input wrapper.
	jQuery( '.mlsimport_hidden_field_button' ).on(
		'click',
		function () {
			var parent = jQuery( this ).closest( '.mlsimport-fieldset' );
			console.log( parent )
			parent.find( '.mlsimport-input-wrapper' ).toggle();
		}
	);
}




/**
 * Trigger a server-side fetch of the selected MLS's metadata from the SaaS API,
 * then reload the page so the newly available fields show up.
 */
function mlsimport_saas_get_metadata()
{

	console.log( 'mlsimport_saas_get_metadata' );
	// Nonce and admin-ajax endpoint for the metadata request.
	var nonce = jQuery('#mlsimport_saas_get_metadata').val();
	var ajaxurl = mlsimport_vars.ajax_url;
	jQuery.ajax(
		{
			type: 'POST',
			url: ajaxurl,
			data: {
				'action'            :   'mlsimport_saas_get_metadata_function',
				'security'			:	nonce
			},
			success: function (data) {
				console.log( data );
				jQuery( '.mlsimport_populate_warning' ).remove();
				location.reload( true );
			},
			error: function (errorThrown) {
				console.log( errorThrown );
			}
		}
	);// end ajax

}


/**
 * Provider detectors — each maps an MLS numeric id to a provider family so the
 * UI can decide which credential fieldset to show. Ranges are mutually exclusive.
 */

/**
 * @param  {number|string} selected_value MLS id.
 * @return {boolean} True for BrightMLS (the single id 8001).
 */
function mlsimport_is_brightmls( selected_value )
{
        return Number( selected_value ) === 8001;
}

/**
 * @param  {number|string} selected_value MLS id.
 * @return {boolean} True for ConnectMLS: 8000–8999 excluding BrightMLS (8001).
 */
function mlsimport_is_connectmls( selected_value )
{
        selected_value = parseInt( selected_value );

        // Non-numeric input is never ConnectMLS.
        if ( isNaN( selected_value ) ) {
                return false;
        }

        // In the 8000-range, but not the BrightMLS id.
        return selected_value >= 8000 && selected_value < 9000 && Number( selected_value ) !== 8001;
}

/**
 * @param  {number|string} selected_value MLS id.
 * @return {boolean} True for Realtor.ca ids (7000–7999).
 */
function mlsimport_is_realtorca( selected_value )
{
        return selected_value >= 7000 && selected_value < 8000;
}

/**
 * @param  {number|string} selected_value MLS id.
 * @return {boolean} True for Paragon ids (6000–6999).
 */
function mlsimport_is_paragon( selected_value )
{
        return selected_value >= 6000 && selected_value < 7000;
}

/**
 * @param  {number|string} selected_value MLS id.
 * @return {boolean} True for Rapattoni ids (5000–5999).
 */
function mlsimport_is_rapattoni( selected_value )
{
        return selected_value >= 5000 && selected_value < 6000;
}

/**
 * @param  {number|string} selected_value MLS id.
 * @return {boolean} True for Trestle ids (901–2999).
 */
function mlsimport_is_trestle( selected_value )
{
        return selected_value > 900 && selected_value < 3000;
}

/**
 * Show/hide the credential fieldsets for the currently selected MLS on page load
 * (and whenever called after a selection). Mirrors the change-handler branches:
 * detects the provider family by id range and reveals only its fields.
 */
function mlsimport_token_on_load()
{
        // Read and normalise the currently selected MLS id.
        var selected_value = jQuery( '#mlsimport_mls_name' ).val();
        selected_value     = parseInt( selected_value );

        console.log("on load "+selected_value);
        // Match the provider family and show its fieldset(s), hiding all the rest.
        if ( mlsimport_is_brightmls( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).show();
        } else if ( mlsimport_is_connectmls( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).show();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();
        } else if ( mlsimport_is_realtorca( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).show();
                jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();
        } else if ( mlsimport_is_paragon( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).show();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();
        } else if ( mlsimport_is_rapattoni( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).show();
                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();
        } else if ( mlsimport_is_trestle( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).show();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).show();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();
        } else {

                jQuery( '.fieldset_mlsimport_mls_token' ).show();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_brightmls_client_id, .fieldset_mlsimport_brightmls_client_secret' ).hide();

        }
}



/**
 * Front-end MLS name autocomplete.
 *
 * Attaches a jQuery UI autocomplete to the visible MLS-name input. As the user
 * types, matches come from the supplied list; picking one stores the human label
 * in the front field and the numeric id in the hidden field, then refreshes the
 * credential fieldsets via mlsimport_token_on_load().
 *
 * @param {Array} autofill Source list of { label, value } MLS entries.
 */
function mlsimport_autocomplte_mls_selection(autofill){

	console.log('mlsimport_autocomplte_mls_selection');
	console.log(typeof jQuery.ui.autocomplete); // should be "function"
	console.log(autofill);


	jQuery( "#mlsimport_mls_name_front" ).autocomplete({
		// Data source and minimum characters before suggestions appear.
		source: autofill,
		minLength: 3,
		// Tag the dropdown widget so it can be styled by the plugin CSS.
		open: function(event, ui) {
			jQuery(this).autocomplete("widget").addClass("mlsimport-autocomplete-menu");
		},
		// On committed change, store label + id and refresh credential fields.
		change( event, ui ){
			console.log(ui);
			jQuery("#mlsimport_mls_name_front").val(ui.item.label);
			jQuery("#mlsimport_mls_name").val(ui.item.value);
			mlsimport_token_on_load();
		},
		focus: function(event, ui) {
			jQuery("#mlsimport_mls_name_front").val(ui.item.label);
			jQuery("#mlsimport_mls_name").val(ui.item.value);  mlsimport_token_on_load();
			return false;
		},
		select: function(event, ui) {
			jQuery("#mlsimport_mls_name_front").val(ui.item.label);
			jQuery("#mlsimport_mls_name").val(ui.item.value);  mlsimport_token_on_load();
			return false;
		},
		// When no matches come back, inject a single "No results found" entry.
		response: function(event, ui) {
			if (!ui.content.length) {
				var noResult = { value:"",label:"No results found" };
				ui.content.push(noResult);
				//$("#message").text("No results found");
			} else {
//                        $("#message").empty();
			}
		}
	});
}
