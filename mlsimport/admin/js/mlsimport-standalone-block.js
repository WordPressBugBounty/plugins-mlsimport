/**
 * Editor registration for the standalone `mlsimport/listings` and
 * `mlsimport/half-map` dynamic blocks.
 *
 * Both are server-rendered (render_callback in PHP) and share one inspector: a
 * "properties to display" control, an Initial filter panel, and one on/off toggle
 * per search field (the form fields are localized from PHP so this list never
 * drifts from search-form.php). The Half Map block adds a Layout panel (map side +
 * height). Blocks are localized from PHP, so the half-map reuses the listings
 * inspector instead of the generic page-block adapter's raw text inputs. No build
 * step: plain wp.element.createElement, no JSX.
 */
( function ( wp, cfg ) {
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el                = wp.element.createElement;
	var components        = wp.components || {};
	var blockEditor       = wp.blockEditor || {};
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps     = blockEditor.useBlockProps;
	// Wrap PanelBody so every inspector panel carries the mlsimport-block-inspector
	// class. That class scopes the shared "2025" admin styling (mlsimport-block-editor.css)
	// to MLSImport block panels only, without restyling core/other-plugin inspectors.
	var RawPanelBody      = components.PanelBody;
	var PanelBody         = RawPanelBody ? function ( props ) {
		var next = Object.assign( {}, props );
		next.className = ( next.className ? next.className + ' ' : '' ) + 'mlsimport-block-inspector';
		return el( RawPanelBody, next );
	} : RawPanelBody;
	var TextControl       = components.TextControl;
	var ToggleControl     = components.ToggleControl;
	var SelectControl     = components.SelectControl;
	var FormTokenField    = components.FormTokenField;
	var name              = ( cfg && cfg.name ) || 'mlsimport/listings';
	var iconUrl           = cfg && cfg.iconUrl;
	var searchFields      = ( cfg && cfg.searchFields ) || [];
	var taxonomies        = ( cfg && cfg.taxonomies ) || [];
	var allKeys           = searchFields.map( function ( f ) { return f.key; } );

	// Friendly sort presets -> the orderby/order attribute pair the render path uses.
	var SORT_OPTIONS = [
		{ label: 'Default',               value: '',             orderby: '',          order: '' },
		{ label: 'Newest',                value: 'list_date|desc', orderby: 'list_date', order: 'desc' },
		{ label: 'Oldest',                value: 'list_date|asc',  orderby: 'list_date', order: 'asc' },
		{ label: 'Price (low to high)',   value: 'price|asc',      orderby: 'price',     order: 'asc' },
		{ label: 'Price (high to low)',   value: 'price|desc',     orderby: 'price',     order: 'desc' },
		{ label: 'Bedrooms (most first)', value: 'bedrooms|desc',  orderby: 'bedrooms',  order: 'desc' }
	];

	// Same image as the admin sidebar menu icon. The PNG is 19x14, so it is left at
	// its natural size (width/height auto beats the width=24 height=24 attributes
	// wp.components.Icon clones onto it) — forcing a square stretched and blurred it.
	var icon = iconUrl
		? el( 'img', { src: iconUrl, alt: '', style: { width: 'auto', height: 'auto' } } )
		: 'admin-home';

	// The enabled set for the current attribute value. Empty search_fields means
	// every field shows (the default); otherwise only the listed keys are on.
	function enabledKeys( value ) {
		if ( ! value ) {
			return allKeys.slice();
		}
		var picked = String( value ).split( ',' ).map( function ( k ) { return k.trim(); } );
		return allKeys.filter( function ( k ) { return picked.indexOf( k ) !== -1; } );
	}

	// Flip one field on/off and serialize back to search_fields, preserving catalog
	// order. Every field on -> '' (the default = show all); every field off -> the
	// 'none' marker (an explicit empty form, distinct from the default); otherwise
	// the comma list. enabledKeys() round-trips all three (the marker matches no key).
	function toggleField( props, key, on ) {
		var current = enabledKeys( props.attributes.search_fields );
		var next    = allKeys.filter( function ( k ) {
			if ( k === key ) {
				return on;
			}
			return current.indexOf( k ) !== -1;
		} );
		var value = '';
		if ( next.length === 0 ) {
			value = 'none';
		} else if ( next.length !== allKeys.length ) {
			value = next.join( ',' );
		}
		props.setAttributes( { search_fields: value } );
	}

	// Split a stored comma list into trimmed, non-empty values.
	function splitList( value ) {
		return value ? String( value ).split( ',' ).map( function ( v ) { return v.trim(); } ).filter( Boolean ) : [];
	}

	// One taxonomy multi-select: tokens show term names, but the stored attribute
	// holds the field's own value (term name or slug). Map both ways so an existing
	// value round-trips to its label and a chosen label saves back as its value.
	function taxControl( props, tax ) {
		if ( ! FormTokenField ) {
			return null;
		}
		var valToLabel = {};
		var labelToVal = {};
		var suggestions = tax.options.map( function ( o ) {
			valToLabel[ o.value ] = o.label;
			labelToVal[ o.label ] = o.value;
			return o.label;
		} );
		var tokens = splitList( props.attributes[ tax.key ] ).map( function ( v ) {
			return valToLabel[ v ] || v;
		} );
		return el( FormTokenField, {
			key:          tax.key,
			label:        tax.label,
			value:        tokens,
			suggestions:  suggestions,
			__experimentalExpandOnFocus: true,
			onChange: function ( picked ) {
				var values = picked.map( function ( t ) {
					return Object.prototype.hasOwnProperty.call( labelToVal, t ) ? labelToVal[ t ] : t;
				} );
				var attr = {};
				attr[ tax.key ] = values.join( ',' );
				props.setAttributes( attr );
			}
		} );
	}

	// A min/max number pair writing two attributes (e.g. price_min/price_max).
	function rangeControl( props, label, minKey, maxKey ) {
		if ( ! TextControl ) {
			return null;
		}
		// Each half sits in its own flex:1 wrapper: without it the two TextControls
		// size to their content and the pair comes out lopsided (and can wrap).
		function field( key, ph ) {
			return el(
				'div',
				{ key: key, style: { flex: '1', minWidth: 0 } },
				el( TextControl, {
					label:    ph,
					type:     'number',
					min:      0,
					value:    props.attributes[ key ] || '',
					onChange: function ( v ) {
						var attr = {};
						attr[ key ] = v === '' ? '' : String( parseInt( v, 10 ) || '' );
						props.setAttributes( attr );
					}
				} )
			);
		}
		return el(
			'div',
			{ key: minKey, className: 'mlsimport-range' },
			el( 'p', { style: { margin: '0 0 4px' } }, label ),
			el( 'div', { style: { display: 'flex', gap: '8px' } }, field( minKey, 'Min' ), field( maxKey, 'Max' ) )
		);
	}

	// A single number attribute (beds, baths — used as a minimum).
	//
	// Parsed as a FLOAT, not an int: baths are half-values in every real MLS feed
	// ("2.5 baths"), and the query filters them as one (bathrooms >= %f). parseInt
	// silently truncated 2.5 to 2, so the control could not express what the filter
	// already supported. Beds are unaffected — the WHERE-builder casts them to int.
	function numberControl( props, key, label ) {
		if ( ! TextControl ) {
			return null;
		}
		return el( TextControl, {
			key:      key,
			label:    label,
			type:     'number',
			min:      0,
			step:     'any',
			value:    props.attributes[ key ] || '',
			onChange: function ( v ) {
				var attr = {};
				attr[ key ] = v === '' ? '' : String( parseFloat( v ) || '' );
				props.setAttributes( attr );
			}
		} );
	}

	// The sort preset select, reading/writing the orderby + order attribute pair.
	function sortControl( props ) {
		if ( ! SelectControl ) {
			return null;
		}
		var current = '';
		SORT_OPTIONS.forEach( function ( o ) {
			if ( o.orderby === ( props.attributes.orderby || '' ) && o.order === ( props.attributes.order || '' ) ) {
				current = o.value;
			}
		} );
		return el( SelectControl, {
			key:      'sort',
			label:    'Order',
			value:    current,
			options:  SORT_OPTIONS.map( function ( o ) { return { label: o.label, value: o.value }; } ),
			onChange: function ( v ) {
				var match = SORT_OPTIONS.filter( function ( o ) { return o.value === v; } )[ 0 ] || SORT_OPTIONS[ 0 ];
				props.setAttributes( { orderby: match.orderby, order: match.order } );
			}
		} );
	}

	// opts.noSort drops the Order control (a map has no pin order); opts.open expands
	// the panel on load (used when it is the ONLY panel, so the inspector isn't blank).
	function initialFilterPanel( props, title, opts ) {
		opts = opts || {};
		var children = taxonomies.map( function ( tax ) { return taxControl( props, tax ); } );
		children.push( rangeControl( props, 'Price', 'price_min', 'price_max' ) );
		children.push( numberControl( props, 'beds', 'Min beds' ) );
		children.push( numberControl( props, 'baths', 'Min baths' ) );
		// Agent post ID — narrows the set to that agent's listings (the mlsimport_list_agent_id
		// link the agent profile uses). Empty (or 0) means every agent.
		children.push( numberControl( props, 'agent', 'Agent (post ID)' ) );
		if ( ! opts.noSort ) {
			children.push( sortControl( props ) );
		}
		return el(
			PanelBody,
			{ key: 'initial-filter', title: title || 'Initial filter', initialOpen: !! opts.open },
			children
		);
	}

	// Layout panel — only the Half Map block has a map to place and a height to set;
	// the plain listings grid has neither, so this panel is added only when asked.
	function layoutPanel( props ) {
		return el(
			PanelBody,
			{ key: 'layout', title: 'Layout', initialOpen: false },
			SelectControl ? el( SelectControl, {
				key:      'map_side',
				label:    'Map side',
				value:    props.attributes.map_side || 'right',
				options:  [ { label: 'Right', value: 'right' }, { label: 'Left', value: 'left' } ],
				onChange: function ( v ) { props.setAttributes( { map_side: v } ); }
			} ) : null,
			TextControl ? el( TextControl, {
				key:      'height',
				label:    'Height (CSS, e.g. 100vh)',
				value:    props.attributes.height || '',
				onChange: function ( v ) { props.setAttributes( { height: v } ); }
			} ) : null
		);
	}

	// Search Results panel — the trimmed listings controls: per-page, a Show filter
	// bar toggle (off renders results only, the bar hidden), and fields-per-row. The
	// filters themselves come from the request, so there is no Initial filter panel.
	function resultsPanel( props, title ) {
		var showBar = props.attributes.show_filter_bar;
		return el(
			PanelBody,
			{ key: 'results', title: title || 'Search results', initialOpen: true },
			TextControl ? el( TextControl, {
				key: 'count',
				label: 'Properties per page',
				type: 'number',
				min: 1,
				value: props.attributes.count || '',
				onChange: function ( v ) {
					props.setAttributes( { count: v === '' ? '' : String( parseInt( v, 10 ) || '' ) } );
				}
			} ) : null,
			ToggleControl ? el( ToggleControl, {
				key: 'show_filter_bar',
				label: 'Show filter bar',
				checked: showBar !== '' && showBar !== '0',
				onChange: function ( on ) { props.setAttributes( { show_filter_bar: on ? '1' : '' } ); }
			} ) : null,
			SelectControl ? el( SelectControl, {
				key: 'fields_per_row',
				label: 'Search fields per row',
				value: props.attributes.fields_per_row || '4',
				options: [
					{ label: '3', value: '3' },
					{ label: '4', value: '4' },
					{ label: '5', value: '5' },
					{ label: '6', value: '6' }
				],
				onChange: function ( v ) { props.setAttributes( { fields_per_row: v } ); }
			} ) : null
		);
	}

	// Content Slider panel — the carousel's own controls: how many slides (limit) and
	// an optional explicit, ordered ID list (overrides the initial filter when set).
	// The filters themselves live in the shared Initial filter panel below it.
	function sliderPanel( props ) {
		return el(
			PanelBody,
			{ key: 'slider', title: 'Slider', initialOpen: true },
			TextControl ? el( TextControl, {
				key: 'limit',
				label: 'How many',
				type: 'number',
				min: 1,
				value: props.attributes.limit || '',
				onChange: function ( v ) {
					props.setAttributes( { limit: v === '' ? '' : String( parseInt( v, 10 ) || '' ) } );
				}
			} ) : null,
			TextControl ? el( TextControl, {
				key: 'ids',
				label: 'Explicit property IDs (comma list)',
				value: props.attributes.ids || '',
				onChange: function ( v ) { props.setAttributes( { ids: v } ); }
			} ) : null
		);
	}

	function inspector( props, block ) {
		if ( ! InspectorControls || ! PanelBody ) {
			return null;
		}
		var hasLayout = !! ( block && block.layout );
		var isResults = !! ( block && block.kind === 'results' );
		var isSlider  = !! ( block && block.kind === 'slider' );
		var isItemList = !! ( block && block.kind === 'item-list' );
		var isMap     = !! ( block && block.kind === 'map' );
		var enabled   = enabledKeys( props.attributes.search_fields );

		// Content Slider: its own Slider panel + the shared Initial filter panel (the
		// same taxonomy dropdowns / price / beds / order the Half Map exposes). No search
		// bar, so no per-field toggle panel.
		if ( isSlider ) {
			return el( InspectorControls, { key: 'inspector' }, [ sliderPanel( props ), initialFilterPanel( props ) ] );
		}

		// Map with Listings: ONLY the shared Initial filter panel — the filter that
		// decides which listings become pins. A map plots every match at its coordinates,
		// so there is no page size ("How many" never caps the query map — map_payload
		// strips limit/page), no order (pins have no sequence) and no explicit-ID surface.
		// The panel opens on load since it is the sole panel. Matches the Elementor widget.
		if ( isMap ) {
			return el( InspectorControls, { key: 'inspector' }, [ initialFilterPanel( props, 'Initial filters', { noSort: true, open: true } ) ] );
		}

		var fieldsPanel = el(
			PanelBody,
			{ key: 'fields', title: 'Search fields', initialOpen: false },
			searchFields.map( function ( f ) {
				return ToggleControl ? el( ToggleControl, {
					key: f.key,
					label: f.label,
					checked: enabled.indexOf( f.key ) !== -1,
					onChange: function ( on ) { toggleField( props, f.key, on ); }
				} ) : null;
			} )
		);

		// Property List: the Settings panel (per-page, show-bar, fields-per-row) + the
		// Initial filter presets + the per-field toggles. The same rich inspector the
		// Half Map uses, minus the map Layout panel.
		if ( isItemList ) {
			return el( InspectorControls, { key: 'inspector' }, [ resultsPanel( props, 'Settings' ), initialFilterPanel( props, 'Initial filters' ), fieldsPanel ] );
		}

		// Results block: just the trimmed panel + the per-field toggles.
		if ( isResults ) {
			return el( InspectorControls, { key: 'inspector' }, [ resultsPanel( props ), fieldsPanel ] );
		}

		var listingsPanel = el(
			PanelBody,
			{ key: 'listings', title: 'Listings', initialOpen: true },
			TextControl ? el( TextControl, {
				key: 'limit',
				label: 'Properties to display',
				type: 'number',
				min: 1,
				value: props.attributes.limit || '',
				onChange: function ( v ) {
					props.setAttributes( { limit: v === '' ? '' : String( parseInt( v, 10 ) || '' ) } );
				}
			} ) : null,
			SelectControl ? el( SelectControl, {
				key: 'fields_per_row',
				label: 'Search fields per row',
				value: props.attributes.fields_per_row || ( hasLayout ? '3' : '4' ),
				options: [
					{ label: '3', value: '3' },
					{ label: '4', value: '4' },
					{ label: '5', value: '5' },
					{ label: '6', value: '6' }
				],
				onChange: function ( v ) { props.setAttributes( { fields_per_row: v } ); }
			} ) : null
		);

		var panels = [ listingsPanel, initialFilterPanel( props ), fieldsPanel ];
		if ( hasLayout ) {
			panels.push( layoutPanel( props ) );
		}
		return el( InspectorControls, { key: 'inspector' }, panels );
	}

	// Every attribute the inspector writes must be declared here so the editor
	// serializes it and ServerSideRender forwards it to the PHP render_callback. The
	// Half Map block adds map_side/height (its Layout panel); the plain grid omits them.
	function buildAttributes( block ) {
		var hasLayout = !! ( block && block.layout );

		// Search Results saves only the refine-bar display config — no initial-filter
		// presets (the filters come from the request). Mirrors results_attributes() in PHP.
		if ( block && block.kind === 'results' ) {
			return {
				count:          { type: 'string', 'default': '12' },
				show_filter_bar: { type: 'string', 'default': '1' },
				fields_per_row: { type: 'string', 'default': '4' },
				search_fields:  { type: 'string', 'default': '' }
			};
		}

		// Content Slider saves the initial-filter presets the Slider + Initial filter
		// panels write (how-many, explicit IDs, price/beds/baths, order, and one attribute
		// per taxonomy) — no search-bar config. Mirrors slider_attributes() in PHP.
		if ( block && block.kind === 'slider' ) {
			var sliderAttrs = {
				limit:     { type: 'string', 'default': '6' },
				ids:       { type: 'string', 'default': '' },
				price_min: { type: 'string', 'default': '' },
				price_max: { type: 'string', 'default': '' },
				beds:      { type: 'string', 'default': '' },
				baths:     { type: 'string', 'default': '' },
				agent:     { type: 'string', 'default': '' },
				orderby:   { type: 'string', 'default': '' },
				order:     { type: 'string', 'default': '' }
			};
			taxonomies.forEach( function ( tax ) {
				sliderAttrs[ tax.key ] = { type: 'string', 'default': '' };
			} );
			return sliderAttrs;
		}

		// Property List saves the search-bar config (per-page, show-bar, fields-per-row,
		// per-field toggles) AND the initial-filter presets. Mirrors item_list_attributes() in PHP.
		if ( block && block.kind === 'item-list' ) {
			var itemAttrs = {
				count:           { type: 'string', 'default': '12' },
				show_filter_bar: { type: 'string', 'default': '1' },
				fields_per_row:  { type: 'string', 'default': '4' },
				search_fields:   { type: 'string', 'default': '' },
				price_min:       { type: 'string', 'default': '' },
				price_max:       { type: 'string', 'default': '' },
				beds:            { type: 'string', 'default': '' },
				baths:           { type: 'string', 'default': '' },
				agent:           { type: 'string', 'default': '' },
				orderby:         { type: 'string', 'default': '' },
				order:           { type: 'string', 'default': '' }
			};
			taxonomies.forEach( function ( tax ) { itemAttrs[ tax.key ] = { type: 'string', 'default': '' }; } );
			return itemAttrs;
		}

		// Map with Listings saves the map selection controls (how many, explicit IDs) AND
		// the initial-filter presets. Mirrors map_attributes() in PHP.
		if ( block && block.kind === 'map' ) {
			var mapAttrs = {
				count:     { type: 'string', 'default': '6' },
				ids:       { type: 'string', 'default': '' },
				price_min: { type: 'string', 'default': '' },
				price_max: { type: 'string', 'default': '' },
				beds:      { type: 'string', 'default': '' },
				baths:     { type: 'string', 'default': '' },
				agent:     { type: 'string', 'default': '' },
				orderby:   { type: 'string', 'default': '' },
				order:     { type: 'string', 'default': '' }
			};
			taxonomies.forEach( function ( tax ) { mapAttrs[ tax.key ] = { type: 'string', 'default': '' }; } );
			return mapAttrs;
		}

		var attributes = {
			limit:          { type: 'string', 'default': '12' },
			search_fields:  { type: 'string', 'default': '' },
			// Search-bar columns: 4-up for the full-width listings bar, 3-up for the
			// Half Map's narrow pane. Mirrors the PHP attribute defaults.
			fields_per_row: { type: 'string', 'default': hasLayout ? '3' : '4' },
			price_min:      { type: 'string', 'default': '' },
			price_max:      { type: 'string', 'default': '' },
			beds:           { type: 'string', 'default': '' },
			baths:          { type: 'string', 'default': '' },
			agent:          { type: 'string', 'default': '' },
			orderby:        { type: 'string', 'default': '' },
			order:          { type: 'string', 'default': '' }
		};
		taxonomies.forEach( function ( tax ) {
			attributes[ tax.key ] = { type: 'string', 'default': '' };
		} );
		if ( hasLayout ) {
			attributes.map_side = { type: 'string', 'default': 'right' };
			attributes.height   = { type: 'string', 'default': '100vh' };
		}
		return attributes;
	}

	// The blocks this script registers. Both share the listings inspector; the Half
	// Map adds a Layout panel. Localized from PHP; a single-block fallback keeps the
	// listings block working if the config predates the blocks array.
	var blocks = ( cfg && cfg.blocks ) || [
		{ name: name, title: 'MLS Listings', description: 'Display imported MLS listings in a filterable grid.', category: 'widgets', layout: false }
	];

	blocks.forEach( function ( block ) {
		wp.blocks.registerBlockType( block.name, {
			apiVersion:  2,
			title:       block.title,
			description: block.description,
			category:    block.category || 'widgets',
			icon:        icon,
			attributes:  buildAttributes( block ),
			edit: function ( props ) {
				// apiVersion 2 requires useBlockProps() on the edit root, or the block has
				// no selectable wrapper in the editor (and its inspector never shows).
				var blockProps = useBlockProps ? useBlockProps() : {};
				// pointer-events:none on the preview lets a click fall through to the block
				// wrapper (so the block selects) instead of following a listing card's link
				// and navigating the editor away.
				var preview = wp.serverSideRender
					? el( 'div', { key: 'preview', style: { pointerEvents: 'none' } }, el( wp.serverSideRender, { block: block.name, attributes: props.attributes } ) )
					: el( 'p', { key: 'preview' }, block.title );
				return el( 'div', blockProps, inspector( props, block ), preview );
			},
			// Dynamic block — output comes from the server render_callback.
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp, window.MLSImportBlock );
