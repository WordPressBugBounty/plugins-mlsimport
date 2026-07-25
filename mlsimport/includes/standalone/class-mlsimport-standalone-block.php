<?php
/**
 * Standalone (theme_id 990) Gutenberg block (M7).
 *
 * A dynamic, server-rendered block: its render_callback maps the block
 * attributes through the same atts->args + render_grid path as the shortcode,
 * so block / shortcode / widget all emit identical output. (The editor-side
 * inspector UI is a separate JS build; this is the render contract.)
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-render.php';
require_once __DIR__ . '/class-mlsimport-standalone-shortcodes.php';
require_once __DIR__ . '/class-mlsimport-page-block-search-fields.php';
require_once __DIR__ . '/page-block-registry.php';

/**
 * Registers and renders the mlsimport/listings and mlsimport/half-map blocks.
 *
 * Both share the rich listings inspector (filter presets + per-field toggles +
 * "properties to display"). Half Map is owned here — not by the generic page-block
 * adapter (Mlsimport_Page_Block_Blocks, which skips it) — so it reuses that
 * inspector instead of one raw text input per filter key. Its markup still comes
 * from the page-block dispatcher, so block / shortcode / Elementor stay identical.
 */
class Mlsimport_Standalone_Block {

	const NAME = 'mlsimport/listings';

	/** The Half Map block: the listings inspector + a Layout panel (map side, height). */
	const HALF_MAP_NAME = 'mlsimport/half-map';

	/** The Search Results block: a trimmed listings inspector (per-page, show-bar,
	 * fields-per-row, per-field toggles). Owned here — not the generic page-block
	 * adapter — so it reuses those toggles instead of a raw search_fields text input. */
	const RESULTS_NAME = 'mlsimport/property-list-filters';

	/** The Content Slider block: a Slider panel (how many + explicit IDs) + the same
	 * rich "Initial filter" panel as the Half Map (taxonomy dropdowns, price, beds/baths,
	 * order). Owned here so it reuses that panel instead of one raw text input per key. */
	const SLIDER_NAME = 'mlsimport/content-slider';

	/** The Property List block: the rich listings inspector (Settings + Initial filter +
	 * per-field toggles) minus the map Layout panel. Owned here — not the generic page-block
	 * adapter — so it reuses those toggles instead of raw text / comma-list inputs. */
	const ITEM_LIST_NAME = 'mlsimport/item-list';

	/** The Map with Listings block: a Settings panel (how many + explicit IDs) + the same
	 * rich Initial filter panel the Half Map and Property List expose. Owned here — not the
	 * generic page-block adapter — so it reuses those dropdowns instead of raw text inputs. */
	const MAP_NAME = 'mlsimport/map-listings';

	/** Editor script handle (registered at init, localized on editor assets). */
	const EDITOR_HANDLE = 'mlsimport-standalone-block';

