<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * The admin-specific functionality of the plugin.
 *
 * @link       http://mlsimport.com/
 * @since      1.0.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/admin
 */


/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/admin
 * @author     MlsImport <office@mlsimport.com>
 */
class Mlsimport_Admin {

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
	public $main;
	public $theme_importer;
	public $env_data;
	public $mls_env_data;
	protected $process_all;
	public $field_import;
    public $themes;
	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string $plugin_name       The name of this plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		$this->field_import = array(
			'City',
			'CountyOrParish',
			'MlsStatus',
			'PropertySubType',
			'PropertyType',
			'StandardStatus',
			'InternetEntireListingDisplayYN',
			'InternetAddressDisplayYN',
		);

		$this->themes = array(
			991 => 'WpResidence',
			992 => 'Houzez',
			993 => 'RealHomes',
			994 => 'Wpestate',
		);
	}
	/**
	 *
	 *
	 *
	 * Admin Setup
	 *
	 * @since    1.0.0
	 */
	public function admin_setup( $plugin_name, $mls_enviroment, $theme_enviroment ) {

		$options  = get_option( $this->plugin_name . '_admin_options' );
		$theme_id = 0;
		if ( isset( $options['mlsimport_theme_used'] ) ) {
			$theme_id = intval( $options['mlsimport_theme_used'] );
		}
		$themes = $this->themes;

		$theme_enviroment = '';
		if ( isset( $themes[ $theme_id ] ) ) {
			$theme_enviroment = $themes[ $theme_id ];
		}

		$this->theme_importer = new ThemeImport( $plugin_name );

		$options_api = get_option( $this->plugin_name . '_admin_options' );

		if ( '' !== $theme_enviroment  ) {
			$classname      = str_replace('Wp','',$theme_enviroment ). 'Class';
			$this->env_data = new $classname();
		} else {
			$this->env_data = new stdClass();
		}

		if ( '' !== $mls_enviroment   ) {
			$mls_classname = $mls_enviroment . 'Class';

			$this->mls_env_data = new $mls_classname( $this->theme_importer );
		} else {
			$this->mls_env_data = new stdClass();
		}
	}

	/**
	 *
	 *
	 *
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/mlsimport-admin.css', array(), MLSIMPORT_VERSION, 'all' );
		wp_enqueue_style( 'mlsimport-onboarding', plugin_dir_url( __FILE__ ) . 'css/mlsimport-onboarding.css', array(), MLSIMPORT_VERSION, 'all' );
		wp_enqueue_style( 'mlsimport-field-selector', plugin_dir_url( __FILE__ ) . 'css/mlsimport-field-selector.css', array(), MLSIMPORT_VERSION, 'all' );
	}




	/**
	 *
	 *
	 *
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts($hook_suffix) {
		wp_enqueue_script( 'jquery-ui-autocomplete' );
		$mls_import_list = mlsimport_saas_request_list();
		wp_enqueue_script( 'mlsimport-admin', plugin_dir_url( __FILE__ ) . 'js/mlsimport-admin.js', array( 'jquery' ), $this->version, true );
		wp_localize_script(
			'mlsimport-admin',
			'mlsimport_vars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' )
			)
		);
	
	 	wp_enqueue_script( 'mlsimport-field-selector', plugin_dir_url( ( __FILE__ ) ) . 'js/mlsimport-field-selector.js', array( 'jquery', 'jquery-ui-sortable', 'jquery-ui-tooltip' ), '1.0.0', true );
        
        // Pass AJAX parameters to script
        wp_localize_script( 'mlsimport-field-selector', 'mlsimport_params', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'mlsimport_field_selector_nonce' )
        ));


		wp_enqueue_script( 'mlsimport-progressive-save', plugin_dir_url( ( __FILE__ ) ) . 'js/progressive-save.js', array('mlsimport-field-selector' ), '1.0.0', true );
        


		if ('toplevel_page_mlsimport_plugin_options' === $hook_suffix && 
			isset($_GET['page']) && $_GET['page'] === 'mlsimport_plugin_options' && 
			isset($_GET['tab']) && $_GET['tab'] === 'field_options') {
			$mlsimport_mls_metadata_populated = get_option( 'mlsimport_mls_metadata_populated', '' );
			if ( 'yes' !==  $mlsimport_mls_metadata_populated  ) {
				$inline_script = 'jQuery(document).ready(function($){ mlsimport_saas_get_metadata(); });';
				wp_add_inline_script('mlsimport-admin', $inline_script);
			}
		}

		if (
			'admin_page_mlsimport-onboarding' === $hook_suffix &&
			isset($_GET['page']) && $_GET['page'] === 'mlsimport-onboarding'
		) {
			$mlsimport_mls_metadata_populated = get_option('mlsimport_mls_metadata_populated', '');
			if ('yes' !== $mlsimport_mls_metadata_populated) {
				$inline_script = 'jQuery(document).ready(function($){  mlsimport_saas_get_metadata(); });';
				wp_add_inline_script('mlsimport-admin', $inline_script);
			}
		}

		


		if ('toplevel_page_mlsimport_plugin_options' === $hook_suffix && 
			( isset($_GET['page']) && $_GET['page'] === 'mlsimport_plugin_options' && isset($_GET['tab']) && $_GET['tab'] === 'display_options') ||
			(isset($_GET['page']) && $_GET['page'] === 'mlsimport_plugin_options'  && !isset($_GET['tab']) ) ) {
			
				$mls_import_list = mlsimport_saas_request_list();
				if(!is_array($mls_import_list)){
					$inline_script = 'jQuery(document).ready(function($){ var autofill='.wp_kses_post($mls_import_list).';mlsimport_autocomplte_mls_selection(autofill);  });';
					wp_add_inline_script('mlsimport-admin', $inline_script);
				}
		}

	}





	/**
	 *
	 *
	 *
	 * Register the administration menu for this plugin into the WordPress Dashboard menu.
	 *
	 * @since    1.0.0
	 */
	public function add_plugin_admin_menu() {
		add_menu_page(
			esc_html__( 'MLS Import Settings', 'mlsimport'),
			esc_html__( 'MLS Import Settings', 'mlsimport' ),
			'administrator',
			'mlsimport_plugin_options',
			array( $this, 'display_plugin_setup_page' ),
			MLSIMPORT_PLUGIN_URL . '/img/mlsimport_menu.png',
			22
		);
	}








	/**
	 *
	 *
	 *
	 * Add settings action link to the plugins page.
	 *
	 * @since    1.0.0
	 */
	public function add_action_links( $links ) {
		$settings_link = array(
			'<a href="' . admin_url( 'admin.php?page=mlsimport_plugin_options' ) . '">' . esc_html__( 'Settings', 'mlsimport') . '</a>',
		);
		return array_merge( $settings_link, $links );
	}








	/**
	 *
	 *
	 * Render the settings page for this plugin.
	 *
	 * @since    1.0.0
	 */
	public function display_plugin_setup_page() {
		include_once 'partials/' . $this->plugin_name . '-admin-display.php';
	}






	/**
	 *
	 *
	 * Validate plugin options fields
	 *
	 * @since    1.0.0
	 */
	public function validate_admin_options( $input ) {

		$valid         = array();
		$settings_list = array(
			'auth_username'                     => array(
				'name'    => esc_html__( 'Api auth_username ', 'mlsimport' ),
				'details' => 'to be added',
			),
			'auth_password'                     => array(
				'name'    => esc_html__( 'Api auth_password', 'mlsimport' ),
				'details' => 'to be added',
			),
			'client_id'                         => array(
				'name'    => esc_html__( 'Api client_id', 'mlsimport' ),
				'details' => 'to be added',
			),
			'client_secret'                     => array(
				'name'    => esc_html__( 'client_secret', 'mlsimport' ),
				'details' => 'to be added',
			),
			'redirect_uri'                      => array(
				'name'    => esc_html__( 'redirect_uri', 'mlsimport' ),
				'details' => 'to be added',
			),
			'title_format'                      => array(
				'name'    => esc_html__( 'title_format', 'mlsimport' ),
				'details' => 'to be added',
			),
			'force_rand'                        => array(
				'name'    => esc_html__( 'title_format', 'mlsimport' ),
				'details' => 'to be added',
			),
			'mlsimport_username'                => array(
				'name'    => esc_html__( 'MLSImport.com Username (not your email)', 'mlsimport' ),
				'details' => 'to be added',
			),
			'mlsimport_password'                => array(
				'name'    => esc_html__( 'MLSImport.com Password', 'mlsimport' ),
				'details' => 'to be added',
			),
			'mlsimport_mls_name'                => array(
				'name'    => esc_html__( 'MLSImport Name', 'mlsimport' ),
				'details' => 'to be added',
			),
			'mlsimport_mls_token'               => array(
				'name'    => esc_html__( 'MLSImport Token', 'mlsimport' ),
				'details' => 'to be added',
			),

			'mlsimport_tresle_client_id'        => array(
				'name'    => esc_html__( 'MLSImport Tresle Client id', 'mlsimport' ),
				'details' => 'to be added',
			),

                        'mlsimport_tresle_client_secret'    => array(
                                'name'    => esc_html__( 'MLSImport Client Secret', 'mlsimport' ),
                                'details' => 'to be added',
                        ),

                        'mlsimport_connectmls_username'     => array(
                                'name'    => esc_html__( 'MLSImport ConnectMLS Username', 'mlsimport' ),
                                'details' => 'to be added',
                        ),

                        'mlsimport_connectmls_password'     => array(
                                'name'    => esc_html__( 'MLSImport ConnectMLS Password', 'mlsimport' ),
                                'details' => 'to be added',
                        ),

                        'mlsimport_rapattoni_client_id'     => array(
                                'name'    => esc_html__( 'MLSImport Rapattoni Client id','mlsimport'),
                                'details' => 'to be added',
                        ),

			'mlsimport_rapattoni_client_secret' => array(
				'name'    => esc_html__( 'MLSImport Rapattoni Secret', 'mlsimport' ),
				'details' => 'to be added',
			),

			'mlsimport_rapattoni_username'      => array(
				'name'    => esc_html__( 'MLSImport Rapattoni Username', 'mlsimport' ),
				'details' => 'to be added',
			),

			'mlsimport_rapattoni_password'      => array(
				'name'    => esc_html__( 'MLSImport Rapattoni Password', 'mlsimport' ),
				'details' => 'to be added',
			),

			'mlsimport_paragon_client_id'       => array(
				'name'    => esc_html__( 'MLSImport Paragon Client id','mlsimport' ),
				'details' => 'to be added',
			),

			'mlsimport_paragon_client_secret'   => array(
				'name'    => esc_html__( 'MLSImport Paragon Secret', 'mlsimport' ),
				'details' => 'to be added',
			),
			'mlsimport_realtorca_client_id'       => array(
				'name'    => esc_html__( 'MLSImport Realtor.ca Client id','mlsimport' ),
				'details' => 'to be added',
			),

			'mlsimport_realtorca_client_secret'   => array(
				'name'    => esc_html__( 'MLSImport Realtor.ca Secret', 'mlsimport' ),
				'details' => 'to be added',
			),

			'mlsimport_theme_used'              => array(
				'name'    => esc_html__( 'Your Wordpress Theme', 'mlsimport' ),
				'details' => 'to be added',
			),
			'mlsimport_mls_name_front'          => array(
				'name'    => '',
				'details' => 'to be added',
			),
			'mlsimport-disable-logs'            => array(
				'name'    => '',
				'details' => 'to be added',
			),
		);

		foreach ( $settings_list as $key => $setting ) {
			$valid[ $key ] = ( isset( $input[ $key ] ) && ! empty( $input[ $key ] ) ) ? esc_attr( $input[ $key ] ) : '';
		}

		delete_option( 'mlsimport_connection_test' );
		delete_option( 'mlsimport_mls_metadata_populated' );

		update_option( 'mlsimport_encoding_array', '' );
		delete_transient( 'mlsimport_token_request' );
		delete_transient( 'mlsimport_schema' );
		delete_transient( 'mlsimport_plugin_data_schema' );

		delete_transient( 'mlsimport_saas_token' );
		return $valid;
	}





	/**
	 *
	 *
	 * Validate admin fields
	 *
	 * @since    1.0.0
	 */
	public function validate_admin_fields_select( $input ) {
		$valid = array();

		$mlsimport_mls_metadata_mls_data = get_option( 'mlsimport_mls_metadata_mls_data', '' );
		$metadata_api_call               = json_decode( $mlsimport_mls_metadata_mls_data, true );

		foreach ( $metadata_api_call as $key => $value ) {
			if ( isset( $input['mls-fields'][ $key ] ) ) {
				$valid['mls-fields'][ $key ] = esc_attr( $input['mls-fields'][ $key ] );
			}

			if ( isset( $input['mls-fields-admin'][ $key ] ) ) {
				$valid['mls-fields-admin'][ $key ] = esc_attr( $input['mls-fields-admin'][ $key ] );
				$valid['mls-fields-label'][ $key ] = esc_attr( $input['mls-fields-label'][ $key ] );
				$valid['mls-fields-map-postmeta'][ $key ] = esc_attr( $input['mls-fields-map-postmeta'][ $key ] );
				$valid['mls-fields-map-taxonomy'][ $key ] = esc_attr( $input['mls-fields-map-taxonomy'][ $key ] );
				$valid['field_order'][ $key ] = esc_attr( $input['field_order'][ $key ] );
			}
		}
		//$valid['mls-fields-admin']['force_rand'] = esc_attr( $input['mls-fields-admin']['force_rand'] );
		return $valid;
	}

	/**
	 *
	 *
	 * Validate Mls Sync fields
	 *
	 * @since    1.0.0
	 */
	public function validate_admin_mls_sync( $input ) {
		$valid = array();

		$field_import = array( 'force_rand', 'min_price', 'max_price', 'title_format', 'property_agent', 'property_user', 'City', 'City_check', 'CountyOrParish', 'CountyOrParish_check', 'MlsStatus', 'MlsStatus_check', 'PropertySubType', 'PropertySubType_check', 'PropertyType', 'PropertyType_check', 
		'StandardStatus_delete', 'StandardStatus_delete_check', 'InternetEntireListingDisplayYN', 'InternetAddressDisplayYN' );
		foreach ( $field_import as $key ) {
			$valid[ $key ] = $input[ $key ];
		}

		return $valid;
	}


	/**
	 *
	 *
	 * Validate Administrative options
	 *
	 * @since    1.0.0
	 */
	public function validate_administrative_options( $input ) {

		$valid = array();

		$field_import = array( 'import' );
		foreach ( $field_import as $key ) {
			$valid[ $key ] = $input[ $key ];
		}

		return $valid;
	}

	/**
	 *
	 *
	 *
	 * Validate Import Options fields
	 *
	 * @since    1.0.0
	 */
	public function validate_admin_import_options( $input ) {
		$valid = array();

		$field_import = array( 'import_number' );
		foreach ( $field_import as $key ) {
			$valid[ $key ] = intval( $input[ $key ] );
		}

		if ( isset( $input['import'] ) &&  '' !==  $input['import'] ) {
			$decode = json_decode( $input['import'] );
			update_option( 'mlsimport_admin_fields_select', $decode['mlsimport_admin_fields_select'] );
			update_option( 'mlsimport_admin_mls_sync', $decode['mlsimport_admin_mls_sync'] );
			update_option( 'mlsimport_admin_import_options', $decode['mlsimport_admin_import_options'] );
			update_option( 'mlsimport_admin_use_transients', $decode['mlsimport_admin_use_transients'] );
		}

		return $valid;
	}






	/**
	 *
	 *
	 * plugin options update
	 */
	public function options_update() {
		register_setting( $this->plugin_name . '_admin_options', $this->plugin_name . '_admin_options', array( $this, 'validate_admin_options' ) );
		register_setting( $this->plugin_name . '_admin_fields_select', $this->plugin_name . '_admin_fields_select', array( $this, 'validate_admin_fields_select' ) );
		register_setting( $this->plugin_name . '_admin_mls_sync', $this->plugin_name . '_admin_mls_sync', array( $this, 'validate_admin_mls_sync' ) );
		register_setting( $this->plugin_name . '_admin_import_options', $this->plugin_name . '_admin_import_options', array( $this, 'validate_admin_import_options' ) );
		register_setting( $this->plugin_name . '_administrative_options', $this->plugin_name . '_administrative_options', array( $this, 'validate_administrative_options' ) );
	}



	/**
	 *
	 *
	 *
	 *
	 *
	 *
	 *
	 */
	public function update_option_mlsimport_administrative_options() {
		$import = get_option( 'mlsimport_administrative_options' );
		if ( '' !==  $import  ) {
			$decode = json_decode( $import['import'], true );
			update_option( 'mlsimport_admin_fields_select', $decode['mlsimport_admin_fields_select'] );
			update_option( 'mlsimport_admin_mls_sync', $decode['mlsimport_admin_mls_sync'] );
			update_option( 'mlsimport_admin_import_options', $decode['mlsimport_admin_import_options'] );
		}
	}

	/**
	 *
	 *
	 * plugin options update
	 */
	public function update_option_mlsimport_admin_fields_select() {

		$this->env_data->enviroment_custom_fields( $this->plugin_name );
	}


	/**
	 *
	 *
	 * plugin options update
	 */
	public function mlsimport_meta_options() {
		if ( method_exists( $this->env_data, 'get_property_post_type' ) ) {
			add_meta_box( 'mlsimport_hidden_fields', esc_html__( 'Mls Import Hidden Fields', 'mlsimport' ), array( $this, 'mlsimport_hidden_fields' ), $this->env_data->get_property_post_type(), 'normal', 'low' );
		}
	}

	/**
	 *
	 *
	 * plugin options update
	 */
	public function mlsimport_hidden_fields() {
		global $post;

		$options = get_option( $this->plugin_name . '_admin_fields_select' );

		$MLSimport_item_inserted = get_post_meta( $post->ID, 'MLSimport_item_inserted', true );
		$MLSimport_item_updated = get_post_meta( $post->ID, 'MLSimport_item_updated', true );
		$listing_key = get_post_meta( $post->ID, 'ListingKey', true );
		$mlsImportItemStatusDelete = get_post_meta($post->ID, 'mlsImportItemStatusDelete', true);



		// Check if the ListingKey exists
		if ( !empty( $listing_key ) ) {
			echo 'ListingKey: ' . $listing_key . '<br>';
		}

		// Check if MLSimport_item_inserted exists
		if ( !empty( $MLSimport_item_inserted ) ) {
			echo 'Added via MLS item id: ' . $MLSimport_item_inserted . ' - ' . get_the_title( $MLSimport_item_inserted ) . '<br>';
		}

		// Check if MLSimport_item_updated exists
		if ( !empty( $MLSimport_item_updated ) ) {
			echo 'Updated via MLS item id: ' . $MLSimport_item_updated . ' - ' . get_the_title( $MLSimport_item_updated ) . '<br>';
		}


		if(!empty($mlsImportItemStatusDelete)) {
			if(is_array($mlsImportItemStatusDelete)) {
				
				echo 'When not in MLS Delete if status: ' .  implode(',' ,$mlsImportItemStatusDelete) . '<br>';
			} else {

				echo 'When not in MLS if status: ' .  esc_html($mlsImportItemStatusDelete) . '<br>';

			}
			
		}

		foreach ( $options['mls-fields-admin'] as $key => $value ) {
			if ( 1 === intval($options['mls-fields-admin'][ $key ] ) ) {
				if ( isset( $options['mls-fields-label'][ $key ] ) &&  '' !== $options['mls-fields-label'][ $key ] ) {
					$key = $options['mls-fields-label'][ $key ];
				}

				if ( 'ListingKey' !== $key   ) {
					$meta_key = strtolower( $key );
				} else {
					$meta_key = $key;
				}
				?>

				<strong><?php echo esc_html($key);?>:</strong>
				<?php echo  esc_html( get_post_meta( $post->ID, $meta_key, true ) ); ?> </br>
				<?php
			}
		}
		?>
		
		<h2 style="font-weight:bold;padding-left:0px;">Mls Import History</h2>
		<?php 
		$meta = get_post_meta( $post->ID, 'mlsimport_property_history', true );
		if ( '' === trim( $meta )  ) { ?>
			<strong>Property history is blank - you can enable it in Settings/ Tools page </strong>
		<?php
		} else {
			print wp_kses_post($meta);
		}
	}




	/**
	 * delete cache
	 */
        function mlsimport_delete_cache() {

		check_ajax_referer( 'mlsimport_tool_actions', 'security' );

		delete_transient( 'mlsimport_token_request' );
		delete_transient( 'mlsimport_metadata_api_call_data_service_property' );
		delete_transient( 'mls_import_meta_enums' );
		delete_transient( 'mls_import_meta' );
		delete_transient( 'mlsimport_plugin_data_schema' );
		delete_transient( 'mlsimport_ready_to_go_mlsimport_data' );
		delete_transient( 'mlsimport_saas_token' );

                delete_option( 'mlsimport_mls_metadata_populated' );

                die( 'deleted' );
        }

        /**
         * clear fields data
         */
        function mlsimport_clear_fields_data() {

                check_ajax_referer( 'mlsimport_tool_actions', 'security' );

                delete_option( 'mlsimport_mls_metadata_populated' );
                delete_option( 'mlsimport_admin_fields_select' );

                die( 'deleted' );
        }

	/**
	 *
	 *
	 *
	 *
	 * delete properties
	 */
	function mlsimport_delete_properties() {
		$error = false;
		global $mlsimport;

		check_ajax_referer( 'mlsimport_tool_actions', 'security' );


		if ( current_user_can( 'administrator' ) ) :
			$mlsimport_delete_category      = sanitize_text_field( wp_unslash( $_POST['mlsimport_delete_category'] ) ) ;
			$mlsimport_delete_category_term = sanitize_title( sanitize_text_field( wp_unslash( $_POST['mlsimport_delete_category_term']) ) );
			$mlsimport_delete_timeout       = intval( $_POST['mlsimport_delete_timeout'] );

			if ( '' ===  $mlsimport_delete_category  ) {
				$error_message = esc_html__('Category cannot be blank','mlsimport');
				$error         = true;
			}

			if ( '' ===  $mlsimport_delete_category_term  ) {
				$error_message = esc_html__('Category Term cannot be blank','mlsimport');
				$error         = true;
			}

			$category = get_term_by( 'slug', $mlsimport_delete_category_term, $mlsimport_delete_category );

			if ( $error ) {
				print wp_json_encode(
					array(
						'message' => esc_html($error_message),
					)
				);
			} else {
				$mlsimport_delete_category_term_array = array();

				$mlsimport_delete_category_term_array[] = $mlsimport_delete_category_term;
				$tax_array                              = array(
					'taxonomy' => $mlsimport_delete_category,
					'field'    => 'slug',
					'terms'    => $mlsimport_delete_category_term_array,
				);

				$args = array(
					'post_type'      => array( 'estate_property', 'property' ),
					'post_status'    => 'any',
					'paged'          => 1,
					'posts_per_page' => -1,
					'tax_query'      => array(
						$tax_array,
					),
					'fields'         => 'ids',
				);

				$prop_selection = new WP_Query( $args );

				foreach ( $prop_selection->posts as $key => $delete_get_id ) {
					if ( 0 !== $mlsimport_delete_timeout  ) {
						set_timeout( $mlsimport_delete_timeout );
					}

					$mlsimport->admin->theme_importer->mlsimportSaasDeletePropertyViaMysql( $delete_get_id, ' delete from tools ' );
				}


		
				



				wp_update_term_count_now( array( $category->term_id ), $mlsimport_delete_category );

				print wp_json_encode(
					array(
						'$category' => $category->term_id,
						'arguments' => $args,
						'posts'     => $prop_selection->posts,
						'found'     => $prop_selection->found_posts,
						'message'   => 'Done...',
					)
				);
			}
		endif;
		die();
	}








	/**
	 *
	 *
	 *  Testing enviroment variables
	 */
	public function mlsimport_saas_setting_up() {
			// Do not output warnings during AJAX requests
			if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ||
					( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
					return;
			}
			
			$is_onboarding = isset( $_GET['page'] ) && 'mlsimport-onboarding' === $_GET['page']; 
			if ( ! $is_onboarding && intval( WP_MEMORY_LIMIT ) < 256 ) : 
				if (intval(WP_MEMORY_LIMIT) < 256){ ?>
						<div class="mlsimport_warning long_warning">
								<strong>WordPress Memory Limit</strong> is set to <strong><?php echo esc_html(WP_MEMORY_LIMIT); ?></strong>. Allocated Memory should be at least <strong>256MB</strong>. Please refer to: <a href="https://wordpress.org/support/article/editing-wp-config-php/#increasing-memory-allocated-to-php" target="_blank">Increasing memory allocated to PHP</a>
						</div>						
				<?php 	
				}
			
			$max_time = ini_get('max_execution_time');
			if ($max_time < 600 && 0 !== $max_time){
			?>
				<div class="mlsimport_warning long_warning">Your <strong>max_execution_time</strong> setting in php is set to <strong><?php echo esc_html($max_time); ?></strong>. Importing hundreds of listings requires extra time. Please set max_execution_time to <strong>0 (unlimited)</strong>. If that is not possible, set it to a minimum of <strong>600 (10 minutes)</strong>.</div>
			
			<?php
			}


			endif; // emd pn boardingin check
			
	}

        /**
         * Check if token validates with MLS
         *
         * @since    4.0.1
         * returns token fron mlsimport
         */
	public function mlsimport_saas_check_mls_connection() {

		$values  = array();
		$options = get_option( $this->plugin_name . '_admin_options' );

		$mls_id = '';
                if ( isset( $options['mlsimport_mls_name'] ) ) {
                        $mls_id = sanitize_text_field( trim( $options['mlsimport_mls_name'] ) );
                }

                $mls_token = '';
                if ( isset( $options['mlsimport_mls_name'] ) ) {
                        $mls_token = sanitize_text_field( trim( $options['mlsimport_mls_token'] ) );
                }

                $mls_id_int = intval( $mls_id );

		$mlsimport_tresle_client_id = '';
		if ( isset( $options['mlsimport_tresle_client_id'] ) ) {
			$mlsimport_tresle_client_id = sanitize_text_field( trim( $options['mlsimport_tresle_client_id'] ) );
		}

                $mlsimport_tresle_client_secret = '';
                if ( isset( $options['mlsimport_tresle_client_secret'] ) ) {
                        $mlsimport_tresle_client_secret = sanitize_text_field( trim( $options['mlsimport_tresle_client_secret'] ) );
                }

                $mlsimport_connectmls_username = '';
                if ( isset( $options['mlsimport_connectmls_username'] ) ) {
                        $mlsimport_connectmls_username = sanitize_text_field( trim( $options['mlsimport_connectmls_username'] ) );
                }

                $mlsimport_connectmls_password = '';
                if ( isset( $options['mlsimport_connectmls_password'] ) ) {
                        $mlsimport_connectmls_password = sanitize_text_field( trim( $options['mlsimport_connectmls_password'] ) );
                }

                // rapattoni data
                $mlsimport_rapattoni_client_id = '';
                if ( isset( $options['mlsimport_rapattoni_client_id'] ) ) {
                        $mlsimport_rapattoni_client_id = sanitize_text_field( trim( $options['mlsimport_rapattoni_client_id'] ) );
                }
		$mlsimport_rapattoni_client_secret = '';
		if ( isset( $options['mlsimport_rapattoni_client_secret'] ) ) {
			$mlsimport_rapattoni_client_secret = sanitize_text_field( trim( $options['mlsimport_rapattoni_client_secret'] ) );
		}

		$mlsimport_rapattoni_username = '';
		if ( isset( $options['mlsimport_rapattoni_username'] ) ) {
			$mlsimport_rapattoni_username = sanitize_text_field( trim( $options['mlsimport_rapattoni_username'] ) );
		}

		$mlsimport_rapattoni_password = '';
		if ( isset( $options['mlsimport_rapattoni_password'] ) ) {
			$mlsimport_rapattoni_password = sanitize_text_field( trim( $options['mlsimport_rapattoni_password'] ) );
		}

		// paragon data
		$mlsimport_paragon_client_id = '';
		if ( isset( $options['mlsimport_paragon_client_id'] ) ) {
			$mlsimport_paragon_client_id = sanitize_text_field( trim( $options['mlsimport_paragon_client_id'] ) );
		}
		$mlsimport_paragon_client_secret = '';
		if ( isset( $options['mlsimport_paragon_client_secret'] ) ) {
			$mlsimport_paragon_client_secret = sanitize_text_field( trim( $options['mlsimport_paragon_client_secret'] ) );
		}

		// realtor.ca data
		$mlsimport_realtorca_client_id = '';
		if ( isset( $options['mlsimport_realtorca_client_id'] ) ) {
			$mlsimport_realtorca_client_id = sanitize_text_field( trim( $options['mlsimport_realtorca_client_id'] ) );
		}
		$mlsimport_realtorca_client_secret = '';
		if ( isset( $options['mlsimport_realtorca_client_secret'] ) ) {
			$mlsimport_realtorca_client_secret = sanitize_text_field( trim( $options['mlsimport_realtorca_client_secret'] ) );
		}







                if ( trim( $mls_token ) === '' ) {
                        if ( $mls_id_int > 900 && $mls_id_int < 3000 ) { // Trestle
                                if ( trim( $mlsimport_tresle_client_id ) === '' || trim( $mlsimport_tresle_client_secret ) === '' ) {
                                        return;
                                }
                        } elseif ( $this->mlsimport_is_connectmls_provider( $mls_id_int ) ) { // ConnectMLS
                                if (
                                        trim( $mlsimport_connectmls_username ) === '' ||
                                        trim( $mlsimport_connectmls_password ) === ''
                                ) {
                                        return;
                                }
                        } elseif ( $mls_id_int >= 5000 && $mls_id_int < 6000 ) { // Rapattoni
                                if (
                                        trim( $mlsimport_rapattoni_client_id ) === '' ||
                                        trim( $mlsimport_rapattoni_client_secret ) === '' ||
                                        trim( $mlsimport_rapattoni_username ) === '' ||
                                        trim( $mlsimport_rapattoni_password ) === ''
                                ) {
                                        return;
                                }
                        } elseif ( $mls_id_int >= 6000 && $mls_id_int < 7000 ) { // Paragon
                                if (
                                        trim( $mlsimport_paragon_client_id ) === '' ||
                                        trim( $mlsimport_paragon_client_secret ) === ''
                                ) {
                                        return;
                                }
                        } elseif ( $mls_id_int >= 7000 && $mls_id_int < 8000 ) { // Realtor.ca
                                if (
                                        trim( $mlsimport_realtorca_client_id ) === '' ||
                                        trim( $mlsimport_realtorca_client_secret ) === ''
                                ) {
                                        return;
                                }
                        }
                }

                $values['mls_token']                      = $mls_token;
                $values['mls_id']                         = $mls_id;
                $values['mlsimport_tresle_client_id']     = $mlsimport_tresle_client_id;
                $values['mlsimport_tresle_client_secret'] = $mlsimport_tresle_client_secret;
                $values['mlsimport_connectmls_username']  = $mlsimport_connectmls_username;
                $values['mlsimport_connectmls_password']  = $mlsimport_connectmls_password;

                $values['mlsimport_rapattoni_client_id']     = $mlsimport_rapattoni_client_id;
                $values['mlsimport_rapattoni_client_secret'] = $mlsimport_rapattoni_client_secret;
                $values['mlsimport_rapattoni_username']      = $mlsimport_rapattoni_username;
                $values['mlsimport_rapattoni_password']      = $mlsimport_rapattoni_password;

		$values['mlsimport_paragon_client_id']     = $mlsimport_paragon_client_id;
		$values['mlsimport_paragon_client_secret'] = $mlsimport_paragon_client_secret;

		
                $values['mlsimport_realtorca_client_id']     = $mlsimport_realtorca_client_id;
                $values['mlsimport_realtorca_client_secret'] = $mlsimport_realtorca_client_secret;





  

		$answer = $this->theme_importer->globalApiRequestSaas( 'clients', $values, 'PATCH' );




		if ( isset( $answer['success'] ) && true ===  $answer['success']  ) {
			if ( isset( $answer['tested'] ) &&  true === $answer['tested'] ) {
				update_option( 'mlsimport_connection_test', 'yes' );
			} else {
				delete_option( 'mlsimport_connection_test' );
				delete_option( 'mlsimport_mls_metadata_populated' );
			}
		} else {
			delete_option( 'mlsimport_connection_test' );
			delete_option( 'mlsimport_mls_metadata_populated' );
		}

		return $answer;
	}

        private function mlsimport_is_connectmls_provider( $mls_id_int ) {
                return $mls_id_int >= 8000 && $mls_id_int < 9000;
        }






	/**
	 * Request auth token from mlsimport.net
	 *
	 * @since    4.0.1
	 * returns token fron mlsimport
	 */
	public function mlsimport_saas_get_mls_api_token_from_transient() {

		$token = get_transient( 'mlsimport_saas_token' );

		if ( false === $token || '' ===  $token  ) {
			$token_json_answer = $this->mlsimport_saas_get_mls_api_token();

			if ( isset( $token_json_answer['success'] ) && true ===  $token_json_answer['success']  ) {
				$token = $token_json_answer['token'];

				set_transient( 'mlsimport_saas_token', $token, 3500 );
			}
		}

		return $token;
	}


	/**
	 * call for token
	 *
	 * @since    4.0.1
	 * returns token fron mlsimport
	 */
	protected function mlsimport_saas_get_mls_api_token() {
		$values  = array();
		$options = get_option( $this->plugin_name . '_admin_options' );
 	
		// Check if the MLS provider has changed since the last run
		$prev_mls = get_option( 'mlsimport_prev_mls_name', '' );


		$username = '';
		if ( isset( $options['mlsimport_username'] ) ) {
			$username = sanitize_text_field( trim( $options['mlsimport_username'] ) );
		}

		$password = '';
		if ( isset( $options['mlsimport_password'] ) ) {
			$password = sanitize_text_field( trim( $options['mlsimport_password'] ) );
		}
		$mls_name = '';
		if ( isset( $options['mlsimport_mls_name'] ) ) {
			$mls_name = sanitize_text_field( trim( $options['mlsimport_mls_name'] ) );
		}

		$mls_token = '';
		if ( isset( $options['mlsimport_mls_token'] ) ) {
			$mls_token = sanitize_text_field( trim( $options['mlsimport_mls_token'] ) );
		}

		if ( $prev_mls !== '' && $prev_mls !== $mls_name ) {
			delete_transient( 'mlsimport_token_request' );
			delete_transient( 'mlsimport_metadata_api_call_data_service_property' );
			delete_transient( 'mls_import_meta_enums' );
			delete_transient( 'mls_import_meta' );
			delete_transient( 'mlsimport_plugin_data_schema' );
			delete_transient( 'mlsimport_ready_to_go_mlsimport_data' );
			delete_transient( 'mlsimport_saas_token' );

			delete_option( 'mlsimport_mls_metadata_populated' );

			delete_option( 'mlsimport_admin_fields_select' );
		}

		update_option( 'mlsimport_prev_mls_name', $mls_name );



		$values['username'] = $username;
		$values['password'] = $password;

		if ( '' ===  $username  || '' === $password ) {
			return '';
		}

		$theme_Start = new ThemeImport();
		$answer      = $theme_Start::globalApiRequestSaas( 'token', $values, 'POST' );

		

		return $answer;
	}






	/**
	 * save meta options
	 *
	 * @since    3.0.1
	 */
	public function mlsimport_item_product_metaboxes() {
		add_meta_box( 'mlsimport_item_metaboxes-sectionid', __( 'Set Import data', 'mlsimport' ), array( $this, 'mlsimport_saas_display_meta_options' ), 'mlsimport_item', 'normal', 'default' );
	}



	/**
	 *
	 *
	 *
	 * save meta options
	 *
	 * @since    3.0.1
	 */
	public function mlsimport_item_product_save_metaboxes( $post_id, $post ) {

		if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
			return;
		}

		if ( 'mlsimport_item' !==  $post->post_type  ) {
			return;
		}

		$allowed_keys = array(
			'mlsimport_item_how_many',
			'mlsimport_item_title_format',
			'mlsimport_item_agent',
			'mlsimport_item_property_status',
			'mlsimport_item_property_user',
			'mlsimport_item_min_price',
			'mlsimport_item_max_price',
			'mlsimport_item_city_check',
			'mlsimport_item_city',
			'mlsimport_item_city[]',
			'mlsimport_item_countyorparish_check',
			'mlsimport_item_countyorparish',
			'mlsimport_item_mlsstatus_check',
			'mlsimport_item_mlsstatus',
			'mlsimport_item_propertysubtype_check',
			'mlsimport_item_propertysubtype',
			'mlsimport_item_propertytype_check',
			'mlsimport_item_propertytype',
			'mlsimport_item_standardstatus_check',
			'mlsimport_item_standardstatus',
			'mlsimport_item_standardstatusdelete_check',
			'mlsimport_item_standardstatusdelete',

			'mlsimport_item_internetentirelistingdisplayyn',
			'mlsimport_item_internetaddressdisplayyn',
			'mlsimport_item_stat_cron',
                       'mlsimport_item_listagentkey',
                       'mlsimport_item_listagentmlsid',
                       'mlsimport_item_buyeragentmlsid',
                       'mlsimport_item_listofficekey',
                       'mlsimport_item_postalcode',
                       'mlsimport_item_listofficemlsid',
                       'mlsimport_item_listingid',
                       'mlsimport_item_extracity',
                       'mlsimport_item_extracounty',
                       'mlsimport_item_exclude_listofficemlsid',
                       'mlsimport_item_exclude_listofficekey',
                       'mlsimport_item_exclude_listagentmlsid',
                       'mlsimport_item_exclude_listagentkey',
                       'mlsimport_item_mlsareamajor',
                       'mlsimport_item_subdivisionname',
               );
	



		foreach ( $allowed_keys as $key => $key_value ) {
				if( isset($_POST[$key_value]) ){
					$postmeta = mlsimport_sanitize_multi_dimensional_array ( $_POST[$key_value] ) ;
					update_post_meta( $post_id, sanitize_key( $key_value ), $postmeta );
				}

		}

		$blank_keys = array(
			'mlsimport_item_standardstatus',
			'mlsimport_item_city',
			'mlsimport_item_countyorparish',
			'mlsimport_item_propertysubtype',
                        'mlsimport_item_propertytype',
                        'mlsimport_item_standardstatus',
                        'mlsimport_item_listingid',
                        'mlsimport_item_mlsareamajor',
                        'mlsimport_item_subdivisionname',

		);

		foreach ( $blank_keys as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, '' );
			}
		}

	
	}


	/**
	 * Display Meta Options
	 *
	 * @param WP_Post $post The post object.
	 */
        public function mlsimport_saas_display_meta_options($post) {
                wp_nonce_field(plugin_basename(__FILE__), 'estate_agent_noncename');
                global $mlsimport;

                $token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
                $is_mls_connected = get_option('mlsimport_connection_test', '');
                $mlsimport->admin->mlsimport_saas_setting_up();

                if ('yes' !== $is_mls_connected) {
                        $mlsimport->admin->mlsimport_saas_check_mls_connection();
                        $is_mls_connected = get_option('mlsimport_connection_test', '');
                }

                if (trim($token) === '') {
                        echo '<div class="mlsimport_warning">' . esc_html__('You are not connected to MlsImport - Please check your Username and Password.', 'mlsimport') . '</div>';
                        return;
                }

                if ('yes' !== $is_mls_connected) {
                        echo '<div class="mlsimport_warning">' . esc_html__('The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved.', 'mlsimport') . '</div>';
                        return;
                }

                $postId = $post->ID;
                $mlsimportItemHowMany   = esc_html(get_post_meta($postId, 'mlsimport_item_how_many', true));
                $mlsimportItemStatCron  = esc_html(get_post_meta($postId, 'mlsimport_item_stat_cron', true));
                $lastDate                               = get_post_meta($postId, 'mlsimport_last_date', true);
                $status                                 = get_option('mlsimport_force_stop_' . $postId);
                $fieldImport                    = $this->mlsimport_saas_return_mls_fields();
                $options                                = get_option('mlsimport_admin_options');
                $mlsimportMlsId                 = isset($options['mlsimport_mls_name']) && $options['mlsimport_mls_name'] !== ''

                                                                        ? intval($options['mlsimport_mls_name'])
                                                                        : 0;

               $mlsRequest = $this->mlsimport_make_listing_requests($postId);

               $hasError = isset($mlsRequest['success']) && !$mlsRequest['success'];
               if ($hasError) {
                       echo '<div class="mlsimport_warning">' . esc_html($mlsRequest['message']) . '</div>';
               }

               $foundItems = isset($mlsRequest['results']) ? intval($mlsRequest['results']) : 'none';
                if ($foundItems === 'none') {
                        $mlsimport->admin->mlsimport_saas_check_mls_connection();
                        esc_html_e('Your Token was expired. Please refresh the page to renew it wait while we renew it.', 'mlsimport');
                }

               echo $this->generateMetaOptionsHtml($postId, $foundItems, $lastDate, $mlsimportItemHowMany, $mlsimportItemStatCron, $mlsimportMlsId, $fieldImport, $hasError);
       }




	/**
	 * Generate Meta Options HTML
	 *
	 * @param int $postId The post ID.
	 * @param int $foundItems The number of found items.
	 * @param string $lastDate The last date checked.
	 * @param string $mlsimportItemHowMany How many items to import.
	 * @param string $mlsimportItemStatCron The status of the cron job.
	 * @param int $mlsimportMlsId The MLS import ID.
	 * @param array $fieldImport The fields to import.
	 * @return string The generated HTML.
	 */
       private function generateMetaOptionsHtml($postId, $foundItems, $lastDate, $mlsimportItemHowMany, $mlsimportItemStatCron, $mlsimportMlsId, $fieldImport, $hasError = false) {


		ob_start();

                $metadata_api_call_city          = array();
                $metadata_api_call_county        = array();
                $metadata_api_call_property_type = array();
		$mlsimport_mls_metadata_mls_enums = get_option('mlsimport_mls_metadata_mls_enums', '');
		if ('' !== $mlsimport_mls_metadata_mls_enums) {
			$metadata_api_call_full = json_decode($mlsimport_mls_metadata_mls_enums, true);
			if (isset($metadata_api_call_full['global_array']['PropertyEnums'])) {
				$property_enums = $metadata_api_call_full['global_array']['PropertyEnums'];
                                if (isset($property_enums['City']) && is_array($property_enums['City'])) {
                                        $metadata_api_call_city = $property_enums['City'];
                                }

                                if (isset($property_enums['CountyOrParish']) && is_array($property_enums['CountyOrParish'])) {
                                        $metadata_api_call_county = $property_enums['CountyOrParish'];
                                }

                                if (isset($property_enums['PropertyType']) && is_array($property_enums['PropertyType'])) {
                                        $metadata_api_call_property_type = $property_enums['PropertyType'];
                                }
                        }
                }

		?>
		<div class="mlsimport_item_search_url" style="display:none;"><?php echo esc_html__('Last date/time we check :', 'mlsimport') . ' ' . esc_html($lastDate); ?></div>
		<ul>
			<li>1. Set the import parameters.</li>
			<li>2. Hit Publish or Update, otherwise import will not work correctly.</li>
			<li>3. Click the Start Import button. Most MLS limit the import number to 1000. If you need to import more create additional import items.</li>
			<li>4. Press the Update button after you make any change in the import settings.</li>
		</ul>

		<?php if (is_numeric($foundItems) && $foundItems >= 500): ?>
			<div class="mlsimport_notification">
				<?php esc_html_e('You found a large number of listings. While MlsImport import can handle such a large number, you need to make sure that your server can do this operation. This import will take some time. Make sure your server has the capacity, there are no time limits for a long-running process and consider splitting the import between multiple MLS Import Tasks.', 'mlsimport'); ?>
			</div>
		<?php endif; ?>

		<div class="mlsimport_import_no">
			<?php esc_html_e('We found', 'mlsimport'); ?>
			<strong><?php echo esc_html($foundItems); ?></strong> listings. If you decide to import all of them make sure your server database can handle the load. Please do a database backup before initial import.
		</div>

		<fieldset class="mlsimport-fieldset">
			<label class="mlsimport-label" for="mlsimport_item_how_many">
				<?php esc_html_e('How Many to import. Use 0 if you want to import all listings found.', 'mlsimport'); ?>
			</label>
			<input type="text" id="mlsimport_item_how_many" name="mlsimport_item_how_many" 
				class="mlsimport-input mlsimport-2025-input " value="<?php echo esc_attr($mlsimportItemHowMany); ?>"/>
		</fieldset>

		<fieldset class="mlsimport-fieldset mlsimport_auto_switch">
			<?php esc_html_e('Enable Auto Update every hour?', 'mlsimport'); ?>
			<label class="mlsimport_switch">
				<input type="hidden" value="0" name="mlsimport_item_stat_cron">
				<input type="checkbox" class="mlsimport-import-checkbox" value="1" name="mlsimport_item_stat_cron"<?php if (intval($mlsimportItemStatCron) !== 0) echo esc_html(' checked'); ?>>
				<span class="slider round"></span>
			</label>
		</fieldset>

               <?php if ($mlsimportItemStatCron !== '' && !$hasError): ?>
                       <div id="mlsimport_item_status">Ready to import!</div>
                       <div id="mlsimport_item_progress" class="mlsimport-progress-bar">
                               <div class="mlsimport-progress-bar-inner" style="width:0%;"></div>
                       </div>
                       <input class="button mlsimport_button  save_data " type="button" id="mlsimport-start_item"
                               data-post-number="<?php echo intval($foundItems); ?>"
                               data-post_id="<?php echo intval($postId); ?>" value="Start Import">
                       <input class="button mlsimport_button error_action" type="button" id="mlsimport_stop_item"
                               data-post-number="<?php echo intval($foundItems); ?>"
                               data-post_id="<?php echo intval($postId); ?>" value="Stop Import">
               <?php endif; ?>

		<input type="hidden" id="mlsimport_item_actions" value="<?php echo esc_attr(wp_create_nonce("mlsimport_item_actions")); ?>"/>
		<div class="mlsimport_param_wrapper"><h2><?php esc_html_e('Import Parameters', 'mlsimport'); ?></h2>

			<?php
			$mlsimportItemTitleFormat = esc_html(get_post_meta($postId, 'mlsimport_item_title_format', true));
			?>

			<fieldset class="mlsimport-fieldset">
				<label class="mlsimport-label" for="mlsimport_item_title_format">
					<?php esc_html_e('Title Format', 'mlsimport'); ?>
				</label>

				<p class="mlsimport-exp"><?php esc_html_e('You can use {Address}, {City}, {CountyOrParish}, {StateOrProvince}, {PostalCode}, {PropertyType}, {Bedrooms}, {Bathrooms}, {ListingKey}, {ListingId},{StreetNumberNumeric} or {StreetName}', 'mlsimport'); ?></p>
				<input type="text" id="mlsimport_item_title_format" name="mlsimport_item_title_format" 
					class="mlsimport-input mlsimport-2025-input"
					value="<?php echo '' !== $mlsimportItemTitleFormat ? trim(esc_html($mlsimportItemTitleFormat)) : esc_html('{Address},{City},{CountyOrParish},{PropertyType}'); ?>"/>
			</fieldset>

			<?php
			$mlsimportItemAgent = esc_html(get_post_meta($postId, 'mlsimport_item_agent', true));
			?>

			<fieldset class="mlsimport-fieldset">
				<label class="mlsimport-label" for="mlsimport_item_agent">
					<?php esc_html_e('Select Agent', 'mlsimport'); ?>
				</label>
				<select class="mlsimport-select mlsimport-2025-select" name="mlsimport_item_agent" id="mlsimport_item_agent">
					<?php
					$permitedTags = mlsimport_allowed_html_tags_content();
					$selectAgent =$this->theme_importer->mlsimportSaasThemeImportSelectAgent($mlsimportItemAgent);
					print wp_kses($selectAgent, $permitedTags);
					?>
				</select>
			</fieldset>

			<?php
			$mlsimportItemPropertyStatus = esc_html(get_post_meta($postId, 'mlsimport_item_property_status', true));
			if ('' === $mlsimportItemPropertyStatus) {
				$mlsimportItemPropertyStatus = 'publish';
			}
			$statusArray = array('publish', 'draft');
			?>
			<fieldset class="mlsimport-fieldset">
				<label class="mlsimport-label" for="mlsimport_item_property_status">
					<?php esc_html_e('Select Property Status on import', 'mlsimport'); ?>
				</label>
				<select class="mlsimport-select mlsimport-2025-select" name="mlsimport_item_property_status" id="mlsimport_item_property_status">
					<?php foreach ($statusArray as $value): ?>
						<option value="<?php echo esc_attr($value); ?>" <?php if ($value === $mlsimportItemPropertyStatus) echo esc_html('selected'); ?>>
							<?php echo esc_html($value); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</fieldset>

			<?php
			$mlsimportItemPropertyUser = esc_html(get_post_meta($postId, 'mlsimport_item_property_user', true));
			?>
			<fieldset class="mlsimport-fieldset">
				<label class="mlsimport-label" for="mlsimport_item_property_user">
					<?php esc_html_e('User', 'mlsimport'); ?>
				</label>
				<select class="mlsimport-select mlsimport-2025-select" id="mlsimport_item_property_user" name="mlsimport_item_property_user">
					<?php
					$selectUser = $this->theme_importer->mlsimportSaasThemeImportSelectUser($mlsimportItemPropertyUser);
					print wp_kses($selectUser, $permitedTags);
					?>
				</select>
			</fieldset>

			<?php
			$mlsimportItemMinPrice = floatval(get_post_meta($postId, 'mlsimport_item_min_price', true));
			$mlsimportItemMaxPrice = floatval(get_post_meta($postId, 'mlsimport_item_max_price', true));
			if (0 === intval($mlsimportItemMaxPrice)) {
				$mlsimportItemMaxPrice = 10000000;
			}
			?>
			<fieldset class="mlsimport-fieldset">
				<label class="mlsimport-label">
					<?php esc_html_e('Price Between', 'mlsimport'); ?>
				</label>
				<input type="text" class="mlsimport-select mlsimport-input mlsimport-2025-input  " id="mlsimport_item_min_price" name="mlsimport_item_min_price" value="<?php echo esc_attr($mlsimportItemMinPrice); ?>"> and
				<input type="text" class="mlsimport-select mlsimport-input mlsimport-2025-input " id="mlsimport_item_max_price" name="mlsimport_item_max_price" value="<?php echo esc_attr($mlsimportItemMaxPrice); ?>">
			</fieldset>

			<?php
			$options = get_option($this->plugin_name . '_admin_options');

			$mlsId = '';
			if (isset($options['mlsimport_mls_name'])) {
				$mlsId = sanitize_text_field(trim($options['mlsimport_mls_name']));
			}

			if ($mlsId > 5000) {
				$fieldImport['PropertyType']['multiple'] = 'no';
			}

			
			if ($mlsId >= 7000) {
				// there is no such thing for realtor.ca
				unset($fieldImport['PropertyType']);
			}




			foreach ($fieldImport as $key => $field):
				$nameCheck = strtolower('mlsimport_item_' . $key . '_check');
				$name = strtolower('mlsimport_item_' . $key);

				$value = get_post_meta($postId, $name, true);
				$valueCheck = get_post_meta($postId, $nameCheck, true);
				$extraClass = '';
				if ('extraCity' === $key || 'extraCounty' === $key) {
					$extraClass = ' mlsimport_hidden_field_button button mlsimport_button';
				}
				?>
				<fieldset class="mlsimport-fieldset">
					<label class="mlsimport-label <?php echo esc_attr($extraClass); ?>" for="<?php echo esc_attr($name); ?>">
						<?php echo esc_html($field['label']); ?>
					</label>
					<?php if ('extraCity' === $key || 'extraCounty' === $key): ?>
					<div class="mlsimport-input-wrapper" style="display:none">
						<?php endif; ?>
						<p class="mlsimport-exp"><?php echo wp_kses_post($this->mlsimport_notes_for_mls($mlsimportMlsId, $name, $field['description'])); ?>
							<?php
							$isCheckboxAdmin = 0;
							if (1 === intval($valueCheck)) {
								$isCheckboxAdmin = 1;
							}

                                                        $selectAllNone = [
                                                                'InternetAddressDisplayYN',
                                                                'InternetEntireListingDisplayYN',
                                                                'PostalCode',
                                                                'ListAgentKey',
                                                                'ListAgentMlsId',
                                                                'BuyerAgentMlsId',
                                                                                                                              'ListOfficeKey',
                                                                                                                              'ListOfficeMlsId',
                                                                                                                              'StandardStatus',
																'StandardStatusDelete',
																'ListingId',
																'extraCity',
																'extraCounty',
																'Exclude_ListOfficeKey',
                                                                'Exclude_ListOfficeMlsId',
                                                                'Exclude_ListAgentKey',
                                                                'Exclude_ListAgentMlsId',
                                                                'MLSAreaMajor',
                                                                'SubdivisionName',
                                                        ];

							if ($mlsId > 5000) {
								$selectAllNone[] = 'PropertyType';
							}

							if (!in_array($key, $selectAllNone)): ?>
								<?php
								esc_html_e('- Or Select All ', 'mlsimport');
							
								?>
								<input type="hidden" name="<?php echo esc_attr($nameCheck); ?>" value="0"/>
								<input type="checkbox" class="mlsimport-import-checkbox" name="<?php echo esc_attr($nameCheck); ?>" value="1" <?php print esc_attr(checked($isCheckboxAdmin, 1, 0)); ?>/>
							<?php endif; ?>
						</p>

						<?php
						$permittedStatus = ['active', 'active under contract', 'coming soon', 'activeundercontract', 'comingsoon', 'pending'];

						if ($field['type'] === 'select'): ?>
							<?php
							$multiple = '';
							if ('yes' === $field['multiple']) {
								$multiple = 'multiple';
								$name .= '[]';
							}

							if ('StandardStatus' === $key && '' === $value) {
								$value = ['Active'];
							}

					

							// Additional conditions can be placed here.
							?>
                                                        <select class="mlsimport-select mlsimport-2025-select" id="<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" <?php echo esc_attr($multiple); ?>>
                                                                <?php foreach ($field['values'] as $selectKey): ?>

                                                                        <?php if ('' !== $selectKey): ?>
                                                                                <?php
                                                                                $option_value = $selectKey;
                                                                                $option_label = $selectKey;
                                                                                $comparison_values = array($option_value);

                                                                                if ('City' === $key && isset($metadata_api_call_city[$selectKey])) {
                                                                                        $option_label = $selectKey;
                                                                                        $comparison_values[] = $metadata_api_call_city[$selectKey];
                                                                                } elseif ('CountyOrParish' === $key && isset($metadata_api_call_county[$selectKey])) {
                                                                                        $option_label = $selectKey;
                                                                                        $comparison_values[] = $metadata_api_call_county[$selectKey];
                                                                                } elseif ('PropertyType' === $key && isset($metadata_api_call_property_type[$selectKey])) {
                                                                                        $option_label = $selectKey;
                                                                                        $comparison_values[] = $metadata_api_call_property_type[$selectKey];
                                                                                }

                                                                                $comparison_values = array_values(array_unique(array_filter($comparison_values, static function ($compare_value) {
                                                                                        return '' !== $compare_value && null !== $compare_value;
                                                                                })));

                                                                                $is_selected = false;
                                                                                if ($key === "StandardStatusDelete" && $value == null) {
                                                                                        $is_selected = true;
                                                                                } elseif (is_array($value)) {
                                                                                        $is_selected = count(array_intersect($comparison_values, $value)) > 0;
                                                                                } else {
                                                                                        $is_selected = in_array($value, $comparison_values, true);
                                                                                }
                                                                                ?>
                                                                                <option value="<?php echo esc_attr($option_value); ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                                                                        <?php echo esc_html($option_label); ?>
                                                                                </option>
                                                                        <?php endif; ?>

                                                                <?php endforeach; ?>
                                                        </select>

						<?php elseif ($field['type'] === 'input'): ?>
							<input type="text" class="mlsimport-select mlsimport-input mlsimport-2025-input" id="<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
						<?php endif; ?>
						<?php if ('extraCity' === $key || 'extraCounty' === $key): ?>
					</div>
				<?php endif; ?>
				</fieldset>
			<?php endforeach; ?>

		</div>
		<?php
		return ob_get_clean();
	}


	



	public function mlsimport_add_extra_fields() {
	}

	/**
	 *
	 *
	 *
	 *
	 *
	 *
	 */
	function mlsimport_notes_for_mls( $mlsimport_mls_id, $name, $description ) {
		// 111 - Rae Edmonton

		if ( 111 ===  intval($mlsimport_mls_id) &&  'mlsimport_item_standardstatus' === $name  ) {
			return esc_html__( 'Your MLS does not use this field - all listings are considered Active.', 'mlsimport' );
		} else {
			return $description;
		}
	}


	/**
	 *
	 *
	 * Get Last date
	 */
	public function mlsimport_saas_get_last_date( $item_id ) {
		$last_date = get_post_meta( $item_id, 'mlsimport_last_date', true );

		if ( '' === $last_date  ) {
			$last_date = $this->mlsimport_saas_update_last_date( $item_id );
		}
		return $last_date;
	}


	/**
	 *
	 *
	 * Save Last date
	 */
	public function mlsimport_saas_update_last_date( $item_id ) {

		$unix_time         = current_time( 'timestamp', 0 ) - ( 2 * 60 * 60 );
		print $last_date_to_save = date( 'Y-m-d\TH:i', $unix_time );
		update_post_meta( $item_id, 'mlsimport_last_date', $last_date_to_save );

		return $last_date_to_save;
	}





	/**
        * Check and process MLSimport item for modified listings in the last 2 hours.
        * Optimized for memory: logs memory, unsets large arrays, and triggers garbage collection.
        *
        * @param int $item_id
        * @return void
        */
       public function mlsimport_saas_start_cron_links_per_item( $item_id ) {
           // Log memory before start

           $last_date = $this->mlsimport_saas_get_last_date( $item_id );
           print 'MLSitem id: ' . $item_id . ' - ';
           esc_html_e('date to consider: ','mlsimport'); 
           print esc_html($last_date) . '. ';

           // Make request to MLS API
           $mlsrequest = $this->mlsimport_make_listing_requests( $item_id, $last_date );

           $found_items = 0;
           if ( isset( $mlsrequest['results'] ) ) {
               $found_items = intval( $mlsrequest['results'] );
           } else {
               delete_transient( 'mlsimport_saas_token' );
           }
           print esc_html__('We found ','mlsimport') . esc_html( $found_items ) . ' listings.</br>' . PHP_EOL;

           // Only process if items found
           if ( $found_items > 0 ) {
			
               $item_id_array = array(
                   'item_id'       => $item_id,
                   'how_many'      => 0,
                   'max_number'    => $found_items,
                   'batch_counter' => 1,
               );

               // Potentially large array, log memory before/after
               $attachments_to_move = (array) $this->mlsimport_saas_generate_import_requests_per_item( $item_id_array, $last_date );

               // Store in post meta (beware if array is huge)
               update_post_meta( $item_id, 'mlsimport_spawn_status_cron_job', 'started' );
               update_post_meta( $item_id, 'mlsimport_cron_attach_to_move_' . $item_id, $attachments_to_move );

               // Save last date for next run
               $this->mlsimport_saas_update_last_date( $item_id );

               // Prepare and pass only necessary arguments to background process
               $attachments_to_send = array(
                   'args' => array(
                       'attachments_to_move' => $item_id,
                       'item_id_array'       => $item_id_array,
                   ),
               );

               $this->mlsimport_background_process_per_item_cron_function( $attachments_to_send['args'] );

               // Unset large arrays/objects after use
               unset($attachments_to_move, $attachments_to_send, $mlsrequest, $item_id_array);
               gc_collect_cycles();
           }
       }






