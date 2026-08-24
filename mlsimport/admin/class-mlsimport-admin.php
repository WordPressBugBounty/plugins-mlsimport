<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/*
 * ---------------------------------------------------------------------------
 * FILE ROLE: admin-side controller for the whole plugin.
 * ---------------------------------------------------------------------------
 * This ~3,470-line class (Mlsimport_Admin) is the admin monolith referenced in
 * CLAUDE.md. Its hooks are registered by the core Mlsimport class via the
 * Loader. Broadly it owns:
 *   - Asset enqueue for wp-admin (styles, field-selector JS, standalone React
 *     settings app, deactivation survey, searchable selects).
 *   - Admin menu + settings pages (main options page, Import History, and the
 *     standalone theme_id 990 "Design Settings" React page).
 *   - Settings registration + validation callbacks (register_setting) for the
 *     several mlsimport_admin_* option groups.
 *   - The mlsimport_item (Import Task) metaboxes: rendering the import-parameter
 *     form and saving its post meta.
 *   - The MLS connection test and SaaS token/metadata retrieval.
 *   - Building the RESO listing-request arguments from an Import Task's meta.
 *   - The import engine: manual (AJAX), hourly cron per item, and the
 *     background/Action Scheduler batch processors.
 *   - The daily reconciliation sweep (delete/keep local listings vs. the MLS
 *     feed) with a truncated-feed safety guard.
 *   - The plugin-deactivation exit survey (~18 AJAX handlers total live here).
 * NOTE: the enviroment/ directory name is intentionally misspelled plugin-wide;
 * "env_data" is the active theme adapter, "mls_env_data" the MLS provider one.
 * ---------------------------------------------------------------------------
 */


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
	// Back-reference to the core Mlsimport instance (set externally).
	public $main;
	// ThemeImport API client instance (OAuth + all SaaS API calls).
	public $theme_importer;
	// Active theme adapter object (e.g. ResidenceClass); stdClass when no theme.
	public $env_data;
	// Active MLS provider adapter object; stdClass when none configured.
	public $mls_env_data;
	/** @var string Clear adapter error checked before the first listings request. */
	private $stored_listing_configuration_error = '';
	// Reserved handle for a batch/queue processor (declared, assigned elsewhere).
	protected $process_all;
	// One shared Import Task runner, created only when a caller needs it.
	private $import_task_execution;
	// Field-import definition array (populated per request where used).
	public $field_import;
    // Map of supported theme_id => human name (990 standalone, 991-994 themes).
    public $themes;
	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string $plugin_name       The name of this plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		// Store the plugin slug (used as the option-key prefix) and version.
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		// RESO fields that are enum/lookup-driven and shown on the Import Task form.
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

		// theme_id => administrator-facing name. Adapter classes are selected by
		// Mlsimport_Stored_Listing_Adapter_Factory, never derived from these labels.
		$this->themes = array(
			990 => 'Standalone',
			991 => 'WpResidence',
			992 => 'Houzez',
			993 => 'RealHomes',
			994 => 'Wpestate',
		);
	}

	/**
	 * Return the one shared Import Task execution module for this request.
	 *
	 * Manual actions, setup, and hourly cron use this method instead of creating
	 * separate runners. Lazy creation also keeps ordinary admin page requests
	 * from allocating import objects when no import work is requested.
	 *
	 * @return Mlsimport_Import_Task_Execution Shared execution module.
	 */
	public function mlsimport_import_task_execution(): Mlsimport_Import_Task_Execution {
		if ( ! $this->import_task_execution instanceof Mlsimport_Import_Task_Execution ) {
			$environment                 = new Mlsimport_Import_Task_Execution_WordPress_Environment( $this );
			$this->import_task_execution = new Mlsimport_Import_Task_Execution( $environment );
		}

		return $this->import_task_execution;
	}
	/**
	 * Wire up the theme and MLS provider adapter objects for this request.
	 *
	 * Reads the configured theme_id, asks the explicit factory for its adapter,
	 * injects one Stored Listing Write into ThemeImport, and instantiates the Provider Family
	 * adapter (mls_env_data). Provider selection comes from the saved type with
	 * the numeric MLS ID used only for older configurations.
	 *
	 * @param string $plugin_name      Plugin slug passed to ThemeImport.
	 * @param string $mls_enviroment   Legacy argument retained for call compatibility.
	 * @param string $theme_enviroment Legacy ignored theme-environment name.
	 * @since    1.0.0
	 */
	public function admin_setup( $plugin_name, $mls_enviroment, $theme_enviroment ) {

		// Load saved options and resolve the configured theme id (0 when unset).
		$options  = get_option( $this->plugin_name . '_admin_options' );
		$theme_id = 0;
		if ( isset( $options['mlsimport_theme_used'] ) ) {
			$theme_id = intval( $options['mlsimport_theme_used'] );
		}
		unset( $theme_enviroment );
		$this->stored_listing_configuration_error = '';
		try {
			$factory              = new Mlsimport_Stored_Listing_Adapter_Factory();
			$this->env_data       = $factory->create( $theme_id );
			$environment          = new Mlsimport_Stored_Listing_WordPress_Environment();
			$writer               = new Mlsimport_Stored_Listing_Write( $environment, $this->env_data );
			$this->theme_importer = new ThemeImport( $plugin_name, $writer );
		} catch ( UnexpectedValueException $exception ) {
			// Keep ordinary settings screens usable, but expose the exact error to
			// Import Run execution before it requests or mutates a listing.
			$this->env_data                           = new stdClass();
			$this->theme_importer                     = new ThemeImport( $plugin_name );
			$this->stored_listing_configuration_error = $exception->getMessage();
		}

		// Resolve the same Provider Family adapter used by connection, Stored, and
		// Direct MLS callers. The legacy environment-name parameter is ignored.
		$mls_id = isset( $options['mlsimport_mls_name'] )
			? sanitize_text_field( trim( (string) $options['mlsimport_mls_name'] ) )
			: '';
		$this->mls_env_data = Mlsimport_Provider_Family::adapter(
			Mlsimport_Provider_Family::saved_type( $mls_id ),
			$mls_id,
			$this->theme_importer
		);
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * Enqueues the main admin CSS plus the onboarding and field-selector styles.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		// Main admin stylesheet.
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/mlsimport-admin.css', array(), MLSIMPORT_VERSION, 'all' );
		// Onboarding wizard styles.
		wp_enqueue_style( 'mlsimport-onboarding', plugin_dir_url( __FILE__ ) . 'css/mlsimport-onboarding.css', array(), MLSIMPORT_VERSION, 'all' );
		// Drag-and-drop field selector styles.
		wp_enqueue_style( 'mlsimport-field-selector', plugin_dir_url( __FILE__ ) . 'css/mlsimport-field-selector.css', array(), MLSIMPORT_VERSION, 'all' );
	}




	/**
	 * Register the JavaScript for the admin area.
	 *
	 * Enqueues the core admin script, the single Field Configuration controller,
	 * and conditionally (by page/hook) injects inline bootstraps for
	 * metadata fetch and MLS autocomplete, plus the searchable-select and
	 * deactivation-survey scripts on their respective screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @since    1.0.0
	 */
	public function enqueue_scripts($hook_suffix) {
		// jQuery UI autocomplete backs the MLS-name search box.
		wp_enqueue_script( 'jquery-ui-autocomplete' );
		// Pull the cached MLS list (used later for the autocomplete bootstrap).
		$mls_import_list = mlsimport_saas_request_list();
		// Resolve every autocomplete MLS ID through PHP's Provider Family module.
		// The browser receives final credential field names and contains no ranges.
		$provider_mls_ids = array();
		$decoded_mls_list = is_string( $mls_import_list ) ? json_decode( $mls_import_list, true ) : array();
		if ( is_array( $decoded_mls_list ) ) {
			foreach ( $decoded_mls_list as $mls_row ) {
				if ( is_array( $mls_row ) && isset( $mls_row['value'] ) ) {
					$provider_mls_ids[] = (string) $mls_row['value'];
				}
			}
		}
		// Keep the currently saved MLS usable even when an older cached list no
		// longer contains it or the list request temporarily failed.
		$current_options = get_option( $this->plugin_name . '_admin_options', array() );
		if ( is_array( $current_options ) && ! empty( $current_options['mlsimport_mls_name'] ) ) {
			$provider_mls_ids[] = (string) $current_options['mlsimport_mls_name'];
		}
		$provider_mls_ids = array_values( array_unique( $provider_mls_ids ) );
		$saved_provider_type   = (string) get_option( 'mlsimport_provider_type', '' );
		$saved_provider_mls_id = (string) get_option( 'mlsimport_provider_type_mls_id', '' );
		$provider_browser_config = Mlsimport_Provider_Family::browser_config_for_ids(
			$provider_mls_ids,
			$saved_provider_type,
			$saved_provider_mls_id
		);
		// Core admin script + AJAX endpoint.
		wp_enqueue_script( 'mlsimport-admin', plugin_dir_url( __FILE__ ) . 'js/mlsimport-admin.js', array( 'jquery' ), $this->version, true );
		wp_localize_script(
			'mlsimport-admin',
			'mlsimport_vars',
			array(
				'ajax_url'          => admin_url( 'admin-ajax.php' ),
				'provider_families' => $provider_browser_config,
			)
		);
	
		// One controller owns filtering, ordering, every mutation, and the ordered
		// save queue. No second script or cross-script window state is required.
		wp_enqueue_script( 'mlsimport-field-selector', plugin_dir_url( ( __FILE__ ) ) . 'js/mlsimport-field-selector.js', array( 'jquery', 'jquery-ui-sortable', 'jquery-ui-tooltip' ), MLSIMPORT_VERSION, true );
		wp_localize_script(
			'mlsimport-field-selector',
			'mlsimport_params',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'mlsimport_field_selector_nonce' ),
				'action'   => 'mlsimport_change_field_configuration',
				'messages' => array(
					'retry'  => esc_html__( 'Retry save', 'mlsimport' ),
					'reload' => esc_html__( 'Reload configuration', 'mlsimport' ),
				),
			)
		);



		// On the settings page Field Options tab: if metadata was never fetched,
		// auto-trigger the metadata pull on DOM ready.
		if ('toplevel_page_mlsimport_plugin_options' === $hook_suffix &&
			isset($_GET['page']) && $_GET['page'] === 'mlsimport_plugin_options' &&
			isset($_GET['tab']) && $_GET['tab'] === 'field_options') {
			$mlsimport_mls_metadata_populated = get_option( 'mlsimport_mls_metadata_populated', '' );
			if ( 'yes' !==  $mlsimport_mls_metadata_populated  ) {
				$inline_script = 'jQuery(document).ready(function($){ mlsimport_saas_get_metadata(); });';
				wp_add_inline_script('mlsimport-admin', $inline_script);
			}
		}

		/*
		 * Same auto-metadata bootstrap, but for the onboarding wizard screen.
		 *
		 * Gated on the page slug only. The wizard's $hook_suffix is NOT
		 * 'admin_page_mlsimport-onboarding': it is registered as a submenu of the
		 * "MLS Import Settings" menu, and WordPress builds a submenu hook from the
		 * sanitized parent menu TITLE, so the real hook is
		 * 'mls-import-settings_page_mlsimport-onboarding'. Testing the old string
		 * meant this block never ran, the metadata pull was never triggered, and the
		 * wizard's Field Mapping step sat on "Please Stand By!" forever.
		 */
		if ( isset($_GET['page']) && $_GET['page'] === 'mlsimport-onboarding' ) {
			$mlsimport_mls_metadata_populated = get_option('mlsimport_mls_metadata_populated', '');
			if ('yes' !== $mlsimport_mls_metadata_populated) {
				$inline_script = 'jQuery(document).ready(function($){  mlsimport_saas_get_metadata(); });';
				wp_add_inline_script('mlsimport-admin', $inline_script);
			}
		}




		// On the settings Display Options tab (or the page with no tab), seed the
		// MLS-name autocomplete with the fetched list when it is not an array.
		if ('toplevel_page_mlsimport_plugin_options' === $hook_suffix &&
			( isset($_GET['page']) && $_GET['page'] === 'mlsimport_plugin_options' && isset($_GET['tab']) && $_GET['tab'] === 'display_options') ||
			(isset($_GET['page']) && $_GET['page'] === 'mlsimport_plugin_options'  && !isset($_GET['tab']) ) ) {
			
				// Re-fetch the MLS list and, when it is a raw string payload,
				// hand it to the JS autocomplete initializer.
				$mls_import_list = mlsimport_saas_request_list();
				if(!is_array($mls_import_list)){
					$inline_script = 'jQuery(document).ready(function($){ var autofill='.wp_kses_post($mls_import_list).';mlsimport_autocomplte_mls_selection(autofill);  });';
					wp_add_inline_script('mlsimport-admin', $inline_script);
				}
		}

		// Searchable City/County multi-select — only on the Import Task edit screen.
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type = $screen ? (string) $screen->post_type : '';
		if ( $this->mlsimport_is_import_task_edit_screen( (string) $hook_suffix, $post_type ) ) {
			wp_enqueue_script( 'mlsimport-searchable-select', plugin_dir_url( __FILE__ ) . 'js/mlsimport-searchable-select.js', array(), MLSIMPORT_VERSION, true );
		}

		// Deactivation exit survey — only needed on the Plugins screen.
		if ( 'plugins.php' === $hook_suffix ) {
			// Enqueue the survey modal script and hand it the nonce, options and i18n.
			wp_enqueue_script( 'mlsimport-deactivation-survey', plugin_dir_url( __FILE__ ) . 'js/mlsimport-deactivation-survey.js', array( 'jquery' ), MLSIMPORT_VERSION, true );
			wp_localize_script( 'mlsimport-deactivation-survey', 'mlsimport_deact_survey', array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'mlsimport_exit_survey' ),
				'plugin_basename' => plugin_basename( MLSIMPORT_PLUGIN_PATH . 'mlsimport.php' ),
				'options'         => $this->get_exit_survey_options(),
				'i18n'            => array(
					'title'             => esc_html__( 'Before you go — quick question', 'mlsimport' ),
					'intro'             => esc_html__( 'Why are you deactivating MLS Import? Your answer helps us improve.', 'mlsimport' ),
					'other_placeholder' => esc_html__( 'Tell us more (optional)', 'mlsimport' ),
					'submit'            => esc_html__( 'Submit & Deactivate', 'mlsimport' ),
					'skip'              => esc_html__( 'Skip & Deactivate', 'mlsimport' ),
				),
			) );
		}

	}





	/**
	 * Register the administration menu for this plugin into the WordPress Dashboard menu.
	 *
	 * Adds the top-level "MLS Import Settings" page and the "Import History"
	 * submenu; in standalone mode it also adds the separate "Design Settings"
	 * React page and enqueues its bundle only on that hook.
	 *
	 * @since    1.0.0
	 */
	public function add_plugin_admin_menu() {
		// Top-level settings menu (capability: administrator).
		add_menu_page(
			esc_html__( 'MLS Import Settings', 'mlsimport'),
			esc_html__( 'MLS Import Settings', 'mlsimport' ),
			'administrator',
			'mlsimport_plugin_options',
			array( $this, 'display_plugin_setup_page' ),
			MLSIMPORT_PLUGIN_URL . '/img/mlsimport_menu.png',
			// Fractional slot right after Import Tasks (21). Fractions are only
			// honoured by add_menu_page, so the two settings pages take 21.1/21.2
			// and leave integer slots 22/23 for the Properties/Agents CPTs — keeping
			// all MLSImport menus grouped above core Comments (25).
			21.1
		);

		// Import History submenu under the settings menu.
		add_submenu_page(
			'mlsimport_plugin_options',
			esc_html__( 'Import History', 'mlsimport' ),
			esc_html__( 'Import History', 'mlsimport' ),
			'administrator',
			'mlsimport_history',
			array( $this, 'display_history_page' )
		);

		// Standalone (theme_id 990) front-end design. Its own top-level menu,
		// deliberately separate from MLS import settings because it controls
		// the public-facing visuals. React app; see admin/settings-app/.
		if ( function_exists( 'mlsimport_is_standalone_mode' ) && mlsimport_is_standalone_mode() ) {
			// Separate top-level menu for the standalone front-end design app.
			$standalone_hook = add_menu_page(
				esc_html__( 'MLS Import Design Settings', 'mlsimport' ),
				esc_html__( 'MLS Import Design Settings', 'mlsimport' ),
				'manage_options',
				'mlsimport_standalone_settings',
				array( $this, 'display_standalone_settings_page' ),
				MLSIMPORT_PLUGIN_URL . '/img/mlsimport_menu.png',
				21.2
			);

			// Load the React bundle only when this exact page hook is rendering.
			add_action(
				'admin_enqueue_scripts',
				function ( $current_hook ) use ( $standalone_hook ) {
					if ( $current_hook === $standalone_hook ) {
						$this->enqueue_standalone_settings_app();
					}
				}
			);
		}
	}

	/**
	 * Render the Standalone Design page — just the React mount point. All
	 * fields, save and validation live in the app (admin/settings-app/) and the
	 * settings REST endpoint.
	 *
	 * @return void
	 */
	public function display_standalone_settings_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MLS Import Design Settings', 'mlsimport' ) . '</h1>';
		echo '<div id="mlsimport-standalone-app"></div>';
		echo '</div>';
	}

	/**
	 * Enqueue the compiled Standalone Design React bundle and its WP component
	 * styles. Dependencies + cache-busting version come from the build's
	 * generated index.asset.php.
	 *
	 * @return void
	 */
	private function enqueue_standalone_settings_app() {
		// The build emits index.asset.php with dependencies + a content hash;
		// bail quietly if the app was never built.
		$asset_file = MLSIMPORT_PLUGIN_PATH . 'admin/settings-app/build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		// The MLS-logo control opens the native WordPress media modal (wp.media).
		wp_enqueue_media();

		// wp-color-picker (Iris) powers the native colour control in the React app;
		// it pulls in jQuery + iris, so the window.jQuery global is available.
		wp_enqueue_script(
			'mlsimport-standalone-settings',
			MLSIMPORT_PLUGIN_URL . 'admin/settings-app/build/index.js',
			array_merge( $asset['dependencies'], array( 'wp-color-picker' ) ),
			$asset['version'],
			true
		);
		// Enable JS translation loading for the app's strings.
		wp_set_script_translations( 'mlsimport-standalone-settings', 'mlsimport' );

		// The field tree the React app renders from — tabs/sub-tabs/fields generated
		// from the ONE registry (mlsimport_standalone_settings_app_config). The app
		// reads window.mlsimportFields instead of a hard-coded list, so a field added
		// to the registry appears here (and, via the schema, in the Customizer) with
		// no JS change.
		if ( function_exists( 'mlsimport_standalone_settings_app_config' ) ) {
			wp_add_inline_script(
				'mlsimport-standalone-settings',
				'window.mlsimportFields = ' . wp_json_encode( mlsimport_standalone_settings_app_config() ) . ';',
				'before'
			);
		}

		// Feed the "Arrange Sections" control its catalog (slug + label) from the
		// property section registry, so the list matches what the front end renders.
		if ( function_exists( 'mlsimport_standalone_section_catalog' ) ) {
			$catalog = array();
			foreach ( mlsimport_standalone_section_catalog() as $slug => $label ) {
				$catalog[] = array( 'slug' => $slug, 'label' => $label );
			}
			wp_add_inline_script(
				'mlsimport-standalone-settings',
				'window.mlsimportSections = ' . wp_json_encode( $catalog ) . ';',
				'before'
			);
		}

		// The Overview "Arrange Fields" control reads the Overview tile catalog — the
		// stat tiles the Overview section can draw (Updated, MLS #, Bedrooms, …).
		if ( function_exists( 'mlsimport_standalone_overview_fields_catalog' ) ) {
			$overview_fields = array();
			foreach ( mlsimport_standalone_overview_fields_catalog() as $slug => $label ) {
				$overview_fields[] = array( 'slug' => $slug, 'label' => $label );
			}
			wp_add_inline_script(
				'mlsimport-standalone-settings',
				'window.mlsimportOverviewFields = ' . wp_json_encode( $overview_fields ) . ';',
				'before'
			);
		}

		// The agent "Arrange Sections" control reads its own catalog (the agent page's
		// reorderable content-column sections), kept separate from the property catalog.
		if ( function_exists( 'mlsimport_standalone_agent_section_catalog' ) ) {
			$agent_catalog = array();
			foreach ( mlsimport_standalone_agent_section_catalog() as $slug => $label ) {
				$agent_catalog[] = array( 'slug' => $slug, 'label' => $label );
			}
			wp_add_inline_script(
				'mlsimport-standalone-settings',
				'window.mlsimportAgentSections = ' . wp_json_encode( $agent_catalog ) . ';',
				'before'
			);
		}

		// The archive "Taxonomy filters" control reads its own catalog (the search
		// form's toggleable filter fields), so the on/off toggle list matches what
		// the taxonomy/CPT archive search bar can render.
		if ( function_exists( 'mlsimport_standalone_archive_filters_catalog' ) ) {
			$archive_filters = array();
			foreach ( mlsimport_standalone_archive_filters_catalog() as $slug => $label ) {
				$archive_filters[] = array( 'slug' => $slug, 'label' => $label );
			}
			wp_add_inline_script(
				'mlsimport-standalone-settings',
				'window.mlsimportArchiveFilters = ' . wp_json_encode( $archive_filters ) . ';',
				'before'
			);
		}

		// The saved MLS logo's preview URL, so the media control can show the
		// current image before the user opens the picker.
		if ( function_exists( 'mlsimport_standalone_mls_logo_url' ) ) {
			wp_add_inline_script(
				'mlsimport-standalone-settings',
				'window.mlsimportLogoUrl = ' . wp_json_encode( mlsimport_standalone_mls_logo_url() ) . ';',
				'before'
			);
		}

		// Component + color-picker styles the React controls rely on, then the
		// app's own stylesheet. Cache-bust by file mtime so edits to the CSS are
		// picked up immediately — the plugin version (MLSIMPORT_VERSION) doesn't
		// change between design tweaks, so keying the ?ver on it left browsers
		// serving a stale cached copy under the same URL.
		$standalone_css_path = MLSIMPORT_PLUGIN_PATH . 'admin/css/mlsimport-standalone-settings.css';
		$standalone_css_ver  = file_exists( $standalone_css_path )
			? (string) filemtime( $standalone_css_path )
			: ( defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : false );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style(
			'mlsimport-standalone-settings',
			MLSIMPORT_PLUGIN_URL . 'admin/css/mlsimport-standalone-settings.css',
			array( 'wp-components' ),
			$standalone_css_ver
		);
	}

	/**
	 * Renders the Import History admin page.
	 *
	 * @return void
	 */
	public function display_history_page() {
		// Delegates the whole page to the history partial template.
		include_once plugin_dir_path( __FILE__ ) . 'partials/mlsimport-history.php';
	}








	/**
	 * Add a "Settings" action link to this plugin's row on the Plugins page.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Links with the Settings link prepended.
	 * @since    1.0.0
	 */
	public function add_action_links( $links ) {
		// Build the Settings link and place it before the default action links.
		$settings_link = array(
			'<a href="' . admin_url( 'admin.php?page=mlsimport_plugin_options' ) . '">' . esc_html__( 'Settings', 'mlsimport') . '</a>',
		);
		return array_merge( $settings_link, $links );
	}








	/**
	 * Render the main settings page for this plugin.
	 *
	 * Loads the admin-display partial (whose filename is prefixed with the slug).
	 *
	 * @since    1.0.0
	 */
	public function display_plugin_setup_page() {
		// Delegates the whole page to the slug-prefixed admin-display partial.
		include_once 'partials/' . $this->plugin_name . '-admin-display.php';
	}






	/**
	 * Sanitize/whitelist the main plugin options on save (register_setting callback).
	 *
	 * Copies only the known keys from $input (esc_attr'd), then invalidates the
	 * connection-test / metadata flags and cached tokens/schema so the next page
	 * load re-tests the connection with the new credentials.
	 *
	 * @param array $input Raw submitted options.
	 * @return array Whitelisted, escaped options.
	 * @since    1.0.0
	 */
	public function validate_admin_options( $input ) {
		// Compare the selected MLS before and after this save. Provider credentials
		// remain stored, but state belonging to a different MLS is invalidated.
		$previous_options = get_option( $this->plugin_name . '_admin_options', array() );
		$previous_options = is_array( $previous_options ) ? $previous_options : array();
		$previous_mls_id  = isset( $previous_options['mlsimport_mls_name'] )
			? (string) $previous_options['mlsimport_mls_name']
			: '';

		// Whitelist of accepted option keys (value = label/help metadata, unused
		// beyond documentation here); anything not listed is dropped on save.
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
			'mlsimport_brightmls_client_id'       => array(
				'name'    => esc_html__( 'MLSImport BrightMLS Client id', 'mlsimport' ),
				'details' => 'to be added',
			),

			'mlsimport_brightmls_client_secret'   => array(
				'name'    => esc_html__( 'MLSImport BrightMLS Secret', 'mlsimport' ),
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

		// Copy each whitelisted key; missing/empty => ''. Credential fields
		// (passwords/secrets/tokens) are stored verbatim — esc_attr() would
		// entity-encode & < > " ' and corrupt them on every re-save (#204).
		// Non-credential fields keep the historical esc_attr() treatment.
		foreach ( $settings_list as $key => $setting ) {
			if ( ! isset( $input[ $key ] ) || empty( $input[ $key ] ) ) {
				$valid[ $key ] = '';
			} elseif ( mlsimport_is_credential_key( $key ) ) {
				$valid[ $key ] = trim( (string) $input[ $key ] );
			} else {
				$valid[ $key ] = esc_attr( $input[ $key ] );
			}
		}

		$new_mls_id = isset( $valid['mlsimport_mls_name'] ) ? (string) $valid['mlsimport_mls_name'] : '';
		if ( $previous_mls_id !== $new_mls_id ) {
			Mlsimport_Provider_Family::clear_active_state();
		} else {
			// Same MLS but possibly new credentials: force the next request to log in again.
			Mlsimport_Provider_Family::clear_access_tokens();
		}

		// Credentials may have changed: force a fresh connection test + metadata pull.
		delete_option( 'mlsimport_connection_test' );
		delete_option( 'mlsimport_mls_metadata_populated' );

		// Reset cached encoding and drop cached token/schema transients.
		update_option( 'mlsimport_encoding_array', '' );
		delete_transient( 'mlsimport_token_request' );
		delete_transient( 'mlsimport_schema' );
		delete_transient( 'mlsimport_plugin_data_schema' );

		delete_transient( 'mlsimport_saas_token' );
		return $valid;
	}





	/**
	 * Validate the MLS-sync option group on save (register_setting callback).
	 *
	 * Copies a fixed whitelist of sync/import parameter keys straight through.
	 *
	 * @param array $input Raw submitted sync settings.
	 * @return array Whitelisted sync settings.
	 * @since    1.0.0
	 */
	public function validate_admin_mls_sync( $input ) {
		$valid = array();

		// Fixed whitelist of sync parameters (price, title, agent/user, the enum
		// filters and their "select-all" _check flags).
		$field_import = array( 'force_rand', 'min_price', 'max_price', 'title_format', 'property_agent', 'property_user', 'City', 'City_check', 'CountyOrParish', 'CountyOrParish_check', 'MlsStatus', 'MlsStatus_check', 'PropertySubType', 'PropertySubType_check', 'PropertyType', 'PropertyType_check',
		'StandardStatus_delete', 'StandardStatus_delete_check', 'InternetEntireListingDisplayYN', 'InternetAddressDisplayYN' );
		// Pass each whitelisted key through unchanged.
		foreach ( $field_import as $key ) {
			$valid[ $key ] = $input[ $key ];
		}

		return $valid;
	}


	/**
	 * Validate the administrative options group on save (register_setting callback).
	 *
	 * Only carries the raw "import" payload through (a JSON blob of exported settings).
	 *
	 * @param array $input Raw submitted administrative options.
	 * @return array Whitelisted administrative options.
	 * @since    1.0.0
	 */
	public function validate_administrative_options( $input ) {

		$valid = array();

		// Pass the single 'import' payload through.
		$field_import = array( 'import' );
		foreach ( $field_import as $key ) {
			$valid[ $key ] = $input[ $key ];
		}

		return $valid;
	}

	/**
	 * Validate the import-options group on save (register_setting callback).
	 *
	 * Casts import_number to int, and when an 'import' JSON payload is present it
	 * restores the field-select / mls-sync / import-options / transients options
	 * from it (used by the settings import/export feature).
	 *
	 * @param array $input Raw submitted import options.
	 * @return array Whitelisted import options.
	 * @since    1.0.0
	 */
	public function validate_admin_import_options( $input ) {
		$valid = array();

		// import_number is numeric-only.
		$field_import = array( 'import_number' );
		foreach ( $field_import as $key ) {
			$valid[ $key ] = intval( $input[ $key ] );
		}

		// When an exported-settings JSON blob is supplied, decode it and restore
		// the four related option groups from it.
		if ( isset( $input['import'] ) &&  '' !==  $input['import'] ) {
			$decode = json_decode( $input['import'], true );
			if ( is_array( $decode ) && isset( $decode['mlsimport_admin_fields_select'] ) && is_array( $decode['mlsimport_admin_fields_select'] ) ) {
				mlsimport_import_field_configuration( $decode['mlsimport_admin_fields_select'] );
			}
			if ( is_array( $decode ) && isset( $decode['mlsimport_admin_mls_sync'] ) ) {
				update_option( 'mlsimport_admin_mls_sync', $decode['mlsimport_admin_mls_sync'] );
			}
			if ( is_array( $decode ) && isset( $decode['mlsimport_admin_import_options'] ) ) {
				update_option( 'mlsimport_admin_import_options', $decode['mlsimport_admin_import_options'] );
			}
			if ( is_array( $decode ) && isset( $decode['mlsimport_admin_use_transients'] ) ) {
				update_option( 'mlsimport_admin_use_transients', $decode['mlsimport_admin_use_transients'] );
			}
		}

		return $valid;
	}






	/**
	 * Register all plugin option groups with the Settings API and bind each to
	 * its validation callback. Hooked on admin_init.
	 */
	public function options_update() {
		// Field Configuration is intentionally absent: it is form-free and only the
		// deep module's compact command endpoint may mutate its option.
		register_setting( $this->plugin_name . '_admin_options', $this->plugin_name . '_admin_options', array( $this, 'validate_admin_options' ) );
		register_setting( $this->plugin_name . '_admin_mls_sync', $this->plugin_name . '_admin_mls_sync', array( $this, 'validate_admin_mls_sync' ) );
		register_setting( $this->plugin_name . '_admin_import_options', $this->plugin_name . '_admin_import_options', array( $this, 'validate_admin_import_options' ) );
		register_setting( $this->plugin_name . '_administrative_options', $this->plugin_name . '_administrative_options', array( $this, 'validate_administrative_options' ) );
		// The standalone option is registered in class-mlsimport-standalone-settings.php
		// (on init, with show_in_rest) so the dedicated React design page can read/write it.
	}

	/**
	 * Update-option hook for the administrative options group.
	 *
	 * When the administrative options carry an 'import' JSON payload, decode it
	 * and restore the field-select / mls-sync / import-options option groups.
	 */
	public function update_option_mlsimport_administrative_options() {
		// Read the saved administrative options and, if present, restore the
		// three related option groups from the embedded JSON payload.
		$import = get_option( 'mlsimport_administrative_options' );
		if ( '' !==  $import  ) {
			$decode = json_decode( $import['import'], true );
			if ( is_array( $decode ) && isset( $decode['mlsimport_admin_fields_select'] ) && is_array( $decode['mlsimport_admin_fields_select'] ) ) {
				mlsimport_import_field_configuration( $decode['mlsimport_admin_fields_select'] );
			}
			if ( is_array( $decode ) && isset( $decode['mlsimport_admin_mls_sync'] ) ) {
				update_option( 'mlsimport_admin_mls_sync', $decode['mlsimport_admin_mls_sync'] );
			}
			if ( is_array( $decode ) && isset( $decode['mlsimport_admin_import_options'] ) ) {
				update_option( 'mlsimport_admin_import_options', $decode['mlsimport_admin_import_options'] );
			}
		}
	}

	/**
	 * Update-option hook for the field-select group: ask the active theme
	 * adapter to (re)register its custom fields/taxonomies for the mapped fields.
	 */
	public function update_option_mlsimport_admin_fields_select() {

		// Delegate to the theme adapter to sync its custom fields.
		$this->env_data->enviroment_custom_fields( $this->plugin_name );
	}


	/**
	 * Register the "Hidden Fields" metabox on the theme's property post type.
	 *
	 * Only added when the theme adapter exposes get_property_post_type().
	 */
	public function mlsimport_meta_options() {
		// Add the metabox to whatever post type the active theme uses for listings.
		if ( method_exists( $this->env_data, 'get_property_post_type' ) ) {
			add_meta_box( 'mlsimport_hidden_fields', esc_html__( 'Mls Import Hidden Fields', 'mlsimport' ), array( $this, 'mlsimport_hidden_fields' ), $this->env_data->get_property_post_type(), 'normal', 'low' );
		}
	}

	/**
	 * Render the "Hidden Fields" metabox for a single property post.
	 *
	 * Shows the ListingKey, the source Import Task (inserted/updated), any
	 * protected statuses, every admin-flagged imported field value, and the
	 * property change history.
	 */
	public function mlsimport_hidden_fields() {
		global $post;

		// The active projection keeps disappeared MLS fields out of the metabox.
		$options = mlsimport_active_field_configuration();

		// Which Import Task created / last updated this property, and its RESO key.
		$MLSimport_item_inserted = get_post_meta( $post->ID, 'MLSimport_item_inserted', true );
		$MLSimport_item_updated = get_post_meta( $post->ID, 'MLSimport_item_updated', true );
		$listing_key = get_post_meta( $post->ID, 'ListingKey', true );

		// Get the import task ID to retrieve protected statuses
		// (prefer the inserting task, fall back to the updating task).
		$import_task_id = !empty( $MLSimport_item_inserted ) ? $MLSimport_item_inserted : ( !empty( $MLSimport_item_updated ) ? $MLSimport_item_updated : null );
		$mlsImportItemStatusProtect = $import_task_id ? get_post_meta( $import_task_id, 'mlsimport_item_standardstatusprotect', true ) : null;

		// Check if the ListingKey exists
		if ( !empty( $listing_key ) ) {
			echo 'ListingKey: ' . esc_html( $listing_key ) . '<br>';
		}

		// Check if MLSimport_item_inserted exists
		if ( !empty( $MLSimport_item_inserted ) ) {
			echo 'Added via MLS item id: ' . esc_html( $MLSimport_item_inserted ) . ' - ' . esc_html( get_the_title( $MLSimport_item_inserted ) ) . '<br>';
		}

		// Check if MLSimport_item_updated exists
		if ( !empty( $MLSimport_item_updated ) ) {
			echo 'Updated via MLS item id: ' . esc_html( $MLSimport_item_updated ) . ' - ' . esc_html( get_the_title( $MLSimport_item_updated ) ) . '<br>';
		}

		// Show any protected statuses (array or scalar) configured on the task.
		if(!empty($mlsImportItemStatusProtect)) {
			if(is_array($mlsImportItemStatusProtect)) {
				echo 'Protected statuses: ' .  esc_html( implode(', ', $mlsImportItemStatusProtect) ) . '<br>';
			} else {
				echo 'Protected statuses: ' .  esc_html($mlsImportItemStatusProtect) . '<br>';
			}
		}

		// Print each admin-flagged field: label + stored meta value.
		foreach ( ( is_array( $options ) && ! empty( $options['mls-fields-admin'] ) ? $options['mls-fields-admin'] : array() ) as $key => $value ) {
			// Only fields explicitly marked for admin display (flag === 1).
			if ( 1 === intval($options['mls-fields-admin'][ $key ] ) ) {
				// Prefer a custom label if one was set for this field.
				$display_label = $key;
				if ( isset( $options['mls-fields-label'][ $key ] ) &&  '' !== $options['mls-fields-label'][ $key ] ) {
					$display_label = $options['mls-fields-label'][ $key ];
				}

				// Resolve the stored value. Standalone (990) stores every imported
				// field as mlsimport_<Field> (with an _x_ fallback); the theme modes
				// store them lowercase (except ListingKey). Reading the wrong casing
				// is why hidden fields (e.g. ParcelNumber) showed here without a value.
				if ( function_exists( 'mlsimport_is_standalone_mode' ) && mlsimport_is_standalone_mode() && function_exists( 'mlsimport_property_field_value' ) ) {
					$field_value = mlsimport_property_field_value( (int) $post->ID, (string) $key );
				} else {
					$meta_key    = ( 'ListingKey' !== $key ) ? strtolower( $key ) : $key;
					$field_value = (string) get_post_meta( $post->ID, $meta_key, true );
				}
				?>

				<strong><?php echo esc_html($display_label);?>:</strong>
				<?php echo esc_html( $field_value ); ?> </br>
				<?php
			}
		}
		?>
		
		<h2 style="font-weight:bold;padding-left:0px;">Mls Import History</h2>
		<?php
		// Property change history (only populated when history logging is enabled).
		$meta = get_post_meta( $post->ID, 'mlsimport_property_history', true );
		if ( '' === trim( $meta )  ) { ?>
			<strong>Property history is blank - you can enable it in Settings/ Tools page </strong>
		<?php
		} else {
			print wp_kses_post($meta);
		}
	}




	/**
	 * AJAX (Tools page): clear all MLSImport caches/transients and the
	 * metadata-populated flag, forcing the next request to re-fetch everything.
	 */
        function mlsimport_delete_cache() {

		// CSRF: Tools-page nonce.
		check_ajax_referer( 'mlsimport_tool_actions', 'security' );

		// Drop every cached token/metadata/schema transient.
		delete_transient( 'mlsimport_token_request' );
		delete_transient( 'mlsimport_metadata_api_call_data_service_property' );
		delete_transient( 'mls_import_meta_enums' );
		delete_transient( 'mls_import_meta' );
		delete_transient( 'mlsimport_plugin_data_schema' );
		delete_transient( 'mlsimport_ready_to_go_mlsimport_data' );
		delete_transient( 'mlsimport_saas_token' );

                // Force a fresh metadata pull next load.
                delete_option( 'mlsimport_mls_metadata_populated' );

                die( 'deleted' );
        }

        /**
         * AJAX (Tools page): reset the field-mapping configuration so the field
         * selector starts fresh (also clears the metadata-populated flag).
         */
        function mlsimport_clear_fields_data() {

                // CSRF: Tools-page nonce.
                check_ajax_referer( 'mlsimport_tool_actions', 'security' );

                // Wipe the metadata flag and the saved field-select configuration.
                delete_option( 'mlsimport_mls_metadata_populated' );
                delete_option( 'mlsimport_admin_fields_select' );

                die( 'deleted' );
        }

	/**
	 * AJAX (Tools page): return the terms of a taxonomy for the "delete
	 * properties by term" picker. Admin-only; validates the taxonomy exists.
	 *
	 * @return void Emits a JSON success payload of {slug,name,count} rows.
	 */
	function mlsimport_get_taxonomy_terms() {
		// CSRF + capability.
		check_ajax_referer( 'mlsimport_tool_actions', 'security' );
		if ( ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		// Reject unknown taxonomies.
		$taxonomy = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ) );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			wp_send_json_error( 'Invalid taxonomy' );
		}

		// Fetch all terms (including empties) and flatten to slug/name/count.
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ) );
		$result = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$result[] = array(
					'slug'  => $term->slug,
					'name'  => $term->name,
					'count' => $term->count,
				);
			}
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX (Tools page): delete imported properties matching selected taxonomy
	 * terms, in batches of 20. Admin-only. Reports progress so the client can
	 * loop until done; refreshes term counts once the last batch completes.
	 *
	 * @return void Emits a JSON success payload {deleted,remaining,total,done}.
	 */
	function mlsimport_delete_properties() {
		global $mlsimport;

		// CSRF + capability.
		check_ajax_referer( 'mlsimport_tool_actions', 'security' );

		if ( ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		// Selected taxonomy and its chosen term slugs.
		$taxonomy = sanitize_text_field( wp_unslash( $_POST['mlsimport_delete_category'] ) );
		$terms    = array();

		// Collect and sanitize the selected term slugs.
		if ( isset( $_POST['mlsimport_delete_category_term'] ) && is_array( $_POST['mlsimport_delete_category_term'] ) ) {
			foreach ( $_POST['mlsimport_delete_category_term'] as $term ) {
				$terms[] = sanitize_text_field( wp_unslash( $term ) );
			}
		}

		// Require a taxonomy.
		if ( '' === $taxonomy ) {
			wp_send_json_error( esc_html__( 'Please select a taxonomy', 'mlsimport' ) );
		}

		// Require at least one term.
		if ( empty( $terms ) ) {
			wp_send_json_error( esc_html__( 'Please select at least one term', 'mlsimport' ) );
		}

		// Query one page of property IDs matching the term selection.
		$post_type = $mlsimport->admin->env_data->get_property_post_type();

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 20,
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $terms,
				),
			),
			'fields'         => 'ids',
		);

		$prop_selection = new WP_Query( $args );
		$deleted        = 0;

		// Delete each property in this batch via the theme importer's SQL delete.
		foreach ( $prop_selection->posts as $delete_id ) {
			$mlsimport->admin->theme_importer->mlsimportSaasDeletePropertyViaMysql( $delete_id, ' delete from tools ' );
			++$deleted;
		}

		// Compute how many still match after this batch; done when none remain.
		$remaining = $prop_selection->found_posts - $deleted;
		$done      = ( $remaining <= 0 );

		// Update term counts only when all deletions are complete
		if ( $done ) {
			// Recount every taxonomy on the property post type in one pass.
			$all_taxonomies = get_object_taxonomies( $post_type );
			foreach ( $all_taxonomies as $tax_name ) {
				$all_terms = get_terms( array( 'taxonomy' => $tax_name, 'hide_empty' => false, 'fields' => 'ids' ) );
				if ( ! is_wp_error( $all_terms ) && ! empty( $all_terms ) ) {
					wp_update_term_count_now( $all_terms, $tax_name );
				}
			}
		}

		// Report progress back to the client loop.
		wp_send_json_success( array(
			'deleted'   => $deleted,
			'remaining' => max( 0, $remaining ),
			'total'     => $prop_selection->found_posts,
			'done'      => $done,
		) );
	}








	/**
	 * Convert a PHP shorthand byte value (e.g. "256M", "1G", "-1") to bytes.
	 *
	 * @param  string|int $value Raw ini/constant value.
	 * @return int               Bytes, or -1 for an unlimited (-1) setting.
	 */
	private function mlsimport_parse_bytes( $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
					return 0;
			}
			if ( '-1' === $value ) {
					return -1; // Unlimited.
			}
			$unit   = strtolower( substr( $value, -1 ) );
			$number = (int) $value;
			switch ( $unit ) {
					case 'g':
							$number *= 1024 * 1024 * 1024;
							break;
					case 'm':
							$number *= 1024 * 1024;
							break;
					case 'k':
							$number *= 1024;
							break;
			}
			return $number;
	}

	/**
	 * Print admin warnings when the PHP/WordPress environment is too constrained
	 * for large imports (effective memory below 256MB, or a positive
	 * max_execution_time below 600s). Suppressed during AJAX and on the
	 * onboarding screen.
	 *
	 * Memory is judged from the effective runtime limit: the larger of
	 * WP_MEMORY_LIMIT (wp-config) and the actual PHP ini memory_limit
	 * (which may be raised at the server/php.ini level), and -1 counts as
	 * unlimited. This avoids a false warning when memory is fine but only set
	 * outside wp-config.php.
	 */
	public function mlsimport_saas_setting_up() {
			// Do not output warnings during AJAX requests
			if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ||
					( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
					return;
			}

			// Skip all warnings on the onboarding wizard.
			$is_onboarding = isset( $_GET['page'] ) && 'mlsimport-onboarding' === $_GET['page'];
			if ( $is_onboarding ) {
					return;
			}

			// Effective memory limit: the larger of wp-config's WP_MEMORY_LIMIT and
			// the actual PHP runtime limit; either being -1 means unlimited.
			$min_bytes   = 256 * 1024 * 1024;
			$wp_bytes    = $this->mlsimport_parse_bytes( WP_MEMORY_LIMIT );
			$php_bytes   = $this->mlsimport_parse_bytes( ini_get( 'memory_limit' ) );
			$memory_ok   = ( -1 === $wp_bytes ) || ( -1 === $php_bytes )
							|| ( $wp_bytes >= $min_bytes ) || ( $php_bytes >= $min_bytes );

			// Memory-limit warning.
			if ( ! $memory_ok ) { ?>
					<div class="mlsimport_warning long_warning">
							<?php
							printf(
								/* translators: 1: current WordPress memory limit, 2: URL to the WordPress documentation on increasing memory. */
								wp_kses(
									__( '<strong>WordPress Memory Limit</strong> is set to <strong>%1$s</strong>. Allocated Memory should be at least <strong>256MB</strong>. Please refer to: <a href="%2$s" target="_blank">Increasing memory allocated to PHP</a>', 'mlsimport' ),
									array(
										'strong' => array(),
										'a'      => array(
											'href'   => array(),
											'target' => array(),
										),
									)
								),
								esc_html( WP_MEMORY_LIMIT ),
								'https://wordpress.org/support/article/editing-wp-config-php/#increasing-memory-allocated-to-php'
							);
							?>
					</div>
			<?php
			}

			// Execution-time warning: 0 or -1 means unlimited (fine); only a
			// positive value below 600s is flagged.
			$max_time = (int) ini_get( 'max_execution_time' );
			if ( $max_time > 0 && $max_time < 600 ) {
			?>
				<div class="mlsimport_warning long_warning">
				<?php
				printf(
					/* translators: %s: current max_execution_time value. */
					wp_kses(
						__( 'Your <strong>max_execution_time</strong> setting in php is set to <strong>%s</strong>. Importing hundreds of listings requires extra time. Please set max_execution_time to <strong>0 (unlimited)</strong>. If that is not possible, set it to a minimum of <strong>600 (10 minutes)</strong>.', 'mlsimport' ),
						array( 'strong' => array() )
					),
					esc_html( $max_time )
				);
				?>
				</div>

			<?php
			}
	}

        /**
         * Test the configured MLS credentials against the SaaS API.
         *
		 * Resolves the selected Provider Family, asks its adapter for the exact
		 * active credentials, PATCHes only those values to the 'clients' endpoint,
		 * and stores/clears the connection flag from the API result.
         *
         * @since    4.0.1
         * @return array|void The API response, or void on an early return.
         */
	public function mlsimport_saas_check_mls_connection() {

		// Resolve the active Provider Family once. A saved type is authoritative;
		// older settings without one use the module's numeric compatibility map.
		$options    = get_option( $this->plugin_name . '_admin_options' );
		$options    = is_array( $options ) ? $options : array();
		$mls_id     = isset( $options['mlsimport_mls_name'] )
			? sanitize_text_field( trim( $options['mlsimport_mls_name'] ) )
			: '';
		$saved_type = Mlsimport_Provider_Family::saved_type( $mls_id );
		$provider   = Mlsimport_Provider_Family::adapter( $saved_type, $mls_id, $this->theme_importer );

		// Build one safe payload. Missing credentials and unsupported saved types
		// stop here, before the network request, with the module's stable error.
		if ( ! $provider->supported() ) {
			delete_option( 'mlsimport_connection_test' );
			return array(
				'success' => false,
				'error'   => $provider->error(),
			);
		}

		$payload_result = $provider->connection_test_payload( $options, $mls_id );
		if ( ! $payload_result['success'] ) {
			delete_option( 'mlsimport_connection_test' );
			return array(
				'success' => false,
				'error'   => $payload_result['error'],
				'missing' => $payload_result['missing'],
			);
		}
		$values = $payload_result['payload'];

		// PATCH the credentials to the SaaS 'clients' endpoint, which validates
		// them against the live MLS and reports back whether it "tested".
		$answer = $this->theme_importer->globalApiRequestSaas( 'clients', $values, 'PATCH' );
		// Some clients responses include the authoritative MLS configuration. Save
		// its type beside this MLS ID so later requests no longer need ID fallback.
		if ( isset( $answer['mls_data']['type'] ) ) {
			Mlsimport_Provider_Family::remember_type( $answer['mls_data']['type'], $mls_id );
		}




		// Persist the connection-test flag only on a confirmed successful test;
		// any other outcome clears it (and the metadata flag) so the UI re-tests.
		if ( isset( $answer['success'] ) && true ===  $answer['success']  ) {
			if ( isset( $answer['tested'] ) &&  true === $answer['tested'] ) {
				update_option( 'mlsimport_connection_test', 'yes' );
				mlsimport_telemetry_set_once( 'mls_connected_at', time() );
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

        /**
         * AJAX handler for the plugin-deactivation exit survey.
         *
         * Thin wrapper: it verifies the nonce and capability, sanitizes input,
         * delegates the real work to mlsimport_exit_survey_record(), and POSTs
         * the result to the SaaS API. The POST is fire-and-forget — a failed or
         * not-yet-deployed endpoint must never stop the admin from deactivating.
         */
        public function mlsimport_exit_survey_submit() {
                check_ajax_referer( 'mlsimport_exit_survey', 'security' );
                if ( ! current_user_can( 'administrator' ) ) {
                        wp_send_json_error( 'Unauthorized' );
                }

                $input = array(
                        'reason'  => sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ),
                        'details' => sanitize_textarea_field( wp_unslash( $_POST['details'] ?? '' ) ),
                );

                // Only record a recognized reason; an unknown value is dropped
                // silently rather than blocking the user or storing junk.
                if ( $this->mlsimport_exit_survey_is_valid_reason( $input['reason'] ) ) {
                        $payload = $this->mlsimport_exit_survey_record( $input );
                        try {
                                ThemeImport::globalApiRequestSaas( 'user-activity', $payload, 'POST' );
                        } catch ( \Throwable $e ) {
                                // Swallow: deactivation proceeds regardless of transport failure.
                        }
                }

                wp_send_json_success();
        }

        /**
         * Whether a submitted exit-survey reason is one of the known options.
         *
         * Pure predicate — no WordPress functions, no translation.
         */
        private function mlsimport_exit_survey_is_valid_reason( string $reason ): bool {
                return in_array( $reason, $this->mlsimport_exit_survey_reasons(), true );
        }

        /**
         * Whether the current admin request is the Import Task (mlsimport_item)
         * post edit screen. Used to scope the Select2 asset enqueue so the
         * searchable-select library does not load across all of wp-admin.
         *
         * @param string $hook_suffix Current admin page hook suffix.
         * @param string $post_type   Post type of the screen being rendered.
         */
        private function mlsimport_is_import_task_edit_screen( string $hook_suffix, string $post_type ): bool {
                return 'mlsimport_item' === $post_type
                        && in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );
        }

        /**
         * Extra CSS class for an Import Task select field. City and County lists
         * can hold 300+ entries, so they are upgraded to a searchable multi-select
         * (Select2) via this marker class; every other field keeps the plain select.
         *
         * @param string $field_key Field key from the $field_import definition.
         * @return string Leading-space class string, or '' when not searchable.
         */
        private function mlsimport_searchable_select_class( string $field_key ): string {
                return in_array( $field_key, array( 'City', 'CountyOrParish' ), true )
                        ? ' mlsimport-searchable-select'
                        : '';
        }

        /**
         * Exit-survey testable core: resolve identity and count, build payload.
         *
         * Calls no dying functions — the AJAX wrapper handles nonce/capability
         * and wp_send_json_*. Always returns the payload to transmit.
         *
         * @param array $input Sanitized survey input (reason, details).
         */
        private function mlsimport_exit_survey_record( array $input ): array {
                $opts = get_option( 'mlsimport_admin_options', array() );
                if ( empty( $opts['mlsimport_install_uuid'] ) ) {
                        $opts['mlsimport_install_uuid'] = wp_generate_uuid4();
                        update_option( 'mlsimport_admin_options', $opts );
                }

                $count = (int) get_option( 'mlsimport_deactivation_count', 0 ) + 1;
                update_option( 'mlsimport_deactivation_count', $count );

                $reason  = (string) ( $input['reason'] ?? '' );
                $options = $this->get_exit_survey_options();

                return array(
                        'event_type'         => 'exit_survey',
                        'reason'             => $reason,
                        'reason_label'       => $options[ $reason ] ?? '',
                        'details'            => (string) ( $input['details'] ?? '' ),
                        'account'            => (string) ( $opts['mlsimport_username'] ?? '' ),
                        'install_uuid'       => $opts['mlsimport_install_uuid'],
                        'deactivation_count' => $count,
                        'environment'        => wp_get_environment_type(),
                        'site_url'           => home_url(),
                        'admin_email'        => (string) get_option( 'admin_email' ),
                        'timestamp'          => time(),
                );
        }

        /**
         * The known exit-survey reason keys — the single source of truth.
         *
         * Pure: keys only, no labels, no translation. The label map
         * (get_exit_survey_options) builds on top of this for the modal.
         *
         * @return string[]
         */
        private function mlsimport_exit_survey_reasons(): array {
                return array(
                        'built_website',
                        'no_leads',
                        'technical_issues',
                        'too_expensive',
                        'switched_tool',
                        'other',
                );
        }

        /**
         * Exit-survey reason key => display label, for the modal and payload.
         *
         * Uses translation, so it is not exercised by the pure unit suite —
         * is_valid_reason() relies on mlsimport_exit_survey_reasons() instead.
         *
         * @return array<string,string>
         */
        private function get_exit_survey_options(): array {
                return array(
                        'built_website'    => esc_html__( "Built the website, don't need ongoing sync", 'mlsimport' ),
                        'no_leads'         => esc_html__( 'Not getting leads from my site', 'mlsimport' ),
                        'technical_issues' => esc_html__( "Technical issues I couldn't fix", 'mlsimport' ),
                        'too_expensive'    => esc_html__( 'Too expensive', 'mlsimport' ),
                        'switched_tool'    => esc_html__( 'Switched to another tool', 'mlsimport' ),
                        'other'            => esc_html__( 'Other', 'mlsimport' ),
                );
        }






	/**
	 * Return a valid SaaS API bearer token, using the cached transient when
	 * present, otherwise requesting a fresh one and caching it for ~58 minutes.
	 *
	 * @since    4.0.1
	 * @return string|array The token string, or the raw answer/'' on failure.
	 */
	public function mlsimport_saas_get_mls_api_token_from_transient() {

		// Prefer the cached token.
		$token = get_transient( 'mlsimport_saas_token' );

		// Cache miss/empty: request a new token and cache it on success.
		if ( false === $token || '' ===  $token  ) {
			$token_json_answer = $this->mlsimport_saas_get_mls_api_token();

			if ( isset( $token_json_answer['success'] ) && true ===  $token_json_answer['success']  ) {
				$token = $token_json_answer['token'];

				// 3500s < the token's 1h life, leaving headroom before expiry.
				set_transient( 'mlsimport_saas_token', $token, 3500 );
			}
		}

		return $token;
	}


	/**
	 * Request a fresh SaaS API token using the stored account username/password.
	 *
	 * If the selected MLS changed since the last run, all cached token/metadata
	 * transients and the field-select option are purged first so nothing leaks
	 * across providers. Returns '' when credentials are missing.
	 *
	 * @since    4.0.1
	 * @return array|string The 'token' API response, or '' when unconfigured.
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
			// Credentials are sent verbatim: sanitize_text_field() strips
			// %[hex][hex] sequences and would corrupt the password (#204).
			$password = trim( (string) $options['mlsimport_password'] );
		}
		$mls_name = '';
		if ( isset( $options['mlsimport_mls_name'] ) ) {
			$mls_name = sanitize_text_field( trim( $options['mlsimport_mls_name'] ) );
		}

		$mls_token = '';
		if ( isset( $options['mlsimport_mls_token'] ) ) {
			$mls_token = sanitize_text_field( trim( $options['mlsimport_mls_token'] ) );
		}

		// Provider switch detected: purge all cross-provider cached state.
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

		// Remember the current MLS so the next call can detect a switch.
		update_option( 'mlsimport_prev_mls_name', $mls_name );



		// Credentials to exchange for a token.
		$values['username'] = $username;
		$values['password'] = $password;

		// No account credentials -> nothing to request.
		if ( '' ===  $username  || '' === $password ) {
			return '';
		}

		// POST to the SaaS 'token' endpoint and return its response.
		$theme_Start = new ThemeImport();
		$answer      = $theme_Start::globalApiRequestSaas( 'token', $values, 'POST' );

		

		return $answer;
	}






	/**
	 * Register the "Set Import data" metabox on the mlsimport_item post type.
	 *
	 * @since    3.0.1
	 */
	public function mlsimport_item_product_metaboxes() {
		// The metabox renders the import-parameter form for an Import Task.
		add_meta_box( 'mlsimport_item_metaboxes-sectionid', __( 'Set Import data', 'mlsimport' ), array( $this, 'mlsimport_saas_display_meta_options' ), 'mlsimport_item', 'normal', 'default' );
	}



	/**
	 * Save the Import Task metabox fields to post meta (save_post callback).
	 *
	 * Only acts on mlsimport_item posts. Sanitizes and stores each posted
	 * whitelisted key; separately, any "blank_keys" absent from the POST (e.g.
	 * unchecked multi-selects) are explicitly reset to '' so cleared selections
	 * actually clear.
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    Post object.
	 * @since    3.0.1
	 */
	public function mlsimport_item_product_save_metaboxes( $post_id, $post ) {

		// Guard against non-post contexts.
		if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
			return;
		}

		// Only handle Import Task posts.
		if ( 'mlsimport_item' !==  $post->post_type  ) {
			return;
		}

		// Never persist metabox fields from autosaves or revision saves.
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// The nonce rendered by mlsimport_saas_display_meta_options().
		if ( ! isset( $_POST['estate_agent_noncename'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['estate_agent_noncename'] ) ), plugin_basename( __FILE__ ) ) ) {
			return;
		}

		// Import Tasks are admin-only: require edit rights on this task.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Every import-parameter meta key this metabox may write.
		$allowed_keys = array(
			'mlsimport_item_how_many',
			'mlsimport_item_title_format',
			'mlsimport_item_agent',
			'mlsimport_item_use_mls_agent',
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
			'mlsimport_item_standardstatusprotect_check',
			'mlsimport_item_standardstatusprotect',

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
                       'mlsimport_item_listingkey',
                       'mlsimport_item_extracity',
                       'mlsimport_item_extracounty',
                       'mlsimport_item_exclude_listofficemlsid',
                       'mlsimport_item_exclude_listofficekey',
                       'mlsimport_item_exclude_listagentmlsid',
                       'mlsimport_item_exclude_listagentkey',
                       'mlsimport_item_customparameters',
                       'mlsimport_item_mlsareamajor',
                       'mlsimport_item_subdivisionname',
               );
	



		// Store each posted key (recursively sanitized; key sanitized too).
		foreach ( $allowed_keys as $key => $key_value ) {
				if( isset($_POST[$key_value]) ){
					$postmeta = mlsimport_sanitize_multi_dimensional_array ( $_POST[$key_value] ) ;
					update_post_meta( $post_id, sanitize_key( $key_value ), $postmeta );
				}

		}

		// Keys that must be reset to '' when omitted from the POST (cleared).
		$blank_keys = array(
			'mlsimport_item_use_mls_agent',
			'mlsimport_item_standardstatus',
			'mlsimport_item_standardstatusprotect',
			'mlsimport_item_city',
			'mlsimport_item_countyorparish',
			'mlsimport_item_propertysubtype',
                        'mlsimport_item_propertytype',
                        'mlsimport_item_standardstatus',
                        'mlsimport_item_listingid',
                        'mlsimport_item_listingkey',
                        'mlsimport_item_customparameters',
                        'mlsimport_item_mlsareamajor',
                        'mlsimport_item_subdivisionname',

		);

		// Reset any whitelisted-blank key that was not submitted this save.
		foreach ( $blank_keys as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, '' );
			}
		}

	
	}


	/**
	 * Render the Import Task metabox content.
	 *
	 * Ensures a live SaaS token + MLS connection, prints a warning and stops if
	 * either is missing, otherwise runs a listing count request and hands off to
	 * generateMetaOptionsHtml() to build the parameter form.
	 *
	 * @param WP_Post $post The post object.
	 */
        public function mlsimport_saas_display_meta_options($post) {
                // Nonce for the metabox save.
                wp_nonce_field(plugin_basename(__FILE__), 'estate_agent_noncename');
                global $mlsimport;

                // Ensure a token, read the cached connection flag, print env warnings.
                $token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
                $is_mls_connected = get_option('mlsimport_connection_test', '');
                $mlsimport->admin->mlsimport_saas_setting_up();

                // If not marked connected, run the connection test once and re-read the flag.
                if ('yes' !== $is_mls_connected) {
                        $mlsimport->admin->mlsimport_saas_check_mls_connection();
                        $is_mls_connected = get_option('mlsimport_connection_test', '');
                }

                // No token -> account not authenticated; stop with a notice.
                if (trim($token) === '') {
                        echo '<div class="mlsimport_warning">' . esc_html__('You are not connected to MlsImport - Please check your Username and Password.', 'mlsimport') . '</div>';
                        return;
                }

                // Token OK but MLS connection failed -> stop with a notice.
                if ('yes' !== $is_mls_connected) {
                        echo '<div class="mlsimport_warning">' . esc_html__('The connection to your MLS was NOT succesful. Please check the authentication token is correct and check your MLS Data Access Application is approved.', 'mlsimport') . '</div>';
                        return;
                }

                // Load current task settings for the form.
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

               // Ask the MLS how many listings currently match this task.
               $mlsRequest = $this->mlsimport_make_listing_requests($postId);
			//  print_r($mlsRequest);

               // Surface any API error message inline.
               $hasError = isset($mlsRequest['success']) && !$mlsRequest['success'];
               if ($hasError) {
                       echo '<div class="mlsimport_warning">' . esc_html($mlsRequest['message']) . '</div>';
               }

               // 'none' means no results key -> likely an expired token; re-test.
               $foundItems = isset($mlsRequest['results']) ? intval($mlsRequest['results']) : 'none';
                if ($foundItems === 'none') {
                        $mlsimport->admin->mlsimport_saas_check_mls_connection();
                        esc_html_e('Your Token was expired. Please refresh the page to renew it wait while we renew it.', 'mlsimport');
                }

               // Build and print the parameter form.
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


		// Buffer all HTML and return it as a string.
		ob_start();

                // Decode the saved MLS enums so City/County/PropertyType options can
                // carry their human-readable labels alongside the raw values.
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
                       <?php
                       // Support diagnostic (issue #216): the latest finished-run
                       // snapshot recorded at finish_run(). One plain sentence so
                       // "is it us or the host?" is answerable from this screen —
                       // workers above 1 + hand-offs means the host killed workers.
                       $mlsimport_telemetry_state = get_option('mlsimport_telemetry_state', array());
                       $mlsimport_last_run        = is_array($mlsimport_telemetry_state) && isset($mlsimport_telemetry_state['last_import_run']) && is_array($mlsimport_telemetry_state['last_import_run'])
                               ? $mlsimport_telemetry_state['last_import_run']
                               : array();
                       if (!empty($mlsimport_last_run)) :
                       ?>
                       <div class="mlsimport-exp" id="mlsimport_last_run_summary">
                               <?php
                               printf(
                                       /* translators: 1 state, 2 saved, 3 failed, 4 elapsed seconds, 5 workers, 6 peak MB, 7 pending actions. */
                                       esc_html__('Last import run %1$s: %2$d saved, %3$d failed, %4$ds across %5$d worker(s), peak memory %6$dMB, %7$d worker action(s) pending.', 'mlsimport'),
                                       esc_html((string) ($mlsimport_last_run['state'] ?? '')),
                                       (int) ($mlsimport_last_run['saved'] ?? 0),
                                       (int) ($mlsimport_last_run['failed'] ?? 0),
                                       (int) ($mlsimport_last_run['elapsed_seconds'] ?? 0),
                                       (int) ($mlsimport_last_run['workers'] ?? 0),
                                       (int) ($mlsimport_last_run['peak_memory_mb'] ?? 0),
                                       (int) ($mlsimport_last_run['queue_depth'] ?? 0)
                               );
                               ?>
                       </div>
                       <?php endif; ?>
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

			<?php if ( mlsimport_is_standalone_mode() ) :
				$mlsimportItemUseMlsAgent = get_post_meta($postId, 'mlsimport_item_use_mls_agent', true);
			?>
			<fieldset class="mlsimport-fieldset">
				<label class="mlsimport-label" for="mlsimport_item_use_mls_agent">
					<?php esc_html_e('Which agent shows on these properties', 'mlsimport'); ?>
				</label>
				<p class="mlsimport-exp"><?php esc_html_e('Off: every property from this task shows the agent you picked above. On: each property shows its own listing agent instead — the name, phone, email and office that came with that listing in the MLS feed, and the agent picked above is ignored. No agent profiles are created either way.', 'mlsimport'); ?></p>
				<label class="mlsimport-switch">
					<input type="checkbox" id="mlsimport_item_use_mls_agent" name="mlsimport_item_use_mls_agent" value="1" <?php checked('1', (string) $mlsimportItemUseMlsAgent); ?> />
					<?php esc_html_e('Show each property\'s own listing agent from the MLS feed', 'mlsimport'); ?>
				</label>
			</fieldset>
			<?php endif; ?>

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
			// Let the active provider adjust only the Import Task fields it owns.
			$options = get_option($this->plugin_name . '_admin_options');
			$options = is_array( $options ) ? $options : array();
			$mlsId  = '';
			if (isset($options['mlsimport_mls_name'])) {
				$mlsId = sanitize_text_field(trim($options['mlsimport_mls_name']));
			}
			$provider    = Mlsimport_Provider_Family::adapter(
				Mlsimport_Provider_Family::saved_type( $mlsId ),
				$mlsId,
				$this->theme_importer
			);
			$fieldImport = $provider->prepare_import_task_fields( $fieldImport );

			// Render one fieldset per import parameter.
			foreach ($fieldImport as $key => $field):
				// Skip fields flagged hidden.
				if (!empty($field['hidden'])) {
					continue;
				}
				// Derive the meta key + its companion "_check" (select-all) key.
				$nameCheck = strtolower('mlsimport_item_' . $key . '_check');
				$name = strtolower('mlsimport_item_' . $key);

				// Current saved value + select-all flag for this field.
				$value = get_post_meta($postId, $name, true);
				$valueCheck = get_post_meta($postId, $nameCheck, true);
				// extraCity/extraCounty render as a toggle button, not a plain label.
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
							// Whether the "select all" checkbox is currently on.
							$isCheckboxAdmin = 0;
							if (1 === intval($valueCheck)) {
								$isCheckboxAdmin = 1;
							}

                                                        // Fields that must NOT offer a "select all" checkbox.
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
																'ListingId',
																'ListingKey',
																'extraCity',
																'extraCounty',
																'Exclude_ListOfficeKey',
                                                                'Exclude_ListOfficeMlsId',
                                                                'Exclude_ListAgentKey',
                                                                'Exclude_ListAgentMlsId',
                                                                'CustomParameters',
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
							// Multi-select fields need the multiple attr + [] name.
							$multiple = '';
							if ('yes' === $field['multiple']) {
								$multiple = 'multiple';
								$name .= '[]';
							}

							// Default StandardStatus to Active when nothing saved.
							if ('StandardStatus' === $key && '' === $value) {
								$value = ['Active'];
							}

					

							// City/County lists can hold 300+ entries — render a filter
							// input above the full native multi-select listbox.
							$searchableClass   = $this->mlsimport_searchable_select_class($key);
							$isSearchable      = '' !== $searchableClass;
							$searchPlaceholder = $isSearchable
								? esc_html__('Type to search…', 'mlsimport')
								: '';

							// Additional conditions can be placed here.
							?>
							<?php if ($isSearchable): ?>
								<div class="mlsimport-selected-chips" aria-live="polite"></div>
								<input type="text" class="mlsimport-select-search" placeholder="<?php echo esc_attr($searchPlaceholder); ?>" aria-label="<?php echo esc_attr($searchPlaceholder); ?>" autocomplete="off">
							<?php endif; ?>
                                                        <select class="mlsimport-select mlsimport-2025-select<?php echo esc_attr($searchableClass); ?>" id="<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>"<?php echo $isSearchable ? ' size="12"' : ''; ?> <?php echo esc_attr($multiple); ?>>
                                                                <?php foreach ($field['values'] as $selectKey): ?>

                                                                        <?php if ('' !== $selectKey): ?>
                                                                                <?php
                                                                                // Match saved value against the raw key AND its
                                                                                // enum-mapped label, so either form stays selected.
                                                                                $option_value = $selectKey;
                                                                                $option_label = $selectKey;
                                                                                $comparison_values = array($option_value);

                                                                                // Label = the enum's mapped name (identity for
                                                                                // name=>name MLSs, city name for code=>name
                                                                                // providers like Centris). Value stays the key.
                                                                                if ('City' === $key && isset($metadata_api_call_city[$selectKey])) {
                                                                                        $option_label = mlsimport_enum_option_label($selectKey, $metadata_api_call_city);
                                                                                        $comparison_values[] = $metadata_api_call_city[$selectKey];
                                                                                } elseif ('CountyOrParish' === $key && isset($metadata_api_call_county[$selectKey])) {
                                                                                        $option_label = mlsimport_enum_option_label($selectKey, $metadata_api_call_county);
                                                                                        $comparison_values[] = $metadata_api_call_county[$selectKey];
                                                                                } elseif ('PropertyType' === $key && isset($metadata_api_call_property_type[$selectKey])) {
                                                                                        $option_label = mlsimport_enum_option_label($selectKey, $metadata_api_call_property_type);
                                                                                        $comparison_values[] = $metadata_api_call_property_type[$selectKey];
                                                                                }

                                                                                $comparison_values = array_values(array_unique(array_filter($comparison_values, static function ($compare_value) {
                                                                                        return '' !== $compare_value && null !== $compare_value;
                                                                                })));

                                                                                // Selected if any comparison value matches the saved
                                                                                // value (array for multi-selects, scalar otherwise).
                                                                                $is_selected = false;
                                                                                if (is_array($value)) {
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
		// Return the buffered form markup.
		return ob_get_clean();
	}


	



	// Placeholder hook target for injecting additional Import Task fields (no-op).
	public function mlsimport_add_extra_fields() {
	}

	/**
	 * Per-field help text override, keyed by MLS + meta field.
	 *
	 * Currently only special-cases MLS 111 (Rae Edmonton), which has no status
	 * field; every other case returns the field's default description unchanged.
	 *
	 * @param int    $mlsimport_mls_id Numeric MLS id.
	 * @param string $name             Meta field name (e.g. mlsimport_item_standardstatus).
	 * @param string $description      Default description to fall back to.
	 * @return string
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
	 * Return the "last checked" timestamp for an Import Task, seeding it if unset.
	 *
	 * @param int $item_id Import Task post id.
	 * @return string A 'Y-m-d\TH:i' timestamp.
	 */
	public function mlsimport_saas_get_last_date( $item_id ) {
		// Stored watermark used as the modification-time filter for syncs.
		$last_date = get_post_meta( $item_id, 'mlsimport_last_date', true );

		// First run: initialize it.
		if ( '' === $last_date  ) {
			$last_date = $this->mlsimport_saas_update_last_date( $item_id );
		}
		return $last_date;
	}


	/**
	 * Set the Import Task's "last checked" watermark to 2 hours ago and store it.
	 *
	 * The 2-hour backdate provides overlap so listings modified right around the
	 * run boundary are not missed. Note: also echoes the value as a side effect.
	 *
	 * @param int $item_id Import Task post id.
	 * @return string The stored 'Y-m-d\TH:i' timestamp.
	 */
	public function mlsimport_saas_update_last_date( $item_id ) {

		// Current site time minus 2 hours, formatted as an ISO-ish local stamp.
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
        * @return int Number of listings found in the MLS feed, or 0 on failure.
        */
	public function mlsimport_saas_start_cron_links_per_item( int $item_id ): int {
		// A task becomes eligible only after its first manual import completed.
		// Keep the existing guard at this scheduling boundary; execution rules
		// themselves now live in the shared runner below.
		$manual_completed = 1 === (int) get_post_meta( $item_id, 'mlsimport_initial_import_completed', true );
		$legacy_completed = mlsimport_cron_should_process_task( get_post_meta( $item_id, 'mlsimport_spawn_status', true ) );
		if ( ! $manual_completed && ! $legacy_completed ) {
			return 0;
		}

		$start = $this->mlsimport_import_task_execution()->start(
			array(
				'task_id' => $item_id,
				'source'  => 'automatic',
			)
		);
		// Hourly work is already in the background. If another import owns the
		// site-wide slot, this task simply waits for the next normal hourly run.
		if ( true !== ( $start['accepted'] ?? false ) ) {
			return 0;
		}

		// Same rules as the manual worker: a large hourly sync must not be
		// killed by the web/cron request time limit mid-run, and term counts
		// are recomputed once after the run instead of per assignment.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}
		wp_defer_term_counting( true );
		$result = $this->mlsimport_import_task_execution()->execute( (string) $start['run_id'] );
		wp_defer_term_counting( false );
		mlsimport_saas_single_write_import_custom_logs(
			'Automatic import for task ' . $item_id . ' finished with state ' . (string) $result['state'] . '.' . PHP_EOL,
			'cron'
		);
		gc_collect_cycles();

		return (int) $result['found'];
	}






/**
 * Backward-compatible entry point for the deep reconciliation module.
 *
 * Cron now calls the module directly. This method remains for existing plugin
 * callers and delegates the full snapshot, plan, policy, deletion, and retry
 * sequence through the same public seam.
 *
 * @return array<string, int|string> Structured Reconciliation Outcome.
 */
public function mlsimport_saas_start_doing_reconciliation() {
    // Backward-compatible entry point for callers outside the cron hook. The
    // complete destructive decision path now lives behind the deep module seam.
    $environment = new Mlsimport_Reconciliation_WordPress_Environment(
        function (): array {
            return $this->mlsimport_saas_get_mls_reconciliation_data();
        }
    );

    return ( new Mlsimport_Reconciliation( $environment ) )->reconcile_current_listings();
}

	/**
	 * Fetch the reconciliation feed (all current ListingKeys) from the SaaS API.
	 *
	 * @return array The API response, expected to carry an 'all_data' key.
	 */
	public function mlsimport_saas_get_mls_reconciliation_data() {

		// GET /reconciliation with no arguments.
		$arguments = array();
		$answer    = $this->theme_importer->globalApiRequestCurlSaas( 'reconciliation', $arguments, 'GET' );
		return $answer;
	}

	/**
	 * Return all published posts' values for a given meta key, with their post ids.
	 *
	 * @param string $key Meta key to fetch.
	 * @return array Rows of {meta_value, ID}.
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



	/**
	 * Run a single listings request for an Import Task and return the API result.
	 *
	 * Builds the RESO query arguments, rejects invalid combinations (Rapattoni
	 * requiring a property type; over-long argument strings), POSTs to the SaaS
	 * 'listings' endpoint, normalizes a non-array failure into a success=false
	 * array, records feed-count telemetry, and returns the response array.
	 *
	 * @param int    $item_id        Import Task post id.
	 * @param string $last_date      Modification-time watermark (optional).
	 * @param string $skip           Pagination offset (optional).
	 * @param string $top            Page size (optional).
	 * @param bool   $is_hourly_sync Whether this call is from the hourly cron.
	 * @return array The (normalized) API response.
	 */
	public function mlsimport_make_listing_requests( $item_id, $last_date = '', $skip = '', $top = '', $is_hourly_sync = false ) {
		// Build the full RESO query argument set from the task's meta.
		$arguments = $this->mlsimport_saas_make_listing_requests_arguments( $item_id, $last_date, $skip, $top, $is_hourly_sync );

		// The Provider Family module returns a private marker when its request
		// rules reject the inputs. Convert that into the existing public error shape.
		if ( isset( $arguments['mlsimport_provider_error'] ) ) {
			$error = $arguments['mlsimport_provider_error'];
			return array(
				'success' => false,
				'type'    => isset( $error['code'] ) ? $error['code'] : 'provider_error',
				'message' => isset( $error['message'] ) ? $error['message'] : esc_html__( 'The MLS request could not be prepared.', 'mlsimport' ),
			);
		}

		// Guard against an over-long query string (too many parameters selected).
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
		// POST the query to the SaaS 'listings' endpoint.
		$answer = $this->theme_importer->globalApiRequestCurlSaas( 'listings', $arguments, 'POST' );

		// globalApiRequestCurlSaas() returns a plain string on failure (token
		// validation, network/WP error, JSON decode). Callers expect an array,
		// so normalize the failure into the success=>false shape they handle.
		if ( ! is_array( $answer ) ) {
			$answer = array(
				'success' => false,
				'message' => is_string( $answer ) ? $answer : esc_html__( 'The request to the MLS could not be completed.', 'mlsimport' ),
			);
		}

		// Echo the computed argument length back on the response for diagnostics.
		$answer['potential_leght'] = $potential_leght;

		// Record the pre-filter MLS feed count for telemetry. Every import path
		// — manual, hourly cron, and onboarding — routes through this method, so
		// recording here is the single rule that keeps the metric complete.
		if ( isset( $answer['results'] ) ) {
			mlsimport_telemetry_set( 'last_feed_found', (int) $answer['results'] );
		}

		// Record the request outcome into sync-health telemetry (issue #207):
		// success stamps last_sync_success; failure stamps last_sync_failed plus
		// a real failure class instead of the former always-"unknown" code.
		mlsimport_telemetry_record_sync_result( $answer );

		return ( $answer );
	}






	/**
	 * Assemble the RESO listings query arguments from an Import Task's meta.
	 *
	 * Reads the task's saved filters (price, city/county, area, subdivision,
	 * postal code, status, property (sub)type, internet-display flags, agent /
	 * office keys and their exclusions, custom parameters) and maps them to the
	 * SaaS API parameter names, applying provider-specific quirks (Edmonton has
	 * no status; Rapattoni collapses property_type; Realtor.ca / PropTx need a
	 * specific modification-time format).
	 *
	 * @param int    $item_id        Import Task post id.
	 * @param string $last_date      Modification-time watermark (optional).
	 * @param string $skip           Pagination offset (optional).
	 * @param string $top            Page size (optional).
	 * @param bool   $is_hourly_sync Whether this call is from the hourly cron.
	 * @return array|string The argument array, or '' when core options are missing.
	 */
	public function mlsimport_saas_make_listing_requests_arguments( $item_id, $last_date = '', $skip = '', $top = '', $is_hourly_sync = false ) {

		// MLS id is mandatory.
		$options = get_option( $this->plugin_name . '_admin_options' );
		if ( isset( $options['mlsimport_mls_name'] ) ) {
			$mls_id = intval( $options['mlsimport_mls_name'] );
		} else {
			return '';
		}

		// Theme id is mandatory (selects the server-side field schema).
		if ( isset( $options['mlsimport_theme_used'] ) ) {
			$theme_id = intval( $options['mlsimport_theme_used'] );
		} else {
			return '';
		}

		// Base parameters every request carries.
		$values             = array();
		$values['mls_id']   = $mls_id;
		$values['theme_id'] = $theme_id;
		// Flag hourly-sync calls so the backend can treat them differently.
		if ( $is_hourly_sync ) {
			$values['hourly_sync'] = 1;
		}

		// Pagination (only when a page size was supplied).
		if ( '' !==  $top  ) {
			$values['top']  = $top;
			$values['skip'] = intval( $skip );
		}

		// // add price
		// Price range (only when both bounds are set).
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

		// Add the shared status input. The active adapter removes it when that MLS
		// has no status field, keeping the exception out of this request builder.
		$values = $this->mls_import_return_multiple_param_value( 'StandardStatus', $item_id, 'status', $values );

		// add property_subtype
		$values = $this->mls_import_return_multiple_param_value( 'PropertySubType', $item_id, 'property_subtype', $values );

		// add property_type
		$values = $this->mls_import_return_multiple_param_value( 'PropertyType', $item_id, 'property_type', $values );

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

		// add ListingKey - single-property filter for MLS APIs (e.g. PropTx/AMPRE)
		// that do not support filtering by ListingId (GitHub issue #198).
		$values = $this->mls_import_saas_add_to_parms_input( 'ListingKey', $item_id, 'listingkey', $values );

		//add Exclude_ListOfficeKey
		$values = $this->mls_import_saas_add_to_parms_input( 'Exclude_ListOfficeKey', $item_id, 'exclude_list_officekey', $values );
		// add Exclude_ListOfficeMlsId
		$values = $this->mls_import_saas_add_to_parms_input( 'Exclude_ListOfficeMlsId', $item_id, 'exclude_list_officemlsid', $values );



		//add Exclude_ListAgentKey
		$values = $this->mls_import_saas_add_to_parms_input( 'Exclude_ListAgentKey', $item_id, 'exclude_list_agentkey', $values );
		// add Exclude_ListAgentMlsId
		$values = $this->mls_import_saas_add_to_parms_input( 'Exclude_ListAgentMlsId', $item_id, 'exclude_list_agentmlsid', $values );
		// add CustomParameters
		$values = $this->mls_import_saas_add_to_parms_input( 'CustomParameters', $item_id, 'custom_parameters', $values );


		// Hand only provider-specific request preparation to the active adapter.
		// Saved type wins; numeric ranges are used only by older configurations.
		$saved_type = Mlsimport_Provider_Family::saved_type( $mls_id );
		$provider   = Mlsimport_Provider_Family::adapter( $saved_type, $mls_id, $this->theme_importer );
		if ( ! $provider->supported() ) {
			return array( 'mlsimport_provider_error' => $provider->error() );
		}

		$prepared = $provider->prepare_stored_request( $values, $last_date );
		if ( ! $prepared['success'] ) {
			return array( 'mlsimport_provider_error' => $prepared['error'] );
		}

		return $prepared['arguments'];
	}



	/**
	 * Copy a single scalar Import Task meta value into the arguments array.
	 *
	 * Reads mlsimport_item_<key> and, when non-empty, stores it under $new_name.
	 *
	 * @param string $key        Field key (used to build the meta key).
	 * @param int    $post_id    Import Task post id.
	 * @param string $new_name   API parameter name to store under.
	 * @param array  $all_values Accumulating arguments array.
	 * @return array The updated arguments array.
	 */
	public function mls_import_saas_add_to_parms_input( $key, $post_id, $new_name, $all_values ) {
		// Read the scalar meta value and add it only when set.
		$name  = strtolower( 'mlsimport_item_' . $key );
		$value = get_post_meta( $post_id, $name, true );
		if ( '' !== $value  ) {
			$all_values[ $new_name ] = $value;
		}

		return $all_values;
	}


	/**
	 * Copy a multi-value (list) Import Task meta value into the arguments array.
	 *
	 * Reads the list value plus its "_check" (select-all) flag; for city/county
	 * it also merges any comma-separated "extra" free-text values. The value is
	 * added only when select-all is off and it is non-empty — except 'status',
	 * which is always written.
	 *
	 * @param string $key        Field key (used to build the meta keys).
	 * @param int    $post_id    Import Task post id.
	 * @param string $new_name   API parameter name to store under.
	 * @param array  $all_values Accumulating arguments array.
	 * @return array The updated arguments array.
	 */
	public function mls_import_return_multiple_param_value( $key, $post_id, $new_name, $all_values ) {
		// The selected list value and its companion select-all flag.
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

		// Only include the list when "select all" is off and there is a value.
		$value_check = get_post_meta( $post_id, $name_check, true );

		if ( 0 ===  intval($value_check)  && '' !== $value  ) {
			$all_values[ $new_name ] = $value;
		}

		// status exception: always send status, regardless of the check flag.
		if ( 'status' === $new_name  ) {
			$all_values[ $new_name ] = $value;
		}

		return $all_values;
	}



	/**
	 * Build the Import Task field definition list (labels, types, enum values).
	 *
	 * Reads the saved MLS enums option, extracts the available City / County /
	 * status / property (sub)type value lists, and returns the ordered field
	 * definition array the metabox renders from. Falls back StandardStatus to
	 * MlsStatus when the MLS has no StandardStatus enum. Emits a warning when no
	 * metadata has been fetched yet.
	 *
	 * @return array Field key => definition (label, description, type, multiple, values).
	 */
	public function mlsimport_saas_return_mls_fields() {

		// Saved MLS enum metadata (JSON); empty until fields have been fetched.
		$mlsimport_mls_metadata_mls_enums = get_option( 'mlsimport_mls_metadata_mls_enums', '' );

		// Warn the user when no metadata is available yet.
		if ( '' ===   $mlsimport_mls_metadata_mls_enums ) {
			?>
			<div class="mlsimport_warning long_warning">Please select the import fields(from MLS Import Settings) before starting a MLS import process.</div>
		<?php
		}

		// Decode and reach into the enum container.
		$metadata_api_call_full = json_decode( $mlsimport_mls_metadata_mls_enums, true );

		if ( isset( $metadata_api_call_full['global_array'] ) ) {
			$metadata_api_call = $metadata_api_call_full['global_array'];
		}

		// Extract each enum list as a flat array of option keys (empty if absent).
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
		if ( isset( $metadata_api_call['PropertyEnums']['StandardStatus'] ) && is_array( $metadata_api_call['PropertyEnums']['StandardStatus'] ) ) {
			$standardstatus_array 		= array_keys( $metadata_api_call['PropertyEnums']['StandardStatus'] );
		}

		// if we do not have standart status
		// Fall back to MlsStatus values when the MLS exposes no StandardStatus.
		if ( empty( $standardstatus_array ) ) {
			$standardstatus_array 			= $mlsstatus_array;
		}

	


		// Free-text "extra" inputs render empty; they hold comma-separated values.
		$extracounty_values = '';
		$extracity_values   = '';

		// Ordered field definitions consumed by the Import Task metabox renderer.
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
				'description' => esc_html__( 'Enter one or more postal codes to import listings from, separated by commas (e.g. 12345, 23456).', 'mlsimport' ),
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
			'StandardStatusProtect'                => array(
				'label'       => esc_html__( 'Protected Statuses', 'mlsimport' ),
				'description' => __( 'Properties with these statuses will NEVER be deleted from your website during reconciliation, even if they are no longer found in the MLS. Use this to protect Closed, Expired, or other non-active listings from automatic deletion.', 'mlsimport' ),
				'type'        => 'select',
				'multiple'    => 'yes',
				'values'      => $standardstatus_array,
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
			'ListingKey'                     => array(
				'label'       => esc_html__( 'ListingKey', 'mlsimport' ),
				'description' => esc_html__( 'Import One Property Only via parameter ListingKey. Use this when your MLS does not support filtering by ListingId (for example PropTx/AMPRE).', 'mlsimport'),
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
			'CustomParameters'                      => array(
				'label'       => esc_html__( 'Custom parameters', 'mlsimport' ),
				'description' => esc_html__( 'Add raw query fragment parameters (for example: $filter=WaterfrontYN eq true). They will be forwarded to the RESO API request.', 'mlsimport' ),
				'type'        => 'input',
				'multiple'    => 'no',
			),


		);
		return $field_import;
	}







	/**
	 * AJAX: kick off a manual import for one Import Task.
	 *
	 * Resets the force-stop flag, builds the paginated batch of request-argument
	 * sets, stores them (and zeroed progress meta), marks the task 'started', and
	 * enqueues the Action Scheduler background job that does the actual import.
	 * Returns any build error immediately, otherwise {success:true}.
	 *
	 * @return void Emits JSON.
	 */
	public function mlsimport_move_files_per_item() {
		check_ajax_referer( 'mlsimport_item_actions', 'security' );

		$post_id    = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$how_many   = isset( $_POST['how_many'] ) ? intval( $_POST['how_many'] ) : 0;
		$max_number = isset( $_POST['post_number'] ) ? intval( $_POST['post_number'] ) : 0;
		$is_onboard = isset( $_POST['is_onboard'] ) ? intval( $_POST['is_onboard'] ) : 0;

		// Reject the request before the shared runner or any task state changes.
		if ( 'mlsimport_item' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not allowed to manage this import task.', 'mlsimport' ) ), 403 );
		}

		// The page already counted the matching listings. Keep that exact count
		// and selected limit so the background worker does not count them again.
		$start = $this->mlsimport_import_task_execution()->start(
			array(
				'task_id'   => $post_id,
				'source'    => 'manual',
				'found'     => $max_number,
				'limit'     => $how_many,
				'is_onboard' => $is_onboard,
			)
		);
		if ( true !== ( $start['accepted'] ?? false ) ) {
			wp_send_json(
				array(
					'success' => false,
					'reason'  => (string) ( $start['reason'] ?? 'already_running' ),
					'message' => esc_html__( 'Another import is already running. Please wait for it to finish.', 'mlsimport' ),
				)
			);
		}

		mlsimport_saas_single_write_import_custom_logs( 'Manual import queued for task ' . $post_id . '.' . PHP_EOL, 'manual' );
		mlsimport_debuglogs_per_plugin( 'Manual import queued for task ' . $post_id . '.' . PHP_EOL );

		$this->mlsimport_enqueue_import_worker( (string) $start['run_id'] );

		wp_send_json(
			array(
				'success' => true,
				'run_id'   => (string) $start['run_id'],
			)
		);
	}

	/**
	 * Queue the background import worker for an accepted Import Run.
	 *
	 * Called only after start() accepted the run, which means this request now
	 * holds the one site-wide run lock. Therefore every worker action already
	 * sitting in Action Scheduler belongs to a dead or replaced run: an
	 * in-progress action is a worker that died mid-run (a crash, a memory
	 * fatal), and its leftover claim blocks Action Scheduler — which runs one
	 * claim at a time — from ever dispatching a new worker; a pending action is
	 * a superseded start that would only wake, discover it lost the lock, and
	 * exit. Both are cleared here so the queue always self-heals on client
	 * sites, with no manual database intervention.
	 *
	 * @param string $run_id Accepted run identity to hand to the worker.
	 * @return void
	 */
	public function mlsimport_enqueue_import_worker( string $run_id ): void {
		try {
			$store = ActionScheduler::store();
			$dead  = $store->query_actions(
				array(
					'hook'     => 'mlsimport_background_process_per_item',
					'status'   => ActionScheduler_Store::STATUS_RUNNING,
					'per_page' => 20,
				)
			);
			foreach ( $dead as $dead_action_id ) {
				$store->mark_failure( $dead_action_id );
				mlsimport_debuglogs_per_plugin( 'Failed dead import worker action ' . $dead_action_id . ' before queueing a new worker.' . PHP_EOL );
			}
			as_unschedule_all_actions( 'mlsimport_background_process_per_item' );
		} catch ( Throwable $exception ) {
			// Queue cleanup must never block starting the new worker.
			mlsimport_debuglogs_per_plugin( 'Import queue cleanup failed: ' . $exception->getMessage() . PHP_EOL );
		}

		// Only the small run identity crosses the HTTP/background boundary. The
		// runner reads the request and progress from WordPress when it wakes.
		as_enqueue_async_action(
			'mlsimport_background_process_per_item',
			array( 'args' => array( 'run_id' => $run_id ) )
		);
		spawn_cron();
	}

	/**
	 * Return the adapter configuration error that blocks Stored mode imports.
	 *
	 * @return string Empty when a supported adapter was composed.
	 */
	public function mlsimport_stored_listing_configuration_error(): string {
		return $this->stored_listing_configuration_error;
	}












	/**
	 * Action Scheduler adapter for the shared Import Task runner.
	 *
	 * The queue carries only a run id. Fetching, saving, stopping, progress, and
	 * completion all remain inside the shared execution module used by cron too.
	 *
	 * @param array|string $input_arg Run payload, or the run id for direct callers.
	 * @return void
	 */
	public function mlsimport_background_process_per_item_function( $input_arg ) {
		$run_id = is_array( $input_arg ) ? (string) ( $input_arg['run_id'] ?? '' ) : (string) $input_arg;
		if ( '' === $run_id ) {
			mlsimport_saas_single_write_import_custom_logs( 'Import worker received no run id.' . PHP_EOL, 'manual' );
			return;
		}

		// A host-killed worker dies without any PHP-level trace: the fatal only
		// lands in the server error log the administrator may not have. This
		// shutdown hook writes the real cause (timeout, memory, fatal) into the
		// plugin's own import log, so one failed run is enough to diagnose.
		register_shutdown_function(
			static function () use ( $run_id ) {
				$last_error = error_get_last();
				if ( null === $last_error
					|| ! in_array( $last_error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					return;
				}
				mlsimport_saas_single_write_import_custom_logs(
					'Import worker FATAL (run ' . substr( $run_id, 0, 8 ) . '): ' . $last_error['message']
					. ' in ' . $last_error['file'] . ':' . $last_error['line']
					. '. Memory ' . round( memory_get_usage( true ) / 1048576 ) . 'MB, peak '
					. round( memory_get_peak_usage( true ) / 1048576 ) . 'MB.' . PHP_EOL,
					'manual'
				);
			}
		);
		$worker_started_at = microtime( true );
		mlsimport_saas_single_write_import_custom_logs(
			'Import worker start (run ' . substr( $run_id, 0, 8 ) . '). Memory '
			. round( memory_get_usage( true ) / 1048576 ) . 'MB.' . PHP_EOL,
			'manual'
		);

		// A long import must not be bound by the web request time limit: at
		// ~2.3s per listing, PHP's max_execution_time (1200s here) hard-kills
		// the worker around listing 516 of a 1000+ run. The proven previous
		// version called set_time_limit(0) in its import path for this reason.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}
		// Proven-previous-version parity: defer term counting for the whole
		// run. WordPress then skips the count queries fired on every term
		// assignment (7 taxonomies x every listing) and recounts once when
		// deferral is switched back off after the run.
		wp_defer_term_counting( true );

		$result = $this->mlsimport_import_task_execution()->execute( $run_id );
		// Recount the deferred term totals now that this worker is done. A
		// hand-off recounts per chunk, which keeps counts correct even if a
		// later chunk in the chain dies.
		wp_defer_term_counting( false );
		// A 'running' result is a chunk hand-off (issue #199): this worker
		// spent its time budget and already queued the follow-up worker. Every
		// exit line carries timing, totals, and memory so one run's log is a
		// complete health trace of the whole worker chain.
		$worker_trace = ' Elapsed ' . round( microtime( true ) - $worker_started_at, 1 ) . 's,'
			. ' saved ' . (int) $result['saved'] . ', failed ' . (int) $result['failed'] . ','
			. ' memory ' . round( memory_get_usage( true ) / 1048576 ) . 'MB,'
			. ' peak ' . round( memory_get_peak_usage( true ) / 1048576 ) . 'MB.'
			. ( '' !== (string) $result['error'] ? ' Error: ' . (string) $result['error'] : '' );
		mlsimport_saas_single_write_import_custom_logs(
			'running' === (string) $result['state']
				? 'Manual import chunk handed off at ' . (int) ( $result['saved'] + $result['failed'] ) . ' listings; next worker queued.' . $worker_trace . PHP_EOL
				: 'Manual import finished with state ' . (string) $result['state'] . '.' . $worker_trace . PHP_EOL,
			'manual'
		);
		gc_collect_cycles();
	}









	/**
	 * AJAX: poll import status/logs for a task (drives the progress UI).
	 *
	 * Admin-only; accepts either the import-task or onboarding nonce. Reads the
	 * status log file plus progress meta and returns a JSON payload flagged
	 * 'done' (stopped/completed) or 'wip' (in progress).
	 *
	 * @return void Emits JSON then dies.
	 */
	public function mlsimport_logger_per_item() {
		// Authorization: only administrators may read import logs/status
		// (consistent with mlsimport_get_taxonomy_terms()).
		if ( ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		// CSRF: accept the nonce from either legitimate caller — the import-task
		// screen (mlsimport_item_actions) or the onboarding wizard (mlsimport_onboarding_nonce).
		if ( ! check_ajax_referer( 'mlsimport_item_actions', 'security', false )
			&& ! check_ajax_referer( 'mlsimport_onboarding_nonce', 'security', false ) ) {
			wp_send_json_error( array( 'message' => 'invalid nonce' ), 403 );
		}
		$post_id=0;
		if(isset($_POST['post_id'] )){
			$post_id = intval( $_POST['post_id'] );
		}

		// Watchdog (issue #199): chunked manual imports depend on each worker
		// queueing its follow-up; a host-killed worker breaks that chain. This
		// poll fires every few seconds while an administrator watches the
		// progress screen, making it the natural revival trigger. The call is
		// a cheap no-op unless the run has been silent long enough. Both real
		// outcomes — a revival and the stalled-run failure — are logged, since
		// each one means a worker died.
		$revive = $this->mlsimport_import_task_execution()->revive( $post_id );
		if ( true === ( $revive['revived'] ?? false ) ) {
			mlsimport_saas_single_write_import_custom_logs( 'Watchdog revived the import worker chain for task ' . $post_id . '.' . PHP_EOL, 'manual' );
		} elseif ( 'stalled' === ( $revive['reason'] ?? '' ) ) {
			mlsimport_saas_single_write_import_custom_logs( 'Watchdog declared the import run for task ' . $post_id . ' stalled and failed it.' . PHP_EOL, 'manual' );
		}

		$progress = $this->mlsimport_import_task_execution()->status( $post_id );
		$status   = (string) ( $progress['state'] ?? '' );
		$done     = '' === $status || in_array( $status, array( 'completed', 'stopped', 'failed' ), true );
		$path     = WP_PLUGIN_DIR . '/mlsimport/logs/status_logs.log';
		$logs     = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

		// Keep the old JSON field names so the current browser code continues to
		// work while their values now come from the one shared progress record.
		wp_send_json(
			array(
				'is_done'                       => $done ? 'done' : 'wip',
				'status'                        => $status,
				'logs'                          => $logs,
				'mlsimport_progress_properties' => (int) ( $progress['handled'] ?? 0 ),
				'mlsimport_progress_batches'    => (int) ( $progress['handled'] ?? 0 ),
				'mlsimport_task_to_import'      => (int) ( $progress['expected'] ?? 0 ),
				// The import worker records its own memory with each progress
				// update; showing this AJAX request's memory instead would only
				// mislead (it grows with the log file it returns).
				'memory'                        => $progress['memory'] ?? round( memory_get_usage( true ) / 1048576, 2 ),
				'post_id'                       => $post_id,
				'result'                        => $progress['result'] ?? array(),
			)
		);
	}






	/**
	 * AJAX: request a force-stop of a running import for one task.
	 *
	 * Sets the per-task force-stop option to 'yes' and clears its object-cache
	 * entry so the in-flight background loop notices on its next iteration.
	 *
	 * @return void Emits JSON success.
	 */
	public function mlsimport_stop_import_per_item() {


		// CSRF + read the task id.
		check_ajax_referer( 'mlsimport_item_actions', 'security' );
		$post_id=0;
		if(isset($_POST['post_id'] )){
				$post_id = intval( $_POST['post_id'] );
		}
		// Admin boundary: the target must be an Import Task the user can edit.
		if ( 'mlsimport_item' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not allowed to manage this import task.', 'mlsimport' ) ), 403 );
		}
		$stop = $this->mlsimport_import_task_execution()->stop( $post_id );
		// Preserve the old option for third-party callers that inspect it. The
		// shared runner itself uses the run-scoped Stop request above.
		update_option( 'mlsimport_force_stop_' . $post_id, 'yes', false );
		mlsimport_saas_single_write_import_custom_logs( 'Stopped  for ' . $post_id . PHP_EOL );
		mlsimport_debuglogs_per_plugin( 'Stopped  for ' . $post_id . PHP_EOL );
		wp_send_json_success( array( 'accepted' => (bool) $stop['accepted'] ) );
	}



	/**
	 * AJAX: fetch the MLS metadata (theme schema + field data + enums) for the
	 * configured theme and cache it in options, marking metadata as populated.
	 *
	 * @return void
	 */
	public function mlsimport_saas_get_metadata_function() {
		// CSRF.
		check_ajax_referer( 'mlsimport_saas_get_metadata', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not allowed to gather MLS metadata.', 'mlsimport' ) ), 403 );
		}
		$theme_Start = new ThemeImport();

		// GET /clients?theme_id=<id> to retrieve the schema + MLS metadata.
		$values  = array();
		$options = get_option( $this->plugin_name . '_admin_options' );
		$url     = 'clients?theme_id=' . intval( $options['mlsimport_theme_used'] );

		$answer = $theme_Start::globalApiRequestSaas( $url, $values, 'GET' );
		// If the API call failed, STOP before touching anything. A failed request
		// returns ['success' => false, ...] with none of the metadata keys; writing
		// that would overwrite the good cached metadata with nothing and mark the
		// site populated with an empty field list. Keep the old cache and the
		// "not populated" state so the next page load retries.
		if ( ! is_array( $answer ) || ! isset( $answer['theme_schema'], $answer['mls_data']['mls_meta_data'], $answer['mls_data']['mls_meta_enums'] ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Gathering MLS metadata failed. Nothing was changed - it will retry on the next page load.', 'mlsimport' ),
					'detail'  => is_array( $answer ) && isset( $answer['error_message'] ) ? $answer['error_message'] : '',
				),
				502
			);
		}

		// Metadata contains the authoritative provider type stored with this MLS.
		// Record it without moving field_corellation out of the SaaS/Dynamo data.
		if ( isset( $answer['mls_data']['type'], $options['mlsimport_mls_name'] ) ) {
			Mlsimport_Provider_Family::remember_type(
				$answer['mls_data']['type'],
				$options['mlsimport_mls_name']
			);
		}

		// Cache metadata first, then build/reconcile the complete Field
		// Configuration in one server-side save. The browser never posts 1,000
		// individual initialization requests and opening the page remains read-only.
		update_option( 'mlsimport_mls_metadata_theme_schema', $answer['theme_schema'] );
		update_option( 'mlsimport_mls_metadata_mls_data', $answer['mls_data']['mls_meta_data'] );
		update_option( 'mlsimport_mls_metadata_mls_enums', $answer['mls_data']['mls_meta_enums'] );

		$metadata = is_string( $answer['mls_data']['mls_meta_data'] )
			? json_decode( $answer['mls_data']['mls_meta_data'], true )
			: $answer['mls_data']['mls_meta_data'];
		$metadata = is_array( $metadata ) ? $metadata : array();
		$result   = mlsimport_reconcile_field_configuration( $metadata, mlsimport_hardocde_theme_schema() );
		if ( ! $result['success'] ) {
			delete_option( 'mlsimport_mls_metadata_populated' );
			wp_send_json_error( $result, 500 );
		}

		update_option( 'mlsimport_mls_metadata_populated', 'yes' );
		wp_send_json_success( array( 'revision' => $result['revision'] ) );
	}












	/**
	 * Append a timestamped message to the cron log file.
	 *
	 * Arrays are JSON-encoded; ensures the WP filesystem is initialized before
	 * writing (append + exclusive lock).
	 *
	 * @param string|array $message Message to log.
	 * @return void
	 */
	public function mlsimport_debuglog_cron( $message ) {
		// Encode arrays for readability.
		if ( is_array( $message ) ) {
			$message = wp_json_encode( $message );
		}
		// Prefix with a human-readable timestamp.
		$message = date( 'F j, Y, g:i a' ) . ' -> ' . $message;
		// Ensure WP_Filesystem is available (harmless if already set up).
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Append to the cron log with an exclusive lock.
		$path = WP_PLUGIN_DIR . '/mlsimport/logs/cron_logs.log';

		file_put_contents( $path, $message, FILE_APPEND | LOCK_EX );
	}

}
