<?php
/**
 * AI tools (MCP): post categories and deleting a post.
 *
 * The categories (Posts -> Categories) group the blog posts: a blog row can
 * show one category (Buying, Selling, Market news). list-categories reads
 * them, create-category adds one; create-post and update-post attach them.
 * delete-post trashes a post with the same two-call confirmation every
 * deleting tool has (mlsimport-mcp-confirm.php).
 *
 * Public interface:
 *   mlsimport_mcp_post_manage_tools()           the tools of this file
 *   mlsimport_mcp_category_summary( $term )     one category, as the tools answer it
 *   mlsimport_mcp_list_categories( $input )     tool: list-categories
 *   mlsimport_mcp_create_category( $input )     tool: create-category
 *   mlsimport_mcp_delete_post( $input )         tool: delete-post
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
function mlsimport_mcp_post_manage_tools(): array {
	return array(
		'list-categories' => array(
			'label'       => 'List the post categories',
			'description' => 'Reads the blog post categories (Posts -> Categories): category_id, name, slug, parent, description and how many posts each has. These are the names create-post and update-post take, and the one a blog row can be limited to.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_categories',
			'changes'     => false,
		),
		'create-category' => array(
			'label'       => 'Create a post category',
			'description' => 'Creates a blog post category (Buying, Selling, Market news) with a name and, optionally, a slug, a parent category_id and a description. A name that already exists is refused.',
			'input'       => array(
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'parent'      => array(
					'type'        => 'integer',
					'description' => 'The category_id to sit under, from list-categories; 0 = top level.',
				),
				'description' => array( 'type' => 'string' ),
			),
			'required'    => array( 'name' ),
			'callback'    => 'mlsimport_mcp_create_category',
			'changes'     => true,
		),
		'delete-post'     => array(
			'label'       => 'Delete a blog post',
			'description' => 'Moves one post from list-posts to the trash, or with permanently = true deletes it for good. Needs two calls: the first call deletes nothing and returns what would be deleted and a confirmation_code; tell the client, and only after they confirm call again with the same inputs and that code.',
			'input'       => array(
				'post_id'           => array(
					'type'        => 'integer',
					'description' => 'The post id, from list-posts.',
				),
				'permanently'       => array(
					'type'        => 'boolean',
					'description' => 'false (default): to the trash, where it can be restored. true: deleted for good.',
				),
				'confirmation_code' => array(
					'type'        => 'string',
					'description' => 'The confirmation_code returned by the first call. Leave out on the first call.',
				),
			),
			'required'    => array( 'post_id' ),
			'callback'    => 'mlsimport_mcp_delete_post',
			'changes'     => true,
			'destructive' => true,
		),
	);
}

/**
 * Tool delete-post: one post to the trash or deleted for good (two calls).
 *
 * Step by step:
 * 1. The post must exist.
 * 2. Say exactly what goes: the title, and trash or for good. Without a
 *    valid code stop here (the confirmation gate answers the preview or the
 *    refusal).
 * 3. Confirmed: trash or delete the post the way Posts -> All Posts does.
 *
 * @param array $input post_id, permanently, confirmation_code.
 * @return array|WP_Error The preview, "deleted", or the refusal.
 */
function mlsimport_mcp_delete_post( array $input ) {
	// Step 1.
	$post = mlsimport_mcp_post( $input );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$permanently = ! empty( $input['permanently'] );

	// Step 2.
	$pending = mlsimport_mcp_confirm_or_preview(
		'delete-post',
		$input,
		$permanently
			/* translators: 1: post title, 2: post id. */
			? sprintf( __( 'The post "%1$s" (%2$d), deleted permanently; it cannot be restored.', 'mlsimport' ), $post->post_title, $post->ID )
			/* translators: 1: post title, 2: post id. */
			: sprintf( __( 'The post "%1$s" (%2$d) goes to the trash, where it can be restored for 30 days.', 'mlsimport' ), $post->post_title, $post->ID )
	);
	if ( null !== $pending ) {
		return $pending;
	}

	// Step 3.
	$deleted = $permanently ? wp_delete_post( (int) $post->ID, true ) : wp_trash_post( (int) $post->ID );
	if ( ! $deleted ) {
		return new WP_Error( 'mlsimport_post_delete_failed', __( 'WordPress could not delete the post.', 'mlsimport' ) );
	}
	return array(
		'deleted'     => true,
		'permanently' => $permanently,
		'post_id'     => (int) $post->ID,
	);
}

/**
 * One category, as the tools answer it.
 *
 * @param WP_Term $term The category.
 * @return array category_id, name, slug, parent, description, count.
 */
function mlsimport_mcp_category_summary( $term ): array {
	return array(
		'category_id' => (int) $term->term_id,
		'name'        => (string) $term->name,
		'slug'        => (string) $term->slug,
		'parent'      => (int) $term->parent,
		'description' => (string) $term->description,
		'count'       => (int) $term->count,
	);
}

/**
 * Tool list-categories: every post category, by name.
 *
 * @param array $input Unused: the tool takes no input.
 * @return array categories.
 */
function mlsimport_mcp_list_categories( array $input = array() ): array {
	unset( $input );
	$terms = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	$rows  = array();
	foreach ( is_array( $terms ) ? $terms : array() as $term ) {
		$rows[] = mlsimport_mcp_category_summary( $term );
	}
	return array( 'categories' => $rows );
}

/**
 * Tool create-category: a new post category.
 *
 * Step by step:
 * 1. A parent, when sent, must be an existing category.
 * 2. Insert the term the way Posts -> Categories does; WordPress refuses a
 *    name or slug that already exists.
 * 3. Answer with the category as list-categories shows it.
 *
 * @param array $input name, slug, parent, description.
 * @return array|WP_Error The category, or the refusal.
 */
function mlsimport_mcp_create_category( array $input ) {
	// Step 1.
	$parent = (int) ( $input['parent'] ?? 0 );
	if ( $parent > 0 && ! term_exists( $parent, 'category' ) ) {
		return new WP_Error( 'mlsimport_category_unknown', __( 'parent must be 0 or a category_id from list-categories.', 'mlsimport' ) );
	}

	// Step 2.
	$args = array( 'parent' => $parent );
	if ( isset( $input['slug'] ) && '' !== trim( (string) $input['slug'] ) ) {
		$args['slug'] = sanitize_title( (string) $input['slug'] );
	}
	if ( isset( $input['description'] ) ) {
		$args['description'] = sanitize_textarea_field( (string) $input['description'] );
	}
	$inserted = wp_insert_term( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 'category', $args );
	if ( is_wp_error( $inserted ) ) {
		return $inserted;
	}

	// Step 3.
	return mlsimport_mcp_category_summary( get_term( (int) $inserted['term_id'], 'category' ) );
}
