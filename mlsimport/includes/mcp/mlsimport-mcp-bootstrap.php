<?php
/**
 * AI assistant access (MCP): the on/off switch, loading the MCP Adapter, and
 * the one MCP server.
 *
 * WHAT THIS FILE DOES
 * -------------------
 * It is the only file of the feature that mlsimport.php loads on every
 * request. It decides whether anything else of the feature loads:
 *   1. The Tools tab switch "AI assistant access" is off by default. Off
 *      means nothing loads and the MCP address does not exist.
 *   2. WordPress must be 6.9 or newer (the Abilities API).
 *   3. The official WordPress MCP Adapter is bundled as source under
 *      vendor/wordpress/ and loaded with the small loader of
 *      mlsimport-mcp-adapter-copy.php. Composer's
 *      generated autoloader is never used at runtime.
 *   4. PHP cannot load two copies of the adapter. When another plugin loaded
 *      it first, theirs is used only when it is the minor version we tested;
 *      any other version, or one we cannot read, keeps the feature off (the
 *      Tools tab says why). A mismatched copy must never fatal a live site.
 *
 * Sign-in: ChatGPT and Claude web connect with "log in and Allow" (OAuth).
 * The plugin is its own sign-in provider (docs/adr/0019); that code lives in
 * includes/mcp/oauth/ and is loaded from here. Turning the switch off
 * removes every Connected AI App, so turning it on again starts clean.
 *
 * When the feature loads it creates ONE server at /wp-json/mlsimport/mcp,
 * administrators only, exposing the tools of mlsimport-mcp-abilities.php, and
 * turns off the adapter's generic default server when we loaded the adapter.
 *
 * Public interface:
 *   mlsimport_mcp_enabled()            is the Tools tab switch on
 *   mlsimport_mcp_adapter_decision()   pure rule: off / load / use theirs / mismatch
 *   mlsimport_mcp_state()              the decision taken on this request
 *   mlsimport_mcp_boot()               hook: plugins_loaded (the server itself is
 *                                      created in mlsimport-mcp-abilities.php)
 *   mlsimport_mcp_endpoint_url()       the address the client copies
 *   mlsimport_mcp_load_oauth()         load the sign-in files
 *   mlsimport_mcp_determine_user()     hook: determine_current_user
 *   mlsimport_mcp_guard_token_route()  hook: rest_pre_dispatch
 *   mlsimport_mcp_switch_changed()     hook: the switch option changed
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The loader of the bundled copy and the version reader of a loaded copy.
require_once __DIR__ . '/mlsimport-mcp-adapter-copy.php';

/** Option holding the Tools tab switch: 'yes' when AI assistant access is on. */
define( 'MLSIMPORT_MCP_ENABLED_OPTION', 'mlsimport_mcp_enabled' );

/** The MCP Adapter minor version this plugin was tested with (bundled: 0.6.1). */
define( 'MLSIMPORT_MCP_ADAPTER_TESTED_MINOR', '0.6' );

/**
 * Whether the Tools tab switch "AI assistant access" is on.
 *
 * @return bool
 */
function mlsimport_mcp_enabled(): bool {
	return 'yes' === get_option( MLSIMPORT_MCP_ENABLED_OPTION, '' );
}

/**
 * Decide what AI assistant access does on this request. Pure function.
 *
 * @param bool   $enabled         The Tools tab switch.
 * @param bool   $has_abilities   WordPress has the Abilities API (6.9+).
 * @param bool   $adapter_loaded  Another plugin already loaded the MCP Adapter.
 * @param string $loaded_version  That copy's version ('' when it cannot be read).
 * @return string 'off' | 'needs_wordpress_69' | 'load_bundled' | 'use_loaded' | 'version_mismatch'
 */
