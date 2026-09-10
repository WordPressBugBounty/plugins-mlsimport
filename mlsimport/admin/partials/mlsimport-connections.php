<?php
/**
 * Admin partial: the Connections tab (issue #280, decision #271).
 *
 * Renders, in the field_options "2025" style, from the data assembled by
 * mlsimport_connections_screen_data():
 *
 *   1. Account block — "Connected to mlsimport.com" summary bar (Manage /
 *      Disconnect) OR, signed out, the same block as an inline connect form.
 *      Nothing else on the page changes between the two states.
 *   2. Plan slot strip — one square per entitled slot, used slots filled;
 *      "+ Add MLS" (at cap: "Upgrade plan" linking to the portal).
 *   3. Connections table — one <tbody> per connection (so the row and its
 *      failure banner drag together): grip + priority badge, MLS name with
 *      provider/id, status pill + last-test time, activity counts, Edit/Test.
 *      A failing connection shows the amber banner row directly under it.
 *
 * All state changes go through mlsimport-connections-ajax.php; the drag /
 * test / connect behavior lives in admin/js/mlsimport-connections.js.
 * The sign-in feedback uses a block container to accommodate the shared
 * subscription notice's heading, paragraph and action button after AJAX.
 * A single text field accepts a username or email and posts the established
 * account identifier key, preserving existing account-save behavior.
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Everything the tab shows, in one assembled structure.
$mlsimport_conn_data    = mlsimport_connections_screen_data();
$mlsimport_conn_account = $mlsimport_conn_data['account'];
?>

<div class="mlsimport-connections" id="mlsimport-connections">

	<?php if ( $mlsimport_conn_account['connected'] ) : ?>
		<!-- 1a. Account block, signed in: summary bar. -->
		<div class="mlsimport-conn-account" id="mlsimport-conn-account-on">
			<span class="mlsimport-conn-account-icon is-ok">&#10003;</span>
			<span class="mlsimport-conn-account-who">
				<b><?php esc_html_e( 'Connected to mlsimport.com', 'mlsimport' ); ?></b>
				<span class="mlsimport-conn-muted">
					<?php echo esc_html( $mlsimport_conn_account['username'] ); ?>
					&middot;
					<?php
					printf(
						/* translators: 1: connections in use, 2: plan connection cap. */
						esc_html__( '%1$d of %2$d MLS connections', 'mlsimport' ),
						(int) $mlsimport_conn_data['used'],
						(int) $mlsimport_conn_data['cap']
					);
					?>
				</span>
			</span>
			<span class="mlsimport-conn-account-actions">
				<a class="button mlsimport_button secondary" href="https://mlsimport.com/my-account/" target="_blank"><?php esc_html_e( 'Manage account', 'mlsimport' ); ?></a>
				<button type="button" class="button mlsimport_button secondary" id="mlsimport-conn-disconnect"><?php esc_html_e( 'Disconnect', 'mlsimport' ); ?></button>
			</span>
		</div>
	<?php else : ?>
		<!-- 1b. Account block, signed out: the SAME block renders the inline
		     connect form (posts to the existing mlsimport_save_account action). -->
		<div class="mlsimport-conn-account is-signedout" id="mlsimport-conn-account-off">
			<span class="mlsimport-conn-account-icon is-warn">!</span>
			<div class="mlsimport-conn-signin">
				<b><?php esc_html_e( 'Connect to mlsimport.com', 'mlsimport' ); ?></b>
				<p class="mlsimport-conn-muted"><?php esc_html_e( 'Sign in with your mlsimport.com account to manage MLS connections.', 'mlsimport' ); ?></p>
				<div class="mlsimport-conn-signin-fields">
					<label>
						<?php esc_html_e( 'Username or email', 'mlsimport' ); ?>
						<input type="text" class="mlsimport-input mlsimport-2025-input" id="mlsimport-conn-username" autocomplete="off"
							value="<?php echo esc_attr( $mlsimport_conn_account['username'] ); ?>">
					</label>
					<label>
						<?php esc_html_e( 'Password', 'mlsimport' ); ?>
						<input type="password" class="mlsimport-input mlsimport-2025-input" id="mlsimport-conn-password" autocomplete="off">
					</label>
					<button type="button" class="button mlsimport_button" id="mlsimport-conn-connect"><?php esc_html_e( 'Connect', 'mlsimport' ); ?></button>
				</div>
				<div class="mlsimport-conn-signin-error" id="mlsimport-conn-signin-error" hidden></div>
				<a class="mlsimport-conn-signup-link" href="https://mlsimport.com/mls-import-plugin-pricing/" target="_blank"><?php esc_html_e( "Don't have an account? Sign up at mlsimport.com", 'mlsimport' ); ?></a>
			</div>
		</div>
	<?php endif; ?>

	<!-- 2. Plan slot strip: a square per entitled slot, used slots filled. -->
	<div class="mlsimport-conn-strip">
		<span class="mlsimport-conn-plan">
			<span class="mlsimport-conn-slots">
				<?php for ( $mlsimport_conn_slot = 1; $mlsimport_conn_slot <= $mlsimport_conn_data['cap']; $mlsimport_conn_slot++ ) : ?>
					<span class="mlsimport-conn-slot<?php echo $mlsimport_conn_slot <= $mlsimport_conn_data['used'] ? ' is-used' : ''; ?>"></span>
				<?php endfor; ?>
			</span>
			<span class="mlsimport-conn-muted">
				<?php
				printf(
					/* translators: 1: connections in use, 2: plan connection cap. */
					esc_html__( '%1$d of %2$d MLSs', 'mlsimport' ),
					(int) $mlsimport_conn_data['used'],
					(int) $mlsimport_conn_data['cap']
				);
				?>
			</span>
		</span>
		<?php if ( $mlsimport_conn_data['at_cap'] ) : ?>
			<a class="button mlsimport_button" id="mlsimport-conn-upgrade" href="https://mlsimport.com/mls-import-plugin-pricing/" target="_blank"><?php esc_html_e( 'Upgrade plan', 'mlsimport' ); ?></a>
		<?php else : ?>
			<!-- Below the cap: the button opens the Add-MLS drawer (#281). -->
			<button type="button" class="button mlsimport_button" id="mlsimport-conn-add"><?php esc_html_e( '+ Add MLS', 'mlsimport' ); ?></button>
		<?php endif; ?>
	</div>

	<?php if ( array() === $mlsimport_conn_data['rows'] ) : ?>
		<!-- Empty registry (no MLS yet): the getting-started steps live here —
		     the drawer above ("+ Add MLS") is the add path. -->
		<p class="mlsimport-conn-empty"><?php esc_html_e( 'No MLS connections yet. Use "+ Add MLS" above to connect your first MLS.', 'mlsimport' ); ?></p>
		<div class="mlsimport-steps">
			<ol>
				<li><?php esc_html_e( 'Connect your MLSImport account and your MLS.', 'mlsimport' ); ?></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=mlsimport_plugin_options&tab=field_options' ) ); ?>"><?php esc_html_e( 'Choose which listing details to show on your site.', 'mlsimport' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mlsimport_item' ) ); ?>"><?php esc_html_e( 'Start an import and bring in your listings.', 'mlsimport' ); ?></a></li>
			</ol>
		</div>
	<?php else : ?>
		<!-- 3. Connections table. One tbody per connection: the drag unit. -->
		<div class="mlsimport-conn-tblwrap">
			<table class="mlsimport-conn-tbl" id="mlsimport-connections-table">
				<thead>
					<tr>
						<th class="mlsimport-conn-col-grip"></th>
						<th><?php esc_html_e( 'Priority', 'mlsimport' ); ?></th>
						<th><?php esc_html_e( 'MLS', 'mlsimport' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mlsimport' ); ?></th>
						<th><?php esc_html_e( 'Activity', 'mlsimport' ); ?></th>
						<th class="mlsimport-conn-col-acts"></th>
					</tr>
				</thead>
				<?php foreach ( $mlsimport_conn_data['rows'] as $mlsimport_conn_row ) : ?>
					<?php
					$mlsimport_conn_status   = $mlsimport_conn_row['status_view'];
					$mlsimport_conn_activity = $mlsimport_conn_row['activity'];
					$mlsimport_conn_last_ts  = '' !== $mlsimport_conn_activity['last_import'] ? strtotime( $mlsimport_conn_activity['last_import'] ) : 0;
					?>
					<tbody class="mlsimport-conn-group<?php echo $mlsimport_conn_status['failing'] ? ' is-failing' : ''; ?>"
						data-mls-id="<?php echo (int) $mlsimport_conn_row['mls_id']; ?>">
						<tr class="mlsimport-conn-row">
							<td class="mlsimport-conn-grip" title="<?php esc_attr_e( 'Drag to change priority', 'mlsimport' ); ?>">&#10247;</td>
							<td><span class="mlsimport-conn-prio"><?php echo (int) $mlsimport_conn_row['priority']; ?></span></td>
							<td>
								<span class="mlsimport-conn-name">
									<?php
									// Name only: provider type and mls_id are internal
									// details and are not shown to the customer.
									echo esc_html( '' !== $mlsimport_conn_row['mls_name'] ? $mlsimport_conn_row['mls_name'] : __( 'MLS', 'mlsimport' ) . ' ' . (int) $mlsimport_conn_row['mls_id'] );
									?>
								</span>
							</td>
							<td class="mlsimport-conn-statcell">
								<span class="mlsimport-conn-pill is-<?php echo esc_attr( $mlsimport_conn_status['class'] ); ?>">
									<span class="mlsimport-conn-dot"></span><span class="mlsimport-conn-pill-label"><?php echo esc_html( mlsimport_connections_status_label( $mlsimport_conn_status['key'] ) ); ?></span>
								</span>
								<span class="mlsimport-conn-when">
									<?php
									if ( $mlsimport_conn_row['tested_at'] > 0 ) {
										/* translators: %s: human time difference since the last connection test. */
										printf( esc_html__( 'tested %s ago', 'mlsimport' ), esc_html( human_time_diff( $mlsimport_conn_row['tested_at'] ) ) );
									} else {
										esc_html_e( 'never tested', 'mlsimport' );
									}
									?>
								</span>
							</td>
							<td class="mlsimport-conn-counts">
								<b><?php echo esc_html( number_format_i18n( $mlsimport_conn_activity['tasks'] ) ); ?></b> <?php echo esc_html( _n( 'task', 'tasks', $mlsimport_conn_activity['tasks'], 'mlsimport' ) ); ?>
								&middot;
								<b><?php echo esc_html( number_format_i18n( $mlsimport_conn_activity['listings'] ) ); ?></b> <?php esc_html_e( 'listings', 'mlsimport' ); ?>
								<?php if ( $mlsimport_conn_last_ts > 0 ) : ?>
									&middot;
									<?php
									/* translators: %s: human time difference since the last import. */
									printf( esc_html__( 'imported %s ago', 'mlsimport' ), esc_html( human_time_diff( $mlsimport_conn_last_ts ) ) );
									?>
								<?php endif; ?>
							</td>
							<td class="mlsimport-conn-acts">
								<!-- Edit opens the drawer in edit mode (credentials prefilled) — the old credentials tab is retired. -->
								<a href="#" class="mlsimport-conn-edit" data-mls-id="<?php echo (int) $mlsimport_conn_row['mls_id']; ?>"><?php esc_html_e( 'Edit', 'mlsimport' ); ?></a>
								<a href="#" class="mlsimport-conn-test" data-mls-id="<?php echo (int) $mlsimport_conn_row['mls_id']; ?>"><?php esc_html_e( 'Test', 'mlsimport' ); ?></a>
								<!-- Remove deletes the connection (confirm first): registry record + its per-connection options; imported listings and tasks stay. -->
								<a href="#" class="mlsimport-conn-remove" data-mls-id="<?php echo (int) $mlsimport_conn_row['mls_id']; ?>"><?php esc_html_e( 'Remove', 'mlsimport' ); ?></a>
							</td>
						</tr>
						<!-- Amber failure banner: rendered for every row, hidden
						     unless failing, so a live test can toggle it. -->
						<tr class="mlsimport-conn-fixrow" <?php echo $mlsimport_conn_status['failing'] ? '' : 'hidden'; ?>>
							<td colspan="6">
								<div class="mlsimport-conn-fixbox">
									<span class="mlsimport-conn-fixtext">
										&#9888;
										<?php
										printf(
											/* translators: %s: MLS connection name. */
											esc_html__( 'Imports for %s are stopped — the connection is failing.', 'mlsimport' ),
											esc_html( '' !== $mlsimport_conn_row['mls_name'] ? $mlsimport_conn_row['mls_name'] : (string) $mlsimport_conn_row['mls_id'] )
										);
										?>
									</span>
									<button type="button" class="button mlsimport_button mlsimport-conn-edit" data-mls-id="<?php echo (int) $mlsimport_conn_row['mls_id']; ?>"><?php esc_html_e( 'Update credentials', 'mlsimport' ); ?></button>
								</div>
							</td>
						</tr>
					</tbody>
				<?php endforeach; ?>
			</table>
		</div>
		<p class="mlsimport-conn-note"><?php esc_html_e( 'Drag rows to set priority — when two MLSs carry the same property, the higher connection wins.', 'mlsimport' ); ?></p>
	<?php endif; ?>

	<?php
	// The slide-over drawer (#281 add / edit mode since the tab
	// consolidation) — always rendered: at the cap "+ Add MLS" is replaced by
	// "Upgrade plan" so adding stays unreachable, but a row's Edit must still
	// open the drawer (the server-side add handler enforces the cap anyway).
	require plugin_dir_path( __FILE__ ) . $this->plugin_name . '-connections-drawer.php';

	// Metadata autotrigger (moved here from the retired credentials tab):
	// when both the account and the current MLS connection are confirmed but
	// metadata was never gathered, fire the background gather now so the
	// Field Options tab is ready before the user opens it.
	global $mlsimport;
	echo mlsimport_metadata_autotrigger_markup(
		$mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient(),
		get_option( 'mlsimport_connection_test', '' ),
		mlsimport_get_connection_option( 'mlsimport_mls_metadata_populated', '' ),
		esc_attr( wp_create_nonce( 'mlsimport_saas_get_metadata' ) )
	);
	?>

</div>
