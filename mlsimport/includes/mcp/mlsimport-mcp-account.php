<?php
/**
 * AI tools (MCP): the MLSImport Account.
 *
 * Each function here is the body of one AI tool. A tool is a thin wrapper:
 * it reads its input, calls the plugin function that already does the work,
 * and returns that function's result. No login logic lives in this file; the
 * login itself is mlsimport_account_login() (includes/mlsimport-account-status.php),
 * the same function the setup wizard and the Connections screen use.
 *
 * Tools never return the password or the SaaS token.
 *
 * Public interface:
 *   mlsimport_mcp_account_tools()   the tools of this file
 *   mlsimport_mcp_account_is_working()        does the site have a working MLSImport Account
 *   mlsimport_mcp_account_connect( $input )   tool: account-connect
 *   mlsimport_mcp_account_status()            tool: account-status
 *   mlsimport_mcp_refresh_entitlements()      tool: refresh-entitlements
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file (the MLSImport Account), as the tool list expects them.
 *
 * Each entry: label, description, input, required, callback, changes. See
 * mlsimport_mcp_ability_definitions() in mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_account_tools(): array {
	$text    = array( 'type' => 'string' );

	return array(
		'account-status' => array(
			'label'       => 'MLSImport account status',
			'description' => 'Reads whether the site is connected to its mlsimport.com account, why not, and how many MLS connections the plan allows. Call this first.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_account_status',
			'changes'     => false,
		),
		'account-connect' => array(
			'label'       => 'Connect the MLSImport account',
			'description' => 'Connects the site to the mlsimport.com account with the username (or email) and password the client gives. Works when no account is saved yet, after account-disconnect, or to sign the saved account in again (for example with a corrected password). A different account than the saved one is refused; that change is made in wp-admin. The password is never returned.',
			'input'       => array(
				'username' => $text,
				'password' => $text,
			),
			'required'    => array( 'username', 'password' ),
			'callback'    => 'mlsimport_mcp_account_connect',
			'changes'     => true,
		),
		'account-disconnect' => array(
			'label'       => 'Disconnect the MLSImport account',
			'description' => 'Disconnects the site from its mlsimport.com account, as the Disconnect button does: the password and the session are forgotten, the username is kept, and the hourly sync stops until the account is signed in again with account-connect. Needs two calls: the first call changes nothing and returns what would happen and a confirmation_code; tell the client, and only after they confirm call again with that code.',
			'input'       => array(
				'confirmation_code' => array(
					'type'        => 'string',
					'description' => 'The confirmation_code returned by the first call. Leave out on the first call.',
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_account_disconnect',
			'changes'     => true,
			'destructive' => true,
		),
		'refresh-entitlements' => array(
			'label'       => 'Re-read the plan from mlsimport.com',
			'description' => 'Asks mlsimport.com again what the account\'s plan allows (how many MLS connections, which MLSs) and updates the site. Use it after the client upgraded the plan.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_refresh_entitlements',
			'changes'     => true,
		),
	);
}

/**
 * Whether the site has a working MLSImport Account.
 *
 * Working means: a username is saved AND the SaaS gives a token for the saved
 * login (a warm cached token counts; a cold cache asks the SaaS with the SAVED
 * login, never with a new one). A site that was never connected, or whose
 * last login failed, has no working account.
 *
 * @return bool
 */
function mlsimport_mcp_account_is_working(): bool {
	// Step 1: no saved username means the account was never connected.
	$options = get_option( 'mlsimport_admin_options', array() );
	if ( empty( $options['mlsimport_username'] ) ) {
		return false;
	}

	// Step 2: the saved login works when it still yields a token.
	global $mlsimport;
	$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();

	return '' !== trim( (string) $token );
}

/**
 * Tool account-connect: save the mlsimport.com login and connect the site.
 *
 * One rule: a saved login is only ever replaced by a login for the SAME
 * username (signing in again after account-disconnect, or with a corrected
 * password). A different account is refused: the login function saves the
 * new login before testing it, so a mistyped login for another account would
 * disconnect a working site and stop the hourly sync. Replacing the account
 * is done in wp-admin.
 *
 * @param array $input {
 *     @type string $username MLSImport.com username or email.
 *     @type string $password MLSImport.com password.
 * }
 * @return array|WP_Error Outcome of the login (connected, message,
 *                        account_status), or the refusal.
 */
