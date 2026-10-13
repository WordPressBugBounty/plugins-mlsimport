<?php
/**
 * Pure normalizer for MLS status enum values.
 *
 * Trestle's PrettyEnums=true returns spaced enum labels ("Active Under
 * Contract") while the plugin's status config and older imports store the raw
 * RESO enum ("ActiveUnderContract"). Both forms must compare equal, so every
 * status comparison funnels its operands through this normalizer: lowercase
 * and strip spaces, yielding a single stable key ("activeundercontract").
 *
 * No WordPress calls — safe to unit-test in isolation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reduce a status enum value to its comparison key.
 *
 * @param mixed $value Raw or PrettyEnums status value (anything non-string yields '').
 * @return string Lowercased, space-free comparison key.
 */
function mlsimport_normalize_status_enum( $value ) {
	// Non-string input has no comparable status; normalize to empty.
	if ( ! is_string( $value ) ) {
		return '';
	}

	// Trim, lowercase, then drop spaces so "Active Under Contract" == "ActiveUnderContract".
	return str_replace( ' ', '', strtolower( trim( $value ) ) );
}

/**
 * Turn a stored (normalized) status back into words a person reads.
 *
 * Statuses are stored as comparison keys ("activeundercontract"). The known
 * RESO statuses get their proper wording; anything else (an MLS's own status)
 * is shown with a capital first letter.
 *
 * @param mixed $value Raw or normalized status.
 * @return string Readable status, '' when there is none.
 */
function mlsimport_status_label( $value ) {
	$key    = mlsimport_normalize_status_enum( $value );
	$labels = array(
		'active'              => 'Active',
		'activeundercontract' => 'Active Under Contract',
		'pending'             => 'Pending',
		'closed'              => 'Closed',
		'sold'                => 'Sold',
		'comingsoon'          => 'Coming Soon',
		'canceled'            => 'Canceled',
		'cancelled'           => 'Cancelled',
		'expired'             => 'Expired',
		'withdrawn'           => 'Withdrawn',
		'hold'                => 'Hold',
		'incomplete'          => 'Incomplete',
		'delete'              => 'Deleted',
	);
	return $labels[ $key ] ?? ucfirst( $key );
}
