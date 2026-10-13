<?php
/**
 * AI assistant access (MCP): the Tools tab card.
 *
 * Shown on MLSImport > Settings > Tools. It holds everything a site owner
 * does about AI apps, and nothing of it is available to the AI itself
 * (there is no tool for Connected AI Apps):
 *   - the on/off switch "AI assistant access" (off by default);
 *   - the MCP address to copy into ChatGPT or Claude;
 *   - why the feature is not running, when it is on but cannot run;
 *   - "Connected AI apps": app, who approved, last used, Disconnect;
 *   - "AI Activity": the last tool calls that changed something.
 *
 * The three actions (on, off, disconnect) are links to admin-post.php, each
 * protected by a nonce and by the manage_options capability. They are links,
 * not form fields, because the Tools tab is already one form.
 *
 * Public interface:
 *   mlsimport_mcp_admin_apply( $do, $grant_id )   perform one action
 *   mlsimport_mcp_admin_handle()                  hook: admin_post_mlsimport_ai_access
 *   mlsimport_mcp_admin_action_url( $do, $grant ) nonce'd link for one action
 *   mlsimport_mcp_render_tools_card()             print the card
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Perform one Tools tab action.
 *
 * @param string $do       'on', 'off' or 'disconnect'.
 * @param int    $grant_id Connected AI App row id (for 'disconnect').
 * @return void
 */
function mlsimport_mcp_admin_apply( string $do, int $grant_id = 0 ): void {
	if ( 'on' === $do ) {
		update_option( MLSIMPORT_MCP_ENABLED_OPTION, 'yes' );
	} elseif ( 'off' === $do ) {
		// The option hook removes every Connected AI App (mlsimport_mcp_switch_changed()).
		update_option( MLSIMPORT_MCP_ENABLED_OPTION, '' );
	} elseif ( 'disconnect' === $do && $grant_id > 0 ) {
		mlsimport_mcp_load_oauth();
		mlsimport_oauth_grant_delete( $grant_id );
	}
}

/**
 * Handle a click on one of the card's action links.
 *
 * Step by step: capability, nonce, perform, back to the Tools tab.
 *
 * @return void
 */
function mlsimport_mcp_admin_handle(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to change AI assistant access.', 'mlsimport' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'mlsimport_ai_access' );

	$do    = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
	$grant = isset( $_GET['grant'] ) ? absint( $_GET['grant'] ) : 0;
	mlsimport_mcp_admin_apply( $do, $grant );

	wp_safe_redirect( admin_url( 'admin.php?page=mlsimport_plugin_options&tab=administrative_options#mlsimport-ai-access' ) );
	exit;
}
add_action( 'admin_post_mlsimport_ai_access', 'mlsimport_mcp_admin_handle' );

/**
 * The nonce-protected link for one action.
 *
 * @param string $do    'on', 'off' or 'disconnect'.
 * @param int    $grant Connected AI App row id (for 'disconnect').
 * @return string
 */
function mlsimport_mcp_admin_action_url( string $do, int $grant = 0 ): string {
	$args = array(
		'action' => 'mlsimport_ai_access',
		'do'     => $do,
	);
	if ( $grant > 0 ) {
		$args['grant'] = $grant;
	}
	return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'mlsimport_ai_access' );
}

/**
 * Why AI assistant access is on but not running, in plain words.
 *
 * @param string $state The decision of this request (mlsimport_mcp_state()).
 * @return string '' when it is running.
 */
function mlsimport_mcp_admin_state_notice( string $state ): string {
	if ( 'needs_wordpress_69' === $state ) {
		return __( 'AI assistant access needs WordPress 6.9 or newer. Update WordPress to use it.', 'mlsimport' );
	}
	if ( 'version_mismatch' === $state ) {
		return __( 'Another plugin on this site loads a different version of the WordPress MCP Adapter than the one MLSImport was tested with, so AI assistant access stays off. Imports and sync are not affected.', 'mlsimport' );
	}
	return '';
}

/**
 * Print the Tools tab card.
 *
 * @return void
 */
