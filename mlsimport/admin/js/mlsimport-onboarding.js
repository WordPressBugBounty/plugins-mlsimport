/**
 * MLSImport Onboarding Wizard JavaScript
 *
 * Handles all the frontend functionality for the onboarding wizard including
 * navigation, form validation, and AJAX requests.
 */

(function($) {
    'use strict';

    // Store wizard state
    var MLSImportWizard = {
        currentStep: '',
        steps: {},
        formData: {},
        init: function() {
            // Set initial state from localized data
            this.currentStep = mlsimportOnboarding.current_step;
            this.steps = mlsimportOnboarding.steps;
            
            // Initialize event listeners
            this.initEvents();
            
            // Initialize step-specific functionality
            this.initCurrentStep();
        },
        
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
                alert(mlsimportOnboarding.strings.complete_current_step || 'Please complete the current step first.');
                return;
            }
            
            // Navigate to the step
            window.location.href = 'admin.php?page=mlsimport-onboarding&step=' + targetStep;
        },

        
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
        
        handleFormSubmit: function(e) {
            // Save current form data
            MLSImportWizard.saveCurrentData();
            
            // Let the form submit normally - PHP will handle the processing
            return true;
        },
        
        handleBackClick: function(e) {
            // Save current form data before going back
            MLSImportWizard.saveCurrentData();
            
            // Let the link work normally
            return true;
        },
        
        saveCurrentData: function() {
            // Collect form data
            var formData = $('#mlsimport-wizard-form').serializeArray();
            var data = {};
            
            // Convert to object
            $.each(formData, function(i, field) {
                if (field.name.indexOf('[]') !== -1) {
                    // Handle array values
                    var name = field.name.replace('[]', '');
                    if (!data[name]) {
                        data[name] = [];
                    }
                    data[name].push(field.value);
                } else {
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
        showStepSection: function(selector) {
            $('.mlsimport-step-section').hide();
            $(selector).show();
        },
        
        // Utility function to validate current step
        validateStep: function() {
            var isValid = true;
            var requiredFields = $('#mlsimport-wizard-form').find('[required]');
            
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
        showError: function(message) {
            if (!$('.mlsimport-error-notice').length) {
                $('<div class="mlsimport-error-notice"></div>').insertBefore('#mlsimport-wizard-form');
            }
            
            $('.mlsimport-error-notice').html('<p>' + message + '</p>').show();
            
            // Scroll to error
            $('html, body').animate({
                scrollTop: $('.mlsimport-error-notice').offset().top - 50
            }, 200);
        },
        
        // Utility function to hide error message
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
 */
function getUrlParameter(name) {
    name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
    var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
    var results = regex.exec(location.search);
    return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
}

/**
 * Helper function to format large numbers with commas
 */
function formatNumber(num) {
    return num.toString().replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1,');
}

/**
 * Helper function to show a loading state on a button
 */
function showButtonLoading(button, loadingText) {
    button.data('original-text', button.html());
    button.html(loadingText || mlsimportOnboarding.strings.loading);
    button.prop('disabled', true);
}

/**
 * Helper function to restore a button from loading state
 */
function hideButtonLoading(button) {
    button.html(button.data('original-text'));
    button.prop('disabled', false);
}











jQuery(document).ready(function (jQuery) {




	function showButtonStatus(button, statusText, reset = true) {
		const originalText = button.data('original-text') || button.text();
		if (!button.data('original-text')) {
			button.data('original-text', originalText);
		}
		button.text(statusText);
		if (reset) {
			setTimeout(() => button.text(originalText), 2000);
		}
	}

    jQuery('.mlsimport-save-account').on('click', function (e) {
        e.preventDefault();
        const button = jQuery(this);
        showButtonStatus(button, mlsimportOnboarding.strings.saving, false);
    
        const username = jQuery('#mlsimport_admin_options-mlsimport_username').val();
        const password = jQuery('#mlsimport_admin_options-mlsimport_password').val();
    
        jQuery.post(mlsimportOnboarding.ajaxurl, {
            action: 'mlsimport_save_account',
            security: mlsimportOnboarding.nonce,
            mlsimport_username: username,
            mlsimport_password: password
        }, function (response) {
            if (response.success) {
                showButtonStatus(button, mlsimportOnboarding.strings.success);
    
                // Replace the status feedback message
                jQuery('.mlsimport_warning').remove();
                jQuery('#mlsimport_admin_options-mlsimport_username')
                .closest('fieldset')
                .before(response.data.html);
            } else {
                showButtonStatus(button, mlsimportOnboarding.strings.error);
            }
        }).fail(function () {
            showButtonStatus(button, mlsimportOnboarding.strings.error);
        });
    });

    jQuery('.mlsimport-save-mls-data').on('click', function (e) {
        e.preventDefault();
    
        const button = jQuery(this);
        const originalText = button.text();
        button.text(mlsimportOnboarding.strings.saving).prop('disabled', true);
    
        const data = {
            action: 'mlsimport_save_mls_data',
            security: mlsimportOnboarding.nonce
        };
    
        jQuery('[name^="mlsimport_admin_options"]').each(function () {
            const name = jQuery(this).attr('name').replace('mlsimport_admin_options[', '').replace(']', '');
            if (name !== 'mlsimport_username' && name !== 'mlsimport_password') {
                data[name] = jQuery(this).val();
            }
        });
    
        jQuery.post(mlsimportOnboarding.ajaxurl, data, function (response) {
            button.text(originalText).prop('disabled', false);
    
            if (response.success) {
                // Remove all .mlsimport_warning except the validated one (account message)
                jQuery('.mlsimport_warning').not('.mlsimport_validated').remove();
    
                // Insert new MLS connection message before MLS input
                jQuery('#mlsimport_mls_name_front')
                    .closest('fieldset')
                    .before(response.data.html);
            } else {
                button.text(mlsimportOnboarding.strings.error);
            }
        }).fail(function () {
            button.text(mlsimportOnboarding.strings.error).prop('disabled', false);
        });
    });
    


    // code for the acocunt page

    if (jQuery('.mlsimport-wizard-content-account').length) {
        updateContinueButton();
        
        // Add continue button navigation
        jQuery('.mlsimport-wizard-content-account .mlsimport-wizard-next').on('click', function(e) {
            e.preventDefault();
            if (!jQuery(this).prop('disabled')) {
                window.location.href = ajaxurl.replace('admin-ajax.php', 'admin.php') + '?page=mlsimport-onboarding&step=field-mapping';
            }
        });
        
        // Update after AJAX calls
        jQuery(document).ajaxComplete(function() {
            setTimeout(updateContinueButton, 500);
        });
    }

});




function updateContinueButton() {
    const validatedCount = jQuery('.mlsimport_warning.mlsimport_validated').length;
    console.log('nwe thing');
    const continueButton = jQuery('.mlsimport-wizard-content-account .mlsimport-wizard-next');
 
    if (validatedCount >= 2) {
        continueButton.prop('disabled', false).removeClass('disabled');
    } else {
        continueButton.prop('disabled', true).addClass('disabled');
    }
}
