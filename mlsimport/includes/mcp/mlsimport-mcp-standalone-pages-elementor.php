<?php
/**
 * AI tools (MCP), standalone mode only: the Elementor side of create-page.
 *
 * Turns the page blocks the AI sent (slug + options, the names of
 * list-page-blocks) into the Elementor document the editor saves: one
 * container holding one MLSImport widget per block. The widget settings
 * follow Elementor's own conventions, which differ from the block schema in
 * four places, all handled here and nowhere else:
 *   - a toggle is 'yes' or '' (Elementor's switcher), not true/false;
 *   - the Featured Property id is the control property_id
 *     (Mlsimport_Page_Block_Elementor::control_key(), Backbone's "id" trap);
 *   - the Property List and Half Map widgets have an Order dropdown
 *     (mlsimport_sort = "orderby|order") instead of the orderby/order pair;
 *   - the same two widgets have one on/off switcher per search field
 *     (mlsimport_field_<key>) instead of the search_fields comma list.
 *     See Mlsimport_Listing_Search_Bar_Controls in
 *     includes/standalone/elementor/class-mlsimport-elementor-page-block-widget.php.
 * The document is written with Elementor's document save, so Elementor sets
 * its own meta (edit mode, template type, version) and caches as the editor
 * would.
 *
 * Public interface:
 *   mlsimport_mcp_elementor_settings( $slug, $options )   one widget's settings
 *   mlsimport_mcp_elementor_document( $rows )             the elements tree
 *   mlsimport_mcp_elementor_save( $page_id, $rows )       write it to a page
 *   mlsimport_mcp_elementor_page( $page_id )              is the page Elementor's?
 *   mlsimport_mcp_elementor_append( $page_id, $rows )     add to the page's document
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The widgets whose panel replaces orderby/order and search_fields with the
 * Order dropdown and the per-field switchers (the search-bar trait).
 */
const MLSIMPORT_MCP_ELEMENTOR_SEARCH_BAR_BLOCKS = array( 'item_list', 'half_map' );

/**
 * One widget's settings from the block options, in Elementor's conventions.
 *
 * Step by step:
 * 1. Rename the key when Elementor's control has another name (id -> property_id).
 * 2. A boolean becomes 'yes' or ''; a list stays a list (the term pickers);
 *    anything else is stored as text.
 * 3. For the search-bar widgets, fold orderby/order into mlsimport_sort and
 *    spread search_fields over the per-field switchers.
 *
 * @param string $slug    Page block slug.
 * @param array  $options Option name => value, as the AI sent them.
 * @return array The settings to store in the widget.
 */
function mlsimport_mcp_elementor_settings( string $slug, array $options ): array {
	$settings = array();
	foreach ( $options as $key => $value ) {
		$control = Mlsimport_Page_Block_Elementor::control_key( (string) $key );
		if ( is_bool( $value ) ) {
			$settings[ $control ] = $value ? 'yes' : '';
		} elseif ( is_array( $value ) ) {
			$settings[ $control ] = array_values( array_map( 'strval', $value ) );
		} else {
			$settings[ $control ] = (string) $value;
		}
	}

	if ( ! in_array( $slug, MLSIMPORT_MCP_ELEMENTOR_SEARCH_BAR_BLOCKS, true ) ) {
		return $settings;
	}

	// The Order dropdown: "orderby|order", or '' for the default order.
	if ( isset( $settings['orderby'] ) || isset( $settings['order'] ) ) {
		$orderby                    = (string) ( $settings['orderby'] ?? '' );
		$settings['mlsimport_sort'] = '' === $orderby ? '' : $orderby . '|' . (string) ( $settings['order'] ?? 'desc' );
		unset( $settings['orderby'], $settings['order'] );
	}

	// The per-field switchers: '' or missing shows every field, 'none' no
	// field, otherwise the listed ones.
	if ( isset( $settings['search_fields'] ) ) {
		$list = (string) $settings['search_fields'];
		$on   = '' === $list ? null : array_map( 'trim', explode( ',', $list ) );
		foreach ( array_keys( Mlsimport_Page_Block_Search_Fields::labels() ) as $field ) {
			$settings[ 'mlsimport_field_' . $field ] = null === $on || in_array( $field, $on, true ) ? 'yes' : '';
		}
		unset( $settings['search_fields'] );
	}
	return $settings;
}

