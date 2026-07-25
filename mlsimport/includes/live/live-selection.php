<?php
/**
 * Live mode: explicit-set page blocks address listings by ListingKey.
 *
 * With no local posts there are no post IDs — a block's ids/id field holds
 * ListingKey STRINGS (never cast to int). One key fetch renders the set,
 * order preserved: List-by-ID as the paginated grid, Property List and the
 * Content Slider as cards, Featured Property as the design card, and the
 * Map block as embedded markers.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A block's ids arg as an ordered ListingKey list (comma string or array;
 * blanks dropped, strings preserved).
 *
 * @param mixed $raw The block's ids arg.
 * @return string[]
 */
function mlsimport_live_selection_keys( $raw ): array {
	$values = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
	return array_values( array_filter( array_map( 'trim', array_map( 'strval', $values ) ), 'strlen' ) );
}

/**
 * Render the List-by-ID block from ListingKeys when live mode is on.
 *
 * @param string|null $pre  Prior short-circuit value.
 * @param array       $args Block args (ids, count).
 * @return string|null
 */
function mlsimport_live_list_by_id( $pre, $args ) {
	if ( null !== $pre || ! mlsimport_live_mode_active() ) {
		return $pre;
	}

	$keys = mlsimport_live_selection_keys( isset( $args['ids'] ) ? $args['ids'] : '' );
	if ( array() === $keys ) {
		return $pre;
	}

	$per_page = isset( $args['count'] ) ? max( 1, (int) $args['count'] ) : 12;
	$total    = count( $keys );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET paging of a fixed key set.
	$current = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;

	$page_keys = array_slice( $keys, ( $current - 1 ) * $per_page, $per_page );
	$records   = mlsimport_live_keys( $page_keys );
	$records   = is_array( $records ) ? $records : array();

	$cards = Mlsimport_Standalone_Render::render_cards( mlsimport_live_payload( $records, $total ) );

	// Same markup as the stored block: the grid plus the one shared pager
	// wrapper every listing surface uses.
	return '<div class="mlsimport-page-block mlsimport-page-block--list-by-id">'
		. '<div class="mlsimport-results__grid">' . $cards . '</div>'
		. '<div class="mlsimport-results__pager">' . Mlsimport_Pagination::render( $total, $per_page, $current ) . '</div>'
		. '</div>';
}
add_filter( 'mlsimport_page_block_list_by_id_pre', 'mlsimport_live_list_by_id', 10, 2 );

/**
 * Render an explicit-set block's cards (Property List, Content Slider) from
 * ListingKeys when live mode is on. Blocks without an ids selection fall
 * through to the query path, which live mode already serves.
 *
 * @param string|null $pre         Prior short-circuit value.
 * @param array       $args        Block args (ids).
 * @param string      $slide_class Extra card wrapper class ('' or splide__slide).
 * @return string|null
 */
function mlsimport_live_ids_cards( $pre, $args, $slide_class ) {
	if ( null !== $pre || ! mlsimport_live_mode_active() ) {
		return $pre;
	}

	$keys = mlsimport_live_selection_keys( isset( $args['ids'] ) ? $args['ids'] : '' );
	if ( array() === $keys ) {
		return $pre;
	}

	$records = mlsimport_live_keys( $keys );
	$records = is_array( $records ) ? $records : array();

	return Mlsimport_Standalone_Render::render_cards( mlsimport_live_payload( $records, count( $records ) ), (string) $slide_class );
}
add_filter( 'mlsimport_page_block_ids_cards_pre', 'mlsimport_live_ids_cards', 10, 3 );

/**
 * Render the Featured Property card from a ListingKey when live mode is on.
 * The card context mirrors the stored builder's, from the live card view
 * (which fills permalink/photo/address from the raw record); the blurb comes
 * from PublicRemarks, as the importer would have stored it. An unknown key
 * renders nothing, like a missing post.
 *
 * @param string|null $pre  Prior short-circuit value.
 * @param array       $args Block args (id, design).
 * @return string|null
 */
function mlsimport_live_featured_card( $pre, $args ) {
	if ( null !== $pre || ! mlsimport_live_mode_active() ) {
		return $pre;
	}

	$key = trim( (string) ( isset( $args['id'] ) ? $args['id'] : '' ) );
	if ( '' === $key ) {
		return $pre;
	}

	$record = mlsimport_live_get( $key );
	if ( ! is_array( $record ) ) {
		return '';
	}

	$payload = mlsimport_live_payload( array( $record ), 1 );
	$post    = $payload['posts'][0];
	$row     = $payload['rows'][ $post->ID ];
	$v       = mlsimport_card_view( $post, $row );

	$c = array(
		'id'        => $row->listing_key,
		'permalink' => $v['permalink'],
		'address'   => $v['address'],
		'price'     => $v['price_fmt'],
		'status'    => $v['status'],
		'specs'     => implode( ' · ', $v['specs'] ),
		'excerpt'   => wp_trim_words( wp_strip_all_tags( (string) ( $record['PublicRemarks'] ?? '' ) ), 26, '…' ),
		'img'       => '' !== $v['thumb'] ? '<img class="mlsimport-featured__img" src="' . esc_url( $v['thumb'] ) . '" alt="' . esc_attr( $v['address'] ) . '" />' : '',
		'img_url'   => $v['thumb'],
		'agent'     => null,
	);

	return mlsimport_featured_card_render( $c, isset( $args['design'] ) ? (int) $args['design'] : 1 );
}
add_filter( 'mlsimport_page_block_featured_card_pre', 'mlsimport_live_featured_card', 10, 2 );

/**
 * Build the hand-picked Map block's marker set from ListingKeys when live
 * mode is on — the same markers the viewport map builds, embedded directly
 * (small by definition). Keys the MLS no longer knows just have no pin.
 *
 * @param array|null $pre  Prior short-circuit value.
 * @param array      $args Block args (ids).
 * @return array|null
 */
function mlsimport_live_ids_markers( $pre, $args ) {
	if ( null !== $pre || ! mlsimport_live_mode_active() ) {
		return $pre;
	}

	$keys = mlsimport_live_selection_keys( isset( $args['ids'] ) ? $args['ids'] : '' );
	if ( array() === $keys ) {
		return $pre;
	}

	$markers = array();
	foreach ( (array) mlsimport_live_keys( $keys ) as $record ) {
		$marker = is_array( $record ) ? mlsimport_live_marker_from_record( $record ) : null;
		if ( null !== $marker ) {
			$markers[] = $marker;
		}
	}
	return $markers;
}
add_filter( 'mlsimport_page_block_map_markers_pre', 'mlsimport_live_ids_markers', 10, 2 );
