/**
 * MLS Import Field Selector JavaScript
 * 
 * Handles client-side functionality for the MLS field selection interface:
 * - Filtering and searching fields
 * - Drag and drop reordering
 * - Bulk actions
 * - Interactive UI elements
 * 
 * @package    MLSImport
 * @subpackage MLSImport/js
 * @since      1.0.0
 */


(function($) {
    'use strict';

    /**
     * Initialize all field selector functionality once the document is ready
     */
    $(document).ready(function() {
        // Reveal field rows progressively with an animated progress bar
        initProgressiveLoading();
        // Wire the search / status / alphabet / reset / pagination filter controls
        initializeFilters();
        // Wire the bulk select-all / select-none buttons
        initializeBulkActions();
        // Enable jQuery UI sortable drag-and-drop row reordering
        initializeDragAndDrop();
        // Wire the per-row up / down / top / bottom move buttons
        initRowReordering();
        // Wire the sort dropdown
        initializeFieldSorting();
        // Stop Enter keypresses in label / postmeta inputs from submitting the form
         preventEnterSubmission();
    });

    /**
     * Prevent form submission when pressing Enter inside label or postmeta inputs
     */
    function preventEnterSubmission() {
        // Delegate keydown so dynamically added inputs are also covered
        jQuery(document).on('keydown', '.mlsimport-label-input, .mlsimport-postmeta-input', function(e) {
            // Swallow the Enter key so the surrounding form is not submitted
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
    }


    /**
     * Initialize filter and search functionality
     */
    function initializeFilters() {
        // Field search functionality
        $('#mlsimport-field-search')
            .on('keydown', function(e) {
                // Prevent form submission when pressing Enter inside the search box
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            })
            .on('keyup', function() {
                // Read the current query, normalised to lower case
                const searchTerm = $(this).val().toLowerCase();

                // Only filter once there are >2 characters, or when the box is cleared
                if (searchTerm.length > 2 || searchTerm.length === 0) {
                    filterFieldsBySearch(searchTerm);
                }
            });

        // Import status filter functionality
        $('#mlsimport-import-filter').on('change', function() {
            // Read the selected status value and filter rows by it
            const filterValue = $(this).val();
            filterFieldsByImportStatus(filterValue);
        });

        // Add alphabetical filters if present
        $('.mlsimport-alpha-filter').on('click', function(e) {
            e.preventDefault();
            
            // Remove active class from all alpha filters
            $('.mlsimport-alpha-filter').removeClass('active');
            
            // Add active class to clicked filter
            $(this).addClass('active');

            // Read the letter carried on the clicked control and filter by it
            const letter = $(this).data('letter');
            filterFieldsByAlphabet(letter);
        });

        // Reset filters button
        $('#mlsimport-reset-filters').on('click', function(e) {
            e.preventDefault();
            // Clear every filter and show all rows
            resetAllFilters();
        });

        // Pagination links
        $('.mlsimport-page-link').on('click', function(e) {
            e.preventDefault();

            // Read the target page number and navigate to it
            const page = $(this).data('page');
            navigateToPage(page);
        });
    }

    /**
     * Filter table rows based on search term
     * 
     * @param {string} searchTerm - The term to search for
     */
    function filterFieldsBySearch(searchTerm) {
        // If search is empty, show all rows (respecting other filters)
        if (searchTerm === '') {
            $('.mlsimport-field-row').show();
            return;
        }
        
        // Hide all rows first
        $('.mlsimport-field-row').hide();
        
        // Show rows that match the search term
        $('.mlsimport-field-row').each(function() {
            // Compare against the row's field key (data-field-key), lower-cased
            const fieldName = $(this).data('field-key').toLowerCase();

            // Substring match reveals the row
            if (fieldName.indexOf(searchTerm) !== -1) {
                $(this).show();
            }
        });
        
        // Update the "no results" message
        updateNoResultsMessage();
    }

    /**
     * Filter table rows based on import status
     * 
     * @param {string} status - The import status to filter by ('all', 'selected', 'not_selected', 'mandatory')
     */
    function filterFieldsByImportStatus(status) {
        // If all, show all rows
        if (status === 'all') {
            $('.mlsimport-field-row').show();
            return;
        }
        
        // Hide all rows first
        $('.mlsimport-field-row').hide();
        
        // Show rows based on import status
        $('.mlsimport-field-row').each(function() {
            // Whether the row's import checkbox is ticked
            const isChecked = $(this).find('.mlsimport-import-checkbox').prop('checked');
            // Whether the field is flagged mandatory (always imported)
            const isMandatory = $(this).data('is-mandatory') === 'true';

            // 'mandatory' view: only mandatory rows
            if (status === 'mandatory' && isMandatory) {
                $(this).show();
            // 'selected' view: checked OR mandatory rows
            } else if (status === 'selected' && (isChecked || isMandatory)) {
                $(this).show();
            // 'not_selected' view: rows neither checked nor mandatory
            } else if (status === 'not_selected' && !isChecked && !isMandatory) {
                $(this).show();
            }
        });
        
        // Update the "no results" message
        updateNoResultsMessage();
    }

    /**
     * Filter table rows based on first letter
     * 
     * @param {string} letter - The first letter to filter by
     */
    function filterFieldsByAlphabet(letter) {
        // If letter is empty or 'all', show all rows
        if (!letter || letter === 'all') {
            $('.mlsimport-field-row').show();
            return;
        }
        
        // Hide all rows first
        $('.mlsimport-field-row').hide();
        
        // Show rows that start with the specified letter
        $('.mlsimport-field-row').each(function() {
            // The row's field key and its first character (upper-cased)
            const fieldName = $(this).data('field-key');
            const firstLetter = fieldName.charAt(0).toUpperCase();

            // Reveal the row when its initial matches the requested letter
            if (firstLetter === letter.toUpperCase()) {
                $(this).show();
            }
        });
        
        // Update the "no results" message
        updateNoResultsMessage();
    }

    /**
     * Reset all filters and show all fields
     */
    function resetAllFilters() {
        // Reset search input
        $('#mlsimport-field-search').val('');
        
        // Reset import filter dropdown
        $('#mlsimport-import-filter').val('all');
        
        // Reset alphabetical filter
        $('.mlsimport-alpha-filter').removeClass('active');
        $('.mlsimport-alpha-filter[data-letter="all"]').addClass('active');
        
        // Show all rows
        $('.mlsimport-field-row').show();
        
        // Hide the "no results" message
        $('.mlsimport-no-results').hide();
    }

    /**
     * Check if there are any visible rows and show/hide the "no results" message
     */
    function updateNoResultsMessage() {
        // Count how many rows are currently visible after filtering
        const visibleRows = $('.mlsimport-field-row:visible').length;

        // No visible rows: ensure a "no results" row is shown
        if (visibleRows === 0) {
            // If no results message doesn't exist, create it
            if ($('.mlsimport-no-results').length === 0) {
                // Span the message across every table header column
                const colspan = $('.mlsimport-fields-table thead th').length;
                const message = $('<tr class="mlsimport-no-results"><td colspan="' + colspan + '">No fields found matching your criteria.</td></tr>');
                $('#mlsimport-fields-table-body').append(message);
            } else {
                // Otherwise reuse the existing message row
                $('.mlsimport-no-results').show();
            }
        } else {
            // Rows are visible: hide the "no results" message
            $('.mlsimport-no-results').hide();
        }
    }

    /**
     * Navigate to a specific page
     * 
     * @param {number} page - The page number to navigate to
     */
    function navigateToPage(page) {
        // This would typically reload the page with the new page parameter
        // For this implementation, we'll use JavaScript to update the form and submit
        
        // Create or update a hidden input for the page
        if ($('input[name="mlsimport_page"]').length > 0) {
            // Hidden page input already present: just update its value
            $('input[name="mlsimport_page"]').val(page);
        } else {
            // Otherwise inject a fresh hidden input carrying the page number
            $('<input>').attr({
                type: 'hidden',
                name: 'mlsimport_page',
                value: page
            }).appendTo('.mlsimport-fields-form');
        }
        
        // Submit the form
        $('.mlsimport-fields-form').submit();
    }

    /**
     * Initialize bulk action functionality
     */
    function initializeBulkActions() {
        // Select All for Import checkboxes (only non-mandatory fields)
        jQuery('#mlsimport-select-all-import').on('click', function(e) {
            e.preventDefault();
            // Tick every visible import checkbox and persist in bulk
            bulkSaveImportSelections(true);
        });

        // Select None for Import checkboxes
        jQuery('#mlsimport-select-none-import').on('click', function(e) {
            e.preventDefault();
            // Untick every visible import checkbox and persist in bulk
            bulkSaveImportSelections(false);
        });
    
        // Select All for Admin Only checkboxes
        jQuery('#mlsimport-select-all-admin').on('click', function(e) {
            e.preventDefault();
            // Tick every visible admin-only checkbox and persist in bulk
            bulkSaveAdminSelections(true);
        });

        // Select None for Admin Only checkboxes
        jQuery('#mlsimport-select-none-admin').on('click', function(e) {
            e.preventDefault();
            // Untick every visible admin-only checkbox and persist in bulk
            bulkSaveAdminSelections(false);
        });
    
        // Update stats when a checkbox is clicked
        jQuery('.mlsimport-import-checkbox').on('change', function() {
            // Recompute the totals shown in the stats header
            updateFieldStats();
        });
    }
    

    /**
     * Update the field statistics displayed at the top.
     *
     * Exposed on `window` so other scripts (e.g. progressive-save) can refresh
     * the counts after they mutate checkboxes or labels.
     */
    window.updateFieldStats = function() {
        // Total number of field rows in the table
        const totalFields = $('.mlsimport-field-row').length;
        // Rows flagged mandatory (always counted as selected)
        const mandatoryFields = $('.mlsimport-field-row[data-is-mandatory="true"]').length;
        // Selected = checked import boxes plus the mandatory rows
        const selectedFields = $('.mlsimport-import-checkbox:checked').length + mandatoryFields;
        
        // Count fields with empty labels that are selected for import
        let missingLabels = 0;
        
        // Count for non-mandatory checked fields with missing labels
        $('.mlsimport-import-checkbox:checked').each(function() {
            // Read the label input in this checkbox's row
            const row = $(this).closest('tr');
            const labelValue = row.find('.mlsimport-label-input').val();

            // Empty / whitespace-only label counts as missing
            if (!labelValue || labelValue.trim() === '') {
                missingLabels++;
            }
        });
        
        // Count for mandatory fields with missing labels
        $('.mlsimport-field-row[data-is-mandatory="true"]').each(function() {
            // Read the label input in this mandatory row
            const labelValue = $(this).find('.mlsimport-label-input').val();

            // Empty / whitespace-only label counts as missing
            if (!labelValue || labelValue.trim() === '') {
                missingLabels++;
            }
        });
        
        // Update the stats display (three <li> summary lines)
        $('.mlsimport-field-stats li').eq(0).text(totalFields + ' fields total');
        $('.mlsimport-field-stats li').eq(1).text(selectedFields + ' marked for import');
        $('.mlsimport-field-stats li').eq(2).text(missingLabels + ' missing labels');
    }

    /**
     * Initialize drag and drop functionality for reordering fields
     */
    function initializeDragAndDrop() {
   
        // Check if jQuery UI sortable is available
        if ($.fn.sortable) {
           
            // Make the entire row draggable. Interactive elements like
            // inputs and buttons remain excluded via the default `cancel`
            // option so they can still be used without initiating a drag.
            $('#mlsimport-fields-table-body').sortable({
                cancel: 'input, textarea, button, select',
                helper: function(e, tr) {
                    // Create a helper that maintains cell widths
                    const $originals = tr.children();
                    const $helper = tr.clone();

                    // Copy each original cell width onto the clone so the drag ghost keeps its layout
                    $helper.children().each(function(index) {
                        $(this).width($originals.eq(index).width());
                    });
                    
                    return $helper;
                },
                update: function(event, ui) {

                    // The row that was just dropped, and the row now above it
                    const $movedRow = jQuery(ui.item);
                    const $prevRow = $movedRow.prev('.mlsimport-field-row');

                    // Order value the dropped row carried before repositioning
                    const movingOrder = parseInt($movedRow.attr('data-field-order'), 10);

                    // If no previous row exists, it's the first position
                    if (!$prevRow.length) {

                        // Persist as "before order 0" (top of the list)
                        saveFieldPosition(movingOrder, 0, 'before');
                    } else {
                        // Normal case - moving after another row
                        const prevOrder = parseInt($prevRow.attr('data-field-order'), 10);
                        saveFieldPosition(movingOrder, prevOrder, 'after');
                    }

                    // Renumber the visible data-field-order attributes / labels
                    refreshRowPositions();
                }
                
            });
            
            // Add visual cue for draggable rows
            $('.mlsimport-field-row td').css('cursor', 'move');
          
        } else {
            // Sortable plugin missing: log and leave rows static
            console.error('jQuery UI sortable not available. Drag and drop ordering is disabled.');
        }
    }

  

  
})(jQuery);



/**
 * Virtual/infinite scrolling loader for the field table.
 *
 * Shows the first page of rows and reveals further batches as the user
 * scrolls near the bottom of the document. Superseded by
 * initProgressiveLoading() but kept for reference.
 */
function initVirtualScrolling() {
 
    
    // Store all rows
    let allRows = jQuery('.mlsimport-field-row').toArray();


    // How many rows per page, how many are currently shown, and a scroll re-entry guard
    let rowsPerPage = 50;
    let visibleCount = rowsPerPage;
    let loading = false;
    
    // Make sure only initial rows are visible by hiding everything and then showing first 50
    jQuery('.mlsimport-field-row').hide();

    // Reveal the first page of rows
    for (let i = 0; i < Math.min(rowsPerPage, allRows.length); i++) {
        jQuery(allRows[i]).show();
    }
    
    // Add loading indicator if there are more than 50 rows
    if (allRows.length > rowsPerPage) {
        jQuery('<div class="mlsimport-loading" style="text-align: center; padding: 20px; margin-top: 20px; background: #f0f0f0; border-top: 1px solid #ddd;">Scroll down to load more fields...</div>')
            .insertAfter('.mlsimport-fields-table');
        
        // Detect scroll
        jQuery(window).on('scroll', function() {
            // Ignore scroll events while a batch is already loading
            if (loading) return;
            
            // Check if user has scrolled near the bottom
            let scrollPosition = jQuery(window).scrollTop() + jQuery(window).height();
            let documentHeight = jQuery(document).height();

            // Within 300px of the bottom: pull in the next batch
            if (scrollPosition > documentHeight - 300) {
                loadMoreRows();
            }
        });
    }

    /**
     * Reveal the next page of rows after a short simulated delay.
     */
    function loadMoreRows() {
        // Guard re-entry and show the loading label
        loading = true;
        jQuery('.mlsimport-loading').text('Loading more fields...').show();
        
        // Simulate loading delay for visual feedback
        setTimeout(function() {
            // Show next batch of rows
            let endIndex = Math.min(visibleCount + rowsPerPage, allRows.length);

            // Reveal the rows from the current cursor up to endIndex
            for (let i = visibleCount; i < endIndex; i++) {
                jQuery(allRows[i]).show();
            }

            // Advance the cursor and release the guard
            visibleCount = endIndex;
            loading = false;
            
            // Update or remove loading message
            if (visibleCount >= allRows.length) {
                jQuery('.mlsimport-loading').remove(); // Remove instead of just changing text
            } else {
                jQuery('.mlsimport-loading').text('Scroll down to load more fields...');
            }
        }, 300);
    }
}

// Complete row movement implementation with debugging
/**
 * Bind click handlers to the four per-row move buttons (up / down / top / bottom).
 */
function initRowReordering() {
   
    
    // Attach click handlers to up buttons
    jQuery('.mlsimport-move-up').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Prevent event bubbling
        // Resolve the row containing the clicked button, then move it up
        var $row = jQuery(this).closest('.mlsimport-field-row');
     
        moveRowUp($row);
    });

    // Attach click handlers to down buttons
    jQuery('.mlsimport-move-down').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Prevent event bubbling
        // Resolve the row containing the clicked button, then move it down
        var $row = jQuery(this).closest('.mlsimport-field-row');
     
        moveRowDown($row);
    });

    // Attach click handlers to move top buttons
    jQuery('.mlsimport-move-top').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        // Resolve the row containing the clicked button, then move it to the top
        var $row = jQuery(this).closest('.mlsimport-field-row');
      
        moveRowTop($row);
    });

    // Attach click handlers to move bottom buttons
    jQuery('.mlsimport-move-bottom').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        // Resolve the row containing the clicked button, then move it to the bottom
        var $row = jQuery(this).closest('.mlsimport-field-row');
      
        moveRowBottom($row);
    });
}


