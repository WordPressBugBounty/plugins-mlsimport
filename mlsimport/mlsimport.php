<?php
/**
 * Plugin Name:       MlsImport
 * Plugin URI:        https://mlsimport.com/
 * Description:       MLS Import - The MLSImport plugin facilitates the connection to your real estate MLS database, allowing you to download and synchronize real estate property data from the MLS.
 * Version:           6.1.10
 * Requires at least: 5.2
 * Requires PHP:      7.4
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Author:            MlsImport
 * Text Domain:       mlsimport
 * Domain Path:       /languages
 */

// If this file is called directly, abort.

if ( ! defined( 'WPINC' ) ) {
	die;
}


define( 'MLSIMPORT_VERSION', '6.1.10');
define( 'MLSIMPORT_CLUBLINK', 'mlsimport.com' );
define( 'MLSIMPORT_CLUBLINKSSL', 'https' );
define( 'MLSIMPORT_CRON_STEP', 20 );
define( 'MLSIMPORT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MLSIMPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );


//define( 'MLSIMPORT_API_URL', 'https://requests.mlsimport.com/' );
//define( 'MLSIMPORT_API_URL', 'https://pyjzsilw7b.execute-api.us-east-1.amazonaws.com/dev/' );
define( 'MLSIMPORT_API_URL', 'https://srky9ddikl.execute-api.us-east-1.amazonaws.com/blue/');






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
}



/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-mlsimport-deactivator.php
 */
function mlsimport_deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport-deactivator.php';
	wp_clear_scheduled_hook( 'event_mls_import_auto' );
	wp_clear_scheduled_hook( 'mlsimport_reconciliation_event' );
	Mlsimport_Deactivator::deactivate();
}



register_activation_hook( __FILE__, 'mlsimport_activate' );
register_deactivation_hook( __FILE__, 'mlsimport_deactivate' );



/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */

require 'vendor/autoload.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/help_functions.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-mlsimport.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ThemeImport.php';
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

            // Call processing function for this item
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


/*
 * Force use of transient
 *
 *
 *
 **/

function mlsimport_force_use_transient( $value ) {
	return $value;
	// return false;
}




global $mlsimport;
$mlsimport = new Mlsimport();
$mlsimport->run();





$supported_theme = array(
	991 => 'WpResidence',
	992 => 'Houzez',
	993 => 'Real Homes',
	994 => 'Wpestate',

);

define( 'MLSIMPORT_THEME', $supported_theme );

add_filter( 'action_scheduler_failure_period', 'mlsimport_saas_filter_timelimit' );
function mlsimport_saas_filter_timelimit( $time_limit ) {
	return 3000;
}



/*
 *
 * Write logs
 *
 **/

function mlsimport_saas_single_write_import_custom_logs( $message, $tip_import = 'normal' ) {
	// Check if logging is enabled
	$enable_logs = intval( get_option( 'mlsimport_disable_logs' ) );
	if ( 1 !==  $enable_logs) {
		return;
	}

	if ( is_array( $message ) ) {
		$message = wp_json_encode( $message );
	}

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



function mlsimport_debuglogs_per_plugin_old( $message ) {

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
function mlsimport_debuglogs_per_plugin( $message ) {

	if ( is_array( $message ) ) {
		$message = wp_json_encode( $message );
	}

	if ( empty( $message ) ) {
		return; // Exit the function if there's nothing to log
	}

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
function mlsimport_trigger_cron_job() {
	// ?mlsimport_cron=yes
	if ( isset( $_REQUEST['mlsimport_cron'] ) && 'yes' === sanitize_text_field( wp_unslash( $_REQUEST['mlsimport_cron'] ) )  ) {
		$last_run = intval( get_option( 'mlsimport_last_server_cron' ) );
		$now      = time();
		if ( 0 ===  intval($last_run)  ) {
			update_option( 'mlsimport_last_server_cron', $now );
		}

		if ( $last_run < $now - ( 60 * 60 * 2 ) ) {
			$log = 'Server Cron Job triggered on ' . date( 'l jS \of F Y h:i:s A', $last_run ) . ' vs ' . gmdate( 'l jS \of F Y h:i:s A', $now ) . PHP_EOL;
			// mlsimport_saas_event_mls_import_auto_function();
			update_option( 'mlsimport_last_server_cron', $now );
		} else {
			$log = 'Server Cron Job Called but not triggered. Last run on ' . gmdate( 'l jS \of F Y h:i:s A', $last_run ) . ' vs ' . gmdate( 'l jS \of F Y h:i:s A', $now ) . PHP_EOL;
		}

		mlsimport_saas_single_write_import_custom_logs( $log, 'server_cron' );
	}
}



function mlsimport_show_signup() {
	$affiliate_url = 'https://mlsimport.com';
	if ( function_exists( 'wp_estate_init' ) ) {
		$affiliate_url = 'https://mlsimport.com/ref/1/?campaign=wpresidence';
	}
	?>
	<div class="mlsimport_signup">
		<h3><?php  esc_html_e('Import MLS Listings into your Real Estate website', 'mlsimport'); ?></h3>
		<p><?php   esc_html_e('Signup now and get 30-Days Free trial, no setup fee & cancel anytime at ', 'mlsimport'); ?><a href="https://mlsimport.com/mls-import-plugin-pricing/" target="_blank">MLSImport.com</a></p>
		<a href="https://mlsimport.com/mls-import-plugin-pricing" class="button mlsimport_button mlsimport_signup_button" target="_blank"><?php esc_html_e('Create My Account', 'mlsimport'); ?></a>
	</div>
<?php
}


//add_action('admin_init', 'force_recount_all_terms');
function force_recount_all_terms() {
    global $wpdb;

    // Get all taxonomies
    $taxonomies = get_taxonomies([], 'names');

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
function mlsiport_mls_select_list( $key, $value, $data_array ) {
	$select = '<select class="mlsimport-2025-select" id="' . esc_attr( $key ) . '" name="mlsimport_admin_options[' . $key . ']">';
	if ( is_array( $data_array ) ) :
		foreach ( $data_array as $key => $mls_item ) {
			$select .= '<option value="' .esc_attr( $key ). '"';
			if ( intval( $value ) === intval( $key ) ) {
				$select .= ' selected ';
			}
			$select .= '>' .esc_html( $mls_item ). '</option>';
		}
	endif;
	$select .= '</select>';
	return $select;
}



add_action('wp_ajax_mlsimport_save_account', 'mlsimport_save_account_callback');
function mlsimport_save_account_callback() {
	check_ajax_referer('mlsimport_onboarding_nonce', 'security');

	$options = get_option('mlsimport_admin_options', []);
	if ( ! empty($_POST['mlsimport_username']) && ! empty($_POST['mlsimport_password']) ) {
		$options['mlsimport_username'] = sanitize_text_field($_POST['mlsimport_username']);
		$options['mlsimport_password'] = sanitize_text_field($_POST['mlsimport_password']);
		update_option('mlsimport_admin_options', $options);
	}

	global $mlsimport;

	// Refresh token
	$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();

	if (trim($token) === '') {
		ob_start();
	
		?>
		<div class="mlsimport_warning">
			<?php esc_html_e('You are not connected to MlsImport - Please check your Username and Password.', 'mlsimport'); ?>
		</div>
		<?php
		$html = ob_get_clean();

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


