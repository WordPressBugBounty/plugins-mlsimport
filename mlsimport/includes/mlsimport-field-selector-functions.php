<?php
/**
 * MLS Import Field Selector Functions
 *
 * This file contains functions for rendering and managing the MLS field selection interface.
 * It provides a tabular approach to field selection with filtering, pagination, and 
 * interactive features for managing large numbers of MLS fields.
 *
 * @package    MLSImport
 * @subpackage MLSImport/includes
 * @since      1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Prepares MLS fields for display by applying filters, sorting, and pagination.
 *
 * @since    1.0.0
 * @param    array     $fields         The array of MLS fields to process.
 * @param    array     $options        The saved options for field selections.
 * @param    array     $filter_params  Parameters for filtering and pagination.
 * @return   array                     The processed fields ready for display.
 */
function prepare_mls_fields_for_display($fields, $options, $filter_params) {
    // Apply search filter if search term is provided
    if (!empty($filter_params['search_term'])) {
        $fields = search_mls_fields($filter_params['search_term'], $fields);
    }
    
    // Apply import status filter
    if ($filter_params['import_filter'] !== 'all') {
        $fields = filter_mls_fields_by_import_status($filter_params['import_filter'], $fields, $options);
    }
    
    // Apply alphabetical filter
    if (!empty($filter_params['alpha_filter'])) {
        $fields = filter_mls_fields_by_alphabet($filter_params['alpha_filter'], $fields);
    }
    
    // Apply custom field order if available, otherwise preserve input order
    if (isset($options['field_order']) && is_array($options['field_order']) && !empty($options['field_order'])) {


        $fields = mlsimport_sort_all_fields_by_order($options);
        
      
    }
    // No else needed - when no field_order exists, we keep fields in their original order
    

    return $fields;
}

/**
[mls-fields] => Array
(
    [ListingKey] => 0
    [AboveGradeFinishedArea] => 0
    [AboveGradeFinishedAreaSource] => 0
    [AboveGradeFinishedAreaUnits] => 0
    [AboveGradeUnfinishedArea] => 0
    [AboveGradeUnfinishedAreaSourc


    [mls-fields-admin] => Array
    (
        [ListingKey] => 0
        [AboveGradeFinishedArea] => 0
        [AboveGradeFinishedAreaSource] => 0
        [AboveGradeFinishedAreaUnits] => 0
        [AboveGradeUnfinishedArea] => 0
        [AboveGradeUnfinishedAreaSource] => 0
        [AboveGradeUnfinishedAreaUnits] => 0


        [mls-fields-label] => Array
        (
            [ListingKey] => 
            [AboveGradeFinishedArea] => 
            [AboveGradeFinishedAreaSource] => 
            [AboveGradeFinishedAreaUnits] => 
            [AboveGradeUnfinishedArea] => 
            [AboveGradeUnfinishedAreaSource] => 
            [AboveGradeUnfinishedAreaUnits] => 
            [AccessCode] => 
            [AccessibilityFeatures] => 
            [ActivationDate] => 
            [AdditionalParcelsDescription] => 
            [AdditionalParcelsYN] => 



            
    [mls-fields-map-postmeta] => Array
    (
        [ListingKey] => 
        [AboveGradeFinishedArea] => 
        [AboveGradeFinishedAreaSource] => 
        [AboveGradeFinishedAreaUnits] => 
        [AboveGradeUnfinishedArea] => 
        [AboveGradeUnfinishedAreaSource] => 
        [AboveGradeUnfinishedAreaUnits] => 
        [AccessCode] => 
        [AccessibilityFeatures] => 



        [mls-fields-map-taxonomy] => Array
        (
            [ListingKey] => 
            [AboveGradeFinishedArea] => 
            [AboveGradeFinishedAreaSource] => 
            [AboveGradeFinishedAreaUnits] => 
            [AboveGradeUnfinishedArea] => 
            [AboveGradeUnfinishedAreaSource] => 
            [AboveGradeUnfinishedAreaUnits] => 
            [AccessCode] => 
            [AccessibilityFeatures] => 
            [ActivationDate] => 
            [AdditionalParcelsDescription] => 
            [AdditionalParcelsYN] => 

 * Applies field_order sorting to all related MLSImport field arrays in options.
 *
 * @param array $options The complete options array containing field_order and field-related arrays.
 * @return array The updated options array with sorted field arrays.
 */
function mlsimport_sort_all_fields_by_order(array $options): array {
    if (empty($options['field_order']) || !is_array($options['field_order'])) {
        return $options;
    }

    asort($options['field_order']); // Sort field_order by index

    $targets = [
        'mls-fields',
        'mls-fields-admin',
        'mls-fields-label',
        'mls-fields-map-postmeta',
        'mls-fields-map-taxonomy'

       
    ];

    foreach ($targets as $target) {
        if (!empty($options[$target]) && is_array($options[$target])) {
            $ordered = [];

            foreach ($options['field_order'] as $field_key => $index) {
                if (isset($options[$target][$field_key])) {
                    $ordered[$field_key] = $options[$target][$field_key];
                }
            }

            // Append remaining fields not in field_order
            foreach ($options[$target] as $key => $value) {
                if (!isset($ordered[$key])) {
                    $ordered[$key] = $value;
                }
            }

            $options[$target] = $ordered;
        }
    }

    return $options;
}


/**
 * Main function to render the MLS field selection interface.
 *
 * @param    array     $fields          The array of MLS fields to display.
 * @param    array     $options         The saved options for field selections.
 * @param    array     $render_params   Parameters to control rendering.
 * @param    array     $theme_schema    Optional. Theme schema for mandatory fields.
 * @return   string                     The HTML for the field selection interface.
 */
