<?php
/**
 * Plugin Name:       MlsImport
 * Plugin URI:        https://mlsimport.com/
 * Description:       "MLS Import - The MLSImport plugin facilitates the connection to your real estate MLS database, allowing you to download and synchronize real estate property data from the MLS.
 * Version:           5.8.3
 * Requires at least: 5.2
 * Requires PHP:      7.2
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


define( 'MLSIMPORT_VERSION', '5.8.2' );
define( 'MLSIMPORT_CLUBLINK', 'mlsimport.com' );
define( 'MLSIMPORT_CLUBLINKSSL', 'https' );
define( 'MLSIMPORT_CRON_STEP', 20 );
define( 'MLSIMPORT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MLSIMPORT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MLSIMPORT_API_URL', 'https://requests.mlsimport.com/' );



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



if ( ! wp_next_scheduled( 'event_mls_import_auto' ) ) {
	wp_schedule_event( time(), 'hourly', 'event_mls_import_auto' );
}







add_action( 'event_mls_import_auto', 'mlsimport_saas_event_mls_import_auto_function' );
function mlsimport_saas_event_mls_import_auto_function() {
	global $mlsimport;
	$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
	if ( trim( $token ) === '' ) {
		return;
	}

	$is_mls_connected = get_option( 'mlsimport_connection_test', '' );
	if ( 'yes' !== $is_mls_connected   ) {
		return;
	}

	$logs ='';
	mlsimport_debuglogs_per_plugin( $logs );
	$args = array(
		'post_type'      => 'mlsimport_item',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'     => 'mlsimport_item_stat_cron',
				'value'   => 1,
				'compare' => '=',
			),

		),

	);

	$prop_selection = new WP_Query( $args );
	if ( $prop_selection->have_posts() ) {
		while ( $prop_selection->have_posts() ) :
			$prop_selection->the_post();
			$prop_id = get_the_ID();
			$logs    = ' Loop custom post : ' . $prop_id . PHP_EOL;
			mlsimport_debuglogs_per_plugin( $logs );
			$mlsimport->admin->mlsimport_saas_start_cron_links_per_item( $prop_id );
		endwhile;
	}
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
		<p><?php   esc_html_e('Signup now and get 30-Days Free trial, no setup fee & cancel anytime at ', 'mlsimport'); ?><a href="<?php echo esc_url($affiliate_url); ?>" target="_blank">MlsImport.com</a></p>
		<a href="<?php echo esc_url($affiliate_url); ?>" class="button mlsimport_button mlsimport_signup_button" target="_blank"><?php esc_html_e('Create My Account', 'mlsimport'); ?></a>
	</div>
<?php
}

/*

// Register the hook before calling the scheduling function
add_action('mlsimport_delete_empty_terms_batch_event', 'mlsimport_delete_empty_terms_batch', 10, 3);

// Call this function with your taxonomy to start the deletion process
mlsimport_schedule_empty_terms_deletion('property_area');

function mlsimport_schedule_empty_terms_deletion($taxonomy) {
    // Clear any existing scheduled events
    wp_clear_scheduled_hook('mlsimport_delete_empty_terms_batch_event');

    // Schedule the first batch
    if (!wp_next_scheduled('mlsimport_delete_empty_terms_batch_event', array($taxonomy, 100, 0))) {
        wp_schedule_single_event(time() + 30, 'mlsimport_delete_empty_terms_batch_event', array($taxonomy, 100, 0));
        echo 'Scheduled first batch event.<br>';
    } else {
        echo 'First batch event already scheduled.<br>';
    }
}

function mlsimport_delete_empty_terms_batch($taxonomy, $batch_size = 100, $offset = 0) {
    // Debugging output to confirm function execution
    echo 'Processing batch starting from offset: ' . $offset . '<br>';

    // Get terms in batches
    $terms = get_terms(array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => false, // Include empty terms
        'orderby'    => 'count',
        'order'      => 'ASC',
        'number'     => $batch_size,
        'offset'     => $offset,
    ));

    if (!is_wp_error($terms) && !empty($terms)) {
        foreach ($terms as $term) {
            // Check if term count is zero
            if ($term->count == 0) {
                // Delete the term if it has no posts associated
                wp_delete_term($term->term_id, $taxonomy);
                echo 'Deleted term ID: ' . $term->term_id . '<br>';
            }
        }

        // Schedule the next batch if more terms are found
        if (count($terms) == $batch_size) {
            $next_offset = $offset + $batch_size;
            wp_schedule_single_event(time() + 30, 'mlsimport_delete_empty_terms_batch_event', array($taxonomy, $batch_size, $next_offset));
            echo 'Scheduled next batch event from offset: ' . $next_offset . '<br>';
        } else {
            echo 'No more terms to process.<br>';
        }
    } else {
        // Debugging output for error or completion
        if (is_wp_error($terms)) {
            echo 'Error fetching terms: ' . $terms->get_error_message() . '<br>';
        } else {
            echo 'No more terms to process.<br>';
        }
    }
}


*/


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