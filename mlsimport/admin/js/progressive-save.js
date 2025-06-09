/**
 * MLS Import Progressive Save System
 * 
 * Handles saving field data progressively:
 * - Initial chunked save if no data exists
 * - Field-by-field saving on change
 * - Optimized ordering save
 */

(function($) {
    'use strict';
    
    // Configuration
    const CONFIG = {
        chunkSize: 50,          // Fields per chunk for bulk operations
        saveDelay: 500,         // Milliseconds to wait before saving after change (debounce)
        retryDelay: 1000,       // Milliseconds to wait before retrying failed save
        maxRetries: 3           // Maximum number of retry attempts
    };
    
    // Track saving state
    const STATE = {
        saving: false,
        pendingSaves: {},
        saveTimers: {},
        retryCount: {},
        saveQueue: [], // Queue of save keys waiting to be processed
        initialSaveComplete: false
    };

    // Expose saving state globally so other scripts can wait
    window.mlsimportSaving = false;
    
    /**
     * Initialize the progressive save system
     */
    function initProgressiveSave() {
        console.log(' Initializing progressive save system');
        
        // Check immediately if an initial save is needed
        checkInitialSaveNeeded();
        
        // Set up field change listeners
        initFieldChangeListeners();
       
    }

    /**
     * Handle manual save button click
     */
    function performManualSave() {
        // Show saving message
        $('.save-status-text').text('Saving all changes...');
        
        // Set up array of save operations
        const saveOperations = [];
        
        // Add all pending saves
        for (const key in STATE.pendingSaves) {
            if (STATE.pendingSaves.hasOwnProperty(key)) {
                saveOperations.push(saveField(key, true));
            }
        }
        
        // Use Promise.all to wait for all saves to complete
        Promise.all(saveOperations)
            .then(function() {
                $('.save-status-text').text('All changes saved successfully!');
                setTimeout(function() {
                    $('.save-status-text').fadeOut();
                }, 3000);
            })
            .catch(function() {
                $('.save-status-text').text('Some changes could not be saved. Please check for errors.');
            });
    }
    
    /**
     * Check if an initial save is needed (option is empty)
     */
    function checkInitialSaveNeeded() {
      

        const allFields = $('.mlsimport-field-row').toArray();
        const totalChunks = Math.ceil(allFields.length / CONFIG.chunkSize);
        let progressContainer;

        $.ajax({
            url: mlsimport_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mlsimport_check_initial_save_needed',
                security: mlsimport_params.nonce
            },
            success: function(response) {
                if (response.success && response.data.initialSaveNeeded) {
                    console.log('Initial save needed, starting chunked save');

                    progressContainer = $('<div class="mlsimport-save-progress"></div>');
                    const progressText = $('<div class="mlsimport-save-progress-text">Please wait while the initial field data is saved.<br>Initializing field data: <span class="current-chunk">0</span>/' + totalChunks + ' chunks</div>');
                    const progressBar = $('<div class="mlsimport-save-progress-bar"><div class="progress-fill"></div></div>');

                    progressContainer.append(progressText).append(progressBar);
                    $('body').append(progressContainer);

                    processChunk(allFields, 0, totalChunks, progressContainer);
                } else {
                    console.log('No initial save needed');
                    STATE.initialSaveComplete = true;
                }
            },
            error: function() {
                console.error('Error checking if initial save needed');
                if (progressContainer) {
                    progressContainer.remove();
                }
                // Retry in 5 seconds
                setTimeout(checkInitialSaveNeeded, 5000);
            }
        });
    }
    
    
    /**
     * Process a chunk of fields for initial save
     */
    function processChunk(allFields, chunkIndex, totalChunks, progressContainer) {
        const startIndex = chunkIndex * CONFIG.chunkSize;
        const endIndex = Math.min(startIndex + CONFIG.chunkSize, allFields.length);
        const currentChunk = chunkIndex + 1;
        
        // Update progress display
        progressContainer.find('.current-chunk').text(currentChunk);
        const percentage = (currentChunk / totalChunks) * 100;
        progressContainer.find('.progress-fill').css('width', percentage + '%');
        
        // Prepare chunk data
        const chunkData = {};
        
        for (let i = startIndex; i < endIndex; i++) {
            const $field = $(allFields[i]);
            const fieldKey = $field.data('field-key');
            const isMandatory = $field.data('is-mandatory') === 'true';
            
            // Get field values - CORRECTED VERSION
            const isImportChecked = $field.find('.mlsimport-import-checkbox').is(':checked') || isMandatory;
            
            chunkData[fieldKey] = {
                import: isImportChecked ? 1 : 0,  // Set to 1 if checked or mandatory
                admin: $field.find('.mlsimport-admin-checkbox').is(':checked') ? 1 : 0,
                label: $field.find('.mlsimport-label-input').val(),
                postmeta: $field.find('.mlsimport-postmeta-input').val(),
                taxonomy: $field.find('.mlsimport-taxonomy-select').val()
            };
        }
        console.log('pricess mlsimport_save_field_chunk');
        // Save chunk
        $.ajax({
            url: mlsimport_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mlsimport_save_field_chunk',
                security: mlsimport_params.nonce,
                chunk_index: chunkIndex,
                total_chunks: totalChunks,
                fields: chunkData
            },
            success: function(response) {
                if (response.success) {
                    // Process next chunk or finish
                    if (currentChunk < totalChunks) {
                        setTimeout(function() {
                            processChunk(allFields, chunkIndex + 1, totalChunks, progressContainer);
                        }, 200); // Small delay between chunks
                    } else {
                        // All chunks processed
                        progressContainer.html('<div style="color: #46b450;">Initial field data saved successfully!</div>');
                        setTimeout(function() {
                            progressContainer.fadeOut(500, function() {
                                progressContainer.remove();
                            });
                        }, 2000);
                        STATE.initialSaveComplete = true;
                    }
                } else {
                    // Error saving chunk
                    progressContainer.html('<div style="color: #dc3232;">Error saving field data. <button id="retry-chunk" class="button">Retry</button></div>');
                    $('#retry-chunk').on('click', function() {
                        processChunk(allFields, chunkIndex, totalChunks, progressContainer);
                    });
                }
            },
            error: function() {
                // Error saving chunk
                progressContainer.html('<div style="color: #dc3232;">Network error saving field data. <button id="retry-chunk" class="button">Retry</button></div>');
                $('#retry-chunk').on('click', function() {
                    processChunk(allFields, chunkIndex, totalChunks, progressContainer);
                });
            }
        });
    }
    
    /**
     * Set up listeners for field changes
     */
    function initFieldChangeListeners() {
        // Import checkbox changes
        $(document).on('change', '.mlsimport-import-checkbox:not([disabled])', function() {
            const $field = $(this).closest('.mlsimport-field-row');
            const fieldKey = $field.data('field-key');
            
            // Queue save for this field
            queueFieldSave(fieldKey, 'import', $(this).is(':checked') ? 1 : 0);
        });
        
        // Admin-only checkbox changes
        $(document).on('change', '.mlsimport-admin-checkbox', function() {
            const $field = $(this).closest('.mlsimport-field-row');
            const fieldKey = $field.data('field-key');
            
            // Queue save for this field
            queueFieldSave(fieldKey, 'admin', $(this).is(':checked') ? 1 : 0);
        });
        
        // Label input changes
        $(document).on('input', '.mlsimport-label-input', function() {
            const $field = $(this).closest('.mlsimport-field-row');
            const fieldKey = $field.data('field-key');
            
            // Queue save for this field
            queueFieldSave(fieldKey, 'label', $(this).val());
        });
        
        // Post meta input changes
        $(document).on('input', '.mlsimport-postmeta-input', function() {
            const $field = $(this).closest('.mlsimport-field-row');
            const fieldKey = $field.data('field-key');
            
            // Queue save for this field
            queueFieldSave(fieldKey, 'postmeta', $(this).val());
        });
        
        // Taxonomy select changes
        $(document).on('change', '.mlsimport-taxonomy-select', function() {
            const $field = $(this).closest('.mlsimport-field-row');
            const fieldKey = $field.data('field-key');
            
            // Queue save for this field
            queueFieldSave(fieldKey, 'taxonomy', $(this).val());
        });
    }
    
    /**
     * Queue a field save operation with debounce
     */
    function queueFieldSave(fieldKey, optionType, value) {
        // Don't queue saves until initial save is complete
        if (!STATE.initialSaveComplete) {
            console.log(' Canceled - Initial save not complete, skipping individual field save');
          //  return;
        }
        
        // Create key for this field+option
        const saveKey = fieldKey + '_' + optionType;
        
        // Store the value to save
        STATE.pendingSaves[saveKey] = {
            fieldKey: fieldKey,
            optionType: optionType,
            value: value
        };
        
        // Clear existing timer for this field if any
        if (STATE.saveTimers[saveKey]) {
            clearTimeout(STATE.saveTimers[saveKey]);
        }
        
        // Set new timer that will enqueue the save
        STATE.saveTimers[saveKey] = setTimeout(function() {
            enqueueSave(saveKey);
        }, CONFIG.saveDelay);
    }

    /**
     * Add a save operation to the queue and start processing if idle
     */
    function enqueueSave(saveKey) {
        if (!STATE.saveQueue.includes(saveKey)) {
            STATE.saveQueue.push(saveKey);
        }
        processSaveQueue();
    }

    /**
     * Process the next save in the queue if not already saving
     */
    function processSaveQueue() {
        if (STATE.saving || window.mlsimportSaving) {
            return;
        }

        const nextKey = STATE.saveQueue.shift();
        if (nextKey) {
            STATE.saving = true;
            window.mlsimportSaving = true;
            saveField(nextKey);
        }
    }

    // Expose so other scripts (e.g., field selector) can resume the queue
    window.processSaveQueue = processSaveQueue;
    
    /**
     * Save a field that was queued for saving
     */
    function saveField(saveKey) {
        // Get the save data
        const saveData = STATE.pendingSaves[saveKey];
        if (!saveData) {
     
            console.log('No pending save data for key', saveKey);
            STATE.saving = false;
            window.mlsimportSaving = false;
            processSaveQueue();
            return;
        }
        // Mark as saving
        const $field = $('.mlsimport-field-row[data-field-key="' + saveData.fieldKey + '"]');
        
        // Add visual indicator
        addSavingIndicator($field, saveData.optionType);
        console.log ('saving '+saveData.fieldKey+' / '+saveData.optionType+' / '+saveData.value);
        // Send save request
        $.ajax({
            url: mlsimport_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mlsimport_save_field_option',
                security: mlsimport_params.nonce,
                field_key: saveData.fieldKey,
                option_type: saveData.optionType,
                value: saveData.value
            },
            success: function(response) {
                console.log(response);
                if (response.success) {
                    // Remove from pending saves
                    // Only remove from pending saves if this is the
                    // latest queued save for this key
                    if (STATE.pendingSaves[saveKey] === saveData) {
                        delete STATE.pendingSaves[saveKey];
                    }

                    // Show success indicator
                    updateSavingIndicator($field, saveData.optionType, 'success');

                    // Reset retry count
                    STATE.retryCount[saveKey] = 0;
                } else {
                    // Show error indicator
                    updateSavingIndicator($field, saveData.optionType, 'error');

                    // Retry if under max retries
                    handleSaveRetry(saveKey);
                }
            },
            error: function(e) {
                console.log(e);
                // Show error indicator
                updateSavingIndicator($field, saveData.optionType, 'error');

                // Retry if under max retries
                handleSaveRetry(saveKey);
            },
            complete: function() {
                console.log('cmpletee ');
                STATE.saving = false;
                window.mlsimportSaving = false;
                processSaveQueue();
            }
        });
    }
    
    /**
     * Handle retry logic for failed saves
     */
    function handleSaveRetry(saveKey) {
        // Initialize retry count if needed
        if (STATE.retryCount[saveKey] === undefined) {
            STATE.retryCount[saveKey] = 0;
        }
        
        // Increment retry count
        STATE.retryCount[saveKey]++;
        
        // Check if we should retry
        if (STATE.retryCount[saveKey] <= CONFIG.maxRetries) {
            console.log('Retrying save for ' + saveKey + ' (attempt ' + STATE.retryCount[saveKey] + ' of ' + CONFIG.maxRetries + ')');
            
            // Use exponential backoff
            const delay = CONFIG.retryDelay * Math.pow(2, STATE.retryCount[saveKey] - 1);
            
            // Schedule retry
            setTimeout(function() {
                enqueueSave(saveKey);
            }, delay);
        } else {
            console.error('Max retries exceeded for ' + saveKey);
            
            // Show more persistent error
            const $field = $('.mlsimport-field-row[data-field-key="' + STATE.pendingSaves[saveKey].fieldKey + '"]');
            showSaveError($field, 'Could not save this field. Please try again or refresh the page.');
        }
    }
    
    /**
     * Add saving indicator to a field
     */
    function addSavingIndicator($field, optionType) {
        // Determine which element to add indicator to
        let $element;
        
        switch (optionType) {
            case 'import':
                $element = $field.find('.mlsimport-field-import');
                break;
            case 'admin':
                $element = $field.find('.mlsimport-field-admin');
                break;
            case 'label':
                $element = $field.find('.mlsimport-field-label');
                break;
            case 'postmeta':
                $element = $field.find('.mlsimport-field-postmeta');
                break;
            case 'taxonomy':
                $element = $field.find('.mlsimport-field-taxonomy');
                break;
            default:
                $element = $field;
        }
        
        // Remove any existing indicators
        $element.find('.save-indicator').remove();
        
        // Add saving indicator
        $element.append('<span class="save-indicator" style="margin-left: 5px; box-sizing: border-box;display: inline-block; width: 16px; height: 16px; border: 2px solid #635BFF; border-radius: 50%; border-top-color: transparent; animation: mlsimport-spin 1s linear infinite;"></span>');
        
        // Add spin animation if it doesn't exist
        if (!$('#mlsimport-spin-animation').length) {
            $('head').append('<style id="mlsimport-spin-animation">@keyframes mlsimport-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>');
        }
    }
    
    /**
     * Update saving indicator to show success or error
     */
    function updateSavingIndicator($field, optionType, status) {
        // Determine which element has the indicator
        let $element;
        
        switch (optionType) {
            case 'import':
                $element = $field.find('.mlsimport-field-import');
                break;
            case 'admin':
                $element = $field.find('.mlsimport-field-admin');
                break;
            case 'label':
                $element = $field.find('.mlsimport-field-label');
                break;
            case 'postmeta':
                $element = $field.find('.mlsimport-field-postmeta');
                break;
            case 'taxonomy':
                $element = $field.find('.mlsimport-field-taxonomy');
                break;
            default:
                $element = $field;
        }
        
        // Get indicator
        const $indicator = $element.find('.save-indicator');
        
        if (status === 'success') {
            // Change to checkmark
            $indicator.css({
                'border': 'none',
                'animation': 'none',
                'color': '#46b450',
                'font-size': '16px'
            }).html('✓');
            
            // Remove after delay
            setTimeout(function() {
                $indicator.fadeOut(500, function() {
                    $indicator.remove();
                });
            }, 1000);
            
        } else if (status === 'error') {
            // Change to X
            $indicator.css({
                'border': 'none',
                'animation': 'none',
                'color': '#dc3232',
                'font-size': '16px'
            }).html('✕');
            
            // Make clickable to retry
            $indicator.css('cursor', 'pointer').attr('title', 'Click to retry');
            
            // Add click handler to retry
            $indicator.on('click', function() {
                const fieldKey = $field.data('field-key');
                const saveKey = fieldKey + '_' + optionType;
                
                // If save data still exists
                if (STATE.pendingSaves[saveKey]) {
                    // Remove indicator
                    $indicator.remove();
                    
                    // Retry save
                    saveField(saveKey);
                }
            });
        }
    }
    
    /**
     * Show a save error message for a field
     */
    function showSaveError($field, message) {
        // Check if error message already exists
        if ($field.find('.mlsimport-field-error').length === 0) {
            // Create error message
            const $error = $('<div class="mlsimport-field-error" style="color: #dc3232; margin-top: 5px; padding: 5px; background: #fbeaea; border-left: 3px solid #dc3232;">' + message + '</div>');
            
            // Add close button
            $error.append('<span class="close-error" style="float: right; cursor: pointer; font-weight: bold;">&times;</span>');
            
            // Add to field
            $field.append($error);
            
            // Add close handler
            $field.find('.close-error').on('click', function() {
                $error.remove();
            });
        }
    }
    

    // Initialize when document is ready
    $(document).ready(function() {
 
        
        // Get the current URL
        const currentUrl = window.location.href;
        console.log (currentUrl);
        // Check if the URL matches either of the target pages
        if (
            currentUrl.includes('admin.php?page=mlsimport_plugin_options&tab=field_options') ||
            currentUrl.includes('admin.php?page=mlsimport-onboarding&step=field-mapping')
        ) {
            // Only initialize on the specified pages
            console.log('start initProgressiveSave');
            initProgressiveSave();
        }
    });
})(jQuery);