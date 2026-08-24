<?php
/**
 * Direct MLS access: the read functions everything else calls.
 *
 * Only mlsimport_live_search() and mlsimport_live_get() talk HTTP to the
 * MLS; both go through the cache. Query building and response parsing stay
 * in the pure modules.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search listings directly on the MLS.
 *
 * @param array $params Standalone filter params (city, status, limit, page, …).
 * @return array{records:array,total:int}|null Null when the MLS is unreachable
 *         and no warm cache exists.
 */
function mlsimport_live_search( array $params ) {
	$config = mlsimport_live_config();
	if ( array() === $config ) {
		return null;
	}

	// Quantized bbox (~110m outward grid) BEFORE the query is built: nearby
	// map pans produce the identical query and reuse one cache entry.
	$params = mlsimport_live_quantize_bbox( $params );

	$query = mlsimport_live_build_query( $params, $config );
	$key   = mlsimport_live_cache_key(
		'search',
		array(
			'q' => $query,
			'u' => $config['api_import_url'],
			'm' => (string) ( $config['api_media_url'] ?? '' ),
		)
	);

	return mlsimport_live_remember(
		$key,
		static function () use ( $query ) {
			return mlsimport_live_request( $query );
		}
	);
}

/**
 * Fetch one listing by ListingKey.
 *
 * @param string $listing_key RESO ListingKey (string — never cast to int).
 * @return array|null The raw RESO record, or null when not found/unreachable.
 */
function mlsimport_live_get( string $listing_key ) {
	$listing_key = trim( $listing_key );
	if ( '' === $listing_key ) {
		return null;
	}

	$config = mlsimport_live_config();
	if ( array() === $config ) {
		return null;
	}

	$type     = isset( $config['type'] ) ? (string) $config['type'] : '';
	$provider = Mlsimport_Provider_Family::adapter( $type, $config['mls_id'] ?? 0 );
	$query    = $provider->build_direct_get_query( $listing_key, $config );

	// 'q' keys the config shape too (dialect, $expand): a provider-type or
	// expand change refetches instead of serving the stale-shaped record.
	$key    = mlsimport_live_cache_key(
		'get',
		array(
			'k' => $listing_key,
			'q' => $query,
			'u' => $config['api_import_url'],
			'm' => (string) ( $config['api_media_url'] ?? '' ),
		)
	);
	$result = mlsimport_live_remember(
		$key,
		static function () use ( $query ) {
			return mlsimport_live_request( $query );
		}
	);

	return is_array( $result ) && ! empty( $result['records'][0] ) ? $result['records'][0] : null;
}

/**
 * Fetch an explicit set of listings by ListingKey, in the given order.
 *
 * One search request (ListingKey list, no status baseline), reordered to the
 * input order — keys the MLS no longer knows are simply absent.
 *
 * @param string[] $keys ListingKeys (strings — never cast to int).
 * @return array[]|null Raw RESO records in input order, or null when the MLS
 *         is unreachable and no warm cache exists.
 */
function mlsimport_live_keys( array $keys ) {
	$keys = array_values( array_filter( array_map( 'trim', array_map( 'strval', $keys ) ), 'strlen' ) );
	if ( array() === $keys ) {
		return array();
	}

	$result = mlsimport_live_search(
		array(
			'keys'  => $keys,
			'limit' => count( $keys ),
		)
	);
	if ( ! is_array( $result ) ) {
		return null;
	}

	$by_key = array();
	foreach ( $result['records'] as $record ) {
		if ( is_array( $record ) && isset( $record['ListingKey'] ) ) {
			$by_key[ (string) $record['ListingKey'] ] = $record;
		}
	}

	$ordered = array();
	foreach ( $keys as $key ) {
		if ( isset( $by_key[ $key ] ) ) {
			$ordered[] = $by_key[ $key ];
		}
	}
	return $ordered;
}

/**
 * The endpoint live requests hit. For Bridge, the configured OData URL is
 * rewritten to the native listings API (…/OData/{ds}/Property →
 * …/{ds}/listings): the native surface returns Media inline where OData
 * hides it for client tokens (verified vs Stellar 2026-07-03).
 *
 * @param array $config Per-MLS config.
 * @return string
 */
function mlsimport_live_endpoint( array $config ): string {
	$type     = isset( $config['type'] ) ? (string) $config['type'] : '';
	$provider = Mlsimport_Provider_Family::adapter( $type, $config['mls_id'] ?? 0 );
	return $provider->direct_endpoint( $config );
}

/**
 * One direct HTTP GET against the MLS import URL.
 *
 * @param string $query OData query string beginning with '?'.
 * @return array{records:array,total:int}|null Null on transport/auth/parse failure.
 */
function mlsimport_live_request( string $query ) {
	$config  = mlsimport_live_config();
	$type    = isset( $config['type'] ) ? (string) $config['type'] : '';
	$provider = Mlsimport_Provider_Family::adapter( $type, $config['mls_id'] ?? 0 );
	$headers = mlsimport_live_auth_headers();
	if ( array() === $config || ! $provider->supports_direct_access() || array() === $headers ) {
		return null;
	}

	$base = $provider->direct_endpoint( $config );
	// The base URL may already carry a query part; keep exactly one '?'.
	$url = false === strpos( $base, '?' )
		? $base . $query
		: $base . '&' . ltrim( $query, '?&' );

	// OData filters carry spaces and quotes; encode the ones URLs can't.
	$url = str_replace( array( ' ', "'" ), array( '%20', '%27' ), $url );

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 20,
			'headers' => $headers,
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( 'MLSImport Direct MLS request failed: request_failed' );
		return null;
	}

	$outcome = $provider->read_direct_response(
		(int) wp_remote_retrieve_response_code( $response ),
		(string) wp_remote_retrieve_body( $response )
	);
	if ( empty( $outcome['success'] ) ) {
		$code = isset( $outcome['error']['code'] ) ? $outcome['error']['code'] : 'request_failed';
		error_log( 'MLSImport Direct MLS request failed: ' . $code );
		return null;
	}

	$outcome = $provider->enrich_direct_media( $outcome, $config, $headers );
	if ( ! empty( $outcome['warning']['code'] ) ) {
		error_log( 'MLSImport Direct MLS warning: ' . $outcome['warning']['code'] );
	}

	return array(
		'records' => $outcome['records'],
		'total'   => $outcome['total'],
	);
}
