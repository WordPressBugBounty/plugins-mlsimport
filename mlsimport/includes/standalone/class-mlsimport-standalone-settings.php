<?php
/**
 * Standalone (theme_id 990) settings store + accessor.
 *
 * One wp_option (mlsimport_standalone_options) holds the standalone front-end
 * choices (map settings, lead recipient). Read everywhere via
 * mlsimport_standalone_option(); the admin UI lives in the plugin settings page,
 * gated to mode 990. See docs/adr/0005 and the build plan, decision 10.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The option key that stores standalone front-end settings.
 */
const MLSIMPORT_STANDALONE_OPTION = 'mlsimport_standalone_options';

/**
 * The field registry — the single source of truth for every standalone setting.
 *
 * One entry per key: type (text|email|number|textarea|select|color|int|sections),
 * default, and for selects an ordered value => label options map. Presentation
 * lives here too — 'label', 'section' (the tab id, see
 * mlsimport_standalone_settings_sections()), and for selects the option labels —
 * so both the settings app and the Customizer render from this ONE definition via
 * mlsimport_standalone_settings_schema(). A field with no 'section' is not shown
 * on either surface; such fields are flagged 'hidden' => true so the
 * schema completeness guard treats their absence as deliberate. Defaults, the REST
 * schema and the save sanitizer are all derived from this, so a new field is added
 * in exactly one place.
 *
 * @return array<string,array<string,mixed>>
 */
function mlsimport_standalone_field_registry(): array {
	return array(
		// General.
		// The URL base the property archive and single permalinks sit on. Default
		// 'listing' rather than 'properties', which collides with nearly every
		// real-estate theme's own CPT and with hand-made pages (#206); settable so
		// a site that collides with even that can move off it. Read (and
		// normalised) by Mlsimport_Standalone_Cpt::property_slug().
		'property_url_slug'      => array( 'type' => 'text', 'default' => 'listing', 'label' => __( 'Property URL base', 'mlsimport' ), 'section' => 'general' ),
		'properties_per_page'    => array( 'type' => 'number', 'default' => '12', 'label' => __( 'Properties per page', 'mlsimport' ), 'section' => 'general' ),
		'cards_per_row'          => array( 'type' => 'select', 'default' => '3', 'options' => array( '2' => __( '2 per row', 'mlsimport' ), '3' => __( '3 per row', 'mlsimport' ), '4' => __( '4 per row', 'mlsimport' ) ), 'label' => __( 'Cards per row', 'mlsimport' ), 'section' => 'general' ),
		'order_by'               => array(
			'type'    => 'select',
			'default' => 'default',
			'options' => array(
				'default'       => __( 'Default', 'mlsimport' ),
				'price_high'    => __( 'Price High to Low', 'mlsimport' ),
				'price_low'     => __( 'Price Low to High', 'mlsimport' ),
				'newest'        => __( 'Newest first', 'mlsimport' ),
				'oldest'        => __( 'Oldest first', 'mlsimport' ),
				'newest_edited' => __( 'Newest Edited', 'mlsimport' ),
				'oldest_edited' => __( 'Oldest Edited', 'mlsimport' ),
				'beds_high'     => __( 'Bedrooms High to Low', 'mlsimport' ),
				'beds_low'      => __( 'Bedrooms Low to High', 'mlsimport' ),
				'baths_high'    => __( 'Bathrooms High to Low', 'mlsimport' ),
				'baths_low'     => __( 'Bathrooms Low to High', 'mlsimport' ),
			),
			'label'   => __( 'Order by', 'mlsimport' ),
			'section' => 'general',
		),
		// Taxonomy archive search filters — a per-filter on/off toggle list for the
		// search bar on the taxonomy/CPT archive pages. Every searchable filter is
		// offered; only Status, City and Property Type are on by default. The active
		// list becomes render_grid's search_fields (templates/archive-mlsimport-property.php).
		'archive_search_fields'  => array( 'type' => 'sections', 'catalog' => 'mlsimport_standalone_archive_filters_catalog', 'default' => mlsimport_standalone_archive_filters_default(), 'label' => __( 'Archive search filters', 'mlsimport' ), 'section' => 'taxonomy_filters' ),

		// Social & Contact.
		'company_name'           => array( 'type' => 'text', 'default' => '', 'label' => __( 'Company name', 'mlsimport' ), 'section' => 'social' ),
		'company_phone'          => array( 'type' => 'text', 'default' => '', 'label' => __( 'Company phone', 'mlsimport' ), 'section' => 'social' ),
		'lead_recipient'         => array( 'type' => 'email', 'default' => '', 'label' => __( 'Email', 'mlsimport' ), 'section' => 'social' ), // Company / fallback lead email.
		'contact_form_recipients' => array( 'type' => 'text', 'default' => '', 'label' => __( 'Contact form recipients', 'mlsimport' ), 'section' => 'social' ), // Page-block contact form recipients (comma-separated emails).
		'consent_label'          => array( 'type' => 'textarea', 'default' => '', 'label' => __( 'Text for the checkbox label', 'mlsimport' ), 'section' => 'social' ),
		'terms_link_text'        => array( 'type' => 'text', 'default' => 'Privacy Policy', 'label' => __( 'Text for terms link', 'mlsimport' ), 'section' => 'social' ),
		'show_looking_dropdown'  => array( 'type' => 'select', 'default' => 'yes', 'options' => array( 'yes' => __( 'Yes', 'mlsimport' ), 'no' => __( 'No', 'mlsimport' ) ), 'label' => __( "Show 'What are you looking to do?' dropdown on contact forms?", 'mlsimport' ), 'section' => 'social' ),
		'looking_options'        => array( 'type' => 'text', 'default' => 'Buy, Rent, Sell', 'label' => __( 'Dropdown options (comma-separated)', 'mlsimport' ), 'section' => 'social' ),

		// Maps.
		'mapbox_api_key'         => array( 'type' => 'text', 'default' => '', 'label' => __( 'MapBox API KEY', 'mlsimport' ), 'section' => 'maps' ),
		'map_start_lat'          => array( 'type' => 'number', 'default' => '', 'label' => __( 'Starting Point Latitude', 'mlsimport' ), 'section' => 'maps' ),
		'map_start_lng'          => array( 'type' => 'number', 'default' => '', 'label' => __( 'Starting Point Longitude', 'mlsimport' ), 'section' => 'maps' ),
		'map_zoom'               => array( 'type' => 'number', 'default' => '11', 'label' => __( 'Default Maps zoom (1 to 20)', 'mlsimport' ), 'section' => 'maps' ),
		'map_pin_cluster'        => array( 'type' => 'select', 'default' => 'yes', 'options' => array( 'yes' => __( 'Yes', 'mlsimport' ), 'no' => __( 'No', 'mlsimport' ) ), 'label' => __( 'Use the Pin Cluster on the maps', 'mlsimport' ), 'section' => 'maps' ),
		'map_cluster_max_zoom'   => array( 'type' => 'number', 'default' => '11', 'label' => __( 'Maximum zoom level for cluster to appear', 'mlsimport' ), 'section' => 'maps' ),
		// Deferred (issue #185 follow-up): the geolocation circle isn't read back yet, so
		// it is hidden from both surfaces. No 'section' + 'hidden' keeps the schema guard
		// happy; a saved value is preserved untouched until the feature ships.
		'map_geolocation_circle' => array( 'type' => 'number', 'default' => '', 'hidden' => true ),

		// Property Page.
		'details_columns'        => array( 'type' => 'select', 'default' => '3', 'options' => array( '2' => __( '2 Columns', 'mlsimport' ), '3' => __( '3 Columns', 'mlsimport' ) ), 'label' => __( 'Details Columns', 'mlsimport' ), 'section' => 'property_page' ), // Columns in every field section's details grid.
		'media_section_type'     => array( 'type' => 'select', 'default' => 'classic', 'options' => array( 'classic' => __( 'Classic Slider', 'mlsimport' ), 'vertical' => __( 'Vertical Slider', 'mlsimport' ), 'v4' => __( 'Slider v4', 'mlsimport' ), 'multi' => __( 'Multi Image Slider', 'mlsimport' ), 'masonry1' => __( 'Masonry Gallery v1', 'mlsimport' ), 'masonry2' => __( 'Masonry Gallery v2', 'mlsimport' ) ), 'label' => __( 'Media Section Type (property images & video)', 'mlsimport' ), 'section' => 'property_page' ),
		'card_style'             => array( 'type' => 'select', 'default' => 'v1', 'options' => array( 'v1' => __( 'V1 — Standard', 'mlsimport' ), 'v2' => __( 'V2 — Horizontal', 'mlsimport' ), 'v3' => __( 'V3 — Photo overlay', 'mlsimport' ) ), 'label' => __( 'Property card style', 'mlsimport' ), 'section' => 'property_card' ), // Property card design for every listing grid.
		'property_sections'      => array( 'type' => 'sections', 'catalog' => 'mlsimport_standalone_section_catalog', 'default' => mlsimport_standalone_sections_default(), 'label' => __( 'Arrange Sections', 'mlsimport' ), 'section' => 'property_page' ),
		// Similar Listings section on the property page: how many siblings to pull, and
		// how many cards sit on one row (the grid drops to 2 then 1 on narrow screens).
		'similar_count'          => array( 'type' => 'number', 'default' => '3', 'label' => __( 'Number of similar listings', 'mlsimport' ), 'section' => 'property_page' ),
		'similar_per_row'        => array( 'type' => 'select', 'default' => '3', 'options' => array( '2' => __( '2 per row', 'mlsimport' ), '3' => __( '3 per row', 'mlsimport' ), '4' => __( '4 per row', 'mlsimport' ) ), 'label' => __( 'Similar listings per row', 'mlsimport' ), 'section' => 'property_page' ),
		'overview_fields'        => array( 'type' => 'sections', 'catalog' => 'mlsimport_standalone_overview_fields_catalog', 'default' => mlsimport_standalone_overview_fields_default(), 'label' => __( 'Arrange Fields', 'mlsimport' ), 'section' => 'property_page' ), // Which tiles the Overview section shows, in order.
		// Comma-separated time slots for the "Schedule a Tour" picker. The default is
		// blank on purpose: mlsimport_standalone_option() cannot tell a cleared field
		// from an untouched one, so any non-blank default here would make "leave it
		// empty to hide the picker" impossible.
		'tour_times'             => array( 'type' => 'text', 'default' => '', 'label' => __( 'Preferred tour times', 'mlsimport' ), 'section' => 'property_page' ),

		// MLS attribution — the logo + the disclaimer every listing must carry. The
		// wording is dictated by the MLS, so it is written once here rather than per
		// property; %mls_id%, %year% and the agent/office contact tokens resolve per listing
		// (mlsimport_property_attribution_text). mls_logo_id sits with these (the
		// settings page groups both under the "MLS Attribution" sub-tab, logo first).
		'mls_logo_id'            => array( 'type' => 'int', 'default' => 0, 'label' => __( 'MLS logo', 'mlsimport' ), 'section' => 'property_page' ),
		// Blank by default for the same reason as tour_times: a non-blank default
		// would print on every property with no way for the admin to switch it off.
		'attribution_text'       => array( 'type' => 'html', 'default' => '', 'label' => __( 'Extra Disclaimer', 'mlsimport' ), 'section' => 'property_page' ),

		// Agent.
		'agent_listings_per_page' => array( 'type' => 'number', 'default' => '12', 'label' => __( 'No. of listings per page', 'mlsimport' ), 'section' => 'agent' ), // Listings shown per page on a single agent profile.
		'agent_listings_per_row'  => array( 'type' => 'select', 'default' => '3', 'options' => array( '2' => __( '2 per row', 'mlsimport' ), '3' => __( '3 per row', 'mlsimport' ), '4' => __( '4 per row', 'mlsimport' ) ), 'label' => __( 'No. of listings per row', 'mlsimport' ), 'section' => 'agent' ), // Cards per row in the agent profile's listings grid.
		'agent_sections'          => array( 'type' => 'sections', 'catalog' => 'mlsimport_standalone_agent_section_catalog', 'default' => mlsimport_standalone_agent_sections_default(), 'label' => __( 'Arrange Sections', 'mlsimport' ), 'section' => 'agent' ), // Agent-profile content-column section order.

		// Colors (brand accent).
		'brand_color'            => array( 'type' => 'color', 'default' => '', 'label' => __( 'Main Color', 'mlsimport' ), 'section' => 'colors' ),
	);
}

