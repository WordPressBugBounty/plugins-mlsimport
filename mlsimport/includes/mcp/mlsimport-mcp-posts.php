<?php
/**
 * AI tools (MCP): blog posts (Posts in wp-admin).
 *
 * These tools work on every site: they read and write plain WordPress posts,
 * the ones Posts -> All Posts lists and a blog row shows. list-posts gives
 * the post_id the other tools need; create-post and update-post take the
 * same fields (title, HTML content, excerpt, status with an optional date,
 * featured image, categories, tags), checked in mlsimport-mcp-posts-fields.php.
 * Deleting and the categories are in mlsimport-mcp-posts-manage.php.
 *
 * How a post is described to the AI (mlsimport_mcp_post_summary()):
 *   post_id, title, status (draft, publish, future = scheduled, pending,
 *   private, trash), date (site time), url, featured_image (an image as
 *   list-media shows it, or null), categories and tags (names).
 *
 * Public interface:
 *   mlsimport_mcp_post_tools()                the tools of this file
 *   mlsimport_mcp_post( $input )              the post of post_id, or the refusal
 *   mlsimport_mcp_post_summary( $post )       one post, as the tools answer it
 *   mlsimport_mcp_list_posts( $input )        tool: list-posts
 *   mlsimport_mcp_create_post( $input )       tool: create-post
 *   mlsimport_mcp_update_post( $input )       tool: update-post
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How many posts one list-posts answer holds. */
const MLSIMPORT_MCP_POSTS_PER_PAGE = 50;

/**
 * The inputs create-post and update-post share.
 *
 * @return array<string, array<string, mixed>> JSON-schema properties.
 */
function mlsimport_mcp_post_fields_input(): array {
	return array(
		'title'             => array( 'type' => 'string' ),
		'content'           => array(
			'type'        => 'string',
			'description' => 'The post body as HTML (paragraphs, headings, lists, images, links).',
		),
		'excerpt'           => array(
			'type'        => 'string',
			'description' => 'The short summary a blog row shows.',
		),
		'status'            => array(
			'type'        => 'string',
			'enum'        => array( 'draft', 'publish', 'scheduled', 'private' ),
			'description' => 'draft (default when creating), publish, private, or scheduled (then send date).',
		),
		'date'              => array(
			'type'        => 'string',
			'description' => 'The publish date and time in site time, like 2026-11-03 09:00. Required for scheduled; with publish it backdates the post.',
		),
		'featured_image_id' => array(
			'type'        => 'integer',
			'description' => 'The featured image: an id from list-media or upload-media. 0 removes it.',
		),
		'categories'        => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => 'Category names, slugs or ids from list-categories. Replaces the post\'s categories.',
		),
		'tags'              => array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => 'Tag names; a new name creates the tag. Replaces the post\'s tags.',
		),
	);
}

/**
 * The AI tools of this file, as the tool list expects them.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_post_tools(): array {
	return array(
		'list-posts'  => array(
			'label'       => 'List the blog posts',
			'description' => 'Reads the blog posts (Posts -> All Posts), newest first, 50 at a time: post_id, title, status, date, url, featured_image, categories and tags. search narrows by title or text, status by state (any = all but the trash), category by a category name, slug or id. Use it to find the post_id the other post tools need.',
			'input'       => array(
				'search'   => array( 'type' => 'string' ),
				'status'   => array(
					'type' => 'string',
					'enum' => array( 'any', 'publish', 'draft', 'scheduled', 'pending', 'private', 'trash' ),
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'A category name, slug or id from list-categories.',
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => 'Which batch of 50, 1 = first.',
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_posts',
			'changes'     => false,
		),
		'create-post' => array(
			'label'       => 'Create a blog post',
			'description' => 'Creates a blog post with a title, HTML content, excerpt, status (draft unless you say publish, private or scheduled with a date), a featured image from list-media, categories from list-categories and tags. Answers with the post as list-posts shows it.',
			'input'       => mlsimport_mcp_post_fields_input(),
			'required'    => array( 'title' ),
			'callback'    => 'mlsimport_mcp_create_post',
			'changes'     => true,
		),
		'update-post' => array(
			'label'       => 'Change a blog post',
			'description' => 'Changes one post from list-posts. Only the fields you send change: title, content, excerpt, status (with date for scheduled), featured_image_id (0 removes it), categories, tags. Answers with the post as list-posts shows it.',
			'input'       => array( 'post_id' => array( 'type' => 'integer' ) ) + mlsimport_mcp_post_fields_input(),
			'required'    => array( 'post_id' ),
			'callback'    => 'mlsimport_mcp_update_post',
			'changes'     => true,
		),
	);
}

/**
 * The post named by post_id.
 *
 * @param array $input The tool input with post_id.
 * @return WP_Post|WP_Error The post, or mlsimport_post_unknown.
 */
