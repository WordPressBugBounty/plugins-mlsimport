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
        initProgressiveLoading();
        initializeFilters();
        initializeBulkActions();
        initializeDragAndDrop();
        initRowReordering();
        initializeFieldSorting();
         preventEnterSubmission();
    });

    /**
     * Prevent form submission when pressing Enter inside label or postmeta inputs
     */
    function preventEnterSubmission() {
        jQuery(document).on('keydown', '.mlsimport-label-input, .mlsimport-postmeta-input', function(e) {
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
                const searchTerm = $(this).val().toLowerCase();

                if (searchTerm.length > 2 || searchTerm.length === 0) {
                    filterFieldsBySearch(searchTerm);
                }
            });

        // Import status filter functionality
        $('#mlsimport-import-filter').on('change', function() {
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
            
            const letter = $(this).data('letter');
            filterFieldsByAlphabet(letter);
        });

        // Reset filters button
        $('#mlsimport-reset-filters').on('click', function(e) {
            e.preventDefault();
            resetAllFilters();
        });

        // Pagination links
        $('.mlsimport-page-link').on('click', function(e) {
            e.preventDefault();
            
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
            const fieldName = $(this).data('field-key').toLowerCase();
            
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
            const isChecked = $(this).find('.mlsimport-import-checkbox').prop('checked');
            const isMandatory = $(this).data('is-mandatory') === 'true';
            
            if (status === 'mandatory' && isMandatory) {
                $(this).show();
            } else if (status === 'selected' && (isChecked || isMandatory)) {
                $(this).show();
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
            const fieldName = $(this).data('field-key');
            const firstLetter = fieldName.charAt(0).toUpperCase();
            
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
        const visibleRows = $('.mlsimport-field-row:visible').length;
        
        if (visibleRows === 0) {
            // If no results message doesn't exist, create it
            if ($('.mlsimport-no-results').length === 0) {
                const colspan = $('.mlsimport-fields-table thead th').length;
                const message = $('<tr class="mlsimport-no-results"><td colspan="' + colspan + '">No fields found matching your criteria.</td></tr>');
                $('#mlsimport-fields-table-body').append(message);
            } else {
                $('.mlsimport-no-results').show();
            }
        } else {
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
            $('input[name="mlsimport_page"]').val(page);
        } else {
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
            bulkSaveImportSelections(true);
        });

        jQuery('#mlsimport-select-none-import').on('click', function(e) {
            e.preventDefault();
            bulkSaveImportSelections(false);
        });
    
        // Select All for Admin Only checkboxes
        jQuery('#mlsimport-select-all-admin').on('click', function(e) {
            e.preventDefault();
            bulkSaveAdminSelections(true);
        });

        // Select None for Admin Only checkboxes
        jQuery('#mlsimport-select-none-admin').on('click', function(e) {
            e.preventDefault();
            bulkSaveAdminSelections(false);
        });
    
        // Update stats when a checkbox is clicked
        jQuery('.mlsimport-import-checkbox').on('change', function() {
            updateFieldStats();
        });
    }
    

    /**
     * Update the field statistics displayed at the top
     */
    window.updateFieldStats = function() {
        const totalFields = $('.mlsimport-field-row').length;
        const mandatoryFields = $('.mlsimport-field-row[data-is-mandatory="true"]').length;
        const selectedFields = $('.mlsimport-import-checkbox:checked').length + mandatoryFields;
        
        // Count fields with empty labels that are selected for import
        let missingLabels = 0;
        
        // Count for non-mandatory checked fields with missing labels
        $('.mlsimport-import-checkbox:checked').each(function() {
            const row = $(this).closest('tr');
            const labelValue = row.find('.mlsimport-label-input').val();
            
            if (!labelValue || labelValue.trim() === '') {
                missingLabels++;
            }
        });
        
        // Count for mandatory fields with missing labels
        $('.mlsimport-field-row[data-is-mandatory="true"]').each(function() {
            const labelValue = $(this).find('.mlsimport-label-input').val();
            
            if (!labelValue || labelValue.trim() === '') {
                missingLabels++;
            }
        });
        
        // Update the stats display
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
                    
                    $helper.children().each(function(index) {
                        $(this).width($originals.eq(index).width());
                    });
                    
                    return $helper;
                },
                update: function(event, ui) {
                  

                    const $movedRow = jQuery(ui.item);
                    const $prevRow = $movedRow.prev('.mlsimport-field-row');

                    const movingOrder = parseInt($movedRow.attr('data-field-order'), 10);

                    // If no previous row exists, it's the first position
                    if (!$prevRow.length) {
                       
                        saveFieldPosition(movingOrder, 0, 'before');
                    } else {
                        // Normal case - moving after another row
                        const prevOrder = parseInt($prevRow.attr('data-field-order'), 10);
                        saveFieldPosition(movingOrder, prevOrder, 'after');
                    }

                    refreshRowPositions();
                }
                
            });
            
            // Add visual cue for draggable rows
            $('.mlsimport-field-row td').css('cursor', 'move');
          
        } else {
            console.error('jQuery UI sortable not available. Drag and drop ordering is disabled.');
        }
    }

  

  
})(jQuery);



