<?php
/**
 * Plugin Name:       MlsImport
 * Plugin URI:        https://mlsimport.com/
 * Description:       MLS Import - The MLSImport plugin facilitates the connection to your real estate MLS database, allowing you to download and synchronize real estate property data from the MLS.
 * Version:           7.2.1
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Author:            MlsImport
 * Text Domain:       mlsimport
 * Domain Path:       /languages
 */

/*
 * ---------------------------------------------------------------------------
 * FILE ROLE: main plugin bootstrap.
 * ---------------------------------------------------------------------------
 * Responsibilities of this file, in load order:
 *   1. Define global constants (version, API endpoint, paths, cron batch size).
 *   2. Register activation/deactivation hooks and one-time upgrade notices.
 *   3. Track the installed version and flag upgrade modals.
 *   4. require_once every plugin PHP file (core, API client, theme/provider
 *      adapters, onboarding, telemetry, and the whole standalone/ module).
 *   5. Wire the standalone (theme_id 990) init/enqueue/template hooks.
 *   6. Schedule the WP-Cron events (hourly import, daily reconciliation, daily
 *      telemetry) and define their handler functions.
 *   7. Instantiate the core Mlsimport class and call run() to register hooks.
 *   8. Define assorted global helper functions (logging, dropdowns, onboarding
 *      AJAX save handlers).
 * ---------------------------------------------------------------------------
 */

// If this file is called directly, abort.

if ( ! defined( 'WPINC' ) ) {
	die;
}


// Current plugin version (kept in sync with the header above and the readme).
define( 'MLSIMPORT_VERSION', '7.2.1');
// Marketing/portal host used to build sign-up and affiliate links.
define( 'MLSIMPORT_CLUBLINK', 'mlsimport.com' );
// Scheme for the portal host links.
define( 'MLSIMPORT_CLUBLINKSSL', 'https' );
// Default per-request import batch size (listings pulled per cron step).
define( 'MLSIMPORT_CRON_STEP', 20 );
// Absolute filesystem path to this plugin directory (trailing slash).
define( 'MLSIMPORT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
// Public URL to this plugin directory (trailing slash).
define( 'MLSIMPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );


// SaaS API base URL (AWS API Gateway). The two commented lines are the legacy
// vanity host and the old "dev" stage; the active endpoint is the "blue" stage.
//define( 'MLSIMPORT_API_URL', 'https://requests.mlsimport.com/' );
//define( 'MLSIMPORT_API_URL', 'https://pyjzsilw7b.execute-api.us-east-1.amazonaws.com/dev/' );
define( 'MLSIMPORT_API_URL', 'https://srky9ddikl.execute-api.us-east-1.amazonaws.com/blue/');






// Allow a site to pre-define this constant (e.g. in wp-config.php) to hide the
// "finish setup" admin notice; default to showing it when not already defined.
if ( ! defined( 'MLSIMPORT_HIDE_SETUP_NOTICE' ) ) {
    define( 'MLSIMPORT_HIDE_SETUP_NOTICE', false );
}



/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-mlsimport-activator.php
 */
function mlsimport_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-activator.php';
	Mlsimport_Activator::activate();
	mlsimport_telemetry_set_once( 'installed_at', time() );

	// Standalone (theme_id 990) search table.
	require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-standalone-table.php';
	Mlsimport_Standalone_Table::create();
}



/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-mlsimport-deactivator.php
 */
function mlsimport_deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-deactivator.php';
	wp_clear_scheduled_hook( 'event_mls_import_auto' );
	wp_clear_scheduled_hook( 'mlsimport_reconciliation_event' );
	wp_clear_scheduled_hook( 'mlsimport_reconciliation_retry_event' );
	wp_clear_scheduled_hook( 'mlsimport_daily_telemetry_event' );
	delete_option( 'mlsimport_reconciliation_running' );
	Mlsimport_Deactivator::deactivate();
}



register_activation_hook( __FILE__, 'mlsimport_activate' );
register_deactivation_hook( __FILE__, 'mlsimport_deactivate' );

/**
 * Show one-time modal about the switch from Delete Statuses to Protected Statuses.
 */
add_action( 'admin_footer', 'mlsimport_protected_statuses_upgrade_modal' );
function mlsimport_protected_statuses_upgrade_modal() {
    // Bail if the user already acknowledged/dismissed the notice.
    if ( get_option( 'mlsimport_dismiss_protected_status_notice' ) ) {
        return;
    }
    // Only show for sites that had a version before 6.2 (not fresh installs)
    $show_modal = get_option( 'mlsimport_show_protected_status_modal' );
    // Bail when the upgrade flag was never set for this site.
    if ( ! $show_modal ) {
        return;
    }
    // Nonce for the dismissal AJAX call embedded in the modal's inline script.
    $nonce = wp_create_nonce( 'mlsimport_dismiss_protected_notice' );
    // Link the user to their Import Tasks list to set Protected Statuses.
    $import_tasks_url = admin_url( 'edit.php?post_type=mlsimport_item' );
    // Emit the modal markup + inline dismissal script into the admin footer.
    ?>
    <div id="mlsimport-protected-status-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:999999;display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;max-width:520px;width:90%;border-radius:8px;padding:30px;box-shadow:0 4px 20px rgba(0,0,0,0.3);">
            <h2 style="margin-top:0;color:#d63638;">MLSImport - Important Change</h2>
            <p><strong>"Delete Statuses"</strong> have been removed. The plugin now uses <strong>Protected Statuses</strong> only.</p>
            <p>Properties with a Protected Status will be kept during reconciliation. All other properties no longer found in MLS <strong>will be deleted</strong>.</p>
            <p style="background:#fff3cd;border-left:4px solid #dba617;padding:10px 14px;"><strong>Action required:</strong> Go to each of your <a href="<?php echo esc_url( $import_tasks_url ); ?>">Import Tasks</a> and set the Protected Statuses field to the statuses you want to keep (e.g. Active, Pending, Coming Soon).</p>
            <button id="mlsimport-acknowledge-btn" class="button button-primary" style="margin-top:10px;font-size:14px;padding:6px 24px;">I acknowledge</button>
        </div>
    </div>
    <script>
    document.getElementById('mlsimport-acknowledge-btn').addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Saving...';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxurl);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            document.getElementById('mlsimport-protected-status-modal').style.display = 'none';
        };
        xhr.send('action=mlsimport_dismiss_protected_notice&_wpnonce=<?php echo esc_js( $nonce ); ?>');
    });
    </script>
    <?php
}
add_action( 'wp_ajax_mlsimport_dismiss_protected_notice', 'mlsimport_handle_dismiss_protected_notice' );
/**
 * AJAX handler: persist the user's dismissal of the protected-statuses modal.
 *
 * Verifies the nonce, records the permanent "dismissed" flag, clears the
 * "show modal" flag, and returns a success JSON response.
 *
 * @return void
 */
