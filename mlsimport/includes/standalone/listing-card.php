<?php
/**
 * Standalone (theme_id 990) listing-card view model + hook surface.
 *
 * Every property grid renders through Mlsimport_Standalone_Render::render_cards(),
 * which picks the card template (v1/v2/v3) via mlsimport_standalone_card_template().
 * Each card template builds its data once from this one helper, so the three
 * designs stay consistent and a new card design is a thin template, not a new data
 * pass. The card action slots below are the WooCommerce-style extension points
 * fired inside every card template.
 *
 * Card action slots (each fired with ( WP_Post $post, object|null $row )):
 *   action mlsimport_card_before      — just after <article> opens (ribbons/badges)
 *   action mlsimport_card_after_media — direct child of <article>, after the media/link
 *                                       (favorite heart, gallery count); positioned over
 *                                       the photo via CSS, so it sits OUTSIDE the card's
 *                                       <a> link and fires on thumbnail-less cards too
 *   action mlsimport_card_body_start  — top of the card body
 *   action mlsimport_card_body_end    — bottom of the card body (CTA buttons)
 *   action mlsimport_card_after       — just before </article> closes
 * Filter:
 *   filter mlsimport_card_view        ( array $view, WP_Post $post, object|null $row )
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build one listing card's view model from the post + its listings row. Pulls the
 * clean RESO address, formatted price, spec line, status and agency, so the card
 * templates only lay out — they don't re-derive.
 *
 * @param WP_Post     $post The listing post.
 * @param object|null $row  The mlsimport_listings row (searchable scalars).
 * @return array
 */
function mlsimport_card_view( $post, $row ): array {
	// Cast the post ID once for the meta/permalink/thumbnail lookups below.
	$pid = (int) $post->ID;

	// Address: the clean RESO UnparsedAddress is the heading; the raw post title
	// (often an importer concatenation) is only the fallback.
	$address = trim( (string) get_post_meta( $pid, 'mlsimport_UnparsedAddress', true ) );
	if ( '' === $address ) {
		$address = get_the_title( $pid );
	}

	// Price: keep null when the row has none; otherwise format as "$ 1,234,567".
	$price     = ( isset( $row->price ) && null !== $row->price && '' !== $row->price ) ? (float) $row->price : null;
	$price_fmt = null !== $price ? '$ ' . number_format( $price ) : '';

	// Spec line: only the parts present.
	$specs = array();
	// Beds — pluralized; drop the decimal for whole numbers.
	if ( isset( $row->bedrooms ) && null !== $row->bedrooms && '' !== $row->bedrooms ) {
		$b       = (float) $row->bedrooms;
		$specs[] = sprintf( _n( '%s bed', '%s beds', (int) ceil( $b ), 'mlsimport' ), number_format_i18n( $b, ( $b === (float) (int) $b ) ? 0 : 1 ) );
	}
	// Baths — pluralized; drop the decimal for whole numbers.
	if ( isset( $row->bathrooms ) && null !== $row->bathrooms && '' !== $row->bathrooms ) {
		$ba      = (float) $row->bathrooms;
		$specs[] = sprintf( _n( '%s bath', '%s baths', (int) ceil( $ba ), 'mlsimport' ), number_format_i18n( $ba, ( $ba === (float) (int) $ba ) ? 0 : 1 ) );
	}
	// Living area — formatted number plus the ft² unit.
	if ( isset( $row->living_area ) && null !== $row->living_area && '' !== $row->living_area ) {
		$specs[] = number_format_i18n( (float) $row->living_area ) . ' ' . __( 'ft²', 'mlsimport' );
	}

	// Assemble the view model the card templates lay out (no further deriving).
	$view = array(
		'id'        => $pid,
		'permalink' => (string) get_permalink( $pid ),
		'address'   => $address,
		'price'     => $price,
		'price_fmt' => $price_fmt,
		'status'    => isset( $row->status ) ? trim( (string) $row->status ) : '',
		'specs'     => $specs,
		'office'    => trim( (string) get_post_meta( $pid, 'mlsimport_ListOfficeName', true ) ),
		'logo'      => function_exists( 'mlsimport_standalone_mls_logo_url' ) ? mlsimport_standalone_mls_logo_url() : '',
		'has_thumb' => has_post_thumbnail( $pid ),
		// Photo URL for the card media — rendered as a CSS background-image (never an
		// <img>), the same convention as the map/featured cards.
		'thumb'     => has_post_thumbnail( $pid ) ? (string) get_the_post_thumbnail_url( $pid, 'large' ) : '',
	);

	/** Filter one card's view model before the template lays it out. @since 6.4 */
	return (array) apply_filters( 'mlsimport_card_view', $view, $post, $row );
}
