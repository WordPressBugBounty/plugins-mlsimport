/**
 * Field Configuration browser controller.
 *
 * One controller owns the list view and one ordered mutation queue. Every save
 * posts four fixed variables (action, nonce, revision, JSON command) — plus the
 * scoped mls_id when the tab is bound to one connection (multi-MLS) — applies
 * the authoritative server result, and advances the revision before the next
 * command starts. Network interruptions receive three automatic retries. Any
 * persistent or rejected save pauses later work and leaves the administrator's
 * visible edits intact until Retry or Reload is chosen.
 *
 * No state is published on window and no unload warning is registered.
 */
(function ($) {
    'use strict';

    /**
     * Start one isolated controller for every rendered configuration list.
     *
     * Settings and onboarding may render the same component on different pages.
     * Each container receives private revision and queue state, with nothing
     * shared through window globals.
     */
    $(function () {
        $('.mlsimport-field-selector-container').each(function () {
            createController($(this));
        });

        // Per-connection scope selector (multi-MLS): switching MLS reloads the
        // tab with ?mls=<id>, so the whole page — partial, hidden scope input,
        // controller — re-renders bound to the picked connection.
        $(document).on('change', '#mlsimport-field-mls-scope', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('mls', this.value);
            window.location.href = url.toString();
        });
    });

    /**
     * The connection this rendered tab is scoped to (0 = legacy/current).
     *
     * The partial resolves the scope server-side and exposes it in the hidden
     * #mlsimport_field_scope input; every mutation posts it back so the save
     * can never land on a different connection than the one rendered.
     *
     * @return {string} The scoped mls_id, or '' when unscoped.
     */
    function scopedMlsId() {
        var value = $('#mlsimport_field_scope').val();
        return value && parseInt(value, 10) > 0 ? value : '';
    }

    /**
     * Create the UI state, event bindings, sortable behavior, and save queue.
     *
     * @param {jQuery} $container One rendered Field Configuration interface.
     */
    function createController($container) {
        var state = {
            revision: parseInt($container.attr('data-revision'), 10) || 0,
            queue: [],
            saving: false,
            paused: false,
            retryTimer: null
        };
        var $body = $container.find('#mlsimport-fields-table-body');
        var $status = $container.find('.mlsimport-field-save-status');
        var $search = $container.find('#mlsimport-field-search');
        var $filter = $container.find('#mlsimport-import-filter');
        var $sort = $container.find('#mlsimport-field-sort');

        $filter.val($container.attr('data-initial-filter') || 'all');

        /**
         * Return all current-metadata rows, including locally hidden rows.
         *
         * Search and filtering toggle visibility without removing elements, so
         * this collection remains the complete active ordering boundary.
         *
         * @return {jQuery} Active field row collection.
         */
        function rows() {
            return $body.children('.mlsimport-field-row');
        }

        /**
         * Find one row without interpolating an MLS key into a CSS selector.
         *
         * Comparing data values handles punctuation safely and avoids escaping
         * provider-specific field names before selector construction.
         *
         * @param {string} fieldKey RESO field identifier.
         * @return {jQuery} Matching row, or an empty collection.
         */
        function rowFor(fieldKey) {
            return rows().filter(function () {
                return String($(this).attr('data-field-key')) === String(fieldKey);
            }).first();
        }

        /**
         * Recalculate active and selected counts from the current controls.
         *
         * Controls may contain authoritative saved values or newer queued edits;
         * counts intentionally describe what the administrator currently sees.
         */
        function updateStats() {
            var total = rows().length;
            var selected = rows().find('.mlsimport-import-checkbox:checked').length;
            $container.find('.mlsimport-total-count').text(total + ' fields total');
            $container.find('.mlsimport-selected-count').text(selected + ' marked for import');
        }

        /**
         * Apply search/filter/sort locally and enable ordering only in the full
         * custom-order view, where every active row and position is visible.
         */
        function applyView() {
            var search = $.trim($search.val() || '').toLowerCase();
            var filter = $filter.val() || 'all';
            var sort = $sort.val() || 'custom_order';
            var $rows = rows();

            $rows.each(function () {
                var $row = $(this);
                // Match the MLS field key, never the whole row text: every row
                // contains a taxonomy dropdown whose option labels (e.g.
                // "Property City") would otherwise match terms like "city".
                var fieldKey = String($row.attr('data-field-key') || '').toLowerCase();
                var matchesSearch = !search || fieldKey.indexOf(search) !== -1;
                var checked = $row.find('.mlsimport-import-checkbox').prop('checked');
                var matchesFilter = filter === 'all' || (filter === 'selected' && checked) || (filter === 'not_selected' && !checked);
                $row.toggle(matchesSearch && matchesFilter);
            });

            var sorted = $rows.get().sort(function (left, right) {
                var $left = $(left);
                var $right = $(right);
                var leftValue;
                var rightValue;

                if (sort === 'custom_order') {
                    return (parseInt($left.attr('data-field-order'), 10) || 0) - (parseInt($right.attr('data-field-order'), 10) || 0);
                }
                if (sort.indexOf('label_') === 0) {
                    leftValue = $left.find('.mlsimport-label-input').val() || '';
                    rightValue = $right.find('.mlsimport-label-input').val() || '';
                } else if (sort.indexOf('postmeta_') === 0) {
                    leftValue = $left.find('.mlsimport-postmeta-input').val() || '';
                    rightValue = $right.find('.mlsimport-postmeta-input').val() || '';
                } else if (sort.indexOf('category_') === 0) {
                    leftValue = $left.find('.mlsimport-taxonomy-select').val() || '';
                    rightValue = $right.find('.mlsimport-taxonomy-select').val() || '';
                } else {
                    leftValue = $left.attr('data-field-key') || '';
                    rightValue = $right.attr('data-field-key') || '';
                }

                leftValue = String(leftValue).toLowerCase();
                rightValue = String(rightValue).toLowerCase();
                return leftValue.localeCompare(rightValue) * (sort.slice(-5) === '_desc' ? -1 : 1);
            });
            // Only touch the DOM when the display order really changed.
            // Re-appending every row detaches the control that has focus (the
            // checkbox just clicked), and the browser then jumps the page to
            // the top — at the end of a long list that loses the user's place.
            var orderChanged = sorted.some(function (row, index) {
                return row !== $rows[index];
            });
            if (orderChanged) {
                $.each(sorted, function (_, row) {
                    $body.append(row);
                });
            }

            // Reordering (buttons and drag-and-drop) stays available in every
            // view: moves are relative commands anchored to the nearest visible
            // row, which is well-defined within the full stored order no matter
            // how the list is currently searched, filtered, or sorted.
        }

        /**
         * Render the paused/error state and the correct recovery action.
         *
         * Routine saving/saved feedback is shown per-control by the inline save
         * indicators; this status line only carries failures. Stale revisions
         * require reload because another writer owns newer state. Other
         * failures keep the head command queued and expose Retry.
         *
         * @param {string} kind Visual state: error.
         * @param {string} message Administrator-facing status text.
         * @param {string} errorCode Stable module failure code.
         */
        function showStatus(kind, message, errorCode) {
            $status.removeClass('is-saving is-saved is-error').empty().addClass('is-' + kind).text(message || '');
            if (kind !== 'error') {
                return;
            }

            var $button = $('<button type="button" class="button mlsimport-field-save-recovery"></button>');
            if (errorCode === 'stale_revision') {
                $button.text(mlsimport_params.messages.reload).on('click', function () {
                    window.location.reload();
                });
            } else {
                $button.text(mlsimport_params.messages.retry).on('click', function () {
                    state.paused = false;
                    processQueue();
                });
            }
            $status.append(' ').append($button);
        }

        /**
         * Map one queued command to the row cells that carry its save
         * indicator: the edited column for set/bulk commands and the
         * field-name cell for move commands.
         *
         * @param {Object} command Compact set, bulk, or move command.
         * @return {jQuery} Cells to annotate.
         */
        function indicatorCells(command) {
            var cellByProperty = {
                'import': '.mlsimport-field-import',
                admin: '.mlsimport-field-admin',
                label: '.mlsimport-field-label',
                postmeta: '.mlsimport-field-postmeta',
                taxonomy: '.mlsimport-field-taxonomy'
            };
            var selector = command.type === 'move' ? '.mlsimport-field-name' : cellByProperty[command.property];
            var fieldKeys = command.type === 'bulk' ? command.fields || [] : [command.field];
            var $cells = $();
            $.each(fieldKeys, function (_, fieldKey) {
                $cells = $cells.add(rowFor(fieldKey).find(selector));
            });
            return $cells;
        }

        /**
         * Show a spinning save indicator inside each given cell, replacing any
         * indicator left over from an earlier save of the same control.
         *
         * @param {jQuery} $cells Cells returned by indicatorCells().
         */
        function addSaveIndicator($cells) {
            $cells.each(function () {
                $(this).find('.mlsimport-save-indicator').remove();
                $(this).append('<span class="mlsimport-save-indicator is-spinning"></span>');
            });
        }

        /**
         * Swap each cell's spinner for a fading success tick or an error cross.
         *
         * @param {jQuery} $cells Annotated cells.
         * @param {boolean} success Whether the save was accepted.
         */
        function resolveSaveIndicators($cells, success) {
            var $indicators = $cells.find('.mlsimport-save-indicator');
            $indicators.removeClass('is-spinning');
            if (success) {
                $indicators.addClass('is-success').html('✓');
                window.setTimeout(function () {
                    $indicators.fadeOut(500, function () {
                        $(this).remove();
                    });
                }, 1000);
                return;
            }
            $indicators.addClass('is-error').html('✕');
        }

        /**
         * Add one administrator intent to the tail of the ordered queue.
         *
         * A fresh network-attempt count belongs to each command. Processing can
         * start immediately, but never overtakes a currently saving head item.
         *
         * @param {Object} command Compact set, bulk, or move command.
         */
        function enqueue(command) {
            state.queue.push({ command: command, networkAttempts: 0 });
            processQueue();
        }

        /**
         * Send only the head command. Later changes wait for its authoritative
         * result, ensuring each subsequent request uses the returned revision.
         */
        function processQueue() {
            if (state.saving || state.paused || state.queue.length === 0) {
                return;
            }

            state.saving = true;
            $status.removeClass('is-saving is-saved is-error').empty();
            var item = state.queue[0];
            var $indicated = indicatorCells(item.command);
            addSaveIndicator($indicated);
            var request = {
                action: mlsimport_params.action,
                security: mlsimport_params.nonce,
                revision: state.revision,
                command: JSON.stringify(item.command)
            };
            // Scoped tab (multi-MLS): pin the save to the rendered connection.
            if (scopedMlsId()) {
                request.mls_id = scopedMlsId();
            }
            $.ajax({
                url: mlsimport_params.ajax_url,
                type: 'POST',
                dataType: 'json',
                timeout: 20000,
                data: request
            }).done(function (response) {
                if (!response || !response.success || !response.data || !response.data.success) {
                    resolveSaveIndicators($indicated, false);
                    pauseQueue(response && response.data ? response.data : null, 'The server rejected this Field Configuration change.');
                    return;
                }

                state.queue.shift();
                state.revision = parseInt(response.data.revision, 10) || state.revision;
                $container.attr('data-revision', state.revision);
                applyResult(response.data);
                restoreQueuedView();
                state.saving = false;
                resolveSaveIndicators($indicated, true);
                processQueue();
            }).fail(function (xhr, textStatus) {
                var isNetworkFailure = xhr.status === 0 || textStatus === 'timeout';
                if (isNetworkFailure && item.networkAttempts < 3) {
                    item.networkAttempts += 1;
                    state.saving = false;
                    state.retryTimer = window.setTimeout(processQueue, item.networkAttempts * 500);
                    return;
                }

                var payload = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
                resolveSaveIndicators($indicated, false);
                pauseQueue(payload, isNetworkFailure ? 'The network did not recover. Your change is still unsaved.' : 'Field Configuration could not be saved.');
            });
        }

        /**
         * Pause later changes while preserving the current optimistic controls.
         *
         * The failed head remains in the queue. Only stale results advance the
         * locally known revision, and their recovery action is Reload rather than
         * retrying a command based on superseded state.
         *
         * @param {Object|null} payload Failed Field Configuration Result.
         * @param {string} fallbackMessage Message used for malformed responses.
         */
        function pauseQueue(payload, fallbackMessage) {
            var error = payload && payload.error ? payload.error : {};
            state.saving = false;
            state.paused = true;
            if (payload && payload.revision !== undefined && error.code === 'stale_revision') {
                state.revision = parseInt(payload.revision, 10) || state.revision;
            }
            showStatus('error', error.message || fallbackMessage, error.code || 'persistence_failed');
        }

        /**
         * Apply cleaned fields and computed order returned by the PHP module.
         *
         * This replaces values from the command that just completed. A separate
         * overlay immediately reapplies later queued intent so an earlier server
         * response cannot make unsaved work disappear from the controls.
         *
         * @param {Object} result Successful authoritative result.
         */
        function applyResult(result) {
            $.each(result.fields || {}, function (fieldKey, field) {
                var $row = rowFor(fieldKey);
                $row.find('.mlsimport-import-checkbox').prop('checked', Number(field.import) === 1);
                $row.find('.mlsimport-admin-checkbox').prop('checked', Number(field.admin) === 1);
                $row.find('.mlsimport-label-input').val(field.label || '');
                $row.find('.mlsimport-postmeta-input').val(field.postmeta || '');
                $row.find('.mlsimport-taxonomy-select').val(field.taxonomy || '');
            });
            // The authoritative order stamps data-field-order directly, so the
            // stored order survives even while a sorted view owns the display.
            // Rows are not moved here: applyView() below sorts by these stamps
            // and only moves rows when the displayed order actually changes.
            $.each(result.order || [], function (index, fieldKey) {
                rowFor(fieldKey).attr('data-field-order', index).find('.field-position').text((index + 1) + '. ');
            });
            updateStats();
            applyView();
        }

        /**
         * Overlay one pending command on the authoritative DOM without saving.
         *
         * Set and bulk commands update their controls, including mutually
         * exclusive mappings. Move commands recreate the pending relative order.
         * This function never enqueues, sends, or changes the revision.
         *
         * @param {Object} command Pending compact command.
         */
        function applyPendingCommand(command) {
            var $targets;
            var $row;
            var $anchor;

            if (command.type === 'move') {
                $row = rowFor(command.field);
                $anchor = rowFor(command.anchor);
                if ($row.length && $anchor.length && !$row.is($anchor)) {
                    command.position === 'before' ? $row.insertBefore($anchor) : $row.insertAfter($anchor);
                }
                return;
            }

            $targets = command.type === 'bulk' ? command.fields || [] : [command.field];
            $.each($targets, function (_, fieldKey) {
                $row = rowFor(fieldKey);
                if (command.property === 'import') {
                    $row.find('.mlsimport-import-checkbox').prop('checked', Number(command.value) === 1);
                } else if (command.property === 'admin') {
                    $row.find('.mlsimport-admin-checkbox').prop('checked', Number(command.value) === 1);
                } else if (command.property === 'label') {
                    $row.find('.mlsimport-label-input').val(command.value || '');
                } else if (command.property === 'postmeta') {
                    $row.find('.mlsimport-postmeta-input').val(command.value || '');
                    if ($.trim(command.value || '') !== '') {
                        $row.find('.mlsimport-taxonomy-select').val('');
                    }
                } else if (command.property === 'taxonomy') {
                    $row.find('.mlsimport-taxonomy-select').val(command.value || '');
                    if ((command.value || '') !== '') {
                        $row.find('.mlsimport-postmeta-input').val('');
                    }
                }
            });
        }

        /**
         * Restore all later unsaved intent after an authoritative result lands.
         *
         * Commands are overlaid in their original queue order so the last edit to
         * a control remains visible. Positions, counts, and filters are refreshed
         * once after the complete overlay rather than once per pending command.
         */
        function restoreQueuedView() {
            $.each(state.queue, function (_, item) {
                applyPendingCommand(item.command);
            });
            refreshPositions();
            updateStats();
            applyView();
        }

        /**
         * Re-index the optimistic DOM after a permitted drag or relative move.
         *
         * These positions are presentation state until the server returns its
         * authoritative order; no complete order array is placed on the wire.
         *
         * data-field-order must always mirror the STORED custom order, so only
         * the custom-order view — where DOM order IS the stored order — may
         * re-derive it from DOM positions. In a sorted view the DOM is display
         * order; stamping it would corrupt the stored order client-side (the
         * "Custom Order" option would then replay the sorted order until a
         * reload). There the authoritative result re-stamps it instead.
         */
        function refreshPositions() {
            if (($sort.val() || 'custom_order') !== 'custom_order') {
                return;
            }
            rows().each(function (index) {
                $(this).attr('data-field-order', index).find('.field-position').text((index + 1) + '. ');
            });
        }

        /**
         * Translate one changed control into a compact one-field command.
         *
         * Mapping exclusivity is reflected optimistically before enqueueing. The
         * authoritative response later replaces normalized text and destinations.
         */
        $container.on('change', '.mlsimport-import-checkbox, .mlsimport-admin-checkbox, .mlsimport-label-input, .mlsimport-postmeta-input, .mlsimport-taxonomy-select', function () {
            var $control = $(this);
            var $row = $control.closest('.mlsimport-field-row');
            var property;
            var value;

            if ($control.hasClass('mlsimport-import-checkbox')) {
                property = 'import';
                value = $control.prop('checked') ? 1 : 0;
            } else if ($control.hasClass('mlsimport-admin-checkbox')) {
                property = 'admin';
                value = $control.prop('checked') ? 1 : 0;
            } else if ($control.hasClass('mlsimport-label-input')) {
                property = 'label';
                value = $control.val();
            } else if ($control.hasClass('mlsimport-postmeta-input')) {
                property = 'postmeta';
                value = $control.val();
                if ($.trim(value) !== '') {
                    $row.find('.mlsimport-taxonomy-select').val('');
                }
            } else {
                property = 'taxonomy';
                value = $control.val();
                if (value !== '') {
                    $row.find('.mlsimport-postmeta-input').val('');
                }
            }

            enqueue({ type: 'set', field: $row.attr('data-field-key'), property: property, value: value });
            updateStats();
            applyView();
        });

        /**
         * Change and send only active rows currently visible to the administrator.
         *
         * Sending explicit field keys makes the server-side bulk transition
         * independent of search text, filters, pagination, or POST variable count.
         */
        $container.on('click', '#mlsimport-select-all-import, #mlsimport-select-none-import, #mlsimport-select-all-admin, #mlsimport-select-none-admin', function () {
            var id = this.id;
            var property = id.indexOf('admin') !== -1 ? 'admin' : 'import';
            var value = id.indexOf('none') === -1 ? 1 : 0;
            var selector = property === 'admin' ? '.mlsimport-admin-checkbox' : '.mlsimport-import-checkbox';
            var $visibleRows = rows().filter(':visible');
            var fieldKeys = $visibleRows.map(function () { return $(this).attr('data-field-key'); }).get();

            if (fieldKeys.length === 0) {
                return;
            }
            $visibleRows.find(selector).prop('checked', value === 1);
            enqueue({ type: 'bulk', property: property, fields: fieldKeys, value: value });
            updateStats();
            applyView();
        });

        /**
         * Convert move buttons into the same relative command as drag-and-drop.
         *
         * Anchors are the nearest VISIBLE rows so that, in a filtered view, one
         * click jumps past hidden neighbors instead of swapping with them
         * invisibly. The DOM moves immediately, then the queue sends only the
         * moving field, anchor field, and before/after position.
         */
        $container.on('click', '.mlsimport-move-btn', function () {
            var $row = $(this).closest('.mlsimport-field-row');
            var $anchor;
            var position;

            if ($(this).hasClass('mlsimport-move-up')) {
                $anchor = $row.prevAll('.mlsimport-field-row:visible').first();
                position = 'before';
            } else if ($(this).hasClass('mlsimport-move-down')) {
                $anchor = $row.nextAll('.mlsimport-field-row:visible').first();
                position = 'after';
            } else if ($(this).hasClass('mlsimport-move-top')) {
                $anchor = rows().filter(':visible').first();
                position = 'before';
            } else {
                $anchor = rows().filter(':visible').last();
                position = 'after';
            }

            if (!$anchor.length || $anchor.is($row)) {
                return;
            }
            // Keep the clicked button under the cursor: measure its position
            // before the move, then scroll by its displacement so repeated
            // clicks walk the row without chasing the button down the page.
            var buttonTop = $(this).offset().top;
            position === 'before' ? $row.insertBefore($anchor) : $row.insertAfter($anchor);
            refreshPositions();
            window.scrollBy(0, $(this).offset().top - buttonTop);
            enqueue({ type: 'move', field: $row.attr('data-field-key'), anchor: $anchor.attr('data-field-key'), position: position });
        });

        /**
         * Derive one moving/anchor command from a completed sortable interaction.
         *
         * Reordering is enabled only for the unfiltered custom-order view, so the
         * adjacent anchor always belongs to the complete active configuration.
         */
        if ($.fn.sortable) {
            $body.sortable({
                items: '> .mlsimport-field-row',
                handle: '.mlsimport-field-name',
                update: function (_, ui) {
                    var $row = ui.item;
                    var $anchor = $row.prev('.mlsimport-field-row');
                    var position = 'after';
                    if (!$anchor.length) {
                        $anchor = $row.next('.mlsimport-field-row');
                        position = 'before';
                    }
                    refreshPositions();
                    if ($anchor.length) {
                        enqueue({ type: 'move', field: $row.attr('data-field-key'), anchor: $anchor.attr('data-field-key'), position: position });
                    }
                }
            });
        }

        $search.on('input', applyView);
        $filter.add($sort).on('change', applyView);
        updateStats();
        applyView();
    }
})(jQuery);