function mlsimport_handle_dismiss_protected_notice() {
    // Verify the nonce created in the modal markup above.
    check_ajax_referer( 'mlsimport_dismiss_protected_notice' );
    // Persist that this notice has been acknowledged so it never shows again.
    update_option( 'mlsimport_dismiss_protected_status_notice', true );
    // Remove the one-time "show modal" flag.
    delete_option( 'mlsimport_show_protected_status_modal' );
    // Return an empty success payload to the inline script.
    wp_send_json_success();
}

/**
 * Track installed version and flag upgrade modals.
 * Fresh installs get current version immediately, so no upgrade modal.
 * Upgrades from < 6.2 flag the protected status modal.
 */
$mlsimport_prev_version = get_option( 'mlsimport_installed_version', '' );
if ( $mlsimport_prev_version !== MLSIMPORT_VERSION ) {
    if ( ! empty( $mlsimport_prev_version ) && version_compare( $mlsimport_prev_version, '6.2', '<' ) ) {
        update_option( 'mlsimport_show_protected_status_modal', true );
    }
    update_option( 'mlsimport_installed_version', MLSIMPORT_VERSION );
}

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */

/*
 * Action Scheduler — the plugin's only third-party runtime dependency.
 *
 * This is deliberately a direct require rather than `vendor/autoload.php`.
 * Composer's generated autoloader is written differently depending on whether
 * dev dependencies (phpunit, php_codesniffer, myclabs/deep-copy) happen to be
 * installed at the time it was generated. Those dev packages are never shipped,
 * so a dev-generated autoloader committed to the repo makes `autoload_files.php`
 * eagerly require files that do not exist in the released plugin — a fatal error
 * on activation, before any plugin code runs.
 *
 * Action Scheduler is built to be dropped into a plugin and included directly
 * (that is how WooCommerce loads it); it registers its own class loader and
 * negotiates versions with any other copy already loaded on the site. So there
 * is nothing left for the Composer autoloader to do at runtime, and not shipping
 * it removes that whole failure mode. Composer is still used for dev tooling.
 */
require_once plugin_dir_path( __FILE__ ) . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
// Core includes: RESO field defs + helpers, provider map, cron/reconciliation
// guards, status taxonomy/normalizer, then the orchestrator and API client.
require_once plugin_dir_path( __FILE__ ) . 'includes/help_functions.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-theme-detection.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-credentials.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-provider-map.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-enum-labels.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-country.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-reconciliation-guard.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-cron-guard.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-task-health.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-status-taxonomy.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-reconciliation.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-reconciliation-wordpress-environment.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-import-task-execution.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-import-task-execution-wordpress-environment.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-status-normalize.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-stored-listing-fields.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-stored-listing-title.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-stored-listing-media.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-listing-key-migration.php';
// Multi-MLS connection registry + the one-time single->multi MLS migration
// (the migration file hooks itself on init, after the listing-key migration).
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-connections.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-multimls-migration.php';
// Per-connection option resolution + settings export/import transfer (#275):
// every read/write of the "_{mls_id}"-suffixed per-MLS state goes through these.
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-connection-options.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-settings-transfer.php';
// N-entitlement SaaS contract consumer (#276): entitlements parsing, the
// mls_id echo guard, and the stable not_entitled rejection handling.
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-entitlements.php';
// Import task connection binding + cron isolation (#277): a task belongs to
// ONE connection for life; the hourly cron gates each task on ITS connection.
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-task-binding.php';
// Per-connection reconciliation (#279): the daily event runs the untouched
// decision module once per connection, each with an echo-guarded scoped
// snapshot and an inventory limited to that connection's stamped listings.
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-reconciliation-connections.php';
// Connections screen (#280): the settings-page Connections tab — screen data
// (registry rows + activity + plan cap + account state) and its AJAX handlers
// (drag-reorder priority, per-row connection test, account disconnect).
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-connections-screen.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-connections-ajax.php';
// Add-MLS drawer (#281): connection-scoped metadata gather (shared with the
// admin metadata AJAX) + the drawer's refuse/test/register/seed flow.
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-metadata-gather.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-connections-add.php';
// The follow-up field-mapping seed the drawer posts after a saved add (#325).
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-connections-seed.php';
// Edit-connection drawer (tab consolidation): the retired "MLS Connection"
// credentials tab's replacement — refuse/test/store flow that updates a
// registered connection's credentials (and mirrors the current connection's
// into the flat options).
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-connections-edit.php';
// Remove-connection flow: a row's Remove action — drops the record + its
// per-connection options and promotes the next-priority connection when the
// current one was removed. Imported listings and tasks stay.
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-connections-remove.php';
// Dedupe mechanics (#282): one visible copy per physical address across
// connections — winner by priority, loser hidden (never deleted), promoted
// back automatically when the winner disappears. Address normalization is
// the pure half; the evaluator/hooks half depends on it.
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-dedupe-address.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-dedupe.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-stored-listing-wordpress-environment.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-stored-listing-write.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-stored-listing-adapter-factory.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ThemeImport.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/StandaloneClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/ResidenceClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/EstateClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/HouzezClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/RealHomesClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/ResoBase.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/SparkResoClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/BridgeResoClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/TresleResoClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/MlsgridResoClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/BrightMlsResoClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/CentrisResoClass.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/ProviderResoClasses.php';
require_once plugin_dir_path( __FILE__ ) . 'enviroment/UnsupportedResoClass.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/addons/agents_offices.php';
// Why the last SaaS login failed (no subscription vs bad password) and the
// one "not connected" box every screen prints.
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-account-status.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-onboarding.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-field-configuration.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-field-selector-functions.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-progressive-save.php';
// Per-connection field-mapping UI: the one scope-resolution rule shared by the
// field_options tab, the field-configuration AJAX, and the metadata-gather AJAX.
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-field-mapping-scope.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-metadata-autotrigger.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-telemetry.php';
// #283: per-connection telemetry (sync stamps + the connections payload seam).
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-telemetry-connections.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-activity-log.php';
// #208: internal incident alerts (dedup + resolve) and import/connection health watch.
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-alerts.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-import-health.php';

