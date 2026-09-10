/**
 * MLSImport Onboarding Wizard JavaScript
 *
 * Handles all the frontend functionality for the onboarding wizard including
 * navigation, form validation, and AJAX requests.
 *
 * Depends on the localized `mlsimportOnboarding` object (ajaxurl, nonce,
 * current_step, steps, strings) printed by the PHP that enqueues this file.
 */

(function($) {
    'use strict';

    // Store wizard state
    var MLSImportWizard = {
        currentStep: '',   // Slug of the step currently being shown
        steps: {},         // Map of step slug -> step config (order defines navigation)
        /**
         * Bootstrap the wizard: pull state from localized data and wire it up.
         */
        init: function() {
            // Set initial state from localized data
            this.currentStep = mlsimportOnboarding.current_step;
            this.steps = mlsimportOnboarding.steps;
            
            // Initialize event listeners
            this.initEvents();
            
        },

        /**
         * Attach the wizard's global event handlers.
         */
        initEvents: function() {
            // Form submission
            $('#mlsimport-wizard-form').on('submit', this.handleFormSubmit);
            
            // Save data when navigating away
            $(window).on('beforeunload', this.saveCurrentData);
            
            // Step navigation
            $('.mlsimport-wizard-step').on('click', this.handleStepClick);
        },
        /**
         * Handle a click on a step indicator: save, gate skipping, then navigate.
         *
         * @param {Event} e - The click event.
         */
        handleStepClick: function(e) {
            e.preventDefault();
            
            // Save current data
            MLSImportWizard.saveCurrentData();
            
            // Get clicked step index
            var $step = $(this);
            var stepIndex = $step.index();
            var stepKeys = Object.keys(MLSImportWizard.steps);
            var targetStep = stepKeys[stepIndex];
            
            // Don't allow skipping ahead - only go to completed steps or next step
            var currentIndex = stepKeys.indexOf(MLSImportWizard.currentStep);
            if (stepIndex > currentIndex + 1) {
                // Block the jump and prompt the user to finish the current step
                alert(mlsimportOnboarding.strings.complete_current_step || 'Please complete the current step first.');
                return;
            }
            
            // Navigate to the step
            window.location.href = 'admin.php?page=mlsimport-onboarding&step=' + targetStep;
        },

        
        /**
         * On form submit, persist the data then let the native submit proceed.
         *
         * @param {Event} e - The submit event.
         * @return {boolean} Always true (do not cancel the submit).
         */
        handleFormSubmit: function(e) {
            // Save current form data
            MLSImportWizard.saveCurrentData();
            
            // Let the form submit normally - PHP will handle the processing
            return true;
        },

        /**
         * Serialize the wizard form and save it via a synchronous AJAX call
         * (synchronous so it completes before the page unloads).
         */
        saveCurrentData: function() {
            // Collect form data
            var formData = $('#mlsimport-wizard-form').serializeArray();
            var data = {};
            
            // Convert to object
            $.each(formData, function(i, field) {
                // Multi-value (`name[]`) fields collapse into an array under the base name
                if (field.name.indexOf('[]') !== -1) {
                    // Handle array values
                    var name = field.name.replace('[]', '');
                    if (!data[name]) {
                        data[name] = [];
                    }
                    data[name].push(field.value);
                } else {
                    // Scalar field: store directly
                    data[field.name] = field.value;
                }
            });
            
            // Save via AJAX
            $.ajax({
                url: mlsimportOnboarding.ajaxurl,
                method: 'POST',
                data: {
                    action: 'mlsimport_save_step_data',
                    step: MLSImportWizard.currentStep,
                    data: data,
                    nonce: mlsimportOnboarding.nonce
                },
                async: false // Make sure data is saved before page unloads
            });
        },
        
        // Utility function to show error message
        /**
         * Display an inline error notice above the form and scroll to it.
         *
         * @param {string} message - Error text to display.
         */
        showError: function(message) {
            // Create the notice element once if it isn't already present
            if (!$('.mlsimport-error-notice').length) {
                $('<div class="mlsimport-error-notice"></div>').insertBefore('#mlsimport-wizard-form');
            }

            // Populate and reveal the notice
            $('.mlsimport-error-notice').html('<p>' + message + '</p>').show();
            
            // Scroll to error
            $('html, body').animate({
                scrollTop: $('.mlsimport-error-notice').offset().top - 50
            }, 200);
        },
        
        // Utility function to hide error message
        /**
         * Hide the inline error notice.
         */
        hideError: function() {
            $('.mlsimport-error-notice').hide();
        }
    };
    
    // Initialize the wizard on document ready
    $(document).ready(function() {
        MLSImportWizard.init();
    });
    
    // Add utility functions for step templates that run inline JS
    window.MLSImportWizard = MLSImportWizard;
    
})(jQuery);