/**
 * The standalone settings SCHEMA — the registry regrouped into ordered sections,
 * the single definition both the React settings page and the Customizer render
 * from. Each field carries its live-preview transport (colour previews instantly
 * via postMessage; everything else reloads the preview). Adding a field to the
 * registry surfaces it here — and therefore on both surfaces — automatically.
 *
 * @return array<int,array{id:string,title:string,fields:array<int,array<string,mixed>>}>
 */
function mlsimport_standalone_settings_schema(): array {
	$titles   = mlsimport_standalone_settings_sections();
	$sections = array();
	// Seed in canonical order so both surfaces group the fields identically,
	// regardless of the order fields happen to sit in the registry.
	foreach ( $titles as $section_id => $title ) {
		$sections[ $section_id ] = array(
			'id'     => $section_id,
			'title'  => $title,
			'fields' => array(),
		);
	}

	// Place each registry field into its section, in registry order.
	foreach ( mlsimport_standalone_field_registry() as $key => $field ) {
		// A field with no section (the hidden map fields) never renders on a surface.
		if ( empty( $field['section'] ) ) {
			continue;
		}
		$section_id = (string) $field['section'];
		// A field referencing a section not in the title map still gets a bucket.
		if ( ! isset( $sections[ $section_id ] ) ) {
			$sections[ $section_id ] = array(
				'id'     => $section_id,
				'title'  => $section_id,
				'fields' => array(),
			);
		}
		// Flatten the registry entry into the schema field shape both surfaces read;
		// transport is derived from type (color previews live, everything else reloads).
		$sections[ $section_id ]['fields'][] = array(
			'key'       => $key,
			'type'      => $field['type'],
			'label'     => isset( $field['label'] ) ? $field['label'] : $key,
			'section'   => $section_id,
			'options'   => isset( $field['options'] ) ? $field['options'] : null,
			'catalog'   => isset( $field['catalog'] ) ? $field['catalog'] : null,
			'transport' => 'color' === $field['type'] ? 'postMessage' : 'refresh',
		);
	}

	// Drop any seeded section that ended up with no fields.
	return array_values( array_filter( $sections, function ( $section ) {
		return ! empty( $section['fields'] );
	} ) );
}