/**
 * Reconciliation log (optimized, batched, memory logged)
 */
public function mlsimport_saas_start_doing_reconciliation() {
    global $mlsimport, $wpdb;

    
    // Get all MLS keys in memory (we assume this is necessary for lookup)
    $mls_data = $this->mlsimport_saas_get_mls_reconciliation_data();
    $listingKey_in_MLS = $mls_data['all_data'] ?? [];
    
	unset($mls_data);
    gc_collect_cycles();
    
	if (empty($listingKey_in_MLS)) {
        return;
    }
    // Flip for fast lookup
    $listingKey_in_MLS = array_flip($listingKey_in_MLS);
    
    // Batch fetch local listings
    $batch = 1000;
    $offset = 0;
    $to_delete = 0;
    $counter = 0;

    $mlsimport_preload_all_mls_item_status_meta = $this->mlsimport_preload_all_mls_item_status_meta();
    //print_r($mlsimport_preload_all_mls_item_status_meta);
 
    do {
        $local = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    p.ID,
                    listingkey_meta.meta_value AS listingkey,
                    inserted_meta.meta_value AS mlsimport_item_inserted
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} listingkey_meta
                    ON p.ID = listingkey_meta.post_id
                    AND listingkey_meta.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} inserted_meta
                    ON p.ID = inserted_meta.post_id
                    AND inserted_meta.meta_key = %s
                WHERE p.post_status NOT IN ('draft', 'trash')
                LIMIT %d OFFSET %d",
                'ListingKey',
                'MLSimport_item_inserted',
                $batch,
                $offset
            ),
            ARRAY_A
        );

        $count = count($local);

        
        foreach ($local as $item) {
            $listingkey  = $item['listingkey']; // not 'meta_value' anymore
            $property_id = $item['ID'];
            $mlsimportItemId = $item['mlsimport_item_inserted'];
            ++$counter;
            // IN MLS
            if (isset($listingKey_in_MLS[$listingkey])) {
               
                if (!empty($mlsimportItemId) && isset($mlsimport_preload_all_mls_item_status_meta[$mlsimportItemId])) {
                    $mlsimport_item_standardstatus = $mlsimport_preload_all_mls_item_status_meta[$mlsimportItemId]['mlsimport_item_standardstatus'] ?? null;
                } else {
                    $mlsimport_item_standardstatus = null;
                }
                
                $keep_when_in_mls = $mlsimport->admin->theme_importer->check_if_delete_when_status_when_in_mls($property_id,$mlsimport_item_standardstatus);
                if (!$keep_when_in_mls) {
                    ++$to_delete;
                  	$mlsimport->admin->theme_importer->mlsimportSaasDeletePropertyViaMysql($property_id, $listingkey);
                }
            } else {
                // NOT IN MLS

                if (!empty($mlsimportItemId) && isset($mlsimport_preload_all_mls_item_status_meta[$mlsimportItemId])) {
                    $mlsimport_item_standardstatus = $mlsimport_preload_all_mls_item_status_meta[$mlsimportItemId]['mlsimport_item_standardstatus'] ?? null;
                    $mlsimport_item_standardstatusdelete = $mlsimport_preload_all_mls_item_status_meta[$mlsimportItemId]['mlsimport_item_standardstatusdelete'] ?? null;
                } else {
                    $mlsimport_item_standardstatus = null;
                    $mlsimport_item_standardstatusdelete = null;
                }  
                $keep = $mlsimport->admin->theme_importer->check_if_delete_when_status($property_id, $mlsimport_item_standardstatus, $mlsimport_item_standardstatusdelete);
                if (!$keep) {
                    ++$to_delete;
   					$mlsimport->admin->theme_importer->mlsimportSaasDeletePropertyViaMysql($property_id, $listingkey);
                }
            }

            // Memory housekeeping
            unset($listingkey, $property_id, $mlsimportItemId, $mlsImportItemStatus, $mlsImportItemStatusDelete, $keep_when_in_mls, $keep);
            if ($counter % 250 == 0) {
                gc_collect_cycles();
            }
        }

        unset($local);
        gc_collect_cycles();

        $offset += $batch;
    } while ($count === $batch);

   
    print esc_html(' to delete:' . $to_delete);

    // Final cleanup
    unset($listingKey_in_MLS);
    gc_collect_cycles();

   
    return;
}



