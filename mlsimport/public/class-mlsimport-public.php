<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 *
 *
 * @link       http://mlsimport.com/
 * @since      1.0.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/public
 */

/**
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

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
				global $mlsimport;
				$theme_enviroment = $mlsimport->get_plugin_data( 'theme_enviroment' );
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/mlsimport-public.css', array(), MLSIMPORT_VERSION, 'all' );

		if ( isset( $options['enviroment'] ) ) {
			wp_enqueue_style( $this->plugin_name . strtolower( $theme_enviroment ), plugin_dir_url( __FILE__ ) . 'css/mlsimport-public-' . strtolower( $theme_enviroment ) . '.css', array(), MLSIMPORT_VERSION, 'all' );
		}
	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {
			wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/mlsimport-public.js', array( 'jquery' ), $this->version, false );
	}


	/**
	 * ReWrite Image url
	 *
	 * @since    1.0.0
	 */
	public function mlsimport_wp_get_attachment_url( $url, $post_id ) {


		if( intval(get_post_meta($post_id,'is_mlsimport',true)) === 1){
			
			$explode = explode('/wp-content/uploads/', $url);
			return $explode[1];
		  
		}

		return $url;
	}


}
