<?php 
// Bail if the file is requested directly (outside of WordPress).
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Public-facing side of the plugin.
 *
 * Defines the hooks that run on the front end: enqueuing the public CSS/JS and a
 * filter that rewrites MLS-imported attachment URLs. The heavy front-end surfaces
 * (standalone listings, agent pages, page blocks) live elsewhere; this class is the
 * thin public bootstrap wired up by Mlsimport_Loader.
 *
 * @link       http://mlsimport.com/
 * @since      1.0.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/public
 * @author     MlsImport <office@mlsimport.com>
 */
class Mlsimport_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string $plugin_name       The name of the plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		// Stash the plugin slug (used as the enqueue handle base).
		$this->plugin_name = $plugin_name;
		// Stash the current version (used for cache-busting the public script).
		$this->version     = $version;
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * Enqueues the base public stylesheet and, when applicable, a theme-specific
	 * override sheet named after the active theme environment (e.g. residence).
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function enqueue_styles() {
				// Pull the active theme environment slug from the plugin core singleton.
				global $mlsimport;
				$theme_enviroment = $mlsimport->get_plugin_data( 'theme_enviroment' );
		// Always enqueue the base public stylesheet.
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/mlsimport-public.css', array(), MLSIMPORT_VERSION, 'all' );

		// When an environment override is flagged, enqueue the theme-specific sheet
		// (mlsimport-public-<theme>.css). Note: $options is not defined in this scope.
		if ( isset( $options['enviroment'] ) ) {
			wp_enqueue_style( $this->plugin_name . strtolower( $theme_enviroment ), plugin_dir_url( __FILE__ ) . 'css/mlsimport-public-' . strtolower( $theme_enviroment ) . '.css', array(), MLSIMPORT_VERSION, 'all' );
		}
	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function enqueue_scripts() {
			// Enqueue the public script (depends on jQuery), versioned for cache-busting.
			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/mlsimport-public.js', array( 'jquery' ), $this->version, false );
	}


	/**
	 * ReWrite Image url
	 *
	 * Filter callback (wp_get_attachment_url): for attachments imported by MLSImport
	 * it returns the path portion after '/wp-content/uploads/' instead of the full URL;
	 * all other attachments pass through unchanged.
	 *
	 * @since    1.0.0
	 * @param    string $url      The attachment URL WordPress resolved.
	 * @param    int    $post_id  The attachment post ID.
	 * @return   string           The rewritten path for MLS imports, else the original URL.
	 */
	public function mlsimport_wp_get_attachment_url( $url, $post_id ) {


		// Only rewrite attachments tagged as MLS imports (is_mlsimport meta === 1).
		if( intval(get_post_meta($post_id,'is_mlsimport',true)) === 1){

			// Split on the uploads base and return the trailing path segment.
			$explode = explode('/wp-content/uploads/', $url);
			return $explode[1];
		  
		}

		// Non-MLS attachments keep their original URL untouched.
		return $url;
	}


}