/**
 * The section id => human title map, in the order both the settings page and the
 * Customizer present them. This is the one place section titles/order are defined;
 * a field's 'section' key must reference an id listed here.
 *
 * @return array<string,string>
 */
function mlsimport_standalone_settings_sections(): array {
	return array(
		'general'          => __( 'General', 'mlsimport' ),
		'taxonomy_filters' => __( 'Category page filters', 'mlsimport' ),
		'social'           => __( 'Social & Contact', 'mlsimport' ),
		'maps'             => __( 'Maps', 'mlsimport' ),
		'property_page'    => __( 'Property Page', 'mlsimport' ),
		'property_card'    => __( 'Property Card', 'mlsimport' ),
		'agent'            => __( 'Agent', 'mlsimport' ),
		'colors'           => __( 'Colors', 'mlsimport' ),
	);
}

/**
 * The Property Page sub-tab id => title map (the settings page groups that tab's
 * fields into these sub-screens). Order here is the sub-tab order.
 *
 * @return array<string,string>
 */
function mlsimport_standalone_settings_subtabs(): array {
	return array(
		'pp_general'     => __( 'General', 'mlsimport' ),
		'pp_layout'      => __( 'Property Page Layout', 'mlsimport' ),
		'pp_attribution' => __( 'MLS Attribution', 'mlsimport' ),
		'pp_tour'        => __( 'Tour Details', 'mlsimport' ),
		'pp_overview'    => __( 'Overview', 'mlsimport' ),
		'pp_similar'     => __( 'Similar Listings', 'mlsimport' ),
	);
}

/**
 * Settings-page-only presentation, keyed by field key. The registry owns each
 * field's existence/type/default/label/section/options; this holds the extras the
 * React settings page needs that the Customizer does not: help text, a control
 * override (when the widget differs from the plain type — buttons/yesno/toggles/
 * media), the Property Page sub-tab a field sits in, textarea rows, and a toggle
 * list's default-on set. A field absent here still renders (no help, control
 * derived from its type, no sub-tab) — so a simple new field needs only a registry
 * entry. Both the settings app and the Customizer are still generated from the one
 * registry; this is the settings app's view of it.
 *
 * @return array<string,array<string,mixed>>
 */
function mlsimport_standalone_settings_ui(): array {
	return array(
		'property_url_slug'     => array( 'help' => __( 'The URL segment your listings live under — e.g. listing gives yoursite.com/listing/. Change it only if another page or plugin already uses that word; existing listing links will break when you do.', 'mlsimport' ) ),
		'properties_per_page'   => array( 'help' => __( 'How many listings show per page on the taxonomy and property archive pages. Does not affect page-builder blocks, which set their own per-page.', 'mlsimport' ) ),
		'cards_per_row'         => array( 'control' => 'buttons', 'help' => __( 'How many property cards sit on one row of the taxonomy and property archive pages. Drops to 2 then 1 automatically on narrow screens.', 'mlsimport' ) ),
		'archive_search_fields' => array( 'control' => 'toggles', 'default_active' => array( 'status', 'city', 'property_type' ), 'help' => __( 'Toggle which filters appear in the search bar on the taxonomy and property archive pages. Status, City and Type are on by default.', 'mlsimport' ) ),
		'company_name'          => array( 'help' => __( 'Shown as the contact name on listings whose agent comes straight from the MLS feed — MLS rules forbid displaying the feed agent\'s own contact details.', 'mlsimport' ) ),
		'company_phone'         => array( 'help' => __( 'Used as the contact phone on listings whose agent comes straight from the MLS feed.', 'mlsimport' ) ),
		'lead_recipient'        => array( 'help' => __( 'Company email — e.g. office@domain.com. Also the fallback lead recipient when a listing has no agent email.', 'mlsimport' ) ),
		'contact_form_recipients' => array( 'help' => __( 'Where the page-builder Contact Form block sends submissions. One or more emails, comma-separated. Leave empty to fall back to the company email above.', 'mlsimport' ) ),
		'consent_label'         => array( 'help' => __( 'Shown next to the marketing-consent checkbox on contact forms.', 'mlsimport' ) ),
		'terms_link_text'       => array( 'help' => __( 'e.g. Privacy Policy.', 'mlsimport' ) ),
		'show_looking_dropdown' => array( 'control' => 'yesno', 'help' => __( 'Displays an optional dropdown field on Agent, Agency, Developer, and Property contact forms.', 'mlsimport' ) ),
		'mapbox_api_key'        => array( 'help' => __( 'Used for tiles when Open Street Maps is enabled. Get a key at https://www.mapbox.com/. If blank, the default OpenStreet server is used (can be slow).', 'mlsimport' ) ),
		'map_start_lat'         => array( 'help' => __( 'Fallback map center — used only when a listings map has no results to show. When there are results the map zooms to fit them and this is ignored. Numbers only (ex: 40.577906).', 'mlsimport' ) ),
		'map_start_lng'         => array( 'help' => __( 'Longitude of the fallback center above. Numbers only (ex: -74.155058).', 'mlsimport' ) ),
		'map_zoom'              => array( 'help' => __( 'Default zoom (1 = world, 20 = street). Sets the single-property map and the fallback zoom for empty listings maps.', 'mlsimport' ) ),
		'map_pin_cluster'       => array( 'control' => 'yesno', 'help' => __( 'If yes, nearby pins are grouped into a numbered cluster.', 'mlsimport' ) ),
		'map_cluster_max_zoom'  => array( 'help' => __( 'Clusters show up to this zoom level; zoom in past it and the listings split into individual pins.', 'mlsimport' ) ),
		'media_section_type'    => array( 'control' => 'buttons', 'subtab' => 'pp_general', 'help' => __( 'Choose how to display the listing images or video.', 'mlsimport' ) ),
		'details_columns'       => array( 'control' => 'buttons', 'subtab' => 'pp_layout', 'help' => __( 'How many columns each details section (Interior, Exterior, Financial…) runs. Collapses automatically on narrow screens.', 'mlsimport' ) ),
		'property_sections'     => array( 'subtab' => 'pp_layout', 'help' => __( 'Drag sections between Enabled and Disabled to choose which appear, and reorder within a list. "Sections as Tabs" and "Sections as Accordion" group the detail sections and Features into one panel — enable one of them and disable the flat sections it holds.', 'mlsimport' ) ),
		'mls_logo_id'           => array( 'control' => 'media', 'subtab' => 'pp_attribution', 'help' => __( "Your MLS's required attribution logo. Shown in the MLS Attribution section and on listing cards.", 'mlsimport' ) ),
		'attribution_text'      => array( 'subtab' => 'pp_attribution', 'rows' => 10, 'help' => __( 'The disclaimer your MLS requires, shown on every property. Use %mls_id% for the listing\'s MLS number and %year% for the current year. You can also use %agent_phone% and %agent_email% for the listing agent the MLS sent, and %office_phone%, %office_email% or %attribution_contact% for the listing office. Each stays empty unless that field is ticked under MLS Import Settings → Listing Details, so tick List Office Phone, List Office Email or Attribution Contact there before using them. Basic HTML (links, bold, paragraphs) is allowed.', 'mlsimport' ) ),
		'tour_times'            => array( 'subtab' => 'pp_tour', 'help' => __( 'Time slots offered in the "Schedule a Tour" picker on the property page. Comma-separated, e.g. 9:00 AM, 11:30 AM, 2:00 PM, 4:30 PM. Leave blank to hide the time picker.', 'mlsimport' ) ),
		'overview_fields'       => array( 'subtab' => 'pp_overview', 'help' => __( 'Drag fields between Enabled and Disabled to choose which appear in the Overview section of the property page, and reorder within a list.', 'mlsimport' ) ),
		'similar_count'         => array( 'subtab' => 'pp_similar', 'help' => __( 'How many similar listings the Similar Listings section pulls in. Default 3.', 'mlsimport' ) ),
		'similar_per_row'       => array( 'control' => 'buttons', 'subtab' => 'pp_similar', 'help' => __( 'How many similar listing cards sit on one row. Drops to 2 then 1 automatically on narrow screens.', 'mlsimport' ) ),
		'card_style'            => array( 'help' => __( 'The card design used in every listing grid (search results, lists, sliders, similar listings).', 'mlsimport' ) ),
		'agent_listings_per_page' => array( 'help' => __( "Listings shown per page on a single agent's profile, with pagination. Default 12.", 'mlsimport' ) ),
		'agent_listings_per_row' => array( 'control' => 'buttons', 'help' => __( "How many listing cards sit on one row of an agent's profile. Drops to 2 then 1 automatically on narrow screens.", 'mlsimport' ) ),
		'agent_sections'        => array( 'help' => __( 'Drag sections between Enabled and Disabled to choose which appear on the agent profile, and reorder within a list.', 'mlsimport' ) ),
		'brand_color'           => array( 'help' => __( 'Main accent / brand colour for the front end.', 'mlsimport' ) ),
	);
}