function mlsimport_mcp_adapter_decision( bool $enabled, bool $has_abilities, bool $adapter_loaded, string $loaded_version ): string {
	// Step 1: the switch decides first.
	if ( ! $enabled ) {
		return 'off';
	}

	// Step 2: no Abilities API, no tools.
	if ( ! $has_abilities ) {
		return 'needs_wordpress_69';
	}

	// Step 3: nobody loaded the adapter, so the bundled copy is used.
	if ( ! $adapter_loaded ) {
		return 'load_bundled';
	}

	// Step 4: another copy is loaded. Use it only when it is the tested minor
	// version ("0.6." prefix, so 0.60.x does not pass as 0.6.x).
	return 0 === strpos( $loaded_version, MLSIMPORT_MCP_ADAPTER_TESTED_MINOR . '.' )
		? 'use_loaded'
		: 'version_mismatch';
}

/**
 * The decision taken on this request ('' before mlsimport_mcp_boot() ran).
 *
 * The Tools tab reads it to explain why AI assistant access is not running.
 *
 * @return string
 */
function mlsimport_mcp_state(): string {
	return (string) ( $GLOBALS['mlsimport_mcp_state'] ?? '' );
}

/**
 * The MCP address the client copies into ChatGPT or Claude.
 *
 * @return string
 */
function mlsimport_mcp_endpoint_url(): string {
	return rest_url( 'mlsimport/mcp' );
}

/**
 * Start AI assistant access for this request, when the rules allow it.
 *
 * Hooked on plugins_loaded at a late priority, so a plugin that loads its own
 * copy of the adapter has done so before the check.
 *
 * Step by step:
 * 1. Take the decision and remember it for the Tools tab.
 * 2. Stop unless the decision is to load the bundled copy or use the loaded one.
 * 3. Bundled copy: register the loader and turn off the adapter's generic
 *    default server (it would expose every public ability of the site).
 * 4. Load the tool files and register the tools and the server.
 *
 * @return void
 */
