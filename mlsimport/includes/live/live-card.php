<?php
/**
 * Live mode: rebuild the card view model from the live row.
 *
 * The mlsimport_card_view() helper derives address/permalink/thumbnail from post meta and
 * attachments — all empty on a synthetic negative-ID post. The card-view filter
 * fires last with the row in hand, so this one callback re-derives those pieces
 * from the raw record in $row->_live. Scalars (price, specs, status) already
 * come from the row and need no help.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fill the card view from the raw RESO record carried by a live row.
 *
 * Keys on the row carrying _live rather than the gate: a payload built while
 * live mode was on should render correctly even if rendering happens later.
 *
 * @param array       $view The view model mlsimport_card_view() built.
 * @param WP_Post     $post The (synthetic) listing post.
 * @param object|null $row  The live row.
 * @return array
 */
function mlsimport_live_card_view( array $view, $post, $row ): array {
	// Not a live row (no raw record attached): leave the stored-mode view as-is.
	if ( ! is_object( $row ) || empty( $row->_live ) || ! is_array( $row->_live ) ) {
		return $view;
	}
	$record = $row->_live;

	// Address: the raw record's UnparsedAddress, if present and non-blank.
	$address = isset( $record['UnparsedAddress'] ) ? trim( (string) $record['UnparsedAddress'] ) : '';
	if ( '' !== $address ) {
		$view['address'] = $address;
	}

	// Permalink points at the virtual single-listing URL keyed by ListingKey;
	// office name comes straight off the record.
	$view['permalink'] = mlsimport_live_url( isset( $row->listing_key ) ? (string) $row->listing_key : '' );
	$view['office']    = isset( $record['ListOfficeName'] ) ? trim( (string) $record['ListOfficeName'] ) : '';

	// Thumbnail: the first media URL the row resolved; has_thumb flags whether
	// the card should render an image at all.
	$thumb             = isset( $row->thumb ) ? (string) $row->thumb : '';
	$view['thumb']     = $thumb;
	$view['has_thumb'] = '' !== $thumb;

	return $view;
}
add_filter( 'mlsimport_card_view', 'mlsimport_live_card_view', 10, 3 );
