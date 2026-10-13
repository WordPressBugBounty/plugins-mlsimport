<?php
/**
 * Listing details ("facts") captured for Listing Change Events.
 *
 * WHY: the notification filter needs a listing's city, neighborhood, zip,
 * property type, price and status, and the daily email needs its address. For a
 * removed listing the post no longer exists when the email is built, so these
 * details are captured while the listing is being written and travel with the
 * event row (events table column `facts`, JSON).
 *
 * The same snapshot is kept on the post (meta `_mlsimport_listing_facts`) for
 * two readers:
 *   - the next write compares it with the incoming record to detect a changed
 *     listing agent / office and changed photos / description;
 *   - reconciliation reads it right before deleting a listing that vanished
 *     from the feed, because that path has no incoming record at all.
 *
 * Everything is taken from the incoming MLS record, so it works the same in
 * every theme mode and does not depend on which fields the admin imports.
 * On a theme site the SaaS moves the fields the theme has its own slot for
 * (ListPrice, PostalCode) out of `extra_meta` into `meta` under the theme's
 * key, so price and zip fall back to that key (the caller names it, from the
 * theme adapter). Without the fallback a price filter drops every event.
 * A listing written before this feature has no snapshot until its next update.
 *
 * Pure: no WordPress calls in from_property().
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the facts snapshot of one incoming MLS record.
 */
final class Mlsimport_Listing_Event_Facts {

	/** Post meta that keeps the latest snapshot of a stored listing. */
	const META_KEY = '_mlsimport_listing_facts';

	/**
	 * Build the snapshot from one incoming MLS property.
	 *
	 * Step 1: read the RESO fields from extra_meta (keys compared without case).
	 * Step 2: fall back to the address values the SaaS prepares (adr_*).
	 * Step 3: reduce the description to a hash — only "did it change" matters.
	 * Price and zip: the RESO field when present, else the theme meta key named
	 * in $theme_keys (a theme site receives them only there).
	 *
	 * @param array<string, mixed> $property   Raw property returned by MLSImport SaaS.
	 * @param string               $status     Normalized status of this record.
	 * @param array<string, string> $theme_keys Theme meta keys of 'price' and 'zip'
	 *                                          ('' or absent = no fallback).
	 * @return array<string, string> city, neighborhood, zip, property_type, price,
	 *                               status, address, agent, agent_name, office,
	 *                               office_name, photos, remarks, photo (URL of the
 *                               first photo, for the email thumbnail).
	 */
	public static function from_property( array $property, string $status, array $theme_keys = array() ): array {
		// Step 1: case-insensitive access to the RESO payload.
		$extra = is_array( $property['extra_meta'] ?? null )
			? array_change_key_case( $property['extra_meta'], CASE_LOWER )
			: array();
		$read  = static function ( string $field ) use ( $extra ): string {
			$value = $extra[ strtolower( $field ) ] ?? '';
			// Some providers deliver single values as a one-element list.
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
			}
			return is_scalar( $value ) ? trim( (string) $value ) : '';
		};

		// Theme sites: a field the SaaS moved into theme meta is read from there.
		$meta     = is_array( $property['meta'] ?? null ) ? $property['meta'] : array();
		$or_theme = static function ( string $value, string $part ) use ( $meta, $theme_keys ): string {
			$key = (string) ( $theme_keys[ $part ] ?? '' );
			if ( '' !== $value || '' === $key ) {
				return $value;
			}
			return self::text( $meta[ $key ] ?? '' );
		};

		// Step 2: address parts prepared by the SaaS win when present.
		$city    = '' !== $read( 'City' ) ? $read( 'City' ) : self::text( $property['adr_city'] ?? '' );
		$type    = '' !== $read( 'PropertyType' ) ? $read( 'PropertyType' ) : self::text( $property['adr_type'] ?? '' );
		$address = self::text( $property['adr_title'] ?? '' );
		if ( '' === $address ) {
			$address = $read( 'UnparsedAddress' );
		}

		// Step 3: description fingerprint ('' when the record has no description).
		$content = self::text( $property['content'] ?? '' );

		// The first photo in feed order: the email shows it as a thumbnail, also
		// for a listing that has already left the site.
		$photo = '';
		$order = PHP_INT_MAX;
		foreach ( (array) ( $property['Media'] ?? array() ) as $media ) {
			$url  = is_array( $media ) ? self::text( $media['MediaURL'] ?? '' ) : '';
			$rank = is_array( $media ) ? (int) ( $media['Order'] ?? PHP_INT_MAX - 1 ) : PHP_INT_MAX;
			if ( '' !== $url && $rank < $order ) {
				$photo = $url;
				$order = $rank;
			}
		}

		return array(
			'city'          => $city,
			'neighborhood'  => $read( 'SubdivisionName' ),
			'zip'           => $or_theme( $read( 'PostalCode' ), 'zip' ),
			'property_type' => $type,
			'price'         => $or_theme( $read( 'ListPrice' ), 'price' ),
			'status'        => $status,
			'address'       => $address,
			'agent'         => $read( 'ListAgentKey' ),
			'agent_name'    => $read( 'ListAgentFullName' ),
			'office'        => $read( 'ListOfficeKey' ),
			'office_name'   => $read( 'ListOfficeName' ),
			'photos'        => $read( 'PhotosChangeTimestamp' ),
			'remarks'       => '' !== $content ? md5( $content ) : '',
			'photo'         => $photo,
		);
	}

	/**
	 * Reduce a payload value to trimmed text (first member of a list).
	 *
	 * @param mixed $value Payload value.
	 * @return string Text, or '' when not scalar.
	 */
	private static function text( $value ): string {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
