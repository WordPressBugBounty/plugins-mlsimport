<?php
/**
 * Build a Managed Listing title from the Import Task template.
 *
 * Title-token resolution is shared by every Stored mode theme. Keeping it in
 * the write module prevents ThemeImport and adapters from reading task options
 * or interpreting the raw RESO payload independently.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve supported title placeholders from one incoming property.
 */
final class Mlsimport_Stored_Listing_Title {

	/**
	 * Replace every supported `{Token}` in the configured title format.
	 *
	 * Unknown tokens remain visible so a configuration mistake is diagnosable.
	 * Array values use their first scalar member, matching existing title input.
	 *
	 * @param string               $format   Import Task title template.
	 * @param array<string, mixed> $property Incoming raw MLS property.
	 * @return string Final post title and slug source.
	 */
	public function build( string $format, array $property ): string {
		if ( '' === $format ) {
			return (string) ( $property['ListingKey'] ?? '' );
		}

		$extra = is_array( $property['extra_meta'] ?? null )
			? array_change_key_case( $property['extra_meta'], CASE_LOWER )
			: array();
		$meta  = is_array( $property['meta'] ?? null ) ? $property['meta'] : array();
		$values = array(
			'Address'             => $property['adr_title'] ?? '',
			'City'                => $property['adr_city'] ?? '',
			'CountyOrParish'      => $property['adr_county'] ?? '',
			'PropertyType'        => $property['adr_type'] ?? '',
			'Bedrooms'            => $property['adr_bedrooms'] ?? '',
			'Bathrooms'           => $property['adr_bathrooms'] ?? '',
			'ListingKey'          => $property['ListingKey'] ?? '',
			'ListingId'           => $property['adr_listingid'] ?? ( $property['ListingId'] ?? '' ),
			'StateOrProvince'     => $extra['stateorprovince'] ?? '',
			'PostalCode'          => $meta['property_zip'] ?? ( $meta['fave_property_zip'] ?? ( $meta['REAL_HOMES_property_zip'] ?? '' ) ),
			'StreetNumberNumeric' => $extra['streetnumbernumeric'] ?? '',
			'StreetName'          => $extra['streetname'] ?? '',
		);

		foreach ( $values as $token => $value ) {
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}
			$format = str_replace( '{' . $token . '}', (string) $value, $format );
		}
		return $format;
	}
}