// Debounce timer plus the "start of run" order/key, so rapid up-clicks collapse
// into a single saveFieldPosition call using the row's ORIGINAL position.
let moveUpTimer = null;
let originalMovingOrder = null;
let movingFieldKey = null;

/**
 * Move a row up by one position, animating the swap and debouncing the save.
 *
 * @param {jQuery} $row - The field row to move up.
 */
function moveRowUp($row) {
    // Row immediately above; bail out if already at the top
    const $prev = $row.prev('.mlsimport-field-row');
    if (!$prev.length) {
       
        return;
    }

    try {
        // Remember the move button's viewport position to keep it under the cursor after the swap
        const $button = $row.find('.mlsimport-move-up');
        const oldTop = $button.offset().top;

        // Get the field key (unique identifier for the row)
        const fieldKey = $row.attr('data-field-key');
        
        // If this is a new field being moved (not continuation of previous moves)
        if (fieldKey !== movingFieldKey) {
            // Capture the run's originating key and order for the eventual save
            movingFieldKey = fieldKey;
            originalMovingOrder = parseInt($row.attr('data-field-order'), 10);
          
        }


        // Get current orders for the swap
        const movingOrder = parseInt($row.attr('data-field-order'), 10);
        const targetOrder = parseInt($prev.attr('data-field-order'), 10);

        // Swap order attributes
        $row.attr('data-field-order', targetOrder);
        $prev.attr('data-field-order', movingOrder);

        // Move the row
        $row.insertBefore($prev);
        // Renumber positions and flash the moved row
        refreshRowPositions();
        highlightRow($row);

        // Scroll by the button's displacement so it stays put on screen
        const newTop = $button.offset().top;
        const deltaY = newTop - oldTop;
        window.scrollBy(0, deltaY);

        // Clear existing timer
        if (moveUpTimer !== null) {
            clearTimeout(moveUpTimer);
        }
        
        // Set new timer
        moveUpTimer = setTimeout(function() {
         
            
            // Find the row by field key
            const $movedRow = jQuery(`.mlsimport-field-row[data-field-key="${movingFieldKey}"]`);

            // Only persist once the moved row still exists in the DOM
            if ($movedRow.length) {
                // Get the previous row
                const $prevRow = $movedRow.prev('.mlsimport-field-row');
                
                if ($prevRow.length) {
                    // Persist "place original position before the current predecessor"
                    const prevOrder = parseInt($prevRow.attr('data-field-order'), 10);
                  
                    saveFieldPosition(originalMovingOrder, prevOrder, 'before');
                } else {
                    // Row is at the top
                   
                    saveFieldPosition(originalMovingOrder, 0, 'before');
                }
            }
            
            // Reset tracking variables
            moveUpTimer = null;
            originalMovingOrder = null;
            movingFieldKey = null;
        }, 1000);

    } catch (e) {
        console.error("Error moving row up:", e);
    }
}