/*
 * Standalone (theme_id 990) mode — own listings table, CPTs and taxonomies.
 * Registered on every load (ADR-0003); the table is created on activation and
 * upgraded behind a version guard on admin_init.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/mlsimport-hooks.php'; // Hook reference + convention (loaded early).
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-standalone-table.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-standalone-cpt.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-term-select.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-standalone-shortcodes.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-standalone-block.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-standalone-ajax.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-standalone-reindex.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-standalone-assets.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-standalone-single.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/property-section-registry.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/property-print.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-property-section-shortcodes.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-property-section-blocks.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-property-section-elementor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-property-lead.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/agent-sections.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/property-schema.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-property-metabox.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-agent-metabox.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-term-meta.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-property-columns.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-favorites.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/page-block-registry.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-page-block-shortcodes.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-page-block-blocks.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-page-block-elementor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/standalone/class-mlsimport-customizer.php'; // Standalone design settings in the WP Customizer (990 mode).
require_once plugin_dir_path( __FILE__ ) . 'includes/live/live-bootstrap.php'; // Live MLS passthrough mode (seam #1 — its only existing-file require).
add_action( 'init', array( 'Mlsimport_Standalone_Cpt', 'register' ) );
// After register(): drop cached rewrite rules when the standalone-mode signature changed (#206).
add_action( 'init', array( 'Mlsimport_Standalone_Cpt', 'maybe_flush_rewrites' ), 20 );
add_action( 'init', array( 'Mlsimport_Property_Metabox', 'register' ) );
add_action( 'init', array( 'Mlsimport_Agent_Metabox', 'register' ) );
add_action( 'init', array( 'Mlsimport_Term_Meta', 'register' ) );
add_action( 'init', array( 'Mlsimport_Property_Columns', 'register' ) );
add_action( 'init', array( 'Mlsimport_Standalone_Shortcodes', 'register' ) );
add_action( 'init', array( 'Mlsimport_Standalone_Block', 'register' ) );
add_action( 'init', array( 'Mlsimport_Standalone_Ajax', 'register' ) );
add_action( 'init', array( 'Mlsimport_Favorites', 'register' ) );
add_action( 'init', array( 'Mlsimport_Property_Section_Shortcodes', 'register' ) );
// Property-section Gutenberg blocks (mlsimport/property-*, "MLSImport — Property"
// category) are intentionally NOT registered — we don't expose them as blocks.
// The render dispatcher, shortcodes and Elementor widget behind them stay active.
// add_action( 'init', array( 'Mlsimport_Property_Section_Blocks', 'register' ) );
add_action( 'init', array( 'Mlsimport_Page_Block_Shortcodes', 'register' ) );
add_action( 'init', array( 'Mlsimport_Page_Block_Blocks', 'register' ) );
// Two MLSImport categories in the block inserter: single-property section blocks
// ("Property") and the page-builder blocks ("Real Estate").
add_filter(
	'block_categories_all',
	static function ( $categories ) {
		return array_merge(
			array(
				array( 'slug' => 'mlsimport-property', 'title' => __( 'MLSImport — Property', 'mlsimport' ) ),
				array( 'slug' => 'mlsimport-real-estate', 'title' => __( 'MLSImport — Real Estate', 'mlsimport' ) ),
			),
			$categories
		);
	}
);
add_action( 'init', array( 'Mlsimport_Property_Lead', 'register' ) );
// Priority 20 (not the default 10) so the plugin's self-contained BEM stylesheets
// enqueue AFTER the active theme's own styles. Themes hook wp_enqueue_scripts at 10
// and, because plugins load before the theme, our default-10 callback would print
// FIRST — losing every equal-specificity tie to a theme rule that prints later.
// Hello Elementor's reset.css is the concrete case: it styles bare `button` /
// `[type=button]` (pink #c36, inline-block, width:auto), which ties our single-class
// `.mlsimport-*` button rules (0,1,0) and won on order, so the multiselect control,
// range/beds toggles, popup buttons, submit and the card heart all rendered narrow
// and pink. Printing after the theme lets our base rules win that tie. It stays a
// SINGLE class (0,1,0), so component state rules (:focus / :hover / .is-open at
// 0,1,1+) and any Elementor Style-tab control ({{WRAPPER}} … at 0,2,0+) still win —
// this only reclaims the tie against a generic theme reset, and never matches a
// non-plugin (e.g. Elementor) element.
add_action( 'wp_enqueue_scripts', array( 'Mlsimport_Standalone_Assets', 'enqueue' ), 20 );
add_action( 'wp_enqueue_scripts', array( 'Mlsimport_Property_Section_Assets', 'ensure_registered' ) );
// Pre-enqueue the single-property section assets into the <head>; the single
// template prints the header before any section renders, so the on-demand
// enqueue at render time lands in the footer and flashes unstyled (issue #172).
// Priority 20 for the same after-the-theme reason as the standalone assets above.
add_action( 'wp_enqueue_scripts', array( 'Mlsimport_Property_Section_Assets', 'enqueue_for_single' ), 20 );
// Same for the single-agent page (issue #188).
add_action( 'wp_enqueue_scripts', 'mlsimport_agent_enqueue_for_single', 20 );
// Load the same front-end CSS into the block editor so the dynamic blocks'
// ServerSideRender previews match the front end (editor-guarded inside the method).
add_action( 'enqueue_block_assets', array( 'Mlsimport_Standalone_Assets', 'enqueue_editor' ) );
add_filter( 'template_include', array( 'Mlsimport_Standalone_Single', 'template_include' ) );
add_action( 'wp_head', 'mlsimport_property_print_schema' );
Mlsimport_Property_Section_Elementor::register();
Mlsimport_Page_Block_Elementor::register();
// A contact-form lead carries no property and no agent; route those general
// enquiries to the "Contact form recipients" setting (decision 3).
add_filter(
	'mlsimport_property_lead_recipient',
	static function ( $to, $property_id, $agent_id = 0 ) {
		if ( $property_id || $agent_id ) {
			return $to;
		}
		$recipients = trim( (string) mlsimport_standalone_option( 'contact_form_recipients', '' ) );
		return '' !== $recipients ? $recipients : $to;
	},
	10,
	3
);
add_action( 'admin_init', array( 'Mlsimport_Standalone_Table', 'maybe_upgrade' ) );
// One-time repair of comma-glued taxonomy terms written before 7.1.2 (#290).
add_action( 'admin_init', array( 'Mlsimport_Standalone_Cpt', 'maybe_split_packed_terms' ) );
add_action( 'before_delete_post', array( 'StandaloneClass', 'cleanup_on_delete' ) );
// Trash/unpublish (not a permanent delete) must also drop the listings row, so the
// search index only ever holds published listings.
add_action( 'transition_post_status', array( 'StandaloneClass', 'cleanup_on_status_change' ), 10, 3 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'mlsimport reindex',
		function () {
			$rebuilt = Mlsimport_Standalone_Reindex::rebuild_all();
			WP_CLI::success( "Reindexed {$rebuilt} standalone listings." );
		}
	);
}

if ( ! wp_next_scheduled( 'event_mls_import_auto' ) ) {
	wp_schedule_event( time(), 'hourly', 'event_mls_import_auto' );
}


/**
 * Scheduled event: Processes MLSimport items marked for cron processing, in memory-safe batches.
 *
 * This function is triggered by the 'event_mls_import_auto' action.
 * It fetches mlsimport_item post IDs in small batches (not all at once!) to minimize memory usage.
 * Only posts with meta 'mlsimport_item_stat_cron' = 1 are processed.
 * For each item, calls mlsimport_saas_start_cron_links_per_item().
 * 
 * Optimizations:
 * - Uses 'fields' => 'ids' so only post IDs are loaded (saves memory)
 * - Batches with posts_per_page/paged, so memory does not spike for large data sets
 * - Calls gc_collect_cycles() periodically to further reduce memory leaks
 * - Bails only when the SaaS token is missing (global, one account); each
 *   task is then gated on its OWN connection (#277) — a broken or deleted
 *   connection skips its tasks while healthy connections keep importing
 * 
 * @return void
 */
