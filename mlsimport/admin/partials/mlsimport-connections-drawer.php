<?php
/**
 * Admin partial: the connection slide-over drawer (issue #281, decision #271;
 * edit mode added by the tab consolidation).
 *
 * Included by mlsimport-connections.php on every render: below the
 * entitlement cap "+ Add MLS" opens it in ADD mode; a row's Edit action opens
 * it in EDIT mode regardless of the cap (at the cap adding stays unreachable
 * — the button is replaced by "Upgrade plan" and the server-side add handler
 * enforces the cap independently).
 *
 * Structure (matching the locked #271 prototype):
 *   - a scrim + right slide-over panel, both hidden until opened,
 *   - a 3-segment step bar with a live "Step X of 3" note,
 *   - three step panes: 1 pick MLS (autocomplete over the SaaS MLS list;
 *     skipped in edit mode — the MLS identity is fixed), 2 provider
 *     credentials (inputs injected per provider by the JS; prefilled from the
 *     record in edit mode), 3 test & save (single action: a passed test
 *     stores the connection/credentials, a failed one keeps the drawer open
 *     with the error, nothing saved),
 *   - a fixed footer whose buttons the JS shows per step.
 *
 * All behavior lives in admin/js/mlsimport-connections-drawer.js; the server
 * flows in includes/mlsimport-connections-add.php (add) and
 * includes/mlsimport-connections-edit.php (edit).
 *
 * Expects $mlsimport_conn_data (the including partial's screen data) in
 * scope for the slot counter data attributes.
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>

<!-- Scrim behind the drawer; clicking it closes the drawer. -->
<div class="mlsimport-drawer-scrim" id="mlsimport-conn-drawer-scrim" hidden></div>

<div class="mlsimport-drawer" id="mlsimport-conn-drawer" hidden
	data-used="<?php echo (int) $mlsimport_conn_data['used']; ?>"
	data-cap="<?php echo (int) $mlsimport_conn_data['cap']; ?>">

	<!-- Header: title + close. The JS swaps the title per mode (Add / Edit). -->
	<div class="mlsimport-drawer-head">
		<h3 id="mlsimport-conn-drawer-title"><?php esc_html_e( 'Add MLS', 'mlsimport' ); ?></h3>
		<button type="button" class="mlsimport-drawer-close" id="mlsimport-conn-drawer-close" aria-label="<?php esc_attr_e( 'Close', 'mlsimport' ); ?>">&#10005;</button>
	</div>

	<div class="mlsimport-drawer-body">
		<!-- 3-segment step bar; the JS lights segments up to the current step. -->
		<div class="mlsimport-drawer-steps" aria-hidden="true">
			<span data-step="1"></span><span data-step="2"></span><span data-step="3"></span>
		</div>
		<p class="mlsimport-conn-muted" id="mlsimport-conn-drawer-stepnote"></p>

		<!-- Step 1: pick the MLS (jQuery UI autocomplete over the SaaS list). -->
		<div class="mlsimport-drawer-pane" id="mlsimport-drawer-step-pick">
			<label class="mlsimport-drawer-label" for="mlsimport-drawer-mls-search"><?php esc_html_e( 'Your MLS', 'mlsimport' ); ?></label>
			<input type="text" class="mlsimport-input mlsimport-2025-input" id="mlsimport-drawer-mls-search"
				autocomplete="off" placeholder="<?php esc_attr_e( 'search your MLS', 'mlsimport' ); ?>">
			<input type="hidden" id="mlsimport-drawer-mls-id" value="">
			<p class="mlsimport-conn-muted">
				<?php esc_html_e( 'MLSs already connected to this site are not listed.', 'mlsimport' ); ?>
				<a href="https://mlsimport.com/contact-us/" target="_blank"><?php esc_html_e( 'MLS not on the list? Contact us.', 'mlsimport' ); ?></a>
			</p>
		</div>

		<!-- Step 2: provider credentials (inputs injected by the JS from the
		     picked MLS's provider credential-field list). -->
		<div class="mlsimport-drawer-pane" id="mlsimport-drawer-step-creds" hidden>
			<label class="mlsimport-drawer-label"><?php esc_html_e( 'Your MLS', 'mlsimport' ); ?></label>
			<input type="text" class="mlsimport-input mlsimport-2025-input" id="mlsimport-drawer-mls-picked" disabled>
			<div id="mlsimport-drawer-fields"></div>
			<p class="mlsimport-conn-muted"><?php esc_html_e( 'Credential fields follow the provider — Bridge shows a token, Trestle shows client id + secret.', 'mlsimport' ); ?></p>
		</div>

		<!-- Step 3: one action tests the credentials and, on pass, saves the
		     connection. A failed test shows here and nothing is saved. -->
		<div class="mlsimport-drawer-pane" id="mlsimport-drawer-step-test" hidden>
			<p id="mlsimport-drawer-summary"></p>
			<div class="mlsimport-drawer-result" id="mlsimport-conn-drawer-result" hidden></div>
			<div class="mlsimport-conn-note" id="mlsimport-drawer-nextnote">
				<?php esc_html_e( 'Next: field mapping is pre-seeded from your theme defaults. The new connection joins the list at the lowest priority.', 'mlsimport' ); ?>
			</div>
		</div>
	</div>

	<!-- Footer: the JS shows the buttons that belong to the current step. -->
	<div class="mlsimport-drawer-foot">
		<button type="button" class="button mlsimport_button secondary" id="mlsimport-drawer-back" hidden><?php esc_html_e( 'Back', 'mlsimport' ); ?></button>
		<button type="button" class="button mlsimport_button" id="mlsimport-drawer-next" disabled><?php esc_html_e( 'Next', 'mlsimport' ); ?></button>
		<button type="button" class="button mlsimport_button" id="mlsimport-drawer-submit" hidden><?php esc_html_e( 'Test & save connection', 'mlsimport' ); ?></button>
		<button type="button" class="button mlsimport_button" id="mlsimport-drawer-done" hidden><?php esc_html_e( 'Done', 'mlsimport' ); ?></button>
		<button type="button" class="button mlsimport_button secondary" id="mlsimport-drawer-cancel"><?php esc_html_e( 'Cancel', 'mlsimport' ); ?></button>
	</div>

</div>
