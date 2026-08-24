<?php
/**
 * Provider Family module and legacy compatibility helpers.
 *
 * Saved provider type is authoritative. Numeric MLS ranges remain only for old
 * configurations that do not yet have a saved type.
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
	// Coerce to int so numeric strings ("9001") compare by value.
	$mls_id_int = (int) $mls_id;
	// True only inside the 9000..9999 PropTx block (upper bound exclusive).
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
	// Empty marker passes through unchanged ("no incremental marker").
	if ( '' === $last_date ) {
		return '';
	}

	// Parse the stored value as UTC, then re-emit as a full DateTimeOffset (Z) literal.
	$date_time = new DateTime( $last_date, new DateTimeZone( 'UTC' ) );
	return $date_time->format( 'Y-m-d\TH:i:s.000\Z' );
}

/**
 * Public entry point for selecting one Provider Family adapter.
 *
 * Callers give this module the provider type saved with the MLS configuration
 * and the numeric MLS ID. The saved type is checked first so old numeric ranges
 * cannot override authoritative provider data.
 */
class Mlsimport_Provider_Family {

	/**
	 * Return the adapter selected by saved provider type.
	 *
	 * @param string      $saved_type    Provider type saved with the MLS configuration.
	 * @param int|string  $mls_id        Numeric MLS identifier used only as fallback.
	 * @param object|null $theme_importer Active theme importer when WordPress supplies one.
	 * @return ResoBase Selected provider adapter.
	 */
	public static function adapter( $saved_type, $mls_id, $theme_importer = null ) {
		// Normalize the saved value once before choosing the provider class.
		$type = strtolower( trim( (string) $saved_type ) );
		$id   = (int) $mls_id;

		// Only a missing saved type may use the old numeric-ID compatibility map.
		if ( '' === $type ) {
			$type = self::type_from_mls_id( $id );
		}

		// The only provider-name map lives at this public module boundary. Each
		// mapped class owns its own behavior; callers never branch on these names.
		$classes = array(
			'bridge'          => 'BridgeResoClass',
			'spark'           => 'SparkResoClass',
			'trestle'         => 'TresleResoClass',
			'mlsgrid'         => 'MlsgridResoClass',
			'rmls'            => 'RmlsResoClass',
			'utah_real_estate'=> 'UtahRealEstateResoClass',
			'realcomp'        => 'RealcompResoClass',
			'realtorca'       => 'RealtorCaResoClass',
			'brightmls'       => 'BrightMlsResoClass',
			'rapattoni'       => 'RapattoniResoClass',
			'paragon'         => 'ParagonResoClass',
			'connectmls'      => 'ConnectMlsResoClass',
			'proptx'          => 'ProptxResoClass',
			'centris'         => 'CentrisResoClass',
		);

		// A present recognized type is authoritative, regardless of numeric ID.
		if ( isset( $classes[ $type ] ) ) {
			$class_name = $classes[ $type ];
			return new $class_name( $theme_importer );
		}

		// Never turn a present but unknown type into Bridge. Return a normal
		// adapter-shaped error so admin and request callers handle it identically.
		return new UnsupportedResoClass( $type );
	}

	/**
	 * Return the saved provider type only when it belongs to the selected MLS.
	 *
	 * Keeping the MLS ID beside the type prevents a provider saved for the prior
	 * selection from winning during the first request after an MLS change.
	 *
	 * @param int|string $mls_id Currently selected MLS identifier.
	 * @return string Saved provider type, or empty when absent/stale.
	 */
	public static function saved_type( $mls_id ) {
		$saved_mls_id = (string) get_option( 'mlsimport_provider_type_mls_id', '' );
		if ( '' === $saved_mls_id || $saved_mls_id !== (string) $mls_id ) {
			return '';
		}

		return strtolower( trim( (string) get_option( 'mlsimport_provider_type', '' ) ) );
	}

	/**
	 * Save the authoritative provider type returned for one MLS configuration.
	 *
	 * @param string     $type   Provider type returned by the SaaS configuration.
	 * @param int|string $mls_id MLS identifier that owns the type.
	 * @return void
	 */
	public static function remember_type( $type, $mls_id ) {
		$clean_type = strtolower( trim( (string) $type ) );
		if ( '' === $clean_type || '' === trim( (string) $mls_id ) ) {
			return;
		}

		update_option( 'mlsimport_provider_type', $clean_type );
		update_option( 'mlsimport_provider_type_mls_id', (string) $mls_id );
	}

