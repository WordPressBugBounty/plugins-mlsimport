<?php
/**
 * ResidenceClass — theme adapter for the WpResidence (WpEstate family) WordPress theme.
 *
 * One of the plugin's theme adapters (enviroment/ directory). Maps RESO-standard MLS
 * property data onto WpResidence's post type ('estate_property') and post-meta
 * keys (property_*, wpestate_*). Stored Listing Write owns shared selection and
 * persistence; this adapter keeps only WpResidence gallery representation,
 * theme field projection, creation defaults, and custom-field registry support.
 *
 * @package MLSImport
 */
// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Persist genuine WpResidence variation behind the Stored adapter seam.
 *
 * @author mlsimport
 */
class ResidenceClass {

	/**
	 * Constructor — no setup required for this adapter.
	 */
	public function __construct() {
      
	}


	/**
	 * return custom post field
	 *
	 * The post type WpResidence uses to store property listings.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @return   string  The 'estate_property' post-type slug.
	 */
	public function get_property_post_type() {
		// WpResidence stores listings in the 'estate_property' post type.
		return 'estate_property';
	}

	/**
	 * Return the WpResidence property post type to Stored Listing Write.
	 *
	 * @return string Property post-type slug.
	 */
	public function property_post_type(): string {
		return 'estate_property';
	}

	/**
	 * Persist WpResidence's one-array gallery representation.
	 *
	 * @param int             $property_id   Managed Listing post ID.
	 * @param array<int, int> $attachment_ids Final ordered attachment IDs.
	 * @return bool Whether the gallery representation was written.
	 */
	public function write_gallery( int $property_id, array $attachment_ids ): bool {
		update_post_meta( $property_id, 'wpestate_property_gallery', $attachment_ids );
		return true;
	}

	/**
	 * Persist only WpResidence-specific fields, defaults, and agent linkage.
	 *
	 * Shared selection, normalization, mappings, Rooms, and order have already
	 * been resolved by Stored Listing Write. Existing listings skip the defaults
	 * and Assigned Agent so later task changes cannot reassign them.
	 *
	 * @param int                  $property_id Managed Listing post ID.
	 * @param array<string, mixed> $property    Raw incoming property.
	 * @param array<string, mixed> $context     Prepared fields and creation choices.
	 * @return bool Whether the theme projection completed.
	 */
	public function write_theme_projection( int $property_id, array $property, array $context ): bool {
		foreach ( (array) ( $context['fields'] ?? array() ) as $field ) {
			// GitHub issue #286: 'listingkey' is the same case-insensitive meta row
			// as the listing identity, and a WPResidence custom field the edit
			// screen saves blank. The identity is never projected as theme meta.
			if ( 'listingkey' === strtolower( (string) $field['field'] ) ) {
				continue;
			}
			update_post_meta( $property_id, strtolower( (string) $field['field'] ), (string) $field['value'] );
		}
		if ( empty( $context['is_new'] ) ) {
			return true;
		}

		update_post_meta( $property_id, 'prop_featured', 0 );
		update_post_meta( $property_id, 'page_custom_zoom', 16 );
		// Country comes from the feed itself (Centris sends "CA"); the helper
		// falls back to the historical "United States" default when absent.
		update_post_meta( $property_id, 'property_country', mlsimport_property_country( $property ) );
		update_post_meta( $property_id, 'property_agent', (int) ( $context['assigned_agent_id'] ?? 0 ) );
		update_post_meta( $property_id, 'property_page_desing_local', '' );
		foreach ( array( 'header_transparent', 'page_show_adv_search', 'sidebar_agent_option', 'local_pgpr_slider_type', 'local_pgpr_content_type', 'sidebar_select', 'sidebar_option' ) as $key ) {
			update_post_meta( $property_id, $key, 'global' );
		}
		update_post_meta( $property_id, 'header_type', 0 );
		if ( function_exists( 'wpestate_update_hiddent_address_single' ) ) {
			wpestate_update_hiddent_address_single( $property_id );
		}
		return true;
	}



	/**
	 * return custom post field
	 *
	 * The post type(s) WpResidence uses for agents, agencies, and developers.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @return   array  The agent/agency/developer post-type slugs.
	 */
	public function get_agent_post_type() {
		// WpResidence splits contacts across three post types.
		return array('estate_agent','estate_agency','estate_developer');
	}



	/**
	 * save custom fields per environment
	 *
	 * Syncs the plugin's selected MLS fields into WpResidence's custom-fields registry
	 * (stored in 'wpresidence_admin' -> 'wpestate_custom_fields_list'). Enabled,
	 * non-admin, non-taxonomy fields are added/updated; others removed; list re-sorted.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @param    string  $option_name  Option prefix used to read '{prefix}_admin_fields_select'.
	 */
	public function enviroment_custom_fields( $option_name ) {
                // Load the theme option holding the custom-fields registry.
                $theme_options = get_option( 'wpresidence_admin' );
                $custom_fields = array();
                if ( isset( $theme_options['wpestate_custom_fields_list'] ) ) {
                        $custom_fields = $theme_options['wpestate_custom_fields_list'];
                }

                // Ensure we have an array to work with.
                if ( ! is_array( $custom_fields ) ) {
                        $custom_fields = array();
                }

                // Guarantee every parallel column exists as an array.
                foreach ( array( 'add_field_name', 'add_field_label', 'add_field_type', 'add_field_order', 'add_dropdown_order' ) as $field_key ) {
                        if ( ! isset( $custom_fields[ $field_key ] ) || ! is_array( $custom_fields[ $field_key ] ) ) {
                                $custom_fields[ $field_key ] = array();
                        }
                }

                // Load the plugin's field-selection options for this prefix.
                $options       = mlsimport_normalized_field_configuration();
                $active_fields = array_fill_keys( array_keys( mlsimport_field_configuration_metadata() ), true );

                // Reconcile each MLS field against the theme registry.
                foreach ( $options['mls-fields'] as $key => $value ) {
                        // Per-field flags: enabled, admin-managed, taxonomy target, order.
                        $import   = intval( $value );
                        $admin    = isset( $options['mls-fields-admin'][ $key ] ) ? intval( $options['mls-fields-admin'][ $key ] ) : 0;
                        $taxonomy = isset( $options['mls-fields-map-taxonomy'][ $key ] ) ? $options['mls-fields-map-taxonomy'][ $key ] : '';
                        $order_value = isset( $options['field_order'][ $key ] ) ? intval( $options['field_order'][ $key ] ) + 100 : 100;

                        // Add/update only enabled, non-admin, non-taxonomy fields.
                        if ( isset( $active_fields[ $key ] ) && 1 === $import && 0 === $admin && '' === $taxonomy ) {
                                // Is this field already registered?
                                $existing_index = array_search( $key, $custom_fields['add_field_name'], true );

                                if ( false === $existing_index ) {
                                        // New field: append across all parallel columns.
                                        $custom_fields['add_field_name'][]     = $key;
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $custom_fields['add_field_label'][]    = $label;
                                        $custom_fields['add_field_type'][]     = 'short text';
                                        $custom_fields['add_field_order'][]    = $order_value;
                                        $custom_fields['add_dropdown_order'][] = '';
                                } else {
                                        // Existing field: refresh its label and order in place.
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $custom_fields['add_field_label'][ $existing_index ] = $label;
                                        $custom_fields['add_field_order'][ $existing_index ] = $order_value;
                                }
                        } else {
                                // Not eligible: remove it from every parallel column if present.
                                $remove_index = array_search( $key, $custom_fields['add_field_name'], true );
                                if ( false !== $remove_index ) {
                                        unset( $custom_fields['add_field_name'][ $remove_index ] );
                                        unset( $custom_fields['add_field_label'][ $remove_index ] );
                                        unset( $custom_fields['add_field_type'][ $remove_index ] );
                                        unset( $custom_fields['add_field_order'][ $remove_index ] );
                                        unset( $custom_fields['add_dropdown_order'][ $remove_index ] );
                                }
                        }
                }

                // Re-sort all columns by field order so the registry stays ordered.
                if ( ! empty( $custom_fields['add_field_order'] ) ) {
                        // Sort the order column, preserving keys as the new sequence.
                        asort( $custom_fields['add_field_order'] );
                        $ordered = array(
                                'add_field_name'     => array(),
                                'add_field_label'    => array(),
                                'add_field_type'     => array(),
                                'add_field_order'    => array(),
                                'add_dropdown_order' => array(),
                        );
                        // Rebuild each column following the sorted order of indices.
                        foreach ( array_keys( $custom_fields['add_field_order'] ) as $idx ) {
                                $ordered['add_field_name'][]     = $custom_fields['add_field_name'][ $idx ];
                                $ordered['add_field_label'][]    = $custom_fields['add_field_label'][ $idx ];
                                $ordered['add_field_type'][]     = $custom_fields['add_field_type'][ $idx ];
                                $ordered['add_field_order'][]    = $custom_fields['add_field_order'][ $idx ];
                                $ordered['add_dropdown_order'][] = $custom_fields['add_dropdown_order'][ $idx ];
                        }
                        $custom_fields = $ordered;
                }

                // Persist the updated registry back to the theme option.
                $theme_options['wpestate_custom_fields_list'] = $custom_fields;
                update_option( 'wpresidence_admin', $theme_options );

        }






	/**
	 * return theme schema
	 *
	 * No-op placeholder: WpResidence provides no field schema through this adapter.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function return_theme_schema() {
		// No schema to return for this theme.
		return;
	}


    
}