/**
 * Preload all status meta for ALL mlsimport_item posts in ONE QUERY.
 * Returns: [mlsimport_item_id => ['mlsimport_item_standardstatus' => ..., 'mlsimport_item_standardstatusdelete' => ...], ...]
 */
function mlsimport_preload_all_mls_item_status_meta() {
    global $wpdb;

    // Only 1 query: join posts and postmeta, grab both metas
    $sql = "
        SELECT p.ID as post_id, pm.meta_key, pm.meta_value
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = 'mlsimport_item'
        AND pm.meta_key IN ('mlsimport_item_standardstatus', 'mlsimport_item_standardstatusdelete')
    ";

    $rows = $wpdb->get_results($sql);

    $meta = [];
    foreach ($rows as $row) {
        if (!isset($meta[$row->post_id])) {
            $meta[$row->post_id] = [
                'mlsimport_item_standardstatus' => null,
                'mlsimport_item_standardstatusdelete' => null
            ];
        }
        $meta[$row->post_id][$row->meta_key] = maybe_unserialize($row->meta_value);
    }
    return $meta;
}



	/**
	 *
	 *
	 * Requestq Reconciliation log
	 */
	public function mlsimport_saas_get_mls_reconciliation_data() {

		$arguments = array();
		$answer    = $this->theme_importer->globalApiRequestCurlSaas( 'reconciliation', $arguments, 'GET' );
		return $answer;
	}

	/**
	 *
	 *
	 * Reconciliation get local data
	 */
	public function mlsimport_saas_get_all_meta_values($key) {
	global $wpdb;
	$result = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT pm.meta_value, p.ID
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
			AND p.post_status = 'publish'
			",
			$key
		),
		ARRAY_A // Lighter than OBJECT, unless you need objects
	);
	return $result;
        }



	/*
	*  Do api Listing Requests
	 *
	 *
	 *
	 *
	 * */
	public function mlsimport_make_listing_requests( $item_id, $last_date = '', $skip = '', $top = '' ) {
		$options = get_option( $this->plugin_name . '_admin_options' );
		$mls_id  = '';
		if ( isset( $options['mlsimport_mls_name'] ) ) {
			$mls_id = sanitize_text_field( trim( $options['mlsimport_mls_name'] ) );
		}

		$arguments = $this->mlsimport_saas_make_listing_requests_arguments( $item_id, $last_date, $skip, $top );


		if (
			$mls_id > 5000 && $mls_id < 6000 &&
				( ! isset( $arguments['property_type'] ) or
					( isset( $arguments['property_type'] ) && '' ===  $arguments['property_type'] ) or
					( isset( $arguments['property_type'][0] ) && '' ===  $arguments['property_type'][0] )
				)
		) {
			return array(
				'success' => false,
				'type'    => 'rapattoni',
				'message' => esc_html__( 'This MLS requires to have one item selected from "Property Action Category" dropdown', 'mlsimport' ),
			);
		}

		$potential_leght = strlen( wp_json_encode( $arguments ) );
		if ( $potential_leght > 1750 ) {
			return array(
				'success'         => false,
				'potential_leght' => $potential_leght,
				'message'         => esc_html__( 'You have too many parameters selected. Split the import beween multiple MLS Import Tasks: For ex : Import per County instead of selecting 10 cities or import listing between certain price range.', 'mlsimport' ),
			);
		}
	
		//print_r($arguments);	
		//print '----------------------------'.PHP_EOL;
		$answer                    = $this->theme_importer->globalApiRequestCurlSaas( 'listings', $arguments, 'POST' );
		$answer['potential_leght'] = $potential_leght;
		return ( $answer );
	}






	/*
	 * Create Api query arguments
	 *
	 *
	 *
	 *
	 *
	 * */

	public function mlsimport_saas_make_listing_requests_arguments( $item_id, $last_date = '', $skip = '', $top = '' ) {

		$options = get_option( $this->plugin_name . '_admin_options' );
		if ( isset( $options['mlsimport_mls_name'] ) ) {
			$mls_id = intval( $options['mlsimport_mls_name'] );
		} else {
			return '';
		}

		if ( isset( $options['mlsimport_theme_used'] ) ) {
			$theme_id = intval( $options['mlsimport_theme_used'] );
		} else {
			return '';
		}

		$values             = array();
		$values['mls_id']   = $mls_id;
		$values['theme_id'] = $theme_id;

		if ( '' !==  $top  ) {
			$values['top']  = $top;
			$values['skip'] = intval( $skip );
		}

		// // add price
		$mlsimport_item_min_price = get_post_meta( $item_id, 'mlsimport_item_min_price', true );
		$mlsimport_item_max_price = get_post_meta( $item_id, 'mlsimport_item_max_price', true );
		if ( '' !==  $mlsimport_item_min_price  && '' !== $mlsimport_item_max_price  ) {
			$values['list_price_min'] = floatval( $mlsimport_item_min_price );
			$values['list_price_max'] = floatval( $mlsimport_item_max_price );
		}

		// add city
		$values = $this->mls_import_return_multiple_param_value( 'city', $item_id, 'city', $values );

		// add county
		$values = $this->mls_import_return_multiple_param_value( 'countyorparish', $item_id, 'county_or_parish', $values );

		// add MLSAreaMajor
		$values = $this->mls_import_saas_add_to_parms_input( 'MLSAreaMajor', $item_id, 'mls_area_major', $values );

		// add SubdivisionName
		$values = $this->mls_import_saas_add_to_parms_input( 'SubdivisionName', $item_id, 'subdivision_name', $values );

		// add postal code
		$values = $this->mls_import_saas_add_to_parms_input( 'PostalCode', $item_id, 'postal_code', $values );

		// add status

		if ( 111 !== $mls_id  ) { // edmonton check
			$values = $this->mls_import_return_multiple_param_value( 'StandardStatus', $item_id, 'status', $values );
		}

		// add property_subtype
		$values = $this->mls_import_return_multiple_param_value( 'PropertySubType', $item_id, 'property_subtype', $values );

		// add property_type
		$values = $this->mls_import_return_multiple_param_value( 'PropertyType', $item_id, 'property_type', $values );

		// rapattoni exception
		if ( $mls_id > 5000 && 
			( isset($values['property_type']) && $values['property_type'] !='' ) ) {

			$values                    = $this->mls_import_saas_add_to_parms_input( 'PropertyType', $item_id, 'property_type', $values );
			$temp                      = $values['property_type'];
			$temp                      = str_replace( ' ', '', $temp );
			$values['property_type']   = array();
			$values['property_type'][] = $temp;
		}

		// add internet_entirelisting_displayyn
		$values = $this->mls_import_saas_add_to_parms_input( 'InternetEntireListingDisplayYN', $item_id, 'internet_entirelisting_displayyn', $values );

		// add internet_address_displayyn
		$values = $this->mls_import_saas_add_to_parms_input( 'InternetAddressDisplayYN', $item_id, 'internet_address_displayyn', $values );

                // add ListAgentKey
                $values = $this->mls_import_saas_add_to_parms_input( 'ListAgentKey', $item_id, 'list_agentkey', $values );
                // add ListAgentKey
                $values = $this->mls_import_saas_add_to_parms_input( 'ListAgentMlsId', $item_id, 'list_agentmlsid', $values );
               // add BuyerAgentMlsId
               $values = $this->mls_import_saas_add_to_parms_input( 'BuyerAgentMlsId', $item_id, 'buyer_agentmlsid', $values );
                // add ListOfficeKey
                $values = $this->mls_import_saas_add_to_parms_input( 'ListOfficeKey', $item_id, 'list_officekey', $values );
		// add ListOfficeMlsId
		$values = $this->mls_import_saas_add_to_parms_input( 'ListOfficeMlsId', $item_id, 'list_officemlsid', $values );

		// add ListingId
		$values = $this->mls_import_saas_add_to_parms_input( 'ListingId', $item_id, 'listingid', $values );

		//add Exclude_ListOfficeKey
		$values = $this->mls_import_saas_add_to_parms_input( 'Exclude_ListOfficeKey', $item_id, 'exclude_list_officekey', $values );
		// add Exclude_ListOfficeMlsId
		$values = $this->mls_import_saas_add_to_parms_input( 'Exclude_ListOfficeMlsId', $item_id, 'exclude_list_officemlsid', $values );



		//add Exclude_ListAgentKey
		$values = $this->mls_import_saas_add_to_parms_input( 'Exclude_ListAgentKey', $item_id, 'exclude_list_agentkey', $values );
		// add Exclude_ListAgentMlsId
		$values = $this->mls_import_saas_add_to_parms_input( 'Exclude_ListAgentMlsId', $item_id, 'exclude_list_agentmlsid', $values );


		// if we have realtorca
		if ($mls_id >= 7000 && $last_date!=='') {
			$dateTime_realtorca = new DateTime($last_date, new DateTimeZone('UTC'));
			// Format with seconds and UTC timezone marker
			$last_date = $dateTime_realtorca->format('Y-m-d\TH:i:s.000\Z');
		}

		if ( '' !==  $last_date  ) {
			$values['modification_time'] = $last_date;
		}

		return( $values );
	}



	/*
	 *
	 * add input  items to parameters array
	 *
	 */

	public function mls_import_saas_add_to_parms_input( $key, $post_id, $new_name, $all_values ) {
		$name  = strtolower( 'mlsimport_item_' . $key );
		$value = get_post_meta( $post_id, $name, true );
		if ( '' !== $value  ) {
			$all_values[ $new_name ] = $value;
		}

		return $all_values;
	}


	/*
	 *
	 * add list items to parameters array
	 *   
	 */

	public function mls_import_return_multiple_param_value( $key, $post_id, $new_name, $all_values ) {
		$name_check = strtolower( 'mlsimport_item_' . $key . '_check' );
		$name       = strtolower( 'mlsimport_item_' . $key );

		$value = get_post_meta( $post_id, $name, true );

		// add extra county - should be moved into function if pass tests
		if ( 'countyorparish' === $key  ) {
			$extracounty_values = get_post_meta( $post_id, 'mlsimport_item_extracounty', true );

			if ( '' !== $extracounty_values ) {
				$extracounty_array = explode( ',', $extracounty_values );

				if ( ! is_array( $value ) ) {
					if ( '' ===  $value  ) {
						$value = array();
					} else {
						$value = array( $value );
					}
				}

				foreach ( $extracounty_array as $extra ) {
					$value[] = $extra;
				}
			}
		}

		// add extra city - should be moved into function if pass tests
		if ( 'city' ===  $key  ) {
			$extracity_values = get_post_meta( $post_id, 'mlsimport_item_extracity', true );
			if ( '' !==  $extracity_values  ) {
				$extracity_array = explode( ',', $extracity_values );

				if ( ! is_array( $value ) ) {
					if ('' ===  $value  ) {
						$value = array();
					} else {
						$value = array( $value );
					}
				}

				foreach ( $extracity_array as $extra ) {
					$value[] = $extra;
				}
			}
		}

		$value_check = get_post_meta( $post_id, $name_check, true );

		if ( 0 ===  intval($value_check)  && '' !== $value  ) {
			$all_values[ $new_name ] = $value;
		}

		// status exception
		if ( 'status' === $new_name  ) {
			$all_values[ $new_name ] = $value;
		}

		return $all_values;
	}



	/*
	 *
	 * All Enums fiels to be used on MLS import Taaks
	 *
	 *
	 *
	 *
	 *
	 *
	 * */

	public function mlsimport_saas_return_mls_fields() {

		$mlsimport_mls_metadata_mls_enums = get_option( 'mlsimport_mls_metadata_mls_enums', '' );

		if ( '' ===   $mlsimport_mls_metadata_mls_enums ) {
			?>
			<div class="mlsimport_warning long_warning">Please select the import fields(from MLS Import Settings) before starting a MLS import process.</div>
		<?php
		}

		$metadata_api_call_full = json_decode( $mlsimport_mls_metadata_mls_enums, true );

		if ( isset( $metadata_api_call_full['global_array'] ) ) {
			$metadata_api_call = $metadata_api_call_full['global_array'];
		}

		$city_array = array();
		if ( isset( $metadata_api_call['PropertyEnums']['City'] ) && is_array( $metadata_api_call['PropertyEnums']['City'] ) ) {
			$city_array = array_keys( $metadata_api_call['PropertyEnums']['City'] );
		}

		$county_array = array();
		if ( isset( $metadata_api_call['PropertyEnums']['CountyOrParish'] ) && is_array( $metadata_api_call['PropertyEnums']['CountyOrParish'] ) ) {
			$county_array = array_keys( $metadata_api_call['PropertyEnums']['CountyOrParish'] );
		}

		$mlsstatus_array = array();
		if ( isset( $metadata_api_call['PropertyEnums']['MlsStatus'] ) && is_array( $metadata_api_call['PropertyEnums']['MlsStatus'] ) ) {
			$mlsstatus_array = array_keys( $metadata_api_call['PropertyEnums']['MlsStatus'] );
		}

		$propertysubtype_array = array();
		if ( isset( $metadata_api_call['PropertyEnums']['PropertySubType'] ) && is_array( $metadata_api_call['PropertyEnums']['PropertySubType'] ) ) {
			$propertysubtype_array = array_keys( $metadata_api_call['PropertyEnums']['PropertySubType'] );
		}

                $propertytype_array = array();
                if ( isset( $metadata_api_call['PropertyEnums']['PropertyType'] ) && is_array( $metadata_api_call['PropertyEnums']['PropertyType'] ) ) {
                        $propertytype_array = array_keys( $metadata_api_call['PropertyEnums']['PropertyType'] );
                }


		$standardstatus_array = array();
		$standardstatus_delete_array=array();
		if ( isset( $metadata_api_call['PropertyEnums']['StandardStatus'] ) && is_array( $metadata_api_call['PropertyEnums']['StandardStatus'] ) ) {
			$standardstatus_array 		= array_keys( $metadata_api_call['PropertyEnums']['StandardStatus'] );
			$standardstatus_delete_array= array_keys( $metadata_api_call['PropertyEnums']['StandardStatus'] );
		}

		// if we do not have standart status
		if ( empty( $standardstatus_array ) ) {
			$standardstatus_array 			= $mlsstatus_array;
			$standardstatus_delete_array	= $mlsstatus_array;
			
		}


		$permited_status=array('active','active under contract','coming soon','activeundercontract','comingsoon','pending');
		$permited_status_lower = array_map('strtolower', $permited_status);

		// Filter out permitted statuses from array1 values
	//	$standardstatus_delete_array = array_filter($standardstatus_delete_array, function ($value) use ($permited_status_lower) {
	//		return !in_array(strtolower($value), $permited_status_lower);
//		}); 

	


		$extracounty_values = '';
		$extracity_values   = '';

		$field_import = array(
			'City'                           => array(
				'label'       => esc_html__( 'Select cities', 'mlsimport' ),
				'description' => esc_html__( 'Select the cities from where we will import data.', 'mlsimport' ),
				'type'        => 'select',
				'multiple'    => 'yes',
				'values'      => $city_array,
			),

			'extraCity'                      => array(
				'label'       => esc_html__( 'Add extra Cities', 'mlsimport' ),
				'description' => esc_html__( 'Add extra cities, separated by comma. They need to be written exactly like they are stored in MLS (for example all caps)', 'mlsimport' ),
				'type'        => 'input',
				'multiple'    => 'no',
				'values'      => $extracity_values,
			),

			'CountyOrParish'                 => array(
				'label'            => esc_html__( 'Select Counties', 'mlsimport' ),
				'description'      => esc_html__( 'Select the counties from where we will import data.', 'mlsimport' ),
				'type'             => 'select',
				'multiple'         => 'yes',
				'values'           => $county_array,
				'show_extra_field' => true,
			),

                        'extraCounty'                    => array(
                                'label'       => esc_html__( 'Add extra Counties', 'mlsimport' ),
                                'description' => esc_html__( 'Add extra counties, separated by comma. They need to be written exactly like they are stored in MLS (for example all caps)', 'mlsimport' ),
                                'type'        => 'input',
                                'multiple'    => 'no',
                                'values'      => $extracounty_values,
                        ),

                       'MLSAreaMajor'                  => array(
                               'label'       => esc_html__( 'MLS Area Major', 'mlsimport' ),
                               'description' => esc_html__( 'Filter listings by MLSAreaMajor.', 'mlsimport' ),
                               'type'        => 'input',
                               'multiple'    => 'no',
                       ),

                       'SubdivisionName'               => array(
                               'label'       => esc_html__( 'Subdivision Name', 'mlsimport' ),
                               'description' => esc_html__( 'Filter listings by SubDivisionName.', 'mlsimport' ),
                               'type'        => 'input',
                               'multiple'    => 'no',
                       ),

			'PostalCode'                     => array(
				'label'       => esc_html__( 'Select Postal Code', 'mlsimport' ),
				'description' => esc_html__( 'Select the PostalCode from where to import listings. Works with only one PostalCode.', 'mlsimport' ),
				'type'        => 'input',
				'multiple'    => 'no',
			),

			'PropertySubType'                => array(
				'label'       => esc_html__( 'Select Property Category', 'mlsimport' ),
				'description' => esc_html__( 'Property Category', 'mlsimport' ),
				'type'        => 'select',
				'multiple'    => 'yes',
				'values'      => $propertysubtype_array,
			),
			'PropertyType'                   => array(
				'label'       => esc_html__( 'Select Property Action Category', 'mlsimport' ),
				'description' => esc_html__( 'Property Action Category', 'mlsimport' ),
				'type'        => 'select',
				'multiple'    => 'yes',
				'values'      => $propertytype_array,
			),
			'StandardStatus'                 => array(
				'label'       => esc_html__( 'Select Status', 'mlsimport' ),
				'description' => __( 'The list is auto-populated with MLS available statuses.  To select multiple statuses, use Ctrl (Windows) or Command (Mac).', 'mlsimport' ),
				'type'        => 'select',
				'multiple'    => 'yes',
				'values'      => $standardstatus_array,
			),
			'StandardStatusDelete'                 => array(
				'label'       => esc_html__( 'Delete Statuses', 'mlsimport' ),
				'description' => __( 'Properties with these statuses will be deleted from your website after they are removed from MLS database. If you edit the field after importing, the changes will NOT apply to listings that have already been imported.', 'mlsimport' ),
				'type'        => 'select',
				'multiple'    => 'yes',
				'values'      => $standardstatus_delete_array,
			),

			'InternetEntireListingDisplayYN' => array(
				'label'       => esc_html__( 'Internet Entire Listing Display ', 'mlsimport'),
				'description' => esc_html__( 'A yes/no field that states the seller has allowed the listing to be displayed on Internet sites.', 'mlsimport' ),
				'type'        => 'select',
				'multiple'    => 'no',
				'values'      => array(
					'yes',
					'no',
				),
			),
			'InternetAddressDisplayYN'       => array(
				'label'       => esc_html__( 'Internet Address display', 'mlsimport' ),
				'description' => esc_html__( 'A yes/no field that states the seller has allowed the listing address to be displayed on Internet sites.', 'mlsimport' ),
				'type'        => 'select',
				'multiple'    => 'no',
				'values'      => array(
					'yes',
					'no',
				),
			),
			'ListAgentKey'                   => array(
				'label'       => esc_html__( 'ListAgentKey', 'mlsimport' ),
				'description' => esc_html__( 'Import listings from a specific Agent (contact your MLS for this information)', 'mlsimport' ),
				'type'        => 'input',
				'multiple'    => 'no',
			),
                        'ListAgentMlsId'                 => array(
                                'label'       => esc_html__( 'ListAgentMlsId', 'mlsimport' ),
                                'description' => esc_html__( 'Import listings from a specific Agent (contact your MLS for this information)', 'mlsimport' ),
                                'type'        => 'input',
                                'multiple'    => 'no',
                        ),
                       'BuyerAgentMlsId'                 => array(
                               'label'       => esc_html__( 'BuyerAgentMlsId', 'mlsimport' ),
                               'description' => esc_html__( 'Import listings from a specific Buyer Agent (contact your MLS for this information)', 'mlsimport' ),
                               'type'        => 'input',
                               'multiple'    => 'no',
                       ),
                        'ListOfficeKey'                  => array(
                                'label'       => esc_html__( 'ListOfficeKey', 'mlsimport' ),
                                'description' => esc_html__( 'Import listings from a specific Office (contact your MLS for this information)', 'mlsimport'),
                                'type'        => 'input',
                                'multiple'    => 'no',
			),
			'ListOfficeMlsId'                => array(
				'label'       => esc_html__( 'ListOfficeMlsId', 'mlsimport' ),
				'description' => esc_html__( 'Import listings from a specific Office (contact your MLS for this information)', 'mlsimport' ),
				'type'        => 'input',
				'multiple'    => 'no',
			),
			'ListingId'                      => array(
				'label'       => esc_html__( 'ListingId', 'mlsimport' ),
				'description' => esc_html__( 'Import One Property Only via parameter ListingID. If this does not work for you, please contact us to check if the field exists in your MLS.', 'mlsimport'),
				'type'        => 'input',
				'multiple'    => 'no',
			),
			'Exclude_ListOfficeMlsId'                => array(
				'label'       => esc_html__( 'Exclude listings with ListOfficeMlsId', 'mlsimport' ),
				'description' => esc_html__( 'Exclude listings that belong to one or more ListOfficeMlsId.', 'mlsimport' ),
				'type'        => 'input',
				'multiple'    => 'no',
			),
			'Exclude_ListOfficeKey'                      => array(
				'label'       => esc_html__( 'Exclude listings with ListOfficeKey', 'mlsimport' ),
				'description' => esc_html__( 'Exclude listings that belong to one or more ListOfficeKey', 'mlsimport'),
				'type'        => 'input',
				'multiple'    => 'no',
			),


			'Exclude_ListAgentMlsId'                => array(
				'label'       => esc_html__( 'Exclude listings with ListAgentMlsId', 'mlsimport' ),
				'description' => esc_html__( 'Exclude listings that belong to one or more ListAgentMlsId.', 'mlsimport' ),
				'type'        => 'input',
				'multiple'    => 'no',
			),
			'Exclude_ListAgentKey'                      => array(
				'label'       => esc_html__( 'Exclude listings with ListAgentKey ', 'mlsimport' ),
				'description' => esc_html__( 'Exclude listings that belong to one or more ListAgentKey ', 'mlsimport'),
				'type'        => 'input',
				'multiple'    => 'no',
			),


		);
		return $field_import;
	}







	/**
	 *
	 *
	 * AYsnc Test
	 */
	public function mlsimport_move_files_per_item() {
		check_ajax_referer( 'mlsimport_item_actions', 'security' );
		$post_id 	=	0;
		$how_many	=	0;
		$max_number	=	0;
		if(isset(  $_POST['post_id']  )){
			$post_id    = intval( $_POST['post_id'] );
		}
		if(isset(  $_POST['how_many']  )){
			$how_many   = intval( $_POST['how_many'] );
		}
		if(isset(  $_POST['post_number']  )){
			$max_number = intval( $_POST['post_number'] );
		}


		$is_onboard=intval($_POST['is_onboard']);

		
		update_option( 'mlsimport_force_stop_' . $post_id, 'no', false );

		$item_id_array = array(
			'item_id'       => $post_id,
			'how_many'      => $how_many,
			'max_number'    => $max_number,
			'batch_counter' => 1,
		);


                update_post_meta( $post_id, 'mlsimport_attach_to_move_' . $post_id, '' );

                $attachments_to_move = (array) $this->mlsimport_saas_generate_import_requests_per_item( $item_id_array );

                // If an error was returned from the API, sanitize and send it back to the client and stop further processing.
                if ( isset( $attachments_to_move['success'] ) && false === $attachments_to_move['success'] ) {
                        if ( isset( $attachments_to_move['message'] ) ) {
                                $attachments_to_move['message'] = wp_strip_all_tags( $attachments_to_move['message'] );
                        }
                        wp_send_json( $attachments_to_move );
                        wp_die();
                }

                update_post_meta( $post_id, 'mlsimport_attach_to_move_' . $post_id, $attachments_to_move );

		// net stat data
		update_post_meta( $post_id, 'mlsimport_progress_properties', 0 );
		update_post_meta( $post_id, 'mlsimport_progress_batches', 0 );
		update_post_meta( $post_id, 'mlsimport_progress_memory', 0 );
	


		$attachments_to_send = array(
			'args' => array(
				'attachments_to_move' => $post_id,
				'item_id_array'       => $item_id_array,
				'is_onboard'			=>$is_onboard,
			),
		);

		mlsimport_saas_single_write_import_custom_logs( 'Preparing the import. Please hold on.' . PHP_EOL );
		mlsimport_debuglogs_per_plugin( 'Preparing the import. Please hold on.' . PHP_EOL );

		update_post_meta( $post_id, 'mlsimport_spawn_status', 'started' );
		
		// old
		as_enqueue_async_action( 'mlsimport_background_process_per_item', $attachments_to_send );

		// Remove any pending async jobs for this item and enqueue a unique one
		//bad ideea 
		// as_unschedule_all_actions( 'mlsimport_background_process_per_item', $attachments_to_send );
		//as_enqueue_async_action( 'mlsimport_background_process_per_item', $attachments_to_send, '', true );


                spawn_cron();

                unset( $attachments_to_send );

                // Return success response to the AJAX caller.
                wp_send_json( array( 'success' => true ) );
        }

	/**
        * Process MLS Import attachments via background cron.
        * Memory-optimized with detailed memory usage logging.
        *
        * @param array $input_arg
        * @return void
        */
       public function mlsimport_background_process_per_item_cron_function( $input_arg ) {
           global $mlsimport;

           $log = 'In cron processing function ->' . wp_json_encode( $input_arg['item_id_array'] ) . PHP_EOL;
           mlsimport_saas_single_write_import_custom_logs( $log, 'cron' );
           mlsimport_saas_single_write_import_custom_logs( '[Memory] Start: ' . (memory_get_usage(true) / 1024 / 1024) . ' MB', 'cron' );

           // Load attachments to move from post meta
           $attachments_to_move = get_post_meta(
               $input_arg['item_id_array']['item_id'],
               'mlsimport_cron_attach_to_move_' . $input_arg['item_id_array']['item_id'],
               true
           );
           $log = '[Memory] After loading attachments: ' . (memory_get_usage(true) / 1024 / 1024) . ' MB' . PHP_EOL;
           mlsimport_saas_single_write_import_custom_logs( $log, 'cron' );

           if (!empty($attachments_to_move) && is_array($attachments_to_move)) {
               foreach ($attachments_to_move as $key => $import_arguments) {
                   // Optionally clear any cache for this batch
                   if ( isset($GLOBALS['wp_object_cache']) ) {
                       $GLOBALS['wp_object_cache']->delete('mlsimport_force_stop_' . $input_arg['item_id_array']['item_id'], 'options');
                   }

                   $log = '[Memory] Before API batch ' . $key . ': ' . (memory_get_usage(true) / 1024 / 1024) . ' MB' . PHP_EOL;
                   mlsimport_saas_single_write_import_custom_logs( $log, 'cron' );

                   // API call
                   $api_call_array = $this->theme_importer->globalApiRequestCurlSaas('listings', $import_arguments, 'POST');

                   $log = '[Memory] After API batch ' . $key . ': ' . (memory_get_usage(true) / 1024 / 1024) . ' MB' . PHP_EOL;
                   mlsimport_saas_single_write_import_custom_logs( $log, 'cron' );

                   // Parse/process response
                   $mlsimport->admin->theme_importer->mlsimportSaasCronParseSearchArrayPerItem(
                       $api_call_array, $input_arg['item_id_array'], $key
                   );

                   // Free per-iteration memory
                   unset($api_call_array, $import_arguments);
                   gc_collect_cycles();

                   $log = '[Memory] After cleanup batch ' . $key . ': ' . (memory_get_usage(true) / 1024 / 1024) . ' MB' . PHP_EOL;
                   mlsimport_saas_single_write_import_custom_logs( $log, 'cron' );
               }
           }

           mlsimport_saas_single_write_import_custom_logs('[Memory] End: ' . (memory_get_usage(true) / 1024 / 1024) . ' MB', 'cron' );
           mlsimport_saas_single_write_import_custom_logs('CRON JOB Import Completed ' . PHP_EOL, 'cron');
           mlsimport_debuglogs_per_plugin('CRON JOB Import Completed ' . PHP_EOL);
           update_post_meta($input_arg['item_id_array']['item_id'], 'mlsimport_spawn_status', 'completed');

           unset($attachments_to_move, $input_arg, $log);
           gc_collect_cycles();
       }




	/**
	 *
	 *
	 * Generate import Requests per item
	 */
	public function mlsimport_saas_generate_import_requests_per_item( $item_id_array, $last_date = '' ) {
		$import_step = 25;

		$prop_id   = $item_id_array['item_id'];
		$max_found = $item_id_array['max_number'];
		$how_many  = $item_id_array['how_many'];
		if ( 0===  intval($how_many)  ) {
			$how_many = $max_found;
		}
		if ( $how_many > $max_found ) {
			$how_many = $max_found;
		}

		$search_url_step = '';
		$urls_array      = array();

		$skip = 0;
		if ( $how_many > 10000 ) {
			$how_many = 10000;
		}
		update_post_meta($prop_id,'mlsimport_task_to_import', intval($how_many) );

		if ( $how_many < $import_step ) {
			$import_step = $how_many;
		}

                while ( $skip < $how_many ) {

                        // Determine how many items to request for this batch.
                        $batch_step = min( $import_step, $how_many - $skip );

                        // Build the request arguments using the remaining count.
                        $search_url_step = $this->mlsimport_saas_make_listing_requests_arguments( $prop_id, $last_date, $skip, $batch_step );

                        // If the API returned an error, propagate it immediately.
                        if ( isset( $search_url_step['success'] ) && false === $search_url_step['success'] ) {
                                return $search_url_step;
                        }

                        $skip       += $batch_step;
                        $urls_array[] = $search_url_step;


                }
                return $urls_array;
        }







	/**
	 *  Process Async function
	 */
	public function mlsimport_background_process_per_item_function( $input_arg ) {

		$mlsimportItemId = $input_arg['item_id_array']['item_id'];
		$log_prefix      = 'In processing function - Item ID: ' . $mlsimportItemId . ' -> ';
		mlsimport_saas_single_write_import_custom_logs( $log_prefix . wp_json_encode( $input_arg['item_id_array'] ) . PHP_EOL );

	
		// Get from MLS Import the big argument array only once
		$attachments_to_move = get_post_meta( $mlsimportItemId, 'mlsimport_attach_to_move_' . $mlsimportItemId, true );

		// Retrieve all meta data in one go to reduce database queries
		$mlsimport_item_option_data = array(
			'mlsimport_item_standardstatus'  		=> get_post_meta( $mlsimportItemId, 'mlsimport_item_standardstatus', true ),
			'mlsimport_item_standardstatusdelete'  	=> get_post_meta( $mlsimportItemId, 'mlsimport_item_standardstatusdelete', true ),
			'mlsimport_item_property_user'   		=> get_post_meta( $mlsimportItemId, 'mlsimport_item_property_user', true ),
			'mlsimport_item_agent'           		=> get_post_meta( $mlsimportItemId, 'mlsimport_item_agent', true ),
			'mlsimport_item_property_status' 		=> get_post_meta( $mlsimportItemId, 'mlsimport_item_property_status', true ),
		);

		$total_batches = count( $attachments_to_move );

		// removed because $this
		global $mlsimport;

		$log = 'In processing function  $attachments_to_move ->' . wp_json_encode( $attachments_to_move ) . PHP_EOL;
		mlsimport_saas_single_write_import_custom_logs( $log );

	 	
		foreach ( $attachments_to_move as $key => $import_arguments ) {
			// reconsider use
			// $GLOBALS['wp_object_cache']->delete('mlsimport_force_stop_' . $input_arg['item_id_array']['item_id'], 'options');
			$status = get_option( 'mlsimport_force_stop_' . $mlsimportItemId );
			if ( 'no' ===  $status  ) {
				// Clear memory before processing each batch
				wp_cache_flush();
				gc_collect_cycles();
                                
				// wp_cache_flush();
				$mem_usage      = memory_get_usage( true );
				$mem_usage_show = round( $mem_usage / 1048576, 2 );

			 	update_post_meta( $mlsimportItemId, 'mlsimport_progress_batches', $key + 1 );
				update_post_meta( $mlsimportItemId, 'mlsimport_progress_memory', $mem_usage_show );



				mlsimport_saas_single_write_import_custom_logs( $log );
				$log = 'Parsing import batch: ' . ( $key + 1 ) . ' of ' . $total_batches . '. Memory used: ' . $mem_usage_show . ' MB.' . PHP_EOL;
				

				// Combine logs and reduce function calls
				mlsimport_saas_single_write_import_custom_logs( $log );
				mlsimport_debuglogs_per_plugin( $log );
				print esc_html($log);

				$api_call_array = $this->theme_importer->globalApiRequestCurlSaas( 'listings', $import_arguments, 'POST' );

				$mlsimport->admin->theme_importer->mlsimportSaasParseSearchArrayPerItem( $api_call_array, $input_arg['item_id_array'], $key, $mlsimport_item_option_data );
			
                                
                                
				// Explicitly unset large variables after each batch
				unset($api_call_array);

				// Force garbage collection again after processing
				wp_cache_flush();
				gc_collect_cycles();

				
				//  Add a small delay to allow memory to be freed
				if (($key + 1) < $total_batches) {
					usleep(100000); // 100ms pause between batches
				}
                                
				} else {

					$final_mem_usage      = memory_get_usage( true );
                	$final_mem_usage_show = round( $final_mem_usage / 1048576, 2 );


					update_post_meta( $mlsimportItemId, 'mlsimport_spawn_status', 'completed' );
					delete_post_meta( $mlsimportItemId, 'mlsimport_attach_to_move_' . $mlsimportItemId );

					//new stats
					update_post_meta( $mlsimportItemId, 'mlsimport_progress_batches', $total_batches );
				update_post_meta( $mlsimportItemId, 'mlsimport_progress_memory', $final_mem_usage_show );
                

					mlsimport_saas_single_write_import_custom_logs( PHP_EOL . 'Parsing importing link  FORCE STOP : ' );
					mlsimport_debuglogs_per_plugin( 'Parsing importing link  FORCE STOP : ' );
					break; // Exit the loop if forced to stop
				}
		}

		mlsimport_saas_single_write_import_custom_logs( 'Import Completed ' . PHP_EOL );
		mlsimport_debuglogs_per_plugin( 'Import Completed ' . PHP_EOL );

		update_post_meta( $mlsimportItemId, 'mlsimport_spawn_status', 'completed' );
		delete_post_meta( $mlsimportItemId, 'mlsimport_attach_to_move_' . $mlsimportItemId );

                // Final cleanup
                unset($attachments_to_move);
                unset($mlsimport_item_option_data);
                unset($input_arg);
                unset($log);

                // One final garbage collection
                wp_cache_flush();
                gc_collect_cycles();
	}









	/**
	 *
	 *
	 *
	 * update log function
	 */
	public function mlsimport_logger_per_item() {
		//check_ajax_referer( 'mlsimport_item_actions', 'security' );
		$post_id=0;
		if(isset($_POST['post_id'] )){
			$post_id = intval( $_POST['post_id'] );
		}

		$status  = get_post_meta( $post_id, 'mlsimport_spawn_status', true );
		$path    = WP_PLUGIN_DIR . '/mlsimport/logs/status_logs.log';
		$logs    = file_get_contents( $path );

		//get neww status data
		$current = intval( get_post_meta( $post_id, 'mlsimport_progress_properties', true ) );
		$total   = intval( get_post_meta( $post_id, 'mlsimport_progress_batches', true ) );
		$memory  = get_post_meta( $post_id, 'mlsimport_progress_memory', true );
        $mlsimport_task_to_import = intval( get_post_meta($post_id,	'mlsimport_task_to_import',true));



		$force_status = intval( get_post_meta( $post_id, 'mlsimport_force_stop', true ) );
		$force_status = get_option( 'mlsimport_force_stop_' . $post_id );

		if ( 'no' !==  $force_status  ) {
			echo wp_json_encode(
				array(
					'is_done' => 'done',
					'status'  => $status,
					'logs'    => $logs,
				)
			);
			die();
		}

               if ( ''  === $status  ||  'completed' === $status  ) {
                       echo wp_json_encode(
                               array(
                                       'is_done'                       => 'done',
                                       'status'                        => $status,
                                       'logs'                          => $logs,
                                       'mlsimport_progress_properties' => $current,
                                       'mlsimport_task_to_import'      => $total,
                               )
                       );
               } else {
			// return from log
			echo wp_json_encode(
				array(
					'is_done' 	=> 'wip',
					'status'  	=> 	$status,
					'logs'    	=> 	$logs,
					'mlsimport_progress_properties'	=>	$current,
					'mlsimport_progress_batches'		=>	$total,
					'memory'	=>	$memory,
					'mlsimport_task_to_import'=>$mlsimport_task_to_import,
					'post_id'=>$post_id

				)
			);
		}
		die();
	}






	/**
	 *
	 *
	 *
	 *
	 * Force Stop Import
	 */
	public function mlsimport_stop_import_per_item() {
	

		check_ajax_referer( 'mlsimport_item_actions', 'security' );
		$post_id=0;
		if(isset($_POST['post_id'] )){
				$post_id = intval( $_POST['post_id'] );
		}
		update_option( 'mlsimport_force_stop_' . $post_id, 'yes', false );
		// ensure caches are cleared so running processes see the update immediately
		if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( 'mlsimport_force_stop_' . $post_id, 'options' );
		}
		mlsimport_saas_single_write_import_custom_logs( 'Stopped  for ' . $post_id . PHP_EOL );
		mlsimport_debuglogs_per_plugin( 'Stopped  for ' . $post_id . PHP_EOL );
		wp_send_json_success();
	}



	/*
	 *
	 *  Get MLS Metadata
	 *
	 *
	 *
	 *
	 **/

	public function mlsimport_saas_get_metadata_function() {
		check_ajax_referer( 'mlsimport_saas_get_metadata', 'security' );  
		$theme_Start = new ThemeImport();

		$values  = array();
		$options = get_option( $this->plugin_name . '_admin_options' );
		$url     = 'clients?theme_id=' . intval( $options['mlsimport_theme_used'] );

		$answer = $theme_Start::globalApiRequestSaas( $url, $values, 'GET' );

		update_option( 'mlsimport_mls_metadata_populated', 'yes' );

		update_option( 'mlsimport_mls_metadata_theme_schema', $answer['theme_schema'] );
		update_option( 'mlsimport_mls_metadata_mls_data', $answer['mls_data']['mls_meta_data'] );
		update_option( 'mlsimport_mls_metadata_mls_enums', $answer['mls_data']['mls_meta_enums'] );
	}












	/**
	 *
	 *
	 * write debug logs
	 */
	public function mlsimport_debuglog_cron( $message ) {
		if ( is_array( $message ) ) {
			$message = wp_json_encode( $message );
		}
		$message = date( 'F j, Y, g:i a' ) . ' -> ' . $message;
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$path = WP_PLUGIN_DIR . '/mlsimport/logs/cron_logs.log';

		file_put_contents( $path, $message, FILE_APPEND | LOCK_EX );
	}

}
