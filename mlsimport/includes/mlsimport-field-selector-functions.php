<?php
/**
 * Render the form-free Field Configuration interface.
 *
 * PHP renders the current active metadata in authoritative custom order. The
 * browser may search, filter, sort, bulk-select visible rows, and enqueue compact
 * commands, but it does not submit a mirrored WordPress settings form. Dormant
 * fields remain in storage through the domain module and are intentionally not
 * rendered until their MLS metadata returns.
 *
 * @package    MLSImport
 * @subpackage MLSImport/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order current metadata by the saved custom sequence.
 *
 * Only keys present in current metadata are returned. Any metadata field absent
 * from the normalized order is appended alphabetically as a safe read-only
 * fallback; metadata reconciliation normally added it before this render.
 *
 * @param array $fields  Current MLS metadata keyed by field name.
 * @param array $options Normalized Field Configuration.
 * @param array $params  Render parameters retained for the public signature.
 * @return array Ordered active metadata.
 */
function prepare_mls_fields_for_display( $fields, $options, $params = array() ) {
	$fields  = is_array( $fields ) ? $fields : array();
	$options = is_array( $options ) ? $options : array();
	$order   = isset( $options['field_order'] ) && is_array( $options['field_order'] ) ? $options['field_order'] : array();
	asort( $order, SORT_NUMERIC );

	$ordered = array();
	foreach ( array_keys( $order ) as $field_key ) {
		if ( array_key_exists( $field_key, $fields ) ) {
			$ordered[ $field_key ] = $fields[ $field_key ];
		}
	}

	$remaining = array_diff_key( $fields, $ordered );
	ksort( $remaining, SORT_STRING );

	return array_merge( $ordered, $remaining );
}

/**
 * Render the complete active Field Configuration list.
 *
 * @param array $fields        Current MLS metadata.
 * @param array $options       Normalized Field Configuration.
 * @param array $render_params Initial filter and feature controls.
 * @param array $theme_schema  Theme defaults retained for compatible callers.
 * @return string Complete interface HTML.
 */
