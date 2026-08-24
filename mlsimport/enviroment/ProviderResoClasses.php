<?php
/**
 * Provider Family adapters that did not previously have WordPress classes.
 *
 * Each class is intentionally separate even where the first implementation is
 * small. Provider credentials and request behavior are added to the matching
 * class, so a later difference never becomes another provider-name switch in a
 * caller. Existing Bridge, Spark, Trestle, MLS Grid, and Bright classes remain
 * in their original files.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provider adapter for Regional Multiple Listing Service (RMLS). */
class RmlsResoClass extends ResoBase {
	/** @var string Stable provider type. */
	protected $provider_type = 'rmls';

	/** @var bool RMLS sends its saved bearer token unchanged. */
	protected $direct_uses_stored_token = true;

	/** Format RMLS sync times as full OData DateTimeOffset literals. */
	protected function format_stored_timestamp( $value ) {
		return $this->format_utc_timestamp( $value, true, true );
	}

	/** Return RMLS's required default and maximum page size. */
	protected function direct_query_rules() {
		return array(
			'default_limit' => 25,
			'max_limit'     => 25,
		);
	}
}

/** Provider adapter for Utah Real Estate. */
class UtahRealEstateResoClass extends ResoBase {
	/** @var string Stable provider type. */
	protected $provider_type = 'utah_real_estate';

	/** @var bool Utah Real Estate sends its saved bearer token unchanged. */
	protected $direct_uses_stored_token = true;
}

/** Provider adapter for Realcomp. */
class RealcompResoClass extends ResoBase {
	/** @var string Stable provider type. */
	protected $provider_type = 'realcomp';

	/** @var string[] Realcomp currently uses the saved Trestle credential slots. */
	protected $provider_credential_fields = array(
		'mlsimport_tresle_client_id',
		'mlsimport_tresle_client_secret',
	);

	/** Format Realcomp sync times with required seconds and UTC suffix. */
	protected function format_stored_timestamp( $value ) {
		return $this->format_utc_timestamp( $value, false, true );
	}

	/** Return Realcomp's required default page size. */
	protected function direct_query_rules() {
		return array( 'default_limit' => 25 );
	}

	/** Build Realcomp's JSON token request. */
	protected function direct_token_request( array $saved_options, array $config ) {
		return array(
			'url'  => 'https://auth.realcomp.com/Token',
			'body' => array(
				'client_id'     => trim( (string) $saved_options['mlsimport_tresle_client_id'] ),
				'client_secret' => trim( (string) $saved_options['mlsimport_tresle_client_secret'] ),
				'audience'      => 'rcapi.realcomp.com',
			),
			'json' => true,
		);
	}
}

/** Provider adapter for Realtor.ca / CREA DDF. */
class RealtorCaResoClass extends ResoBase {
	/** @var string Stable provider type. */
	protected $provider_type = 'realtorca';

	/** @var string[] CREA DDF client credentials. */
	protected $provider_credential_fields = array(
		'mlsimport_realtorca_client_id',
		'mlsimport_realtorca_client_secret',
	);

	/** Format CREA DDF sync times as full UTC DateTimeOffset literals. */
	protected function format_stored_timestamp( $value ) {
		return $this->format_utc_timestamp( $value, true, true );
	}

	/** Remove PropertyType because CREA DDF does not expose that task filter. */
	public function prepare_import_task_fields( array $fields ) {
		unset( $fields['PropertyType'] );
		return $fields;
	}

	/** Build CREA DDF's client-credentials token request. */
	protected function direct_token_request( array $saved_options, array $config ) {
		return array(
			'url'  => 'https://identity.crea.ca/connect/token',
			'body' => array(
				'client_id'     => trim( (string) $saved_options['mlsimport_realtorca_client_id'] ),
				'client_secret' => trim( (string) $saved_options['mlsimport_realtorca_client_secret'] ),
				'grant_type'    => 'client_credentials',
				'scope'         => 'DDFApi_Read',
			),
			'json' => false,
		);
	}
}

/** Provider adapter for Rapattoni. */
class RapattoniResoClass extends ResoBase {
	/** @var string Stable provider type. */
	protected $provider_type = 'rapattoni';

	/** @var string[] Rapattoni password-grant credentials. */
	protected $provider_credential_fields = array(
		'mlsimport_rapattoni_client_id',
		'mlsimport_rapattoni_client_secret',
		'mlsimport_rapattoni_username',
		'mlsimport_rapattoni_password',
	);

