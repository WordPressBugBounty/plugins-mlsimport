<?php
/**
 * AI tools (MCP): custom CSS, for the site or for one page.
 *
 * Free Elementor has no per-element CSS and the widgets have no setting for
 * every small fix, so the AI needs one place to put a CSS rule: the site's
 * Additional CSS (Appearance -> Customize, what WordPress prints on every
 * page) or the CSS of one page (includes/mlsimport-custom-css.php, printed in
 * that page's head only). get-custom-css reads either; update-custom-css
 * replaces or appends, and answers with the previous text so a change can
 * be undone.
 *
 * Public interface:
 *   mlsimport_mcp_custom_css_tools()           the tools of this file
 *   mlsimport_mcp_custom_css_read( $input )    the CSS and its scope, or the refusal
 *   mlsimport_mcp_get_custom_css( $input )     tool: get-custom-css
 *   mlsimport_mcp_update_custom_css( $input )  tool: update-custom-css
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
function mlsimport_mcp_custom_css_tools(): array {
	$page_id = array(
		'type'        => 'integer',
		'description' => 'A page (or post) id from list-pages or list-posts for CSS that applies to that page only. Leave out for the site\'s Additional CSS, printed on every page.',
	);
	return array(
		'get-custom-css'    => array(
			'label'       => 'Read the custom CSS',
			'description' => 'Reads the site\'s Additional CSS (Appearance -> Customize), or with page_id the CSS stored for that one page. Answers with scope (site or page), page_id and css.',
			'input'       => array( 'page_id' => $page_id ),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_get_custom_css',
			'changes'     => false,
		),
		'update-custom-css' => array(
			'label'       => 'Change the custom CSS',
			'description' => 'Writes CSS: without page_id the site\'s Additional CSS (every page), with page_id CSS printed in the head of that one page only, for fixes the widgets have no setting for, like hiding a leftover heading. Replaces the text unless append is true; an empty css removes it. The answer repeats the previous text so the change can be undone.',
			'input'       => array(
				'css'     => array(
					'type'        => 'string',
					'description' => 'Plain CSS rules, no style or script tags.',
				),
				'page_id' => $page_id,
				'append'  => array(
					'type'        => 'boolean',
					'description' => 'true adds the rules after the existing CSS; false (default) replaces it.',
				),
			),
			'required'    => array( 'css' ),
			'callback'    => 'mlsimport_mcp_update_custom_css',
			'changes'     => true,
		),
	);
}

/**
 * The CSS the input points at, with its scope.
 *
 * @param array $input page_id, when sent.
 * @return array|WP_Error scope ('site' or 'page'), page_id (0 for the site), css; or mlsimport_page_unknown.
 */
function mlsimport_mcp_custom_css_read( array $input ) {
	$page_id = (int) ( $input['page_id'] ?? 0 );
	if ( $page_id <= 0 ) {
		return array(
			'scope'   => 'site',
			'page_id' => 0,
			'css'     => (string) wp_get_custom_css(),
		);
	}
	$page = get_post( $page_id );
	if ( ! $page || ! in_array( $page->post_type, array( 'page', 'post' ), true ) ) {
		return new WP_Error( 'mlsimport_page_unknown', __( 'There is no page or post with that id. list-pages and list-posts show them.', 'mlsimport' ) );
	}
	return array(
		'scope'   => 'page',
		'page_id' => $page_id,
		'css'     => mlsimport_page_custom_css( $page_id ),
	);
}

/**
 * Tool get-custom-css: the site's or one page's CSS.
 *
 * @param array $input page_id.
 * @return array|WP_Error scope, page_id, css; or the refusal.
 */
function mlsimport_mcp_get_custom_css( array $input = array() ) {
	return mlsimport_mcp_custom_css_read( $input );
}

/**
 * Tool update-custom-css: replace or append the site's or one page's CSS.
 *
 * Step by step:
 * 1. The CSS must be plain CSS: no markup (a tag would break out of the
 *    style element) and balanced braces, the checks the Customizer makes.
 * 2. Read what is there (it goes back under previous) and build the new
 *    text: the rules sent, or the old text plus the rules on a new line.
 * 3. Store it where WordPress keeps the site's Additional CSS, or on the page.
 *
 * @param array $input css, page_id, append.
 * @return array|WP_Error scope, page_id, css, previous; or the refusal.
 */
function mlsimport_mcp_update_custom_css( array $input ) {
	// Step 1.
	$css = str_replace( "\r\n", "\n", (string) ( $input['css'] ?? '' ) );
	if ( preg_match( '#<\s*/?\s*[a-z]#i', $css ) || substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
		return new WP_Error( 'mlsimport_css_invalid', __( 'css must be plain CSS: no HTML tags, and every { closed by a }.', 'mlsimport' ) );
	}

	// Step 2.
	$current = mlsimport_mcp_custom_css_read( $input );
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	$previous = (string) $current['css'];
	$text     = trim( $css );
	if ( ! empty( $input['append'] ) && '' !== trim( $previous ) && '' !== $text ) {
		$text = rtrim( $previous ) . "\n" . $text;
	}

	// Step 3.
	if ( 'site' === $current['scope'] ) {
		$saved = wp_update_custom_css_post( $text );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
	} elseif ( '' === $text ) {
		delete_post_meta( (int) $current['page_id'], MLSIMPORT_PAGE_CSS_META );
	} else {
		update_post_meta( (int) $current['page_id'], MLSIMPORT_PAGE_CSS_META, $text );
	}
	$answer             = mlsimport_mcp_custom_css_read( $input );
	$answer['previous'] = $previous;
	return $answer;
}
