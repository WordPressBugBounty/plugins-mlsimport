<?php
/**
 * Standalone (theme_id 990) page-block manifest + dispatcher.
 *
 * Page blocks are the building blocks a site builder drops onto a normal page
 * (home, landing, agent) — a property list, a slider, a map, a search form, a
 * contact form, a featured property. They are the page-building counterpart to
 * Property sections (ADR-0005): same one-function-wrapped-per-builder pattern,
 * but driven by their own args (a query/config), never the single-property loop.
 *
 * One registry maps a block slug to its metadata (label, render callback, arg
 * schema, required assets). Every builder adapter (Shortcode, Gutenberg,
 * Elementor, future builders) loops this manifest, so adding a block is one
 * register call + one render fn and all builders pick it up. The dispatcher is the
 * single place that resolves the render fn, merges arg defaults, enqueues the
 * block's assets and fires the WooCommerce-style hooks. See docs/adr/0007 and
 * CONTEXT.md (Page block).
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/page-blocks.php';
require_once __DIR__ . '/page-blocks-categories.php';
require_once __DIR__ . '/page-blocks-agents.php';
require_once __DIR__ . '/class-mlsimport-property-section-assets.php';

/**
 * Register a page block in the manifest.
 *
 * @param string $slug Block slug (e.g. 'item_list').
 * @param array  $def  {
 *     @type string   $label  Editor-facing label.
 *     @type callable $render Render fn: ( array $args ) => string.
 *     @type array    $args   Arg schema: key => { type, label, default, options }.
 *     @type array    $assets Optional asset handles to enqueue when rendered.
 * }
 * @return void
 */
function mlsimport_register_page_block( string $slug, array $def ): void {
	// Grab the shared registry by reference so the write persists.
	$registry =& mlsimport_page_block_registry();

	// Store the block under its slug, filling each key with a safe default.
	$registry[ $slug ] = array(
		'label'  => isset( $def['label'] ) ? (string) $def['label'] : $slug,
		'render' => isset( $def['render'] ) ? $def['render'] : '',
		'args'   => isset( $def['args'] ) ? (array) $def['args'] : array(),
		'assets' => isset( $def['assets'] ) ? (array) $def['assets'] : array(),
	);
}

/**
 * The registered page blocks, slug => definition.
 *
 * @return array
 */
function mlsimport_get_page_blocks(): array {
	// Ensure the built-ins are registered before exposing the manifest.
	mlsimport_register_builtin_page_blocks();
	/** Filter the page-block manifest: add/remove/reorder blocks globally. @since 6.4 */
	return (array) apply_filters( 'mlsimport_page_blocks', mlsimport_page_block_registry() );
}

/**
 * The default args for a block, taken from its schema.
 *
 * @param string $slug Block slug.
 * @return array key => default.
 */
function mlsimport_page_block_defaults( string $slug ): array {
	// Unknown slug → no defaults.
	$registry = mlsimport_page_block_registry();
	if ( ! isset( $registry[ $slug ] ) ) {
		return array();
	}
	// Collapse the arg schema to a flat key => default map.
	$defaults = array();
	foreach ( $registry[ $slug ]['args'] as $key => $schema ) {
		$defaults[ $key ] = isset( $schema['default'] ) ? $schema['default'] : '';
	}
	return $defaults;
}

/**
 * Render a registered page block by slug (the one dispatch point for all builders).
 *
 * @param string $slug Block slug.
 * @param array  $args Caller args (override the schema defaults).
 * @return string HTML, or '' for an unknown slug / uncallable render fn.
 */
