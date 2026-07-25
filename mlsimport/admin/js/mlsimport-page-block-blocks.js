/**
 * Editor registration for the standalone page blocks.
 *
 * Plain ES5, no build step. Loops the localized MLSImportPageBlocks manifest and
 * registers one dynamic block per page block. Each block renders server-side via
 * ServerSideRender (the PHP render_callback is the single source of markup) and
 * builds its inspector controls from the localized arg schema, so adding a block
 * in PHP needs no change here.
 */
( function ( blocks, element, components, blockEditor, serverSideRender ) {
	// Bail out if the block API or the localized manifest isn't available
	if ( ! blocks || ! window.MLSImportPageBlocks ) {
		return;
	}

	// Local aliases for the WP editor primitives used below
	var el                = element.createElement;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps     = blockEditor.useBlockProps;
	// Wrap PanelBody so every inspector panel carries the mlsimport-block-inspector
	// class, scoping the shared "2025" admin styling (mlsimport-block-editor.css) to
	// MLSImport block panels only, without restyling core/other-plugin inspectors.
	var RawPanelBody      = components.PanelBody;
	var PanelBody         = RawPanelBody ? function ( props ) {
		var next = Object.assign( {}, props );
		next.className = ( next.className ? next.className + ' ' : '' ) + 'mlsimport-block-inspector';
		return el( RawPanelBody, next );
	} : RawPanelBody;
	var TextControl       = components.TextControl;
	var TextareaControl   = components.TextareaControl;
	var SelectControl     = components.SelectControl;
	var ToggleControl     = components.ToggleControl;
	var FormTokenField    = components.FormTokenField;
	var Button            = components.Button;
	var BaseControl       = components.BaseControl;
	var ColorPalette      = components.ColorPalette;
	var ColorIndicator    = components.ColorIndicator;
	var Dropdown          = components.Dropdown;
	var MediaUpload       = blockEditor.MediaUpload;
	var MediaUploadCheck  = blockEditor.MediaUploadCheck;
	var iconUrl           = window.MLSImportPageBlocksIcon || '';

	// Block toolbar/menu icon: the localized image URL, else a Dashicon fallback.
	// The PNG is 19x14, so it keeps its natural size (width/height auto beats the
	// width=24 height=24 attributes wp.components.Icon clones onto it) — a forced
	// square stretched it to 24x24 and blurred it.
	var icon = iconUrl
		? el( 'img', { src: iconUrl, alt: '', style: { width: 'auto', height: 'auto' } } )
		: 'admin-home';

	/**
	 * Build a Gutenberg attribute schema from the localized field list.
	 *
	 * The declared attribute type MUST match what PHP declares, or values fail
	 * schema validation at render time and fall back to their default.
	 *
	 * @param {Array} fields - Field descriptors (key, type, default, ...).
	 * @return {Object} Attribute schema keyed by field key.
	 */
	function buildAttributes( fields ) {
		var attrs = {};
		fields.forEach( function ( f ) {
			if ( f.type === 'number' ) {
				attrs[ f.key ] = { type: 'number', 'default': f['default'] !== '' ? Number( f['default'] ) : 0 };
			} else if ( f.type === 'toggle' ) {
				attrs[ f.key ] = { type: 'boolean', 'default': !! f['default'] };
			} else if ( f.type === 'repeater' ) {
				// A repeater stores an array of rows. It MUST be declared 'array' (matching
				// the PHP attribute schema) or the editor serializes the rows into a
				// string-typed attribute that never round-trips — the rows are lost on reload.
				attrs[ f.key ] = { type: 'array', 'default': Array.isArray( f['default'] ) ? f['default'] : [] };
			} else {
				// Includes 'multiselect': like every taxonomy filter, it stores a comma
				// list of term ids as a string (the FormTokenField serializes to it).
				attrs[ f.key ] = { type: 'string', 'default': String( f['default'] || '' ) };
			}
		} );
		return attrs;
	}

	// One control for a single value (used by top-level fields and repeater rows).
	/**
	 * Render the appropriate inspector control for a single field value.
	 *
	 * @param {Object}   f        - Field descriptor (type, label, options, ...).
	 * @param {*}        value    - Current value.
	 * @param {Function} onChange - Called with the new value on edit.
	 * @param {string}   reactKey - React key for the control element.
	 * @return {Object} A wp.element control element.
	 */
	function inputControl( f, value, onChange, reactKey ) {
		if ( f.type === 'multiselect' && FormTokenField ) {
			// The SAME control the Property List "Initial filter" taxonomy pickers use
			// (taxControl in mlsimport-standalone-block.js): a FormTokenField whose
			// tokens show term NAMES while the stored attribute is a comma list of term
			// IDs. f.options is an { id: name } object localized from PHP; map both ways
			// so a saved value round-trips to its label and a chosen label saves its id.
			var opts        = f.options || {};
			var valToLabel  = {};
			var labelToVal  = {};
			var suggestions = Object.keys( opts ).map( function ( id ) {
				valToLabel[ id ]         = opts[ id ];
				labelToVal[ opts[ id ] ] = id;
				return opts[ id ];
			} );
			var tokens = ( value ? String( value ).split( ',' ) : [] ).map( function ( v ) {
				v = v.trim();
				return valToLabel[ v ] || v;
			} ).filter( Boolean );
			return el( FormTokenField, {
				key: reactKey,
				label: f.label,
				value: tokens,
				suggestions: suggestions,
				__experimentalExpandOnFocus: true,
				onChange: function ( picked ) {
					var ids = picked.map( function ( t ) {
						return Object.prototype.hasOwnProperty.call( labelToVal, t ) ? labelToVal[ t ] : t;
					} );
					onChange( ids.join( ',' ) );
				}
			} );
		}
		// Fixed-choice field: render a dropdown from the { value: label } options
		if ( f.type === 'select' && f.options ) {
			var options = Object.keys( f.options ).map( function ( key ) {
				return { value: key, label: f.options[ key ] };
			} );
			return el( SelectControl, { key: reactKey, label: f.label, value: value, options: options, onChange: onChange } );
		}
		// Colour swatch. The attribute stores the plain CSS colour string PHP prints
		// inline, so an unset colour is '' and the stylesheet's own default wins.
		//
		// Collapsed into a one-line swatch + value button rather than an always-open
		// ColorPalette: the palette grid, its "Custom" toggle and the hex readout take
		// most of the inspector, and this block has six other controls under it. The
		// palette itself is unchanged — it just lives in the popover now.
		if ( f.type === 'color' && ColorPalette && Dropdown ) {
			return el( BaseControl, { key: reactKey, label: f.label },
				el( Dropdown, {
					contentClassName: 'mlsimport-color-popover',
					popoverProps: { placement: 'left-start' },
					renderToggle: function ( toggle ) {
						return el( Button, {
							variant: 'secondary',
							onClick: toggle.onToggle,
							'aria-expanded': toggle.isOpen,
							style: { display: 'flex', alignItems: 'center', gap: '8px', width: '100%' }
						},
							ColorIndicator ? el( ColorIndicator, { colorValue: value || 'transparent' } ) : null,
							// An empty value is not "no colour" but "whatever the stylesheet
							// already uses", so it reads as Default rather than as blank.
							el( 'span', null, value ? value : 'Default' )
						);
					},
					renderContent: function () {
						return el( ColorPalette, {
							value: value || '',
							onChange: function ( c ) {
								onChange( c || '' );
							}
						} );
					}
				} )
			);
		}
		// Media picker. The attribute stores the image URL (a string), not the
		// attachment id, so the render fn can print it without a second lookup and
		// the Elementor side — which hands back { url, id } — normalises to the same.
		if ( f.type === 'media' && MediaUpload && MediaUploadCheck ) {
			return el( BaseControl, { key: reactKey, label: f.label },
				el( MediaUploadCheck, null,
					el( MediaUpload, {
						allowedTypes: [ 'image' ],
						value: value,
						onSelect: function ( media ) {
							onChange( media && media.url ? media.url : '' );
						},
						render: function ( open ) {
							return el( 'div', null,
								value ? el( 'img', { src: value, style: { maxWidth: '48px', display: 'block', marginBottom: '6px' } } ) : null,
								el( Button, { variant: 'secondary', onClick: open.open }, value ? 'Replace' : 'Select image' ),
								value ? el( Button, { isDestructive: true, isSmall: true, onClick: function () { onChange( '' ); } }, 'Remove' ) : null
							);
						}
					} )
				)
			);
		}
		if ( f.type === 'toggle' ) {
			// Store a real BOOLEAN, because that is what the attribute is declared as
			// (buildAttributes below, and Mlsimport_Page_Block_Blocks::attributes in PHP).
			//
			// This used to write the STRINGS 'yes' / '' — and a string in a boolean-typed
			// attribute never reaches the render callback at all. WP_Block_Type::
			// prepare_attributes_for_render() validates every attribute against its schema,
			// UNSETS anything that fails, and then fills the default back in. So the value
			// was silently discarded and the default restored on every render: switching a
			// toggle OFF did nothing whatsoever. That is every toggle in every generic page
			// block — Show filter bar, Featured agents only, Show job title, Show office name,
			// Hide terms with no listings, Show listing count, Display as auto grid.
			//
			// The PHP side already normalises with (string) — (string) true === '1',
			// (string) false === '' — so booleans are what it wants, and the legacy 'yes'/'1'
			// strings a shortcode may still pass keep working.
			return el( ToggleControl, { key: reactKey, label: f.label, checked: value === true || value === 'yes' || value === '1', onChange: function ( c ) {
				onChange( !! c );
			} } );
		}
		// Textarea: a multi-line input, so a long comma list (e.g. many post IDs)
		// is fully visible while editing.
		if ( f.type === 'textarea' && TextareaControl ) {
			return el( TextareaControl, {
				key: reactKey,
				label: f.label,
				value: value === undefined || value === null ? '' : value,
				rows: 5,
				onChange: function ( v ) {
					onChange( v );
				}
			} );
		}
		// Default: a text (or numeric) input; numbers are coerced back to Number
		return el( TextControl, {
			key: reactKey,
			label: f.label,
			type: f.type === 'number' ? 'number' : 'text',
			value: value === undefined || value === null ? '' : value,
			onChange: function ( v ) {
				onChange( f.type === 'number' ? Number( v ) : v );
			}
		} );
	}

	// A repeater: an editable list of rows, each row a control per sub-field.
	/**
	 * Render a repeater field: an add/remove list of rows, one control per
	 * sub-field, writing the whole array back to the block attribute.
	 *
	 * @param {Object} f     - Repeater field descriptor (with nested .fields).
	 * @param {Object} props - Gutenberg edit() props (attributes, setAttributes).
	 * @return {Object} A wp.element repeater element.
	 */
	function repeaterControl( f, props ) {
		// Current rows (default to empty) and the per-row sub-field descriptors
		var rows = Array.isArray( props.attributes[ f.key ] ) ? props.attributes[ f.key ] : [];
		var subs = f.fields || [];

		// Persist a new rows array back to the block attribute
		function commit( next ) {
			var update = {};
			update[ f.key ] = next;
			props.setAttributes( update );
		}
		// Immutably update one cell (row i, sub-field key) and commit
		function setCell( i, key, val ) {
			var copy = rows.map( function ( r, idx ) {
				if ( idx !== i ) {
					return r;
				}
				// Clone the target row so state stays immutable, then set the changed cell
				var row = {};
				Object.keys( r || {} ).forEach( function ( k ) { row[ k ] = r[ k ]; } );
				row[ key ] = val;
				return row;
			} );
			commit( copy );
		}
		// Append a new row pre-filled with each sub-field's default
		function addRow() {
			var def = {};
			subs.forEach( function ( sf ) { def[ sf.key ] = sf['default']; } );
			commit( rows.concat( [ def ] ) );
		}
		// Drop the row at index i
		function removeRow( i ) {
			commit( rows.filter( function ( _, idx ) { return idx !== i; } ) );
		}
		// Move the row at `from` to sit at `to`, keeping every other row's relative
		// order. The rendered form follows this array order directly, so reordering
		// here IS the field order on the front end — no separate order attribute.
		function moveRow( from, to ) {
			if ( from === to || from < 0 || to < 0 || from >= rows.length || to >= rows.length ) {
				return;
			}
			var next  = rows.slice();
			var moved = next.splice( from, 1 )[ 0 ];
			next.splice( to, 0, moved );
			commit( next );
		}

		// Build one bordered row block per row: a control per sub-field plus a Remove
		// button. The row itself is the drag source — drag it onto another row to put
		// it there. The moved index travels in dataTransfer rather than in a closure
		// variable, because a re-render between dragstart and drop would otherwise
		// leave a stale index behind.
		var rowEls = rows.map( function ( row, i ) {
			var cells = subs.map( function ( sf ) {
				return inputControl( sf, row ? row[ sf.key ] : sf['default'], function ( v ) {
					setCell( i, sf.key, v );
				}, sf.key );
			} );
			cells.push( el( 'div', { key: '__actions', style: { display: 'flex', alignItems: 'center', gap: '4px', marginTop: '8px' } },
				el( Button, {
					key: '__up',
					isSmall: true,
					variant: 'tertiary',
					disabled: i === 0,
					'aria-label': 'Move up',
					onClick: function () { moveRow( i, i - 1 ); }
				}, '↑' ),
				el( Button, {
					key: '__down',
					isSmall: true,
					variant: 'tertiary',
					disabled: i === rows.length - 1,
					'aria-label': 'Move down',
					onClick: function () { moveRow( i, i + 1 ); }
				}, '↓' ),
				el( Button, {
					key: '__rm',
					isDestructive: true,
					isSmall: true,
					onClick: function () { removeRow( i ); }
				}, 'Remove' )
			) );
			return el( 'div', {
				key: i,
				className: 'mlsimport-repeater-row',
				draggable: true,
				onDragStart: function ( e ) {
					e.dataTransfer.setData( 'text/plain', String( i ) );
					e.dataTransfer.effectAllowed = 'move';
				},
				onDragOver: function ( e ) { e.preventDefault(); },
				onDrop: function ( e ) {
					e.preventDefault();
					var from = parseInt( e.dataTransfer.getData( 'text/plain' ), 10 );
					if ( ! isNaN( from ) ) {
						moveRow( from, i );
					}
				},
				style: { border: '1px solid #e0e0e0', borderRadius: '4px', padding: '10px', marginBottom: '10px', cursor: 'move' }
			}, cells );
		} );

		// Wrap the label, the rows, and the "+ add" button
		return el( 'div', { key: f.key, className: 'mlsimport-repeater' },
			el( 'p', { key: '__lbl' }, el( 'strong', null, f.label ) ),
			rowEls,
			// Full-width and solid: it is the panel's one primary action, and a small
			// outline button floating under a stack of bordered rows reads as part of
			// the last row rather than as "add another".
			el( Button, {
				key: '__add',
				variant: 'primary',
				onClick: addRow,
				style: { width: '100%', justifyContent: 'center' }
			}, '+ ' + f.label )
		);
	}

	/**
	 * Dispatch to the right control for a top-level field and wire it to setAttributes.
	 *
	 * @param {Object} f     - Field descriptor.
	 * @param {Object} props - Gutenberg edit() props.
	 * @return {Object} A wp.element control element.
	 */
	function controlFor( f, props ) {
		// Repeaters manage their own array attribute
		if ( f.type === 'repeater' ) {
			return repeaterControl( f, props );
		}
		// Scalar field: read the attribute and write edits straight back
		return inputControl( f, props.attributes[ f.key ], function ( v ) {
			var update = {};
			update[ f.key ] = v;
			props.setAttributes( update );
		}, f.key );
	}

	// Register one dynamic block per entry in the localized manifest
	window.MLSImportPageBlocks.forEach( function ( block ) {
		var blockName = 'mlsimport/' + block.slug;
		var fields    = block.args || [];

		blocks.registerBlockType( blockName, {
			apiVersion: 2,
			title: block.title,
			icon: icon,
			category: 'mlsimport-real-estate',
			attributes: buildAttributes( fields ),
			// Editor view: inspector controls + a non-interactive server-rendered preview
			edit: function ( props ) {
				// apiVersion 2 requires the edit root to carry useBlockProps(), or the
				// editor never builds the selectable block wrapper (block unselectable,
				// so its InspectorControls — which only show on selection — never appear).
				var blockProps = useBlockProps ? useBlockProps() : {};
				var inspector = el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: block.title, initialOpen: true },
						fields.map( function ( f ) {
							return controlFor( f, props );
						} )
					)
				);
				// Wrap the preview so its links/forms can't be clicked: the card markup
				// is a full anchor, and an inline SSR preview would otherwise navigate
				// the editor away (to the property permalink / site root) instead of
				// selecting the block. pointer-events:none lets the click fall through
				// to the block wrapper so the block selects normally.
				var preview = el( 'div', { key: 'preview', style: { pointerEvents: 'none' } },
					el( serverSideRender, {
						block: blockName,
						attributes: props.attributes
					} )
				);
				return el( 'div', blockProps, inspector, preview );
			},
			// Dynamic block: markup comes from PHP, so nothing is saved to post content
			save: function () {
				return null;
			}
		} );
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender );
