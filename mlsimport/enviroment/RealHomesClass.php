<?php
/**
 * RealHomesClass — theme adapter for the "Real Homes" (Inspiry) WordPress theme.
 *
 * One of the plugin's theme adapters (see enviroment/ directory). Each adapter maps
 * RESO-standard MLS property data onto the post type + post-meta conventions of a
 * specific real-estate theme. This class targets Real Homes, whose property meta keys
 * are prefixed REAL_HOMES_* / inspiry_*.
 *
 * Stored Listing Write owns shared selection, normalization, and WordPress
 * persistence. This adapter keeps only the Real Homes gallery/additional-details
 * representation, creation defaults, custom-field registry, and remote-image
 * display filters that genuinely vary by theme.
 *
 * @package MLSImport
 */
// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Persist genuine Real Homes variation behind the Stored adapter seam.
 *
 * @author mlsimport
 */
class RealHomesClass {

	/**
	 * Register the filters that make remote MLS images render like local attachments.
	 */
	public function __construct() {
				// Enable support for remote MLS images in media JS and thumbnails
		// Inject remote URL/sizes into the media library's JS attachment payload.
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'inject_remote_image_data' ], 20 );
		// Short-circuit image_downsize so wp_get_attachment_image() returns the remote URL.
		add_filter( 'image_downsize', [ $this, 'override_image_downsize' ], 10, 3 );
	}


	/**
	 * return custom post field
	 *
	 * The post type Real Homes uses to store property listings.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @return   string  The 'property' post type slug.
	 */
	public function get_property_post_type() {
		// Real Homes stores listings in the 'property' post type.
		return 'property';
	}

	/**
	 * Return the Real Homes post type used for Managed Listing lookup/write.
	 *
	 * @return string Real Homes property post-type slug.
	 */
	public function property_post_type(): string {
		return 'property';
	}

	/**
	 * Replace Real Homes' repeated gallery-meta rows with the final ordered set.
	 *
	 * @param int             $property_id   Managed Listing post ID.
	 * @param array<int, int> $attachment_ids Final ordered attachment IDs.
	 * @return bool Whether the gallery representation was written.
	 */
	public function write_gallery( int $property_id, array $attachment_ids ): bool {
		delete_post_meta( $property_id, 'REAL_HOMES_property_images' );
		foreach ( $attachment_ids as $attachment_id ) {
			add_post_meta( $property_id, 'REAL_HOMES_property_images', (int) $attachment_id );
		}
		return true;
	}

	/**
	 * Persist Real Homes coordinates, details, and creation-time defaults.
	 *
	 * @param int                  $property_id Managed Listing post ID.
	 * @param array<string, mixed> $property    Raw incoming property.
	 * @param array<string, mixed> $context     Prepared fields and creation choices.
	 * @return bool Whether the theme projection completed.
	 */
	public function write_theme_projection( int $property_id, array $property, array $context ): bool {
		$meta = is_array( $property['meta'] ?? null ) ? $property['meta'] : array();
		if ( isset( $meta['property_longitude'], $meta['property_latitude'] ) ) {
			update_post_meta( $property_id, 'REAL_HOMES_property_location', (string) $meta['property_latitude'] . ',' . (string) $meta['property_longitude'] );
		}

		$details = array();
		foreach ( (array) ( $context['fields'] ?? array() ) as $field ) {
			// GitHub issue #286: the identity field is never projected as theme
			// meta or an additional-details row; its lowercased form collides
			// with the listing identity under case-insensitive meta keys.
			if ( 'listingkey' === strtolower( (string) $field['field'] ) ) {
				continue;
			}
			if ( '' === (string) ( $field['value'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $field['admin'] ) ) {
				update_post_meta( $property_id, strtolower( (string) $field['label'] ), (string) $field['value'] );
				continue;
			}
			$details[ (string) $field['label'] ] = (string) $field['value'];
		}
		update_post_meta( $property_id, 'REAL_HOMES_additional_details', $details );
		update_post_meta( $property_id, 'REAL_HOMES_additional_details_list', $details );
		if ( empty( $context['is_new'] ) ) {
			return true;
		}

		update_post_meta( $property_id, 'REAL_HOMES_featured', 0 );
		update_post_meta( $property_id, 'REAL_HOMES_agent_display_option', 'agent_info' );
		update_post_meta( $property_id, 'REAL_HOMES_agents', (int) ( $context['assigned_agent_id'] ?? 0 ) );
		update_post_meta( $property_id, 'REAL_HOMES_property_map', 0 );
		update_post_meta( $property_id, 'inspiry_property_label_color', '#fb641c' );
		if ( '' === get_post_meta( $property_id, 'REAL_HOMES_property_size_postfix', true ) ) {
			update_post_meta( $property_id, 'REAL_HOMES_property_size_postfix', 'Sq Ft' );
		}
		if ( '' === get_post_meta( $property_id, 'REAL_HOMES_property_lot_size_postfix', true ) ) {
			update_post_meta( $property_id, 'REAL_HOMES_property_lot_size_postfix', 'Sq Ft' );
		}
		return true;
	}



	/**
	 * return custom post field
	 *
	 * The post type(s) Real Homes uses for agents/agencies.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * @return   array  The 'agency' and 'agent' post-type slugs.
	 */
	public function get_agent_post_type() {
		// Real Homes represents contacts as both 'agency' and 'agent' post types.
		return array('agency','agent');
	}


	/**
         * save custom fields per environment
         *
         * Syncs the plugin's selected MLS fields into the theme's custom-fields registry
         * (stored in the 'wpresidence_admin' option as 'wpestate_custom_fields_list').
         * Enabled non-admin, non-taxonomy fields are added/updated; others are removed.
         * Finally the list is re-sorted by field order.
         *
         * @since    1.0.0
         * @access   protected
         * @var      string    $plugin_name
         * @param    string  $option_name  Option prefix used to read '{prefix}_admin_fields_select'.
         */
	public function enviroment_custom_fields( $option_name ) {
                // Load the theme option that holds the custom-fields registry.
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
                $options = mlsimport_normalized_field_configuration();
                if ( ! is_array( $options ) ) {
                        $options = array();
                }
                // Extract the sub-maps that decide import/admin/taxonomy/order per field.
                $mls_fields       = isset( $options['mls-fields'] ) && is_array( $options['mls-fields'] ) ? $options['mls-fields'] : array();
                $mls_fields_admin = isset( $options['mls-fields-admin'] ) && is_array( $options['mls-fields-admin'] ) ? $options['mls-fields-admin'] : array();
                $mls_fields_tax   = isset( $options['mls-fields-map-taxonomy'] ) && is_array( $options['mls-fields-map-taxonomy'] ) ? $options['mls-fields-map-taxonomy'] : array();
                $field_order      = isset( $options['field_order'] ) && is_array( $options['field_order'] ) ? $options['field_order'] : array();
                $active_fields    = array_fill_keys( array_keys( mlsimport_field_configuration_metadata() ), true );

                // Reconcile each MLS field against the theme's custom-fields registry.
                foreach ( $mls_fields as $key => $value ) {
                        // Per-field flags: enabled, admin-managed, taxonomy target, order.
                        $import      = intval( $value );
                        $admin       = isset( $mls_fields_admin[ $key ] ) ? intval( $mls_fields_admin[ $key ] ) : 0;
                        $taxonomy    = isset( $mls_fields_tax[ $key ] ) ? $mls_fields_tax[ $key ] : '';
                        $order_value = isset( $field_order[ $key ] ) ? intval( $field_order[ $key ] ) + 100 : 100;

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
                        // Sort the order column, preserving its keys as the new sequence.
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
	 * No-op placeholder: Real Homes provides no field schema through this adapter.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function return_theme_schema() {
		// No schema to return for this theme.
		return;
	}

	/**
	 * Filter wp_prepare_attachment_for_js to inject remote image URL and fake sizes.
	 *
	 * This ensures Meta Box and the WordPress media library display thumbnails
	 * for images that are stored remotely (i.e., imported via MLS).
	 *
	 * @param array $response Attachment response data prepared for JS.
	 * @return array Modified response with remote image URL and sizes if applicable.
	 */
	public function inject_remote_image_data( $response ) {
		// Nothing to do without an attachment ID.
		if ( empty( $response['id'] ) ) {
			return $response;
		}

		$attachment_id = $response['id'];

		// Only modify attachments imported by MLS
		if ( intval( get_post_meta( $attachment_id, 'is_mlsimport', true ) ) === 1 ) {
			// Bail if the attachment post cannot be loaded.
			$attachment = get_post( $attachment_id );
			if ( ! $attachment ) {
				return $response;
			}

			// Determine the correct remote URL
			// Prefer a valid URL in the guid, else fall back to the stored external URL.
			$url = filter_var( $attachment->guid, FILTER_VALIDATE_URL )
				? $attachment->guid
				: get_post_meta( $attachment_id, 'houzez_external_url', true );

			// Inject the URL and fake thumbnail size into the response
			if ( $url ) {
				$response['url'] = $url;
				$response['sizes'] = [
					'thumbnail' => [
						'url'    => $url,
						'width'  => 150,
						'height' => 150,
					],
				];
			
			}
		}

		return $response;
	}

	/**
	 * Filter image_downsize to return remote image data instead of local file paths.
	 *
	 * This enables WordPress functions like wp_get_attachment_image() to work
	 * with remote images by returning a URL and fake dimensions.
	 *
	 * @param bool|array $out  Whether to short-circuit the image downsize. Default false.
	 * @param int        $id   Attachment ID.
	 * @param string|array $size Requested image size.
	 * @return array|false Array of image data (URL, width, height, crop) or false to fall back.
	 */
	public function override_image_downsize( $out, $id, $size ) {
		// Only override for MLS-imported attachments
		if ( intval( get_post_meta( $id, 'is_mlsimport', true ) ) !== 1 ) {
			return false;
		}

		// Bail if the attachment post cannot be loaded.
		$attachment = get_post( $id );
		if ( ! $attachment ) {
			return false;
		}

		// Determine the remote image URL
		// Prefer a valid URL in the guid, else the stored external URL.
		$url = filter_var( $attachment->guid, FILTER_VALIDATE_URL )
			? $attachment->guid
			: get_post_meta( $id, 'houzez_external_url', true );

		// No URL resolved -> let WordPress handle it normally.
		if ( ! $url ) {
			return false;
		}

	

		// Return URL with fake dimensions (used for thumbnail display)
		return [ $url, 150, 150, false ];
	}
}
