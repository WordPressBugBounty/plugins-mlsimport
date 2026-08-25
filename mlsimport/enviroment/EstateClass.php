<?php 
	/**
 * Theme adapter: WpEstate.
 *
 * One of the per-theme adapters in enviroment/. Maps RESO-standard MLS data
 * onto WpEstate's post type (estate_property), agent post type (estate_agent),
 * post meta keys and its wp_estate_custom_fields / wp_estate_feature_list
 * options. Shared persistence stays in Stored Listing Write; this adapter owns
 * WpEstate feature flags, creation defaults, and custom-field registration.
 *
 * @package MLSImport
 */
if ( ! defined( 'ABSPATH' ) ) {
	// Block direct web access — only load when WordPress is bootstrapped.
	exit; // Exit if accessed directly
}

/**
 * Persist genuine WpEstate variation behind the Stored adapter seam.
 *
 * @author mlsimport
 */
class EstateClass {

	/**
	 * No setup required — the adapter is stateless.
	 */
	public function __construct() {
	}


	/**
	 * return custom post field
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function get_property_post_type() {
		// WpEstate's property CPT slug — imported listings are stored here.
		return 'estate_property';
	}

	/**
	 * Return the WpEstate post type used for Managed Listing lookup/write.
	 *
	 * @return string WpEstate property post-type slug.
	 */
	public function property_post_type(): string {
		return 'estate_property';
	}

	/**
	 * Keep WpEstate's existing no-gallery-meta behavior.
	 *
	 * @param int             $property_id   Managed Listing post ID.
	 * @param array<int, int> $attachment_ids Final ordered attachment IDs.
	 * @return bool Always true because this theme stores no gallery meta here.
	 */
	public function write_gallery( int $property_id, array $attachment_ids ): bool {
		unset( $property_id, $attachment_ids );
		return true;
	}

	/**
	 * Persist WpEstate's plain custom fields and creation-time defaults.
	 *
	 * @param int                  $property_id Managed Listing post ID.
	 * @param array<string, mixed> $property    Raw incoming property.
	 * @param array<string, mixed> $context     Prepared fields and creation choices.
	 * @return bool Whether the theme projection completed.
	 */
	public function write_theme_projection( int $property_id, array $property, array $context ): bool {
		foreach ( (array) ( $context['fields'] ?? array() ) as $field ) {
			// GitHub issue #286: the identity field is never projected as theme
			// meta — its lowercased row is the same case-insensitive meta row as
			// the listing identity and an edit-screen save could blank it.
			if ( 'listingkey' === strtolower( (string) $field['field'] ) ) {
				continue;
			}
			update_post_meta( $property_id, strtolower( (string) $field['field'] ), (string) $field['value'] );
		}
		$this->write_property_features( $property_id, $property );
		if ( empty( $context['is_new'] ) ) {
			return true;
		}

		update_post_meta( $property_id, 'prop_featured', 0 );
		update_post_meta( $property_id, 'page_custom_zoom', 16 );
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
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function get_agent_post_type() {
		// WpEstate's agent CPT slug — imported agents are stored here.
		return 'estate_agent';
	}

/**
	 * Persist WpEstate feature flags and its theme-wide feature registry.
	 *
	 * The RESO feature collection may contain scalar names or one nested list.
	 * Each usable name becomes a sanitized boolean post-meta flag. The theme
	 * registry is normalized once and saved only when a new name is discovered.
	 *
	 * @param int                  $property_id Managed Listing post ID.
	 * @param array<string, mixed> $property    Raw incoming property.
	 * @return void
	 */
	private function write_property_features( int $property_id, array $property ): void {
		$raw_features = $property['meta']['property_features'] ?? array();
		if ( ! is_array( $raw_features ) ) {
			return;
		}

		$feature_names = array();
		foreach ( $raw_features as $raw_feature ) {
			foreach ( is_array( $raw_feature ) ? $raw_feature : array( $raw_feature ) as $feature_name ) {
				$feature_name = trim( (string) $feature_name );
				if ( '' !== $feature_name ) {
					$feature_names[] = $feature_name;
				}
			}
		}

		$registered = array_values(
			array_filter(
				array_map( 'trim', explode( ',', (string) get_option( 'wp_estate_feature_list', '' ) ) )
			)
		);
		$changed = false;
		foreach ( array_values( array_unique( $feature_names ) ) as $feature_name ) {
			$meta_key = sanitize_key( sanitize_title( str_replace( ' ', '_', $feature_name ) ) );
			if ( '' !== $meta_key ) {
				update_post_meta( $property_id, $meta_key, 1 );
			}
			if ( ! in_array( $feature_name, $registered, true ) ) {
				$registered[] = $feature_name;
				$changed      = true;
			}
		}
		if ( $changed ) {
			update_option( 'wp_estate_feature_list', implode( ',', $registered ) );
		}
	}

	/**
	 * save custom fields per environment
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function enviroment_custom_fields( $option_name ) {

		// Existing WpEstate custom-field definitions (each a numeric array row).
		$custom_fields = get_option( 'wp_estate_custom_fields', true );

		// Ensure we always work with an array.
		if ( ! is_array( $custom_fields ) ) {
			$custom_fields = array();
		}
		// Starting order index for newly added custom fields.
		$custom_field_no = 100;
		$options         = mlsimport_normalized_field_configuration();
		$active_fields   = array_fill_keys( array_keys( mlsimport_field_configuration_metadata() ), true );

		foreach ( $options['mls-fields'] as $key => $value ) {
			// Field is enabled AND not flagged as admin-only → it becomes a theme custom field.
			if ( isset( $active_fields[ $key ] ) && 1 === intval($value)  && 0 === intval( $options['mls-fields-admin'][ $key ])  ) {
				// Not already registered (and non-empty key) → add a new custom-field row.
				if ( ! in_array( $key, array_column( $custom_fields, 0 ) ) && '' !== $key  ) {
					++$custom_field_no;
					$temp_array    = array();
					$temp_array[0] = $key;
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $temp_array[1] = $label;

					$temp_array[2]   = 'short text';
					$temp_array[3]   = $custom_field_no;
					$custom_fields[] = $temp_array;
				} else {
					// Already registered → just refresh its label (index 1).
					$to_replace_key                        = array_search( $key, array_column( $custom_fields, 0 ) );
                                        $label = isset( $options['mls-fields-label'][ $key ] ) && '' !== $options['mls-fields-label'][ $key ] ? $options['mls-fields-label'][ $key ] : $key;
                                        $custom_fields[ $to_replace_key ]['1'] = $label;
				}
			} else {
				// remove item from custom fields
				$key_remove = $this->searchForId( $key, $custom_fields );

				// Drop the row when a matching definition exists.
				if ( null !== $key_remove ) {
					unset( $custom_fields[ $key_remove ] );
				}
			}
		}

		// Persist the rebuilt custom-field list back to the theme option.
		update_option( 'wp_estate_custom_fields', $custom_fields );
	}


	/**
	 * Find the array index of a custom-field row whose first element equals $id.
	 *
	 * @param string $id    The field key to look for (matched against $val[0]).
	 * @param array  $array Rows of custom-field definitions.
	 * @return int|string|null Matching key, or null when not found.
	 */
	public function searchForId( $id, $array ) {
		// Linear scan comparing each row's key column (index 0).
		foreach ( $array as $key => $val ) {
			if ( $val[0] === $id ) {
				return $key;
			}
		}
		// No match.
		return null;
	}



	/**
	 * return theme schema
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function return_theme_schema() {

		// No schema exposed for WpEstate — returns nothing.
		return;
	}
}
