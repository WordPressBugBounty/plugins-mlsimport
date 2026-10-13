<?php
/**
 * AI tools (MCP): the fields of a blog post, as create-post and update-post
 * take them.
 *
 * One checker turns the tool input into the row wp_insert_post() stores:
 * title, HTML content (cleaned as the editor cleans it), excerpt, status
 * (scheduled = WordPress's future, which needs a date), a date in site time,
 * the categories (by id, slug or name) and the tags (names). The featured
 * image is set after the save, as the editor does.
 *
 * Public interface:
 *   mlsimport_mcp_post_fields( $input )            the checked fields, or the refusal
 *   mlsimport_mcp_category_find( $name )           a category by id, slug or name
 *   mlsimport_mcp_post_thumbnail( $post_id, $in )  set or remove the featured image
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The checked fields create-post and update-post store.
 *
 * Step by step:
 * 1. title, content (HTML, cleaned as the editor cleans it) and excerpt go
 *    into the post row as sent.
 * 2. status: scheduled becomes WordPress's future and needs a date; a date
 *    is parsed in site time and refused when unreadable.
 * 3. featured_image_id must be an image; categories are looked up by id,
 *    slug or name (an unknown one is refused, pointing at create-category);
 *    tags are names, created by WordPress when new.
 *
 * @param array $input The tool input.
 * @return array|WP_Error The wp_insert_post() fields (unslashed), or the refusal.
 */
function mlsimport_mcp_post_fields( array $input ) {
	$fields = array();
	// Step 1.
	if ( isset( $input['title'] ) ) {
		$fields['post_title'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( isset( $input['content'] ) ) {
		$fields['post_content'] = wp_kses_post( (string) $input['content'] );
	}
	if ( isset( $input['excerpt'] ) ) {
		$fields['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
	}
	// Step 2.
	if ( isset( $input['status'] ) ) {
		$fields['post_status'] = 'scheduled' === $input['status'] ? 'future' : sanitize_key( (string) $input['status'] );
	}
	if ( isset( $input['date'] ) && '' !== trim( (string) $input['date'] ) ) {
		$timestamp = strtotime( (string) $input['date'] );
		if ( false === $timestamp || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', trim( (string) $input['date'] ) ) ) {
			return new WP_Error( 'mlsimport_post_date_invalid', __( 'date must be a date and time like 2026-11-03 09:00 (site time).', 'mlsimport' ) );
		}
		// strtotime() reads a plain date in the server zone and returns that same
		// wall-clock time; the post keeps it as site time, as the editor does.
		$fields['post_date']     = gmdate( 'Y-m-d H:i:s', $timestamp );
		$fields['post_date_gmt'] = get_gmt_from_date( $fields['post_date'] );
	} elseif ( 'future' === ( $fields['post_status'] ?? '' ) ) {
		return new WP_Error( 'mlsimport_post_date_missing', __( 'A scheduled post needs date: when it goes live, like 2026-11-03 09:00 (site time).', 'mlsimport' ) );
	}
	// Step 3.
	$image_id = (int) ( $input['featured_image_id'] ?? 0 );
	if ( $image_id > 0 && ! wp_attachment_is_image( $image_id ) ) {
		/* translators: %d: the id sent */
		return new WP_Error( 'mlsimport_media_not_image', sprintf( __( 'featured_image_id %d is not an image of the Media Library. list-media shows the images and their ids.', 'mlsimport' ), $image_id ) );
	}
	if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
		$fields['post_category'] = array();
		foreach ( $input['categories'] as $name ) {
			$term = mlsimport_mcp_category_find( (string) $name );
			if ( ! $term ) {
				/* translators: %s: the category as sent */
				return new WP_Error( 'mlsimport_category_unknown', sprintf( __( 'There is no category "%s". list-categories shows the categories; create-category makes a new one.', 'mlsimport' ), (string) $name ) );
			}
			$fields['post_category'][] = (int) $term->term_id;
		}
	}
	if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
		$fields['tags_input'] = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $input['tags'] ) ) ) );
	}
	return $fields;
}

/**
 * A post category by id, slug or name.
 *
 * @param string $name What the AI sent.
 * @return WP_Term|null The category, or null.
 */
function mlsimport_mcp_category_find( string $name ) {
	$name = trim( $name );
	$term = ctype_digit( $name ) ? get_term( (int) $name, 'category' ) : null;
	if ( ! $term || is_wp_error( $term ) ) {
		$term = get_term_by( 'slug', sanitize_title( $name ), 'category' );
	}
	if ( ! $term ) {
		$term = get_term_by( 'name', $name, 'category' );
	}
	return $term instanceof WP_Term ? $term : null;
}

/**
 * Set or remove the featured image after a post is saved.
 *
 * @param int   $post_id The post.
 * @param array $input   The tool input (featured_image_id, when sent).
 * @return void
 */
function mlsimport_mcp_post_thumbnail( int $post_id, array $input ): void {
	if ( ! isset( $input['featured_image_id'] ) ) {
		return;
	}
	if ( (int) $input['featured_image_id'] > 0 ) {
		set_post_thumbnail( $post_id, (int) $input['featured_image_id'] );
	} else {
		delete_post_thumbnail( $post_id );
	}
}
