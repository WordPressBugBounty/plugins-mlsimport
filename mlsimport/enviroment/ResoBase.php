<?php
/**
 * ResoBase — base class for the RESO-standard MLS provider adapters.
 *
 * Provider adapters in the enviroment/ directory extend this class. It defines
 * only the public behavior that is identical for every supported Provider
 * Family. Provider-specific credentials and request rules stay in subclasses.
 *
 * @package MLSImport
 */
// Abort if the file is accessed directly outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of ResoBase
 *
 * @author cretu
 */
class ResoBase {

	/** @var string Stable provider type stored with the MLS configuration. */
	protected $provider_type = '';

	/** @var string[] Saved option keys required to authenticate this provider. */
	protected $provider_credential_fields = array( 'mlsimport_mls_token' );

	/** @var array<string,string> Saved option key to SaaS request key aliases. */
	protected $connection_payload_keys = array(
		'mlsimport_mls_token' => 'mls_token',
	);

	/** @var bool Whether this provider's Direct MLS request is implemented. */
	protected $direct_access_supported = true;

	/** @var bool Whether Direct MLS sends the saved provider token unchanged. */
	protected $direct_uses_stored_token = false;

	/** @var object|null Active theme importer used by Stored mode. */
	public $theme_importer;

	/**
	 * Keep the active theme importer for callers that save Stored-mode listings.
	 *
	 * @param object|null $theme_importer Active theme importer, or null in pure tests.
	 */
	public function __construct( $theme_importer = null ) {
		$this->theme_importer = $theme_importer;
	}

	/**
	 * Return the stable saved type owned by this adapter.
	 *
	 * @return string
	 */
	public function type() {
		return $this->provider_type;
	}

	/**
	 * Real provider adapters are usable. UnsupportedResoClass overrides this.
	 *
	 * @return bool
	 */
	public function supported() {
		return true;
	}

	/**
	 * Return the exact saved option keys required by this Provider Family.
	 *
	 * The settings screen and connection test both consume this list, preventing
	 * either caller from maintaining a second provider credential map.
	 *
	 * @return string[] Ordered credential option keys.
	 */
	public function credential_fields() {
		return $this->provider_credential_fields;
	}

	/**
	 * Build the safe payload sent to the SaaS connection-test endpoint.
	 *
	 * Step 1 adds the selected MLS ID. Step 2 reads only the credential keys
	 * declared by this adapter. Step 3 trims their values and reports any blank
	 * required keys. No inactive provider credential can enter the payload.
	 *
	 * @param array      $saved_options Saved plugin settings for every provider.
	 * @param int|string $mls_id        Selected numeric MLS identifier.
	 * @return array{success:bool,payload:array,missing:array,error:?array}
	 */
	public function connection_test_payload( array $saved_options, $mls_id ) {
		$payload = array( 'mls_id' => trim( (string) $mls_id ) );
		$missing = array();

		// Copy only this adapter's declared credentials into the outgoing request.
		foreach ( $this->credential_fields() as $field ) {
			$value = isset( $saved_options[ $field ] )
				? trim( (string) $saved_options[ $field ] )
				: '';

			if ( '' === $value ) {
				$missing[] = $field;
				continue;
			}

			$request_key             = isset( $this->connection_payload_keys[ $field ] )
				? $this->connection_payload_keys[ $field ]
				: $field;
			$payload[ $request_key ] = $value;
		}

		return array(
			'success' => array() === $missing,
			'payload' => $payload,
			'missing' => $missing,
			'error'   => array() === $missing
				? null
				: array(
					'code'    => 'missing_credentials',
					'message' => 'Required MLS provider credentials are missing.',
				),
		);
	}

	/**
	 * Apply this provider's WordPress-side rules to a Stored-mode request.
	 *
	 * Shared request fields arrive fully assembled by the Import Task caller.
	 * This adapter adds only the provider-specific timestamp here; adapters with
	 * further requirements override this method and then return the same outcome.
	 *
	 * @param array  $arguments Shared Stored-mode request arguments.
	 * @param string $last_date Last Successful Sync Time, or an empty string.
	 * @return array{success:bool,arguments:array,error:?array}
	 */
	public function prepare_stored_request( array $arguments, $last_date = '' ) {
		if ( '' !== trim( (string) $last_date ) ) {
			$arguments['modification_time'] = $this->format_stored_timestamp( $last_date );
		}

		return array(
			'success'   => true,
			'arguments' => $arguments,
			'error'     => null,
		);
	}