function initVirtualScrolling() {
 
    
    // Store all rows
    let allRows = jQuery('.mlsimport-field-row').toArray();
  
    
    let rowsPerPage = 50;
    let visibleCount = rowsPerPage;
    let loading = false;
    
    // Make sure only initial rows are visible by hiding everything and then showing first 50
    jQuery('.mlsimport-field-row').hide();
    
    for (let i = 0; i < Math.min(rowsPerPage, allRows.length); i++) {
        jQuery(allRows[i]).show();
    }
    
    // Add loading indicator if there are more than 50 rows
    if (allRows.length > rowsPerPage) {
        jQuery('<div class="mlsimport-loading" style="text-align: center; padding: 20px; margin-top: 20px; background: #f0f0f0; border-top: 1px solid #ddd;">Scroll down to load more fields...</div>')
            .insertAfter('.mlsimport-fields-table');
        
        // Detect scroll
        jQuery(window).on('scroll', function() {
            if (loading) return;
            
            // Check if user has scrolled near the bottom
            let scrollPosition = jQuery(window).scrollTop() + jQuery(window).height();
            let documentHeight = jQuery(document).height();
            
            if (scrollPosition > documentHeight - 300) {
                loadMoreRows();
            }
        });
    }
    
    // Function to load more rows
    function loadMoreRows() {
        loading = true;
        jQuery('.mlsimport-loading').text('Loading more fields...').show();
        
        // Simulate loading delay for visual feedback
        setTimeout(function() {
            // Show next batch of rows
            let endIndex = Math.min(visibleCount + rowsPerPage, allRows.length);
            
            for (let i = visibleCount; i < endIndex; i++) {
                jQuery(allRows[i]).show();
            }
            
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
function initRowReordering() {
   
    
    // Attach click handlers to up buttons
    jQuery('.mlsimport-move-up').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Prevent event bubbling
        var $row = jQuery(this).closest('.mlsimport-field-row');
     
        moveRowUp($row);
    });

    // Attach click handlers to down buttons
    jQuery('.mlsimport-move-down').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Prevent event bubbling
        var $row = jQuery(this).closest('.mlsimport-field-row');
     
        moveRowDown($row);
    });

    // Attach click handlers to move top buttons
    jQuery('.mlsimport-move-top').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $row = jQuery(this).closest('.mlsimport-field-row');
      
        moveRowTop($row);
    });

    // Attach click handlers to move bottom buttons
    jQuery('.mlsimport-move-bottom').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $row = jQuery(this).closest('.mlsimport-field-row');
      
        moveRowBottom($row);
    });
}


let moveUpTimer = null;
let originalMovingOrder = null;
let movingFieldKey = null;