function mlsimport_mcp_post( array $input ) {
	$post = get_post( (int) ( $input['post_id'] ?? 0 ) );
	if ( ! $post || 'post' !== $post->post_type ) {
		return new WP_Error( 'mlsimport_post_unknown', __( 'There is no blog post with that id. list-posts shows the posts and their ids.', 'mlsimport' ) );
	}
	return $post;
}

/**
 * One post, as the tools answer it.
 *
 * @param WP_Post $post The post.
 * @return array post_id, title, status, date, url, featured_image, categories, tags.
 */
function mlsimport_mcp_post_summary( $post ): array {
	$image_id = (int) get_post_thumbnail_id( $post->ID );
	$names    = static function ( $terms ): array {
		return is_array( $terms ) ? array_values( array_map( 'strval', wp_list_pluck( $terms, 'name' ) ) ) : array();
	};
	return array(
		'post_id'        => (int) $post->ID,
		'title'          => (string) $post->post_title,
		'status'         => 'future' === $post->post_status ? 'scheduled' : (string) $post->post_status,
		'date'           => (string) $post->post_date,
		'url'            => (string) get_permalink( $post->ID ),
		'featured_image' => $image_id > 0 ? mlsimport_mcp_media_summary( $image_id ) : null,
		'categories'     => $names( get_the_terms( $post->ID, 'category' ) ),
		'tags'           => $names( get_the_terms( $post->ID, 'post_tag' ) ),
	);
}

/**
 * Tool list-posts: the posts, newest first, 50 at a time.
 *
 * Step by step:
 * 1. Read the posts the way Posts -> All Posts does: by date, the asked
 *    status (any = everything but the trash; scheduled = WordPress's future),
 *    the search text, the category, the batch.
 * 2. Answer with each post summarised, and how many there are in all.
 *
 * @param array $input search, status, category, page.
 * @return array posts, total, page, per_page.
 */
function mlsimport_mcp_list_posts( array $input ): array {
	// Step 1: the query.
	$status = sanitize_key( (string) ( $input['status'] ?? 'any' ) );
	$batch  = max( 1, (int) ( $input['page'] ?? 1 ) );
	$args   = array(
		'post_type'      => 'post',
		'post_status'    => '' === $status ? 'any' : str_replace( 'scheduled', 'future', $status ),
		's'              => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
		'posts_per_page' => MLSIMPORT_MCP_POSTS_PER_PAGE,
		'paged'          => $batch,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( isset( $input['category'] ) && '' !== trim( (string) $input['category'] ) ) {
		$term = mlsimport_mcp_category_find( (string) $input['category'] );
		// An unknown category matches nothing, as Posts -> All Posts would show.
		$args['category__in'] = array( $term ? (int) $term->term_id : -1 );
	}
	$query = new WP_Query( $args );

	// Step 2: the answer.
	$posts = array();
	foreach ( $query->posts as $post ) {
		$posts[] = mlsimport_mcp_post_summary( $post );
	}
	return array(
		'posts'    => $posts,
		'total'    => (int) $query->found_posts,
		'page'     => $batch,
		'per_page' => MLSIMPORT_MCP_POSTS_PER_PAGE,
	);
}

/**
 * Tool create-post: a new blog post.
 *
 * Step by step:
 * 1. Check the fields; a post starts as a draft unless a status is sent.
 * 2. Insert the post the way the editor does (wp_insert_post with slashed data).
 * 3. Answer with the post as list-posts shows it.
 *
 * @param array $input title, content, excerpt, status, date, featured_image_id, categories, tags.
 * @return array|WP_Error The post, or the refusal.
 */
function mlsimport_mcp_create_post( array $input ) {
	// Step 1.
	$fields = mlsimport_mcp_post_fields( $input );
	if ( is_wp_error( $fields ) ) {
		return $fields;
	}
	$fields += array(
		'post_type'   => 'post',
		'post_status' => 'draft',
	);

	// Step 2.
	$post_id = wp_insert_post( wp_slash( $fields ), true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	mlsimport_mcp_post_thumbnail( (int) $post_id, $input );

	// Step 3.
	return mlsimport_mcp_post_summary( get_post( (int) $post_id ) );
}

/**
 * Tool update-post: change the sent fields of one post.
 *
 * @param array $input post_id plus the fields of create-post.
 * @return array|WP_Error The post, or the refusal.
 */
function mlsimport_mcp_update_post( array $input ) {
	$post = mlsimport_mcp_post( $input );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$fields = mlsimport_mcp_post_fields( $input );
	if ( is_wp_error( $fields ) ) {
		return $fields;
	}
	$fields['ID'] = (int) $post->ID;
	$saved        = wp_update_post( wp_slash( $fields ), true );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	mlsimport_mcp_post_thumbnail( (int) $post->ID, $input );
	return mlsimport_mcp_post_summary( get_post( (int) $post->ID ) );
}
