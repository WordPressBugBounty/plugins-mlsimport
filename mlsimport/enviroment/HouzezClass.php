<?php 
/**
 * Theme adapter: Houzez.
 *
 * One of the per-theme adapters in enviroment/. Maps RESO-standard MLS data
 * onto Houzez post meta (fave_* keys), its property/agent post types, gallery,
 * and the additional_features repeater. Shared field normalization and listing
 * persistence stay in Stored Listing Write. Remote-image display filters remain
 * here because their representation is specific to Houzez.
 *
 * @package MLSImport
 */
if ( ! defined( 'ABSPATH' ) ) {
	// Block direct web access — only load when WordPress is bootstrapped.
	exit; // Exit if accessed directly
}

/**
 * Persist genuine Houzez variation behind the Stored adapter seam.
 *
 * @author mlsimport
 */
class HouzezClass {


	/**
	 * Wire the filters that let remote MLS images render through Houzez/WP media.
	 */
	public function __construct() {
		// Enable support for remote MLS images in media JS and thumbnails
		// Inject remote URL + fake sizes into media-library JS payloads.
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'inject_remote_image_data' ], 20 );
		// Short-circuit image_downsize so WP returns the remote URL + fake dimensions.
		add_filter( 'image_downsize', [ $this, 'override_image_downsize' ], 10, 3 );
	}


	/**
	 * return custom post field
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	public function get_property_post_type() {
		// Houzez property CPT slug — imported listings are stored here.
		return 'property';
	}

	/**
	 * Return the Houzez post type used for Managed Listing lookup/write.
	 *
	 * @return string Houzez property post-type slug.
	 */
	public function property_post_type(): string {
		return 'property';
	}

	/**
	 * Replace Houzez's repeated gallery-meta rows with one ordered final set.
	 *
	 * @param int             $property_id   Managed Listing post ID.
	 * @param array<int, int> $attachment_ids Final ordered attachment IDs.
	 * @return bool Whether the gallery representation was written.
	 */
	public function write_gallery( int $property_id, array $attachment_ids ): bool {
		delete_post_meta( $property_id, 'fave_property_images' );
		foreach ( $attachment_ids as $attachment_id ) {
			add_post_meta( $property_id, 'fave_property_images', (int) $attachment_id );
		}
		return true;
	}

	/**
	 * Persist Houzez coordinates, additional features, and creation defaults.
	 *
	 * Field values arrive selected, normalized, and ordered. Administrator-only
	 * rows remain plain post meta; public rows become Houzez repeater entries.
	 * Assigned Agent and display defaults are written only on creation.
	 *
	 * @param int                  $property_id Managed Listing post ID.
	 * @param array<string, mixed> $property    Raw incoming property.
	 * @param array<string, mixed> $context     Prepared fields and creation choices.
	 * @return bool Whether the theme projection completed.
	 */
	public function write_theme_projection( int $property_id, array $property, array $context ): bool {
		$meta = is_array( $property['meta'] ?? null ) ? $property['meta'] : array();
		$longitude = $meta['houzez_geolocation_long'] ?? ( $meta['property_longitude'] ?? null );
		$latitude  = $meta['houzez_geolocation_lat'] ?? ( $meta['property_latitude'] ?? null );
		if ( null !== $longitude && null !== $latitude ) {
			$location = (string) $latitude . ',' . (string) $longitude;
			update_post_meta( $property_id, 'fave_property_location', $location );
			update_post_meta( $property_id, 'property_location', $location );
		}

		$features = array();
		foreach ( (array) ( $context['fields'] ?? array() ) as $field ) {
			if ( '' === (string) ( $field['value'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $field['admin'] ) ) {
				update_post_meta( $property_id, strtolower( (string) $field['label'] ), (string) $field['value'] );
				continue;
			}
			$features[] = array(
				'fave_additional_feature_title' => (string) $field['label'],
				'fave_additional_feature_value' => (string) $field['value'],
			);
		}
		update_post_meta( $property_id, 'additional_features', $features );
		if ( empty( $context['is_new'] ) ) {
			return true;
		}

		update_post_meta( $property_id, 'fave_agents', (int) ( $context['assigned_agent_id'] ?? 0 ) );
		update_post_meta( $property_id, 'fave_agent_display_option', 'agent_info' );
		update_post_meta( $property_id, 'fave_featured', 0 );
		update_post_meta( $property_id, 'fave_property_map', '1' );
		update_post_meta( $property_id, 'fave_property_map_street_view', 'show' );
		update_post_meta( $property_id, 'fave_single_top_area', 'global' );
		update_post_meta( $property_id, 'fave_single_content_area', 'global' );
		update_post_meta( $property_id, 'fave_additional_features_enable', 'enable' );
		if ( '' === get_post_meta( $property_id, 'fave_property_size_prefix', true ) ) {
			update_post_meta( $property_id, 'fave_property_size_prefix', 'Sq Ft' );
		}
		if ( '' === get_post_meta( $property_id, 'fave_property_land_postfix', true ) ) {
			update_post_meta( $property_id, 'fave_property_land_postfix', 'Sq Ft' );
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
		// Houzez stores contacts under two CPTs: agency and agent.
		return array('houzez_agency','houzez_agent');
	}



/**
	 * Keep the shared admin callback compatible when Houzez needs no registry.
	 *
	 * Houzez stores additional details directly per listing and has no separate
	 * custom-field option that must be synchronized by this plugin.
	 *
	 * @param string $option_name Legacy plugin option prefix.
	 * @return void
	 */
	public function enviroment_custom_fields( $option_name ) {
		unset( $option_name );
	}

	/**
	 * return theme schema
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 * property_type - is type, property_status is action,property_feature is features,property_label is status,
	 * property_city is city, property_area is area,property_state is county state
	 */
	public function return_theme_schema() {
		// No schema returned for Houzez (see the taxonomy mapping notes above).

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
		if ( empty( $response['id'] ) ) {
			return $response;
		}

		$attachment_id = $response['id'];

		// Only modify attachments imported by MLS
		if ( intval( get_post_meta( $attachment_id, 'is_mlsimport', true ) ) === 1 ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment ) {
				return $response;
			}

			// Determine the correct remote URL
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

		$attachment = get_post( $id );
		if ( ! $attachment ) {
			return false;
		}

		// Determine the remote image URL
		$url = filter_var( $attachment->guid, FILTER_VALIDATE_URL )
			? $attachment->guid
			: get_post_meta( $id, 'houzez_external_url', true );

		if ( ! $url ) {
			return false;
		}

		

		// Return URL with fake dimensions (used for thumbnail display)
		return [ $url, 150, 150, false ];
	}


}