function moveRowUp($row) {
    const $prev = $row.prev('.mlsimport-field-row');
    if (!$prev.length) {
       
        return;
    }

    try {
        const $button = $row.find('.mlsimport-move-up');
        const oldTop = $button.offset().top;

        // Get the field key (unique identifier for the row)
        const fieldKey = $row.attr('data-field-key');
        
        // If this is a new field being moved (not continuation of previous moves)
        if (fieldKey !== movingFieldKey) {
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
        refreshRowPositions();
        highlightRow($row);

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
            
            if ($movedRow.length) {
                // Get the previous row
                const $prevRow = $movedRow.prev('.mlsimport-field-row');
                
                if ($prevRow.length) {
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




let moveDownTimer = null;
let originalDownOrder = null;
let movingDownFieldKey = null;

// Queue for saving field positions so requests don't overlap
let positionSaving = false;
let positionQueue = [];

function moveRowDown($row) {
    // Get the next row specifically with the same class
    var $next = $row.next('.mlsimport-field-row');
   
    
    if ($next.length) {
        try {
            // Save exact mouse position on screen
            var mouseY = window.event.clientY;
            var mouseX = window.event.clientX;
            var $button = $row.find('.mlsimport-move-down');
            var oldOffset = $button.offset();
            var oldTop = oldOffset.top;
            
            // Get the field key (unique identifier for the row)
            const fieldKey = $row.attr('data-field-key');
            
            // If this is a new field being moved (not continuation of previous moves)
            if (fieldKey !== movingDownFieldKey) {
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
                
                if ($movedRow.length) {
                    // Get the next row
                    const $nextRow = $movedRow.next('.mlsimport-field-row');
                    
                    if ($nextRow.length) {
                        let nextOrder = parseInt($nextRow.attr('data-field-order'), 10);
                        nextOrder=nextOrder-1;
                      
                        saveFieldPosition(originalDownOrder, nextOrder, 'after');
                    } else {
                        // Row is at the bottom
                 
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








function saveFieldPosition(movingOrder, targetOrder, position) {
    positionQueue.push({ movingOrder, targetOrder, position });
    processPositionQueue();
}

function processPositionQueue() {
    if (positionSaving || positionQueue.length === 0 || window.mlsimportSaving) {
        return;
    }

    positionSaving = true;
    window.mlsimportSaving = true;
    const item = positionQueue.shift();

    jQuery('.mlsimport-move-up, .mlsimport-move-down').prop('disabled', true);
    let nonce = '';
    if (jQuery('#mlsimport_field_selector_nonce').length > 0) {
        nonce = jQuery('#mlsimport_field_selector_nonce').val();
    } else if (typeof mlsimport_params !== 'undefined' && mlsimport_params.nonce) {
        nonce = mlsimport_params.nonce;
    }

    const $notification = jQuery('<div class="mlsimport-notification mlsimport-notification-info">Saving field position...</div>');
    jQuery('body').append($notification).fadeIn();

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
            if (response.success) {
                showNotification('Field position saved.', 'success');
            } else {
                showNotification('Error saving position: ' + (response.data || 'Unknown error'), 'error');
            }
        },
        error: function() {
            showNotification('Server error while saving position.', 'error');
        },
        complete: function() {
            jQuery('.mlsimport-move-up, .mlsimport-move-down').prop('disabled', false);
            $notification.remove();
            positionSaving = false;
            window.mlsimportSaving = false;
            processPositionQueue();
            if (typeof window.processSaveQueue === 'function') {
                window.processSaveQueue();
            }
        }
    });
}


// Highlight function
function highlightRow($row) {
    $row.css('background-color', '#ffffd0');
    setTimeout(function() {
        $row.css('background-color', '');
    }, 500);
}

// Refresh data-field-order attributes and debug positions
function refreshRowPositions() {
    jQuery('#mlsimport-fields-table-body .mlsimport-field-row').each(function(index) {
        jQuery(this).attr('data-field-order', index);
        jQuery(this).find('.field-position').text((index + 1) + '. ');
    });
}

// Move a row directly to the top
function moveRowTop($row) {
    const $first = jQuery('.mlsimport-field-row').first();
    if ($row.is($first)) return;

    const $button = $row.find('.mlsimport-move-top');
    const oldTop = $button.offset().top;
    const originalOrder = parseInt($row.attr('data-field-order'), 10);

    $row.insertBefore($first);
    refreshRowPositions();
    highlightRow($row);

    const newTop = $button.offset().top;
    window.scrollBy(0, newTop - oldTop);

    saveFieldPosition(originalOrder, 0, 'before');
}

// Move a row directly to the bottom
function moveRowBottom($row) {
    const $last = jQuery('.mlsimport-field-row').last();
    if ($row.is($last)) return;

    const $button = $row.find('.mlsimport-move-bottom');
    const oldTop = $button.offset().top;
    const originalOrder = parseInt($row.attr('data-field-order'), 10);
    const lastOrder = parseInt($last.attr('data-field-order'), 10);

    $row.insertAfter($last);
    refreshRowPositions();
    highlightRow($row);

    const newTop = $button.offset().top;
    window.scrollBy(0, newTop - oldTop);

    saveFieldPosition(originalOrder, lastOrder, 'after');
}


/**
 * Handles the sorting dropdown functionality
 */
function initializeFieldSorting() {
    // Add change event handler to the sorting dropdown
    jQuery('#mlsimport-field-sort').on('change', function() {
        const sortValue = jQuery(this).val();
        sortFields(sortValue);
    });
}

/**
 * Initialize tooltips for row action buttons using jQuery UI
 */
function initializeMoveButtonTooltips() {
    if (jQuery.fn.tooltip) {
        jQuery(document).tooltip({
            items: '.mlsimport-row-actions .mlsimport-move-btn',
            classes: { 'ui-tooltip': 'mlsimport-move-tooltip' }
        });
    } else {
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

    const $tbody = jQuery('#mlsimport-fields-table-body');
    let $rows = $tbody.find('tr.mlsimport-field-row').toArray();
    
    // Split sort value into criteria and direction
    const [criteria, direction] = sortBy.split('_');

    
    // Sort the rows based on the selected criteria
    $rows.sort(function(a, b) {
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

                labelA = labelA.toLowerCase();
                labelB = labelB.toLowerCase();

                if (labelA === '' && labelB !== '') return 1;
                if (labelA !== '' && labelB === '') return -1;
                if (labelA === '' && labelB === '') {
                    return $a.data('field-key').toLowerCase().localeCompare($b.data('field-key').toLowerCase());
                }

                result = labelA.localeCompare(labelB);
                break;

            case 'postmeta':
                let pmA = ($a.find('.mlsimport-postmeta-input').val() || '').toLowerCase();
                let pmB = ($b.find('.mlsimport-postmeta-input').val() || '').toLowerCase();
                result = pmA.localeCompare(pmB);
                break;

            case 'category':
                let catA = ($a.find('.mlsimport-field-taxonomy select option:selected').text() || '').toLowerCase();
                let catB = ($b.find('.mlsimport-field-taxonomy select option:selected').text() || '').toLowerCase();
                result = catA.localeCompare(catB);
                break;

            case 'import':
                let impA = $a.find('.mlsimport-import-checkbox').is(':checked');
                let impB = $b.find('.mlsimport-import-checkbox').is(':checked');
                if (impA === impB) {
                    result = 0;
                } else {
                    result = impA ? -1 : 1; // selected first
                }
                break;

            case 'hidden':
                let hidA = $a.find('.mlsimport-admin-checkbox').is(':checked');
                let hidB = $b.find('.mlsimport-admin-checkbox').is(':checked');
                if (hidA === hidB) {
                    result = 0;
                } else {
                    result = hidA ? -1 : 1; // selected first
                }
                break;

            default:
                const defaultNameA = $a.data('field-key').toLowerCase();
                const defaultNameB = $b.data('field-key').toLowerCase();
                result = defaultNameA.localeCompare(defaultNameB);
                break;
        }

        // Apply direction for ascending/descending sorts or selected/unselected
        if (criteria === 'import' || criteria === 'hidden') {
            if (direction === 'unselected') {
                result = -result;
            }
        } else if (direction === 'desc') {
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
    
    // Set message and type
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
    const fields = {};

    jQuery('.mlsimport-import-checkbox:not([disabled]):visible').each(function() {
        const $checkbox = jQuery(this);
        const $row = $checkbox.closest('.mlsimport-field-row');
        const fieldKey = $row.data('field-key');

        $checkbox.prop('checked', checked);
        fields[fieldKey] = checked ? 1 : 0;

        // Add saving indicator similar to progressive-save.js
        addBulkSavingIndicator($row, '.mlsimport-field-import');
    });

    if (Object.keys(fields).length === 0) {
        updateFieldStats();
        return;
    }

    jQuery.ajax({
        url: mlsimport_params.ajax_url,
        type: 'POST',
        data: {
            action: 'mlsimport_save_bulk_import',
            security: mlsimport_params.nonce,
            fields: fields
        },
        success: function(response) {
            const status = response.success ? 'success' : 'error';
            jQuery.each(fields, function(key) {
                const $row = jQuery('.mlsimport-field-row[data-field-key="' + key + '"]');
                updateBulkSavingIndicator($row, status, '.mlsimport-field-import');
            });
            updateFieldStats();
        },
        error: function() {
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
    const fields = {};

    jQuery('.mlsimport-admin-checkbox:visible').each(function() {
        const $checkbox = jQuery(this);
        const $row = $checkbox.closest('.mlsimport-field-row');
        const fieldKey = $row.data('field-key');

        $checkbox.prop('checked', checked);
        fields[fieldKey] = checked ? 1 : 0;

        addBulkSavingIndicator($row, '.mlsimport-field-admin');
    });

    if (Object.keys(fields).length === 0) {
        updateFieldStats();
        return;
    }

    jQuery.ajax({
        url: mlsimport_params.ajax_url,
        type: 'POST',
        data: {
            action: 'mlsimport_save_bulk_admin',
            security: mlsimport_params.nonce,
            fields: fields
        },
        success: function(response) {
            const status = response.success ? 'success' : 'error';
            jQuery.each(fields, function(key) {
                const $row = jQuery('.mlsimport-field-row[data-field-key="' + key + '"]');
                updateBulkSavingIndicator($row, status, '.mlsimport-field-admin');
            });
            updateFieldStats();
        },
        error: function() {
            jQuery.each(fields, function(key) {
                const $row = jQuery('.mlsimport-field-row[data-field-key="' + key + '"]');
                updateBulkSavingIndicator($row, 'error', '.mlsimport-field-admin');
            });
        }
    });
}

// Helper to add saving indicator for bulk updates
function addBulkSavingIndicator($row, columnSelector) {
    const $element = $row.find(columnSelector);
    $element.find('.save-indicator').remove();
    $element.append('<span class="save-indicator" style="margin-left: 5px; box-sizing: border-box; display: inline-block; width: 16px; height: 16px; border: 2px solid #635BFF; border-radius: 50%; border-top-color: transparent; animation: mlsimport-spin 1s linear infinite;"></span>');

    if (!jQuery('#mlsimport-spin-animation').length) {
        jQuery('head').append('<style id="mlsimport-spin-animation">@keyframes mlsimport-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>');
    }
}

// Helper to update indicator after bulk save
function updateBulkSavingIndicator($row, status, columnSelector) {
    const $element = $row.find(columnSelector);
    const $indicator = $element.find('.save-indicator');

    if (status === 'success') {
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
    
    progressContainer.append(progressText).append(progressBar);
    
    // Add progress container at the top of the table
    jQuery('.mlsimport-field-selector-container').prepend(progressContainer);
    
    // Variables for loading control
    let loadedCount = 0;
    let batchSize = 10; // How many rows to show at once
    let interval = 7; // Milliseconds between batches (adjust for speed)
    let isLoading = true;
    
    // Function to update progress display
    function updateProgress(count) {
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
        const loadingInterval = setInterval(function() {
            if (!isLoading) {
                clearInterval(loadingInterval);
                return;
            }
            
            // Load next batch of rows
            let endIndex = Math.min(loadedCount + batchSize, allRows.length);
            
            for (let i = loadedCount; i < endIndex; i++) {
                jQuery(allRows[i])
                    .css('opacity', 0)
                    .show()
                    .animate({opacity: 1}, 300);
            }
            
            loadedCount = endIndex;
            updateProgress(loadedCount);
            
            // Check if we're done
            if (loadedCount >= allRows.length) {
                isLoading = false;
                clearInterval(loadingInterval);
                console.log("All fields have been loaded");


                
            }
        }, interval);
    }, 500); // Small initial delay before starting
    
    // No control buttons needed - just let the loading progress automatically
}