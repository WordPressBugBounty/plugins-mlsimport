<?php
/**
 * Direct MLS access: normalize a provider response into { records, total }.
 *
 * Pure functions — no WordPress, no network. Family 1 providers all answer
 * with the standard OData envelope: @odata.count + value[]. Provider-family
 * variants (Rapattoni, Realtor.ca) extend this file when they are ported.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse a raw response body into records + total.
 *
 * @param string $body   Raw HTTP response body.
 * @param array  $config Per-MLS config retained for call compatibility. Provider
 *                       adapters normalize any family-specific record fields.
 * @return array{records:array,total:int}|null Null when the body is not a
 *         usable listing payload (bad JSON or a provider error envelope) —
 *         callers treat null as "keep the warm cache".
 */
function mlsimport_live_parse_response( string $body, array $config ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $config is the family-dispatch seam.
	// Bad JSON or an error envelope: null tells callers to keep the warm cache.
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) || isset( $data['error'] ) ) {
		return null;
	}

	// Bridge native listings envelope: success/total/bundle. A single-listing
	// fetch returns bundle as one object instead of a list.
	if ( array_key_exists( 'success', $data ) || array_key_exists( 'bundle', $data ) ) {
		// Reject an unsuccessful or malformed bundle.
		if ( empty( $data['success'] ) || ! isset( $data['bundle'] ) || ! is_array( $data['bundle'] ) ) {
			return null;
		}
		$bundle  = $data['bundle'];
		// A list-shaped bundle stays a list; a single object is wrapped into one.
		$records = array_keys( $bundle ) === range( 0, count( $bundle ) - 1 ) || array() === $bundle
			? array_values( $bundle )
			: array( $bundle );
		// Use the envelope's total when given, else the returned record count.
		$total   = isset( $data['total'] ) && is_numeric( $data['total'] ) ? (int) $data['total'] : count( $records );

		return array(
			'records' => $records,
			'total'   => $total,
		);
	}

	// Generic RESO OData requires a real value[] member. An unrelated JSON
	// object is an invalid response, not a successful zero-listing result.
	if ( ! array_key_exists( 'value', $data ) || ! is_array( $data['value'] ) ) {
		return null;
	}
	$records = array_values( $data['value'] );
	// @odata.count when present, otherwise the page's own record count.
	$total   = isset( $data['@odata.count'] ) && is_numeric( $data['@odata.count'] )
		? (int) $data['@odata.count']
		: count( $records );

	return array(
		'records' => $records,
		'total'   => $total,
	);
}

/**
 * The SaaS /clusters answer as the map's { type: clusters } payload — the
 * same shape stored mode emits, so the map JS needs nothing new. Anything
 * off (failed, missing pieces, malformed rows) yields null and the caller
 * keeps the v1 zoom-in state.
 *
 * @param mixed $answer Decoded /clusters response body.
 * @return array|null
 */
function mlsimport_live_clusters_payload( $answer ): ?array {
	// Anything missing or malformed: null keeps the caller on the zoom-in state.
	if ( ! is_array( $answer ) || empty( $answer['success'] ) || ! isset( $answer['total'] ) || ! is_array( $answer['clusters'] ?? null ) ) {
		return null;
	}

	$clusters = array();
	// Keep only well-formed cluster rows, coercing their types.
	foreach ( $answer['clusters'] as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['lat'], $row['lng'], $row['count'] ) ) {
			continue;
		}
		$clusters[] = array(
			'lat'   => (float) $row['lat'],
			'lng'   => (float) $row['lng'],
			'count' => (int) $row['count'],
		);
	}

	// The map JS's { type: clusters } shape: total + the cleaned bubbles.
	$payload = array(
		'type'     => 'clusters',
		'total'    => (int) $answer['total'],
		'clusters' => $clusters,
	);

	// The index's exact in-bbox bounds: lets an unfiltered map block fit
	// itself from the same cached answer, no record fetch.
	$bounds = $answer['bounds'] ?? null;
	if ( is_array( $bounds ) && isset( $bounds['lat_min'], $bounds['lat_max'], $bounds['lng_min'], $bounds['lng_max'] ) ) {
		$payload['bounds'] = array(
			'lat_min' => (float) $bounds['lat_min'],
			'lat_max' => (float) $bounds['lat_max'],
			'lng_min' => (float) $bounds['lng_min'],
			'lng_max' => (float) $bounds['lng_max'],
		);
	}

	return $payload;
}
