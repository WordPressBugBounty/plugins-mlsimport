<?php
/**
 * AI tools (MCP): delete a page.
 *
 * Like every tool that deletes, delete-page needs two calls, enforced by the
 * plugin (mlsimport-mcp-confirm.php): the first call deletes nothing and
 * answers with the page's title and whether it goes to the trash or is
 * deleted for good, plus a one-time confirmation code; the second call, with
 * that code and the same inputs, deletes.
 *
 * By default the page goes to the trash (wp_trash_post(), what Pages -> Trash
 * does), where it can be restored for 30 days. With permanently = true it is
 * deleted for good (wp_delete_post()). The homepage and the posts page
 * (Settings -> Reading) are never deleted: the site would lose its front.
 *
 * Public interface:
 *   mlsimport_mcp_page_delete_tools()      the tools of this file
 *   mlsimport_mcp_delete_page( $input )    tool: delete-page
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
function mlsimport_mcp_page_delete_tools(): array {
	return array(
		'delete-page' => array(
			'label'       => 'Delete a page',
			'description' => 'Moves one page from list-pages to the trash, or with permanently = true deletes it for good. The homepage and the posts page are refused. Needs two calls: the first call deletes nothing and returns what would be deleted and a confirmation_code; tell the client, and only after they confirm call again with the same inputs and that code.',
			'input'       => array(
				'page_id'           => array(
					'type'        => 'integer',
					'description' => 'The page id, from list-pages.',
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
			'required'    => array( 'page_id' ),
			'callback'    => 'mlsimport_mcp_delete_page',
			'changes'     => true,
			'destructive' => true,
		),
	);
}

/**
 * Tool delete-page: one page to the trash or deleted for good (two calls).
 *
 * Step by step:
 * 1. The page must exist and must not be the homepage or the posts page.
 * 2. Say exactly what goes: the title, and trash or for good. Without a
 *    valid code stop here (the confirmation gate answers the preview or the
 *    refusal).
 * 3. Confirmed: trash or delete the page the way Pages -> All Pages does.
 *
 * @param array $input page_id, permanently, confirmation_code.
 * @return array|WP_Error The preview, "deleted", or the refusal.
 */
function mlsimport_mcp_delete_page( array $input ) {
	// Step 1: the page, and that the site does not show it as its front.
	$page = mlsimport_mcp_page( $input );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	$summary = mlsimport_mcp_page_summary( $page );
	if ( $summary['is_front_page'] || $summary['is_posts_page'] ) {
		return new WP_Error(
			'mlsimport_page_in_use',
			sprintf(
				/* translators: 1: page title, 2: homepage or posts page. */
				__( 'The page "%1$s" is the %2$s (Settings -> Reading) and cannot be deleted. Choose another with set-front-page first.', 'mlsimport' ),
				$page->post_title,
				$summary['is_front_page'] ? __( 'homepage', 'mlsimport' ) : __( 'posts page', 'mlsimport' )
			)
		);
	}
	$permanently = ! empty( $input['permanently'] );

	// Step 2: the preview / the code.
	$pending = mlsimport_mcp_confirm_or_preview(
		'delete-page',
		$input,
		$permanently
			/* translators: 1: page title, 2: page id. */
			? sprintf( __( 'The page "%1$s" (%2$d), deleted permanently; it cannot be restored.', 'mlsimport' ), $page->post_title, $page->ID )
			/* translators: 1: page title, 2: page id. */
			: sprintf( __( 'The page "%1$s" (%2$d) goes to the trash, where it can be restored for 30 days.', 'mlsimport' ), $page->post_title, $page->ID )
	);
	if ( null !== $pending ) {
		return $pending;
	}

	// Step 3: the delete.
	$deleted = $permanently ? wp_delete_post( (int) $page->ID, true ) : wp_trash_post( (int) $page->ID );
	if ( ! $deleted ) {
		return new WP_Error( 'mlsimport_page_delete_failed', __( 'WordPress could not delete the page.', 'mlsimport' ) );
	}
	return array(
		'deleted'     => true,
		'permanently' => $permanently,
		'page_id'     => (int) $page->ID,
	);
}