/**
 * The settings-page field tree (tabs → optional sub-tabs → fields), generated from
 * the ONE registry + the settings UI map. Localised to the React app as
 * window.mlsimportFields, which renders from it — so a field added to the registry
 * appears on the settings page (and, via the schema, the Customizer) automatically.
 * The shape matches what the React app's generic renderer expects: each field has
 * key, label, help, type (the control), options [{label,value}] for select/buttons,
 * catalog id + defaultActive for sections/toggles, and rows for a textarea.
 *
 * @return array<int,array<string,mixed>>
 */
function mlsimport_standalone_settings_app_config(): array {
	$ui          = mlsimport_standalone_settings_ui();
	$sections    = mlsimport_standalone_settings_sections();
	$subtabs     = mlsimport_standalone_settings_subtabs();
	$catalog_map = array(
		'mlsimport_standalone_section_catalog'         => '',
		'mlsimport_standalone_overview_fields_catalog' => 'overview',
		'mlsimport_standalone_agent_section_catalog'   => 'agent',
		'mlsimport_standalone_archive_filters_catalog' => 'archive_filters',
	);

	// Group registry fields by section, in registry order, skipping hidden.
	$by_section = array();
	foreach ( mlsimport_standalone_field_registry() as $key => $field ) {
		if ( ! empty( $field['hidden'] ) || empty( $field['section'] ) ) {
			continue;
		}
		$by_section[ $field['section'] ][ $key ] = $field;
	}

	// Build one tab per section, in section-map order.
	$tabs = array();
	foreach ( $sections as $sec_id => $sec_title ) {
		// Skip a section with no visible fields.
		if ( empty( $by_section[ $sec_id ] ) ) {
			continue;
		}

		// Does any field in this section declare a Property Page sub-tab?
		$has_subtab = false;
		foreach ( $by_section[ $sec_id ] as $k => $f ) {
			if ( ! empty( $ui[ $k ]['subtab'] ) ) {
				$has_subtab = true;
				break;
			}
		}

		// Simple section: a flat field list, no sub-tabs.
		if ( ! $has_subtab ) {
			$fields = array();
			foreach ( $by_section[ $sec_id ] as $k => $f ) {
				$fields[] = mlsimport_standalone_settings_field_json( $k, $f, $ui, $catalog_map );
			}
			$tabs[] = array( 'name' => $sec_id, 'title' => $sec_title, 'fields' => $fields );
			continue;
		}

		// Bucket the section's fields by sub-tab, then emit sub-tabs in map order.
		$buckets = array();
		foreach ( $by_section[ $sec_id ] as $k => $f ) {
			// A field with no declared sub-tab falls into the first sub-tab.
			$st                = ! empty( $ui[ $k ]['subtab'] ) ? $ui[ $k ]['subtab'] : array_key_first( $subtabs );
			$buckets[ $st ][]  = mlsimport_standalone_settings_field_json( $k, $f, $ui, $catalog_map );
		}
		// Emit sub-tabs in the sub-tab map order, skipping empty ones.
		$sub = array();
		foreach ( $subtabs as $st_id => $st_title ) {
			if ( empty( $buckets[ $st_id ] ) ) {
				continue;
			}
			$sub[] = array( 'name' => $sec_id . '__' . $st_id, 'title' => $st_title, 'fields' => $buckets[ $st_id ] );
		}
		$tabs[] = array( 'name' => $sec_id, 'title' => $sec_title, 'subtabs' => $sub );
	}

	return $tabs;
}

/**
 * Shape one registry field for the settings app (see the app-config builder).
 *
 * @param string $key         Field key.
 * @param array  $field       Registry entry.
 * @param array  $ui          Settings UI map.
 * @param array  $catalog_map PHP catalog callable => React catalog id.
 * @return array<string,mixed>
 */
