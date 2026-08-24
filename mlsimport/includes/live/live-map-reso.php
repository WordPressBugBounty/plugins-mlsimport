<?php
/**
 * Live mode: raw RESO record -> the row/view-model shapes the render stack
 * already consumes.
 *
 * The row mirrors a wp_mlsimport_listings flat row (same columns, built by the
 * same reso-map via Mlsimport_Standalone_Row::build_columns()) so the card and
 * grid code read live rows unchanged. _live carries the raw record so the card
 * view and single-page VM can rebuild richer data without another fetch.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/standalone/class-mlsimport-standalone-row.php';

/**
 * Build a flat-table-shaped row object from a raw RESO record.
 *
 * @param array $record Raw RESO property (PascalCase fields, Media[] optional).
 * @return object Row with the flat-table columns + listing_key, thumb, _live.
 */
function mlsimport_live_row_from_reso( array $record ): object {
	// Same reso-map the flat-table build uses, so live rows match stored rows.
	$row = Mlsimport_Standalone_Row::build_columns( $record );

	// Carry the ListingKey for permalinks/media lookups.
	$row['listing_key'] = isset( $record['ListingKey'] ) ? (string) $record['ListingKey'] : '';

	// First photo URL as the thumbnail (empty when the record has no media).
	$urls         = mlsimport_live_media_urls( $record );
	$row['thumb'] = array() === $urls ? '' : $urls[0];
	// Stash the raw record for the card/single rebuilds that need richer data.
	$row['_live'] = $record;

	// Hand back an object (the render stack reads rows as objects).
	return (object) $row;
}

/**
 * The record's public property-photo URLs, in delivered order — the same
 * filter the import applies (MediaCategory Property Photo/Photo, non-empty
 * MediaURL) but returning CDN URLs instead of sideloading.
 *
 * @param array $record Raw RESO property.
 * @return string[] Photo URLs.
 */
function mlsimport_live_media_urls( array $record ): array {
	// No media collection at all: no URLs.
	if ( empty( $record['Media'] ) || ! is_array( $record['Media'] ) ) {
		return array();
	}

	$urls = array();
	// Keep delivered order; filter to property photos with a real URL.
	foreach ( $record['Media'] as $item ) {
		// Skip malformed items and ones with no MediaURL.
		if ( ! is_array( $item ) || empty( $item['MediaURL'] ) ) {
			continue;
		}
		// When a category is present, keep only Property Photo / Photo.
		if ( isset( $item['MediaCategory'] ) && ! in_array( $item['MediaCategory'], array( 'Property Photo', 'Photo' ), true ) ) {
			continue;
		}
		$urls[] = (string) $item['MediaURL'];
	}

	return $urls;
}
