<?php
/**
 * Plugin activation handler.
 *
 * Defines Mlsimport_Activator, whose static activate() runs on plugin activation
 * (registered via register_activation_hook in mlsimport.php). It clears the cached
 * plugin data schema transient and creates the plugin's activity tracking table.
 *
 * @package MLSImport
 */
// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Fired during plugin activation
 *
 * @link       http://mlsimport.com/
 * @since      1.0.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 * @author     MlsImport <office@mlsimport.com>
 */
class Mlsimport_Activator {


	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * Runs once on plugin activation: clears the cached plugin-data schema transient
	 * and ensures the activity tracking table exists.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function activate() {
			// Drop any stale cached schema so it is rebuilt fresh.
			delete_transient( 'mlsimport_plugin_data_schema' );
			// Create (or update) the activity tracking table.
			mlsimport_create_activity_table();
	}
}
