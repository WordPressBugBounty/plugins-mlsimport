<?php
/**
 * AI tools (MCP): the site identity (Appearance -> Customize -> Site Identity).
 *
 * The site identity is what every page shows about the site: the logo, the
 * site title, the tagline and the favicon (the site icon). WordPress keeps the
 * logo as the custom_logo theme setting, the title and the tagline as the
 * blogname / blogdescription options and the favicon as the site_icon option.
 * get-site-identity reads the four; update-site-identity changes only the
 * ones named and answers with the values they had, so a change can be undone.
 *
 * Public interface:
 *   mlsimport_mcp_site_identity_tools()            the tools of this file
 *   mlsimport_mcp_site_identity()                  the four values, as the answers show them
 *   mlsimport_mcp_get_site_identity( $input )      tool: get-site-identity
 *   mlsimport_mcp_update_site_identity( $input )   tool: update-site-identity
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
function mlsimport_mcp_site_identity_tools(): array {
	return array(
		'get-site-identity'    => array(
			'label'       => 'Site identity: logo, title, tagline, favicon',
			'description' => 'Reads what the site shows about itself: the logo (an image: id, title, url, width, height, type, or null when there is none), the site_title, the tagline and the site_icon (the favicon, an image or null). This is Appearance -> Customize -> Site Identity.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_get_site_identity',
			'changes'     => false,
		),
		'update-site-identity' => array(
			'label'       => 'Change the site logo, title, tagline or favicon',
			'description' => 'Sets the site logo, the site title, the tagline and the favicon (Appearance -> Customize -> Site Identity). Only the inputs named change; the others stay. The answer shows the new identity and, under previous, the values the site had, so the change can be undone with the same tool. logo_id and site_icon_id are images from list-media or upload-media; 0 removes the logo or the favicon.',
			'input'       => array(
				'logo_id'      => array(
					'type'        => 'integer',
					'description' => 'The image to show as the site logo (an id from list-media); 0 removes the logo.',
				),
				'site_title'   => array(
					'type'        => 'string',
					'description' => 'The site title.',
				),
				'tagline'      => array(
					'type'        => 'string',
					'description' => 'The tagline; an empty text clears it.',
				),
				'site_icon_id' => array(
					'type'        => 'integer',
					'description' => 'The image to use as the favicon (a square image, 512 by 512 or larger); 0 removes it.',
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_update_site_identity',
			'changes'     => true,
		),
	);
}

/**
 * The four identity values as the answers show them.
 *
 * The logo and the favicon are images (as list-media shows them) or null
 * when the site has none.
 *
 * @return array logo, site_title, tagline, site_icon.
 */
function mlsimport_mcp_site_identity(): array {
	$logo_id = (int) get_theme_mod( 'custom_logo' );
	$icon_id = (int) get_option( 'site_icon' );
	return array(
		'logo'       => $logo_id > 0 ? mlsimport_mcp_media_summary( $logo_id ) : null,
		'site_title' => (string) get_option( 'blogname' ),
		'tagline'    => (string) get_option( 'blogdescription' ),
		'site_icon'  => $icon_id > 0 ? mlsimport_mcp_media_summary( $icon_id ) : null,
	);
}

/**
 * Tool get-site-identity: the logo, the site title, the tagline, the favicon.
 *
 * @param array $input Unused: the tool takes no input.
 * @return array The four values.
 */
function mlsimport_mcp_get_site_identity( array $input = array() ): array {
	unset( $input );
	return mlsimport_mcp_site_identity();
}

/**
 * Tool update-site-identity: change the logo, the title, the tagline, the
 * favicon; only the inputs named.
 *
 * Step by step:
 * 0. The caller is an administrator (every tool checks that) and must also
 *    hold edit_theme_options, the Customizer's own permission for the logo
 *    and the favicon; and at least one input must be named.
 * 1. logo_id and site_icon_id, when named and not 0, must be images of the
 *    Media Library; otherwise nothing changes and the id is refused.
 * 2. Remember the identity the site has: it goes back under previous.
 * 3. site_title and tagline, when named, go to the blogname and
 *    blogdescription options the way Settings -> General stores them.
 * 4. logo_id goes to the custom_logo theme setting (removed when 0) and
 *    site_icon_id to the site_icon option (deleted when 0), the way the
 *    Customizer's Site Identity panel stores them.
 * 5. The answer is the new identity plus the previous values.
 *
 * @param array $input logo_id, site_title, tagline, site_icon_id; all optional.
 * @return array|WP_Error The new identity with previous, or the refusal.
 */
function mlsimport_mcp_update_site_identity( array $input ) {
	// Step 0.
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'mlsimport_site_identity_forbidden', __( 'Changing the site identity needs the manage_options and edit_theme_options permissions.', 'mlsimport' ) );
	}
	$named = array_intersect_key( $input, array_flip( array( 'logo_id', 'site_title', 'tagline', 'site_icon_id' ) ) );
	if ( empty( $named ) ) {
		return new WP_Error( 'mlsimport_site_identity_empty', __( 'Name at least one of logo_id, site_title, tagline or site_icon_id.', 'mlsimport' ) );
	}

	// Step 1.
	foreach ( array( 'logo_id', 'site_icon_id' ) as $image_input ) {
		$image_id = (int) ( $input[ $image_input ] ?? 0 );
		if ( $image_id > 0 && ! wp_attachment_is_image( $image_id ) ) {
			/* translators: 1: the input name, 2: the id sent */
			return new WP_Error( 'mlsimport_media_not_image', sprintf( __( '%1$s %2$d is not an image of the Media Library. list-media shows the images and their ids.', 'mlsimport' ), $image_input, $image_id ) );
		}
	}

	// Step 2.
	$previous = mlsimport_mcp_site_identity();

	// Step 3.
	if ( isset( $input['site_title'] ) ) {
		update_option( 'blogname', sanitize_text_field( (string) $input['site_title'] ) );
	}
	if ( isset( $input['tagline'] ) ) {
		update_option( 'blogdescription', sanitize_text_field( (string) $input['tagline'] ) );
	}

	// Step 4.
	if ( isset( $input['logo_id'] ) ) {
		if ( (int) $input['logo_id'] > 0 ) {
			set_theme_mod( 'custom_logo', (int) $input['logo_id'] );
		} else {
			remove_theme_mod( 'custom_logo' );
		}
	}
	if ( isset( $input['site_icon_id'] ) ) {
		if ( (int) $input['site_icon_id'] > 0 ) {
			update_option( 'site_icon', (int) $input['site_icon_id'] );
		} else {
			delete_option( 'site_icon' );
		}
	}

	// Step 5.
	$answer             = mlsimport_mcp_site_identity();
	$answer['previous'] = $previous;
	return $answer;
}
