<?php
/**
 * Standalone (theme_id 990) Featured Property card — six WPResidence-style designs.
 *
 * The Featured Property page block renders ONE hand-picked listing in one of six
 * layouts that mirror WpResidence's featured_property_1..6 templates (image+price
 * badge, overlay, horizontal split, full-bleed gradient hero, floating content
 * box, status hero). Unlike the grid card these are single-listing showcases, so
 * they live here rather than in the shared card templates.
 *
 * Data comes from mlsimport_card_view() (address, price, status, beds/baths/size,
 * office). WpResidence's designs also show an agent face/name; the standalone
 * importer stores no agent record, so that element is intentionally omitted.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/listing-card.php';
require_once __DIR__ . '/property-sections.php';

/**
 * Render a single listing as a featured card in one of six designs.
 *
 * @param int $id     Listing post ID.
 * @param int $design Design number 1-6 (out-of-range falls back to 1).
 * @return string Card HTML, or '' when the post is missing/not a listing.
 */
function mlsimport_featured_card( int $id, int $design ): string {
	// Guard: bail unless the ID resolves to a real standalone listing post.
	$post = get_post( $id );
	if ( ! $post || 'mlsimport_property' !== $post->post_type ) {
		return '';
	}

	// Fetch the listing's fast-table row (searchable scalars) for this post.
	global $wpdb;
	$table = $wpdb->prefix . 'mlsimport_listings';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $id ) );

	// Build the shared card view model; clamp the design to the 1-6 range.
	$v      = mlsimport_card_view( $post, $row );
	$design = ( $design >= 1 && $design <= 6 ) ? $design : 1;

	// The raw content/excerpt as a short blurb for the designs that show one.
	$source  = '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_content;
	$excerpt = wp_trim_words( wp_strip_all_tags( (string) $source ), 26, '…' );

	// Linked agent (assigned at import via list_agent_id): the agent post's
	// featured image is the agent face the image-based designs show.
	$agent      = function_exists( 'mlsimport_property_data' ) ? mlsimport_property_data( $id )['agent'] ?? null : null;
	$agent_face = ( $agent && ! empty( $agent['photo_id'] ) ) ? array(
		'name'  => (string) $agent['name'],
		'photo' => (string) wp_get_attachment_image_url( (int) $agent['photo_id'], 'thumbnail' ),
		'url'   => ! empty( $agent['id'] ) ? (string) get_permalink( (int) $agent['id'] ) : '',
	) : null;

	// Assemble the card context the design renderers consume (address, price,
	// specs, blurb, image markup + URL, and the agent face).
	$c = array(
		'id'        => $v['id'],
		'permalink' => $v['permalink'],
		'address'   => $v['address'],
		'price'     => $v['price_fmt'],
		'status'    => $v['status'],
		'specs'     => implode( ' · ', $v['specs'] ),
		'excerpt'   => $excerpt,
		'img'       => $v['has_thumb'] ? get_the_post_thumbnail( $id, 'large', array( 'class' => 'mlsimport-featured__img', 'alt' => $v['address'] ) ) : '',
		'img_url'   => $v['has_thumb'] ? (string) get_the_post_thumbnail_url( $id, 'large' ) : '',
		'agent'     => $agent_face,
	);

	// Hand off to the shared layout tail.
	return mlsimport_featured_card_render( $c, $design );
}

/**
 * Lay a built card context out in one of the six designs — the shared tail of
 * the stored (post) and live (RESO record) featured cards.
 *
 * @param array $c      Card context (id, permalink, address, price, status,
 *                      specs, excerpt, img, img_url, agent).
 * @param int   $design Design number 1-6 (out-of-range falls back to 1).
 * @return string
 */
function mlsimport_featured_card_render( array $c, int $design ): string {
	// Clamp the design number into the supported 1-6 range.
	$design = ( $design >= 1 && $design <= 6 ) ? $design : 1;

	// Dispatch to the matching design renderer; design 1 is the safety fallback.
	$fn   = 'mlsimport_featured_design_' . $design;
	$html = function_exists( $fn ) ? $fn( $c ) : mlsimport_featured_design_1( $c );

	// Wrap the design's markup in the design-tagged, id-carrying outer element.
	$class = 'mlsimport-featured mlsimport-featured--design-' . $design;
	return '<div class="' . esc_attr( $class ) . '" data-id="' . esc_attr( (string) $c['id'] ) . '">' . $html . '</div>';
}

/**
 * Status + "Featured" tag cluster (shared by the image-based designs).
 *
 * @param array $c Card context.
 * @return string
 */
function mlsimport_featured_tags( array $c ): string {
	$out = '<div class="mlsimport-featured__tags">';
	$out .= '<span class="mlsimport-featured__flag">' . esc_html__( 'Featured', 'mlsimport' ) . '</span>';
	if ( '' !== $c['status'] ) {
		$out .= '<span class="mlsimport-featured__badge">' . esc_html( $c['status'] ) . '</span>';
	}
	$out .= '</div>';
	return $out;
}