add_action('event_mls_import_auto', 'mlsimport_saas_event_mls_import_auto_function');
/**
 * Scheduled event handler for MLS Import Auto (runs via WP Cron).
 * Processes mlsimport_item posts in batches and logs memory usage.
 */
function mlsimport_saas_event_mls_import_auto_function() {
    global $mlsimport;

    //error_log('[AutoCron] Start: ' . (memory_get_usage(true) / 1024 / 1024) . ' MB');

    // Watchdog backstop (issue #199): a chunked manual import whose worker
    // chain died is normally revived by the polled progress screen, but if
    // the administrator closed that screen nothing else watches the run.
    // Hourly cron picks it up here; revive() is a cheap no-op for anything
    // that is not a silent, stalled manual run.
    // Import-health checks (#208) run before anything can bail out below:
    // detect a previous cron run that died mid-loop, and a manual run stuck
    // at "Preparing". Both open one deduplicated internal incident.
    mlsimport_cron_heartbeat_check();
    mlsimport_import_health_watch_manual_run();

    $mlsimport_active_lock = get_option( 'mlsimport_import_run_lock', array() );
    if ( is_array( $mlsimport_active_lock ) && ! empty( $mlsimport_active_lock['task_id'] ) ) {
        $mlsimport_revive = $mlsimport->admin->mlsimport_import_task_execution()->revive( (int) $mlsimport_active_lock['task_id'] );
        if ( true === ( $mlsimport_revive['revived'] ?? false ) ) {
            mlsimport_saas_single_write_import_custom_logs( 'Hourly watchdog revived the import worker chain for task ' . (int) $mlsimport_active_lock['task_id'] . '.' . PHP_EOL, 'manual' );
        } elseif ( 'stalled' === ( $mlsimport_revive['reason'] ?? '' ) ) {
            mlsimport_saas_single_write_import_custom_logs( 'Hourly watchdog declared the import run for task ' . (int) $mlsimport_active_lock['task_id'] . ' stalled and failed it.' . PHP_EOL, 'manual' );
            // The run was terminated as hopeless — tell the SaaS once (#208).
            mlsimport_alert_open(
                'import_stalled:' . (int) $mlsimport_active_lock['task_id'],
                'import_stalled',
                array( 'task_id' => (int) $mlsimport_active_lock['task_id'] )
            );
        }
    }

    // 0. Bail if a run is already in progress. Without this guard an overlapping
    // cron fire processes the same listings in parallel, causing duplicate
    // listing_key inserts and term_relationship/term_count deadlocks. The TTL is
    // the safety net if a run dies mid-loop without reaching the release below.
    if ( get_transient( 'mlsimport_cron_running' ) ) {
        return;
    }

    // 1. Get the API token from transient - exit if not set
    $token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
    //error_log('[AutoCron] After token fetch: ' . (memory_get_usage(true) / 1024 / 1024) . ' MB');
    if (trim($token) === '') {
        // Silent exit made frozen sites undiagnosable (issue #207 finding 3):
        // record the failed attempt with a real class before bailing.
        mlsimport_telemetry_set( 'last_sync_failed', time() );
        mlsimport_telemetry_set( 'last_sync_failed_code', 'no_token' );
        //error_log('[AutoCron] No token, exiting.');
        return;
    }

    // 2. The connection-status check is no longer a global bail-out (#277):
    // each task is gated on ITS OWN connection inside the loop below, so one
    // broken MLS never blocks tasks on healthy connections. Only the SaaS
    // token above stays global — one account, one token.

    // Claim the run lock now that we are committed to processing.
    set_transient( 'mlsimport_cron_running', 1, 15 * MINUTE_IN_SECONDS );

    // Every operation after lock acquisition belongs to one committed cron
    // run. Keep that complete run inside a try/finally boundary so a Throwable
    // from task discovery, connection gating, or one Import Run cannot leave
    // the site locked until the transient TTL expires (#303).
    try {
    // Heartbeat (#208): record that a cron import is now running, so the next
    // cron entry can tell a clean finish from a process that died mid-loop.
    mlsimport_cron_heartbeat_start();

    // Record sync attempt in telemetry. The 'syncs' counter is no longer
    // bumped here (#283): a run-level tick belongs to no single connection
    // and would double-count against the per-pull tick that
    // mlsimport_telemetry_record_sync_result() now records — one pull, one
    // tick, attributed to the pull's own connection, so the global syncs sum
    // stays equal to the sum of the per-connection buckets.
    mlsimport_telemetry_set( 'last_sync_attempt', time() );

    // 3. Set batch size for gathering and initialize loop variables
    $batch_size = 100;
    $paged = 1;
    $total_processed = 0;

    // 4. Gather every cron-enabled task id first (ids only — a few bytes each,
    // still fetched in paged batches so the query never loads post objects).
    // Gathering before processing is what makes fair ordering possible below.
    $cron_task_ids = array();
    do {
        // Prepare query: only IDs, filter by meta key, batch, paged, no_found_rows speeds up query
        $args = array(
            'post_type'      => 'mlsimport_item',
            'post_status'    => 'any',
            'posts_per_page' => $batch_size,
            'paged'          => $paged,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => 'mlsimport_item_stat_cron',
                    'value'   => 1,
                    'compare' => '=',
                ),
            ),
            'no_found_rows'  => true,
        );

        // Get post IDs for this batch
        $post_ids = get_posts($args);

        // If nothing is returned, the gather is complete
        if (empty($post_ids)) {
            break;
        }

        foreach ($post_ids as $prop_id) {
            $cron_task_ids[] = (int) $prop_id;
        }

        // Prepare next batch
        $paged++;
        unset($post_ids);   // Free memory

    } while (true);

    // 5. Order by starvation (issue #203): the query above returns tasks in
    // the same fixed order every hour, so when an early large task ate the
    // whole cycle the bottom tasks were skipped run after run. The queue key
    // is the newer of last success and last ATTEMPT (issue #330): ordering
    // by success alone let one task whose run never completes keep the
    // oldest stamp and hold first place every hour while the rest starved.
    $cron_task_watermarks = array();
    foreach ($cron_task_ids as $prop_id) {
        $cron_task_watermarks[ $prop_id ] = mlsimport_cron_task_queue_key(
            (string) get_post_meta( $prop_id, 'mlsimport_last_date', true ),
            (string) get_post_meta( $prop_id, 'mlsimport_last_attempt', true )
        );
    }
    unset($cron_task_ids);

    // 6. Process every task, most starved first. A broken connection is
    // recorded ONCE per run, not once per task — the entry is identical and
    // re-writing telemetry for every skipped task would only amplify writes.
    $failed_connections = array();
    foreach (mlsimport_cron_task_order($cron_task_watermarks) as $prop_id) {
        $logs = 'Loop custom post: ' . $prop_id . PHP_EOL;
        mlsimport_debuglogs_per_plugin($logs);

        // Per-connection failure isolation (#277): gate this task on ITS OWN
        // connection. A broken connection's tasks skip with a recorded reason;
        // a DELETED connection's tasks skip with a deduplicated health
        // incident and are never re-defaulted; healthy connections keep
        // importing in this same run.
        $mlsimport_gate = mlsimport_cron_task_gate( (int) $prop_id );
        if ( 'import' !== $mlsimport_gate['action'] ) {
            if ( 'skip_missing' === $mlsimport_gate['action'] ) {
                mlsimport_alert_open(
                    'task_connection_missing:' . (int) $prop_id,
                    'task_connection_missing',
                    array( 'task_id' => (int) $prop_id, 'mls_id' => $mlsimport_gate['mls_id'] )
                );
            } elseif ( ! isset( $failed_connections[ $mlsimport_gate['mls_id'] ] ) ) {
                $failed_connections[ $mlsimport_gate['mls_id'] ] = true;
                mlsimport_record_connection_sync_failure( $mlsimport_gate['mls_id'], $mlsimport_gate['code'] );
            }
            mlsimport_debuglogs_per_plugin(
                'Task ' . $prop_id . ' skipped: connection ' . $mlsimport_gate['mls_id']
                . ' ' . $mlsimport_gate['action'] . ' (' . $mlsimport_gate['code'] . ')' . PHP_EOL
            );
            continue;
        }

        // Call processing function for this item. The feed count it pulls
        // is recorded inside mlsimport_make_listing_requests() (last_feed_found).
        $mlsimport->admin->mlsimport_saas_start_cron_links_per_item($prop_id);

        $total_processed++;

        // Heartbeat (#208): measurable progress for the stuck-run check.
        mlsimport_cron_heartbeat_progress( $total_processed );

        // Free memory every 100 processed items
        if ($total_processed % 100 === 0) {
            gc_collect_cycles();
            //error_log("[AutoCron] Processed {$total_processed} total, memory: " . (memory_get_usage(true) / 1024 / 1024) . ' MB');
        }
    }

    // Heartbeat (#208): clean finish — also resolves an open died-run incident.
    } finally {
        // A failed task still ends this cron invocation. Mark the heartbeat as
        // finished and release the overlap lock while PHP continues propagating
        // the original Throwable to the caller for logging and diagnosis.
        mlsimport_cron_heartbeat_finish();
        delete_transient( 'mlsimport_cron_running' );
    }

    // last_sync_success is no longer stamped here (issue #207 finding 1): the
    // end-of-loop stamp reported success even when every request failed, and
    // never fired when a run died mid-loop. Each listings request now records
    // its own outcome inside mlsimport_make_listing_requests().

    //error_log('[AutoCron] Done, total processed: ' . $total_processed . ', end memory: ' . (memory_get_usage(true) / 1024 / 1024) . ' MB');
}



