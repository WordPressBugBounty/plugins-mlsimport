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
        formData: {},      // Scratch object for collected form values
        /**
         * Bootstrap the wizard: pull state from localized data and wire it up.
         */
        init: function() {
            // Set initial state from localized data
            this.currentStep = mlsimportOnboarding.current_step;
            this.steps = mlsimportOnboarding.steps;
            
            // Initialize event listeners
            this.initEvents();
            
            // Initialize step-specific functionality
            this.initCurrentStep();
        },

        /**
         * Attach the wizard's global event handlers.
         */
        initEvents: function() {
            // Form submission
            $('#mlsimport-wizard-form').on('submit', this.handleFormSubmit);
            
            // Back button handling
            $('.mlsimport-wizard-back').on('click', this.handleBackClick);
            
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
         * Run any per-step setup based on the current step slug.
         * Most steps do their own setup inline in their templates.
         */
        initCurrentStep: function() {
            // Step-specific initialization
            switch(this.currentStep) {
                case 'welcome':
                    // Nothing special for welcome step
                    break;
                case 'account':
                    // Initialize autocomplete already handled in template
                    break;
                case 'field-mapping':
                    // Template selection handler already in template
                    break;
                case 'import-config':
                    // Initialize Select2 if available already in template
                    break;
                case 'test-import':
                    // Test import handlers already in template
                    break;
                case 'success':
                    // Success page doesn't need special handling
                    break;
            }
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
         * On Back click, persist the data then let the link navigate.
         *
         * @param {Event} e - The click event.
         * @return {boolean} Always true (do not cancel the navigation).
         */
        handleBackClick: function(e) {
            // Save current form data before going back
            MLSImportWizard.saveCurrentData();
            
            // Let the link work normally
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
        
        // Utility function to show step-specific sections
        /**
         * Hide all step sections and reveal only the one matched by selector.
         *
         * @param {string} selector - Selector of the section to show.
         */
        showStepSection: function(selector) {
            $('.mlsimport-step-section').hide();
            $(selector).show();
        },
        
        // Utility function to validate current step
        /**
         * Validate that every [required] field in the form has a value.
         *
         * @return {boolean} True if all required fields are filled.
         */
        validateStep: function() {
            var isValid = true;
            var requiredFields = $('#mlsimport-wizard-form').find('[required]');

            // Flag each empty required field and clear the flag when filled
            requiredFields.each(function() {
                if (!$(this).val()) {
                    isValid = false;
                    $(this).addClass('mlsimport-field-error');
                } else {
                    $(this).removeClass('mlsimport-field-error');
                }
            });
            
            return isValid;
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

/**
 * Helper function to get a URL parameter by name
 *
 * @param {string} name - Query-string parameter name.
 * @return {string} Decoded value, or '' when absent.
 */
function getUrlParameter(name) {
    // Escape regex-special bracket characters in the parameter name
    name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
    // Build a matcher for `?name=` / `&name=` and run it against the query string
    var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
    var results = regex.exec(location.search);
    // No match returns empty; otherwise URL-decode (treating '+' as space)
    return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
}

/**
 * Helper function to format large numbers with commas
 *
 * @param {number|string} num - Value to format.
 * @return {string} Number with thousands separators.
 */
function formatNumber(num) {
    // Insert a comma before every group of three trailing digits
    return num.toString().replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1,');
}

/**
 * Helper function to show a loading state on a button
 *
 * @param {jQuery} button      - The button element.
 * @param {string} loadingText - Optional label; defaults to the localized "loading" string.
 */
function showButtonLoading(button, loadingText) {
    // Stash the original label so it can be restored later
    button.data('original-text', button.html());
    // Swap in the loading label and disable the button
    button.html(loadingText || mlsimportOnboarding.strings.loading);
    button.prop('disabled', true);
}

/**
 * Helper function to restore a button from loading state
 *
 * @param {jQuery} button - The button element.
 */
function hideButtonLoading(button) {
    // Restore the stashed label and re-enable the button
    button.html(button.data('original-text'));
    button.prop('disabled', false);
}











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
                // Server reported failure
                showButtonStatus(button, mlsimportOnboarding.strings.error);
            }
        }).fail(function () {
            // Transport failure
            showButtonStatus(button, mlsimportOnboarding.strings.error);
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
    console.log('nwe thing');
    const continueButton = jQuery('.mlsimport-wizard-content-account .mlsimport-wizard-next');

    // Both checks passed (>=2): enable; otherwise disable
    if (validatedCount >= 2) {
        continueButton.prop('disabled', false).removeClass('disabled');
    } else {
        continueButton.prop('disabled', true).addClass('disabled');
    }
}