// Mirror of the move-up debounce state, for downward moves.
let moveDownTimer = null;
let originalDownOrder = null;
let movingDownFieldKey = null;

// Queue for saving field positions so requests don't overlap
let positionSaving = false;
let positionQueue = [];

/**
 * Move a row down by one position, animating the swap and debouncing the save.
 *
 * @param {jQuery} $row - The field row to move down.
 */
function moveRowDown($row) {
    // Get the next row specifically with the same class
    var $next = $row.next('.mlsimport-field-row');


    // Only proceed when there is a row below to swap with
    if ($next.length) {
        try {
            // Save exact mouse position on screen
            var mouseY = window.event.clientY;
            var mouseX = window.event.clientX;
            // Remember the button's viewport position to preserve scroll after the swap
            var $button = $row.find('.mlsimport-move-down');
            var oldOffset = $button.offset();
            var oldTop = oldOffset.top;
            
            // Get the field key (unique identifier for the row)
            const fieldKey = $row.attr('data-field-key');
            
            // If this is a new field being moved (not continuation of previous moves)
            if (fieldKey !== movingDownFieldKey) {
                // Capture the run's originating key and order for the eventual save
                movingDownFieldKey = fieldKey;
                originalDownOrder = parseInt($row.attr('data-field-order'), 10);
             
            }
            
            // Get current order values
            var movingOrder = parseInt($row.attr('data-field-order'), 10);
            var targetOrder = parseInt($next.attr('data-field-order'), 10);
            
            // Swap the data-field-order values
            $row.attr('data-field-order', targetOrder);
            $next.attr('data-field-order', movingOrder);

            // Move the row in the UI
            $row.insertAfter($next);
            refreshRowPositions();

            // Highlight to confirm movement
            highlightRow($row);
            
            // Get new position
            var newOffset = $button.offset();
            
            // Find how much the position changed
            var deltaY = newOffset.top - oldTop;
            
            // Adjust scroll to keep relative position
            window.scrollBy(0, deltaY);
            
            // Clear existing timer
            if (moveDownTimer !== null) {
                clearTimeout(moveDownTimer);
            }
            
            // Set new timer
            moveDownTimer = setTimeout(function() {
              
                
                // Find the row by field key
                const $movedRow = jQuery(`.mlsimport-field-row[data-field-key="${movingDownFieldKey}"]`);

                // Only persist once the moved row still exists in the DOM
                if ($movedRow.length) {
                    // Get the next row
                    const $nextRow = $movedRow.next('.mlsimport-field-row');
                    
                    if ($nextRow.length) {
                        // Persist after the successor's order, adjusted down by one
                        let nextOrder = parseInt($nextRow.attr('data-field-order'), 10);
                        nextOrder=nextOrder-1;
                      
                        saveFieldPosition(originalDownOrder, nextOrder, 'after');
                    } else {
                        // Row is at the bottom

                        // Persist after its own (last) order value
                        const lastOrder = parseInt($movedRow.attr('data-field-order'), 10);
                        saveFieldPosition(originalDownOrder, lastOrder, 'after');
                    }
                }
                
                // Reset tracking variables
                moveDownTimer = null;
                originalDownOrder = null;
                movingDownFieldKey = null;
            }, 1000);

        } catch (e) {
            console.error("Error moving row down:", e);
        }
    } else {
        console.log("No next row found, can't move down");
    }
}