/*
 *  Reconciliation Mechanism
 *
 *
 *
 **/

if ( ! wp_next_scheduled( 'mlsimport_reconciliation_event' ) ) {
	wp_schedule_event( time(), 'daily', 'mlsimport_reconciliation_event' );
}

add_action( 'mlsimport_reconciliation_event', 'mlsimport_saas_reconciliation_event_function' );
add_action( 'mlsimport_reconciliation_retry_event', 'mlsimport_saas_reconciliation_event_function' );

if ( ! wp_next_scheduled( 'mlsimport_daily_telemetry_event' ) ) {
	wp_schedule_event( time(), 'daily', 'mlsimport_daily_telemetry_event' );
}

add_action( 'mlsimport_daily_telemetry_event', 'mlsimport_telemetry_run_daily' );


/*
 * Force use of transient
 *
 *
 *
 **/

/**
 * Filter callback stub that returns the transient value unchanged.
 *
 * Left as a pass-through hook point; the commented `return false;` would force
 * a transient miss for debugging.
 *
 * @param mixed $value Incoming transient value.
 * @return mixed The value unchanged.
 */
function mlsimport_force_use_transient( $value ) {
	return $value;
	// return false;
}




// Instantiate the core orchestrator and register all admin/public hooks.
global $mlsimport;
$mlsimport = new Mlsimport();
$mlsimport->run();