function mlsimport_mcp_account_connect( array $input ) {
	// Step 1: read the input the same way the wizard reads its form: the
	// username is text-sanitized, the password is kept verbatim (trimmed only).
	$username = isset( $input['username'] ) ? sanitize_text_field( (string) $input['username'] ) : '';
	$password = isset( $input['password'] ) ? trim( (string) $input['password'] ) : '';

	// Step 2: refuse another account while a login is saved; change nothing.
	$options = get_option( 'mlsimport_admin_options', array() );
	$saved   = (string) ( $options['mlsimport_username'] ?? '' );
	if ( '' !== $saved && 0 !== strcasecmp( $saved, $username ) ) {
		return new WP_Error(
			'mlsimport_account_already_connected',
			sprintf(
				/* translators: %s: the saved MLSImport.com username or email. */
				__( 'This site already has the MLSImport account "%s" saved. Sign that account in again here, or change to a different account in wp-admin: MLSImport > Settings > Connections.', 'mlsimport' ),
				$saved
			)
		);
	}

	// Step 3: the plugin's single login function saves and tests the login.
	$login = mlsimport_account_login( $username, $password );

	// Step 4: return the outcome. The password and token are never included.
	return array(
		'connected'      => $login['connected'],
		'message'        => $login['message'],
		'account_status' => $login['account_status'],
	);
}

/**
 * Tool account-disconnect: forget the account's password and session (two
 * calls).
 *
 * Step by step:
 * 1. Without a saved login there is nothing to disconnect.
 * 2. First call: say what would happen and give a confirmation code.
 * 3. Confirmed: the Connections screen's own disconnect, then the status.
 *
 * @param array $input confirmation_code.
 * @return array|WP_Error The preview, "disconnected", or the refusal.
 */
function mlsimport_mcp_account_disconnect( array $input ) {
	$options = get_option( 'mlsimport_admin_options', array() );
	if ( empty( $options['mlsimport_username'] ) ) {
		return new WP_Error( 'mlsimport_account_not_connected', __( 'No MLSImport account is saved on this site; there is nothing to disconnect.', 'mlsimport' ) );
	}
	$pending = mlsimport_mcp_confirm_or_preview(
		'account-disconnect',
		$input,
		sprintf(
			/* translators: %s: the saved MLSImport.com username or email. */
			__( 'The site is disconnected from the MLSImport account "%s": its password and session are forgotten and the hourly sync stops until the account is signed in again with account-connect. MLS connections, tasks and listings stay.', 'mlsimport' ),
			$options['mlsimport_username']
		)
	);
	if ( null !== $pending ) {
		$pending['disconnected'] = false;
		$pending['would_do']     = $pending['would_delete'];
		unset( $pending['deleted'], $pending['would_delete'] );
		return $pending;
	}
	mlsimport_account_disconnect();
	return array(
		'disconnected'   => true,
		'account_status' => mlsimport_mcp_account_status(),
	);
}

/**
 * Tool account-status: is the MLSImport Account connected, why not, and what
 * the plan allows.
 *
 * Reads only. The reason comes from mlsimport_account_status() and the plan's
 * connection cap from mlsimport_entitlement_cap().
 *
 * @return array {
 *     @type bool   $connected        Whether the site has a working MLSImport Account.
 *     @type string $username         The saved username or email ('' when none).
 *     @type string $reason           'no_subscription' | 'invalid_credentials' | ''.
 *     @type string $message          Why not connected, in the plugin's own words ('' when connected).
 *     @type int    $connection_cap   MLS Connections the plan allows.
 *     @type int    $connections_used MLS Connections the site has.
 * }
 */
function mlsimport_mcp_account_status(): array {
	$options   = get_option( 'mlsimport_admin_options', array() );
	$connected = mlsimport_mcp_account_is_working();

	return array(
		'connected'        => $connected,
		'username'         => (string) ( $options['mlsimport_username'] ?? '' ),
		'reason'           => $connected ? '' : mlsimport_account_status(),
		'message'          => $connected ? '' : mlsimport_account_not_connected_message(),
		'connection_cap'   => mlsimport_entitlement_cap(),
		'connections_used' => count( Mlsimport_Connections::all() ),
	);
}

/**
 * Tool refresh-entitlements: re-read what the plan allows from mlsimport.com.
 *
 * Wraps mlsimport_refresh_entitlements(), the call the Connections screen
 * makes on every render. A failed or old-style answer changes nothing.
 *
 * @return array|WP_Error The account status after the refresh, or the refusal
 *                        when no account is connected.
 */
function mlsimport_mcp_refresh_entitlements() {
	// Step 1: without a working account there is nothing to ask.
	if ( ! mlsimport_mcp_account_is_working() ) {
		return new WP_Error(
			'mlsimport_account_not_connected',
			__( 'No MLSImport account is connected. Connect one with account-connect first.', 'mlsimport' )
		);
	}

	// Step 2: the plugin's own refresh, then the status as it is now.
	mlsimport_refresh_entitlements();
	return mlsimport_mcp_account_status();
}
