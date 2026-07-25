<?php
// Guard: block direct web access — only load when WordPress is bootstrapped.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Fired during plugin deactivation
 *
 * File role: defines the deactivation hook handler for the plugin. Registered as the
 * register_deactivation_hook() callback in mlsimport.php. Currently a no-op — all cleanup
 * (options/transients) is intentionally left commented out so settings survive deactivation.
 *
 * @link       http://mlsimport.com/
 * @since      1.0.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 * @author     MlsImport <office@mlsimport.com>
 */
class Mlsimport_Deactivator {


	/**
	 * Run on plugin deactivation.
	 *
	 * Intentionally a no-op: the cleanup calls below are left commented out so the
	 * cached schema transient and saved admin options persist across deactivation
	 * (re-activating keeps the user's configuration).
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function deactivate() {
		// Disabled cleanup — kept for reference; uncomment to wipe plugin state on deactivate.
		//	global $mlsimport;
		//	delete_transient( 'mlsimport_plugin_data_schema' );
		//	delete_option( 'mlsimport_admin_options' );
	}
}
