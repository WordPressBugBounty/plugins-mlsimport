/**
 * Standalone Design — settings app (theme_id 990).
 *
 * Renders on the dedicated "Standalone Design" admin page. Reads/writes the
 * mlsimport_standalone_options WordPress option through the core settings REST
 * endpoint (/wp/v2/settings) — no custom save handler. Built with
 * @wordpress/components so every control is WP-native and accessible.
 * Compile with: npm run build:settings.
 *
 * The layout is a vertical menu on the left and the selected screen's fields on
 * the right. Each menu item and its fields are declared in the TABS config below;
 * an item with `subtabs` lists them as indented children in that same left menu.
 * A generic renderer turns each field into the right control.
 * Field KEYS must match the PHP registry in
 * includes/standalone/class-mlsimport-standalone-settings.php — that registry
 * owns defaults, the REST schema and sanitization.
 */

import { createRoot, useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Card,
	CardBody,
	TextControl,
	TextareaControl,
	SelectControl,
	ToggleControl,
	Button,
	Spinner,
	Notice,
	__experimentalHeading as Heading,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

// wp_options key the whole settings tree reads from and writes back to.
const OPTION_KEY = 'mlsimport_standalone_options';

// Reusable Yes/No option set for `yesno` fields.
const YES_NO = [
	{ label: __( 'Yes', 'mlsimport' ), value: 'yes' },
	{ label: __( 'No', 'mlsimport' ), value: 'no' },
];

/**
 * Tab + field definitions. type: text | number | email | textarea | select |
 * yesno | color. Empty `fields` renders a placeholder (scaffolded for later).
 *
 * This inline copy is now only a FALLBACK. The live tree comes from PHP as
 * window.mlsimportFields (mlsimport_standalone_settings_app_config), generated from
 * the one field registry — so adding a field to the registry surfaces it here (and
 * in the Customizer) with no JS change. See TABS below.
 */
const TABS_FALLBACK = [
	{
		name: 'general',
		title: __( 'General', 'mlsimport' ),
		fields: [
			{
				key: 'properties_per_page',
				label: __( 'No. of Properties per Page', 'mlsimport' ),
				type: 'number',
			},
			{
				key: 'order_by',
				label: __( 'Order by', 'mlsimport' ),
				type: 'select',
				options: [
					{ label: __( 'Default', 'mlsimport' ), value: 'default' },
					{ label: __( 'Price High to Low', 'mlsimport' ), value: 'price_high' },
					{ label: __( 'Price Low to High', 'mlsimport' ), value: 'price_low' },
					{ label: __( 'Newest first', 'mlsimport' ), value: 'newest' },
					{ label: __( 'Oldest first', 'mlsimport' ), value: 'oldest' },
					{ label: __( 'Newest Edited', 'mlsimport' ), value: 'newest_edited' },
					{ label: __( 'Oldest Edited', 'mlsimport' ), value: 'oldest_edited' },
					{ label: __( 'Bedrooms High to Low', 'mlsimport' ), value: 'beds_high' },
					{ label: __( 'Bedrooms Low to High', 'mlsimport' ), value: 'beds_low' },
					{ label: __( 'Bathrooms High to Low', 'mlsimport' ), value: 'baths_high' },
					{ label: __( 'Bathrooms Low to High', 'mlsimport' ), value: 'baths_low' },
				],
			},
		],
	},
	{
		name: 'taxonomy_filters',
		title: __( 'Category page filters', 'mlsimport' ),
		fields: [
			{
				key: 'archive_search_fields',
				label: __( 'Archive search filters', 'mlsimport' ),
				help: __( 'Toggle which filters appear in the search bar on the taxonomy and property archive pages. Status, City and Type are on by default.', 'mlsimport' ),
				type: 'toggles',
				catalog: 'archive_filters',
				defaultActive: [ 'status', 'city', 'property_type' ],
			},
		],
	},
	{
		name: 'social',
		title: __( 'Social & Contact', 'mlsimport' ),
		fields: [
			{
				key: 'lead_recipient',
				label: __( 'Email', 'mlsimport' ),
				type: 'email',
				help: __( 'Company email — e.g. office@domain.com. Also the fallback lead recipient when a listing has no agent email.', 'mlsimport' ),
			},
			{
				key: 'contact_form_recipients',
				label: __( 'Contact form recipients', 'mlsimport' ),
				type: 'text',
				help: __( 'Where the page-builder Contact Form block sends submissions. One or more emails, comma-separated. Leave empty to fall back to the company email above.', 'mlsimport' ),
			},
			{
				key: 'consent_label',
				label: __( 'Text for the checkbox label', 'mlsimport' ),
				type: 'textarea',
				help: __( 'Shown next to the marketing-consent checkbox on contact forms.', 'mlsimport' ),
			},
			{
				key: 'terms_link_text',
				label: __( 'Text for terms link', 'mlsimport' ),
				type: 'text',
				help: __( 'e.g. Privacy Policy.', 'mlsimport' ),
			},
			{
				key: 'show_looking_dropdown',
				label: __( "Show 'What are you looking to do?' dropdown on contact forms?", 'mlsimport' ),
				type: 'yesno',
				help: __( 'Displays an optional dropdown field on Agent, Agency, Developer, and Property contact forms.', 'mlsimport' ),
			},
			{
				key: 'looking_options',
				label: __( 'Dropdown options (comma-separated)', 'mlsimport' ),
				type: 'text',
			},
		],
	},
	{
		name: 'maps',
		title: __( 'Maps', 'mlsimport' ),
		fields: [
			{
				key: 'mapbox_api_key',
				label: __( 'MapBox API KEY', 'mlsimport' ),
				type: 'text',
				help: __( 'Used for tiles when Open Street Maps is enabled. Get a key at https://www.mapbox.com/. If blank, the default OpenStreet server is used (can be slow).', 'mlsimport' ),
			},
			{
				key: 'map_start_lat',
				label: __( 'Starting Point Latitude', 'mlsimport' ),
				type: 'number',
				help: __( 'Numbers only (ex: 40.577906).', 'mlsimport' ),
			},
			{
				key: 'map_start_lng',
				label: __( 'Starting Point Longitude', 'mlsimport' ),
				type: 'number',
				help: __( 'Numbers only (ex: -74.155058).', 'mlsimport' ),
			},
			{
				key: 'map_zoom',
				label: __( 'Default Maps zoom (1 to 20)', 'mlsimport' ),
				type: 'number',
			},
			{
				key: 'map_pin_cluster',
				label: __( 'Use the Pin Cluster on the maps', 'mlsimport' ),
				type: 'yesno',
				help: __( 'If yes, nearby pins are grouped in a cluster.', 'mlsimport' ),
			},
			{
				key: 'map_cluster_max_zoom',
				label: __( 'Maximum zoom level for cluster to appear', 'mlsimport' ),
				type: 'number',
				help: __( 'Pin cluster disappears when the map zoom is less than this value.', 'mlsimport' ),
			},
			{
				key: 'map_geolocation_circle',
				label: __( 'Geolocation Circle over maps (in meters)', 'mlsimport' ),
				type: 'number',
				help: __( 'Circle radius for the user geolocation pin. Numbers only (ex: 400).', 'mlsimport' ),
			},
		],
	},
	{
		name: 'property_page',
		title: __( 'Property Page', 'mlsimport' ),
		subtabs: [
			{
				name: 'pp_general',
				title: __( 'General', 'mlsimport' ),
				fields: [
			{
				key: 'media_section_type',
				label: __( 'Media Section Type (property images & video)', 'mlsimport' ),
				type: 'buttons',
				help: __( 'Choose how to display the listing images or video.', 'mlsimport' ),
				options: [
					{ label: __( 'Classic Slider', 'mlsimport' ), value: 'classic' },
					{ label: __( 'Vertical Slider', 'mlsimport' ), value: 'vertical' },
					{ label: __( 'Slider v4', 'mlsimport' ), value: 'v4' },
					{ label: __( 'Multi Image Slider', 'mlsimport' ), value: 'multi' },
					{ label: __( 'Masonry Gallery v1', 'mlsimport' ), value: 'masonry1' },
					{ label: __( 'Masonry Gallery v2', 'mlsimport' ), value: 'masonry2' },
				],
			},
				],
			},
			{
				name: 'pp_layout',
				title: __( 'Property Page Layout', 'mlsimport' ),
				fields: [
					{
						key: 'details_columns',
						label: __( 'Details Columns', 'mlsimport' ),
						type: 'buttons',
						help: __( 'How many columns each details section (Interior, Exterior, Financial…) runs. Collapses automatically on narrow screens.', 'mlsimport' ),
						options: [
							{ label: __( '3 Columns', 'mlsimport' ), value: '3' },
							{ label: __( '2 Columns', 'mlsimport' ), value: '2' },
						],
					},
					{
						key: 'property_sections',
						label: __( 'Arrange Sections', 'mlsimport' ),
						help: __( 'Drag sections between Enabled and Disabled to choose which appear, and reorder within a list.', 'mlsimport' ),
						type: 'sections',
					},
				],
			},
			{
				name: 'pp_attribution',
				title: __( 'MLS Attribution', 'mlsimport' ),
				fields: [
					{
						key: 'mls_logo_id',
						label: __( 'MLS logo', 'mlsimport' ),
						type: 'media',
						help: __( "Your MLS's required attribution logo. Shown in the MLS Attribution section and on listing cards.", 'mlsimport' ),
					},
					{
						key: 'attribution_text',
						label: __( 'Disclaimer', 'mlsimport' ),
						type: 'textarea',
						rows: 10,
						help: __(
							'The disclaimer your MLS requires, shown on every property. Use %mls_id% for the listing\'s MLS number and %year% for the current year. Basic HTML (links, bold, paragraphs) is allowed.',
							'mlsimport'
						),
					},
				],
			},
			{
				name: 'pp_tour',
				title: __( 'Tour Details', 'mlsimport' ),
				fields: [
					{
						key: 'tour_times',
						label: __( 'Preferred tour times', 'mlsimport' ),
						type: 'text',
						help: __( 'Time slots offered in the "Schedule a Tour" picker on the property page. Comma-separated, e.g. 9:00 AM, 11:30 AM, 2:00 PM, 4:30 PM.', 'mlsimport' ),
					},
				],
			},
			{
				name: 'pp_overview',
				title: __( 'Overview', 'mlsimport' ),
				fields: [
					{
						key: 'overview_fields',
						label: __( 'Arrange Fields', 'mlsimport' ),
						help: __( 'Drag fields between Enabled and Disabled to choose which appear in the Overview section of the property page, and reorder within a list.', 'mlsimport' ),
						type: 'sections',
						catalog: 'overview',
					},
				],
			},
		],
	},
	{
		name: 'property_card',
		title: __( 'Property Card', 'mlsimport' ),
		fields: [
			{
				key: 'card_style',
				label: __( 'Property card style', 'mlsimport' ),
				type: 'select',
				help: __( 'The card design used in every listing grid (search results, lists, sliders, similar listings).', 'mlsimport' ),
				options: [
					{ label: __( 'V1 — Standard', 'mlsimport' ), value: 'v1' },
					{ label: __( 'V2 — Horizontal', 'mlsimport' ), value: 'v2' },
					{ label: __( 'V3 — Photo overlay', 'mlsimport' ), value: 'v3' },
				],
			},
		],
	},
	{
		name: 'agent',
		title: __( 'Agent', 'mlsimport' ),
		fields: [
			{
				key: 'agent_listings_per_page',
				label: __( 'No. of listings per page', 'mlsimport' ),
				type: 'number',
				default: '12',
				help: __( "Listings shown per page on a single agent's profile, with pagination. Default 12.", 'mlsimport' ),
			},
			{
				key: 'agent_sections',
				label: __( 'Arrange Sections', 'mlsimport' ),
				help: __( 'Drag sections between Enabled and Disabled to choose which appear on the agent profile, and reorder within a list.', 'mlsimport' ),
				type: 'sections',
				catalog: 'agent',
			},
		],
	},
	{
		name: 'colors',
		title: __( 'Colors', 'mlsimport' ),
		fields: [
			{
				key: 'brand_color',
				label: __( 'Main Color', 'mlsimport' ),
				help: __( 'Main accent / brand colour for the front end.', 'mlsimport' ),
				type: 'color',
			},
		],
	},
];

/**
 * The native WordPress color picker (wp-color-picker / Iris) — the same widget
 * the theme options use: swatch + Select Color + hex input + Clear, with the
 * saturation square, hue bar and preset palette.
 *
 * Iris is a jQuery widget that rewrites the DOM around its input, which fights
 * React's reconciliation. To avoid that, JSX renders only an empty ref'd <div>
 * (no children React tracks); we create the input by hand inside it, init Iris,
 * and wipe the div on unmount. Initialised once — continuous onChange updates
 * never re-init the widget.
 */
function IrisColorField( { value, onChange } ) {
	// Ref to the empty div React owns; Iris' input is created inside it by hand.
	const holder = useRef();
	// Latest value kept in a ref so the init effect can read it without re-running.
	const valueRef = useRef( value );
	valueRef.current = value;

	useEffect( () => {
		// Bail unless jQuery and the wpColorPicker widget are available.
		const $ = window.jQuery;
		if ( ! $ || ! holder.current || ! $.fn.wpColorPicker ) {
			return undefined;
		}
		// Build the text input Iris upgrades, seeded with the current value.
		const input = document.createElement( 'input' );
		input.type = 'text';
		input.value = valueRef.current || '';
		holder.current.appendChild( input );

		// Initialise Iris; forward its change/clear events to onChange.
		const $input = $( input );
		$input.wpColorPicker( {
			defaultColor: valueRef.current || '',
			change: ( event, ui ) => onChange( ui.color.toString() ),
			clear: () => onChange( '' ),
		} );

		// Cleanup: close the widget and wipe the DOM Iris built on unmount.
		const node = holder.current;
		return () => {
			try {
				$input.wpColorPicker( 'close' );
			} catch ( e ) {} // eslint-disable-line no-empty
			if ( node ) {
				node.innerHTML = '';
			}
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- init once.

	// React renders only this empty div; Iris populates it imperatively.
	return <div ref={ holder } />;
}

/**
 * "Arrange Sections" — two drag-and-drop lists (Enabled / Disabled). Items can be
 * reordered within a list and dragged across lists. Value is { active, inactive }
 * slug arrays; the catalog (slug + label) comes from window.mlsimportSections,
 * localized by PHP from the property section registry.
 *
 * Uses native HTML5 drag-and-drop (no extra deps). The dragged item's origin is
 * held in a ref; on drop we splice it out and insert at the target position.
 */
// The first four single-property sections are mandatory and fixed at the top:
// the breadcrumbs, the gallery, the title bar, and the in-page navigation. They
// render with a distinct background and cannot be dragged or used as a drop
// target, so users can neither move them nor insert other sections above them.
const LOCKED_SECTIONS = [ 'breadcrumbs', 'property_gallery', 'title_bar', 'subnav' ];

/**
 * The Enabled/Disabled catalog (slug + label list) for a `sections` field,
 * chosen by the field's `catalog` id. Each list is localized by PHP on the
 * settings page. Defaults to the property section catalog when unset.
 */
function sectionsCatalog( id ) {
	if ( 'agent' === id ) {
		return window.mlsimportAgentSections || [];
	}
	if ( 'archive_filters' === id ) {
		return window.mlsimportArchiveFilters || [];
	}
	if ( 'overview' === id ) {
		return window.mlsimportOverviewFields || [];
	}
	return window.mlsimportSections || [];
}

/**
 * MLS logo picker — the native WordPress media modal (wp.media). Stores an
 * attachment ID (0 when none). The initial preview URL for an already-saved
 * logo is localized by PHP as window.mlsimportLogoUrl; once the user picks a
 * new image we read the fresh URL straight off the selected attachment.
 */
function MediaField( { value, onChange } ) {
	// Cache the wp.media frame so re-opening reuses the same modal instance.
	const frameRef = useRef( null );
	// Preview URL: use the PHP-localized URL for an already-saved logo, else none.
	const [ previewUrl, setPreviewUrl ] = useState(
		value ? window.mlsimportLogoUrl || '' : ''
	);

	// Open (or lazily create) the WordPress media modal.
	const openFrame = () => {
		// wp.media must be present to open the picker.
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		// Reuse an already-created frame.
		if ( frameRef.current ) {
			frameRef.current.open();
			return;
		}
		// First open: build an image-only, single-select media frame.
		const frame = window.wp.media( {
			title: __( 'Select the MLS logo', 'mlsimport' ),
			button: { text: __( 'Use this logo', 'mlsimport' ) },
			library: { type: 'image' },
			multiple: false,
		} );
		// On selection, store the attachment ID and derive a fresh preview URL.
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			onChange( att.id );
			setPreviewUrl( att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url );
		} );
		frameRef.current = frame;
		frame.open();
	};

	// Clear the selection (ID 0) and its preview.
	const remove = () => {
		onChange( 0 );
		setPreviewUrl( '' );
	};

	return (
		<div>
			{ previewUrl && (
				<div style={ { marginBottom: '10px' } }>
					<img
						src={ previewUrl }
						alt=""
						style={ {
							maxHeight: '48px',
							width: 'auto',
							background: '#fff',
							padding: '6px',
							border: '1px solid #dcdcde',
							borderRadius: '6px',
						} }
					/>
				</div>
			) }
			<div style={ { display: 'flex', gap: '8px' } }>
				<Button variant="secondary" onClick={ openFrame }>
					{ __( 'Select logo', 'mlsimport' ) }
				</Button>
				{ !! value && (
					<Button variant="tertiary" isDestructive onClick={ remove }>
						{ __( 'Remove', 'mlsimport' ) }
					</Button>
				) }
			</div>
		</div>
	);
}

/**
 * The Enabled/Disabled drag-and-drop control described in the block above.
 *
 * @param {Object}   props.value    Current { active, inactive } slug arrays.
 * @param {Function} props.onChange Called with the next { active, inactive }.
 * @param {Array}    props.catalog  Catalog rows ({ slug, label }) for this field.
 * @param {Array}    props.locked   Slugs pinned Enabled-and-first, undraggable.
 * @return {Element} Two rendered drag-and-drop columns.
 */
function SectionsArrange( { value, onChange, catalog = [], locked = [], defaultInactive = [] } ) {
	// Holds the { list, index } origin of the item currently being dragged.
	const drag = useRef( null );

	// Resolve a slug's human label from the catalog (falls back to the slug).
	const labelFor = ( slug ) => {
		const found = catalog.find( ( c ) => c.slug === slug );
		return found ? found.label : slug;
	};

	let active = value && Array.isArray( value.active ) ? value.active : [];
	let inactive = value && Array.isArray( value.inactive ) ? value.inactive : [];
	// A catalog section the saved value never mentions lands where the field's
	// default puts it — Disabled for the slugs in defaultInactive (the Tabs /
	// Accordion containers), Enabled otherwise — which is exactly where the PHP
	// sanitizer will put it on save. This covers both the first use (nothing saved
	// yet: every section is unmentioned) and a section the catalog gained AFTER the
	// user last saved, so it is never missing from both columns.
	const unmentioned = catalog
		.map( ( c ) => c.slug )
		.filter( ( s ) => ! active.includes( s ) && ! inactive.includes( s ) );
	active = active.concat( unmentioned.filter( ( s ) => ! defaultInactive.includes( s ) ) );
	inactive = inactive.concat( unmentioned.filter( ( s ) => defaultInactive.includes( s ) ) );
	// Locked sections are always Enabled and always first, in their fixed order —
	// exactly where the front end renders them, no matter what a stale saved
	// layout says. The next save persists this healed order.
	if ( locked.length ) {
		active = locked.concat( active.filter( ( s ) => ! locked.includes( s ) ) );
		inactive = inactive.filter( ( s ) => ! locked.includes( s ) );
	}

	// Move the dragged item into toList at toIndex, then emit the new value.
	const apply = ( toList, toIndex ) => {
		// Read and clear the drag origin.
		const from = drag.current;
		drag.current = null;
		if ( ! from ) {
			return;
		}
		// Work on copies so we don't mutate the current value.
		const next = { active: [ ...active ], inactive: [ ...inactive ] };
		// Pull the item out of its source list.
		const [ item ] = next[ from.list ].splice( from.index, 1 );
		let idx = toIndex;
		// Same-list move past the removed slot shifts the target index down by one.
		if ( from.list === toList && from.index < toIndex ) {
			idx -= 1;
		}
		// Clamp out-of-range targets to the end of the destination list.
		if ( idx < 0 || idx > next[ toList ].length ) {
			idx = next[ toList ].length;
		}
		// Insert at the resolved position and notify the parent.
		next[ toList ].splice( idx, 0, item );
		onChange( next );
	};

	const columnStyle = {
		flex: 1,
		minWidth: 0,
		border: '1px solid #dcdcde',
		borderRadius: '6px',
		padding: '12px',
		minHeight: '220px',
		background: '#fbfbfc',
	};
	const itemStyle = {
		padding: '10px 12px',
		marginBottom: '8px',
		border: '1px solid #dcdcde',
		borderRadius: '6px',
		background: 'linear-gradient(#ffffff, #f3f4f5)',
		textAlign: 'center',
		fontWeight: 600,
		color: '#1d2327',
		cursor: 'grab',
	};
	const lockedItemStyle = {
		...itemStyle,
		background: '#e7edf5',
		borderColor: '#c5d2e3',
		color: '#50575e',
		cursor: 'not-allowed',
	};

	const renderColumn = ( list, items, title ) => (
		<div
			className={ `mlsimport-arrange__col mlsimport-arrange__col--${ list }` }
			style={ columnStyle }
			onDragOver={ ( e ) => e.preventDefault() }
			onDrop={ ( e ) => {
				e.preventDefault();
				apply( list, items.length );
			} }
		>
			<div
				style={ {
					fontWeight: 600,
					borderBottom: '1px solid #dcdcde',
					paddingBottom: '8px',
					marginBottom: '12px',
				} }
			>
				{ title }
			</div>
			{ items.length === 0 && (
				<div style={ { color: '#a7aaad', textAlign: 'center', padding: '16px 0' } }>
					{ __( 'Drop sections here', 'mlsimport' ) }
				</div>
			) }
			{ items.map( ( slug, i ) => {
				const isLocked = locked.includes( slug );
				return (
					<div
						key={ slug }
						className="mlsimport-arrange__item"
						draggable={ ! isLocked }
						style={ isLocked ? lockedItemStyle : itemStyle }
						onDragStart={
							isLocked
								? undefined
								: () => {
										drag.current = { list, index: i };
								  }
						}
						onDragOver={ ( e ) => e.preventDefault() }
						onDrop={ ( e ) => {
							e.preventDefault();
							e.stopPropagation();
							if ( ! isLocked ) {
								apply( list, i );
							}
						} }
					>
						{ labelFor( slug ) }
					</div>
				);
			} ) }
		</div>
	);

	return (
		<div style={ { display: 'flex', gap: '16px', alignItems: 'flex-start' } }>
			{ renderColumn( 'active', active, __( 'Enabled', 'mlsimport' ) ) }
			{ renderColumn( 'inactive', inactive, __( 'Disabled', 'mlsimport' ) ) }
		</div>
	);
}

/**
 * A per-filter on/off toggle list backed by the same { active, inactive } value
 * shape as SectionsArrange (so it shares the PHP catalog + sanitizer). Every
 * catalog filter renders one ToggleControl, in catalog order; a filter is on
 * unless it's explicitly in `inactive` — matching the PHP sanitizer, which
 * enables any catalog key not present in either list. When there's no saved value
 * yet, `defaultActive` seeds which filters start on.
 */
function FilterToggles( { value, onChange, catalog = [], defaultActive = [] } ) {
	// All catalog slugs, in catalog order.
	const slugs = catalog.map( ( c ) => c.slug );
	// Whether a saved value exists (either list is a non-empty array).
	const hasValue =
		value &&
		( ( Array.isArray( value.active ) && value.active.length ) ||
			( Array.isArray( value.inactive ) && value.inactive.length ) );
	// The off-set: saved inactive list, or everything not in defaultActive on first use.
	const inactive =
		hasValue && Array.isArray( value.inactive )
			? value.inactive
			: slugs.filter( ( s ) => ! defaultActive.includes( s ) );

	// Toggle one filter, then recompute both lists and emit them.
	const setOn = ( slug, on ) => {
		// Rebuild inactive: flip this slug, keep others as they were.
		const nextInactive = slugs.filter( ( s ) =>
			s === slug ? ! on : inactive.includes( s )
		);
		// Active is every slug not in the new inactive list.
		const active = slugs.filter( ( s ) => ! nextInactive.includes( s ) );
		onChange( { active, inactive: nextInactive } );
	};

	return (
		<div>
			{ catalog.map( ( c ) => (
				<div key={ c.slug } style={ { marginBottom: '4px' } }>
					<ToggleControl
						label={ c.label }
						checked={ ! inactive.includes( c.slug ) }
						onChange={ ( on ) => setOn( c.slug, on ) }
						__nextHasNoMarginBottom
					/>
				</div>
			) ) }
		</div>
	);
}

/**
 * Generic field renderer — maps a field definition's `type` to the right control.
 *
 * @param {Object}   props.field    Field def ({ type, label, help, options, … }).
 * @param {*}        props.value    Current value for this field.
 * @param {Function} props.onChange Called with the field's next value.
 * @return {Element} The control for this field type (text/number/email by default).
 */
function Field( { field, value, onChange } ) {
	// Pick the control by declared field type.
	switch ( field.type ) {
		case 'select':
			return (
				<SelectControl
					label={ field.label }
					help={ field.help }
					value={ value || '' }
					options={ field.options }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'yesno':
			return (
				<SelectControl
					label={ field.label }
					help={ field.help }
					value={ value || 'no' }
					options={ YES_NO }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
		case 'toggles':
			return (
				<>
					<p style={ { margin: '0 0 2px', fontWeight: 600 } }>{ field.label }</p>
					{ field.help && (
						<p style={ { margin: '0 0 12px', color: '#787c82', fontSize: '12px' } }>
							{ field.help }
						</p>
					) }
					<FilterToggles
						value={ value }
						onChange={ onChange }
						catalog={ sectionsCatalog( field.catalog ) }
						defaultActive={ field.defaultActive || [] }
					/>
				</>
			);
		case 'buttons':
			return (
				<>
					<p style={ { margin: '0 0 2px', fontWeight: 600 } }>{ field.label }</p>
					{ field.help && (
						<p style={ { margin: '0 0 8px', color: '#787c82', fontSize: '12px' } }>
							{ field.help }
						</p>
					) }
					<div style={ { display: 'flex', flexWrap: 'wrap', gap: '8px' } }>
						{ field.options.map( ( opt ) => (
							<Button
								key={ opt.value }
								variant={ value === opt.value ? 'primary' : 'secondary' }
								onClick={ () => onChange( opt.value ) }
							>
								{ opt.label }
							</Button>
						) ) }
					</div>
				</>
			);
		case 'sections':
			return (
				<>
					<p style={ { margin: '0 0 2px', fontWeight: 600 } }>{ field.label }</p>
					{ field.help && (
						<p style={ { margin: '0 0 12px', color: '#787c82', fontSize: '12px' } }>
							{ field.help }
						</p>
					) }
					<SectionsArrange
						value={ value }
						onChange={ onChange }
						catalog={ sectionsCatalog( field.catalog ) }
						locked={ field.catalog === 'property' || ! field.catalog ? LOCKED_SECTIONS : [] }
						defaultInactive={ field.defaultInactive || [] }
					/>
				</>
			);
		case 'textarea':
			return (
				<TextareaControl
					label={ field.label }
					help={ field.help }
					value={ value || '' }
					onChange={ onChange }
					rows={ field.rows || 5 }
					__nextHasNoMarginBottom
				/>
			);
		case 'media':
			return (
				<>
					<p style={ { margin: '0 0 2px', fontWeight: 600 } }>{ field.label }</p>
					{ field.help && (
						<p style={ { margin: '0 0 8px', color: '#787c82', fontSize: '12px' } }>
							{ field.help }
						</p>
					) }
					<MediaField value={ value } onChange={ onChange } />
				</>
			);
		case 'color':
			return (
				<>
					<p style={ { margin: '0 0 2px', fontWeight: 600 } }>{ field.label }</p>
					{ field.help && (
						<p style={ { margin: '0 0 8px', color: '#787c82', fontSize: '12px' } }>
							{ field.help }
						</p>
					) }
					<IrisColorField value={ value } onChange={ onChange } />
				</>
			);
		default: // text | number | email.
			return (
				<TextControl
					label={ field.label }
					help={ field.help }
					type={ field.type === 'number' ? 'number' : field.type === 'email' ? 'email' : 'text' }
					value={ value !== undefined && value !== '' ? value : field.default || '' }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
			);
	}
}

/**
 * The live field tree — generated by PHP from the one registry and localized as
 * window.mlsimportFields. Falls back to the inline copy only if that is absent
 * (e.g. the script somehow loaded without its inline data).
 */
const TABS =
	typeof window !== 'undefined' && Array.isArray( window.mlsimportFields ) && window.mlsimportFields.length
		? window.mlsimportFields
		: TABS_FALLBACK;

/**
 * Every selectable screen, in menu order: a top-level tab without subtabs is one
 * screen; a tab with subtabs contributes one screen per subtab (each keeping a
 * reference to its parent, so the panel can title itself "Parent — Child").
 */
const SCREENS = TABS.flatMap( ( t ) =>
	t.subtabs
		? t.subtabs.map( ( s ) => ( { ...s, parent: t } ) )
		: [ t ]
);

/**
 * Root component: left menu of screens + the selected screen's fields, with a
 * Save button. Loads/saves the whole option via the core /wp/v2/settings REST
 * endpoint and shows a success/error Notice.
 *
 * @return {Element} The settings app UI (or a loading spinner until settings load).
 */
function SettingsApp() {
	// The full option object (null until the initial REST load resolves).
	const [ settings, setSettings ] = useState( null );
	// In-flight flag for the Save request.
	const [ saving, setSaving ] = useState( false );
	// Success/error banner state.
	const [ notice, setNotice ] = useState( null );
	// Currently selected screen (defaults to the first).
	const [ screen, setScreen ] = useState( SCREENS[ 0 ].name );

	// On mount: fetch all settings and pull out our option (or a friendly error).
	useEffect( () => {
		apiFetch( { path: '/wp/v2/settings' } )
			.then( ( all ) => setSettings( all[ OPTION_KEY ] || {} ) )
			.catch( ( err ) =>
				setNotice( { status: 'error', text: err.message || __( 'Could not load settings.', 'mlsimport' ) } )
			);
	}, [] );

	// Immutably set one field's value in the settings object.
	const update = ( key, value ) => setSettings( ( prev ) => ( { ...prev, [ key ]: value } ) );

	// Render a screen's fields, or a placeholder when the screen has none.
	const renderFields = ( fields ) =>
		fields.length === 0 ? (
			<p style={ { color: '#787c82' } }>{ __( 'No settings here yet.', 'mlsimport' ) }</p>
		) : (
			fields.map( ( field ) => (
				<div key={ field.key } data-field={ field.key } style={ { marginBottom: '22px' } }>
					<Field
						field={ field }
						value={ settings[ field.key ] }
						onChange={ ( v ) => update( field.key, v ) }
					/>
				</div>
			) )
		);

	// POST the whole option back, then reflect the saved value and show a notice.
	const save = () => {
		setSaving( true );
		setNotice( null );
		apiFetch( { path: '/wp/v2/settings', method: 'POST', data: { [ OPTION_KEY ]: settings } } )
			.then( ( all ) => {
				// Adopt the server-sanitized value returned by the REST endpoint.
				setSettings( all[ OPTION_KEY ] || {} );
				setNotice( { status: 'success', text: __( 'Settings saved.', 'mlsimport' ) } );
			} )
			.catch( ( err ) =>
				setNotice( { status: 'error', text: err.message || __( 'Save failed.', 'mlsimport' ) } )
			)
			.finally( () => setSaving( false ) );
	};

	// Show a spinner until the initial settings load completes.
	if ( ! settings ) {
		return (
			<div style={ { display: 'flex', alignItems: 'center', gap: '10px', padding: '24px' } }>
				<Spinner />
				<span>{ __( 'Loading your settings — please wait…', 'mlsimport' ) }</span>
			</div>
		);
	}

	// The screen object for the active tab (falls back to the first screen).
	const current = SCREENS.find( ( s ) => s.name === screen ) || SCREENS[ 0 ];

	// Render one left-menu button (child items get an is-child modifier).
	const menuItem = ( item, isChild ) => (
		<button
			key={ item.name }
			type="button"
			className={ [
				'mlsimport-settings-tabs__item',
				isChild ? 'is-child' : '',
				current.name === item.name ? 'is-active' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
			aria-current={ current.name === item.name }
			onClick={ () => setScreen( item.name ) }
		>
			{ item.title }
		</button>
	);

	return (
		<div style={ { maxWidth: '900px', marginTop: '20px' } }>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) } isDismissible>
					{ notice.text }
				</Notice>
			) }

			<Card>
				<CardBody>
					<div className="mlsimport-settings-tabs">
						<nav className="mlsimport-settings-tabs__menu">
							{ TABS.map( ( t ) =>
								t.subtabs ? (
									<div key={ t.name } className="mlsimport-settings-tabs__group">
										<button
											type="button"
											className="mlsimport-settings-tabs__item is-parent"
											onClick={ () => setScreen( t.subtabs[ 0 ].name ) }
										>
											{ t.title }
										</button>
										{ t.subtabs.map( ( s ) => menuItem( s, true ) ) }
									</div>
								) : (
									menuItem( t, false )
								)
							) }
						</nav>

						<div className="mlsimport-settings-tabs__panel">
							<Heading level={ 3 } style={ { marginTop: 0 } }>
								{ current.parent
									? `${ current.parent.title } — ${ current.title }`
									: current.title }
							</Heading>
							{ renderFields( current.fields ) }
						</div>
					</div>
				</CardBody>
			</Card>

			<div style={ { marginTop: '20px' } }>
				<Button className="mlsimport-save-button" variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
					{ saving ? __( 'Saving…', 'mlsimport' ) : __( 'Save changes', 'mlsimport' ) }
				</Button>
			</div>
		</div>
	);
}

// Mount point printed by the settings page; render the app only when present.
const mount = document.getElementById( 'mlsimport-standalone-app' );
if ( mount ) {
	createRoot( mount ).render( <SettingsApp /> );
}