// Map of supported theme IDs to human labels. 990 = standalone (no host theme
// dependency); 991-994 are the four supported real-estate themes.
$supported_theme = array(
	990 => 'Standalone (any theme)',
	991 => 'WpResidence',
	992 => 'Houzez',
	993 => 'Real Homes',
	994 => 'Wpestate',

);

// Expose the theme map as a constant for use across the plugin.
define( 'MLSIMPORT_THEME', $supported_theme );

add_filter( 'action_scheduler_failure_period', 'mlsimport_saas_filter_timelimit' );
/**
 * Raise the Action Scheduler failure timeout so long imports are not marked failed.
 *
 * @param int $time_limit Default failure period in seconds (unused).
 * @return int Fixed failure period of 3000 seconds.
 */
function mlsimport_saas_filter_timelimit( $time_limit ) {
	return 3000;
}



/*
 *
 * Write logs
 *
 **/

/**
 * Append a timestamped line to a per-type import log file (when logging is on).
 *
 * @param string|array $message    Message to log; arrays are JSON-encoded.
 * @param string       $tip_import Log bucket: normal|cron|delete|server_cron.
 * @return void
 */
function mlsimport_saas_single_write_import_custom_logs( $message, $tip_import = 'normal' ) {
	// Check if logging is enabled
	$enable_logs = intval( get_option( 'mlsimport_disable_logs' ) );
	// Bail unless the "logs enabled" option equals exactly 1.
	if ( 1 !==  $enable_logs) {
		return;
	}

	// Encode array payloads to JSON so they can be written as text.
	if ( is_array( $message ) ) {
		$message = wp_json_encode( $message );
	}

	// Prefix the message with a UTC timestamp.
	$formatted_message = gmdate( 'F j, Y, g:i a' ) . ' -> ' . $message;

	// Determine the log file path based on the import type
	$log_file_name =  'cron' 		 ===  $tip_import  ? 'cron_logs' :
					(  'delete' 	 ===  $tip_import  ? 'delete_logs' :
					(  'server_cron' ===  $tip_import  ? 'server_cron_logs' : 'import_logs' ) );

	// Construct the full path with a date suffix
	$log_file_path = WP_PLUGIN_DIR . "/mlsimport/logs/{$log_file_name}-" . gmdate( 'Y-m-d' ) . '.log';

	// Error handling for file operations
	try {
		// Check and create the directory for logs if it does not exist
		$log_dir = dirname( $log_file_path );
		if ( ! file_exists( $log_dir ) ) {
			mkdir( $log_dir, 0755, true );
		}

		// Append the formatted message to the log file
		file_put_contents( $log_file_path, $formatted_message, FILE_APPEND | LOCK_EX );
	} catch ( Exception $e ) {
		// Handle the exception, such as logging the error elsewhere or sending a notification
	}
}