function mlsimport_mcp_render_tools_card(): void {
	$enabled = mlsimport_mcp_enabled();
	$notice  = mlsimport_mcp_admin_state_notice( mlsimport_mcp_state() );
	$running = $enabled && '' === $notice;
	?>
<div id="mlsimport-ai-access" class="mlsimport_tool_block mlsimport_tool_card">
	<h3><?php esc_html_e( 'AI assistant access', 'mlsimport' ); ?></h3>
	<p><?php esc_html_e( 'Lets you set up and operate MLSImport by chatting in ChatGPT or Claude. You add this site there as a connector, log in to WordPress and click Allow. Only administrators can approve an AI app.', 'mlsimport' ); ?></p>

	<?php if ( ! $enabled ) : ?>
		<p><strong><?php esc_html_e( 'AI assistant access is off.', 'mlsimport' ); ?></strong></p>
		<a class="button mlsimport_button" href="<?php echo esc_url( mlsimport_mcp_admin_action_url( 'on' ) ); ?>"><?php esc_html_e( 'Turn on AI assistant access', 'mlsimport' ); ?></a>
	<?php else : ?>
		<?php if ( '' !== $notice ) : ?>
			<div class="mlsimport_warning" role="alert"><?php echo esc_html( $notice ); ?></div>
		<?php endif; ?>
		<?php if ( $running ) : ?>
			<label class="mlsimport-label" for="mlsimport-ai-access-address"><?php esc_html_e( 'Address to add in ChatGPT or Claude', 'mlsimport' ); ?></label>
			<input id="mlsimport-ai-access-address" class="large-text code mlsimport-2025-input" type="text" readonly onclick="this.select();" value="<?php echo esc_attr( mlsimport_mcp_endpoint_url() ); ?>" />
			<?php if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) : ?>
				<div class="mlsimport_warning" role="alert"><?php esc_html_e( 'ChatGPT and Claude can only connect to a public site with HTTPS. This site does not use HTTPS.', 'mlsimport' ); ?></div>
			<?php endif; ?>
		<?php endif; ?>
		<p>
			<a class="button mlsimport_button" href="<?php echo esc_url( mlsimport_mcp_admin_action_url( 'off' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Turn off AI assistant access? Every connected AI app is removed and has to be approved again later.', 'mlsimport' ) ); ?>');"><?php esc_html_e( 'Turn off AI assistant access', 'mlsimport' ); ?></a>
		</p>
		<?php
		if ( $running ) {
			mlsimport_mcp_render_connected_apps();
			mlsimport_mcp_render_ai_activity();
		}
		?>
	<?php endif; ?>
</div>
	<?php
}

/**
 * Print the "Connected AI apps" list.
 *
 * An app whose approving user is gone or no longer an administrator is shown
 * as "no longer valid": it cannot act and is never handed to another user.
 *
 * @return void
 */
function mlsimport_mcp_render_connected_apps(): void {
	mlsimport_mcp_load_oauth();
	$apps = mlsimport_oauth_grants();
	?>
	<h4><?php esc_html_e( 'Connected AI apps', 'mlsimport' ); ?></h4>
	<?php if ( empty( $apps ) ) : ?>
		<p><?php esc_html_e( 'No AI app is connected yet.', 'mlsimport' ); ?></p>
		<?php
		return;
	endif;
	?>
	<table class="widefat striped mlsimport-ai-apps">
		<thead>
			<tr>
				<th><?php esc_html_e( 'App', 'mlsimport' ); ?></th>
				<th><?php esc_html_e( 'Approved by', 'mlsimport' ); ?></th>
				<th><?php esc_html_e( 'Last used', 'mlsimport' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $apps as $app ) : ?>
			<?php
			$user    = get_userdata( (int) $app['user_id'] );
			$problem = mlsimport_oauth_grant_problem( (int) $app['last_used'], user_can( (int) $app['user_id'], 'manage_options' ), time() );
			?>
			<tr>
				<td>
					<?php echo esc_html( $app['client_name'] ); ?>
					<br><small><?php echo esc_html( $app['redirect_domain'] ); ?></small>
				</td>
				<td><?php echo esc_html( $user ? $user->display_name : __( '(deleted user)', 'mlsimport' ) ); ?></td>
				<td>
					<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $app['last_used'] ) ); ?>
					<?php if ( '' !== $problem ) : ?>
						<br><strong><?php echo esc_html( 'expired' === $problem ? __( 'Expired', 'mlsimport' ) : __( 'No longer valid', 'mlsimport' ) ); ?></strong>
					<?php endif; ?>
				</td>
				<td><a class="button" href="<?php echo esc_url( mlsimport_mcp_admin_action_url( 'disconnect', (int) $app['id'] ) ); ?>"><?php esc_html_e( 'Disconnect', 'mlsimport' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Print the AI Activity list (the newest 20 of the 100 kept).
 *
 * @return void
 */
function mlsimport_mcp_render_ai_activity(): void {
	$entries = array_slice( mlsimport_ai_activity_entries(), 0, 20 );
	?>
	<h4><?php esc_html_e( 'AI Activity', 'mlsimport' ); ?></h4>
	<?php if ( empty( $entries ) ) : ?>
		<p><?php esc_html_e( 'No AI app has changed anything yet.', 'mlsimport' ); ?></p>
		<?php
		return;
	endif;
	?>
	<table class="widefat striped mlsimport-ai-activity">
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'mlsimport' ); ?></th>
				<th><?php esc_html_e( 'Action', 'mlsimport' ); ?></th>
				<th><?php esc_html_e( 'Administrator', 'mlsimport' ); ?></th>
				<th><?php esc_html_e( 'App', 'mlsimport' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $entries as $entry ) : ?>
			<?php $user = get_userdata( (int) $entry['user_id'] ); ?>
			<tr>
				<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
				<td><?php echo esc_html( $entry['tool'] ); ?></td>
				<td><?php echo esc_html( $user ? $user->display_name : __( '(deleted user)', 'mlsimport' ) ); ?></td>
				<td><?php echo esc_html( '' !== $entry['app'] ? $entry['app'] : __( '(direct)', 'mlsimport' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