function mlsimport_render_page_block( string $slug, array $args = array() ): string {
	// Ensure built-ins exist, then resolve the block's definition.
	mlsimport_register_builtin_page_blocks();
	$registry = mlsimport_page_block_registry();
	// Unknown slug or non-callable render fn → nothing to render.
	if ( ! isset( $registry[ $slug ] ) || ! is_callable( $registry[ $slug ]['render'] ) ) {
		return '';
	}

	// Schema defaults first; caller args win.
	$args = array_merge( mlsimport_page_block_defaults( $slug ), $args );
	/** Filter the resolved block args before render. @since 6.4 */
	$args = (array) apply_filters( 'mlsimport_page_block_args', $args, $slug );

	// Action slot before the block (buffered so the function returns one string).
	ob_start();
	/** Inject markup before any page block; $slug identifies which. @since 6.4 */
	do_action( 'mlsimport_before_page_block', $slug, $args );
	/** Inject markup before a page block. Dynamic by slug. @since 6.4 */
	do_action( "mlsimport_before_page_block_{$slug}", $args );
	$before = (string) ob_get_clean();

	// Call the block's render fn to produce its core markup.
	$html = (string) call_user_func( $registry[ $slug ]['render'], $args );

	// Data-driven: a block's assets load only when it actually renders output.
	if ( '' !== $html ) {
		Mlsimport_Property_Section_Assets::enqueue( $registry[ $slug ]['assets'] );
	}

	/** Filter a single block's markup. Dynamic by slug. @since 6.4 */
	$html = apply_filters( "mlsimport_page_block_{$slug}_html", $html, $args );
	/** Filter any block's markup; $slug identifies which. @since 6.4 */
	$html = apply_filters( 'mlsimport_page_block_html', $html, $slug, $args );

	// Action slot after the block.
	ob_start();
	/** Inject markup after a page block. Dynamic by slug. @since 6.4 */
	do_action( "mlsimport_after_page_block_{$slug}", $args );
	/** Inject markup after any page block; $slug identifies which. @since 6.4 */
	do_action( 'mlsimport_after_page_block', $slug, $args );
	$after = (string) ob_get_clean();

	// Concatenate the buffered before/after slots around the block markup.
	return $before . $html . $after;
}

/**
 * Register the plugin's built-in page blocks. Idempotent.
 *
 * @return void
 */
