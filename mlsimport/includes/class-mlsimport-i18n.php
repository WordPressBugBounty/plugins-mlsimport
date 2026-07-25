<?php 
// Guard: block direct web access — only load when WordPress is bootstrapped.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Define the internationalization functionality
 *
 * File role: loads the plugin's translation (.mo) files from the /languages/ directory.
 * The load_plugin_textdomain() method is hooked to 'plugins_loaded' via the Loader so the
 * 'mlsimport' text domain is available before admin/public strings are translated.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       http://mlsimport.com/
 * @since      1.0.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 * @author     MlsImport <office@mlsimport.com>
 */
class Mlsimport_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * Registers the 'mlsimport' text domain, pointing WordPress at the plugin's
	 * /languages/ directory so .mo files there are used for translated strings.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function load_plugin_textdomain() {

		// Register the text domain; path is derived from this file: includes/ -> plugin root -> /languages/.
		load_plugin_textdomain(
			'mlsimport',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);
	}
}