/**
 * Enqueue a field-position save and kick off queue processing.
 *
 * @param {number} movingOrder - Original order of the row being moved.
 * @param {number} targetOrder - Order of the anchor row to position relative to.
 * @param {string} position    - 'before' or 'after' the anchor.
 */
function saveFieldPosition(movingOrder, targetOrder, position) {
    // Queue the request so overlapping moves are serialised
    positionQueue.push({ movingOrder, targetOrder, position });
    processPositionQueue();
}

/**
 * Process one queued position-save at a time via AJAX, guarding against
 * overlap with other in-flight saves (window.mlsimportSaving).
 */
function processPositionQueue() {
    // Skip while another save runs, the queue is empty, or a global save is active
    if (positionSaving || positionQueue.length === 0 || window.mlsimportSaving) {
        return;
    }

    // Claim both the local and global save locks
    positionSaving = true;
    window.mlsimportSaving = true;
    // Dequeue the next position-save request
    const item = positionQueue.shift();

    // Disable move buttons for the duration of the request
    jQuery('.mlsimport-move-up, .mlsimport-move-down').prop('disabled', true);
    // Resolve the security nonce: prefer the hidden field, fall back to localized params
    let nonce = '';
    if (jQuery('#mlsimport_field_selector_nonce').length > 0) {
        nonce = jQuery('#mlsimport_field_selector_nonce').val();
    } else if (typeof mlsimport_params !== 'undefined' && mlsimport_params.nonce) {
        nonce = mlsimport_params.nonce;
    }

    // Show a transient "saving" notification
    const $notification = jQuery('<div class="mlsimport-notification mlsimport-notification-info">Saving field position...</div>');
    jQuery('body').append($notification).fadeIn();

    // POST the new position to the admin-ajax endpoint
    jQuery.ajax({
        url: mlsimport_params.ajax_url,
        type: 'POST',
        data: {
            action: 'mlsimport_save_field_position',
            security: nonce,
            moving_index: item.movingOrder,
            target_index: item.targetOrder,
            position: item.position
        },
        success: function(response) {
            // Report success or a server-provided error message
            if (response.success) {
                showNotification('Field position saved.', 'success');
            } else {
                showNotification('Error saving position: ' + (response.data || 'Unknown error'), 'error');
            }
        },
        error: function() {
            // Transport-level failure
            showNotification('Server error while saving position.', 'error');
        },
        complete: function() {
            // Re-enable move buttons and remove the transient notification
            jQuery('.mlsimport-move-up, .mlsimport-move-down').prop('disabled', false);
            $notification.remove();
            // Release both save locks
            positionSaving = false;
            window.mlsimportSaving = false;
            // Drain any further queued position saves
            processPositionQueue();
            // Also let the progressive-save queue (label/postmeta edits) run
            if (typeof window.processSaveQueue === 'function') {
                window.processSaveQueue();
            }
        }
    });
}


