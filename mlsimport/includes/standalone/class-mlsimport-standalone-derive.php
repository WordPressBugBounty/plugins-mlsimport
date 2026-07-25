<?php
/**
 * Standalone (theme_id 990) derivation helpers.
 *
 * Pure transformations from a raw RESO property array into the normalized
 * scalar a flat-table column expects. No WordPress, no DB. RESO scalars are
 * frequently delivered as strings, so numeric inputs are coerced.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure RESO-value derivation helpers for the standalone write adapter.
 */
class Mlsimport_Standalone_Derive {

	/**
	 * Bathrooms as a decimal, preferring the RESO BathroomsTotalDecimal field.
	 *
	 * @param array $property Raw RESO property (PascalCase keys).
	 * @return float|null Decimal bathroom count, or null when unknown.
	 */
	public static function derive_bathrooms( array $property ): ?float {
		$decimal = self::numeric( $property, 'BathroomsTotalDecimal' );
		if ( null !== $decimal ) {
			return $decimal;
		}

		$full = self::numeric( $property, 'BathroomsFull' );
		$half = self::numeric( $property, 'BathroomsHalf' );

		if ( null !== $full || null !== $half ) {
			return (float) $full + ( (float) $half * 0.5 );
		}

		return null;
	}

	/**
	 * Lot size in square feet, preferring the RESO LotSizeSquareFeet field.
	 *
	 * @param array $property Raw RESO property (PascalCase keys).
	 * @return float|null Lot size in sqft, or null when unknown.
	 */
	public static function derive_lot_sqft( array $property ): ?float {
		$sqft = self::numeric( $property, 'LotSizeSquareFeet' );
		if ( null !== $sqft ) {
			return $sqft;
		}

		$acres = self::numeric( $property, 'LotSizeAcres' );
		if ( null !== $acres ) {
			return $acres * 43560;
		}

		return null;
	}

	/**
	 * Listing date, preferring ListingContractDate and falling back to OnMarketDate.
	 *
	 * @param array $property Raw RESO property (PascalCase keys).
	 * @return string|null Raw RESO date string, or null when unknown.
	 */
	public static function derive_list_date( array $property ): ?string {
		return self::non_empty_string( $property, 'ListingContractDate' )
			?? self::non_empty_string( $property, 'OnMarketDate' );
	}

	/**
	 * Clean virtual-tour URL from the RESO VirtualTourURLUnbranded value.
	 *
	 * The provider often delivers an <iframe> HTML blob rather than a bare URL;
	 * extract the src attribute. A blob with no iframe src falls back to the
	 * raw trimmed value (assumed to already be a URL).
	 *
	 * @param string|null $blob Raw VirtualTourURLUnbranded value.
	 * @return string|null Clean URL, or null when empty.
	 */
	public static function extract_tour_src( ?string $blob ): ?string {
		if ( preg_match( '/src="([^"]+)"/', (string) $blob, $matches ) ) {
			return $matches[1];
		}

		$raw = trim( (string) $blob );

		return '' === $raw ? null : $raw;
	}

	/**
	 * Normalize a RESO date/timestamp to MySQL DATETIME (Y-m-d H:i:s).
	 *
	 * RESO sends ISO-8601 (e.g. 2024-01-15T10:30:00Z); the literal date/time is
	 * preserved (T->space, fractional seconds + timezone offset dropped) without
	 * a timezone shift. Date-only values get 00:00:00. Returns null when the
	 * value is empty or not a recognizable date.
	 *
	 * @param mixed $value Raw date string.
	 * @return string|null
	 */
	public static function normalize_datetime( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}:\d{2}))?/', $value, $matches ) ) {
			return null;
		}

		$time = isset( $matches[2] ) ? $matches[2] : '00:00:00';

		return $matches[1] . ' ' . $time;
	}

	/**
	 * Break a single-block listing remark into readable paragraphs.
	 *
	 * MLS feeds deliver PublicRemarks as one unbroken run of text with no line
	 * breaks, so wpautop() (or any theme) renders it as one giant paragraph. This
	 * groups the remark into paragraphs of roughly $per sentences each, joined by
	 * blank lines so wpautop wraps each in its own <p>. Remarks that already carry
	 * their own structure (blank lines or HTML block tags) are returned untouched.
	 *
	 * @param string $text Raw remark text.
	 * @param int    $per  Sentences per paragraph (default 3).
	 * @return string Text with blank-line paragraph breaks, or the original.
	 */
	public static function paragraphs( string $text, int $per = 3 ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}

		// Respect remarks that already provide their own paragraphing.
		if ( preg_match( '/\n\s*\n/', $text ) || preg_match( '/<\s*(?:p|br|div|ul|ol)\b/i', $text ) ) {
			return $text;
		}

		// MLS feeds pad sentence gaps with single/double spaces -> normalize.
		$text  = (string) preg_replace( '/\s+/', ' ', $text );
		$parts = preg_split( '/(?<=[.!?])\s+(?=[A-Z0-9"\'(])/', $text );
		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return $text;
		}

		// Re-join fragments split mid-sentence after a common abbreviation
		// (e.g. "Mt. Hood") so paragraph breaks only land at real sentence ends.
		$sentences = array();
		foreach ( $parts as $part ) {
			$prev = count( $sentences ) - 1;
			if ( $prev >= 0 && preg_match( '/\b(?:Mt|St|Ave|Rd|Blvd|Ln|Ct|Apt|Ste|Sq|Ft|Approx|No|Jr|Sr|Dr)\.$/i', $sentences[ $prev ] ) ) {
				$sentences[ $prev ] .= ' ' . $part;
				continue;
			}
			$sentences[] = $part;
		}

		$paragraphs = array();
		foreach ( array_chunk( $sentences, max( 1, $per ) ) as $chunk ) {
			$paragraphs[] = implode( ' ', $chunk );
		}

		return implode( "\n\n", $paragraphs );
	}

	/**
	 * A numeric field coerced to float (RESO often sends numbers as strings).
	 *
	 * @param array  $property Raw RESO property.
	 * @param string $key      Field name.
	 * @return float|null Float value, or null when absent/non-numeric.
	 */
	private static function numeric( array $property, string $key ): ?float {
		return isset( $property[ $key ] ) && is_numeric( $property[ $key ] ) ? (float) $property[ $key ] : null;
	}

	/**
	 * A non-empty string field, or null when absent/empty.
	 *
	 * @param array  $property Raw RESO property.
	 * @param string $key      Field name.
	 * @return string|null String value, or null when absent/empty.
	 */
	private static function non_empty_string( array $property, string $key ): ?string {
		return isset( $property[ $key ] ) && '' !== $property[ $key ] ? (string) $property[ $key ] : null;
	}
}