/*
 *
 *
 * Write Status logs
 *
 *
 **/



/**
 * Legacy status-log writer (superseded by mlsimport_debuglogs_per_plugin()).
 *
 * Note: writes with LOCK_EX but no FILE_APPEND, so it overwrites status_logs.log
 * on each call. Retained under the _old suffix; not called anywhere.
 *
 * @param string|array $message Message to log; arrays are JSON-encoded.
 * @return void
 */
function mlsimport_debuglogs_per_plugin_old( $message ) {

	// Encode array payloads to JSON.
	if ( is_array( $message ) ) {
		$message = wp_json_encode( $message );
	}

	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();
	}

	$path_status = WP_PLUGIN_DIR . '/mlsimport/logs/status_logs.log';
	file_put_contents( $path_status, $message, LOCK_EX );
}
/**
 * Append a status/debug line to logs/status_logs.log.
 *
 * @param string|array $message Message to log; arrays are JSON-encoded.
 * @return void
 */
function mlsimport_debuglogs_per_plugin( $message ) {

	// Encode array payloads to JSON.
	if ( is_array( $message ) ) {
		$message = wp_json_encode( $message );
	}

	// Nothing to write for an empty message.
	if ( empty( $message ) ) {
		return; // Exit the function if there's nothing to log
	}

	// Target log file inside the plugin's logs/ directory.
	$log_file_path = WP_PLUGIN_DIR . '/mlsimport/logs/status_logs.log';

	// Check and create the directory for logs if it does not exist
	$log_dir = dirname( $log_file_path );
	if ( ! file_exists( $log_dir ) ) {
		mkdir( $log_dir, 0755, true );
	}

	// Error handling for file operations
	try {
		// Append the message to the log file with a newline and acquire an exclusive lock during writing
		file_put_contents( $log_file_path, $message . PHP_EOL, LOCK_EX );
	} catch ( Exception $e ) {
		// Handle the exception, such as logging the error elsewhere or sending a notification
	}
}





/*
 * Cron job trigger
 *
 *
 *
 **/


// */5 * * * * wget http://example.com/check  */2
add_action( 'init', 'mlsimport_trigger_cron_job' );
/**
 * Server-cron entry point hit via the ?mlsimport_cron=yes query parameter.
 *
 * Rate-limited to once every 2 hours. Note: the actual import call on the
 * throttled branch is currently commented out, so this only writes a log line.
 *
 * @return void
 */
function mlsimport_trigger_cron_job() {
	// ?mlsimport_cron=yes
	// Only proceed when the request carries mlsimport_cron=yes.
	if ( isset( $_REQUEST['mlsimport_cron'] ) && 'yes' === sanitize_text_field( wp_unslash( $_REQUEST['mlsimport_cron'] ) )  ) {
		// Timestamp of the previous server-cron run (0 if never run).
		$last_run = intval( get_option( 'mlsimport_last_server_cron' ) );
		// Current time.
		$now      = time();
		// First-ever call: seed the last-run timestamp.
		if ( 0 ===  intval($last_run)  ) {
			update_option( 'mlsimport_last_server_cron', $now );
		}

		// Only run if at least 2 hours have elapsed since the last run.
		if ( $last_run < $now - ( 60 * 60 * 2 ) ) {
			// Build the "triggered" log line and record the new run time.
			$log = 'Server Cron Job triggered on ' . date( 'l jS \of F Y h:i:s A', $last_run ) . ' vs ' . gmdate( 'l jS \of F Y h:i:s A', $now ) . PHP_EOL;
			// mlsimport_saas_event_mls_import_auto_function();
			update_option( 'mlsimport_last_server_cron', $now );
		} else {
			$log = 'Server Cron Job Called but not triggered. Last run on ' . gmdate( 'l jS \of F Y h:i:s A', $last_run ) . ' vs ' . gmdate( 'l jS \of F Y h:i:s A', $now ) . PHP_EOL;
		}

		mlsimport_saas_single_write_import_custom_logs( $log, 'server_cron' );
	}
}