// Highlight function
/**
 * Briefly flash a row's background to confirm it moved.
 *
 * @param {jQuery} $row - Row to highlight.
 */
function highlightRow($row) {
    // Apply a pale-yellow background, then clear it after 500ms
    $row.css('background-color', '#ffffd0');
    setTimeout(function() {
        $row.css('background-color', '');
    }, 500);
}

// Refresh data-field-order attributes and debug positions
/**
 * Renumber every row's data-field-order and visible position label to match
 * its current DOM index.
 */
function refreshRowPositions() {
    jQuery('#mlsimport-fields-table-body .mlsimport-field-row').each(function(index) {
        // Reset the order attribute to the current index
        jQuery(this).attr('data-field-order', index);
        // Update the human-readable "N. " position label
        jQuery(this).find('.field-position').text((index + 1) + '. ');
    });
}

// Move a row directly to the top
/**
 * Move a row to the very top of the table and persist the change.
 *
 * @param {jQuery} $row - Row to move.
 */
function moveRowTop($row) {
    // Already first: nothing to do
    const $first = jQuery('.mlsimport-field-row').first();
    if ($row.is($first)) return;

    // Remember button position and the row's original order
    const $button = $row.find('.mlsimport-move-top');
    const oldTop = $button.offset().top;
    const originalOrder = parseInt($row.attr('data-field-order'), 10);

    // Reposition to the top, renumber, and highlight
    $row.insertBefore($first);
    refreshRowPositions();
    highlightRow($row);

    // Keep the button under the cursor by scrolling the displacement
    const newTop = $button.offset().top;
    window.scrollBy(0, newTop - oldTop);

    // Persist as "before order 0"
    saveFieldPosition(originalOrder, 0, 'before');
}