	/**
	 * Clear state that belongs only to the previously selected MLS.
	 *
	 * Saved credential fields are deliberately untouched so switching back to a
	 * prior provider restores its inputs. Active type, config, connection flag,
	 * metadata, and cached access tokens are removed immediately.
	 *
	 * @return void
	 */
	public static function clear_active_state() {
		delete_option( 'mlsimport_provider_type' );
		delete_option( 'mlsimport_provider_type_mls_id' );
		delete_option( 'mlsimport_live_mls_config' );
		delete_option( 'mlsimport_connection_test' );
		delete_option( 'mlsimport_mls_metadata_populated' );

		self::clear_access_tokens();
	}

	/**
	 * Clear cached SaaS and Direct MLS access tokens after credentials change.
	 *
	 * @return void
	 */
	public static function clear_access_tokens() {
		delete_transient( 'mlsimport_saas_token' );
		self::clear_direct_access_tokens();
	}

	/**
	 * Clear every expiring token owned by a Direct MLS provider adapter.
	 *
	 * @return void
	 */
	public static function clear_direct_access_tokens() {
		foreach ( array( 'trestle', 'realcomp', 'realtorca', 'brightmls', 'rapattoni' ) as $type ) {
			delete_transient( 'mlsimport_live_token_' . $type );
		}
	}

	/**
	 * Build the provider configuration handed to the plain admin JavaScript.
	 *
	 * Each listed MLS ID is resolved through the same adapter method used by PHP
	 * requests. JavaScript receives final field names and only shows/hides them;
	 * it never interprets provider ranges or names.
	 *
	 * @param array      $mls_ids            MLS IDs present in the autocomplete list.
	 * @param string     $saved_type         Authoritative type for the current MLS.
	 * @param int|string $saved_type_mls_id  MLS ID that owns the saved type.
	 * @return array{by_mls_id:array,all_credential_fields:array}
	 */
	public static function browser_config_for_ids( array $mls_ids, $saved_type = '', $saved_type_mls_id = '' ) {
		$by_mls_id = array();
		foreach ( $mls_ids as $mls_id ) {
			$id       = (string) $mls_id;
			$type     = $id === (string) $saved_type_mls_id ? $saved_type : '';
			$provider = self::adapter( $type, $id );
			$by_mls_id[ $id ] = array(
				'type'              => $provider->type(),
				'credential_fields' => $provider->credential_fields(),
			);
		}

		// Collect the form's full credential catalog from real adapters, not a UI map.
		$all_fields = array();
		foreach ( self::supported_types() as $type ) {
			$all_fields = array_merge( $all_fields, self::adapter( $type, 0 )->credential_fields() );
		}

		return array(
			'by_mls_id'            => $by_mls_id,
			'all_credential_fields' => array_values( array_unique( $all_fields ) ),
		);
	}

	/**
	 * Return every recognized saved provider type.
	 *
	 * @return string[]
	 */
	private static function supported_types() {
		return array(
			'bridge',
			'spark',
			'trestle',
			'mlsgrid',
			'rmls',
			'utah_real_estate',
			'realcomp',
			'realtorca',
			'brightmls',
			'rapattoni',
			'paragon',
			'connectmls',
			'proptx',
			'centris',
		);
	}

	/**
	 * Translate the historic numeric MLS ranges into a provider type.
	 *
	 * This method exists only for older saved configurations that have no type.
	 * New saved types always win before this compatibility method is called.
	 *
	 * @param int $mls_id Numeric MLS identifier.
	 * @return string Provider type used by the legacy range map.
	 */
	private static function type_from_mls_id( $mls_id ) {
		// Bright MLS is the reserved 8001 ID inside the ConnectMLS block.
		if ( 8001 === $mls_id ) {
			return 'brightmls';
		}

		// Centris is the reserved 9001 ID inside the PropTx block. Centris is a
		// different provider with a different feed, so the surrounding range
		// must never resolve it to PropTx when no type has been saved yet.
		if ( 9001 === $mls_id ) {
			return 'centris';
		}

		if ( $mls_id >= 9000 && $mls_id < 10000 ) {
			return 'proptx';
		}
		if ( $mls_id >= 8000 && $mls_id < 9000 ) {
			return 'connectmls';
		}
		if ( $mls_id >= 7000 && $mls_id < 8000 ) {
			return 'realtorca';
		}
		if ( $mls_id >= 6000 && $mls_id < 7000 ) {
			return 'paragon';
		}
		if ( $mls_id >= 5000 && $mls_id < 6000 ) {
			return 'rapattoni';
		}
		if ( $mls_id > 900 && $mls_id < 3000 ) {
			return 'trestle';
		}

		// The old code treated all remaining IDs as token-based Bridge.
		return 'bridge';
	}
}