	/**
	 * Register the dynamic blocks (idempotent).
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$editor_script = self::register_editor_script();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) ) {
			register_block_type(
				self::NAME,
				array(
					'render_callback' => array( __CLASS__, 'render' ),
					'attributes'      => self::attributes(),
					'editor_script'   => $editor_script,
				)
			);
		}

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( self::HALF_MAP_NAME ) ) {
			register_block_type(
				self::HALF_MAP_NAME,
				array(
					'render_callback' => array( __CLASS__, 'render_half_map' ),
					'attributes'      => self::half_map_attributes(),
					'editor_script'   => $editor_script,
				)
			);
		}

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( self::RESULTS_NAME ) ) {
			register_block_type(
				self::RESULTS_NAME,
				array(
					'render_callback' => array( __CLASS__, 'render_results' ),
					'attributes'      => self::results_attributes(),
					'editor_script'   => $editor_script,
				)
			);
		}

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( self::SLIDER_NAME ) ) {
			register_block_type(
				self::SLIDER_NAME,
				array(
					'render_callback' => array( __CLASS__, 'render_slider' ),
					'attributes'      => self::slider_attributes(),
					'editor_script'   => $editor_script,
				)
			);
		}

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( self::ITEM_LIST_NAME ) ) {
			register_block_type(
				self::ITEM_LIST_NAME,
				array(
					'render_callback' => array( __CLASS__, 'render_item_list' ),
					'attributes'      => self::item_list_attributes(),
					'editor_script'   => $editor_script,
				)
			);
		}

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( self::MAP_NAME ) ) {
			register_block_type(
				self::MAP_NAME,
				array(
					'render_callback' => array( __CLASS__, 'render_map' ),
					'attributes'      => self::map_attributes(),
					'editor_script'   => $editor_script,
				)
			);
		}

		// The taxonomy-term options are only needed in the editor, so localize them
		// there (and not on every front-end request, where get_terms would be dead work).
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'localize_editor' ) );
	}

	/**
	 * Register the editor script that JS-registers this dynamic block so it
	 * appears in the inserter carrying the same brand icon as the Import Tasks
	 * menu. Returns the script handle (or '' if scripts are unavailable).
	 *
	 * @return string
	 */
	private static function register_editor_script(): string {
		if ( ! function_exists( 'wp_register_script' ) ) {
			return '';
		}
		$url = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );
		// Version by file mtime so a JS edit busts the editor cache even when the
		// plugin version constant is unchanged (see Mlsimport_Page_Block_Blocks).
		$path = defined( 'MLSIMPORT_PLUGIN_PATH' ) ? MLSIMPORT_PLUGIN_PATH . 'admin/js/mlsimport-standalone-block.js' : '';
		$ver  = ( $path && file_exists( $path ) ) ? (string) filemtime( $path ) : ( defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : '1' );

		wp_register_script(
			self::EDITOR_HANDLE,
			$url . 'admin/js/mlsimport-standalone-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ),
			$ver,
			true
		);
		return self::EDITOR_HANDLE;
	}

	/**
	 * Attach the editor config to the block script (editor context only). Carries the
	 * block name, brand icon, the search-field toggle catalog and — for the initial
	 * filter inspector — the term options of every standalone taxonomy.
	 *
	 * @return void
	 */
	public static function localize_editor(): void {
		if ( ! function_exists( 'wp_localize_script' ) ) {
			return;
		}
		$url = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/mlsimport.php' );

		// Re-skin MLSImport block inspector controls to the shared "2025" admin design
		// system (matches the settings page / field_options tab). Scoped by the
		// .mlsimport-block-inspector class the PanelBody wrapper adds, so it only
		// touches MLSImport panels. mtime-versioned so CSS edits bust the editor cache.
		if ( function_exists( 'wp_enqueue_style' ) ) {
			$css_path = defined( 'MLSIMPORT_PLUGIN_PATH' ) ? MLSIMPORT_PLUGIN_PATH . 'admin/css/mlsimport-block-editor.css' : '';
			$css_ver  = ( $css_path && file_exists( $css_path ) ) ? (string) filemtime( $css_path ) : ( defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : '1' );
			wp_enqueue_style(
				'mlsimport-block-editor',
				$url . 'admin/css/mlsimport-block-editor.css',
				array( 'wp-components' ),
				$css_ver
			);
		}

		wp_localize_script(
			self::EDITOR_HANDLE,
			'MLSImportBlock',
			array(
				'name'         => self::NAME,
				'iconUrl'      => $url . 'img/mlsimport_menu.png',
				'searchFields' => self::search_fields(),
				'taxonomies'   => self::taxonomy_options(),
				'blocks'       => array(
					array(
						'name'        => self::NAME,
						'title'       => __( 'MLS Listings', 'mlsimport' ),
						'description' => __( 'Display imported MLS listings in a filterable grid.', 'mlsimport' ),
						'category'    => 'widgets',
						'layout'      => false,
						'kind'        => 'listings',
					),
					array(
						'name'        => self::HALF_MAP_NAME,
						'title'       => __( 'Half Map', 'mlsimport' ),
						'description' => __( 'Filterable MLS listings beside a live, viewport-clustered map.', 'mlsimport' ),
						'category'    => 'mlsimport-real-estate',
						'layout'      => true,
						'kind'        => 'half-map',
					),
					array(
						'name'        => self::RESULTS_NAME,
						'title'       => __( 'Search Results', 'mlsimport' ),
						'description' => __( 'MLS search results seeded from the search form, with an optional refine bar.', 'mlsimport' ),
						'category'    => 'mlsimport-real-estate',
						'layout'      => false,
						'kind'        => 'results',
					),
					array(
						'name'        => self::SLIDER_NAME,
						'title'       => __( 'Property Slider', 'mlsimport' ),
						'description' => __( 'Selected MLS listings as a swipeable carousel, with an initial filter.', 'mlsimport' ),
						'category'    => 'mlsimport-real-estate',
						'layout'      => false,
						'kind'        => 'slider',
					),
					array(
						'name'        => self::ITEM_LIST_NAME,
						'title'       => __( 'Property List', 'mlsimport' ),
						'description' => __( 'A filterable MLS listings grid with a search bar and pagination.', 'mlsimport' ),
						'category'    => 'mlsimport-real-estate',
						'layout'      => false,
						'kind'        => 'item-list',
					),
					array(
						'name'        => self::MAP_NAME,
						'title'       => __( 'Map with Listings', 'mlsimport' ),
						'description' => __( 'A map of MLS listings matching an initial filter (or an explicit set).', 'mlsimport' ),
						'category'    => 'mlsimport-real-estate',
						'layout'      => false,
						'kind'        => 'map',
					),
				),
			)
		);
	}

	/**
	 * The taxonomy filter controls for the initial-filter inspector: one entry per
	 * standalone taxonomy field, each carrying its attribute key, label and term
	 * options. Option values follow the field's own convention — term name for the
	 * fast-column taxonomies, slug for the term-join ones — so a chosen value drops
	 * straight into the matching block attribute the render path already understands.
	 *
	 * Public because the Elementor Property List widget builds the same initial-filter
	 * pickers from this one list, so the two builders can never offer different terms.
	 *
	 * @return array<int,array{key:string,label:string,options:array<int,array{label:string,value:string}>}>
	 */
	public static function taxonomy_options(): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}
		$out = array();
		// One control per catalog field that is a real, registered taxonomy field.
		foreach ( Mlsimport_Page_Block_Search_Fields::catalog() as $key ) {
			$def = Mlsimport_Page_Block_Search_Fields::definition( $key );
			// Skip non-taxonomy fields and any taxonomy that isn't registered.
			if ( null === $def || 'taxonomy' !== $def['group'] || ! taxonomy_exists( $def['tax'] ) ) {
				continue;
			}
			// Pull every term (including empty ones) so the editor lists all options.
			$terms = get_terms(
				array(
					'taxonomy'   => $def['tax'],
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			// Build { label, value } options; the value follows the field's own
			// convention (slug for term-join fields, term name for fast-column ones).
			$options = array();
			foreach ( $terms as $term ) {
				$value     = 'slug' === $def['value'] ? (string) $term->slug : (string) $term->name;
				$options[] = array( 'label' => (string) $term->name, 'value' => $value );
			}
			$out[] = array(
				'key'     => $key,
				'label'   => (string) $def['label'],
				'options' => $options,
			);
		}
		return $out;
	}

	/**
	 * Map block attributes to filter args and render the grid (render_callback). The
	 * search_fields attribute is display config (the per-field on/off toggles), not a
	 * filter key, so it is injected after atts_to_args (which keeps only filter keys).
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ): string {
		$attributes = (array) $attributes;
		$args       = Mlsimport_Standalone_Shortcodes::atts_to_args( $attributes );
		if ( isset( $attributes['search_fields'] ) && '' !== $attributes['search_fields'] ) {
			$args['search_fields'] = (string) $attributes['search_fields'];
		}
		// fields_per_row is search-form display config (columns per row), not a filter
		// key, so it is injected after atts_to_args (which keeps only filter keys).
		if ( isset( $attributes['fields_per_row'] ) && '' !== $attributes['fields_per_row'] ) {
			$args['fields_per_row'] = (string) $attributes['fields_per_row'];
		}
		return Mlsimport_Standalone_Render::render_grid( $args );
	}

	/**
	 * Render the Half Map block through the page-block dispatcher, so its markup,
	 * assets and hooks match the shortcode and Elementor widget exactly. The block
	 * attributes (filter presets + search_fields + map_side + height) map 1:1 to the
	 * dispatcher args.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_half_map( $attributes ): string {
		return mlsimport_render_page_block( 'half_map', (array) $attributes );
	}

	/**
	 * Render the Search Results block through the page-block dispatcher, so its
	 * markup, assets and hooks match the shortcode and Elementor widget exactly. The
	 * dispatcher's mlsimport_page_block_results reads the GET search; these attributes
	 * are only the refine-bar display config.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_results( $attributes ): string {
		return mlsimport_render_page_block( 'property_list_filters', (array) $attributes );
	}

	/**
	 * Render the Content Slider block through the page-block dispatcher, so its markup,
	 * assets and hooks match the shortcode and Elementor widget exactly. The block
	 * attributes (initial-filter presets + how-many + explicit IDs) map 1:1 to the
	 * dispatcher args; the slider render maps them through the same atts->args path.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_slider( $attributes ): string {
		return mlsimport_render_page_block( 'content_slider', (array) $attributes );
	}

	/**
	 * Render the Property List block through the page-block dispatcher, so its markup,
	 * assets and hooks match the shortcode and Elementor widget exactly. The block
	 * attributes (initial-filter presets + per-page + show-bar + fields-per-row +
	 * search_fields) map 1:1 to the dispatcher args.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_item_list( $attributes ): string {
		return mlsimport_render_page_block( 'item_list', (array) $attributes );
	}

	/**
	 * Render the Map with Listings block through the page-block dispatcher, so its markup,
	 * assets and hooks match the shortcode and Elementor widget exactly. The block
	 * attributes (initial-filter presets + how-many + explicit IDs) map 1:1 to the
	 * dispatcher args.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_map( $attributes ): string {
		return mlsimport_render_page_block( 'map_listings', (array) $attributes );
	}

	/**
	 * Content Slider attribute schema — every filter key as an optional string (the
	 * "Initial filter" panel) plus an explicit-IDs list. limit defaults to 6 (the
	 * "How many" control), matching the manifest's slider count default.
	 *
	 * @return array
	 */
	private static function slider_attributes(): array {
		// One optional string attribute per filter key; limit defaults to the slider count.
		$attributes = array();
		foreach ( Mlsimport_Standalone_Shortcodes::filter_keys() as $key ) {
			$attributes[ $key ] = array(
				'type'    => 'string',
				'default' => 'limit' === $key ? '6' : '',
			);
		}
		$attributes['ids'] = array(
			'type'    => 'string',
			'default' => '',
		);
		return $attributes;
	}

	/**
	 * Property List attribute schema — every filter key as an optional string (so the
	 * Initial filter panel presets and any shortcode-set filter validate) plus the
	 * search-bar config: per-page (count), show-bar, fields-per-row and the per-field
	 * toggle state (search_fields). Per-page is `count`, so the raw `limit` key is dropped.
	 *
	 * @return array
	 */
	private static function item_list_attributes(): array {
		$attributes = array();
		foreach ( Mlsimport_Standalone_Shortcodes::filter_keys() as $key ) {
			if ( 'limit' === $key ) {
				continue;
			}
			$attributes[ $key ] = array( 'type' => 'string', 'default' => '' );
		}
		$attributes['count']           = array( 'type' => 'string', 'default' => '12' );
		$attributes['show_filter_bar'] = array( 'type' => 'string', 'default' => '1' );
		$attributes['fields_per_row']  = array( 'type' => 'string', 'default' => '4' );
		$attributes['search_fields']   = array( 'type' => 'string', 'default' => '' );
		return $attributes;
	}

	/**
	 * Map with Listings attribute schema — every filter key as an optional string (so the
	 * Initial filter panel presets and any shortcode-set filter validate). count and ids
	 * stay registered so a shortcode-set value (or a pre-existing saved block) still
	 * validates, but both default empty: the editor exposes no How-many / IDs / Order
	 * control, because a map plots every match at its coordinates. A non-empty count would
	 * inject a pointless limit into the map's data-filters (bounds() ignores it and
	 * map_payload() strips it anyway), so it defaults to '' — the map shows them all.
	 *
	 * @return array
	 */
	private static function map_attributes(): array {
		$attributes = array();
		foreach ( Mlsimport_Standalone_Shortcodes::filter_keys() as $key ) {
			if ( 'limit' === $key || 'page' === $key ) {
				continue;
			}
			$attributes[ $key ] = array( 'type' => 'string', 'default' => '' );
		}
		$attributes['count'] = array( 'type' => 'string', 'default' => '' );
		$attributes['ids']   = array( 'type' => 'string', 'default' => '' );
		return $attributes;
	}

	/**
	 * Search Results attribute schema — only the refine-bar display config (the
	 * filters themselves come from the request, not saved attributes). Mirrors the
	 * manifest defaults so an unconfigured block shows the bar, 4-up, all fields.
	 *
	 * @return array
	 */
	private static function results_attributes(): array {
		return array(
			'count'           => array( 'type' => 'string', 'default' => '12' ),
			'show_filter_bar' => array( 'type' => 'string', 'default' => '1' ),
			'fields_per_row'  => array( 'type' => 'string', 'default' => '4' ),
			'search_fields'   => array( 'type' => 'string', 'default' => '' ),
		);
	}

	/**
	 * Block attribute schema — every filter key as an optional string, plus
	 * search_fields (the per-field on/off toggle state: a comma list of the search
	 * fields to show; empty = show all). limit defaults to 12 (the "Properties to
	 * display" control), matching the render-grid per-page default.
	 *
	 * @return array
	 */
	private static function attributes(): array {
		$attributes = array();
		foreach ( Mlsimport_Standalone_Shortcodes::filter_keys() as $key ) {
			$attributes[ $key ] = array(
				'type'    => 'string',
				'default' => 'limit' === $key ? '12' : '',
			);
		}
		$attributes['search_fields'] = array(
			'type'    => 'string',
			'default' => '',
		);
		// Search-bar columns (the "Search fields per row" control); 4-up by default for
		// the full-width listings bar. Half Map overrides this to 3 (its narrow pane).
		$attributes['fields_per_row'] = array(
			'type'    => 'string',
			'default' => '4',
		);
		return $attributes;
	}

	/**
	 * Half Map attribute schema — the listings schema plus the two Layout-panel
	 * controls (map_side, height). Defaults mirror the manifest so an unconfigured
	 * block renders a right-side, full-height map.
	 *
	 * @return array
	 */
	private static function half_map_attributes(): array {
		$attributes                   = self::attributes();
		$attributes['fields_per_row'] = array(
			'type'    => 'string',
			'default' => '3',
		);
		$attributes['map_side']       = array(
			'type'    => 'string',
			'default' => 'right',
		);
		$attributes['height']   = array(
			'type'    => 'string',
			'default' => '100vh',
		);
		return $attributes;
	}

	/**
	 * The search form's toggleable fields in render order: the keyword box, every
	 * searchable catalog field, then the sort control — each as { key, label } so the
	 * editor can build one on/off toggle per field. Mirrors search-form.php.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	private static function search_fields(): array {
		$labels = array_merge(
			array( 'keywords' => __( 'Keywords', 'mlsimport' ) ),
			Mlsimport_Page_Block_Search_Fields::labels(),
			array( 'sort' => __( 'Sort by', 'mlsimport' ) )
		);
		$fields = array();
		foreach ( $labels as $key => $label ) {
			$fields[] = array( 'key' => $key, 'label' => $label );
		}
		return $fields;
	}
}