function mlsimport_register_builtin_page_blocks(): void {
	// Idempotence: register the built-ins at most once per request.
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	// Asset-handle bundles reused across several block definitions below.
	$slider   = array( 'mlsimport-splide', 'mlsimport-property-slider', 'mlsimport-glightbox', 'mlsimport-property-lightbox' );
	$lead     = array( 'mlsimport-property-lead' );

	// Search field catalog (key => label) for the search-form repeater's field
	// picker: every taxonomy, then every fast-table column except the geo coords.
	require_once __DIR__ . '/class-mlsimport-page-block-search-fields.php';
	$catalog = Mlsimport_Page_Block_Search_Fields::labels();
	// The range-slider fields, which are the only rows whose slider bounds can be
	// overridden — used as the editor condition on the min/max row controls.
	$range_fields = Mlsimport_Page_Block_Search_Fields::range_fields();

	// A field's width is its share of a 12-column row, shared verbatim by the search
	// and contact form repeaters so a site builder learns one vocabulary.
	$width_field = array(
		'type'    => 'select',
		'label'   => __( 'Width', 'mlsimport' ),
		'default' => '',
		// Labelled as fractions of the row — shorter to scan in the inspector than
		// words, and it reads as a proportion, which is what the value actually is.
		// The stored values are unchanged, so existing pages keep their widths.
		'options' => array(
			''           => __( '1/1 (full row)', 'mlsimport' ),
			'two_thirds' => __( '2/3', 'mlsimport' ),
			'half'       => __( '1/2', 'mlsimport' ),
			'third'      => __( '1/3', 'mlsimport' ),
			'quarter'    => __( '1/4', 'mlsimport' ),
		),
	);

	// The size steps a form's buttons and fields step through, shared by the search and
	// contact forms so "medium" means the same thing on both.
	$size_options = array(
		'small'  => __( 'Small', 'mlsimport' ),
		'medium' => __( 'Medium', 'mlsimport' ),
		'large'  => __( 'Large', 'mlsimport' ),
	);

	// Repeater row schema for the search form (field key, optional label/placeholder,
	// and the row's width).
	$search_rows = array(
		'type'    => 'repeater',
		'label'   => __( 'Search fields', 'mlsimport' ),
		'default' => array(
			array( 'field' => 'location', 'label' => __( 'Location', 'mlsimport' ), 'placeholder' => __( 'City, area, county or ZIP', 'mlsimport' ), 'width' => 'half' ),
			array( 'field' => 'property_type', 'label' => __( 'Type', 'mlsimport' ), 'placeholder' => '', 'width' => 'half' ),
			array( 'field' => 'price', 'label' => __( 'Price', 'mlsimport' ), 'placeholder' => '', 'width' => 'third' ),
			array( 'field' => 'beds_baths', 'label' => __( 'Beds & Baths', 'mlsimport' ), 'placeholder' => '', 'width' => 'third' ),
		),
		'fields'  => array(
			'field'       => array( 'type' => 'select', 'label' => __( 'Field', 'mlsimport' ), 'default' => 'city', 'options' => $catalog ),
			'label'       => array( 'type' => 'text', 'label' => __( 'Label', 'mlsimport' ), 'default' => '' ),
			'placeholder' => array( 'type' => 'text', 'label' => __( 'Placeholder (shown when labels are hidden)', 'mlsimport' ), 'default' => '' ),
			// Slider bounds, for the range fields only (price, living area, lot, year).
			// Left empty each field keeps the bounds computed from the live data; set,
			// they pin the slider to a chosen window. One pair covers every range field
			// rather than a price-only special case.
			// Text, not number: the editors coerce an emptied number control to 0, which
			// would read as a real bound of zero instead of "unset".
			'min_value'   => array(
				'type'      => 'text',
				'label'     => __( 'Slider minimum', 'mlsimport' ),
				'default'   => '',
				'condition' => array( 'field' => $range_fields ),
			),
			'max_value'   => array(
				'type'      => 'text',
				'label'     => __( 'Slider maximum', 'mlsimport' ),
				'default'   => '',
				'condition' => array( 'field' => $range_fields ),
			),
			'width'       => $width_field,
		),
	);

	// Repeater row schema for the contact form (per-field label/name/type/etc.).
	// A field's width is its share of the row: two halves sit side by side, three
	// thirds make a three-up row, and the default (full) keeps a field on its own.
	$contact_rows = array(
		'type'    => 'repeater',
		'label'   => __( 'Form fields', 'mlsimport' ),
		'default' => array(
			array( 'name' => 'name', 'type' => 'text', 'label' => __( 'Name', 'mlsimport' ), 'placeholder' => '', 'options' => '', 'required' => 'yes', 'width' => 'half' ),
			array( 'name' => 'email', 'type' => 'email', 'label' => __( 'Email', 'mlsimport' ), 'placeholder' => '', 'options' => '', 'required' => 'yes', 'width' => 'half' ),
			array( 'name' => 'phone', 'type' => 'tel', 'label' => __( 'Phone', 'mlsimport' ), 'placeholder' => '', 'options' => '', 'required' => '', 'width' => '' ),
			array( 'name' => 'message', 'type' => 'textarea', 'label' => __( 'Message', 'mlsimport' ), 'placeholder' => '', 'options' => '', 'required' => '', 'width' => '' ),
		),
		'fields'  => array(
			'label'       => array( 'type' => 'text', 'label' => __( 'Label', 'mlsimport' ), 'default' => '' ),
			'name'        => array( 'type' => 'text', 'label' => __( 'Field name', 'mlsimport' ), 'default' => '' ),
			'type'        => array(
				'type'    => 'select',
				'label'   => __( 'Type', 'mlsimport' ),
				'default' => 'text',
				'options' => array(
					'text'     => __( 'Text', 'mlsimport' ),
					'email'    => __( 'Email', 'mlsimport' ),
					'tel'      => __( 'Phone', 'mlsimport' ),
					'textarea' => __( 'Message', 'mlsimport' ),
					'select'   => __( 'Dropdown', 'mlsimport' ),
					'radio'    => __( 'Radio buttons', 'mlsimport' ),
					'checkbox' => __( 'Checkbox', 'mlsimport' ),
				),
			),
			'options'     => array( 'type' => 'text', 'label' => __( 'Choices, comma separated (dropdown and radio only)', 'mlsimport' ), 'default' => '' ),
			'placeholder' => array( 'type' => 'text', 'label' => __( 'Placeholder', 'mlsimport' ), 'default' => '' ),
			'required'    => array( 'type' => 'toggle', 'label' => __( 'Required', 'mlsimport' ), 'default' => '' ),
			'width'       => $width_field,
		),
	);

	// Shared selection controls (taxonomy + sort + count, or explicit ids).
	$sort_options = array(
		''           => __( 'Default', 'mlsimport' ),
		'newest'     => __( 'Newest', 'mlsimport' ),
		'price_low'  => __( 'Price (low to high)', 'mlsimport' ),
		'price_high' => __( 'Price (high to low)', 'mlsimport' ),
	);
	$selection = array(
		'city'          => array( 'type' => 'text', 'label' => __( 'City', 'mlsimport' ), 'default' => '' ),
		'property_type' => array( 'type' => 'text', 'label' => __( 'Property type', 'mlsimport' ), 'default' => '' ),
		'status'        => array( 'type' => 'text', 'label' => __( 'Status', 'mlsimport' ), 'default' => '' ),
		'features'      => array( 'type' => 'text', 'label' => __( 'Features (comma list)', 'mlsimport' ), 'default' => '' ),
		'sort'          => array( 'type' => 'select', 'label' => __( 'Sort', 'mlsimport' ), 'default' => 'newest', 'options' => $sort_options ),
		'count'         => array( 'type' => 'number', 'label' => __( 'How many', 'mlsimport' ), 'default' => 6 ),
		'ids'           => array( 'type' => 'text', 'label' => __( 'Explicit IDs (comma list)', 'mlsimport' ), 'default' => '' ),
	);
	$ids_only = array(
		'ids'   => array( 'type' => 'textarea', 'label' => __( 'Property WordPress Post ID (comma list)', 'mlsimport' ), 'default' => '' ),
		'count' => array( 'type' => 'number', 'label' => __( 'Per page', 'mlsimport' ), 'default' => 12 ),
	);

	// Half Map initial filter: every listings filter key as an optional text
	// preset — the exact set the standalone listings block exposes — so a builder
	// can pre-constrain the block (a "Miami condos" landing page). 'page' is
	// runtime paging, not a preset, so it is skipped.
	$listings_presets = array();
	foreach ( Mlsimport_Standalone_Shortcodes::filter_keys() as $fkey ) {
		if ( 'page' === $fkey ) {
			continue;
		}
		$listings_presets[ $fkey ] = array(
			'type'    => 'text',
			'label'   => ucwords( str_replace( '_', ' ', $fkey ) ),
			'default' => '',
		);
	}
	// Agent is an agent post ID, not free text — the same control the List Items per
	// Agent block exposes, so a list/slider can be narrowed to one agent's listings.
	$listings_presets['agent'] = array( 'type' => 'number', 'label' => __( 'Agent', 'mlsimport' ), 'default' => 0 );
	// Search fields per row — the search bar lays its fields out as this many columns
	// (shared by the Search Form, MLS Listings and Half Map widgets). 3–6 only.
	$fields_per_row_options = array( '3' => '3', '4' => '4', '5' => '5', '6' => '6' );

	// Half Map = the shared initial-filter presets + search-bar display config + map layout.
	$half_map_args = $listings_presets;
	$half_map_args['search_fields']  = array( 'type' => 'text', 'label' => __( 'Search fields (comma list; empty = all)', 'mlsimport' ), 'default' => '' );
	$half_map_args['fields_per_row'] = array( 'type' => 'select', 'label' => __( 'Search fields per row', 'mlsimport' ), 'default' => '3', 'options' => $fields_per_row_options );
	$half_map_args['map_side']       = array(
		'type'    => 'select',
		'label'   => __( 'Map side', 'mlsimport' ),
		'default' => 'right',
		'options' => array( 'right' => __( 'Right', 'mlsimport' ), 'left' => __( 'Left', 'mlsimport' ) ),
	);
	$half_map_args['height'] = array( 'type' => 'text', 'label' => __( 'Height (CSS, e.g. 100vh)', 'mlsimport' ), 'default' => '100vh' );

	// Property List = the same listings + initial filter + search bar as the Half
	// Map, shown above the grid and filtering it via AJAX, but no map pane. Same
	// manifest controls half_map exposes (presets + search_fields + fields_per_row)
	// plus a per-page size and a show/hide toggle for the bar (mirrors Search Results).
	$item_list_args = $listings_presets;
	// Sizing on this block is PAGINATION: "Per page" is the page size and the pager
	// governs the rest. The raw `limit` preset is dropped because render_grid lets a
	// preset limit win over count (see mlsimport_page_block_item_list), which would be
	// a second, hidden sizing control that silently defeats the pager. The slider drops
	// the same key for the mirror-image reason: it caps, and has no pager at all.
	unset( $item_list_args['limit'] );
	// Geo bounds and freehand polygon are outputs of the map's draw tools, not values a
	// builder sets by hand — and this block has no map pane — so drop them from the inspector.
	unset( $item_list_args['lat_min'], $item_list_args['lat_max'], $item_list_args['lng_min'], $item_list_args['lng_max'], $item_list_args['polygon'] );
	$item_list_args['count']           = array( 'type' => 'number', 'label' => __( 'Per page', 'mlsimport' ), 'default' => 12 );
	$item_list_args['show_filter_bar'] = array( 'type' => 'toggle', 'label' => __( 'Show filter bar', 'mlsimport' ), 'default' => '1' );
	$item_list_args['fields_per_row']  = array( 'type' => 'select', 'label' => __( 'Search fields per row', 'mlsimport' ), 'default' => '4', 'options' => $fields_per_row_options );
	$item_list_args['search_fields']   = array( 'type' => 'text', 'label' => __( 'Search fields (comma list; empty = all)', 'mlsimport' ), 'default' => '' );

	// Content Slider = friendly How-many + Order + explicit IDs, then the SAME initial-
	// filter presets the Half Map exposes (so city/type/status/features/price/beds/…
	// live in one place). The raw orderby/order/limit preset keys are dropped: the
	// slider's own Sort + How-many controls replace them (Selection maps them back).
	$slider_presets = $listings_presets;
	unset( $slider_presets['orderby'], $slider_presets['order'], $slider_presets['limit'] );
	// Geo bounds and freehand polygon are outputs of the map's draw tools, not values a
	// builder sets by hand — and the slider has no map pane — so drop them from the
	// inspector, exactly as the Property List block does.
	unset( $slider_presets['lat_min'], $slider_presets['lat_max'], $slider_presets['lng_min'], $slider_presets['lng_max'], $slider_presets['polygon'] );
	$slider_args = array(
		'count' => $selection['count'],
		'sort'  => $selection['sort'],
		'ids'   => $selection['ids'],
	) + $slider_presets;

	// Map with Listings = ONLY the shared initial-filter presets (city/type/status/price/
	// beds/…) — the SAME rich pickers the Content Slider and Gutenberg map expose. A map
	// plots every match at its coordinates, so it carries no page size (the query map never
	// honours limit), no order (pins have no sequence) and no explicit-ID surface. This is
	// the exact key set the Elementor widget builds, so render_settings forwards the SELECT2
	// term-picker (autocomplete) values and nothing else. Was a flat $selection (raw text
	// City/Type/Status/Features), which is why the Elementor widget showed plain text boxes.
	$map_args = $slider_presets;

	// Category widgets (Category Slider + Display Categories): one term multi-select
	// PER plugin taxonomy (Cities, Property Types, Areas, County, …). The operator
	// picks the exact terms to show as image tiles; picks from several taxonomies
	// combine, and the widget shows EXACTLY those terms (pick 2 → 2 tiles). Nothing
	// picked → nothing rendered. Each picker's option list — the taxonomy's terms —
	// is resolved lazily by the block-editor / Elementor adapters, never on the front
	// end (see mlsimport_category_term_options()). show-count is display-only (the
	// per-tile "N listings" tagline), not a tile count.
	$cat_taxonomies = class_exists( 'Mlsimport_Standalone_Cpt' ) ? Mlsimport_Standalone_Cpt::taxonomy_labels() : array( 'mlsimport_city' => __( 'Cities', 'mlsimport' ) );
	$per_row_1_6    = array( '1' => '1', '2' => '2', '3' => '3', '4' => '4', '6' => '6' );

	$cat_selection = array();
	foreach ( $cat_taxonomies as $tax_slug => $tax_label ) {
		$cat_selection[ $tax_slug ] = array(
			'type'     => 'multiselect',
			'label'    => $tax_label,
			'default'  => '',
			'taxonomy' => $tax_slug,
		);
	}
	$cat_selection += array(
		'show_count' => array( 'type' => 'toggle', 'label' => __( 'Show listing count', 'mlsimport' ), 'default' => '1' ),
	);

	// The built-in manifest: slug => { label, render fn, arg schema, assets }.
	$blocks = array(
		'category_slider' => array(
			'label'  => __( 'Category Slider', 'mlsimport' ),
			'render' => 'mlsimport_page_block_category_slider',
			'args'   => $cat_selection + array(
				'design'         => array( 'type' => 'select', 'label' => __( 'Design type', 'mlsimport' ), 'default' => '1', 'options' => array( '1' => __( 'Design 1', 'mlsimport' ), '2' => __( 'Design 2', 'mlsimport' ), '3' => __( 'Design 3', 'mlsimport' ) ) ),
				'per_row'        => array( 'type' => 'select', 'label' => __( 'Items per row', 'mlsimport' ), 'default' => '3', 'options' => $per_row_1_6 ),
				'item_height'    => array( 'type' => 'text', 'label' => __( 'Item height (CSS, e.g. 260px)', 'mlsimport' ), 'default' => '260px' ),
				'gap'            => array( 'type' => 'number', 'label' => __( 'Gap (px)', 'mlsimport' ), 'default' => 16 ),
				'border_radius'  => array( 'type' => 'number', 'label' => __( 'Border radius (px)', 'mlsimport' ), 'default' => 8 ),
				'text_padding'   => array( 'type' => 'number', 'label' => __( 'Interior text padding (px)', 'mlsimport' ), 'default' => 16 ),
				'title_margin'   => array( 'type' => 'number', 'label' => __( 'Title bottom margin (px)', 'mlsimport' ), 'default' => 4 ),
				'tagline_margin' => array( 'type' => 'number', 'label' => __( 'Tagline bottom margin (px)', 'mlsimport' ), 'default' => 0 ),
				'title_size'     => array( 'type' => 'number', 'label' => __( 'Title font size (px)', 'mlsimport' ), 'default' => 18 ),
				'title_color'    => array( 'type' => 'text', 'label' => __( 'Title color (CSS)', 'mlsimport' ), 'default' => '#ffffff' ),
			),
			'assets' => $slider,
		),
		'category_list' => array(
			'label'  => __( 'Display Categories', 'mlsimport' ),
			'render' => 'mlsimport_page_block_category_list',
			'args'   => $cat_selection + array(
				'design'        => array( 'type' => 'select', 'label' => __( 'Design type', 'mlsimport' ), 'default' => '1', 'options' => array( '1' => __( 'Design 1', 'mlsimport' ), '2' => __( 'Design 2', 'mlsimport' ), '3' => __( 'Design 3', 'mlsimport' ) ) ),
				'per_row'       => array( 'type' => 'select', 'label' => __( 'Items per row', 'mlsimport' ), 'default' => '4', 'options' => $per_row_1_6 ),
				'display_grid'  => array( 'type' => 'toggle', 'label' => __( 'Display as auto grid', 'mlsimport' ), 'default' => '' ),
				'min_width'     => array( 'type' => 'number', 'label' => __( 'Unit minimum width (px, auto grid)', 'mlsimport' ), 'default' => 220 ),
				'gap'           => array( 'type' => 'number', 'label' => __( 'Gap (px)', 'mlsimport' ), 'default' => 16 ),
				'item_height'   => array( 'type' => 'text', 'label' => __( 'Item height (CSS, e.g. 220px)', 'mlsimport' ), 'default' => '220px' ),
				'text_padding'  => array( 'type' => 'number', 'label' => __( 'Interior text padding (px)', 'mlsimport' ), 'default' => 14 ),
				'title_size'    => array( 'type' => 'number', 'label' => __( 'Title font size (px)', 'mlsimport' ), 'default' => 16 ),
				'title_color'   => array( 'type' => 'text', 'label' => __( 'Title color (CSS)', 'mlsimport' ), 'default' => '#ffffff' ),
				'border_radius' => array( 'type' => 'number', 'label' => __( 'Border radius (px)', 'mlsimport' ), 'default' => 8 ),
				'border_width'  => array( 'type' => 'number', 'label' => __( 'Border width (px, design 3)', 'mlsimport' ), 'default' => 0 ),
				'border_color'  => array( 'type' => 'text', 'label' => __( 'Border color (CSS, design 3)', 'mlsimport' ), 'default' => '#e0e0e0' ),
			),
		),
		'item_list' => array(
			'label'  => __( 'Property List', 'mlsimport' ),
			'render' => 'mlsimport_page_block_item_list',
			'args'   => $item_list_args,
		),
		'saved' => array(
			'label'  => __( 'Saved Properties', 'mlsimport' ),
			'render' => 'mlsimport_page_block_saved',
			'args'   => array(
				'count' => array( 'type' => 'number', 'label' => __( 'Per page', 'mlsimport' ), 'default' => 12 ),
			),
		),
		'property_list_filters' => array(
			'label'  => __( 'Search Results', 'mlsimport' ),
			'render' => 'mlsimport_page_block_results',
			'args'   => array(
				'count'           => array( 'type' => 'number', 'label' => __( 'Per page', 'mlsimport' ), 'default' => 12 ),
				'show_filter_bar' => array( 'type' => 'toggle', 'label' => __( 'Show filter bar', 'mlsimport' ), 'default' => '1' ),
				'fields_per_row'  => array( 'type' => 'select', 'label' => __( 'Search fields per row', 'mlsimport' ), 'default' => '4', 'options' => $fields_per_row_options ),
				'search_fields'   => array( 'type' => 'text', 'label' => __( 'Search fields (comma list; empty = all)', 'mlsimport' ), 'default' => '' ),
			),
		),
		'list_by_id' => array(
			'label'  => __( 'List Properties by ID', 'mlsimport' ),
			'render' => 'mlsimport_page_block_list_by_id',
			'args'   => $ids_only,
		),
		'content_slider' => array(
			'label'  => __( 'Property Slider', 'mlsimport' ),
			'render' => 'mlsimport_page_block_slider',
			'args'   => $slider_args,
			'assets' => $slider,
		),
		'agents_directory' => array(
			'label'  => __( 'Team Directory', 'mlsimport' ),
			'render' => 'mlsimport_page_block_agents_directory',
			'args'   => array(
				'agents'        => array( 'type' => 'multiselect', 'label' => __( 'Specific agents (empty = all)', 'mlsimport' ), 'default' => '', 'post_type' => 'mlsimport_agent' ),
				'count'         => array( 'type' => 'number', 'label' => __( 'How many (0 = all)', 'mlsimport' ), 'default' => 8 ),
				'featured_only' => array( 'type' => 'toggle', 'label' => __( 'Verified agents only', 'mlsimport' ), 'default' => '' ),
				'sort'          => array(
					'type'    => 'select',
					'label'   => __( 'Order', 'mlsimport' ),
					'default' => 'featured',
					'options' => array(
						'featured'  => __( 'Verified first', 'mlsimport' ),
						'name_asc'  => __( 'Alphabetical (A–Z)', 'mlsimport' ),
						'name_desc' => __( 'Alphabetical (Z–A)', 'mlsimport' ),
					),
				),
				'show_title'    => array( 'type' => 'toggle', 'label' => __( 'Show job title', 'mlsimport' ), 'default' => '1' ),
				'show_office'   => array( 'type' => 'toggle', 'label' => __( 'Show office name', 'mlsimport' ), 'default' => '1' ),
				'per_row'       => array( 'type' => 'select', 'label' => __( 'Items per row', 'mlsimport' ), 'default' => '4', 'options' => array( '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6' ) ),
				'gap'           => array( 'type' => 'number', 'label' => __( 'Gap (px)', 'mlsimport' ), 'default' => 16 ),
				'border_radius' => array( 'type' => 'number', 'label' => __( 'Border radius (px)', 'mlsimport' ), 'default' => 8 ),
			),
		),
		'featured_property' => array(
			'label'  => __( 'Featured Property', 'mlsimport' ),
			'render' => 'mlsimport_page_block_featured',
			'args'   => array(
				// Text, not number: live mode addresses the listing by ListingKey,
				// which can be alphanumeric; stored mode (int)-casts it anyway.
				'id'     => array( 'type' => 'text', 'label' => __( 'Property ID', 'mlsimport' ), 'default' => '' ),
				'design' => array(
					'type'    => 'select',
					'label'   => __( 'Design', 'mlsimport' ),
					'default' => '1',
					'options' => array( '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6' ),
				),
			),
		),
		'map_listings' => array(
			'label'  => __( 'Map with Listings', 'mlsimport' ),
			'render' => 'mlsimport_page_block_map',
			'args'   => $map_args,
		),
		'search_form' => array(
			'label'  => __( 'Search Form', 'mlsimport' ),
			'render' => 'mlsimport_page_block_search_form',
			// No "fields per row" here: each row carries its own Width on the shared
			// 12-column grid, which is strictly more expressive. The listings grid and
			// half-map forms still use fields_per_row — they have no per-field settings.
			'args'   => array(
				// A heading above the fields. Empty means no heading at all, so one
				// control does the work of a show/hide switcher plus a text box.
				'title'        => array( 'type' => 'text', 'label' => __( 'Title (leave empty for none)', 'mlsimport' ), 'default' => '' ),
				'results_url'  => array( 'type' => 'text', 'label' => __( 'Results page URL', 'mlsimport' ), 'default' => '' ),
				'hide_labels'  => array( 'type' => 'toggle', 'label' => __( 'Hide labels (use placeholders)', 'mlsimport' ), 'default' => '' ),
				'fields'       => $search_rows,
				'button_text'  => array( 'type' => 'text', 'label' => __( 'Button text', 'mlsimport' ), 'default' => '' ),
				'button_color' => array( 'type' => 'color', 'label' => __( 'Button color', 'mlsimport' ), 'default' => '' ),
				'button_size'  => array(
					'type'    => 'select',
					'label'   => __( 'Button size', 'mlsimport' ),
					'default' => 'medium',
					'options' => $size_options,
				),
				'button_icon'  => array( 'type' => 'media', 'label' => __( 'Button icon', 'mlsimport' ), 'default' => '' ),
				// Defaults to a third, so out of the box the button sits INLINE as the
				// last cell of a row rather than claiming a full row of its own.
				'button_width' => array_merge( $width_field, array( 'label' => __( 'Button width', 'mlsimport' ), 'default' => 'third' ) ),
			),
		),
		'contact_form' => array(
			'label'  => __( 'Contact Form', 'mlsimport' ),
			'render' => 'mlsimport_page_block_contact_form',
			// Same control vocabulary as the Search Form above — title, hide_labels and
			// the button_* set mean the same thing on both, so a site builder learns one
			// form and knows the other.
			'args'   => array(
				'title'        => array( 'type' => 'text', 'label' => __( 'Title (leave empty for none)', 'mlsimport' ), 'default' => '' ),
				'hide_labels'  => array( 'type' => 'toggle', 'label' => __( 'Hide labels (use placeholders)', 'mlsimport' ), 'default' => '' ),
				'fields'       => $contact_rows,
				// Field height/typography preset. Three steps rather than WpResidence's
				// five: the Style tab's Padding and Typography controls cover anything
				// between, so extra presets would just be two ways to say one thing.
				'input_size'   => array(
					'type'    => 'select',
					'label'   => __( 'Field size', 'mlsimport' ),
					'default' => 'medium',
					'options' => $size_options,
				),
				// The GDPR/consent checkbox. Its wording is NOT repeated here: it comes
				// from the site-wide Consent label / Terms link text settings, the same
				// source the property lead forms use, so one edit changes every form.
				'show_consent' => array( 'type' => 'toggle', 'label' => __( 'Show consent checkbox', 'mlsimport' ), 'default' => '' ),
				'button_text'  => array( 'type' => 'text', 'label' => __( 'Button text', 'mlsimport' ), 'default' => '' ),
				'button_color' => array( 'type' => 'color', 'label' => __( 'Button color', 'mlsimport' ), 'default' => '' ),
				'button_size'  => array(
					'type'    => 'select',
					'label'   => __( 'Button size', 'mlsimport' ),
					'default' => 'medium',
					'options' => $size_options,
				),
				'button_width' => array_merge( $width_field, array( 'label' => __( 'Button width', 'mlsimport' ), 'default' => '' ) ),
				// Defaults to Left, not WpResidence's Justified: the button has always sat
				// left-aligned at its natural width here, and defaulting to Justified
				// would silently turn it into a full-width bar on every existing page.
				'button_align' => array(
					'type'    => 'select',
					'label'   => __( 'Button alignment', 'mlsimport' ),
					'default' => 'start',
					'options' => array(
						'start'   => __( 'Left', 'mlsimport' ),
						'center'  => __( 'Center', 'mlsimport' ),
						'end'     => __( 'Right', 'mlsimport' ),
						'stretch' => __( 'Justified', 'mlsimport' ),
					),
				),
			),
			'assets' => $lead,
		),
		'half_map' => array(
			'label'  => __( 'Half Map', 'mlsimport' ),
			'render' => 'mlsimport_page_block_half_map',
			'args'   => $half_map_args,
			'assets' => array( 'mlsimport-half-map' ),
		),
	);

	// Push each built-in definition into the shared registry.
	foreach ( $blocks as $slug => $def ) {
		mlsimport_register_page_block( $slug, $def );
	}
}

/**
 * Internal: the by-reference registry store.
 *
 * @return array
 */
function &mlsimport_page_block_registry(): array {
	// One process-wide store, returned by reference so callers can mutate it.
	static $registry = array();
	return $registry;
}