/**
 * The Elementor elements tree: one container holding one widget per block.
 *
 * @param array $rows The checked blocks: list of [ slug, options ].
 * @return array The elements tree Elementor stores in _elementor_data.
 */
function mlsimport_mcp_elementor_document( array $rows ): array {
	// Elementor element ids are 7 hex characters, unique within the document.
	$element_id = static function (): string {
		return dechex( wp_rand( 0x1000000, 0xfffffff ) );
	};

	$widgets = array();
	foreach ( $rows as $row ) {
		$widgets[] = array(
			'id'         => $element_id(),
			'elType'     => 'widget',
			'settings'   => mlsimport_mcp_elementor_settings( $row['slug'], $row['options'] ),
			'elements'   => array(),
			'widgetType' => 'mlsimport-' . str_replace( '_', '-', $row['slug'] ),
		);
	}

	return array(
		array(
			'id'       => $element_id(),
			'elType'   => 'container',
			'settings' => array(),
			'elements' => $widgets,
			'isInner'  => false,
		),
	);
}

/**
 * Write the blocks to a page as its Elementor document.
 *
 * Step by step:
 * 1. Mark the page as built with Elementor (the edit mode meta the front end
 *    and the editor test first); the document save does not set it.
 * 2. Elementor's document save writes _elementor_data, the template type and
 *    version meta, and refreshes its caches: the write the editor's Update
 *    button makes.
 *
 * @param int   $page_id The page.
 * @param array $rows    The checked blocks: list of [ slug, options ].
 * @return string|WP_Error The Elementor edit link of the page, or the refusal.
 */
function mlsimport_mcp_elementor_save( int $page_id, array $rows ) {
	$document = \Elementor\Plugin::instance()->documents->get( $page_id );
	if ( $document ) {
		$document->set_is_built_with_elementor( true );
	}
	$saved = $document && $document->save(
		array(
			'elements' => mlsimport_mcp_elementor_document( $rows ),
			'settings' => array(),
		)
	);
	if ( ! $saved ) {
		return new WP_Error( 'mlsimport_elementor_save_failed', __( 'Elementor did not accept the page document.', 'mlsimport' ) );
	}
	return (string) $document->get_edit_url();
}

/**
 * Is the page built with Elementor? Elementor must be active and the page's
 * document marked as built with it (the mark the Elementor editor sets on
 * its first save and create-page sets before saving).
 *
 * @param int $page_id The page.
 * @return bool True when the page's blocks belong in its Elementor document.
 */
function mlsimport_mcp_elementor_page( int $page_id ): bool {
	if ( ! class_exists( '\Elementor\Plugin' ) ) {
		return false;
	}
	$document = \Elementor\Plugin::instance()->documents->get( $page_id );
	return $document && $document->is_built_with_elementor();
}

/**
 * Add the blocks to the end of a page's Elementor document.
 *
 * Step by step:
 * 1. Read the elements the document has (its published data).
 * 2. Append one new container holding one widget per block, so the page's
 *    own containers or sections are left exactly as they are.
 * 3. Save through Elementor's document save; no settings are sent, so the
 *    page settings stay as they are.
 *
 * @param int   $page_id The page.
 * @param array $rows    The checked blocks: list of [ slug, options ].
 * @return string|WP_Error The Elementor edit link of the page, or the refusal.
 */
function mlsimport_mcp_elementor_append( int $page_id, array $rows ) {
	$document = \Elementor\Plugin::instance()->documents->get( $page_id );
	$saved    = $document && $document->save(
		array(
			'elements' => array_merge( (array) $document->get_elements_data(), mlsimport_mcp_elementor_document( $rows ) ),
		)
	);
	if ( ! $saved ) {
		return new WP_Error( 'mlsimport_elementor_save_failed', __( 'Elementor did not accept the page document.', 'mlsimport' ) );
	}
	return (string) $document->get_edit_url();
}
