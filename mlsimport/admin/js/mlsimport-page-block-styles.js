/**
 * The "Look" sidebar panel of the MLSImport page blocks (block editor).
 *
 * Every page block stores its look in a `styles` attribute: an object of
 * option => value, with the option names the AI tools (update-page-block) use
 * (price_color, columns, button_background, ...). This panel lets a person
 * see and change those same values by hand. The options of each block come
 * from PHP (MLSImportBlockStyleOptions, localized in
 * includes/standalone/page-block-styles.php): one { key, label, kind } per
 * option, kind 'color' (a hex color), 'number' (whole pixels) or 'columns'
 * (1 to 6). The front end turns the attribute into scoped CSS; this file
 * never writes CSS.
 *
 * Both editor scripts (mlsimport-standalone-block.js and
 * mlsimport-page-block-blocks.js) call window.MLSImportBlockStyles.panel(
 * props, blockName ) and add the result to their InspectorControls. Plain
 * ES5, no build step.
 */
( function ( wp ) {
	if ( ! wp || ! wp.element || ! wp.components ) {
		return;
	}

	var el             = wp.element.createElement;
	var components     = wp.components;
	var PanelBody      = components.PanelBody;
	var TextControl    = components.TextControl;
	var BaseControl    = components.BaseControl;
	var Button         = components.Button;
	var ColorPalette   = components.ColorPalette;
	var ColorIndicator = components.ColorIndicator;
	var Dropdown       = components.Dropdown;

	/**
	 * The style options of one block, as localized from PHP.
	 *
	 * @param {string} blockName Registered block name, e.g. mlsimport/item-list.
	 * @return {Array} [ { key, label, kind }, ... ]; [] for a block without styles.
	 */
	function optionsOf( blockName ) {
		var all = window.MLSImportBlockStyleOptions || {};
		return Array.isArray( all[ blockName ] ) ? all[ blockName ] : [];
	}

	/**
	 * Write one style: a set value is stored, an empty one removes the option
	 * (so the theme's own look returns), and the attribute is replaced as a
	 * whole so the editor sees the change.
	 *
	 * @param {Object} props Gutenberg edit() props.
	 * @param {string} key   Option name.
	 * @param {*}      value The new value, or '' / undefined to remove.
	 */
	function setStyle( props, key, value ) {
		var current = props.attributes.styles && typeof props.attributes.styles === 'object' ? props.attributes.styles : {};
		var next    = {};
		Object.keys( current ).forEach( function ( k ) {
			if ( k !== key ) {
				next[ k ] = current[ k ];
			}
		} );
		if ( value !== '' && value !== undefined && value !== null ) {
			next[ key ] = value;
		}
		props.setAttributes( { styles: next } );
	}

	/**
	 * The theme's color palette, as the editor knows it, so the swatches are
	 * the site's own colors (plus a custom picker). [] when unavailable.
	 */
	function paletteColors() {
		try {
			var settings = wp.data.select( 'core/block-editor' ).getSettings();
			return Array.isArray( settings.colors ) ? settings.colors : [];
		} catch ( e ) {
			return [];
		}
	}

	/**
	 * A color option: a one-line swatch + value button opening the palette
	 * (the same control the generic page blocks use for their colors). An
	 * unset option reads "Default": the stylesheet's own color.
	 */
	function colorControl( props, option, value ) {
		if ( ! ColorPalette || ! Dropdown ) {
			return null;
		}
		return el( BaseControl, { key: option.key, label: option.label },
			el( Dropdown, {
				contentClassName: 'mlsimport-color-popover',
				popoverProps: { placement: 'left-start' },
				renderToggle: function ( toggle ) {
					return el( Button, {
						variant: 'secondary',
						onClick: toggle.onToggle,
						'aria-expanded': toggle.isOpen,
						'aria-label': option.label,
						style: { display: 'flex', alignItems: 'center', gap: '8px', width: '100%' }
					},
						ColorIndicator ? el( ColorIndicator, { colorValue: value || 'transparent' } ) : null,
						el( 'span', null, value ? value : 'Default' )
					);
				},
				renderContent: function () {
					return el( ColorPalette, {
						colors: paletteColors(),
						value: value || '',
						onChange: function ( c ) {
							setStyle( props, option.key, c || '' );
						}
					} );
				}
			} )
		);
	}

	/**
	 * A number option: a numeric input. Columns are 1 to 6; every other
	 * number is whole pixels, 0 or more. Clearing the input removes the option.
	 */
	function numberControl( props, option, value ) {
		if ( ! TextControl ) {
			return null;
		}
		var columns = option.kind === 'columns';
		return el( TextControl, {
			key:      option.key,
			label:    option.label,
			type:     'number',
			min:      columns ? 1 : 0,
			max:      columns ? 6 : undefined,
			value:    value === undefined || value === null ? '' : value,
			onChange: function ( v ) {
				var n = parseInt( v, 10 );
				setStyle( props, option.key, v === '' || isNaN( n ) ? '' : n );
			}
		} );
	}

	/**
	 * The "Look" panel of one block, or null when the block has no style
	 * options (or the editor lacks the components).
	 *
	 * @param {Object} props     Gutenberg edit() props (attributes, setAttributes).
	 * @param {string} blockName Registered block name.
	 * @return {Object|null} A PanelBody element for InspectorControls.
	 */
	function panel( props, blockName ) {
		var options = optionsOf( blockName );
		if ( ! PanelBody || ! options.length ) {
			return null;
		}
		var styles = props.attributes.styles && typeof props.attributes.styles === 'object' ? props.attributes.styles : {};
		return el(
			PanelBody,
			{ key: 'look', title: 'Look', initialOpen: false, className: 'mlsimport-block-inspector' },
			options.map( function ( option ) {
				return option.kind === 'color'
					? colorControl( props, option, styles[ option.key ] )
					: numberControl( props, option, styles[ option.key ] );
			} )
		);
	}

	window.MLSImportBlockStyles = { panel: panel };
} )( window.wp );
