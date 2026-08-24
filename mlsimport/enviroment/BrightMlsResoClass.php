<?php
/**
 * Bright MLS Provider Family adapter.
 *
 * The adapter is the single WordPress-side home for Bright MLS credentials,
 * Stored-mode request changes, and Direct MLS behavior.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider adapter for the Bright MLS API family.
 */
class BrightMlsResoClass extends ResoBase {
	/** @var string[] Bright MLS Okta client credentials. */
	protected $provider_credential_fields = array(
		'mlsimport_brightmls_client_id',
		'mlsimport_brightmls_client_secret',
	);

	/** @var object|null Active theme importer used by Stored mode. */
	public $theme_importer;

	/**
	 * Keep the active theme importer for listing writes.
	 *
	 * @param object|null $theme_importer Active theme importer, or null in pure tests.
	 */
	public function __construct( $theme_importer = null ) {
		$this->theme_importer = $theme_importer;
	}

	/**
	 * Return the saved provider type.
	 *
	 * @return string
	 */
	public function type() {
		return 'brightmls';
	}

	/** Format Bright MLS sync times with required seconds and UTC suffix. */
	protected function format_stored_timestamp( $value ) {
		return $this->format_utc_timestamp( $value, false, true );
	}

	/** Return Bright MLS query rules: JSON format, no expand, and safe page size. */
	protected function direct_query_rules() {
		return array(
			'format_json'   => true,
			'allow_expand'  => false,
			'default_limit' => 25,
		);
	}

	/** Build Bright MLS's Okta client-credentials token request. */
	protected function direct_token_request( array $saved_options, array $config ) {
		return array(
			'url'  => 'https://brightmls.okta.com/oauth2/default/v1/token',
			'body' => array(
				'client_id'     => trim( (string) $saved_options['mlsimport_brightmls_client_id'] ),
				'client_secret' => trim( (string) $saved_options['mlsimport_brightmls_client_secret'] ),
				'grant_type'    => 'client_credentials',
			),
			'json' => false,
		);
	}

	/**
	 * Merge the separate BrightMedia response into a listings outcome.
	 *
	 * Listing failures pass through unchanged. A media error adds a warning but
	 * keeps the successful records. Successful rows are grouped by listing key
	 * inside this adapter and attached as Media[].
	 *
	 * @param array       $outcome    Successful or failed listings outcome.
	 * @param array       $media_rows Decoded BrightMedia value rows.
	 * @param string|null $media_error Safe media failure detail, or null.
	 * @return array Provider Request Outcome with media or warning attached.
	 */
	public function merge_direct_media( array $outcome, array $media_rows, $media_error = null ) {
		if ( empty( $outcome['success'] ) ) {
			return $outcome;
		}

		if ( null !== $media_error && '' !== trim( (string) $media_error ) ) {
			$outcome['warning'] = array(
				'code'    => 'media_failed',
				'message' => 'Listings loaded, but Bright MLS photos could not be loaded.',
			);
			return $outcome;
		}

		$media_by_key = $this->direct_media_map( $media_rows );
		foreach ( $outcome['records'] as $index => $record ) {
			$key = is_array( $record ) && isset( $record['ListingKey'] )
				? (string) $record['ListingKey']
				: '';
			if ( '' !== $key && isset( $media_by_key[ $key ] ) ) {
				$outcome['records'][ $index ]['Media'] = $media_by_key[ $key ];
			}
		}

		return $outcome;
	}

	/**
	 * Fetch BrightMedia in 100-key groups and merge it into successful listings.
	 *
	 * The optional callable receives ($url, $args), allowing saved fake responses
	 * in tests. Any failed chunk becomes one safe warning; successful listing data
	 * and media from other chunks remain available.
	 *
	 * @param array         $outcome  Successful listings outcome.
	 * @param array         $config   Per-MLS config, optionally api_media_url.
	 * @param array         $headers  Bearer headers from the listings request.
	 * @param callable|null $http_get Optional fake HTTP getter.
	 * @return array Provider Request Outcome.
	 */
	public function enrich_direct_media( array $outcome, array $config, array $headers, $http_get = null ) {
		if ( empty( $outcome['success'] ) || empty( $outcome['records'] ) ) {
			return $outcome;
		}

		$keys = array();
		foreach ( $outcome['records'] as $record ) {
			if ( is_array( $record ) && empty( $record['Media'] ) && isset( $record['ListingKey'] ) ) {
				$keys[] = (string) $record['ListingKey'];
			}
		}
		if ( array() === $keys ) {
			return $outcome;
		}

		$base = isset( $config['api_media_url'] ) ? trim( (string) $config['api_media_url'] ) : '';
		if ( '' === $base ) {
			$base = 'https://bright-reso.brightmls.com/RESO/OData/bright/BrightMedia';
		}
		$base      = rtrim( $base, '/' );
		$rows      = array();
		$had_error = false;
		$getter    = is_callable( $http_get ) ? $http_get : 'wp_remote_get';

		foreach ( array_chunk( $keys, 100 ) as $chunk ) {
			$url      = $base . $this->direct_media_query( $chunk );
			$url      = str_replace( array( ' ', "'" ), array( '%20', '%27' ), $url );
			$response = call_user_func(
				$getter,
				$url,
				array(
					'timeout' => 20,
					'headers' => $headers,
				)
			);
			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_wp_error( $response ) || 200 !== $code || ! is_array( $data ) || ! is_array( $data['value'] ?? null ) ) {
				$had_error = true;
				continue;
			}
			$rows = array_merge( $rows, $data['value'] );
		}

		return $this->merge_direct_media(
			$outcome,
			$rows,
			$had_error ? 'media request failed' : null
		);
	}

	/** Build BrightMedia's separate query for one chunk of listing keys. */
	private function direct_media_query( array $keys ) {
		$select = 'MediaKey,ResourceRecordKey,MediaCategory,MediaType,'
			. 'MediaDisplayOrder,PreferredPhotoYN,MediaURL,MediaURLHiRes,'
			. 'MediaModificationTimestamp';

		return '?$filter=ResourceRecordKey in (' . implode( ',', array_map( 'strval', $keys ) ) . ')'
			. '&$orderby=ResourceRecordKey,MediaDisplayOrder'
			. '&$select=' . $select
			. '&$format=json';
	}

	/** Group BrightMedia rows into standard RESO Media arrays by listing key. */
	private function direct_media_map( array $rows ) {
		$map = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['ResourceRecordKey'] ) ) {
				continue;
			}
			$url = ! empty( $row['MediaURLHiRes'] ) ? $row['MediaURLHiRes'] : ( $row['MediaURL'] ?? '' );
			if ( '' === (string) $url ) {
				continue;
			}
			$map[ (string) $row['ResourceRecordKey'] ][] = array(
				'MediaURL'      => (string) $url,
				'Order'         => $row['MediaDisplayOrder'] ?? null,
				'MediaCategory' => $row['MediaCategory'] ?? null,
				'MediaType'     => $row['MediaType'] ?? null,
			);
		}
		return $map;
	}
}
