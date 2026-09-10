<?php
/**
 * Dedupe address normalization (issue #282, decision #267).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The #267 policy anchor says two connections carry the SAME physical
 * property when their normalized addresses (street + unit + city/postal)
 * match. This file owns that normalization: pure string functions with no
 * WordPress dependency, so the matching rules are unit-testable in isolation
 * and every caller (the dedupe evaluator in mlsimport-dedupe.php) builds the
 * key the same single way. The key is stamped on every imported listing as
 * the 'mlsimport_address_key' post meta.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the normalized address key for one incoming MLS property payload.
 *
 * Step by step:
 * 1. Street line: the SaaS-computed 'adr_title' (always present, the same
 *    value the title builder uses); fallback to raw RESO UnparsedAddress cut
 *    at its first comma (the remainder is city/state/zip noise).
 * 2. Locality: the SaaS-computed 'adr_city' (fallback raw City), and when no
 *    city exists at all, the raw PostalCode — the #267 "city/postal" anchor.
 * 3. Normalize the street: lowercase, punctuation to spaces, collapse
 *    whitespace, canonicalize directionals (North => n) and street suffixes
 *    (Street => st, Boulevard => blvd, ...) so different feeds' spellings of
 *    one street compare equal.
 * 4. Unit: an explicit RESO UnitNumber wins; otherwise a trailing
 *    "apt/unit/suite/# X" embedded in the street line is extracted, so
 *    "123 Main St Apt 4B" matches "123 Main St" + UnitNumber "4B".
 * 5. A property without a street or without any locality returns '' — no
 *    key means it never participates in dedupe (matching would be a guess).
 *
 * @param array<string, mixed> $property Raw property payload from the SaaS.
 * @return string "street|unit|locality" key, or '' when unmatchable.
 */
function mlsimport_dedupe_address_key( array $property ): string {
	$extra = is_array( $property['extra_meta'] ?? null ) ? $property['extra_meta'] : array();

	// Step 1: resolve the street line.
	$street = (string) ( $property['adr_title'] ?? '' );
	if ( '' === trim( $street ) ) {
		$unparsed = (string) ( $extra['UnparsedAddress'] ?? '' );
		$comma    = strpos( $unparsed, ',' );
		$street   = false === $comma ? $unparsed : substr( $unparsed, 0, $comma );
	}

	// Step 2: resolve the locality anchor (city first, postal as fallback).
	$locality = mlsimport_dedupe_squash( (string) ( $property['adr_city'] ?? ( $extra['City'] ?? '' ) ) );
	if ( '' === $locality ) {
		$locality = mlsimport_dedupe_squash( (string) ( $extra['PostalCode'] ?? '' ) );
	}

	// Steps 3+4: canonical street tokens, extracting any embedded unit.
	$unit       = '';
	$tokens     = array();
	$raw_tokens = explode( ' ', mlsimport_dedupe_squash( $street ) );
	foreach ( $raw_tokens as $index => $token ) {
		// A unit marker ends the street: everything after it is the unit.
		// The leading token is never a marker ("Unit 5 Road" stays a street).
		if ( $index > 0 && in_array( $token, array( 'apt', 'apartment', 'unit', 'ste', 'suite' ), true ) ) {
			$unit = implode( ' ', array_slice( $raw_tokens, $index + 1 ) );
			break;
		}
		$tokens[] = mlsimport_dedupe_canonical_word( $token );
	}

	// An explicit RESO unit field is authoritative over the extracted one.
	$explicit_unit = mlsimport_dedupe_squash( (string) ( $extra['UnitNumber'] ?? '' ) );
	if ( '' !== $explicit_unit ) {
		$unit = $explicit_unit;
	}

	// Step 5: refuse to build a guessable key.
	$street_key = trim( implode( ' ', $tokens ) );
	if ( '' === $street_key || '' === $locality ) {
		return '';
	}

	return $street_key . '|' . $unit . '|' . $locality;
}

/**
 * Lowercase one address part, turn punctuation into spaces, collapse runs.
 *
 * '#' becomes the word 'unit' BEFORE punctuation stripping so "# 4B" and
 * "Apt 4B" normalize identically instead of the marker silently vanishing.
 *
 * @param string $part Raw address part.
 * @return string Normalized single-spaced lowercase text.
 */
function mlsimport_dedupe_squash( string $part ): string {
	$part = str_replace( '#', ' unit ', strtolower( $part ) );
	$part = (string) preg_replace( '/[^a-z0-9]+/', ' ', $part );
	return trim( (string) preg_replace( '/ +/', ' ', $part ) );
}

/**
 * Map one street token to its canonical short form.
 *
 * Only directionals and the common USPS street suffixes are mapped — enough
 * to make two RESO feeds' spellings of one street compare equal without
 * guessing at genuinely different names.
 *
 * @param string $token Lowercased street token.
 * @return string Canonical token (unchanged when unmapped).
 */
function mlsimport_dedupe_canonical_word( string $token ): string {
	static $map = array(
		'street'    => 'st',
		'avenue'    => 'ave',
		'av'        => 'ave',
		'drive'     => 'dr',
		'road'      => 'rd',
		'boulevard' => 'blvd',
		'lane'      => 'ln',
		'court'     => 'ct',
		'place'     => 'pl',
		'terrace'   => 'ter',
		'terr'      => 'ter',
		'circle'    => 'cir',
		'highway'   => 'hwy',
		'parkway'   => 'pkwy',
		'square'    => 'sq',
		'north'     => 'n',
		'south'     => 's',
		'east'      => 'e',
		'west'      => 'w',
		'northeast' => 'ne',
		'northwest' => 'nw',
		'southeast' => 'se',
		'southwest' => 'sw',
	);
	return $map[ $token ] ?? $token;
}
