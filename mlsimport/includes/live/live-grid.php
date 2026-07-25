<?php
/**
 * Live mode: swap the prepared-listings payload for a live MLS search.
 *
 * The render stack (grid, slider, AJAX repaint, archive) all flow through
 * Mlsimport_Standalone_Render::prepare(), whose payload passes the
 * mlsimport_prepared_listings filter — this one callback is the whole grid
 * read path. The render args already speak the live param vocabulary
 * (city/status/price_min/orderby/limit/page), so they pass straight to
 * mlsimport_live_search().
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replace the stored payload with live MLS results when live mode is on.
 *
 * Posts are synthetic WP_Post objects with NEGATIVE IDs: card templates run
 * get_post_meta()/has_post_thumbnail() on $post->ID before the card-view
 * filter fires, and a negative ID guarantees those hit nothing real. The row
 * keyed to each ID carries the flat-table scalars plus the raw record in
 * _live for the card rebuild.
 *
 * @param array $payload {posts, rows, total} from the stored query.
 * @param array $args    Render args (the standalone filter params).
 * @return array
 */
function mlsimport_live_prepared_listings( $payload, $args ): array {
	// Gate off: hand the stored query's payload straight through.
	if ( ! mlsimport_live_mode_active() ) {
		return (array) $payload;
	}

	// Live read: translate the render args into an MLS search.
	$result = mlsimport_live_search( (array) $args );
	if ( ! is_array( $result ) ) {
		// MLS unreachable and no warm cache: an empty grid, never the stored rows.
		return mlsimport_live_payload( array(), 0 );
	}

	// Build synthetic posts/rows from the live records + real total.
	return mlsimport_live_payload( $result['records'], (int) $result['total'] );
}
add_filter( 'mlsimport_prepared_listings', 'mlsimport_live_prepared_listings', 10, 2 );

/**
 * Build the {posts, rows, total} payload the render stack consumes from raw
 * RESO records — shared by the grid swap and the list-by-key selection.
 *
 * @param array $records Raw RESO records.
 * @param int   $total   The set's real total (may exceed count($records)).
 * @return array
 */
function mlsimport_live_payload( array $records, int $total ): array {
	$posts = array();
	$rows  = array();
	// One synthetic post + row per raw record, indexed off a reset counter.
	foreach ( array_values( $records ) as $i => $record ) {
		// Skip anything that isn't a record array.
		if ( ! is_array( $record ) ) {
			continue;
		}
		// Negative, sequential ID so get_post_meta()/has_post_thumbnail() on it
		// can never collide with a real post.
		$id  = -( $i + 1 );
		// Reso record -> flat-table-shaped row (carries _live for later rebuilds).
		$row = mlsimport_live_row_from_reso( $record );

		// Back-reference the synthetic ID onto the row and key the row by it.
		$row->post_id = $id;
		$rows[ $id ]  = $row;
		// A minimal WP_Post the render stack can treat like a real listing.
		$posts[]      = new WP_Post(
			(object) array(
				'ID'          => $id,
				'post_type'   => 'mlsimport_property',
				'post_status' => 'publish',
				'post_title'  => isset( $record['UnparsedAddress'] ) ? (string) $record['UnparsedAddress'] : $row->listing_key,
			)
		);
	}

	// The shape prepare()'s consumers expect: posts + rows keyed by ID + total.
	return array(
		'posts' => $posts,
		'rows'  => $rows,
		'total' => $total,
	);
}