/**
 * Render the "Sign up for MLSImport" promo box (30-day free-trial CTA).
 *
 * Uses a WpResidence-specific affiliate URL when that theme is active.
 *
 * @return void
 */
function mlsimport_show_signup() {
	// Default sign-up URL.
	$affiliate_url = 'https://mlsimport.com';
	// Swap in the WpResidence affiliate/campaign link when that theme is active.
	if ( function_exists( 'wp_estate_init' ) ) {
		$affiliate_url = 'https://mlsimport.com/ref/1/?campaign=wpresidence';
	}
	// Output the promo markup.
	?>
	<div class="mlsimport_signup">
		<h3><?php  esc_html_e('Import MLS Listings into your Real Estate website', 'mlsimport'); ?></h3>
		<p><?php   esc_html_e('Signup now and get 30-Days Free trial, no setup fee & cancel anytime at ', 'mlsimport'); ?><a href="https://mlsimport.com/mls-import-plugin-pricing/" target="_blank">MLSImport.com</a></p>
		<a href="https://mlsimport.com/mls-import-plugin-pricing" class="button mlsimport_button mlsimport_signup_button" target="_blank"><?php esc_html_e('Create My Account', 'mlsimport'); ?></a>
	</div>
<?php
}


//add_action('admin_init', 'force_recount_all_terms');
/**
 * Maintenance utility: recalculate term counts for every taxonomy.
 *
 * Not hooked by default (the add_action above is commented out); intended to be
 * run manually to fix drifted term counts. Echoes a completion message.
 *
 * @return void
 */
function force_recount_all_terms() {
    global $wpdb;

    // Get all taxonomies
    $taxonomies = get_taxonomies([], 'names');

    // Recount terms for each registered taxonomy.
    foreach ($taxonomies as $taxonomy) {
        // Get all terms for the taxonomy
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false, // Include terms with 0 count
            'fields' => 'ids', // Get only the term IDs
        ]);

        if (!is_wp_error($terms) && !empty($terms)) {
            // Get term_taxonomy_ids for these terms
            $term_taxonomy_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id IN (" . implode(',', array_map('intval', $terms)) . ")"
            ));

            // Update term counts
            if (!empty($term_taxonomy_ids)) {
                wp_update_term_count_now($term_taxonomy_ids, $taxonomy);
            }
        }
    }

    echo "Term counts have been recalculated for all taxonomies.";
}


// The theme <select> builder lives in includes/mlsimport-theme-detection.php,
// next to mlsimport_resolve_theme_id() whose answer it renders (#242).



// mlsimport_save_account_callback() (wp_ajax_mlsimport_save_account) lives in
// includes/mlsimport-onboarding.php with the wizard code that submits it. This
// also keeps the live credential-save path loadable by the focused credential
// sanitization and token-purge regression harnesses.






add_action('wp_ajax_mlsimport_save_mls_data', 'mlsimport_save_mls_data_callback');
function mlsimport_save_mls_data_callback() {
	check_ajax_referer('mlsimport_onboarding_nonce', 'security');
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}

	$options = get_option('mlsimport_admin_options', []);
	$options = is_array( $options ) ? $options : array();
	$previous_mls_id = isset( $options['mlsimport_mls_name'] )
		? (string) $options['mlsimport_mls_name']
		: '';

	foreach ($_POST as $key => $value) {
		if (strpos($key, 'mlsimport_') === 0 && $key !== 'mlsimport_username' && $key !== 'mlsimport_password') {
			$options[$key] = sanitize_text_field($value);
		}
	}

	// An MLS change invalidates only state owned by the old selection. Provider
	// credentials remain saved so returning to that provider restores its fields.
	$new_mls_id = isset( $options['mlsimport_mls_name'] )
		? (string) $options['mlsimport_mls_name']
		: '';
	if ( $previous_mls_id !== $new_mls_id ) {
		Mlsimport_Provider_Family::clear_active_state();
	} else {
		Mlsimport_Provider_Family::clear_access_tokens();
		delete_option( 'mlsimport_connection_test' );
	}

	// Saving credentials always forces a fresh metadata gather for the SAVED
	// MLS. The populated flag is per-connection (#275): only this connection's
	// flag is cleared — other connections' gathered state stays isolated.
	mlsimport_delete_connection_option( 'mlsimport_mls_metadata_populated', (int) $new_mls_id );

	update_option('mlsimport_admin_options', $options);

	// Always test the newly saved selection. Reusing a prior "yes" flag could
	// incorrectly report that a different MLS or changed credentials succeeded.
	global $mlsimport;
	$mlsimport->admin->mlsimport_saas_setting_up();
	$mlsimport->admin->mlsimport_saas_check_mls_connection();
	$is_mls_connected = get_option('mlsimport_connection_test', '');

	ob_start();
	if ('yes' === $is_mls_connected) {
		?>
		<div class="mlsimport_warning mlsimport_validated">
			<?php esc_html_e('You’re now connected to your MLS.', 'mlsimport'); ?>
		</div>
		<?php
	} else {
		?>
		<div class="mlsimport_warning">
			<?php esc_html_e('The connection to your MLS was NOT successful. Please check the authentication token is correct and check your MLS Data Access Application is approved.', 'mlsimport'); ?>
		</div>
		<?php
	}
	$html = ob_get_clean();

	wp_send_json_success([
		'message' => __('MLS data saved', 'mlsimport'),
		'html'    => $html,
		'connected' => $is_mls_connected === 'yes',
	]);
}
