/**
 * Editor registration for the standalone single-property section blocks.
 *
 * Plain ES5, no build step. Loops the localized MLSImportSections list and
 * registers one dynamic block per section. Each block renders server-side via
 * ServerSideRender (the PHP render_callback is the single source of markup).
 */
( function ( blocks, element, serverSideRender, blockEditor ) {
	// Bail out if the block API or the localized section list isn't available
	if ( ! blocks || ! window.MLSImportSections ) {
		return;
	}

	// Local aliases and the block icon source
	var el            = element.createElement;
	var useBlockProps = ( blockEditor || {} ).useBlockProps;
	var iconUrl       = window.MLSImportSectionsIcon || '';

	// Block toolbar/menu icon: the localized image URL, else a Dashicon fallback.
	// The PNG is 19x14, so it keeps its natural size (width/height auto beats the
	// width=24 height=24 attributes wp.components.Icon clones onto it) — a forced
	// square stretched it to 24x24 and blurred it.
	var icon = iconUrl
		? el( 'img', { src: iconUrl, alt: '', style: { width: 'auto', height: 'auto' } } )
		: 'admin-home';

	// Register one dynamic block per localized property section
	window.MLSImportSections.forEach( function ( section ) {
		// Namespaced block name, e.g. "mlsimport/property-gallery"
		var blockName = 'mlsimport/property-' + section.slug;

		blocks.registerBlockType( blockName, {
			apiVersion: 2,
			title: section.title,
			icon: icon,
			category: 'mlsimport-property',
			attributes: {
				// Optional explicit property id; 0 means "use the current post"
				id: { type: 'number', default: 0 }
			},
			// Editor view: a server-rendered preview of the section
			edit: function ( props ) {
				// apiVersion 2 requires useBlockProps() on the edit root, or the
				// block has no selectable wrapper in the editor.
				var blockProps = useBlockProps ? useBlockProps() : {};
				// Render the PHP markup for this block/attributes inside the wrapper
				return el( 'div', blockProps, el( serverSideRender, {
					block: blockName,
					attributes: props.attributes
				} ) );
			},
			// Dynamic block: markup comes from PHP, so nothing is saved to post content
			save: function () {
				return null;
			}
		} );
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor );
