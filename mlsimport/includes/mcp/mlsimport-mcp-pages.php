<?php
/**
 * AI tools (MCP): the site's pages (Pages in wp-admin).
 *
 * These tools work on every site, theme mode or standalone: they read and
 * change plain WordPress pages, the same posts Pages -> All Pages lists. The
 * other page tools (create-page, page-blocks ...) need a page id; list-pages
 * is where that id comes from.
 *
 * How a page is described to the AI (mlsimport_mcp_page_summary()):
 *   page_id, title, status (publish, draft, private, pending, trash), slug,
 *   parent (the page id it sits under; 0 at the top), url, builder
 *   (elementor or gutenberg), is_front_page, is_posts_page.
 *
 * Public interface:
 *   mlsimport_mcp_page_tools()             the tools of this file
 *   mlsimport_mcp_page_summary( $page )    one page, as the tools answer it
 *   mlsimport_mcp_list_pages( $input )     tool: list-pages
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How many pages one list-pages answer holds. */
define( 'MLSIMPORT_MCP_PAGES_PER_PAGE', 50 );

/**
 * The AI tools of this file, as the tool list expects them.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_page_tools(): array {
	return array(
		'list-pages'  => array(
			'label'       => 'List the pages',
			'description' => 'Reads the site\'s pages (Pages -> All Pages), 50 at a time: each with its page_id, title, status, slug, parent, url, builder (elementor or gutenberg), and whether it is the homepage (is_front_page) or the posts page (is_posts_page). Use it to find the page_id the other page tools need.',
			'input'       => array(
				'search' => array(
					'type'        => 'string',
					'description' => 'Only pages whose title or text contains this.',
				),
				'status' => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'publish', 'draft', 'private', 'pending', 'trash' ),
					'description' => 'any (default: every page except the trash), publish, draft, private, pending or trash.',
				),
				'page'   => array(
					'type'        => 'integer',
					'description' => 'Which batch of 50, 1 = first.',
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_pages',
			'changes'     => false,
		),
		'update-page' => array(
			'label'       => 'Change a page',
			'description' => 'Changes one page from list-pages. Only the fields you name change: status (publish, draft or private; publish makes the page live), title, slug (the last part of its address) and parent (the page_id it sits under, 0 = top level). The page\'s content is not touched.',
			'input'       => array(
				'page_id' => array(
					'type'        => 'integer',
					'description' => 'The page id, from list-pages.',
				),
				'status'  => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'publish', 'private' ),
					'description' => 'draft, publish or private.',
				),
				'title'   => array(
					'type'        => 'string',
					'description' => 'The new title.',
				),
				'slug'    => array(
					'type'        => 'string',
					'description' => 'The new slug, the last part of the page address.',
				),
				'parent'  => array(
					'type'        => 'integer',
					'description' => 'The page_id to sit under, from list-pages; 0 = top level.',
				),
			),
			'required'    => array( 'page_id' ),
			'callback'    => 'mlsimport_mcp_update_page',
			'changes'     => true,
		),
	);
}

/**
 * Tool update-page: change the named fields of one page.
 *
 * Step by step:
 * 1. The page must exist. A status must be draft, publish or private; a
 *    parent must be 0 or another page (never the page itself). A refusal
 *    changes nothing.
 * 2. Save only the named fields with wp_update_post(), the way the editor does.
 * 3. Answer with the page as list-pages shows it.
 *
 * @param array $input page_id, status, title, slug, parent.
 * @return array|WP_Error The page, or the refusal.
 */
function mlsimport_mcp_update_page( array $input ) {
	// Step 1: the checks.
	$page = mlsimport_mcp_page( $input );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	$changes = array( 'ID' => (int) $page->ID );
	if ( isset( $input['status'] ) ) {
		$status = sanitize_key( (string) $input['status'] );
		if ( ! in_array( $status, array( 'draft', 'publish', 'private' ), true ) ) {
			return new WP_Error( 'mlsimport_page_status_unknown', __( 'status must be draft, publish or private. To trash a page use delete-page.', 'mlsimport' ) );
		}
		$changes['post_status'] = $status;
	}
	if ( isset( $input['parent'] ) ) {
		$parent_id = (int) $input['parent'];
		$parent    = $parent_id > 0 ? get_post( $parent_id ) : null;
		if ( $parent_id === (int) $page->ID || ( $parent_id > 0 && ( ! $parent || 'page' !== $parent->post_type ) ) ) {
			return new WP_Error( 'mlsimport_page_parent_unknown', __( 'parent must be 0 or the page_id of another page, from list-pages.', 'mlsimport' ) );
		}
		$changes['post_parent'] = $parent_id;
	}
	if ( isset( $input['title'] ) ) {
		$changes['post_title'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( isset( $input['slug'] ) ) {
		$changes['post_name'] = sanitize_title( (string) $input['slug'] );
	}

	// Step 2: the save.
	$saved = wp_update_post( $changes, true );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	// Step 3: the answer.
	return mlsimport_mcp_page_summary( get_post( (int) $page->ID ) );
}

/**
 * One page, as the tools answer it.
 *
 * @param WP_Post $page The page.
 * @return array page_id, title, status, slug, parent, url, builder, is_front_page, is_posts_page.
 */
function mlsimport_mcp_page_summary( $page ): array {
	return array(
		'page_id'       => (int) $page->ID,
		'title'         => (string) $page->post_title,
		'status'        => (string) $page->post_status,
		'slug'          => (string) $page->post_name,
		'parent'        => (int) $page->post_parent,
		'url'           => (string) get_permalink( $page->ID ),
		'builder'       => mlsimport_mcp_elementor_page( (int) $page->ID ) ? 'elementor' : 'gutenberg',
		'is_front_page' => 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $page->ID,
		'is_posts_page' => 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_for_posts' ) === (int) $page->ID,
	);
}

/**
 * Tool list-pages: the pages, 50 at a time.
 *
 * Step by step:
 * 1. Read the pages the way Pages -> All Pages does: by title, the asked
 *    status (any = everything but the trash), the search text, the batch.
 * 2. Answer with each page summarised, and how many there are in all.
 *
 * @param array $input search, status, page.
 * @return array pages, total, page, per_page.
 */
function mlsimport_mcp_list_pages( array $input ): array {
	// Step 1: the query.
	$status = sanitize_key( (string) ( $input['status'] ?? 'any' ) );
	$batch  = max( 1, (int) ( $input['page'] ?? 1 ) );
	$query  = new WP_Query(
		array(
			'post_type'      => 'page',
			'post_status'    => '' === $status ? 'any' : $status,
			's'              => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
			'posts_per_page' => MLSIMPORT_MCP_PAGES_PER_PAGE,
			'paged'          => $batch,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	// Step 2: the answer.
	$pages = array();
	foreach ( $query->posts as $page ) {
		$pages[] = mlsimport_mcp_page_summary( $page );
	}
	return array(
		'pages'    => $pages,
		'total'    => (int) $query->found_posts,
		'page'     => $batch,
		'per_page' => MLSIMPORT_MCP_PAGES_PER_PAGE,
	);
}
