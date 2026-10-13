<?php
/**
 * Custom CSS for one page.
 *
 * WordPress has one site-wide stylesheet the user can edit (Appearance ->
 * Customize -> Additional CSS). A page sometimes needs a rule of its own: a
 * leftover widget heading to hide, a spacing fix a widget has no setting
 * for. This file keeps that CSS on the page (post meta) and prints it in the
 * head of that page only, right after the site's Additional CSS. The AI tools
 * get-custom-css and update-custom-css (includes/mcp/mlsimport-mcp-custom-css.php)
 * read and write it; nothing else in the plugin does.
 *
 * Public interface:
 *   MLSIMPORT_PAGE_CSS_META                 the post meta key
 *   mlsimport_page_custom_css( $post_id )   the CSS stored on a page
 *   mlsimport_custom_css_print()            hook: wp_head, prints the page's CSS
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The post meta key that holds a page's own CSS. */
const MLSIMPORT_PAGE_CSS_META = 'mlsimport_custom_css';

/**
 * The CSS stored on one page.
 *
 * @param int $post_id The page (or post).
 * @return string The CSS; '' when none.
 */
function mlsimport_page_custom_css( int $post_id ): string {
	return (string) get_post_meta( $post_id, MLSIMPORT_PAGE_CSS_META, true );
}

/**
 * Print the current page's own CSS in its head.
 *
 * Step by step:
 * 1. Only a single page or post has CSS of its own; archives have none.
 * 2. Print it in a style tag, with any markup stripped the way WordPress
 *    prints the site's Additional CSS (wp_custom_css_cb).
 *
 * Hooked on wp_head at 101, right after WordPress's own Additional CSS.
 *
 * @return void
 */
function mlsimport_custom_css_print(): void {
	// Step 1.
	if ( ! is_singular() ) {
		return;
	}
	$css = mlsimport_page_custom_css( (int) get_queried_object_id() );
	if ( '' === trim( $css ) ) {
		return;
	}
	// Step 2.
	echo '<style id="mlsimport-page-css">' . "\n" . wp_strip_all_tags( $css ) . "\n" . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS text with tags stripped, as wp_custom_css_cb() prints it.
}
add_action( 'wp_head', 'mlsimport_custom_css_print', 101 );