function mlsimport_standalone_settings_field_json( string $key, array $field, array $ui, array $catalog_map ): array {
	// The settings-page-only presentation for this key (help/control/subtab/rows).
	$u = isset( $ui[ $key ] ) ? $ui[ $key ] : array();

	// Widget to render with: an explicit UI override, else derived from the type.
	$control = isset( $u['control'] ) ? $u['control'] : null;
	if ( ! $control ) {
		switch ( $field['type'] ) {
			case 'html':
				// Rich HTML fields render in a textarea control.
				$control = 'textarea';
				break;
			case 'int':
				// The one int field (mls_logo_id) is an attachment picker.
				$control = 'media';
				break;
			default: // text | number | email | textarea | select | color | sections.
				$control = $field['type'];
				break;
		}
	}

	// The base field shape the React renderer expects.
	$out = array(
		'key'   => $key,
		'label' => isset( $field['label'] ) ? $field['label'] : $key,
		'type'  => $control,
	);
	// Optional help text under the control.
	if ( ! empty( $u['help'] ) ) {
		$out['help'] = $u['help'];
	}
	// select/buttons carry a [{label,value}] list, rebuilt from the value=>label map.
	if ( in_array( $control, array( 'select', 'buttons' ), true ) && ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
		$opts = array();
		foreach ( $field['options'] as $value => $label ) {
			$opts[] = array( 'label' => $label, 'value' => (string) $value );
		}
		$out['options'] = $opts;
	}
	// sections/toggles carry a React catalog id + the default-on set, and the
	// slugs the field's default keeps disabled (so an unmentioned catalog entry
	// is shown where the PHP sanitizer will put it on save).
	if ( in_array( $control, array( 'sections', 'toggles' ), true ) ) {
		$out['catalog'] = ( isset( $field['catalog'] ) && isset( $catalog_map[ $field['catalog'] ] ) ) ? $catalog_map[ $field['catalog'] ] : '';
		if ( ! empty( $u['default_active'] ) ) {
			$out['defaultActive'] = $u['default_active'];
		}
		if ( ! empty( $field['default']['inactive'] ) ) {
			$out['defaultInactive'] = array_values( (array) $field['default']['inactive'] );
		}
	}
	// A textarea can request a row count.
	if ( ! empty( $u['rows'] ) ) {
		$out['rows'] = $u['rows'];
	}

	return $out;
}

/**
 * The single-property section catalog (slug => label) used by the "Arrange
 * Sections" control. Sourced from the property section registry so it always
 * matches the sections the front end can actually render.
 *
 * @return array<string,string>
 */
function mlsimport_standalone_section_catalog(): array {
	if ( function_exists( 'mlsimport_register_builtin_property_sections' ) ) {
		mlsimport_register_builtin_property_sections();
	}
	// The sections offered in the "Arrange Sections" layout control — the prototype
	// single-page set. The gallery/slider/masonry variants are represented by the
	// single 'property_gallery' slot (the look is chosen in "Media Section Type").
	// The two containers (Tabs / Accordion) group the field sections + Features;
	// they are offered here but start disabled (see the default below), because
	// enabling one alongside the flat field sections shows the same data twice.
	$allowed = array(
		'breadcrumbs', 'property_gallery', 'title_bar', 'subnav', 'overview', 'description',
		'virtual_tour', 'map',
		'interior', 'exterior', 'structure', 'utilities', 'financial',
		'schools', 'location', 'listing_info', 'other',
		'features', 'tabs', 'accordion',
		'calculator', 'agent_card', 'similar', 'attribution',
		'mobile_agent_bar',
	);
	$registry = function_exists( 'mlsimport_get_property_sections' ) ? mlsimport_get_property_sections() : array();
	$out      = array();
	foreach ( $allowed as $slug ) {
		if ( isset( $registry[ $slug ] ) ) {
			$out[ $slug ] = isset( $registry[ $slug ]['label'] ) ? (string) $registry[ $slug ]['label'] : $slug;
		}
	}
	return $out;
}

/**
 * Default sections order: every known section enabled, in catalog order, except
 * the Tabs and Accordion containers, which start disabled — they re-group the
 * field sections + Features, so a page that shows both the flat sections and a
 * container repeats the same data (#311). The operator enables a container by
 * dragging it to Enabled (and, normally, the flat sections it holds to Disabled).
 *
 * @return array{active:string[],inactive:string[]}
 */
function mlsimport_standalone_sections_default(): array {
	$off = array( 'tabs', 'accordion' );
	$all = array_keys( mlsimport_standalone_section_catalog() );
	return array(
		'active'   => array_values( array_diff( $all, $off ) ),
		'inactive' => array_values( array_intersect( $all, $off ) ),
	);
}

/**
 * The section slugs to render on the single property page, in order.
 *
 * Resolves the saved "Arrange Sections" layout, falling back to the default when
 * it is empty. A section added to the catalog AFTER the user last saved their
 * layout appears in neither their active nor their inactive list; it is enabled
 * by default and placed next to the catalog neighbour it follows, so a new
 * section reaches existing installs in the right place instead of staying
 * invisible until they happen to re-save their design settings. A section the
 * user has explicitly disabled stays disabled, and a newcomer the default keeps
 * disabled (the Tabs/Accordion containers) stays off until the user enables it.
 *
 * @return string[]
 */
function mlsimport_standalone_active_sections(): array {
	$layout = mlsimport_standalone_option( 'property_sections' );
	if ( ! is_array( $layout ) || empty( $layout['active'] ) ) {
		return mlsimport_standalone_sections_default()['active'];
	}

	$active   = array_values( (array) $layout['active'] );
	$inactive = isset( $layout['inactive'] ) ? (array) $layout['inactive'] : array();
	$catalog  = array_keys( mlsimport_standalone_section_catalog() );
	// Newcomers the default keeps disabled are never auto-enabled.
	$off      = mlsimport_standalone_sections_default()['inactive'];

	foreach ( $catalog as $i => $slug ) {
		if ( in_array( $slug, $active, true ) || in_array( $slug, $inactive, true ) || in_array( $slug, $off, true ) ) {
			continue;
		}

		// Walk back through the catalog for the nearest predecessor the user still
		// has enabled, and slot the newcomer in right after it.
		$pos = null;
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			$at = array_search( $catalog[ $j ], $active, true );
			if ( false !== $at ) {
				$pos = $at + 1;
				break;
			}
		}

		if ( null === $pos ) {
			array_unshift( $active, $slug );
		} else {
			array_splice( $active, $pos, 0, array( $slug ) );
		}
	}

	return $active;
}

/**
 * The Overview tile catalog (slug => label) used by the Overview "Arrange Fields"
 * control. Sourced from the property Overview section itself, so the control
 * offers exactly the tiles the front end knows how to draw.
 *
 * @return array<string,string>
 */
function mlsimport_standalone_overview_fields_catalog(): array {
	if ( ! function_exists( 'mlsimport_property_overview_fields' ) ) {
		return array();
	}
	$out = array();
	foreach ( mlsimport_property_overview_fields() as $slug => $field ) {
		$out[ $slug ] = $field[1];
	}
	return $out;
}

/**
 * Default Overview tiles: every tile enabled, in catalog order, none disabled.
 *
 * @return array{active:string[],inactive:string[]}
 */
function mlsimport_standalone_overview_fields_default(): array {
	return array(
		'active'   => array_keys( mlsimport_standalone_overview_fields_catalog() ),
		'inactive' => array(),
	);
}

