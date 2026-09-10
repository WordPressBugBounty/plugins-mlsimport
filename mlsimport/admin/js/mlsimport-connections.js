/**
 * Connections tab behavior (issue #280).
 *
 * Three interactions, all against the handlers in
 * includes/mlsimport-connections-ajax.php (localized config in
 * window.mlsimportConnections: ajaxUrl, nonce, accountNonce, i18n):
 *
 * 1. Drag-to-reorder: each connection is one <tbody class="mlsimport-conn-group">
 *    (row + its banner row move as one unit). jQuery UI sortable on the table;
 *    on drop the priority badges renumber 1..N and the id order is POSTed to
 *    mlsimport_connections_reorder. A rejected save restores the page order.
 * 2. Per-row Test: POSTs mlsimport_connections_test and repaints that row's
 *    status pill, last-test time and failure banner from the response.
 * 2b. Per-row Remove: confirms, POSTs mlsimport_connections_remove and
 *    reloads (the server drops the record + its per-connection options and
 *    promotes the next-priority connection when the current one was removed).
 * 3. Account block: the inline connect form POSTs to the existing
 *    mlsimport_save_account action (onboarding module) and reloads on
 *    success; Disconnect confirms, POSTs mlsimport_connections_disconnect and
 *    reloads (the server forgets the password + token, so the reload renders
 *    the signed-out block).
 *    All login failures reuse the server's escaped notice HTML so headings,
 *    explanations and any plans button match the onboarding screen.
 */
(function ($) {
	'use strict';

	$(function () {
		var config = window.mlsimportConnections || {};
		var $table = $('#mlsimport-connections-table');

		/* ------------------------------------------------------------------
		 * 1. Drag-to-reorder (tbody per connection = the drag unit).
		 * ---------------------------------------------------------------- */

		// Renumber the visible priority badges 1..N in current DOM order.
		function renumber() {
			$table.find('tbody.mlsimport-conn-group').each(function (index) {
				$(this).find('.mlsimport-conn-prio').text(index + 1);
			});
		}

		if ($table.length && $.fn.sortable) {
			$table.sortable({
				items: 'tbody.mlsimport-conn-group',
				handle: '.mlsimport-conn-grip',
				axis: 'y',
				update: function () {
					// Collect the new id order and persist it.
					var order = $table
						.find('tbody.mlsimport-conn-group')
						.map(function () {
							return $(this).data('mls-id');
						})
						.get();
					renumber();
					$.post(config.ajaxUrl, {
						action: 'mlsimport_connections_reorder',
						security: config.nonce,
						order: order
					}).done(function (response) {
						// A refused order (stale page) is undone visually.
						if (!response || !response.success) {
							$table.sortable('cancel');
							renumber();
						}
					});
				}
			});
		}

		/* ------------------------------------------------------------------
		 * 2. Per-row connection test.
		 * ---------------------------------------------------------------- */

		$table.on('click', '.mlsimport-conn-test', function (event) {
			event.preventDefault();
			var $link = $(this);
			var $group = $link.closest('tbody.mlsimport-conn-group');

			// One test at a time per row; show progress in the action link.
			if ($link.data('busy')) {
				return;
			}
			$link.data('busy', true).text(config.i18n.testing);

			$.post(config.ajaxUrl, {
				action: 'mlsimport_connections_test',
				security: config.nonce,
				mls_id: $group.data('mls-id')
			})
				.done(function (response) {
					if (!response || !response.success) {
						return;
					}
					var data = response.data;
					// Repaint the pill, the last-test time and the banner.
					$group
						.find('.mlsimport-conn-pill')
						.removeClass('is-ok is-bad is-warn')
						.addClass('is-' + data.status.class);
					$group.find('.mlsimport-conn-pill-label').text(data.label);
					$group.find('.mlsimport-conn-when').text(data.tested);
					$group.toggleClass('is-failing', data.status.failing);
					$group.find('.mlsimport-conn-fixrow').prop('hidden', !data.status.failing);
				})
				.always(function () {
					$link.data('busy', false).text(config.i18n.test);
				});
		});

		/* ------------------------------------------------------------------
		 * 2b. Per-row remove (confirm -> AJAX -> reload; the reload renders
		 *     the shrunk table, slot strip, and promoted current connection).
		 * ---------------------------------------------------------------- */

		$table.on('click', '.mlsimport-conn-remove', function (event) {
			event.preventDefault();
			var $link = $(this);
			var $group = $link.closest('tbody.mlsimport-conn-group');

			if ($link.data('busy') || !window.confirm(config.i18n.removeConfirm)) {
				return;
			}
			$link.data('busy', true);

			$.post(config.ajaxUrl, {
				action: 'mlsimport_connections_remove',
				security: config.nonce,
				mls_id: $group.data('mls-id')
			})
				.done(function (response) {
					if (response && response.success) {
						window.location.reload();
						return;
					}
					$link.data('busy', false);
				})
				.fail(function () {
					$link.data('busy', false);
				});
		});

		/* ------------------------------------------------------------------
		 * 3. Account block: inline connect form + disconnect.
		 * ---------------------------------------------------------------- */

		$('#mlsimport-conn-connect').on('click', function () {
			var $button = $(this);
			var $error = $('#mlsimport-conn-signin-error');

			$button.prop('disabled', true);
			$error.prop('hidden', true);
			$.post(config.ajaxUrl, {
				action: 'mlsimport_save_account',
				security: config.accountNonce,
				mlsimport_username: $('#mlsimport-conn-username').val(),
				mlsimport_password: $('#mlsimport-conn-password').val()
			})
				.done(function (response) {
					// The account handler reports connected true/false in data.
					if (response && response.success && response.data && response.data.connected) {
						window.location.reload();
						return;
					}
					// The handler names the reason (wrong password vs. account
					// without a subscription); the fixed string is only the
					// fallback for a reply that carries no message.
					var reason = response && response.data && response.data.message
						? response.data.message
						: config.i18n.connectFailed;
					$error.empty().text(reason);

					// The shared PHP builder escapes every value in this notice.
					// Keep all login verdicts equally visible: render the complete
					// notice for credentials errors as well as subscription errors.
					if (response && response.data && response.data.html) {
						$error.html(response.data.html);
					}

					$error.prop('hidden', false);
					$button.prop('disabled', false);
				})
				.fail(function () {
					$error.text(config.i18n.connectFailed).prop('hidden', false);
					$button.prop('disabled', false);
				});
		});

		$('#mlsimport-conn-disconnect').on('click', function () {
			if (!window.confirm(config.i18n.disconnectConfirm)) {
				return;
			}
			$.post(config.ajaxUrl, {
				action: 'mlsimport_connections_disconnect',
				security: config.nonce
			}).always(function () {
				window.location.reload();
			});
		});
	});
})(jQuery);
