<?php
/**
 * Provider identification helpers.
 *
 * MLS providers are allocated by numeric mls_id range. These are pure functions
 * (no WordPress dependency) so they can be unit tested in isolation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Whether an mls_id belongs to a PropTx / AMPRE RESO Web API board (e.g. TRREB).
 *
 * PropTx boards are allocated the 9000-9999 id block, consistent with the
 * exclusive-upper 1000-wide blocks used by the other providers.
 *
 * @param int|string $mls_id
 * @return bool
 */
function mlsimport_is_proptx_provider( $mls_id ) {
	$mls_id_int = (int) $mls_id;
	return $mls_id_int >= 9000 && $mls_id_int < 10000;
}

/**
 * Normalise a stored last-import date into a full OData DateTimeOffset literal.
 *
 * The plugin stores the last-import marker as 'Y-m-d\TH:i' (e.g. 2026-07-01T00:00),
 * which RESO Web API providers such as AMPRE reject in a
 * $filter=ModificationTimestamp comparison. This produces a UTC literal with a Z
 * offset (e.g. 2026-07-01T00:00:00.000Z). An empty input is returned unchanged so
 * callers can keep treating '' as "no incremental marker".
 *
 * @param string $last_date
 * @return string
 */
function mlsimport_format_odata_modification_time( $last_date ) {
	if ( '' === $last_date ) {
		return '';
	}

	$date_time = new DateTime( $last_date, new DateTimeZone( 'UTC' ) );
	return $date_time->format( 'Y-m-d\TH:i:s.000\Z' );
}