// Move a row directly to the bottom
/**
 * Move a row to the very bottom of the table and persist the change.
 *
 * @param {jQuery} $row - Row to move.
 */
function moveRowBottom($row) {
    // Already last: nothing to do
    const $last = jQuery('.mlsimport-field-row').last();
    if ($row.is($last)) return;

    // Remember button position, the row's original order, and the last row's order
    const $button = $row.find('.mlsimport-move-bottom');
    const oldTop = $button.offset().top;
    const originalOrder = parseInt($row.attr('data-field-order'), 10);
    const lastOrder = parseInt($last.attr('data-field-order'), 10);

    // Reposition to the bottom, renumber, and highlight
    $row.insertAfter($last);
    refreshRowPositions();
    highlightRow($row);

    // Keep the button under the cursor by scrolling the displacement
    const newTop = $button.offset().top;
    window.scrollBy(0, newTop - oldTop);

    // Persist as "after the last row's order"
    saveFieldPosition(originalOrder, lastOrder, 'after');
}


/**
 * Handles the sorting dropdown functionality
 */
function initializeFieldSorting() {
    // Add change event handler to the sorting dropdown
    jQuery('#mlsimport-field-sort').on('change', function() {
        // Read the chosen sort key (e.g. "label_asc") and re-sort the rows
        const sortValue = jQuery(this).val();
        sortFields(sortValue);
    });
}

/**
 * Initialize tooltips for row action buttons using jQuery UI
 */
function initializeMoveButtonTooltips() {
    // Only if the jQuery UI tooltip widget is loaded
    if (jQuery.fn.tooltip) {
        // Attach tooltips scoped to the row-action move buttons
        jQuery(document).tooltip({
            items: '.mlsimport-row-actions .mlsimport-move-btn',
            classes: { 'ui-tooltip': 'mlsimport-move-tooltip' }
        });
    } else {
        // Widget missing: warn and skip tooltips
        console.warn('jQuery UI tooltip not available.');
    }
}

/**
 * Sort fields based on selected criteria
 * 
 * @param {string} sortBy - The field to sort by
 */
// Debug and fix for label sorting
function sortFields(sortBy) {

    // Table body and its field rows as a plain array we can sort
    const $tbody = jQuery('#mlsimport-fields-table-body');
    let $rows = $tbody.find('tr.mlsimport-field-row').toArray();
    
    // Split sort value into criteria and direction
    const [criteria, direction] = sortBy.split('_');

    
    // Sort the rows based on the selected criteria
    $rows.sort(function(a, b) {
        // Wrap each raw row for jQuery access; result holds the comparison outcome
        const $a = jQuery(a);
        const $b = jQuery(b);
        let result = 0;
        
        // Debug the actual elements to make sure we're accessing correctly
      //  if (criteria === 'label') {
            
       // }
        
        switch (criteria) {
            case 'label':
                // Sort by the label input value
                let labelA = $a.find('.mlsimport-label-input').val() || '';
                let labelB = $b.find('.mlsimport-label-input').val() || '';

                // Normalise both labels to lower case
                labelA = labelA.toLowerCase();
                labelB = labelB.toLowerCase();

                // Push rows with empty labels to the end
                if (labelA === '' && labelB !== '') return 1;
                if (labelA !== '' && labelB === '') return -1;
                // Both empty: fall back to comparing field keys
                if (labelA === '' && labelB === '') {
                    return $a.data('field-key').toLowerCase().localeCompare($b.data('field-key').toLowerCase());
                }

                // Both non-empty: alphabetical label comparison
                result = labelA.localeCompare(labelB);
                break;

            case 'postmeta':
                // Compare the post-meta key inputs alphabetically
                let pmA = ($a.find('.mlsimport-postmeta-input').val() || '').toLowerCase();
                let pmB = ($b.find('.mlsimport-postmeta-input').val() || '').toLowerCase();
                result = pmA.localeCompare(pmB);
                break;

            case 'category':
                // Compare the selected taxonomy option's visible text
                let catA = ($a.find('.mlsimport-field-taxonomy select option:selected').text() || '').toLowerCase();
                let catB = ($b.find('.mlsimport-field-taxonomy select option:selected').text() || '').toLowerCase();
                result = catA.localeCompare(catB);
                break;

            case 'import':
                // Group by whether the import checkbox is checked
                let impA = $a.find('.mlsimport-import-checkbox').is(':checked');
                let impB = $b.find('.mlsimport-import-checkbox').is(':checked');
                if (impA === impB) {
                    result = 0;
                } else {
                    result = impA ? -1 : 1; // selected first
                }
                break;

            case 'hidden':
                // Group by whether the admin-only checkbox is checked
                let hidA = $a.find('.mlsimport-admin-checkbox').is(':checked');
                let hidB = $b.find('.mlsimport-admin-checkbox').is(':checked');
                if (hidA === hidB) {
                    result = 0;
                } else {
                    result = hidA ? -1 : 1; // selected first
                }
                break;

            default:
                // Fallback: sort by the field key
                const defaultNameA = $a.data('field-key').toLowerCase();
                const defaultNameB = $b.data('field-key').toLowerCase();
                result = defaultNameA.localeCompare(defaultNameB);
                break;
        }

        // Apply direction for ascending/descending sorts or selected/unselected
        if (criteria === 'import' || criteria === 'hidden') {
            // Boolean groupings invert when 'unselected' is requested
            if (direction === 'unselected') {
                result = -result;
            }
        } else if (direction === 'desc') {
            // Text sorts invert for descending order
            result = -result;
        }
        
        return result;
    });
    
    // Reattach the sorted rows to the table
    jQuery.each($rows, function(index, row) {
        $tbody.append(row);
    });
    
    // Visual feedback that sorting occurred
    $tbody.fadeOut(100).fadeIn(100);
}

