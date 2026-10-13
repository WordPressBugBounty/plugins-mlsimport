<?php
/**
 * AI tools (MCP), standalone mode only: removing a property's photo, and a
 * property.
 *
 * The tool delete-listing-image does what the Delete button of the edit screen's
 * Media tab does (Mlsimport_Property_Metabox::delete_gallery_image()): the
 * image file is deleted for good. delete-listing deletes one property for
 * good, with its photos and its row in the fast search table. Both need two
 * calls like every delete (mlsimport-mcp-confirm.php) and work only on a
 * property post; the id comes from search-listings.
 *
 * Public interface:
 *   mlsimport_mcp_standalone_listing_delete_tools()   the tools of this file
 *   mlsimport_mcp_delete_listing_image( $input )      tool: delete-listing-image
 *   mlsimport_mcp_delete_listing( $input )            tool: delete-listing
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file, as the tool list expects them.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_standalone_listing_delete_tools(): array {
	$post_id = array(
		'type'        => 'integer',
		'description' => 'The WordPress post id of the property, from search-listings.',
	);
	$code    = array(
		'type'        => 'string',
		'description' => 'The confirmation_code returned by the first call. Leave out on the first call.',
	);

	return array(
		'delete-listing-image' => array(
			'label'       => 'Delete a photo of a listing',
			'description' => 'Deletes one photo of a property for good, as the edit screen\'s Media tab does: it leaves the gallery and the image file is deleted. When it was the featured image, the next photo takes over. The attachment ids come from get-listing (gallery). Needs two calls: the first call deletes nothing and returns what would be deleted and a confirmation_code; tell the client, and only after they confirm call again with the same inputs and that code.',
			'input'       => array(
				'post_id'           => $post_id,
				'attachment_id'     => array(
					'type'        => 'integer',
					'description' => 'The attachment id of the photo, from get-listing.',
				),
				'confirmation_code' => $code,
			),
			'required'    => array( 'post_id', 'attachment_id' ),
			'callback'    => 'mlsimport_mcp_delete_listing_image',
			'changes'     => true,
			'destructive' => true,
		),
		'delete-listing'       => array(
			'label'       => 'Delete a listing',
			'description' => 'Deletes one property for good, with its photos; it cannot be restored. The next import adds it again when the MLS still has it and an import task matches it. Needs two calls: the first call deletes nothing and returns what would be deleted and a confirmation_code; tell the client, and only after they confirm call again with the same inputs and that code.',
			'input'       => array(
				'post_id'           => $post_id,
				'confirmation_code' => $code,
			),
			'required'    => array( 'post_id' ),
			'callback'    => 'mlsimport_mcp_delete_listing',
			'changes'     => true,
			'destructive' => true,
		),
	);
}

/**
 * Tool delete-listing-image: delete one photo of a property (two calls).
 *
 * Step by step:
 * 1. The post must be a property and the photo must be in its gallery.
 * 2. First call: say what would be deleted and give a confirmation code.
 * 3. Confirmed: the edit screen's own delete.
 *
 * @param array $input post_id, attachment_id, confirmation_code.
 * @return array|WP_Error The preview; or remaining and featured (as the edit screen answers); or the refusal.
 */
function mlsimport_mcp_delete_listing_image( array $input ) {
	// Step 1.
	$post_id       = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
	$refusal       = mlsimport_mcp_require_post( $post_id, 'mlsimport_property', __( 'That is not a property. Find the post id with search-listings.', 'mlsimport' ) );
	if ( null !== $refusal ) {
		return $refusal;
	}
	if ( ! in_array( $attachment_id, array_map( 'intval', mlsimport_property_gallery_ids( $post_id ) ), true ) ) {
		return new WP_Error( 'mlsimport_listing_image_unknown', __( 'That photo is not in this property\'s gallery. get-listing shows the gallery.', 'mlsimport' ) );
	}

	// Step 2.
	$pending = mlsimport_mcp_confirm_or_preview(
		'delete-listing-image',
		$input,
		sprintf(
			/* translators: 1: attachment id, 2: property title, 3: post id. */
			__( 'Photo %1$d of the property "%2$s" (%3$d) is deleted for good, with its image file; it cannot be restored.', 'mlsimport' ),
			$attachment_id,
			get_the_title( $post_id ),
			$post_id
		)
	);
	if ( null !== $pending ) {
		return $pending;
	}

	// Step 3.
	$result = Mlsimport_Property_Metabox::delete_gallery_image( $post_id, $attachment_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return array(
		'deleted' => true,
		'post_id' => $post_id,
	) + $result;
}

/**
 * Tool delete-listing: delete one property for good (two calls).
 *
 * Step by step:
 * 1. The post must be a property.
 * 2. First call: say what would be deleted and give a confirmation code.
 * 3. Confirmed: delete the photos (they belong to the listing), the post,
 *    and its row in the fast search table.
 *
 * @param array $input post_id, confirmation_code.
 * @return array|WP_Error The preview, "deleted", or the refusal.
 */
function mlsimport_mcp_delete_listing( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$refusal = mlsimport_mcp_require_post( $post_id, 'mlsimport_property', __( 'That is not a property. Find the post id with search-listings.', 'mlsimport' ) );
	if ( null !== $refusal ) {
		return $refusal;
	}
	$gallery = mlsimport_property_gallery_ids( $post_id );
	$pending = mlsimport_mcp_confirm_or_preview(
		'delete-listing',
		$input,
		sprintf(
			/* translators: 1: property title, 2: post id, 3: number of photos. */
			__( 'The property "%1$s" (%2$d) and its %3$d photos are deleted for good; they cannot be restored.', 'mlsimport' ),
			get_the_title( $post_id ),
			$post_id,
			count( $gallery )
		)
	);
	if ( null !== $pending ) {
		return $pending;
	}
	foreach ( $gallery as $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	}
	if ( ! wp_delete_post( $post_id, true ) ) {
		return new WP_Error( 'mlsimport_listing_not_deleted', __( 'The property could not be deleted.', 'mlsimport' ) );
	}
	Mlsimport_Standalone_Row::delete( $post_id );
	return array(
		'deleted' => true,
		'post_id' => $post_id,
	);
}
