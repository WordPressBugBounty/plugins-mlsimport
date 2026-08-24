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
	 * Settings survive deactivation on purpose (re-activating keeps the user's
	 * configuration), but the cached rewrite rules must not: the standalone
	 * property archive rule would keep claiming its URL base with the plugin
	 * off (#206). Deleting rewrite_rules makes WordPress rebuild them on the
	 * next request, without this plugin's CPTs. The mode signature is deleted
	 * too so Mlsimport_Standalone_Cpt::maybe_flush_rewrites() re-flushes on
	 * the first init after a future re-activation.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public static function deactivate() {
		delete_option( 'rewrite_rules' );
		delete_option( 'mlsimport_rewrite_mode' );
	}
}