/**
 * Show a notification message to the user
 * 
 * @param {string} message - The message to display
 * @param {string} type - The type of message ('success', 'error', 'info')
 */
function showNotification(message, type) {
    console.log('doing notification');
    // Create notification element if it doesn't exist
    if (jQuery('.mlsimport-notification').length === 0) {
        jQuery('<div class="mlsimport-notification"></div>').appendTo('body');
    }
    
    // Set message and type, then fade in, hold 3s, and fade out
    jQuery('.mlsimport-notification')
        .attr('class', 'mlsimport-notification mlsimport-notification-' + type)
        .text(message)
        .fadeIn()
        .delay(3000)
        .fadeOut();
}

/**
 * Save import selections for all visible fields in bulk
 *
 * @param {boolean} checked - Whether checkboxes should be checked or not
 */
function bulkSaveImportSelections(checked) {
    // Accumulates fieldKey -> 1/0 for the batch request
    const fields = {};

    // Only affect enabled, visible import checkboxes (respects current filter)
    jQuery('.mlsimport-import-checkbox:not([disabled]):visible').each(function() {
        const $checkbox = jQuery(this);
        const $row = $checkbox.closest('.mlsimport-field-row');
        const fieldKey = $row.data('field-key');

        // Set the checkbox and record its intended value
        $checkbox.prop('checked', checked);
        fields[fieldKey] = checked ? 1 : 0;

        // Add saving indicator similar to progressive-save.js
        addBulkSavingIndicator($row, '.mlsimport-field-import');
    });

    // Nothing matched: just refresh the stats and stop
    if (Object.keys(fields).length === 0) {
        updateFieldStats();
        return;
    }

    // Persist the whole batch in one request
    jQuery.ajax({
        url: mlsimport_params.ajax_url,
        type: 'POST',
        data: {
            action: 'mlsimport_save_bulk_import',
            security: mlsimport_params.nonce,
            fields: fields
        },
        success: function(response) {
            // Mark each row's indicator success/error per the response flag
            const status = response.success ? 'success' : 'error';
            jQuery.each(fields, function(key) {
                const $row = jQuery('.mlsimport-field-row[data-field-key="' + key + '"]');
                updateBulkSavingIndicator($row, status, '.mlsimport-field-import');
            });
            updateFieldStats();
        },
        error: function() {
            // Transport failure: flag every row's indicator as error
            jQuery.each(fields, function(key) {
                const $row = jQuery('.mlsimport-field-row[data-field-key="' + key + '"]');
                updateBulkSavingIndicator($row, 'error', '.mlsimport-field-import');
            });
        }
    });
}

/**
 * Save admin visibility selections for all visible fields in bulk
 *
 * @param {boolean} checked - Whether checkboxes should be checked or not
 */
function bulkSaveAdminSelections(checked) {
    // Accumulates fieldKey -> 1/0 for the batch request
    const fields = {};

    // Every visible admin-only checkbox (these have no mandatory/disabled concept)
    jQuery('.mlsimport-admin-checkbox:visible').each(function() {
        const $checkbox = jQuery(this);
        const $row = $checkbox.closest('.mlsimport-field-row');
        const fieldKey = $row.data('field-key');

        // Set the checkbox and record its intended value
        $checkbox.prop('checked', checked);
        fields[fieldKey] = checked ? 1 : 0;

        // Add the spinning saving indicator to the admin column
        addBulkSavingIndicator($row, '.mlsimport-field-admin');
    });

    // Nothing matched: just refresh the stats and stop
    if (Object.keys(fields).length === 0) {
        updateFieldStats();
        return;
    }

    // Persist the whole batch in one request
    jQuery.ajax({
        url: mlsimport_params.ajax_url,
        type: 'POST',
        data: {
            action: 'mlsimport_save_bulk_admin',
            security: mlsimport_params.nonce,
            fields: fields
        },
        success: function(response) {
            // Mark each row's indicator success/error per the response flag
            const status = response.success ? 'success' : 'error';
            jQuery.each(fields, function(key) {
                const $row = jQuery('.mlsimport-field-row[data-field-key="' + key + '"]');
                updateBulkSavingIndicator($row, status, '.mlsimport-field-admin');
            });
            updateFieldStats();
        },
        error: function() {
            // Transport failure: flag every row's indicator as error
            jQuery.each(fields, function(key) {
                const $row = jQuery('.mlsimport-field-row[data-field-key="' + key + '"]');
                updateBulkSavingIndicator($row, 'error', '.mlsimport-field-admin');
            });
        }
    });
}