/**
 * Round agent-face avatar (the linked agent's featured image), linked to the
 * agent page. Empty when the listing has no agent photo. Used by the image-based
 * designs that float the face over the photo.
 *
 * @param array $c Card context.
 * @return string
 */
function mlsimport_featured_agent_avatar( array $c ): string {
	// No agent, or agent without a photo → render nothing.
	$a = $c['agent'];
	if ( empty( $a ) || '' === $a['photo'] ) {
		return '';
	}
	// The round face image; wrapped in a link when the agent has a page.
	$face = '<span class="mlsimport-featured__agent-photo" style="background-image:url(' . esc_url( $a['photo'] ) . ')" role="img" aria-label="' . esc_attr( $a['name'] ) . '"></span>';
	if ( '' !== $a['url'] ) {
		return '<a class="mlsimport-featured__agent" href="' . esc_url( $a['url'] ) . '">' . $face . '</a>';
	}
	return '<span class="mlsimport-featured__agent">' . $face . '</span>';
}

/**
 * Agent avatar + name chip (the linked agent's featured image and display name).
 * Empty when the listing has no agent photo. Used by the designs that show the
 * agent in the text area rather than over the photo.
 *
 * @param array $c Card context.
 * @return string
 */
function mlsimport_featured_agent_chip( array $c ): string {
	// No agent, or agent without a photo → render nothing.
	$a = $c['agent'];
	if ( empty( $a ) || '' === $a['photo'] ) {
		return '';
	}
	// Face image plus (optionally) the agent name, optionally wrapped in a link.
	$inner = '<span class="mlsimport-featured__agent-photo" style="background-image:url(' . esc_url( $a['photo'] ) . ')" role="img" aria-label="' . esc_attr( $a['name'] ) . '"></span>';
	if ( '' !== $a['name'] ) {
		$inner .= '<span class="mlsimport-featured__agent-name">' . esc_html( $a['name'] ) . '</span>';
	}
	if ( '' !== $a['url'] ) {
		return '<a class="mlsimport-featured__agent mlsimport-featured__agent--chip" href="' . esc_url( $a['url'] ) . '">' . $inner . '</a>';
	}
	return '<span class="mlsimport-featured__agent mlsimport-featured__agent--chip">' . $inner . '</span>';
}

/**
 * Design 1 — image with price badge + tags; title/specs bar overlapping the
 * image's bottom edge. Mirrors WpResidence featured_property_type1.
 *
 * @param array $c Card context.
 * @return string
 */
function mlsimport_featured_design_1( array $c ): string {
	$price = '' !== $c['price'] ? '<div class="mlsimport-featured__price">' . esc_html( $c['price'] ) . '</div>' : '';
	$specs = '' !== $c['specs'] ? '<div class="mlsimport-featured__specs">' . esc_html( $c['specs'] ) . '</div>' : '';
	return '<div class="mlsimport-featured__media">'
		. mlsimport_featured_tags( $c )
		. '<a class="mlsimport-featured__media-link" href="' . esc_url( $c['permalink'] ) . '">' . $c['img'] . '</a>'
		. $price
		. '</div>'
		. '<div class="mlsimport-featured__bar">'
		. mlsimport_featured_agent_chip( $c )
		. '<h2 class="mlsimport-featured__title"><a href="' . esc_url( $c['permalink'] ) . '">' . esc_html( $c['address'] ) . '</a></h2>'
		. $specs
		. '</div>';
}

/**
 * Design 2 — image with title + price overlaid at the bottom. Mirrors
 * featured_property_type2.
 *
 * @param array $c Card context.
 * @return string
 */
function mlsimport_featured_design_2( array $c ): string {
	$price = '' !== $c['price'] ? '<span class="mlsimport-featured__price">' . esc_html( $c['price'] ) . '</span>' : '';
	return '<div class="mlsimport-featured__media">'
		. mlsimport_featured_tags( $c )
		. '<a class="mlsimport-featured__media-link" href="' . esc_url( $c['permalink'] ) . '">' . $c['img'] . '</a>'
		. mlsimport_featured_agent_avatar( $c )
		. '<div class="mlsimport-featured__overlay">'
		. '<h2 class="mlsimport-featured__title"><a href="' . esc_url( $c['permalink'] ) . '">' . esc_html( $c['address'] ) . '</a></h2>'
		. $price
		. '</div>'
		. '</div>';
}

/**
 * Design 3 — horizontal split: photo left, title/price/excerpt/specs right.
 * Mirrors featured_property_type3.
 *
 * @param array $c Card context.
 * @return string
 */