/**
 * The Overview tile slugs to render, in order. Resolves the saved arrangement,
 * falling back to every tile when the setting is empty. A tile added to the
 * catalog after the user last saved is in neither list; it is enabled and
 * appended, so a new tile reaches existing installs instead of staying invisible
 * until they happen to re-save. A tile the user disabled stays disabled.
 *
 * @return string[]
 */
function mlsimport_standalone_active_overview_fields(): array {
	$layout = mlsimport_standalone_option( 'overview_fields' );
	if ( ! is_array( $layout ) || empty( $layout['active'] ) ) {
		$active = mlsimport_standalone_overview_fields_default()['active'];
	} else {
		$active   = array_values( (array) $layout['active'] );
		$inactive = isset( $layout['inactive'] ) ? (array) $layout['inactive'] : array();
		foreach ( array_keys( mlsimport_standalone_overview_fields_catalog() ) as $slug ) {
			if ( ! in_array( $slug, $active, true ) && ! in_array( $slug, $inactive, true ) ) {
				$active[] = $slug;
			}
		}
	}

	/** Filter the ordered Overview tile slugs. @since 6.4 */
	return (array) apply_filters( 'mlsimport_property_overview_active_fields', $active );
}

/**
 * The single-agent section catalog (slug => label) used by the agent "Arrange
 * Sections" control. These are the reorderable content-column sections; the hero
 * + sub-nav (locked at the top) and the contact rail (sidebar) are fixed and not
 * listed here. Mirrors mlsimport_render_agent_section().
 *
 * @return array<string,string>
 */
function mlsimport_standalone_agent_section_catalog(): array {
	return array(
		'about'       => __( 'About', 'mlsimport' ),
		'listings'    => __( 'Listings', 'mlsimport' ),
		'credentials' => __( 'Credentials', 'mlsimport' ),
	);
}

/**
 * Default agent section order: every agent section enabled, none disabled.
 *
 * @return array{active:string[],inactive:string[]}
 */
function mlsimport_standalone_agent_sections_default(): array {
	return array(
		'active'   => array_keys( mlsimport_standalone_agent_section_catalog() ),
		'inactive' => array(),
	);
}

/**
 * Resolve the ordered active agent content-column sections: the saved "Arrange
 * Sections" order when set, else the default.
 *
 * Catalog sections a saved layout predates — neither active nor explicitly
 * disabled (About was re-added to the catalog after some sites already saved an
 * order) — are inserted at their catalog position, so a newly added section
 * surfaces without the operator re-saving, while their custom order for the
 * sections they did arrange is preserved.
 *
 * @return string[] Active section slugs, in render order.
 */
function mlsimport_standalone_agent_active_sections(): array {
	$layout   = mlsimport_standalone_option( 'agent_sections' );
	$active   = ( is_array( $layout ) && ! empty( $layout['active'] ) ) ? array_values( (array) $layout['active'] ) : mlsimport_standalone_agent_sections_default()['active'];
	$inactive = ( is_array( $layout ) && ! empty( $layout['inactive'] ) ) ? (array) $layout['inactive'] : array();
	$catalog  = array_keys( mlsimport_standalone_agent_section_catalog() );

	foreach ( $catalog as $ci => $slug ) {
		// Already placed or deliberately disabled — leave it be.
		if ( in_array( $slug, $active, true ) || in_array( $slug, $inactive, true ) ) {
			continue;
		}
		// Insert before the first already-active section that follows this one in
		// the catalog (so About lands ahead of Listings); else append.
		$pos = count( $active );
		foreach ( array_slice( $catalog, $ci + 1 ) as $after ) {
			$idx = array_search( $after, $active, true );
			if ( false !== $idx ) {
				$pos = (int) $idx;
				break;
			}
		}
		array_splice( $active, $pos, 0, array( $slug ) );
	}
	return $active;
}

/**
 * The archive search-filter catalog (field key => label): the search form's
 * toggleable fields in render order — the keyword box, every searchable catalog
 * field, then the sort control. Mirrors search-form.php and the block's
 * per-field on/off list, so the settings control matches what the form renders.
 *
 * @return array<string,string>
 */
function mlsimport_standalone_archive_filters_catalog(): array {
	$labels = array( 'keywords' => __( 'Keywords', 'mlsimport' ) );
	if ( class_exists( 'Mlsimport_Page_Block_Search_Fields' ) ) {
		$labels = array_merge( $labels, Mlsimport_Page_Block_Search_Fields::labels() );
	}
	$labels['sort'] = __( 'Sort by', 'mlsimport' );
	return $labels;
}

/**
 * Default archive filters: Status, City and Property Type on, every other filter
 * off. Any filter the catalog gains later defaults on (the sanitizer enables
 * catalog keys not present in either list).
 *
 * @return array{active:string[],inactive:string[]}
 */
function mlsimport_standalone_archive_filters_default(): array {
	$on  = array( 'status', 'city', 'property_type' );
	$all = array_keys( mlsimport_standalone_archive_filters_catalog() );
	return array(
		'active'   => array_values( array_intersect( $all, $on ) ),
		'inactive' => array_values( array_diff( $all, $on ) ),
	);
}

/**
 * Sanitize an "Arrange Sections" value into { active, inactive } slug lists.
 * Only known slugs survive, each appears once, a slug can't be in both lists,
 * and any known section missing from the input lands where the field's default
 * puts it: appended to active, unless the default keeps it disabled (the
 * Tabs/Accordion containers), in which case it is appended to inactive.
 *
 * @param mixed         $raw              Posted value.
 * @param string[]|null $known            Allowed slugs for this catalog (null = property catalog).
 * @param string[]      $default_inactive Slugs the field's default keeps disabled.
 * @return array{active:string[],inactive:string[]}
 */