// Second ready block: account / MLS-credential save handlers for the wizard.
jQuery(document).ready(function (jQuery) {




	/**
	 * Show transient status text on a button, optionally reverting after 2s.
	 *
	 * @param {jQuery}  button     - The button element.
	 * @param {string}  statusText - Text to display.
	 * @param {boolean} reset      - When true, restore the original label after 2s.
	 */
	function showButtonStatus(button, statusText, reset = true) {
		// Remember the original label (once) so we can restore it
		const originalText = button.data('original-text') || button.text();
		if (!button.data('original-text')) {
			button.data('original-text', originalText);
		}
		// Apply the status text; optionally schedule a revert
		button.text(statusText);
		if (reset) {
			setTimeout(() => button.text(originalText), 2000);
		}
	}

    // Save the MLSImport account username/password via AJAX
    jQuery('.mlsimport-save-account').on('click', function (e) {
        e.preventDefault();
        const button = jQuery(this);
        // A new attempt replaces any field error from the preceding attempt.
        window.MLSImportWizard.hideError();
        // Show a persistent "saving" label while the request is in flight
        showButtonStatus(button, mlsimportOnboarding.strings.saving, false);

        // Read the entered credentials
        const username = jQuery('#mlsimport_admin_options-mlsimport_username').val();
        const password = jQuery('#mlsimport_admin_options-mlsimport_password').val();

        // POST the credentials to the account-save endpoint
        jQuery.post(mlsimportOnboarding.ajaxurl, {
            action: 'mlsimport_save_account',
            security: mlsimportOnboarding.nonce,
            mlsimport_username: username,
            mlsimport_password: password
        }, function (response) {
            if (response.success) {
                // Success: flash the success label
                showButtonStatus(button, mlsimportOnboarding.strings.success);
    
                // Replace the status feedback message
                jQuery('.mlsimport_warning').remove();
                jQuery('#mlsimport_admin_options-mlsimport_username')
                .closest('fieldset')
                .before(response.data.html);
            } else {
                // Validation failures carry the missing field labels. Surface
                // that message beside the form instead of reducing it to the
                // button's generic "Error" state (#306).
                showButtonStatus(button, mlsimportOnboarding.strings.error);
                const message = response.data && response.data.message
                    ? response.data.message
                    : mlsimportOnboarding.strings.error;
                window.MLSImportWizard.showError(message);
            }
        }).fail(function () {
            // A transport failure has no server message, but it still needs
            // visible feedback beyond the transient button label.
            showButtonStatus(button, mlsimportOnboarding.strings.error);
            window.MLSImportWizard.showError(mlsimportOnboarding.strings.error);
        });
    });

    // Save the MLS-specific settings (everything except the account credentials)
    jQuery('.mlsimport-save-mls-data').on('click', function (e) {
        e.preventDefault();

        // Disable the button and show the "saving" label
        const button = jQuery(this);
        const originalText = button.text();
        button.text(mlsimportOnboarding.strings.saving).prop('disabled', true);

        // Base payload
        const data = {
            action: 'mlsimport_save_mls_data',
            security: mlsimportOnboarding.nonce
        };

        // Collect every mlsimport_admin_options[...] field except the credentials
        jQuery('[name^="mlsimport_admin_options"]').each(function () {
            const name = jQuery(this).attr('name').replace('mlsimport_admin_options[', '').replace(']', '');
            if (name !== 'mlsimport_username' && name !== 'mlsimport_password') {
                data[name] = jQuery(this).val();
            }
        });

        // POST the collected MLS settings
        jQuery.post(mlsimportOnboarding.ajaxurl, data, function (response) {
            // Restore the button regardless of outcome
            button.text(originalText).prop('disabled', false);
    
            if (response.success) {
                // Remove all .mlsimport_warning except the validated one (account message)
                jQuery('.mlsimport_warning').not('.mlsimport_validated').remove();
    
                // Insert new MLS connection message before MLS input
                jQuery('#mlsimport_mls_name_front')
                    .closest('fieldset')
                    .before(response.data.html);

                // MLS connection confirmed: gather the metadata + save the
                // import-field configuration in the background right away, so
                // the Field Mapping step is ready when the user reaches it.
                // Fire-and-forget on purpose: the shared reloading helper
                // would yank the wizard step from under the user on success.
                if (response.data.connected) {
                    jQuery.post(mlsimportOnboarding.ajaxurl, {
                        action: 'mlsimport_saas_get_metadata_function',
                        security: jQuery('#mlsimport_saas_get_metadata').val()
                    });
                }
            } else {
                // Server reported failure
                button.text(mlsimportOnboarding.strings.error);
            }
        }).fail(function () {
            // Transport failure: show error and re-enable
            button.text(mlsimportOnboarding.strings.error).prop('disabled', false);
        });
    });
    


    // code for the acocunt page

    // Only run this block on the account step
    if (jQuery('.mlsimport-wizard-content-account').length) {
        // Set the Continue button's initial enabled/disabled state
        updateContinueButton();
        
        // Add continue button navigation
        jQuery('.mlsimport-wizard-content-account .mlsimport-wizard-next').on('click', function(e) {
            e.preventDefault();
            // Only navigate onward when the button isn't disabled
            if (!jQuery(this).prop('disabled')) {
                // Derive the admin.php URL from ajaxurl and go to the field-mapping step
                window.location.href = ajaxurl.replace('admin-ajax.php', 'admin.php') + '?page=mlsimport-onboarding&step=field-mapping';
            }
        });
        
        // Update after AJAX calls
        jQuery(document).ajaxComplete(function() {
            // Re-evaluate the Continue button shortly after any AJAX completes
            setTimeout(updateContinueButton, 500);
        });
    }

});




/**
 * Enable the account-step Continue button only once both credentials
 * (account + MLS) have been validated.
 */
function updateContinueButton() {
    // Count how many validated confirmation messages are present
    const validatedCount = jQuery('.mlsimport_warning.mlsimport_validated').length;
    const continueButton = jQuery('.mlsimport-wizard-content-account .mlsimport-wizard-next');

    // Both checks passed (>=2): enable; otherwise disable
    if (validatedCount >= 2) {
        continueButton.prop('disabled', false).removeClass('disabled');
    } else {
        continueButton.prop('disabled', true).addClass('disabled');
    }
}
