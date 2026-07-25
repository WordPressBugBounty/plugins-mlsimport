<?php
/**
 * Standalone property print page.
 *
 * Printing the live page is a poor deliverable: the mortgage calculator, lead
 * forms, booking sidebar and sub-nav all print as dead ink, and the theme's
 * header/footer bleed in. So the Print button opens a dedicated URL —
 * ?mlsimport_print=1 on the listing's own permalink — that renders a clean,
 * self-contained HTML document and prints itself on load.
 *
 * The route hangs off template_include at priority 30, AFTER live mode's own
 * router (priority 20). That single hook covers both modes: stored listings
 * arrive as a real singular post, live listings arrive with the RESO record
 * already resolved into mlsimport_live_current_record(), so
 * mlsimport_property_data() answers correctly either way.
 *
 * Sections are the ordinary section renderers, dispatched through the same
 * mlsimport_render_property_section() the page uses — so print inherits every
 * fix the page gets. Only the header and the photo list are print-specific.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/property-sections.php';

/**
 * The print URL for a listing — its own permalink plus the print flag.
 *
 * @param string $permalink Listing permalink.
 * @return string
 */
function mlsimport_property_print_url( string $permalink ): string {
	// An empty permalink can't be printed; callers fall back to window.print().
	if ( '' === $permalink ) {
		return '';
	}
	return add_query_arg( 'mlsimport_print', '1', $permalink );
}

/**
 * The sections that make up the printed document, in order.
 *
 * Deliberately excludes everything that is interactive or worthless on paper:
 * lead/contact forms, schedule-tour, booking sidebar, mortgage calculator,
 * map (Leaflet never initializes in the print document), video/virtual tour,
 * similar listings, sub-nav and the share/print bar itself.
 *
 * @return string[] Registered section slugs.
 */
function mlsimport_property_print_sections(): array {
	/**
	 * Filters the sections rendered on the property print page.
	 *
	 * @param string[] $slugs Ordered registered section slugs.
	 */
	return (array) apply_filters(
		'mlsimport_property_print_sections',
		array(
			'overview',
			'description',
			'interior',
			'exterior',
			'structure',
			'utilities',
			'financial',
			'schools',
			'location',
			'listing_info',
			'other',
			'features',
			'agent_card',
			'attribution',
		)
	);
}

/**
 * The masthead logo for the print document: the site's custom logo when one is
 * set, else the site name as text.
 *
 * @return string
 */
