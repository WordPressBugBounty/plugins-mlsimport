jQuery( document ).ready(
	function ($) {
		'use strict';
	
		var log_refresh_interval;
		var log_refresh_interval_per_item;
		var timer          = 2000;
		var timer_per_item = 4000;

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

		jQuery( '#mlsimport_mls_name' ).on(
			'change',
			function (event) {

				var selected_value = jQuery( '#mlsimport_mls_name' ).val();
				selected_value     = parseInt( selected_value );

		
			
                                if ( mlsimport_is_connectmls( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_id, .fieldset_mlsimport_tresle_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).show();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                                } else if ( mlsimport_is_realtorca( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_id, .fieldset_mlsimport_tresle_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).show();
                                } else if ( mlsimport_is_paragon( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_id, .fieldset_mlsimport_tresle_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).show();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                                } else if ( mlsimport_is_rapattoni( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_id,.fieldset_mlsimport_tresle_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).show();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
                                } else if ( mlsimport_is_trestle( selected_value ) ) {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();

                                        jQuery( '.fieldset_mlsimport_tresle_client_id' ).show();
                                        jQuery( '.fieldset_mlsimport_tresle_client_secret' ).show();
                                } else {

                                        jQuery( '.fieldset_mlsimport_mls_token' ).show();
                                        jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                                        jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                                        jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                                        jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();

                                        jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                                        jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                                }

			}
		);

		/**
		* Stop Import per item
		*/

		jQuery( '#mlsimport_stop_item' ).on(
			'click',
			function () {
				console.log( 'mlsimport-stop' );
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

		jQuery( '#mlsimport-start_item,#mlsimport-run-test' ).on(
			'click',
			function (event) {
				console.log( 'mlsimport-start' );
				var ajaxurl     = mlsimport_vars.ajax_url;
				var post_id     = jQuery( this ).attr( 'data-post_id' );
				var post_number = jQuery( this ).attr( 'data-post-number' );
				var how_many    = jQuery( '#mlsimport_item_how_many' ).val();
				var is_onboard  = 0;
				var nonce 		= jQuery('#mlsimport_item_actions').val();
				jQuery( '#mlsimport_item_status' ).empty();
				jQuery( '#mlsimport_item_status' ).append( "Starting the import. Please stand by!" );


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

		jQuery( '#mlsimport-delete-prop' ).on(
			'click',
			function () {
				var ajaxurl = mlsimport_vars.ajax_url;

				var mlsimport_delete_category      = jQuery( '#mlsimport_delete_category' ).val();
				var mlsimport_delete_category_term = jQuery( '#mlsimport_delete_category_term' ).val();
				var mlsimport_delete_timeout       = jQuery( '#mlsimport_delete_timeout' ).val();
				var nonce 						   = jQuery('#mlsimport_tool_actions').val();

				if (mlsimport_delete_category === '') {
					jQuery( '#mlsimport-delete-notification' ).text( 'Please add the category ' );
					return;
				}
				if (mlsimport_delete_category_term === '' ) {
					jQuery( '#mlsimport-delete-notification' ).text( 'Please add the category term ' );
					return;
				}

				jQuery( '#mlsimport-delete-notification' ).text( 'Deleting...If you have many properties this may take a while' );
				jQuery.ajax(
					{
						type: 'POST',
						url: ajaxurl,
						dataType:'json',
						data: {
							'action'                            :   'mlsimport_delete_properties',
							'mlsimport_delete_category'         :    mlsimport_delete_category,
							'mlsimport_delete_category_term'    :   mlsimport_delete_category_term,
							'mlsimport_delete_timeout'          :   mlsimport_delete_timeout,
							'security'							:	nonce
						},
						success: function (data) {
							console.log( data );
							jQuery( '#mlsimport-delete-notification' ).text( data.message );

						},
						error: function (errorThrown) {
							console.log( errorThrown );
						}
					}
				);// end ajax
			}
		);

		/**
		* Stop import
		*/

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
		 */

		function mlsimport_log_interval()
		{

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
						
						if (data.is_done === 'done' && data.logs === '' ) {
							jQuery( '#log_container' ).prepend( 'COMPLETED' );
							jQuery( '#log_container' ).append( 'COMPLETED' );
							clearInterval( log_refresh_interval );
						} else if (data.logs !== '') {
							jQuery( '#log_container' ).empty().prepend( data.logs );
							jQuery( '#aws_more_files' ).empty().text( data.current_files_no );

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
		 */

		function mlsimport_log_interval_per_item()
		{
				console.log( 'mlsimport_log_interval_per_item' );
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
                                                        var progress = parseInt( data.mlsimport_progress_properties );
                                                        var total    = parseInt( data.mlsimport_task_to_import );
                                                        if ( ! isNaN( progress ) && ! isNaN( total ) && total > 0 ) {
                                                                var width = progress * 100 / total;
                                                                jQuery( '#mlsimport_item_progress .mlsimport-progress-bar-inner' ).css( 'width', width + '%' );
                                                        }
                                                       if (data.is_done === 'done' || data.logs === '' ) {
                                                                console.log( 'kill interval' );

                                                                clearInterval( log_refresh_interval_per_item );
                                                                var message = (data.status === 'completed' && progress > 0) ? "Import completed!" : "Ready to import!";
                                                                jQuery( '#mlsimport_item_status' ).empty().append( message );
                                                                jQuery( '#mlsimport_item_progress .mlsimport-progress-bar-inner' ).css( 'width', '100%' );

                                                        }else if(data.is_done==='wip'){
                                                                console.log('we do wip');
                                                                jQuery( '#mlsimport_item_status' ).empty().append( 'Importing property: '+data.mlsimport_progress_properties+' of '+data.mlsimport_task_to_import+'. Memory used: '+data.memory+' MB.' );

							} else if (data.logs !== '') {
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
		 */

		function aws_show_progress()
		{

			jQuery( '#log_container' ).empty();

			var files_to_move = jQuery( '#aws_move' ).text();
			files_to_move     = parseInt( files_to_move );

			console.log( 'files_to_move ' + files_to_move );

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

		jQuery( '.mls_import_selec_all_class' ).on(
			'click',
			function () {
				var trigger_type = jQuery( this ).attr( 'data-import' );
				console.log( 'trigger type ' + trigger_type );

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



function mslimport_show_extra_options()
{
	jQuery( '.mlsimport_hidden_field_button' ).on(
		'click',
		function () {
			var parent = jQuery( this ).closest( '.mlsimport-fieldset' );
			console.log( parent )
			parent.find( '.mlsimport-input-wrapper' ).toggle();
		}
	);
}




function mlsimport_saas_get_metadata()
{

	console.log( 'mlsimport_saas_get_metadata' );
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


function mlsimport_is_connectmls( selected_value )
{
        selected_value = parseInt( selected_value );

        if ( isNaN( selected_value ) ) {
                return false;
        }

        return selected_value >= 8000 && selected_value < 9000;
}

function mlsimport_is_realtorca( selected_value )
{
        return selected_value >= 7000 && selected_value < 8000;
}

function mlsimport_is_paragon( selected_value )
{
        return selected_value >= 6000 && selected_value < 7000;
}

function mlsimport_is_rapattoni( selected_value )
{
        return selected_value >= 5000 && selected_value < 6000;
}

function mlsimport_is_trestle( selected_value )
{
        return selected_value > 900 && selected_value < 3000;
}

function mlsimport_token_on_load()
{
        var selected_value = jQuery( '#mlsimport_mls_name' ).val();
        selected_value     = parseInt( selected_value );

        console.log("on load "+selected_value);
        if ( mlsimport_is_connectmls( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).show();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
        } else if ( mlsimport_is_realtorca( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).show();
        } else if ( mlsimport_is_paragon( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).show();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
        } else if ( mlsimport_is_rapattoni( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).show();
                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
        } else if ( mlsimport_is_trestle( selected_value ) ) {

                jQuery( '.fieldset_mlsimport_mls_token' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).show();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).show();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();
        } else {

                jQuery( '.fieldset_mlsimport_mls_token' ).show();
                jQuery( '.fieldset_mlsimport_tresle_client_id' ).hide();
                jQuery( '.fieldset_mlsimport_tresle_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_connectmls_username, .fieldset_mlsimport_connectmls_password' ).hide();
                jQuery( '.fieldset_mlsimport_rapattoni_client_id,.fieldset_mlsimport_rapattoni_client_secret,.fieldset_mlsimport_rapattoni_username,.fieldset_mlsimport_rapattoni_password ' ).hide();
                jQuery( '.fieldset_mlsimport_paragon_client_id, .fieldset_mlsimport_paragon_client_secret' ).hide();
                jQuery( '.fieldset_mlsimport_realtorca_client_id, .fieldset_mlsimport_realtorca_client_secret' ).hide();

        }
}



function mlsimport_autocomplte_mls_selection(autofill){

	console.log('mlsimport_autocomplte_mls_selection');
	console.log(typeof jQuery.ui.autocomplete); // should be "function"
	console.log(autofill);


	jQuery( "#mlsimport_mls_name_front" ).autocomplete({
		source: autofill,
		minLength: 3,
		open: function(event, ui) {
			jQuery(this).autocomplete("widget").addClass("mlsimport-autocomplete-menu");
		},
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