	/**
	 * Prepare the Import Task field catalog for this Provider Family.
	 *
	 * Most providers use the shared fields unchanged. An adapter overrides this
	 * method only when its MLS API requires a different input shape.
	 *
	 * @param array $fields Import Task fields keyed by RESO field name.
	 * @return array Provider-ready Import Task fields.
	 */
	public function prepare_import_task_fields( array $fields ) {
		return $fields;
	}

	/**
	 * Keep timestamps unchanged for providers with no WordPress-side rule.
	 *
	 * @param string $value Saved sync timestamp.
	 * @return string
	 */
	protected function format_stored_timestamp( $value ) {
		return trim( (string) $value );
	}

	/**
	 * Convert a saved timestamp to one exact UTC shape.
	 *
	 * @param string $value        Saved sync timestamp.
	 * @param bool   $milliseconds Whether to include `.000`.
	 * @param bool   $utc_suffix   Whether to append the UTC `Z` suffix.
	 * @return string Formatted timestamp, or the trimmed input when invalid.
	 */
	protected function format_utc_timestamp( $value, $milliseconds, $utc_suffix ) {
		$clean = trim( (string) $value );

		try {
			$date = new DateTimeImmutable( $clean, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $exception ) {
			return $clean;
		}

		$format = 'Y-m-d\TH:i:s';
		if ( $milliseconds ) {
			$format .= '.000';
		}
		if ( $utc_suffix ) {
			$format .= '\Z';
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format );
	}

	/**
	 * Supported adapters have no provider-selection error.
	 *
	 * @return null
	 */
	public function error() {
		return null;
	}

	/**
	 * Report whether this adapter can execute Direct MLS requests today.
	 *
	 * @return bool
	 */
	public function supports_direct_access() {
		return $this->direct_access_supported;
	}

	/**
	 * Build this provider's Direct MLS query through the shared OData encoder.
	 *
	 * The encoder knows filter syntax only. The selected adapter supplies every
	 * provider decision in direct_query_rules(), so the helper never switches on
	 * provider type.
	 *
	 * @param array $params Standalone filter and paging values.
	 * @param array $config Per-MLS URLs, expand value, and field_corellation map.
	 * @return string Query beginning with `?`, or empty when Direct MLS is unsupported.
	 */
	public function build_direct_query( array $params, array $config ) {
		if ( ! $this->supports_direct_access() ) {
			return '';
		}

		return mlsimport_live_build_query_odata( $params, $config, $this->direct_query_rules() );
	}

	/**
	 * Build the OData query for one exact ListingKey.
	 *
	 * @param string $listing_key RESO ListingKey; never cast to an integer.
	 * @param array  $config      Per-MLS config including expand/field_corellation.
	 * @return string Query beginning with `?`.
	 */
	public function build_direct_get_query( $listing_key, array $config ) {
		$query = '?';
		if ( ! empty( $config['expand'] ) ) {
			$query .= '&$expand=' . $config['expand'];
		}
		$query .= '&$top=1&$filter=' . mlsimport_live_filter_list_segment(
			mlsimport_live_field_alias( 'ListingKey', $config ),
			array( (string) $listing_key )
		);

		return (string) preg_replace( '/ and $/', '', $query );
	}

	/**
	 * Return OData choices that are truly identical for this provider.
	 *
	 * @return array<string,bool|int|string>
	 */
	protected function direct_query_rules() {
		return array();
	}

	/**
	 * Build the provider-owned Direct MLS authentication plan.
	 *
	 * The plan contains no inactive credentials. Token providers return their
	 * saved bearer. Expiring-token providers return the exact POST description
	 * their adapter owns; the WordPress HTTP caller only executes the plan.
	 *
	 * @param array $saved_options Saved plugin credential options.
	 * @param array $config        Per-MLS config, including provider token URL.
	 * @return array Authentication plan or stable failure result.
	 */
	public function direct_auth_plan( array $saved_options, array $config ) {
		if ( ! $this->supports_direct_access() ) {
			$result         = $this->direct_unsupported_result();
			$result['mode'] = null;
			return $result;
		}

		$missing = array();
		foreach ( $this->credential_fields() as $field ) {
			if ( ! isset( $saved_options[ $field ] ) || '' === trim( (string) $saved_options[ $field ] ) ) {
				$missing[] = $field;
			}
		}
		if ( array() !== $missing ) {
			return array(
				'success' => false,
				'mode'    => null,
				'missing' => $missing,
				'error'   => array(
					'code'    => 'missing_credentials',
					'message' => 'Required MLS provider credentials are missing.',
				),
			);
		}

		if ( $this->direct_uses_stored_token ) {
			return array(
				'success' => true,
				'mode'    => 'stored_token',
				'token'   => trim( (string) $saved_options['mlsimport_mls_token'] ),
				'error'   => null,
			);
		}

		$request = $this->direct_token_request( $saved_options, $config );
		if ( null === $request ) {
			return array(
				'success' => false,
				'mode'    => null,
				'error'   => array(
					'code'    => 'login_configuration_missing',
					'message' => 'The MLS login configuration is incomplete.',
				),
			);
		}

		return array(
			'success' => true,
			'mode'    => 'token_request',
			'request' => $request,
			'error'   => null,
		);
	}

	/**
	 * Return an expiring-token request, or null for token-as-is providers.
	 *
	 * @param array $saved_options Active provider credentials.
	 * @param array $config        Per-MLS configuration.
	 * @return array|null
	 */
	protected function direct_token_request( array $saved_options, array $config ) {
		return null;
	}

	/**
	 * Return the standard result for a provider not yet ported to Direct MLS.
	 *
	 * @return array{success:bool,records:array,total:int,error:array}
	 */
	public function direct_unsupported_result() {
		return array(
			'success' => false,
			'records' => array(),
			'total'   => 0,
			'error'   => array(
				'code'    => 'unsupported_direct_provider',
				'message' => 'Direct MLS access is not supported for this provider.',
			),
		);
	}

	/**
	 * Read one Direct MLS HTTP response into a Provider Request Outcome.
	 *
	 * Status is classified before the body is parsed: authentication failures,
	 * rejected queries, and provider/server failures receive distinct safe codes.
	 * A valid envelope with zero records remains a successful outcome.
	 *
	 * @param int    $status_code HTTP response status.
	 * @param string $body        Raw provider response body.
	 * @return array{success:bool,records:array,total:int,error:?array,warning:?array}
	 */
	public function read_direct_response( $status_code, $body ) {
		$status_code = (int) $status_code;
		if ( 401 === $status_code || 403 === $status_code ) {
			return $this->direct_failure( 'login_failure', 'The MLS rejected the saved credentials.' );
		}
		if ( $status_code >= 400 && $status_code < 500 ) {
			return $this->direct_failure( 'rejected_query', 'The MLS rejected the listings query.' );
		}
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->direct_failure( 'request_failed', 'The MLS request could not be completed.' );
		}

		$parsed = mlsimport_live_parse_response( (string) $body, array() );
		if ( null === $parsed ) {
			return $this->direct_failure( 'invalid_response', 'The MLS returned an invalid listings response.' );
		}

		return array(
			'success' => true,
			'records' => $parsed['records'],
			'total'   => $parsed['total'],
			'error'   => null,
			'warning' => null,
		);
	}