function mlsimport_property_print_logo_html(): string {
	$logo_id = (int) get_theme_mod( 'custom_logo' );
	$src     = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
	// No logo configured → the site name still identifies the printout.
	if ( ! $src ) {
		return '<span class="mlsimport-print__site">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
	}
	return '<img class="mlsimport-print__logo" src="' . esc_url( $src ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" />';
}

/**
 * The QR code image for a listing URL — scanning the printout opens the listing.
 *
 * Rendered from a remote QR service (no bundled library), so it is skipped
 * silently when the endpoint is filtered away.
 *
 * @param string $url Listing URL to encode.
 * @return string
 */
function mlsimport_property_print_qr_html( string $url ): string {
	// Nothing to encode without a URL.
	if ( '' === $url ) {
		return '';
	}

	/**
	 * Filters the QR image source for the print page. Return '' to omit the QR.
	 *
	 * @param string $src QR image URL.
	 * @param string $url The listing URL being encoded.
	 */
	$src = (string) apply_filters(
		'mlsimport_property_print_qr_src',
		'https://qrcode.tec-it.com/API/QRCode?size=small&dpi=110&data=' . rawurlencode( $url ),
		$url
	);
	if ( '' === $src ) {
		return '';
	}
	return '<img class="mlsimport-print__qr" src="' . esc_url( $src ) . '" alt="' . esc_attr__( 'QR code linking to this listing', 'mlsimport' ) . '" />';
}

/**
 * Every listing photo, one per row, at full width.
 *
 * Handles both storage modes: stored listings carry attachment ids, live
 * passthrough listings carry MLS CDN URLs and no attachments at all.
 *
 * @param array $data Property view model.
 * @return string
 */
function mlsimport_property_print_photos_html( array $data ): string {
	// Attachment ids when stored, CDN URLs when live.
	$items = ! empty( $data['gallery_ids'] ) ? $data['gallery_ids'] : ( $data['gallery_urls'] ?? array() );
	if ( empty( $items ) ) {
		return '';
	}

	$html = '<div class="mlsimport-print__photos">';
	foreach ( $items as $item ) {
		// An int is an attachment; anything else is already a URL.
		$src = is_numeric( $item ) ? wp_get_attachment_image_url( (int) $item, 'large' ) : (string) $item;
		// Skip an attachment that no longer resolves.
		if ( ! $src ) {
			continue;
		}
		$html .= '<div class="mlsimport-print__photo"><img src="' . esc_url( $src ) . '" alt="" /></div>';
	}
	$html .= '</div>';
	return $html;
}

/**
 * The print document's masthead: logo, title, price, address and QR.
 *
 * @param array $data Property view model.
 * @return string
 */
function mlsimport_property_print_header_html( array $data ): string {
	$html = '<header class="mlsimport-print__header">';
	$html .= '<div class="mlsimport-print__brand">' . mlsimport_property_print_logo_html() . '</div>';

	$html .= '<div class="mlsimport-print__headline">';
	$html .= '<div class="mlsimport-print__headline-text">';
	if ( '' !== (string) $data['title'] ) {
		$html .= '<h1 class="mlsimport-print__title">' . esc_html( $data['title'] ) . '</h1>';
	}
	if ( null !== $data['price'] ) {
		$html .= '<p class="mlsimport-print__price">' . esc_html( mlsimport_format_price( $data['price'] ) ) . '</p>';
	}
	if ( '' !== (string) $data['address'] ) {
		$html .= '<p class="mlsimport-print__address">' . esc_html( $data['address'] ) . '</p>';
	}
	$html .= '</div>';
	// QR sits beside the headline so it survives even when there is no hero photo.
	$html .= mlsimport_property_print_qr_html( (string) $data['permalink'] );
	$html .= '</div>';

	$html .= '</header>';
	return $html;
}

/**
 * Render the complete print document for one listing.
 *
 * Self-contained: stylesheets are linked by URL (there is no wp_head here), and
 * an onload handler fires the print dialog once images have loaded.
 *
 * @param int $id Property post ID (0 in live mode).
 * @return string
 */
function mlsimport_property_print_document( int $id ): string {
	$data = mlsimport_property_data( $id );
	// No resolvable listing → no document.
	if ( empty( $data ) ) {
		return '';
	}

	// Sections, dispatched exactly as the page dispatches them.
	$sections = '';
	foreach ( mlsimport_property_print_sections() as $slug ) {
		$sections .= mlsimport_render_property_section( $slug, $id );
	}

	$url = defined( 'MLSIMPORT_PLUGIN_URL' ) ? MLSIMPORT_PLUGIN_URL : '';
	$ver = defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : '';

	// The section stylesheet gives the reused sections their normal look; the
	// print stylesheet layers the paper-specific rules on top.
	$styles  = '<link rel="stylesheet" href="' . esc_url( $url . 'public/css/mlsimport-property-sections.css?ver=' . $ver ) . '" />';
	$styles .= '<link rel="stylesheet" href="' . esc_url( $url . 'public/css/mlsimport-property-print.css?ver=' . $ver ) . '" />';
	// The brand colour normally rides in as an inline style on the enqueued
	// handle; inline it here since this document never runs wp_head.
	if ( function_exists( 'mlsimport_standalone_brand_color_css' ) ) {
		$brand = mlsimport_standalone_brand_color_css();
		if ( '' !== $brand ) {
			$styles .= '<style>' . wp_strip_all_tags( $brand ) . '</style>';
		}
	}

	$html  = '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '" />';
	$html .= '<title>' . esc_html( $data['title'] ) . '</title>';
	$html .= '<meta name="robots" content="noindex,nofollow" />';
	$html .= $styles;
	$html .= '</head><body class="mlsimport-print">';
	$html .= mlsimport_property_print_header_html( $data );
	$html .= mlsimport_property_print_photos_html( $data );
	$html .= '<div class="mlsimport-print__sections">' . $sections . '</div>';
	// Print once the document (images included) has finished loading.
	$html .= '<script>window.addEventListener("load",function(){window.print();});</script>';
	$html .= '</body></html>';

	/**
	 * Filters the complete property print document.
	 *
	 * @param string $html Full HTML document.
	 * @param array  $data Property view model.
	 * @param int    $id   Property post ID (0 in live mode).
	 */
	return (string) apply_filters( 'mlsimport_property_print_document', $html, $data, $id );
}

/**
 * Serve the print document when ?mlsimport_print=1 is on a listing URL.
 *
 * Runs after live mode's router so the live record is already resolved. Any
 * other request passes straight through untouched.
 *
 * @param string $template The template WordPress resolved.
 * @return string
 */
function mlsimport_property_print_template_include( $template ) {
	// Not a print request.
	if ( ! isset( $_GET['mlsimport_print'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public view of already-public content.
		return $template;
	}

	// Live listing: the router already resolved the record, so id 0 is valid.
	// Stored listing: must be a real single property page.
	$is_live = function_exists( 'mlsimport_live_current_record' ) && null !== mlsimport_live_current_record();
	if ( ! $is_live && ! is_singular( 'mlsimport_property' ) ) {
		return $template;
	}

	$document = mlsimport_property_print_document( $is_live ? 0 : (int) get_the_ID() );
	// Nothing to print → fall back to the normal page rather than a blank window.
	if ( '' === $document ) {
		return $template;
	}

	// phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- assembled and escaped in mlsimport_property_print_document().
	echo $document;
	exit;
}

add_filter( 'template_include', 'mlsimport_property_print_template_include', 30 );
