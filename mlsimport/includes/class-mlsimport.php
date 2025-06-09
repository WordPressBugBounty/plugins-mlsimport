<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       http://mlsimport.com/
 * @since      1.0.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 * @author     MlsImport <office@mlsimport.com>
 */
class Mlsimport {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Mlsimport_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */

	/**
	 * Store plugin admin class to allow public access.
	 *
	 * @since    20180622
	 * @var object      The admin class.
	 */
	public $admin;

	/**
	 * Store plugin public class to allow public access.
	 *
	 * @since    20180622
	 * @var object      The admin class.
	 */
	public $public;

	public function __construct() {
		if ( defined( 'MLSIMPORT_VERSION' ) ) {
			$this->version = MLSIMPORT_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'mlsimport';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Mlsimport_Loader. Orchestrates the hooks of the plugin.
	 * - Mlsimport_i18n. Defines internationalization functionality.
	 * - Mlsimport_Admin. Defines all hooks for the admin area.
	 * - Mlsimport_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-mlsimport-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-mlsimport-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-mlsimport-admin.php';

		/**
		 * The class responsible for custom post type
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-mlsimport-item.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'public/class-mlsimport-public.php';

		$this->loader = new Mlsimport_Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Mlsimport_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Mlsimport_i18n();

                // Load translations at `init` to ensure the locale is fully set.
                // Loading earlier can trigger WordPress notices starting WP 6.7.
                $this->loader->add_action( 'init', $plugin_i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$this->admin = $plugin_admin = new Mlsimport_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->admin->admin_setup( $this->get_plugin_name(), $this->get_plugin_data( 'mls_enviroment' ), $this->get_plugin_data( 'theme_enviroment' ) );

		$this->loader->add_action( 'admin_enqueue_scripts', $this->admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $this->admin, 'enqueue_scripts' );

		$plugin_post_types = new Mlsimport_Item();
		$this->loader->add_action( 'init', $plugin_post_types, 'create_custom_post_type', 999 );

		// save and render metaboxed
		$this->loader->add_action( 'admin_init', $plugin_admin, 'mlsimport_item_product_metaboxes' );
		$this->loader->add_action( 'save_post', $plugin_admin, 'mlsimport_item_product_save_metaboxes', 1, 2 );

		// Save/Update our plugin options
		$this->loader->add_action( 'admin_init', $this->admin, 'options_update' );
		$this->loader->add_action( 'update_option_' . $this->plugin_name . '_admin_fields_select', $this->admin, 'update_option_mlsimport_admin_fields_select' );
		$this->loader->add_action( 'add_option_' . $this->plugin_name . '_admin_fields_select', $this->admin, 'update_option_mlsimport_admin_fields_select' );

		$this->loader->add_action( 'update_option_' . $this->plugin_name . '_administrative_options', $this->admin, 'update_option_mlsimport_administrative_options' );

		// Add menu item
		$this->loader->add_action( 'admin_menu', $this->admin, 'add_plugin_admin_menu' );

		// Add Settings link to the plugin list
		$plugin_basename = plugin_basename( plugin_dir_path( __DIR__ ) . $this->plugin_name . '.php' );
		$this->loader->add_filter( 'plugin_action_links_' . $plugin_basename, $this->admin, 'add_action_links' );

		$this->loader->add_action( 'admin_init', $this->admin, 'mlsimport_meta_options' );

		$this->loader->add_action( 'wp_ajax_mlsimport_move_files_per_item', $this->admin, 'mlsimport_move_files_per_item' );
		$this->loader->add_action( 'wp_ajax_mlsimport_stop_import_per_item', $this->admin, 'mlsimport_stop_import_per_item' );
		$this->loader->add_action( 'wp_ajax_mlsimport_saas_get_metadata_function', $this->admin, 'mlsimport_saas_get_metadata_function' );

		$this->loader->add_action( 'mlsimport_background_process_per_item', $this->admin, 'mlsimport_background_process_per_item_function', 10, 1 );
		$this->loader->add_action( 'mlsimport_background_process_per_item_inital_batch', $this->admin, 'mlsimport_background_process_per_item_inital_batch_function', 10, 1 );

		$this->loader->add_action( 'wp_ajax_mlsimport_logger_per_item', $this->admin, 'mlsimport_logger_per_item' );
		$this->loader->add_action( 'wp_ajax_mlsimport_move_files', $this->admin, 'mlsimport_move_files' );
		$this->loader->add_action( 'wp_ajax_mlsimport_move_files_to_aws_logger', $this->admin, 'mlsimport_move_files_to_aws_logger' );
                $this->loader->add_action( 'wp_ajax_mlsimport_stop_moving_files', $this->admin, 'mlsimport_stop_moving_files' );
                $this->loader->add_action( 'wp_ajax_mlsimport_delete_cache', $this->admin, 'mlsimport_delete_cache' );
                $this->loader->add_action( 'wp_ajax_mlsimport_clear_fields_data', $this->admin, 'mlsimport_clear_fields_data' );
                $this->loader->add_action( 'wp_ajax_mlsimport_delete_properties', $this->admin, 'mlsimport_delete_properties' );
        }

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Mlsimport_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
		$this->loader->add_filter( 'wp_get_attachment_url', $plugin_public, 'mlsimport_wp_get_attachment_url', 99, 2 );


	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Mlsimport_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * return plugin shema
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function return_plugin_schema() {

		return '';
	}

	/**
	 * return plugin data
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function get_plugin_data( $what ) {
		$plugin_data = $this->return_plugin_schema();

		if ( isset( $plugin_data['mls_enviroment'] ) && 'TresleReso' ===  $plugin_data['mls_enviroment']  &&  'server_token' === $what   ) {
			$tresle_token = $this->return_tresle_token();
			return $tresle_token;
		}

		if ( isset( $plugin_data[ $what ] ) ) {
			return $plugin_data[ $what ];
		} else {
			return '';
		}
	}
}