function mlsimport_featured_design_3( array $c ): string {
	$price   = '' !== $c['price'] ? '<div class="mlsimport-featured__price">' . esc_html( $c['price'] ) . '</div>' : '';
	$excerpt = '' !== $c['excerpt'] ? '<p class="mlsimport-featured__excerpt">' . esc_html( $c['excerpt'] ) . '</p>' : '';
	$specs   = '' !== $c['specs'] ? '<div class="mlsimport-featured__specrow">' . esc_html( $c['specs'] ) . '</div>' : '';
	return '<div class="mlsimport-featured__media">'
		. mlsimport_featured_tags( $c )
		. '<a class="mlsimport-featured__media-link" href="' . esc_url( $c['permalink'] ) . '">' . $c['img'] . '</a>'
		. mlsimport_featured_agent_avatar( $c )
		. '</div>'
		. '<div class="mlsimport-featured__panel">'
		. '<h2 class="mlsimport-featured__title"><a href="' . esc_url( $c['permalink'] ) . '">' . esc_html( $c['address'] ) . '</a></h2>'
		. $price
		. $excerpt
		. $specs
		. '</div>';
}

/**
 * Design 4 — full-bleed image, dark gradient, "Featured Property" label + big
 * title + "discover more". Mirrors featured_property_type4.
 *
 * @param array $c Card context.
 * @return string
 */
function mlsimport_featured_design_4( array $c ): string {
	$style = '' !== $c['img_url'] ? ' style="background-image:url(' . esc_url( $c['img_url'] ) . ')"' : '';
	// A div (not an anchor) hero so the inner title/more/agent links stay valid.
	// Agent chip first (its own line, left), then the "Discover more" link below it —
	// otherwise both are inline and the chip floats to the right of the link.
	return '<div class="mlsimport-featured__hero"' . $style . '>'
		. '<div class="mlsimport-featured__gradient"></div>'
		. '<div class="mlsimport-featured__hero-body">'
		. '<div class="mlsimport-featured__label">' . esc_html__( 'Featured Property', 'mlsimport' ) . '</div>'
		. '<h2 class="mlsimport-featured__title"><a href="' . esc_url( $c['permalink'] ) . '">' . esc_html( $c['address'] ) . '</a></h2>'
		. mlsimport_featured_agent_chip( $c )
		. '<a class="mlsimport-featured__more" href="' . esc_url( $c['permalink'] ) . '">' . esc_html__( 'Discover more', 'mlsimport' ) . ' &rsaquo;</a>'
		. '</div>'
		. '</div>';
}

/**
 * Design 5 — full-bleed image with a floating white content box (price label,
 * title, specs, excerpt, discover more). Mirrors featured_property_type5.
 *
 * @param array $c Card context.
 * @return string
 */
function mlsimport_featured_design_5( array $c ): string {
	$style   = '' !== $c['img_url'] ? ' style="background-image:url(' . esc_url( $c['img_url'] ) . ')"' : '';
	$price   = '' !== $c['price'] ? '<div class="mlsimport-featured__label">' . esc_html( $c['price'] ) . '</div>' : '';
	$specs   = '' !== $c['specs'] ? '<div class="mlsimport-featured__specrow">' . esc_html( $c['specs'] ) . '</div>' : '';
	$excerpt = '' !== $c['excerpt'] ? '<p class="mlsimport-featured__excerpt">' . esc_html( $c['excerpt'] ) . '</p>' : '';
	return '<div class="mlsimport-featured__hero"' . $style . '>'
		. '<div class="mlsimport-featured__box">'
		. $price
		. '<h2 class="mlsimport-featured__title"><a href="' . esc_url( $c['permalink'] ) . '">' . esc_html( $c['address'] ) . '</a></h2>'
		. $specs
		. $excerpt
		. '<a class="mlsimport-featured__more" href="' . esc_url( $c['permalink'] ) . '">' . esc_html__( 'discover more', 'mlsimport' ) . ' &rsaquo;</a>'
		. '</div>'
		. '</div>';
}

/**
 * Design 6 — full-bleed image, gradient, status badge, title + specs + price
 * over the image. Mirrors featured_property_type6.
 *
 * @param array $c Card context.
 * @return string
 */
function mlsimport_featured_design_6( array $c ): string {
	$style = '' !== $c['img_url'] ? ' style="background-image:url(' . esc_url( $c['img_url'] ) . ')"' : '';
	$badge = '' !== $c['status'] ? '<span class="mlsimport-featured__badge">' . esc_html( $c['status'] ) . '</span>' : '';
	$specs = '' !== $c['specs'] ? '<div class="mlsimport-featured__features">' . esc_html( $c['specs'] ) . '</div>' : '';
	$price = '' !== $c['price'] ? '<div class="mlsimport-featured__price">' . esc_html( $c['price'] ) . '</div>' : '';
	return '<a class="mlsimport-featured__hero" href="' . esc_url( $c['permalink'] ) . '"' . $style . '>'
		. '<div class="mlsimport-featured__gradient"></div>'
		. $badge
		. '<div class="mlsimport-featured__hero-body">'
		. '<h2 class="mlsimport-featured__title">' . esc_html( $c['address'] ) . '</h2>'
		. $specs
		. $price
		. '</div>'
		. '</a>';
}