	/** Make PropertyType single-select because Rapattoni requires one Class. */
	public function prepare_import_task_fields( array $fields ) {
		if ( isset( $fields['PropertyType'] ) ) {
			$fields['PropertyType']['multiple'] = 'no';
		}
		return $fields;
	}

	/**
	 * Require and normalize Rapattoni's single PropertyType request value.
	 *
	 * @param array  $arguments Shared Stored-mode request arguments.
	 * @param string $last_date Last Successful Sync Time, or empty.
	 * @return array{success:bool,arguments:array,error:?array}
	 */
	public function prepare_stored_request( array $arguments, $last_date = '' ) {
		// The existing UI can supply either a scalar or a one-item selection array.
		$property_type = isset( $arguments['property_type'] )
			? $arguments['property_type']
			: '';
		if ( is_array( $property_type ) ) {
			$property_type = isset( $property_type[0] ) ? $property_type[0] : '';
		}
		$property_type = trim( (string) $property_type );

		// Stop before the SaaS call when Rapattoni's required class is absent.
		if ( '' === $property_type ) {
			return array(
				'success'   => false,
				'arguments' => $arguments,
				'error'     => array(
					'code'    => 'missing_property_type',
					'message' => 'This MLS requires one Property Action Category.',
				),
			);
		}

		// Rapattoni expects one value with spaces removed inside a one-item list.
		$arguments['property_type'] = array( str_replace( ' ', '', $property_type ) );
		return parent::prepare_stored_request( $arguments, $last_date );
	}

	/** Normalize Rapattoni's PropertyPictures collection to standard Media. */
	public function read_direct_response( $status_code, $body ) {
		$outcome = parent::read_direct_response( $status_code, $body );
		if ( empty( $outcome['success'] ) ) {
			return $outcome;
		}

		foreach ( $outcome['records'] as $index => $record ) {
			if ( is_array( $record ) && empty( $record['Media'] ) && ! empty( $record['PropertyPictures'] ) ) {
				$outcome['records'][ $index ]['Media'] = $record['PropertyPictures'];
			}
		}
		return $outcome;
	}

	/** Return Rapattoni's Class parameter and numeric listing-key ordering rules. */
	protected function direct_query_rules() {
		return array(
			'skip_listing_type_filter' => true,
			'class_parameter'          => true,
			'default_orderby'          => 'ListingKeyNumeric',
		);
	}

	/** Build Rapattoni's password-grant token request from its MLS token URL. */
	protected function direct_token_request( array $saved_options, array $config ) {
		$token_url = isset( $config['api_token_url'] ) ? trim( (string) $config['api_token_url'] ) : '';
		if ( '' === $token_url ) {
			return null;
		}

		return array(
			'url'  => $token_url,
			'body' => array(
				'client_id'     => trim( (string) $saved_options['mlsimport_rapattoni_client_id'] ),
				'client_secret' => trim( (string) $saved_options['mlsimport_rapattoni_client_secret'] ),
				'username'      => trim( (string) $saved_options['mlsimport_rapattoni_username'] ),
				'password'      => trim( (string) $saved_options['mlsimport_rapattoni_password'] ),
				'grant_type'    => 'password',
			),
			'json' => false,
		);
	}
}

/** Provider adapter for Paragon. */
class ParagonResoClass extends ResoBase {
	/** @var string Stable provider type. */
	protected $provider_type = 'paragon';

	/** @var bool Paragon Direct MLS access is not currently implemented. */
	protected $direct_access_supported = false;

	/** @var string[] Paragon client credentials used by Stored mode. */
	protected $provider_credential_fields = array(
		'mlsimport_paragon_client_id',
		'mlsimport_paragon_client_secret',
	);
}

/** Provider adapter for ConnectMLS. */
class ConnectMlsResoClass extends ResoBase {
	/** @var string Stable provider type. */
	protected $provider_type = 'connectmls';

	/** @var bool ConnectMLS Direct MLS access is not currently implemented. */
	protected $direct_access_supported = false;

	/** @var string[] ConnectMLS account credentials. */
	protected $provider_credential_fields = array(
		'mlsimport_connectmls_username',
		'mlsimport_connectmls_password',
	);
}

/** Provider adapter for PropTx / AMPRE. */
class ProptxResoClass extends ResoBase {
	/** @var string Stable provider type. */
	protected $provider_type = 'proptx';

	/** @var bool PropTx Direct MLS access is not currently implemented. */
	protected $direct_access_supported = false;

	/** Format PropTx sync times as full OData DateTimeOffset literals. */
	protected function format_stored_timestamp( $value ) {
		return $this->format_utc_timestamp( $value, true, true );
	}
}