function mlsimport_mcp_boot(): void {
	// Step 1: decide. class_exists() without autoloading: only a copy that is
	// really loaded counts.
	$adapter_loaded = class_exists( 'WP\\MCP\\Core\\McpAdapter', false );
	$state          = mlsimport_mcp_adapter_decision(
		mlsimport_mcp_enabled(),
		function_exists( 'wp_register_ability' ),
		$adapter_loaded,
		$adapter_loaded ? mlsimport_mcp_loaded_adapter_version() : ''
	);
	$GLOBALS['mlsimport_mcp_state'] = $state;

	// Step 2: anything else means the feature does not run.
	if ( 'load_bundled' !== $state && 'use_loaded' !== $state ) {
		return;
	}

	// Step 3: the bundled copy.
	if ( 'load_bundled' === $state ) {
		spl_autoload_register( 'mlsimport_mcp_autoload' );
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );
	}

	// Step 4: the tools and the server.
	$directory = MLSIMPORT_PLUGIN_PATH . 'includes/mcp/';
	require_once $directory . 'mlsimport-ai-activity.php';
	require_once $directory . 'mlsimport-mcp-account.php';
	require_once $directory . 'mlsimport-mcp-connections.php';
	require_once $directory . 'mlsimport-mcp-connection-manage.php';
	require_once $directory . 'mlsimport-mcp-mapping.php';
	require_once $directory . 'mlsimport-mcp-tasks.php';
	require_once $directory . 'mlsimport-mcp-task-read.php';
	require_once $directory . 'mlsimport-mcp-task-write.php';
	require_once $directory . 'mlsimport-mcp-imports.php';
	require_once $directory . 'mlsimport-mcp-import-control.php';
	require_once $directory . 'mlsimport-mcp-duplicates.php';
	require_once $directory . 'mlsimport-mcp-history.php';
	require_once $directory . 'mlsimport-mcp-events.php';
	require_once $directory . 'mlsimport-mcp-notifications.php';
	require_once $directory . 'mlsimport-mcp-tools-tab.php';
	require_once $directory . 'mlsimport-mcp-site.php';
	require_once $directory . 'mlsimport-mcp-confirm.php';
	require_once $directory . 'mlsimport-mcp-destructive.php';
	require_once $directory . 'mlsimport-mcp-menus.php';
	require_once $directory . 'mlsimport-mcp-menu-items.php';
	require_once $directory . 'mlsimport-mcp-menu-item-edit.php';
	require_once $directory . 'mlsimport-mcp-menu-delete.php';
	require_once $directory . 'mlsimport-mcp-navigation.php';
	require_once $directory . 'mlsimport-mcp-navigation-write.php';
	require_once $directory . 'mlsimport-mcp-navigation-items.php';
	require_once $directory . 'mlsimport-mcp-menu-locations.php';
	require_once $directory . 'mlsimport-mcp-pages.php';
	require_once $directory . 'mlsimport-mcp-front-page.php';
	require_once $directory . 'mlsimport-mcp-page-delete.php';
	require_once $directory . 'mlsimport-mcp-standalone-listings.php';
	require_once $directory . 'mlsimport-mcp-standalone-listing-edit.php';
	require_once $directory . 'mlsimport-mcp-standalone-listing-delete.php';
	require_once $directory . 'mlsimport-mcp-standalone-people.php';
	require_once $directory . 'mlsimport-mcp-standalone-people-write.php';
	require_once $directory . 'mlsimport-mcp-standalone-site.php';
	require_once $directory . 'mlsimport-mcp-term-picks.php';
	require_once $directory . 'mlsimport-mcp-standalone-pages.php';
	require_once $directory . 'mlsimport-mcp-standalone-pages-elementor.php';
	require_once $directory . 'mlsimport-mcp-standalone-pages-add.php';
	require_once $directory . 'mlsimport-mcp-standalone-pages-gutenberg.php';
	require_once $directory . 'mlsimport-mcp-standalone-pages-elementor-edit.php';
	require_once $directory . 'mlsimport-mcp-standalone-pages-edit.php';
	require_once $directory . 'mlsimport-mcp-standalone-pages-update.php';
	require_once $directory . 'mlsimport-mcp-elementor.php';
	require_once $directory . 'mlsimport-mcp-elementor-controls.php';
	require_once $directory . 'mlsimport-mcp-elementor-tree.php';
	require_once $directory . 'mlsimport-mcp-elementor-add.php';
	require_once $directory . 'mlsimport-mcp-elementor-edit.php';
	require_once $directory . 'mlsimport-mcp-elementor-page.php';
	require_once $directory . 'mlsimport-mcp-media.php';
	require_once $directory . 'mlsimport-mcp-site-identity.php';
	require_once $directory . 'mlsimport-mcp-posts-fields.php';
	require_once $directory . 'mlsimport-mcp-posts.php';
	require_once $directory . 'mlsimport-mcp-posts-manage.php';
	require_once $directory . 'mlsimport-mcp-custom-css.php';
	require_once $directory . 'mlsimport-mcp-abilities.php';

	// The sign-in for the web apps: tables, discovery, register/token routes,
	// the approval page and the 401 header that starts the discovery.
	mlsimport_mcp_load_oauth();
	mlsimport_oauth_maybe_install();
	add_action( 'init', 'mlsimport_oauth_route_request', 0 );
	add_action( 'rest_api_init', 'mlsimport_oauth_register_routes' );
	add_filter( 'rest_pre_dispatch', 'mlsimport_oauth_guard_request', 1, 3 );
	add_filter( 'rest_post_dispatch', 'mlsimport_oauth_unauthorized_header', 20, 3 );

	add_action( 'wp_abilities_api_categories_init', 'mlsimport_mcp_register_category' );
	add_action( 'wp_abilities_api_init', 'mlsimport_mcp_register_abilities' );
	add_action( 'mcp_adapter_init', 'mlsimport_mcp_create_server' );

	\WP\MCP\Core\McpAdapter::instance();
}

/**
 * Load the sign-in (OAuth) files.
 *
 * @return void
 */