// Helper to add saving indicator for bulk updates
/**
 * Insert a spinning "saving" indicator into a row's given column.
 *
 * @param {jQuery} $row           - Target row.
 * @param {string} columnSelector - Selector for the cell to annotate.
 */
function addBulkSavingIndicator($row, columnSelector) {
    // Remove any stale indicator, then append a fresh spinner span
    const $element = $row.find(columnSelector);
    $element.find('.save-indicator').remove();
    $element.append('<span class="save-indicator" style="margin-left: 5px; box-sizing: border-box; display: inline-block; width: 16px; height: 16px; border: 2px solid #635BFF; border-radius: 50%; border-top-color: transparent; animation: mlsimport-spin 1s linear infinite;"></span>');

    // Inject the spin keyframes once, on first use
    if (!jQuery('#mlsimport-spin-animation').length) {
        jQuery('head').append('<style id="mlsimport-spin-animation">@keyframes mlsimport-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>');
    }
}

// Helper to update indicator after bulk save
/**
 * Swap a row's spinner for a success tick (auto-fading) or an error cross.
 *
 * @param {jQuery} $row           - Target row.
 * @param {string} status         - 'success' or anything else (treated as error).
 * @param {string} columnSelector - Selector for the annotated cell.
 */
function updateBulkSavingIndicator($row, status, columnSelector) {
    // Locate the indicator span inside the given column
    const $element = $row.find(columnSelector);
    const $indicator = $element.find('.save-indicator');

    if (status === 'success') {
        // Success: green tick, then fade out and remove after 1s
        $indicator.css({
            'border': 'none',
            'animation': 'none',
            'color': '#46b450',
            'font-size': '16px'
        }).html('✓');

        setTimeout(function() {
            $indicator.fadeOut(500, function() {
                $indicator.remove();
            });
        }, 1000);
    } else {
        // Error: red cross left in place
        $indicator.css({
            'border': 'none',
            'animation': 'none',
            'color': '#dc3232',
            'font-size': '16px'
        }).html('✕');
    }
}

/**
 * Progressive Field Loading - Replaces the virtual scrolling with timed display
 * This shows fields one by one with a smooth animation regardless of scrolling
 */
function initProgressiveLoading() {
    console.log("Progressive loading initialization started");
    
    // Store all rows
    let allRows = jQuery('.mlsimport-field-row').toArray();
    console.log("Found " + allRows.length + " total rows");
    
    // Hide all rows initially - ensure none are visible at start
    jQuery('.mlsimport-field-row').hide().css('opacity', 0);
    
    // Create progress bar container
    const progressContainer = jQuery('<div class="mlsimport-loading-progress" style="position: sticky; top: 32px; z-index: 100; padding: 10px; background: #f9f9f9; border-bottom: 1px solid #ddd; text-align: center;"></div>');
    const progressText = jQuery('<div class="mlsimport-loading-text">Loading fields: <span class="mlsimport-loading-count">0</span> of ' + allRows.length + '</div>');
    const progressBar = jQuery('<div class="mlsimport-progress-bar" style="height: 10px; background: #eee; margin-top: 5px; border-radius: 5px;"><div class="mlsimport-progress-fill" style="width: 0%; height: 100%; background: #635BFF; border-radius: 5px; transition: width 0.3s;"></div></div>');
    
    // Assemble the text + bar into the container and mount it above the table
    progressContainer.append(progressText).append(progressBar);
    
    // Add progress container at the top of the table
    jQuery('.mlsimport-field-selector-container').prepend(progressContainer);
    
    // Variables for loading control
    let loadedCount = 0;
    let batchSize = 10; // How many rows to show at once
    let interval = 7; // Milliseconds between batches (adjust for speed)
    let isLoading = true;

    /**
     * Update the progress count/bar and clean up once fully loaded.
     *
     * @param {number} count - Number of rows revealed so far.
     */
    function updateProgress(count) {
        // Compute percentage and reflect it in the count + fill width
        const percentage = Math.floor((count / allRows.length) * 100);
        jQuery('.mlsimport-loading-count').text(count);
        jQuery('.mlsimport-progress-fill').css('width', percentage + '%');
        
        // If complete, remove progress or change to completion message
        if (count >= allRows.length) {
            setTimeout(function() {
                progressContainer.fadeOut(500, function() {
                    progressContainer.remove();
                });
            }, 1000);
        }
    }
    
    // Start loading rows with a slight delay
    setTimeout(function() {
        // Reveal one batch per tick until every row is shown
        const loadingInterval = setInterval(function() {
            // Bail out (and stop the interval) if loading was cancelled
            if (!isLoading) {
                clearInterval(loadingInterval);
                return;
            }
            
            // Load next batch of rows
            let endIndex = Math.min(loadedCount + batchSize, allRows.length);

            // Fade each row in this batch from transparent to opaque
            for (let i = loadedCount; i < endIndex; i++) {
                jQuery(allRows[i])
                    .css('opacity', 0)
                    .show()
                    .animate({opacity: 1}, 300);
            }

            // Advance the cursor and refresh the progress display
            loadedCount = endIndex;
            updateProgress(loadedCount);
            
            // Check if we're done
            if (loadedCount >= allRows.length) {
                // All rows revealed: stop the interval
                isLoading = false;
                clearInterval(loadingInterval);
                console.log("All fields have been loaded");


                
            }
        }, interval);
    }, 500); // Small initial delay before starting
    
    // No control buttons needed - just let the loading progress automatically
}