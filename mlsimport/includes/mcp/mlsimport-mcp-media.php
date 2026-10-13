<?php
/**
 * AI tools (MCP): the Media Library.
 *
 * The other tools take images by attachment id (a hero background, an
 * agent photo, a category image). These two tools give the AI those ids:
 * list-media finds the images the Media Library has, upload-media brings a
 * new one in from a URL, as "Add New" on the Media screen does with a file.
 *
 * Public interface:
 *   mlsimport_mcp_media_tools()          the tools of this file
 *   mlsimport_mcp_media_summary( $id )   one image, as the answers show it
 *   mlsimport_mcp_list_media( $input )   tool: list-media
 *   mlsimport_mcp_upload_media( $input ) tool: upload-media
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Images per list-media answer. */
const MLSIMPORT_MCP_MEDIA_PER_PAGE = 50;

/**
 * The AI tools of this file, as the tool list expects them.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_media_tools(): array {
	return array(
		'list-media'   => array(
			'label'       => 'Images in the Media Library',
			'description' => 'Lists the images of the Media Library, newest first, 50 per page: id, title, url, width, height and type. search narrows them by title or file name. The ids are what the other tools take as an image (image_id, photo_id, a background_image setting).',
			'input'       => array(
				'search' => array(
					'type'        => 'string',
					'description' => 'Only the images whose title or file name contains this text.',
				),
				'page'   => array(
					'type'        => 'integer',
					'description' => 'The page of results (1 is the first).',
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_media',
			'changes'     => false,
		),
		'upload-media' => array(
			'label'       => 'Upload an image from a URL',
			'description' => 'Downloads an image from a URL into the Media Library and answers with its id and url, to use in the other tools (a hero background_image, an agent photo, a category image). title and alt are optional.',
			'input'       => array(
				'url'   => array(
					'type'        => 'string',
					'description' => 'The http(s) address of the image file.',
				),
				'title' => array( 'type' => 'string' ),
				'alt'   => array(
					'type'        => 'string',
					'description' => 'The alternative text of the image.',
				),
			),
			'required'    => array( 'url' ),
			'callback'    => 'mlsimport_mcp_upload_media',
			'changes'     => true,
		),
	);
}

/**
 * One image as the answers show it: id, title, url, width, height, mime.
 *
 * @param int $attachment_id The attachment.
 * @return array
 */
function mlsimport_mcp_media_summary( int $attachment_id ): array {
	$meta = wp_get_attachment_metadata( $attachment_id );
	return array(
		'id'     => $attachment_id,
		'title'  => (string) get_the_title( $attachment_id ),
		'url'    => (string) wp_get_attachment_url( $attachment_id ),
		'width'  => (int) ( $meta['width'] ?? 0 ),
		'height' => (int) ( $meta['height'] ?? 0 ),
		'mime'   => (string) get_post_mime_type( $attachment_id ),
	);
}

/**
 * Tool list-media: the images of the Media Library, newest first.
 *
 * @param array $input The tool input: search (title or file name text), page.
 * @return array The images, the total and the paging.
 */
function mlsimport_mcp_list_media( array $input ): array {
	$page  = max( 1, (int) ( $input['page'] ?? 1 ) );
	$query = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => MLSIMPORT_MCP_MEDIA_PER_PAGE,
			'paged'          => $page,
			's'              => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		)
	);
	$media = array();
	foreach ( $query->posts as $attachment_id ) {
		$media[] = mlsimport_mcp_media_summary( (int) $attachment_id );
	}
	return array(
		'media'    => $media,
		'total'    => (int) $query->found_posts,
		'page'     => $page,
		'per_page' => MLSIMPORT_MCP_MEDIA_PER_PAGE,
	);
}

/**
 * Tool upload-media: bring an image in from a URL.
 *
 * Step by step:
 * 1. The URL must be http(s).
 * 2. WordPress downloads it and creates the attachment (media_sideload_image,
 *    what the editor's "insert from URL" uses); a download or file-type
 *    failure is WordPress's own refusal.
 * 3. A title and an alt text, when sent, go on the attachment.
 *
 * @param array $input url, title, alt.
 * @return array|WP_Error The image, or the refusal.
 */
function mlsimport_mcp_upload_media( array $input ) {
	// Step 1.
	$url = esc_url_raw( trim( (string) ( $input['url'] ?? '' ) ) );
	if ( '' === $url || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
		return new WP_Error( 'mlsimport_media_url_invalid', __( 'The url must be an http or https address of an image file.', 'mlsimport' ) );
	}

	// Step 2.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$attachment_id = media_sideload_image( $url, 0, isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : null, 'id' );
	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	// Step 3.
	if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
		wp_update_post(
			array(
				'ID'         => (int) $attachment_id,
				'post_title' => sanitize_text_field( (string) $input['title'] ),
			)
		);
	}
	if ( isset( $input['alt'] ) ) {
		update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
	}
	return mlsimport_mcp_media_summary( (int) $attachment_id );
}
