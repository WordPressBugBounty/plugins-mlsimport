<?php
/**
 * Plugin Name:       MlsImport
 * Plugin URI:        https://mlsimport.com/
 * Description:       MLS Import - The MLSImport plugin facilitates the connection to your real estate MLS database, allowing you to download and synchronize real estate property data from the MLS.
 * Version:           7.0.7
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
define( 'MLSIMPORT_VERSION', '7.0.7');
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
	wp_clear_scheduled_hook( 'mlsimport_daily_telemetry_event' );
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
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-provider-map.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-reconciliation-guard.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-cron-guard.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-status-taxonomy.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-status-normalize.php';
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
require_once plugin_dir_path( __FILE__ ) . 'enviroment/MlsgridResoClass.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/addons/agents_offices.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-onboarding.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-field-selector-functions.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-progressive-save.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-telemetry.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/mlsimport-activity-log.php';

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
 * - Skips processing if MLS is not connected or token is missing
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
        //error_log('[AutoCron] No token, exiting.');
        return;
    }

    // 2. Check if MLS connection is valid - exit if not
    $is_mls_connected = get_option('mlsimport_connection_test', '');
    //error_log('[AutoCron] After connection check: ' . (memory_get_usage(true) / 1024 / 1024) . ' MB');
    if ('yes' !== $is_mls_connected) {
        //error_log('[AutoCron] No valid connection, exiting.');
        return;
    }

    // Claim the run lock now that we are committed to processing.
    set_transient( 'mlsimport_cron_running', 1, 15 * MINUTE_IN_SECONDS );

    // Record sync attempt in telemetry
    mlsimport_telemetry_bump( 'syncs' );
    mlsimport_telemetry_set( 'last_sync_attempt', time() );

    // 3. Set batch size for processing and initialize loop variables
    $batch_size = 100;
    $paged = 1;
    $total_processed = 0;

    // 4. Process in batches until no more items are found
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
        //error_log("[AutoCron] Batch {$paged} fetched " . count($post_ids) . " items, memory: " . (memory_get_usage(true) / 1024 / 1024) . ' MB');

        // If nothing is returned, break the loop
        if (empty($post_ids)) {
            break;
        }

        // 5. Loop through each post ID in this batch
        foreach ($post_ids as $prop_id) {
            $logs = 'Loop custom post: ' . $prop_id . PHP_EOL;
            mlsimport_debuglogs_per_plugin($logs);

            // Call processing function for this item. The feed count it pulls
            // is recorded inside mlsimport_make_listing_requests() (last_feed_found).
            $mlsimport->admin->mlsimport_saas_start_cron_links_per_item($prop_id);

            $total_processed++;

            // Free memory every 100 processed items
            if ($total_processed % 100 === 0) {
                gc_collect_cycles();
                //error_log("[AutoCron] Processed {$total_processed} total, memory: " . (memory_get_usage(true) / 1024 / 1024) . ' MB');
            }
        }

        // 6. Prepare next batch
        $paged++;
        unset($post_ids);   // Free memory
        gc_collect_cycles(); // Trigger garbage collection
        //error_log("[AutoCron] After batch {$paged}, memory: " . (memory_get_usage(true) / 1024 / 1024) . ' MB');

    } while (true);

    // Release the run lock so the next scheduled run can proceed.
    delete_transient( 'mlsimport_cron_running' );

    mlsimport_telemetry_set( 'last_sync_success', time() );

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


/*
 *
 * create dropdown list
 *
 *
 */
/**
 * Build a <select> for a mlsimport_admin_options[$key] field.
 *
 * @param string $key        Option key (used as id and name suffix).
 * @param mixed  $value       Currently selected option value.
 * @param array  $data_array  Map of option value => label.
 * @return string The rendered <select> HTML.
 */
function mlsiport_mls_select_list( $key, $value, $data_array ) {
	// Open the select, binding it to the mlsimport_admin_options[$key] field.
	$select = '<select class="mlsimport-2025-select" id="' . esc_attr( $key ) . '" name="mlsimport_admin_options[' . $key . ']">';
	// Only build options when given an array of choices.
	if ( is_array( $data_array ) ) :
		// Emit one <option> per choice.
		foreach ( $data_array as $key => $mls_item ) {
			$select .= '<option value="' .esc_attr( $key ). '"';
			// Mark the option matching the current value as selected.
			if ( intval( $value ) === intval( $key ) ) {
				$select .= ' selected ';
			}
			$select .= '>' .esc_html( $mls_item ). '</option>';
		}
	endif;
	// Close the select and return the assembled markup.
	$select .= '</select>';
	return $select;
}



add_action('wp_ajax_mlsimport_save_account', 'mlsimport_save_account_callback');
/**
 * AJAX handler: save the MLSImport account username/password and test the login.
 *
 * Verifies the onboarding nonce, stores credentials in mlsimport_admin_options,
 * fetches a fresh API token, and returns connected/not-connected HTML + flag.
 *
 * @return void
 */
function mlsimport_save_account_callback() {
	// Verify the shared onboarding AJAX nonce.
	check_ajax_referer('mlsimport_onboarding_nonce', 'security');

	// Load current plugin options.
	$options = get_option('mlsimport_admin_options', []);
	// Persist the submitted credentials only when both are present.
	if ( ! empty($_POST['mlsimport_username']) && ! empty($_POST['mlsimport_password']) ) {
		$options['mlsimport_username'] = sanitize_text_field($_POST['mlsimport_username']);
		$options['mlsimport_password'] = sanitize_text_field($_POST['mlsimport_password']);
		update_option('mlsimport_admin_options', $options);
	}

	global $mlsimport;

	// Refresh token
	$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();

	// Empty token means the credentials did not authenticate.
	if (trim($token) === '') {
		// Buffer the "not connected" warning markup.
		ob_start();
	
		?>
		<div class="mlsimport_warning">
			<?php esc_html_e('You are not connected to MlsImport - Please check your Username and Password.', 'mlsimport'); ?>
		</div>
		<?php
		$html = ob_get_clean();

		// Return failure HTML + connected=false.
		wp_send_json_success([
			'message' => __('You are not connected.', 'mlsimport'),
			'html'    => $html,
			'connected' => false
		]);
	} else {
		ob_start();
		?>
		<div class="mlsimport_warning mlsimport_validated">
			<?php esc_html_e('You are connected to your MlsImport account!', 'mlsimport'); ?>
		</div>
		<?php
		$html = ob_get_clean();

		wp_send_json_success([
			'message' => __('Connected successfully!', 'mlsimport'),
			'html'    => $html,
			'connected' => true
		]);
	}
}








add_action('wp_ajax_mlsimport_save_mls_data', 'mlsimport_save_mls_data_callback');
function mlsimport_save_mls_data_callback() {
	check_ajax_referer('mlsimport_onboarding_nonce', 'security');

	$options = get_option('mlsimport_admin_options', []);

	foreach ($_POST as $key => $value) {
		if (strpos($key, 'mlsimport_') === 0 && $key !== 'mlsimport_username' && $key !== 'mlsimport_password') {
			$options[$key] = sanitize_text_field($value);
		}
	}

	update_option('mlsimport_admin_options', $options);

	// Run MLS connection check
	global $mlsimport;
	$is_mls_connected = get_option('mlsimport_connection_test', '');
	$mlsimport->admin->mlsimport_saas_setting_up();

	if ('yes' !== $is_mls_connected) {
		$mlsimport->admin->mlsimport_saas_check_mls_connection();
		$is_mls_connected = get_option('mlsimport_connection_test', '');
	}

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