function render_mls_field_selection_interface($fields, $options, $render_params = array(), $theme_schema = array()) {
    $defaults = array(
        'page'              => 1,
        'fields_per_page'   => 50,
        'search_term'       => '',
        'import_filter'     => 'all', // 'all', 'selected', 'not_selected'
        'alpha_filter'      => '',    // Filter by first letter
        'show_pagination'   => true,
        'show_filters'      => true,
        'show_stats'        => true,
        'enable_drag_drop'  => true,
        'form_action'       => 'options.php',
        'form_method'       => 'post',
        'form_name'         => 'mlsimport_fields_select',
        'nonce_field'       => 'mlsimport_admin_fields_select',
        'plugin_name'       => 'mlsimport',
    );
    
    // Merge default parameters with provided parameters
    $params = wp_parse_args( $render_params, $defaults );
    
   
    // Prepare fields for display (filtering, sorting, pagination)
    $display_fields = prepare_mls_fields_for_display( $fields, $options, $params );
    
    






    // Calculate statistics for display
    $stats = calculate_mls_fields_stats(  $options );


    // Start output buffering
    ob_start();
    
    // Generate form opening
    echo '<form method="' . esc_attr( $params['form_method'] ) . '" name="' . esc_attr( $params['form_name'] ) . '" action="' . esc_attr( $params['form_action'] ) . '" class="mlsimport-fields-form">';
    
    // Add nonce field
    settings_fields( $params['nonce_field'] );
    do_settings_sections( $params['nonce_field'] );
    
    // Add container div for the entire interface
    echo '<div class="mlsimport-field-selector-container">';
    
    // Show stats if enabled
    if ( $params['show_stats'] ) {
        render_mls_field_stats( $stats );
    }
    
    // Show filters if enabled
    if ( $params['show_filters'] ) {
        render_mls_field_filters( $params );
    }
    
    // Start table
    echo '<table class="mlsimport-fields-table" id="mlsimport-fields-table">';
    
    // Render table header
    render_mls_field_table_header();
    
    // Render table body
    echo '<tbody id="mlsimport-fields-table-body">';


    // Loop through fields and render rows
    if ( ! empty( $display_fields ) ) {
        foreach ($display_fields['mls-fields'] as $field_key => $field_value) {
            render_mls_field_table_row(
                $field_key, 
                $field_value, 
                $display_fields, 
                $params['plugin_name'],
                $theme_schema  // Pass the theme_schema here
            );
        }
    } else {
        // No fields found
        echo '<tr><td colspan="5">' . esc_html__( 'No fields found matching your criteria.', 'mlsimport' ) . '</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    
    // Show pagination if enabled
    if ( $params['show_pagination'] ) {
        $total_pages = ceil( count( $fields ) / $params['fields_per_page'] );
        render_mls_field_table_pagination( $params['page'], $total_pages );
    }
    
    // Close container div
    echo '</div>';
    
  
    // Close form
    echo '</form>';
    
 
    // Return the buffered output
    return ob_get_clean();
}

/**
 * Prepares MLS fields for display by applying filters, sorting, and pagination.
 *
 * @since    1.0.0
 * @param    array     $fields         The array of MLS fields to process.
 * @param    array     $options        The saved options for field selections.
 * @param    array     $filter_params  Parameters for filtering and pagination.
 * @return   array                     The processed fields ready for display.
 *//**
 * Apply custom field order if available
 */
function apply_custom_field_order($fields, $options) {
    // Check if we have a custom order
    if (isset($options['field_order']) && is_array($options['field_order']) && !empty($options['field_order'])) {
        $ordered_fields = array();
        $remaining_fields = $fields;
        
        // First add fields that are in the custom order
        foreach ($options['field_order'] as $field_key) {
            if (isset($fields[$field_key])) {
                $ordered_fields[$field_key] = $fields[$field_key];
                unset($remaining_fields[$field_key]);
            }
        }
        
        // Then add any remaining fields that weren't in the custom order
        if (!empty($remaining_fields)) {
            $ordered_fields = array_merge($ordered_fields, $remaining_fields);
        }
        
        return $ordered_fields;
    }
    
    // No custom order, return original
    return $fields;
}

/**
 * Filters fields by name based on search input.
 *
 * @since    1.0.0
 * @param    string    $search_term    The term to search for.
 * @param    array     $all_fields     The array of all MLS fields.
 * @return   array                     Filtered fields matching the search term.
 */
function search_mls_fields( $search_term, $all_fields ) {
    $filtered_fields = array();
    
    foreach ( $all_fields as $key => $value ) {
        // Search in field key (name)
        if ( stripos( $key, $search_term ) !== false ) {
            $filtered_fields[ $key ] = $value;
        }
    }
    
    return $filtered_fields;
}

/**
 * Filters fields by their import status (selected or not selected).
 *
 * @since    1.0.0
 * @param    string    $import_status  The import status to filter by ('selected' or 'not_selected').
 * @param    array     $all_fields     The array of all MLS fields.
 * @param    array     $options        The saved options for field selections.
 * @return   array                     Filtered fields matching the import status.
 */
function filter_mls_fields_by_import_status( $import_status, $all_fields, $options ) {
    $filtered_fields = array();
    
    foreach ( $all_fields as $key => $value ) {
        $is_selected = isset( $options['mls-fields'][ $key ] ) && 
                      intval( $options['mls-fields'][ $key ] ) === 1;
        
        if ( $import_status === 'selected' && $is_selected ) {
            $filtered_fields[ $key ] = $value;
        } elseif ( $import_status === 'not_selected' && ! $is_selected ) {
            $filtered_fields[ $key ] = $value;
        }
    }
    
    return $filtered_fields;
}

/**
 * Filters fields starting with a particular letter.
 *
 * @since    1.0.0
 * @param    string    $letter         The letter to filter by.
 * @param    array     $all_fields     The array of all MLS fields.
 * @return   array                     Filtered fields starting with the specified letter.
 */
function filter_mls_fields_by_alphabet( $letter, $all_fields ) {
    $filtered_fields = array();
    
    foreach ( $all_fields as $key => $value ) {
        if ( strtoupper( substr( $key, 0, 1 ) ) === strtoupper( $letter ) ) {
            $filtered_fields[ $key ] = $value;
        }
    }
    
    return $filtered_fields;
}

/**
 * Calculates statistics for display (total, selected, missing labels).
 *
 * @since    1.0.0
 * @param    array     $all_fields     The array of all MLS fields.
 * @param    array     $options        The saved options for field selections.
 * @return   array                     Statistics about the fields.
 */
function calculate_mls_fields_stats(  $options ) {
    $stats = array(
        'total_fields'      => count( $options['mls-fields'] ),
        'selected_fields'   => 0,
        'missing_labels'    => 0,
    );
    
    foreach ( $options['mls-fields'] as $key => $value ) {
        // Count selected fields
        if ( isset( $options['mls-fields'][ $key ] ) && 
            intval( $options['mls-fields'][ $key ] ) === 1 ) {
            $stats['selected_fields']++;
            
         
        }
    }
    
    return $stats;
}

/**
 * Renders the statistics bar showing counts of fields.
 *
 * @since    1.0.0
 * @param    array     $stats          The statistics to display.
 * @return   void
 */

 function render_mls_field_stats( $stats ) {
    echo '<div class="mlsimport-field-stats">';
    echo '<ul>';
    echo '<li>' . esc_html( sprintf( __( '%d fields total', 'mlsimport' ), $stats['total_fields'] ) ) . '</li>';
    echo '<li>' . esc_html( sprintf( __( '%d marked for import', 'mlsimport' ), $stats['selected_fields'] ) ) . '</li>';
  
    echo '</ul>';

    echo '<div class="mlsimport-button-action-wrapper">';
    echo '<button id="mlsimport-select-all-import" class="button mlsimport_button">' . esc_html__( 'Import - Select All', 'mlsimport' ) . '</button>';
    echo '<button id="mlsimport-select-none-import" class="button mlsimport_button">' . esc_html__( 'Import - Select None', 'mlsimport' ) . '</button>';
    echo '<button id="mlsimport-select-all-admin" class="button mlsimport_button secondary">' . esc_html__( 'Hidden from Public - Select All', 'mlsimport' ) . '</button>';
    echo '<button id="mlsimport-select-none-admin" class="button mlsimport_button secondary">' . esc_html__( 'Hidden from Public - Select None', 'mlsimport' ) . '</button>';
    echo '</div>';
    echo '</div>';
}

    


/**
 * Renders the filters bar for searching and filtering fields.
 *
 * @since    1.0.0
 * @param    array     $params         Parameters for filtering.
 * @return   void
 */
function render_mls_field_filters( $params ) {
    // Field sorting dropdown
    echo '<div class="mlsimport-field-filters">';
    
    // Field sorting dropdown
    echo '<select id="mlsimport-field-sort" class="mlsimport-field-filter mlsimport-2025-select" style="width:200px">';

    echo '<option value="field_asc">' . esc_html__( 'Field Name (A-Z)', 'mlsimport' ) . '</option>';
    echo '<option value="field_desc">' . esc_html__( 'Field Name (Z-A)', 'mlsimport' ) . '</option>';

    echo '<option value="label_asc">' . esc_html__( 'Label (A-Z)', 'mlsimport' ) . '</option>';
    echo '<option value="label_desc">' . esc_html__( 'Label (Z-A)', 'mlsimport' ) . '</option>';

    echo '<option value="postmeta_asc">' . esc_html__( 'Property Detail Field (A-Z)', 'mlsimport' ) . '</option>';
    echo '<option value="postmeta_desc">' . esc_html__( 'Property Detail Field (Z-A)', 'mlsimport' ) . '</option>';

    echo '<option value="category_asc">' . esc_html__( 'Category (A-Z)', 'mlsimport' ) . '</option>';
    echo '<option value="category_desc">' . esc_html__( 'Category (Z-A)', 'mlsimport' ) . '</option>';

    echo '<option value="import_selected">' . esc_html__( 'Import Selected First', 'mlsimport' ) . '</option>';
    echo '<option value="import_unselected">' . esc_html__( 'Import Unselected First', 'mlsimport' ) . '</option>';

    echo '<option value="hidden_selected">' . esc_html__( 'Hidden from Public Selected First', 'mlsimport' ) . '</option>';
    echo '<option value="hidden_unselected">' . esc_html__( 'Hidden from Public Unselected First', 'mlsimport' ) . '</option>';

    echo '</select>';
    
    // Import status filter
    echo '<select id="mlsimport-import-filter" class="mlsimport-field-filter mlsimport-2025-select" style="width:200px">';
    echo '<option value="all"' . selected( $params['import_filter'], 'all', false ) . '>' . esc_html__( 'All Fields', 'mlsimport' ) . '</option>';
    echo '<option value="selected"' . selected( $params['import_filter'], 'selected', false ) . '>' . esc_html__( 'Selected Only', 'mlsimport' ) . '</option>';
    echo '<option value="not_selected"' . selected( $params['import_filter'], 'not_selected', false ) . '>' . esc_html__( 'Not Selected', 'mlsimport' ) . '</option>';
    echo '</select>';
    
    // Search box
    echo '<input type="text" id="mlsimport-field-search" class="mlsimport-field-filter mlsimport-input mlsimport-2025-input" style="width:300px" placeholder="' . esc_attr__( 'Search...', 'mlsimport' ) . '" value="' . esc_attr( $params['search_term'] ) . '">';
    
    echo '</div>';
}

/**
 * Renders the table header for the field selection table.
 *
 * @since    1.0.0
 * @return   void
 */
function render_mls_field_table_header() {
    echo '<thead>';
    echo '<tr>';
    echo '<th>' . esc_html__( 'Field', 'mlsimport' ) . '</th>';
    echo '<th style="width:75px;">' . esc_html__( 'Import it?', 'mlsimport' ) . '</th>';
    echo '<th>' . esc_html__( 'Front End Label', 'mlsimport' ) . '</th>';
    echo '<th style="width:75px;">' . esc_html__( 'Hidden from Public?', 'mlsimport' ) . '</th>';
    echo '<th>' . esc_html__( 'Property Detail Field (post meta)', 'mlsimport' ) . '</th>';
    echo '<th>' . esc_html__( 'Category', 'mlsimport' ) . '</th>';
    echo '<th style="width:140px;">' . esc_html__( 'Actions', 'mlsimport' ) . '</th>';
    echo '</tr>';
    echo '</thead>';
}


/**
 * Renders a single table row for a field.
 *
 * @since    1.0.0
 * @param    string    $field_key      The field key/name.
 * @param    string    $field_value    The field explanation/description.
 * @param    array     $options        The saved options for field selections.
 * @param    string    $plugin_name    The plugin name for form field IDs.
 * @param    array     $theme_schema   Optional. Theme schema for mandatory fields.
 * @return   void
 */
function render_mls_field_table_row($field_key, $field_value, $options, $plugin_name, $theme_schema = array()) {
    // Determine if this is a mandatory field
    $is_mandatory = 0;
    
    // Determine if field is checked for import
    $is_checked = $is_mandatory || 
                 (isset($options['mls-fields'][$field_key]) && 
                 intval($options['mls-fields'][$field_key]) === 1);
    
    // Determine if field is checked for admin-only visibility
    $is_admin_only = isset($options['mls-fields-admin'][$field_key]) && 
                    intval($options['mls-fields-admin'][$field_key]) === 1;
    
    // Get field label value
    $label_value = isset($options['mls-fields-label'][$field_key]) ? 
                  $options['mls-fields-label'][$field_key] : '';
    
    // Get post meta value
    $post_meta_value = isset($options['mls-fields-map-postmeta'][$field_key]) ? 
                      $options['mls-fields-map-postmeta'][$field_key] : '';
    
    // Get taxonomy value
    $taxonomy_value = isset($options['mls-fields-map-taxonomy'][$field_key]) ? 
                     $options['mls-fields-map-taxonomy'][$field_key] : '';


    // Get taxonomy value
    $field_order= isset($options['field_order'][$field_key]) ? 
        $options['field_order'][$field_key] : '';
        
        
    
    // Define CSS classes for the row
    $row_classes = array('mlsimport-field-row');
    if ($is_mandatory) {
        $row_classes[] = 'mlsimport-mandatory-row';
    }
    
    // Start row
    echo '<tr style="display:none; opacity:0;" class="' . esc_attr(implode(' ', $row_classes)) . '"  
        data-field-order="'.intval($field_order).'" data-field-key="' . esc_attr($field_key) . '" data-is-mandatory="' . ($is_mandatory ? 'true' : 'false') . '">';
    // Field name column with full explanation


    echo '<td class="mlsimport-field-name mlsimport-field-name_title_desc">';

    // Position indicator for debugging

    echo '<span class="field-name">'; 
    echo '<span class="field-position">' . ( intval( $field_order ) + 1 ) . '. </span>';
    echo esc_html( $field_key ) . '</span>';

    if ( !empty($field_value) && intval($field_value)!==1 )  {
        echo '<div class="field-explanation">' . esc_html($field_value) . '</div>';
    }
    echo '</td>';
    
    // Import checkbox column
    echo '<td class="mlsimport-field-import">';
    echo '<input type="hidden" name="' . esc_attr($plugin_name) . '_admin_fields_select[mls-fields][' . esc_attr($field_key) . ']" value="' . ($is_mandatory ? '1' : '0') . '">';
    
    if ($is_mandatory) {
        // For mandatory fields, show a disabled checked checkbox
        echo '<input type="checkbox" class="mlsimport-import-checkbox" checked="checked" disabled="disabled" title="' . esc_attr__('Mandatory field - cannot be deselected', 'mlsimport') . '">';
    } else {
        // For optional fields, show a regular checkbox
        echo '<input type="checkbox" class="mlsimport-import-checkbox" name="' . esc_attr($plugin_name) . '_admin_fields_select[mls-fields][' . esc_attr($field_key) . ']" value="1" ' . checked($is_checked, true, false) . '>';
    }
    echo '</td>';
    
    // Label column
    echo '<td class="mlsimport-field-label">';
    echo '<input type="text" class="mlsimport-label-input mlsimport-input mlsimport-2025-input" name="' . esc_attr($plugin_name) . '_admin_fields_select[mls-fields-label][' . esc_attr($field_key) . ']" value="' . esc_attr($label_value) . '" placeholder="' . esc_attr__('enter label...', 'mlsimport') . '">';
    echo '</td>';
    
    // Admin-only visibility checkbox column
    echo '<td class="mlsimport-field-admin">';
    echo '<input type="hidden" name="' . esc_attr($plugin_name) . '_admin_fields_select[mls-fields-admin][' . esc_attr($field_key) . ']" value="0">';
    echo '<input type="checkbox" class="mlsimport-admin-checkbox" name="' . esc_attr($plugin_name) . '_admin_fields_select[mls-fields-admin][' . esc_attr($field_key) . ']" value="1" ' . checked($is_admin_only, true, false) . '>';
    echo '</td>';
    
    // Post meta mapping column
    echo '<td class="mlsimport-field-postmeta">';
    echo '<input type="text" class="mlsimport-postmeta-input mlsimport-input mlsimport-2025-input " name="' . esc_attr($plugin_name) . '_admin_fields_select[mls-fields-map-postmeta][' . esc_attr($field_key) . ']" value="' . esc_attr($post_meta_value) . '">';
    echo '</td>';
    
    // Category/taxonomy mapping column
    echo '<td class="mlsimport-field-taxonomy">';
    echo get_taxonomy_dropdown_html($field_key, $taxonomy_value, $plugin_name, $theme_schema);
    echo '</td>';

    // Actions column with move buttons
    echo '<td class="mlsimport-field-actions">';
    echo '<div class="mlsimport-row-actions">';
    echo '<button type="button" class="mlsimport-move-btn mlsimport-move-up" title="Move field up">↑</button>';
    echo '<button type="button" class="mlsimport-move-btn mlsimport-move-down" title="Move field down">↓</button>';
    echo '<button type="button" class="mlsimport-move-btn mlsimport-move-top" title="Move field to top">⇈</button>';
    echo '<button type="button" class="mlsimport-move-btn mlsimport-move-bottom" title="Move field to bottom">⇊</button>';
    echo '</div>';
    echo '</td>';

    // End row
    echo '</tr>';
}

/**
 * Gets HTML for taxonomy dropdown with proper availability checking.
 *
 * @since    1.0.0
 * @param    string    $field_key      The field key/name.
 * @param    string    $selected       The currently selected taxonomy.
 * @param    string    $plugin_name    The plugin name for form field IDs.
 * @param    array     $theme_schema   Optional. Theme schema for mandatory fields.
 * @return   string                    HTML for the taxonomy dropdown.
 */
function get_taxonomy_dropdown_html($field_key, $selected, $plugin_name, $theme_schema = array()) {
    // Don't rely on global - explicitly get the taxonomies
    global $mlsimport;
    $post_type = '';
    
    if (method_exists($mlsimport->admin->env_data, 'get_property_post_type')) {
        $post_type = $mlsimport->admin->env_data->get_property_post_type();
    }
        
    // Get taxonomies directly
    $available_taxonomies = mlsimport_get_custom_post_type_taxonomies($post_type);
    
    // For mandatory fields, set default taxonomy from theme schema
    if (!empty($theme_schema) && isset($theme_schema[$field_key]) && 
        isset($theme_schema[$field_key]['type']) && $theme_schema[$field_key]['type'] === 'taxonomy' && 
        isset($theme_schema[$field_key]['name'])) {
        // Use schema's taxonomy value if no existing selection
        if (empty($selected)) {
            $selected = $theme_schema[$field_key]['name'];
        }
    }
 
    // Build dropdown HTML
    $html = '<select class="mlsimport-taxonomy-select mlsimport-2025-select " name="mlsimport_admin_fields_select[mls-fields-map-taxonomy][' . esc_attr($field_key) . ']">';
    $html .= '<option value="">' . esc_html__('None', 'mlsimport') . '</option>';
    
    if (is_array($available_taxonomies) && !empty($available_taxonomies)) {
        foreach ($available_taxonomies as $key => $label) {
            $html .= '<option value="' . esc_attr($key) . '" ' . selected($selected, $key, false) . '>' . esc_html($label) . '</option>';
        }
    }
    
    $html .= '</select>';
    return $html;
}
/**
 * Gets available taxonomies for the dropdown.
 *
 * @since    1.0.0
 * @return   array    Array of taxonomies with key => label pairs.
 */
function get_custom_taxonomies_for_dropdown() {
    // This function should return an array of taxonomies
    // Replace with actual implementation based on your needs
    
    // For example:
    $post_type = ''; // Get your post type here
    
    if ( function_exists( 'mlsimport_get_custom_post_type_taxonomies' ) ) {
        return mlsimport_get_custom_post_type_taxonomies( $post_type );
    }
    
    // Fallback to empty array if function doesn't exist
    return array();
}

/**
 * Renders pagination controls for the table.
 *
 * @since    1.0.0
 * @param    int       $current_page   The current page number.
 * @param    int       $total_pages    The total number of pages.
 * @return   void
 */
function render_mls_field_table_pagination( $current_page, $total_pages ) {
    if ( $total_pages <= 1 ) {
        return;
    }
    
    echo '<div class="mlsimport-pagination">';
    
    // Previous page
    if ( $current_page > 1 ) {
        echo '<a href="#" class="mlsimport-page-link" data-page="' . ( $current_page - 1 ) . '">&laquo; ' . esc_html__( 'Previous', 'mlsimport' ) . '</a>';
    } else {
        echo '<span class="mlsimport-page-disabled">&laquo; ' . esc_html__( 'Previous', 'mlsimport' ) . '</span>';
    }
    
    // Page numbers
    $start_page = max( 1, $current_page - 2 );
    $end_page = min( $total_pages, $current_page + 2 );
    
    if ( $start_page > 1 ) {
        echo '<a href="#" class="mlsimport-page-link" data-page="1">1</a>';
        if ( $start_page > 2 ) {
            echo '<span class="mlsimport-page-ellipsis">...</span>';
        }
    }
    
    for ( $i = $start_page; $i <= $end_page; $i++ ) {
        if ( $i === $current_page ) {
            echo '<span class="mlsimport-page-current">' . $i . '</span>';
        } else {
            echo '<a href="#" class="mlsimport-page-link" data-page="' . $i . '">' . $i . '</a>';
        }
    }
    
    if ( $end_page < $total_pages ) {
        if ( $end_page < $total_pages - 1 ) {
            echo '<span class="mlsimport-page-ellipsis">...</span>';
        }
        echo '<a href="#" class="mlsimport-page-link" data-page="' . $total_pages . '">' . $total_pages . '</a>';
    }
    
    // Next page
    if ( $current_page < $total_pages ) {
        echo '<a href="#" class="mlsimport-page-link" data-page="' . ( $current_page + 1 ) . '">' . esc_html__( 'Next', 'mlsimport' ) . ' &raquo;</a>';
    } else {
        echo '<span class="mlsimport-page-disabled">' . esc_html__( 'Next', 'mlsimport' ) . ' &raquo;</span>';
    }
    
    echo '</div>';
}


/**
 * Processes bulk selection/deselection of fields.
 *
 * @since    1.0.0
 * @param    string    $action         The bulk action ('select_all', 'select_none', etc.).
 * @param    array     $fields         The fields to apply the action to.
 * @return   void
 */
function handle_bulk_actions( $action, $fields ) {
    // This function will be implemented in JavaScript for client-side actions
    // Server-side processing would happen in the form submission handler
}

/**
 * Saves the custom order from drag and drop operations.
 *
 * @since    1.0.0
 * @param    array     $ordered_fields The fields in their new order.
 * @return   bool                      Whether the save was successful.
 */
function save_field_display_order( $ordered_fields ) {
    // Save the custom order to user meta or options
    // This would be called via AJAX when the user reorders fields
    
    // Example implementation:
    update_option( 'mlsimport_field_display_order', $ordered_fields );
    
    return true;
}

/**
 * Updates individual field options.
 *
 * @since    1.0.0
 * @param    string    $field_key      The field key to update.
 * @param    string    $option_type    The option type ('import', 'admin', 'label', etc.).
 * @param    mixed     $value          The new value for the option.
 * @return   bool                      Whether the update was successful.
 */
function update_field_options( $field_key, $option_type, $value ) {
    // This would be used for AJAX updates to individual field options
    // Implementation would depend on how options are stored
    
    return true;
}


/**
 * Generate HTML for taxonomy dropdown with automatic selection for mandatory fields
 *
 * @param string $field_key The field key/name
 * @param array $theme_schema The theme schema array
 * @param array $options The saved options
 * @param string $plugin_name The plugin name
 * @param array $available_taxonomies Available taxonomies array
 * @return string HTML for the select dropdown
 */
function mlsimport_get_taxonomy_dropdown($field_key, $theme_schema, $options, $plugin_name, $available_taxonomies) {
    // Get current selection from saved options
    $selected_value = isset($options['mls-fields-map-taxonomy'][$field_key]) ? 
                      $options['mls-fields-map-taxonomy'][$field_key] : '';
    
    // For mandatory fields, set default from theme schema
    if (isset($theme_schema[$field_key]) && isset($theme_schema[$field_key]['type']) && 
        $theme_schema[$field_key]['type'] == 'taxonomy' && isset($theme_schema[$field_key]['name'])) {
        // If this is a taxonomy field in schema, use the schema's taxonomy name by default
        $selected_value = $theme_schema[$field_key]['name'];
    }
    
    // Start building the dropdown
    $html = '<select class="mlsfield_map_post_tax_select" name="' . esc_attr($plugin_name) . '_admin_fields_select[mls-fields-map-taxonomy][' . esc_attr($field_key) . ']">';
    $html .= '<option value="">' . esc_html__('None', 'mlsimport') . '</option>';
    
    // Add options for each available taxonomy
    foreach ($available_taxonomies as $tax_key => $tax_label) {
        $html .= '<option value="' . esc_attr($tax_key) . '"';
        if ($selected_value == $tax_key) {
            $html .= ' selected';
        }
        $html .= '>' . esc_html($tax_label) . '</option>';
    }
    
    $html .= '</select>';
    
    return $html;
}