	/**
	 * Return the Direct MLS listing endpoint for this provider.
	 *
	 * @param array $config Per-MLS configuration.
	 * @return string
	 */
	public function direct_endpoint( array $config ) {
		return isset( $config['api_import_url'] ) ? (string) $config['api_import_url'] : '';
	}

	/**
	 * Allow a provider to add separate media after listings succeed.
	 *
	 * Most providers return media with the listing response, so their default is
	 * a no-op. Bright MLS overrides this because it owns a second media request.
	 *
	 * @param array         $outcome  Successful Provider Request Outcome.
	 * @param array         $config   Per-MLS configuration.
	 * @param array         $headers  Authorization headers used for listings.
	 * @param callable|null $http_get Optional fake HTTP getter for tests.
	 * @return array Provider Request Outcome, possibly with media or a warning.
	 */
	public function enrich_direct_media( array $outcome, array $config, array $headers, $http_get = null ) {
		return $outcome;
	}

	/**
	 * Build one failed Provider Request Outcome without exposing provider data.
	 *
	 * @param string $code    Stable machine-readable failure code.
	 * @param string $message Short safe administrator message.
	 * @return array{success:bool,records:array,total:int,error:array,warning:null}
	 */
	protected function direct_failure( $code, $message ) {
		return array(
			'success' => false,
			'records' => array(),
			'total'   => 0,
			'error'   => array(
				'code'    => (string) $code,
				'message' => (string) $message,
			),
			'warning' => null,
		);
	}

}
