<?php
/**
 * AI tools (MCP): the homepage (Settings -> Reading).
 *
 * WordPress keeps three options: show_on_front ("posts" = the latest posts,
 * "page" = a page), page_on_front (the page shown as the homepage) and
 * page_for_posts (the page that lists the posts). These tools read and set
 * them the way Settings -> Reading does, on every site, theme mode or
 * standalone.
 *
 * Public interface:
 *   mlsimport_mcp_front_page_tools()           the tools of this file
 *   mlsimport_mcp_reading_settings()           the three options, with each page's title and url
 *   mlsimport_mcp_get_reading_settings()       tool: get-reading-settings
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
function mlsimport_mcp_front_page_tools(): array {
	return array(
		'get-reading-settings' => array(
			'label'       => 'What the homepage shows',
			'description' => 'Reads Settings -> Reading: show_on_front is posts (the latest posts) or page; page_on_front is the page shown as the homepage and page_for_posts the page that lists the posts, each with page_id, title and url, or null when none is set.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_get_reading_settings',
			'changes'     => false,
		),
		'set-front-page'       => array(
			'label'       => 'Make a page the homepage',
			'description' => 'Makes a published page from list-pages the site\'s homepage (Settings -> Reading: show_on_front = page), and optionally another published page the posts page. This replaces the live homepage at once, so read get-reading-settings first and tell the client what the homepage is now before calling. The answer repeats the previous settings, so the same tool can put them back.',
			'input'       => array(
				'page_id'       => array(
					'type'        => 'integer',
					'description' => 'The published page to show as the homepage, from list-pages.',
				),
				'posts_page_id' => array(
					'type'        => 'integer',
					'description' => 'A published page to list the posts on; 0 or left out keeps the posts page as it is.',
				),
			),
			'required'    => array( 'page_id' ),
			'callback'    => 'mlsimport_mcp_set_front_page',
			'changes'     => true,
		),
	);
}

/**
 * A page that may be shown as the homepage or the posts page: it exists and is published.
 *
 * @param int $page_id Page id.
 * @return WP_Post|WP_Error
 */
function mlsimport_mcp_published_page_require( int $page_id ) {
	$page = mlsimport_mcp_page( array( 'page_id' => $page_id ) );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	if ( 'publish' !== $page->post_status ) {
		return new WP_Error( 'mlsimport_page_not_published', sprintf( /* translators: 1: page title, 2: page status. */ __( 'The page "%1$s" is %2$s, not published. Publish it first with update-page, then make it the homepage.', 'mlsimport' ), $page->post_title, $page->post_status ) );
	}
	return $page;
}

/**
 * Tool set-front-page: a page becomes the homepage, optionally another the posts page.
 *
 * Step by step:
 * 1. Both pages must exist and be published, and must not be the same page.
 *    A refusal changes nothing.
 * 2. Remember the settings as they are, for the answer.
 * 3. Save the three options the way Settings -> Reading does.
 * 4. Answer with the new settings and the previous ones.
 *
 * @param array $input page_id, posts_page_id.
 * @return array|WP_Error show_on_front, page_on_front, page_for_posts, previous; or the refusal.
 */
function mlsimport_mcp_set_front_page( array $input ) {
	// Step 1: the checks.
	$front = mlsimport_mcp_published_page_require( (int) ( $input['page_id'] ?? 0 ) );
	if ( is_wp_error( $front ) ) {
		return $front;
	}
	$posts_page_id = (int) ( $input['posts_page_id'] ?? 0 );
	if ( $posts_page_id > 0 ) {
		$posts_page = mlsimport_mcp_published_page_require( $posts_page_id );
		if ( is_wp_error( $posts_page ) ) {
			return $posts_page;
		}
		if ( $posts_page_id === (int) $front->ID ) {
			return new WP_Error( 'mlsimport_page_same', __( 'The homepage and the posts page must be two different pages.', 'mlsimport' ) );
		}
	}

	// Step 2: the settings before.
	$previous = mlsimport_mcp_reading_settings();

	// Step 3: the save.
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $front->ID );
	if ( $posts_page_id > 0 ) {
		update_option( 'page_for_posts', $posts_page_id );
	}

	// Step 4: the answer.
	return mlsimport_mcp_reading_settings() + array( 'previous' => $previous );
}

/**
 * One page named by a reading option: page_id, title, url; or null.
 *
 * @param int $page_id The option's value.
 * @return array|null
 */
function mlsimport_mcp_reading_page( int $page_id ) {
	$page = $page_id > 0 ? get_post( $page_id ) : null;
	if ( ! $page || 'page' !== $page->post_type ) {
		return null;
	}
	return array(
		'page_id' => (int) $page->ID,
		'title'   => (string) $page->post_title,
		'url'     => (string) get_permalink( $page->ID ),
	);
}

/**
 * The reading settings as the tools answer them.
 *
 * When the homepage shows the latest posts, no page is named even if an old
 * page_on_front value is still stored: it is not in use.
 *
 * @return array show_on_front, page_on_front, page_for_posts.
 */
function mlsimport_mcp_reading_settings(): array {
	$by_page = 'page' === get_option( 'show_on_front' );
	return array(
		'show_on_front'  => $by_page ? 'page' : 'posts',
		'page_on_front'  => $by_page ? mlsimport_mcp_reading_page( (int) get_option( 'page_on_front' ) ) : null,
		'page_for_posts' => $by_page ? mlsimport_mcp_reading_page( (int) get_option( 'page_for_posts' ) ) : null,
	);
}

/**
 * Tool get-reading-settings.
 *
 * @param array $input Unused.
 * @return array show_on_front, page_on_front, page_for_posts.
 */
function mlsimport_mcp_get_reading_settings( array $input ): array {
	unset( $input );
	return mlsimport_mcp_reading_settings();
}