function render_mls_field_selection_interface( $fields, $options, $render_params = array(), $theme_schema = array() ) {
	$params = wp_parse_args(
		$render_params,
		array(
			'import_filter'    => 'all',
			'show_filters'     => true,
			'show_stats'       => true,
			'enable_drag_drop' => true,
			'plugin_name'      => 'mlsimport',
		)
	);
	$display_fields = prepare_mls_fields_for_display( $fields, $options, $params );
	$revision       = max( 0, (int) ( $options[ Mlsimport_Field_Configuration::REVISION_KEY ] ?? 0 ) );
	$taxonomies     = mlsimport_field_configuration_taxonomies();

	ob_start();
	echo '<div class="mlsimport-field-selector-container" data-revision="' . esc_attr( $revision ) . '" data-initial-filter="' . esc_attr( $params['import_filter'] ) . '">';
	echo '<div class="mlsimport-field-save-status" role="status" aria-live="polite"></div>';

	if ( $params['show_stats'] ) {
		mlsimport_render_field_stats( $display_fields, $options );
	}
	if ( $params['show_filters'] ) {
		mlsimport_render_field_filters( $params );
	}

	echo '<table class="mlsimport-fields-table" id="mlsimport-fields-table">';
	mlsimport_render_field_table_header();
	echo '<tbody id="mlsimport-fields-table-body">';
	foreach ( $display_fields as $field_key => $description ) {
		mlsimport_render_field_table_row( $field_key, $description, $options, $taxonomies );
	}
	if ( empty( $display_fields ) ) {
		echo '<tr class="mlsimport-no-fields"><td colspan="7">' . esc_html__( 'No MLS fields are available.', 'mlsimport' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';

	return ob_get_clean();
}

/**
 * Render active-field statistics and visible-only bulk controls.
 *
 * Counts cover only current MLS metadata because dormant fields are retained in
 * storage but absent from this interface. Bulk buttons describe their visible
 * scope explicitly; the controller sends the exact visible field keys.
 *
 * @param array $fields  Ordered active metadata.
 * @param array $options Normalized Field Configuration.
 * @return void
 */
function mlsimport_render_field_stats( array $fields, array $options ) {
	$selected = 0;
	foreach ( array_keys( $fields ) as $field_key ) {
		$selected += ! empty( $options['mls-fields'][ $field_key ] ) ? 1 : 0;
	}

	echo '<div class="mlsimport-field-stats"><ul>';
	echo '<li class="mlsimport-total-count">' . esc_html( sprintf( __( '%d fields total', 'mlsimport' ), count( $fields ) ) ) . '</li>';
	echo '<li class="mlsimport-selected-count">' . esc_html( sprintf( __( '%d marked for import', 'mlsimport' ), $selected ) ) . '</li>';
	echo '</ul><div class="mlsimport-button-action-wrapper">';
	echo '<button type="button" id="mlsimport-select-all-import" class="button mlsimport_button">' . esc_html__( 'Import - Select all visible', 'mlsimport' ) . '</button>';
	echo '<button type="button" id="mlsimport-select-none-import" class="button mlsimport_button">' . esc_html__( 'Import - Select none visible', 'mlsimport' ) . '</button>';
	echo '<button type="button" id="mlsimport-select-all-admin" class="button mlsimport_button secondary">' . esc_html__( 'Hidden from Public - Select all visible', 'mlsimport' ) . '</button>';
	echo '<button type="button" id="mlsimport-select-none-admin" class="button mlsimport_button secondary">' . esc_html__( 'Hidden from Public - Select none visible', 'mlsimport' ) . '</button>';
	echo '</div></div>';
}

/**
 * Render client-side search, import filter, and sort controls.
 *
 * These controls change only the local view. Custom order remains the sole view
 * where reordering is enabled, avoiding position commands derived from a
 * filtered or alphabetically sorted subset.
 *
 * @param array $params Parsed renderer parameters including initial filter.
 * @return void
 */
function mlsimport_render_field_filters( array $params ) {
	echo '<div class="mlsimport-field-filters">';
	echo '<select id="mlsimport-field-sort" class="mlsimport-field-filter mlsimport-2025-select" style="width:200px">';
	echo '<option value="custom_order">' . esc_html__( 'Custom Order', 'mlsimport' ) . '</option>';
	echo '<option value="field_asc">' . esc_html__( 'Field Name (A-Z)', 'mlsimport' ) . '</option>';
	echo '<option value="field_desc">' . esc_html__( 'Field Name (Z-A)', 'mlsimport' ) . '</option>';
	echo '<option value="label_asc">' . esc_html__( 'Label (A-Z)', 'mlsimport' ) . '</option>';
	echo '<option value="label_desc">' . esc_html__( 'Label (Z-A)', 'mlsimport' ) . '</option>';
	echo '<option value="postmeta_asc">' . esc_html__( 'Property Detail Field (A-Z)', 'mlsimport' ) . '</option>';
	echo '<option value="postmeta_desc">' . esc_html__( 'Property Detail Field (Z-A)', 'mlsimport' ) . '</option>';
	echo '<option value="category_asc">' . esc_html__( 'Category (A-Z)', 'mlsimport' ) . '</option>';
	echo '<option value="category_desc">' . esc_html__( 'Category (Z-A)', 'mlsimport' ) . '</option>';
	echo '</select>';

	echo '<select id="mlsimport-import-filter" class="mlsimport-field-filter mlsimport-2025-select" style="width:200px">';
	echo '<option value="all"' . selected( $params['import_filter'], 'all', false ) . '>' . esc_html__( 'All Fields', 'mlsimport' ) . '</option>';
	echo '<option value="selected"' . selected( $params['import_filter'], 'selected', false ) . '>' . esc_html__( 'Selected Only', 'mlsimport' ) . '</option>';
	echo '<option value="not_selected"' . selected( $params['import_filter'], 'not_selected', false ) . '>' . esc_html__( 'Not Selected', 'mlsimport' ) . '</option>';
	echo '</select>';
	echo '<input type="text" id="mlsimport-field-search" class="mlsimport-field-filter mlsimport-input mlsimport-2025-input" style="width:300px" placeholder="' . esc_attr__( 'Search...', 'mlsimport' ) . '">';
	echo '</div>';
}

/**
 * Render the seven stable table columns consumed by the browser controller.
 *
 * No form names are emitted because changes travel as compact JSON commands.
 * The actions column holds relative move controls that remain usable when the
 * jQuery sortable interaction is unavailable.
 *
 * @return void
 */
function mlsimport_render_field_table_header() {
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Field', 'mlsimport' ) . '</th>';
	echo '<th style="width:75px">' . esc_html__( 'Import it?', 'mlsimport' ) . '</th>';
	echo '<th>' . esc_html__( 'Front End Label', 'mlsimport' ) . '</th>';
	echo '<th style="width:75px">' . esc_html__( 'Hidden from Public?', 'mlsimport' ) . '</th>';
	echo '<th>' . esc_html__( 'Property Detail Field (post meta)', 'mlsimport' ) . '</th>';
	echo '<th>' . esc_html__( 'Category', 'mlsimport' ) . '</th>';
	echo '<th style="width:140px">' . esc_html__( 'Actions', 'mlsimport' ) . '</th>';
	echo '</tr></thead>';
}

/**
 * Render one active field without form names or mandatory-field branches.
 *
 * Values come exclusively from the normalized configuration. The row exposes
 * the RESO key and custom-order position as data attributes, while editable
 * controls expose only classes interpreted by the compact command controller.
 *
 * @param string $field_key RESO field identifier.
 * @param mixed  $description Metadata description displayed below the key.
 * @param array  $options Normalized Field Configuration.
 * @param array  $taxonomies Allowed taxonomy destinations.
 * @return void
 */
function mlsimport_render_field_table_row( $field_key, $description, array $options, array $taxonomies ) {
	$order      = (int) ( $options['field_order'][ $field_key ] ?? 0 );
	$is_import  = ! empty( $options['mls-fields'][ $field_key ] );
	$is_admin   = ! empty( $options['mls-fields-admin'][ $field_key ] );
	$label      = (string) ( $options['mls-fields-label'][ $field_key ] ?? '' );
	$postmeta   = (string) ( $options['mls-fields-map-postmeta'][ $field_key ] ?? '' );
	$taxonomy   = (string) ( $options['mls-fields-map-taxonomy'][ $field_key ] ?? '' );
	$description = is_scalar( $description ) && ! is_bool( $description ) ? (string) $description : '';

	echo '<tr class="mlsimport-field-row" data-field-order="' . esc_attr( $order ) . '" data-field-key="' . esc_attr( $field_key ) . '">';
	echo '<td class="mlsimport-field-name mlsimport-field-name_title_desc"><span class="field-name"><span class="field-position">' . esc_html( $order + 1 ) . '. </span>' . esc_html( $field_key ) . '</span>';
	if ( '' !== $description && '1' !== $description && '0' !== $description ) {
		echo '<div class="field-explanation">' . esc_html( $description ) . '</div>';
	}
	echo '</td>';
	echo '<td class="mlsimport-field-import"><input type="checkbox" class="mlsimport-import-checkbox" value="1" ' . checked( $is_import, true, false ) . '></td>';
	echo '<td class="mlsimport-field-label"><input type="text" class="mlsimport-label-input mlsimport-input mlsimport-2025-input" value="' . esc_attr( $label ) . '" placeholder="' . esc_attr__( 'enter label...', 'mlsimport' ) . '"></td>';
	echo '<td class="mlsimport-field-admin"><input type="checkbox" class="mlsimport-admin-checkbox" value="1" ' . checked( $is_admin, true, false ) . '></td>';
	echo '<td class="mlsimport-field-postmeta"><input type="text" class="mlsimport-postmeta-input mlsimport-input mlsimport-2025-input" value="' . esc_attr( $postmeta ) . '"></td>';
	echo '<td class="mlsimport-field-taxonomy">' . mlsimport_field_taxonomy_dropdown( $taxonomy, $taxonomies ) . '</td>';
	echo '<td class="mlsimport-field-actions"><div class="mlsimport-row-actions">';
	echo '<button type="button" class="mlsimport-move-btn mlsimport-move-up" title="' . esc_attr__( 'Move field up', 'mlsimport' ) . '">↑</button>';
	echo '<button type="button" class="mlsimport-move-btn mlsimport-move-down" title="' . esc_attr__( 'Move field down', 'mlsimport' ) . '">↓</button>';
	echo '<button type="button" class="mlsimport-move-btn mlsimport-move-top" title="' . esc_attr__( 'Move field to top', 'mlsimport' ) . '">⇈</button>';
	echo '<button type="button" class="mlsimport-move-btn mlsimport-move-bottom" title="' . esc_attr__( 'Move field to bottom', 'mlsimport' ) . '">⇊</button>';
	echo '</div></td></tr>';
}

/**
 * Build the active property type's allowed taxonomy destination control.
 *
 * An explicit empty option represents no taxonomy mapping. The selected value
 * is read from normalized state, and every value and label is escaped before
 * the complete select element is returned to the row renderer.
 *
 * @param string $selected_taxonomy Currently mapped taxonomy slug.
 * @param array  $taxonomies Allowed slug-to-label map.
 * @return string Escaped select markup.
 */
function mlsimport_field_taxonomy_dropdown( $selected_taxonomy, array $taxonomies ) {
	$html       = '<select class="mlsimport-taxonomy-select mlsimport-2025-select">';
	$html      .= '<option value="">' . esc_html__( 'None', 'mlsimport' ) . '</option>';
	foreach ( $taxonomies as $taxonomy => $label ) {
		$html .= '<option value="' . esc_attr( $taxonomy ) . '" ' . selected( $selected_taxonomy, $taxonomy, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$html .= '</select>';

	return $html;
}