function mlsimport_mcp_load_oauth(): void {
	$directory = MLSIMPORT_PLUGIN_PATH . 'includes/mcp/';
	require_once $directory . 'mlsimport-ai-activity.php';
	require_once $directory . 'oauth/mlsimport-oauth-rules.php';
	require_once $directory . 'oauth/mlsimport-oauth-guards.php';
	require_once $directory . 'oauth/mlsimport-oauth-clients.php';
	require_once $directory . 'oauth/mlsimport-oauth-store.php';
	require_once $directory . 'oauth/mlsimport-oauth-metadata.php';
	require_once $directory . 'oauth/mlsimport-oauth-endpoints.php';
	require_once $directory . 'oauth/mlsimport-oauth-authorize.php';
	require_once $directory . 'oauth/mlsimport-oauth-bearer.php';
}

/**
 * Sign a Connected AI App's request in as its approving administrator.
 *
 * Hooked on determine_current_user when this file loads, not from
 * mlsimport_mcp_boot(): WordPress works out the current user once per
 * request, and another plugin may ask for it before plugins_loaded priority
 * 20. The check itself stays narrow: the switch must be on, the sign-in
 * tables must exist, and the token counts on the MCP address only (see
 * includes/mcp/oauth/mlsimport-oauth-bearer.php).
 *
 * @param int|false $user_id The user WordPress has determined so far.
 * @return int|false
 */
function mlsimport_mcp_determine_user( $user_id ) {
	if ( ! empty( $user_id ) || ! mlsimport_mcp_enabled() || false === get_option( 'mlsimport_oauth_db_version' ) ) {
		return $user_id;
	}
	mlsimport_mcp_load_oauth();
	return mlsimport_oauth_determine_user( $user_id );
}

/**
 * Second lock on the token rule: a request signed in by an AI app's token may
 * run the MCP route and no other REST route.
 *
 * Hooked on rest_pre_dispatch. mlsimport_oauth_is_mcp_request() already
 * refuses to sign a token in anywhere else; this check uses the route
 * WordPress has actually resolved, so the rule holds even if a request finds
 * a way past the first check.
 *
 * @param mixed           $result  Response to send instead of dispatching (null to go on).
 * @param WP_REST_Server  $server  The REST server.
 * @param WP_REST_Request $request The request about to be dispatched.
 * @return mixed
 */
function mlsimport_mcp_guard_token_route( $result, $server, $request ) {
	if ( ! empty( $GLOBALS['mlsimport_oauth_token_request'] ) && '/mlsimport/mcp' !== rtrim( $request->get_route(), '/' ) ) {
		return new WP_Error( 'rest_forbidden', __( 'This token works only on the MLSImport MCP address.', 'mlsimport' ), array( 'status' => 401 ) );
	}
	return $result;
}

/**
 * Remove every Connected AI App when the switch is turned off.
 *
 * Hooked on the switch option being updated or deleted. Turning the switch
 * on again starts clean: every app has to be approved again.
 *
 * @return void
 */
function mlsimport_mcp_switch_changed(): void {
	if ( mlsimport_mcp_enabled() ) {
		return;
	}
	mlsimport_mcp_load_oauth();
	mlsimport_oauth_delete_everything();
}

// The Tools tab card and its actions exist in wp-admin whether the switch is
// on or off (that is where it is turned on).
if ( is_admin() ) {
	require_once MLSIMPORT_PLUGIN_PATH . 'includes/mcp/mlsimport-ai-activity.php';
	require_once MLSIMPORT_PLUGIN_PATH . 'includes/mcp/mlsimport-mcp-admin.php';
}

add_action( 'plugins_loaded', 'mlsimport_mcp_boot', 20 );
add_filter( 'determine_current_user', 'mlsimport_mcp_determine_user', 20 );
add_filter( 'rest_pre_dispatch', 'mlsimport_mcp_guard_token_route', 1, 3 );
add_action( 'update_option_' . MLSIMPORT_MCP_ENABLED_OPTION, 'mlsimport_mcp_switch_changed' );
add_action( 'delete_option_' . MLSIMPORT_MCP_ENABLED_OPTION, 'mlsimport_mcp_switch_changed' );
