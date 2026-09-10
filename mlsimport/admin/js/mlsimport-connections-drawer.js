/**
 * Connection drawer behavior (issue #281 add flow; edit mode added by the
 * tab consolidation that retired the old "MLS Connection" credentials tab).
 *
 * Drives the right slide-over drawer rendered by
 * admin/partials/mlsimport-connections-drawer.php in two modes:
 *
 * ADD ("+ Add MLS", below the entitlement cap; posts mlsimport_connections_add):
 * 1. Open/close: "+ Add MLS" opens; ✕ / Cancel / the scrim close and reset.
 * 2. Step 1 — pick MLS: jQuery UI autocomplete over the SaaS MLS list
 *    (window.mlsimportConnections.mlsList), minus MLSs already registered
 *    and the "not on this list" opt-out entry. Picking an entry stores the
 *    mls id and enables Next; editing the text clears the pick.
 * 3. Step 2 — credentials: inputs are injected from the picked MLS's
 *    provider credential-field list (window.mlsimport_vars.provider_families,
 *    localized by the core admin script — final flat field names resolved by
 *    PHP's Provider Family module). Labels come from the field's GENERIC
 *    credential name, derived with the same longest-suffix-first rule as
 *    mlsimport_multimls_generic_credential_name() (PHP) — keep both in sync.
 * 4. Step 3 — test & save: ONE action POSTs the picked MLS + credentials;
 *    the server tests them against the live MLS and only a passed test saves
 *    the connection. Failure paints the error and keeps the drawer open
 *    (nothing saved). Success is painted the moment the server says "saved",
 *    Done appears, and ONLY THEN a second, separate request seeds the field
 *    mapping (#325: seeding used to run inside the save request, so a host
 *    time limit after the save made a real connection look like a failure).
 *    The seed's outcome settles the wording; it can never undo the save.
 * 5. After a save, EVERY way out of the drawer (Done, ✕, Cancel, the scrim)
 *    reloads, so the new row, slot fill and seeded mapping always show.
 *
 * EDIT (a row's Edit / the failure banner's Update credentials; posts
 * mlsimport_connections_edit): same drawer, opened directly at step 2 with
 * the MLS identity FIXED (step 1 is skipped — editing never changes which
 * MLS a connection is) and the credential inputs prefilled from the
 * connection's stored values (config.connections, localized server-side).
 * Step 3's single action tests and, only on a pass, stores the new
 * credentials.
 */