function mlsimport_sanitize_sections( $raw, ?array $known = null, array $default_inactive = array() ): array {
	$raw   = is_array( $raw ) ? $raw : array();
	// Default to the property catalog when no explicit allow-list is given.
	$known = null !== $known ? $known : array_keys( mlsimport_standalone_section_catalog() );

	// Keep only known slugs, sanitized and de-duplicated, preserving input order.
	$clean = static function ( $list ) use ( $known ) {
		$out = array();
		foreach ( (array) $list as $slug ) {
			$slug = sanitize_key( $slug );
			if ( in_array( $slug, $known, true ) && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	};

	// active wins any tie: a slug in both lists is dropped from inactive.
	$active   = isset( $raw['active'] ) ? $clean( $raw['active'] ) : array();
	$inactive = array_values( array_diff( isset( $raw['inactive'] ) ? $clean( $raw['inactive'] ) : array(), $active ) );

	// Any known slug the input never mentioned lands where the default puts it.
	foreach ( $known as $slug ) {
		if ( ! in_array( $slug, $active, true ) && ! in_array( $slug, $inactive, true ) ) {
			if ( in_array( $slug, $default_inactive, true ) ) {
				$inactive[] = $slug;
			} else {
				$active[] = $slug;
			}
		}
	}

	return array( 'active' => $active, 'inactive' => $inactive );
}

/**
 * Default values for standalone settings, derived from the registry.
 *
 * @return array
 */
function mlsimport_standalone_option_defaults(): array {
	$defaults = array();
	foreach ( mlsimport_standalone_field_registry() as $key => $field ) {
		$defaults[ $key ] = $field['default'];
	}
	return $defaults;
}

/**
 * Register the standalone option for the WP Settings REST endpoint.
 *
 * The dedicated "Standalone Design" admin page is a React app that reads/writes
 * this option through /wp/v2/settings, so the option must be registered with a
 * show_in_rest schema (derived from the registry). Hooked on init (not
 * admin_init) so the registration is present during REST requests too.
 *
 * @return void
 */
function mlsimport_register_standalone_setting(): void {
	// Build the REST schema's per-key property types from the registry.
	$properties = array();
	foreach ( mlsimport_standalone_field_registry() as $key => $field ) {
		// A 'sections' field is an { active[], inactive[] } object.
		if ( 'sections' === $field['type'] ) {
			$properties[ $key ] = array(
				'type'                 => 'object',
				'properties'           => array(
					'active'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'inactive' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
				'additionalProperties' => false,
			);
			continue;
		}
		// Everything else is an integer (int fields) or a string.
		$properties[ $key ] = array( 'type' => 'int' === $field['type'] ? 'integer' : 'string' );
	}

	register_setting(
		'mlsimport_standalone_options',
		MLSIMPORT_STANDALONE_OPTION,
		array(
			'type'              => 'object',
			'default'           => mlsimport_standalone_option_defaults(),
			'sanitize_callback' => 'mlsimport_sanitize_standalone_options',
			'show_in_rest'      => array(
				'schema' => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'additionalProperties' => false,
				),
			),
		)
	);
}
// Guarded so this file can be required by pure unit tests (no WP runtime).
if ( function_exists( 'add_action' ) ) {
	add_action( 'init', 'mlsimport_register_standalone_setting' );
	// Feed the saved MapBox key into every map surface via the shared tile filter.
	add_filter( 'mlsimport_map_tile_url', 'mlsimport_standalone_map_tile_url' );
}

/**
 * Sanitize the standalone settings on save (Settings API + REST share this).
 * Iterates the registry — not the input — so only known keys persist and each is
 * coerced by its declared type; a select outside its options falls back to the
 * field default.
 *
 * @param mixed $input Raw posted/REST values.
 * @return array Sanitized settings.
 */
function mlsimport_sanitize_standalone_options( $input ): array {
	$input = is_array( $input ) ? $input : array();
	$out   = array();

	foreach ( mlsimport_standalone_field_registry() as $key => $field ) {
		$raw = array_key_exists( $key, $input ) ? $input[ $key ] : $field['default'];

		switch ( $field['type'] ) {
			case 'sections':
				$catalog     = isset( $field['catalog'] ) && is_callable( $field['catalog'] ) ? array_keys( call_user_func( $field['catalog'] ) ) : null;
				// Unmentioned slugs land where this field's default puts them.
				$off         = isset( $field['default']['inactive'] ) ? (array) $field['default']['inactive'] : array();
				$out[ $key ] = mlsimport_sanitize_sections( $raw, $catalog, $off );
				break;
			case 'int':
				$out[ $key ] = absint( $raw );
				break;
			case 'color':
				$color       = sanitize_hex_color( is_string( $raw ) ? $raw : '' );
				$out[ $key ] = is_string( $color ) ? $color : '';
				break;
			case 'email':
				$out[ $key ] = sanitize_email( is_string( $raw ) ? $raw : '' );
				break;
			case 'textarea':
				$out[ $key ] = sanitize_textarea_field( is_string( $raw ) ? $raw : '' );
				break;
			case 'html':
				// Post-grade markup, not plain text: an MLS disclaimer routinely needs a
				// link back to the MLS or a bold line, which sanitize_textarea_field eats.
				$out[ $key ] = wp_kses_post( is_string( $raw ) ? $raw : '' );
				break;
			case 'select':
				$val         = sanitize_text_field( is_string( $raw ) ? $raw : '' );
				// options is an ordered value => label map; allowed values are its keys.
				$out[ $key ] = array_key_exists( $val, $field['options'] ) ? $val : $field['default'];
				break;
			default: // text | number.
				$out[ $key ] = sanitize_text_field( is_string( $raw ) ? $raw : '' );
				break;
		}
	}

	return $out;
}

/**
 * The configured MLS logo URL, or '' when none is set. Shown on listing cards
 * (agency row) and the IDX attribution block.
 *
 * @param string $size Image size (default 'medium').
 * @return string
 */
function mlsimport_standalone_mls_logo_url( string $size = 'medium' ): string {
	$id = (int) mlsimport_standalone_option( 'mls_logo_id', 0 );
	if ( $id ) {
		$url = wp_get_attachment_image_url( $id, $size );
		if ( $url ) {
			return (string) $url;
		}
	}
	/** Filter the MLS logo URL (e.g. to use a hard-coded asset). @since 6.4 */
	return (string) apply_filters( 'mlsimport_mls_logo_url', '' );
}

/**
 * Read one standalone setting, falling back to its default.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Override default (optional).
 * @return mixed
 */
function mlsimport_standalone_option( string $key, $default = null ) {
	$opts     = get_option( MLSIMPORT_STANDALONE_OPTION, array() );
	$defaults = mlsimport_standalone_option_defaults();

	// A stored, non-empty value wins.
	if ( isset( $opts[ $key ] ) && '' !== $opts[ $key ] ) {
		return $opts[ $key ];
	}
	// Otherwise the caller-supplied override, then the registry default.
	if ( null !== $default ) {
		return $default;
	}
	return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
}

/**
 * Swap the Leaflet tile source to MapBox when the admin has saved a MapBox API key.
 *
 * Hooked on mlsimport_map_tile_url — the single seam every standalone map surface
 * reads (single-property map, the Map-with-listings block, the Half Map). With no
 * key the OSM default passes straight through; with a key set, tiles come from
 * MapBox's raster Static Tiles API (faster, per the field's own help text).
 *
 * @param string $url The incoming tile URL (the OSM default, unless already filtered).
 * @return string The MapBox tile URL when a key is set, else $url unchanged.
 */
function mlsimport_standalone_map_tile_url( string $url ): string {
	$key = trim( (string) mlsimport_standalone_option( 'mapbox_api_key', '' ) );
	if ( '' === $key ) {
		return $url;
	}
	// MapBox raster Static Tiles API — Leaflet templates {z}/{x}/{y} directly.
	return 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/256/{z}/{x}/{y}@2x?access_token=' . rawurlencode( $key );
}

/**
 * The default map zoom (the "Default Maps zoom (1 to 20)" setting), clamped to the
 * range the field promises. Drives the single-property map and the listings map's
 * fallback view.
 *
 * @return int Zoom level in 1..20.
 */
function mlsimport_standalone_map_zoom(): int {
	$zoom = (int) mlsimport_standalone_option( 'map_zoom', 11 );
	return max( 1, min( 20, $zoom ) );
}

/**
 * The map defaults the front-end JS reads: the starting point and default zoom.
 * Localised onto the listings-map script (MLSImportMap). Start lat/lng are floats
 * when set, else '' so the script can tell "configured" from "empty" and only then
 * use the starting point as the fallback view (otherwise the map fits to results).
 *
 * @return array{zoom:int,startLat:(float|string),startLng:(float|string)}
 */
function mlsimport_standalone_map_js_config(): array {
	$lat = mlsimport_standalone_option( 'map_start_lat', '' );
	$lng = mlsimport_standalone_option( 'map_start_lng', '' );
	return array(
		'zoom'     => mlsimport_standalone_map_zoom(),
		'startLat' => is_numeric( $lat ) ? (float) $lat : '',
		'startLng' => is_numeric( $lng ) ? (float) $lng : '',
	);
}

/**
 * Whether the viewport map should group nearby pins into clusters at this zoom.
 *
 * Two settings decide it: "Use the Pin Cluster on the maps" (map_pin_cluster) is
 * the master switch, and "Maximum zoom level for cluster to appear"
 * (map_cluster_max_zoom) is the cut-over — clusters appear up to and including that
 * zoom, and individual price pins take over beyond it. Used server-side by the
 * marker payload so the cluster/pin split honours the admin's choice.
 *
 * @param int $zoom The current Leaflet zoom of the request.
 * @return bool True to cluster dense cells; false to always emit individual pins.
 */
function mlsimport_standalone_map_cluster_enabled( int $zoom ): bool {
	if ( 'no' === mlsimport_standalone_option( 'map_pin_cluster', 'yes' ) ) {
		return false;
	}
	return $zoom <= (int) mlsimport_standalone_option( 'map_cluster_max_zoom', 11 );
}

/**
 * The configured brand colour as a :root CSS custom-property override, or '' when
 * no colour is set.
 *
 * The stylesheets hard-code their accent tokens (--mlsimport-accent for the
 * property/agent sections, --mli-accent for the listing grids). The "Main Color"
 * design setting (brand_color) is meant to re-theme the whole front end, so this
 * redefines those tokens from the saved colour. The hover/deep-accent variants
 * and the property-section washes/tints derive from the base accent via
 * color-mix, so a single picked colour re-tints everything.
 *
 * The value is re-validated as a hex colour, so the string is safe to emit inline.
 *
 * @return string CSS ':root{…}' rule, or '' when no brand colour is set.
 */
function mlsimport_standalone_brand_color_css(): string {
	$brand = sanitize_hex_color( (string) mlsimport_standalone_option( 'brand_color', '' ) );
	if ( ! $brand ) {
		return '';
	}

	// Property/agent-section tokens live on :root, so a :root override wins there.
	// The listing-grid accent (--mli-accent) is defined ON .mlsimport-listings /
	// .mlsimport-page-block, not :root — a value set directly on an element beats
	// one inherited from :root regardless of source order, so --mli-accent MUST be
	// overridden on those same selectors (printed after listings.css to win).
	// :root is listed as WELL so the token also reaches listing cards rendered
	// OUTSIDE a grid wrapper — Similar Listings on the single-property page — whose
	// favorite hearts read --mli-accent and would otherwise keep the unbranded
	// default while the same card on a grid page shows the brand colour. The
	// element-level selectors still win inside the grids, so nothing there changes.
	return ':root{'
		. '--mlsimport-accent:' . $brand . ';'
		. '--mlsimport-accent-hover:color-mix(in srgb,' . $brand . ' 85%,#fff);'
		. '--mlsimport-accent2:color-mix(in srgb,' . $brand . ' 80%,#000);'
		. '--mlsimport-accent2-hover:color-mix(in srgb,' . $brand . ' 70%,#000);'
		. '--mlsimport-secondary:' . $brand . ';'
		. '--mlsimport-good:' . $brand . ';'
		. '}'
		. ':root,.mlsimport-listings,.mlsimport-page-block{--mli-accent:' . $brand . ';}';
}

/**
 * Attach the brand-colour override to an enqueued stylesheet handle via
 * wp_add_inline_style, so it prints immediately AFTER that stylesheet and wins by
 * source order — the accent stylesheets are enqueued on demand during content
 * rendering (after wp_head), so a plain wp_head <style> would print BEFORE them
 * and lose the cascade. Idempotent per handle so repeated section enqueues don't
 * stack duplicate rules.
 *
 * @param string $handle Enqueued/registered stylesheet handle to append to.
 * @return void
 */
function mlsimport_standalone_attach_brand_color( string $handle ): void {
	static $done = array();
	if ( isset( $done[ $handle ] ) || ! function_exists( 'wp_add_inline_style' ) ) {
		return;
	}
	$done[ $handle ] = true;

	$css = mlsimport_standalone_brand_color_css();
	if ( '' !== $css ) {
		wp_add_inline_style( $handle, $css );
	}
}

/**
 * Attach the brand-colour override to the admin edit-screen metabox stylesheet
 * (mlsimport-property-metabox.css). Its accent tokens live on .mlsimport-mb (not
 * :root) as a warm mirror of the front-end set, so the override targets that same
 * selector and is printed after the stylesheet to win. The wash/tint tokens
 * derive from --mlsmb-accent via color-mix, so overriding the base re-tints the
 * whole metabox. Idempotent per handle.
 *
 * @param string $handle Enqueued metabox stylesheet handle.
 * @return void
 */
function mlsimport_standalone_attach_brand_color_metabox( string $handle ): void {
	static $done = array();
	if ( isset( $done[ $handle ] ) || ! function_exists( 'wp_add_inline_style' ) ) {
		return;
	}
	$done[ $handle ] = true;

	$brand = sanitize_hex_color( (string) mlsimport_standalone_option( 'brand_color', '' ) );
	if ( ! $brand ) {
		return;
	}

	$css = '.mlsimport-mb{'
		. '--mlsmb-accent:' . $brand . ';'
		. '--mlsmb-accent-hover:color-mix(in srgb,' . $brand . ' 85%,#fff);'
		. '--mlsmb-accent2:color-mix(in srgb,' . $brand . ' 80%,#000);'
		. '--mlsmb-good:' . $brand . ';'
		. '}';
	wp_add_inline_style( $handle, $css );
}

/**
 * The card template filename for the configured card style. Every listing grid
 * (archive, page blocks, similar listings, agent listings) renders through this
 * one resolver, so changing the setting restyles all of them. v1 is the default
 * card (templates/card.php); v2/v3 are alternative designs.
 *
 * @return string Template filename (e.g. 'card.php', 'card-v2.php').
 */
function mlsimport_standalone_card_template(): string {
	$style = (string) mlsimport_standalone_option( 'card_style', 'v1' );
	// v1 is the base card.php; other styles map to card-<style>.php (slug sanitized).
	$name  = 'v1' === $style ? 'card.php' : 'card-' . preg_replace( '/[^a-z0-9]/', '', strtolower( $style ) ) . '.php';
	/** Filter the resolved card template filename. @since 6.4 */
	return (string) apply_filters( 'mlsimport_card_template', $name, $style );
}