(function ($) {
	'use strict';

	$(function () {
		var config = window.mlsimportConnections || {};
		var $drawer = $('#mlsimport-conn-drawer');

		// No drawer markup means we are not on the Connections tab.
		if (!$drawer.length) {
			return;
		}

		var $scrim = $('#mlsimport-conn-drawer-scrim');
		var $search = $('#mlsimport-drawer-mls-search');
		var $mlsId = $('#mlsimport-drawer-mls-id');
		var $fields = $('#mlsimport-drawer-fields');
		var $result = $('#mlsimport-conn-drawer-result');
		var panes = {
			1: $('#mlsimport-drawer-step-pick'),
			2: $('#mlsimport-drawer-step-creds'),
			3: $('#mlsimport-drawer-step-test')
		};
		var step = 1;
		// 'add' (full 3-step flow) or 'edit' (MLS fixed, opens at step 2).
		var mode = 'add';
		// True once this open saved something: closing must then reload so
		// the Connections table shows the real state (#325).
		var saved = false;
		// Stored per-connection state for edit prefill: { mlsId: {name, creds} }.
		var connections = config.connections || {};
		// The add-mode "what happens next" note as rendered by the markup —
		// kept so edit mode can swap its own text in and add can restore it.
		var addNextNote = $('#mlsimport-drawer-nextnote').text();

		// Provider credential fields per MLS id, resolved server-side by the
		// Provider Family module and localized by the core admin script.
		var families = (window.mlsimport_vars && window.mlsimport_vars.provider_families) || {};
		var byMlsId = families.by_mls_id || {};

		/* ------------------------------------------------------------------
		 * Helpers.
		 * ---------------------------------------------------------------- */

		// Mirror of PHP mlsimport_multimls_generic_credential_name(): longest
		// suffixes first so 'client_secret' never half-matches as 'secret'.
		var GENERIC_SUFFIXES = ['client_secret', 'client_id', 'mls_token', 'username', 'password'];
		function genericName(field) {
			for (var i = 0; i < GENERIC_SUFFIXES.length; i++) {
				if (field.slice(-GENERIC_SUFFIXES[i].length) === GENERIC_SUFFIXES[i]) {
					return GENERIC_SUFFIXES[i];
				}
			}
			return '';
		}

		// sprintf-lite for the localized '%1$s'-style templates.
		function format(template) {
			var args = arguments;
			return String(template).replace(/%(\d+)\$s/g, function (match, index) {
				return typeof args[index] !== 'undefined' ? args[index] : match;
			});
		}

		// Registered ids and the "not on this list" opt-out never appear in
		// the picker.
		function pickableList() {
			var registered = (config.registered || []).map(String);
			return (config.mlsList || []).filter(function (entry) {
				var id = parseInt(entry.value, 10);
				return id > 0 && registered.indexOf(String(id)) === -1;
			});
		}

		/* ------------------------------------------------------------------
		 * Step navigation.
		 * ---------------------------------------------------------------- */

		function goToStep(next) {
			step = next;
			$.each(panes, function (number, $pane) {
				$pane.prop('hidden', parseInt(number, 10) !== step);
			});
			$drawer.find('.mlsimport-drawer-steps span').each(function () {
				$(this).toggleClass('is-on', parseInt($(this).data('step'), 10) <= step);
			});
			// Edit mode has no slot counter — its note is just step + name.
			$('#mlsimport-conn-drawer-stepnote').text(
				'edit' === mode
					? format(config.i18n.drawerStepNoteEdit, step, config.i18n.drawerStepNames[step - 1])
					: format(
						config.i18n.drawerStepNote,
						step,
						config.i18n.drawerStepNames[step - 1],
						$drawer.data('used') + 1,
						$drawer.data('cap')
					)
			);
			// Footer buttons that belong to the current step. Edit mode starts
			// at step 2 (the MLS identity is fixed), so Back never reaches 1.
			$('#mlsimport-drawer-back').prop('hidden', step <= ('edit' === mode ? 2 : 1));
			$('#mlsimport-drawer-next').prop('hidden', 3 === step).prop('disabled', !stepReady());
			$('#mlsimport-drawer-submit').prop('hidden', 3 !== step).prop('disabled', false);
			$('#mlsimport-drawer-done').prop('hidden', true);
			$('#mlsimport-drawer-cancel').prop('hidden', false);
			if (3 === step) {
				// Summary shows the MLS name only; provider type and id are
				// internal details and stay out of the customer-facing UI.
				$('#mlsimport-drawer-summary').text(
					format(config.i18n.drawerSummary, $search.val())
				);
				// The "what happens next" note differs per mode: the markup
				// carries the add-mode text (new connection joins the list);
				// edit mode swaps in its own line.
				$('#mlsimport-drawer-nextnote').text(
					'edit' === mode ? config.i18n.drawerNextNoteEdit : addNextNote
				);
				$result.prop('hidden', true);
			}
		}

		// Whether the current step's inputs allow moving forward.
		function stepReady() {
			if (1 === step) {
				return parseInt($mlsId.val(), 10) > 0;
			}
			if (2 === step) {
				var ready = $fields.find('input').length > 0;
				$fields.find('input').each(function () {
					if ('' === $.trim($(this).val())) {
						ready = false;
					}
				});
				return ready;
			}
			return true;
		}

		function refreshNext() {
			$('#mlsimport-drawer-next').prop('disabled', !stepReady());
		}

		/* ------------------------------------------------------------------
		 * Open / close.
		 * ---------------------------------------------------------------- */

		function setOpen(open) {
			$drawer.prop('hidden', !open);
			$scrim.prop('hidden', !open);
			if (open) {
				// Every open starts a fresh flow.
				saved = false;
				$search.val('');
				$mlsId.val('');
				$fields.empty();
				$result.prop('hidden', true).removeClass('is-ok is-bad');
				$('#mlsimport-conn-drawer-title').text(
					'edit' === mode ? config.i18n.drawerEditTitle : config.i18n.drawerAddTitle
				);
				goToStep('edit' === mode ? 2 : 1);
			}
		}

		$('#mlsimport-conn-add').on('click', function () {
			mode = 'add';
			setOpen(true);
		});

		// Edit mode: a row's Edit link or the failure banner's Update
		// credentials button. The MLS is fixed; the drawer opens at step 2
		// with the stored credential values prefilled.
		$(document).on('click', '.mlsimport-conn-edit', function (event) {
			event.preventDefault();
			var editId = String($(this).data('mls-id'));
			var stored = connections[editId] || { name: '', creds: {} };
			mode = 'edit';
			setOpen(true);
			$mlsId.val(editId);
			$search.val(stored.name);
			$('#mlsimport-drawer-mls-picked').val(stored.name);
			injectFields(editId);
			$fields.find('input').each(function () {
				$(this).val(stored.creds[$(this).data('field')] || '');
			});
			refreshNext();
		});
		// Leaving the drawer: a plain close before anything was saved; a reload
		// after a save, so the user never sees a stale table (#325).
		function leave() {
			if (saved) {
				window.location.reload();
				return;
			}
			setOpen(false);
		}
		$('#mlsimport-conn-drawer-close, #mlsimport-drawer-cancel').on('click', leave);
		$scrim.on('click', leave);

		/* ------------------------------------------------------------------
		 * Step 1: MLS autocomplete.
		 * ---------------------------------------------------------------- */

		if ($.fn.autocomplete) {
			$search.autocomplete({
				minLength: 1,
				appendTo: $drawer,
				source: function (request, respond) {
					var term = request.term.toLowerCase();
					respond(pickableList().filter(function (entry) {
						return String(entry.label).toLowerCase().indexOf(term) !== -1;
					}));
				},
				select: function (event, ui) {
					$search.val(ui.item.label);
					$mlsId.val(ui.item.value);
					refreshNext();
					return false;
				}
			});
		}

		// Editing the text invalidates the previous pick.
		$search.on('input', function () {
			$mlsId.val('');
			refreshNext();
		});

		/* ------------------------------------------------------------------
		 * Step 2: provider credential fields.
		 * ---------------------------------------------------------------- */

		function injectFields(mlsId) {
			var family = byMlsId[String(mlsId)] || { credential_fields: [] };
			$fields.empty();
			// An MLS with no resolvable credential fields would dead-end the
			// step (Next needs at least one filled input) — say so instead.
			if (!(family.credential_fields || []).length) {
				$('<p>', { 'class': 'mlsimport-conn-muted', text: config.i18n.drawerNoFields }).appendTo($fields);
				return;
			}
			$.each(family.credential_fields || [], function (index, field) {
				var generic = genericName(field);
				var secret = 'password' === generic || 'client_secret' === generic;
				$('<label>', { 'class': 'mlsimport-drawer-label', text: config.credLabels[generic] || field })
					.appendTo($fields);
				// autocomplete='off' is set via attr(): in a jQuery props
				// object the key would be CALLED as the jQuery UI
				// .autocomplete() plugin method instead of set as an attribute.
				$('<input>', {
					'class': 'mlsimport-input mlsimport-2025-input',
					type: secret ? 'password' : 'text',
					'data-field': field
				}).attr('autocomplete', 'off').appendTo($fields);
			});
		}

		$fields.on('input', 'input', refreshNext);

		/* ------------------------------------------------------------------
		 * Footer navigation.
		 * ---------------------------------------------------------------- */

		$('#mlsimport-drawer-next').on('click', function () {
			if (!stepReady()) {
				return;
			}
			if (1 === step) {
				$('#mlsimport-drawer-mls-picked').val($search.val());
				injectFields($mlsId.val());
			}
			goToStep(step + 1);
		});

		$('#mlsimport-drawer-back').on('click', function () {
			goToStep(step - 1);
		});

		/* ------------------------------------------------------------------
		 * Step 3: test & save (one action; nothing saved on failure).
		 * ---------------------------------------------------------------- */

		$('#mlsimport-drawer-submit').on('click', function () {
			var $submit = $(this);
			var creds = {};
			$fields.find('input').each(function () {
				creds[$(this).data('field')] = $(this).val();
			});

			$submit.prop('disabled', true);
			$('#mlsimport-drawer-back').prop('disabled', true);
			$result.removeClass('is-ok is-bad').text(config.i18n.testing).prop('hidden', false);

			$.post(config.ajaxUrl, {
				// Edit posts the credentials-update action; add registers.
				action: 'edit' === mode ? 'mlsimport_connections_edit' : 'mlsimport_connections_add',
				security: config.nonce,
				mls_id: $mlsId.val(),
				mls_name: $search.val(),
				creds: creds
			})
				.done(function (response) {
					if (response && response.success) {
						// Saved: green result, only Done (reload) remains.
						saved = true;
						$result.addClass('is-ok').text(
							'edit' === mode ? config.i18n.drawerEditSaved : config.i18n.drawerSeeding
						);
						$('#mlsimport-drawer-submit, #mlsimport-drawer-back, #mlsimport-drawer-cancel').prop('hidden', true);
						$('#mlsimport-drawer-done').prop('hidden', false);
						if ('add' === mode) {
							seedMapping($mlsId.val());
						}
						return;
					}
					// Failed test: show the error, keep the drawer open.
					var message = (response && response.data && response.data.message) || config.i18n.drawerFailed;
					$result.addClass('is-bad').text(message);
					$submit.prop('disabled', false);
					$('#mlsimport-drawer-back').prop('disabled', false);
				})
				.fail(function () {
					$result.addClass('is-bad').text(config.i18n.drawerFailed);
					$submit.prop('disabled', false);
					$('#mlsimport-drawer-back').prop('disabled', false);
				});
		});

		// The separate seed request after a saved add (#325). The connection
		// already exists whatever happens here: a seeded mapping settles the
		// wording to "saved"; a failed seed — including a request that never
		// answered — is said out loud so the user knows to open Field Options,
		// which retries the gather on its own.
		function seedMapping(mlsId) {
			$.post(config.ajaxUrl, {
				action: 'mlsimport_connections_seed',
				security: config.nonce,
				mls_id: mlsId
			})
				.done(function (response) {
					var seeded = response && response.success && response.data && true === response.data.seeded;
					$result.text(seeded ? config.i18n.drawerSaved : config.i18n.drawerSavedNoSeed);
				})
				.fail(function () {
					$result.text(config.i18n.drawerSavedNoSeed);
				});
		}

		$('#mlsimport-drawer-done').on('click', function () {
			window.location.reload();
		});
	});
})(jQuery);
