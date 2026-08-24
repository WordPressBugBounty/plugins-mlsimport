<?php 
/**
 * ThemeImport — SaaS API client and Stored Listing Write compatibility edge.
 *
 * The class retains the SaaS request helpers, batch compatibility entry points,
 * and reconciliation utilities used by older callers. Per-listing Stored mode
 * persistence is deliberately narrow: mlsimportSaasPrepareToImportPerItem()
 * translates legacy task option names and delegates once to the injected
 * Mlsimport_Stored_Listing_Write module.
 *
 * Listing status, post/meta/taxonomy writes, field normalization, title, media,
 * activity, and error outcomes no longer live in this compatibility class.
 *
 * @package MLSImport
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Expose legacy API/batch methods around the explicit listing-write module.
 */
class ThemeImport {


	// Active theme adapter / identifier (set by callers).
	public $theme;
	// Plugin slug/name carried for logging and context.
	public $plugin_name;
	// Environment adapter instance (theme-specific meta mapping).
	public $enviroment;
	// Cached encoded credential/config values.
	public $encoded_values;

	/** @var object|null Injected Stored Listing Write module. */
	private $stored_listing_write;

	/**
	 * Configure the API client and optional Stored mode write boundary.
	 *
	 * Most ThemeImport instances only call the SaaS API and therefore need no
	 * writer. Admin composition injects the writer once; listing calls then
	 * delegate without reading the global admin object or theme adapter.
	 *
	 * @param string      $plugin_name         Plugin slug used by legacy callers.
	 * @param object|null $stored_listing_write Single listing write module.
	 */
	public function __construct( $plugin_name = '', $stored_listing_write = null ) {
		$this->plugin_name         = (string) $plugin_name;
		$this->stored_listing_write = $stored_listing_write;
	}
	

	 /**
     * Api Request to MLSimport API using CURL
     *
     * @param string $method The API method to call.
     * @param array $values_array The values to pass to the API.
     * @param string $type The request type (default is 'GET').
     * @return mixed The API response or error message.
     */

	public function globalApiRequestCurlSaas($method, $valuesArray, $type = 'GET') {

		
		global $mlsimport;
	 	
		// Skip validation for token requests
		// (the token call is what mints the credential, so it can't require one).
		if ($method !== 'token') {
			// Ensure a live JWT before any non-token call; bail out with a message on failure.
			if (!self::validateAndRefreshToken()) {
				return 'Token validation failed';
			}
		}

		// Build the full endpoint URL from the SaaS base + method path.
		$url = MLSIMPORT_API_URL . $method;
		// Default headers for the token request (plain text body).
		$headers = ['Content-Type' => 'text/plain'];

		// For authenticated calls, swap to JSON + Bearer token headers.
		if ($method !== 'token') {
			$token = self::getApiToken();
			$headers = [
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer '.$token,
			];
		}

		// Assemble the wp_remote_* argument array (long timeout for large payloads).
		$args = [
			'method' => $type,
			'headers' => $headers,
			'body' => !empty($valuesArray) ? wp_json_encode($valuesArray) : null,
			'timeout' => 120,
			'redirection' => 10,
			'httpversion' => '1.1',
			'blocking' => true,
			'user-agent' => $_SERVER['HTTP_USER_AGENT'],
		];


		// Dispatch as GET or POST depending on $type.
		$response = $type === 'GET' ? wp_remote_get($url, $args) : wp_remote_post($url, $args);

		// #208 recovery: same rule as globalApiRequestSaas() — one refresh and
		// one retry when the server rejects the Bearer token mid-flight.
		if ( 'token' !== $method
			&& ! is_wp_error( $response )
			&& 401 === intval( $response['response']['code'] ?? 0 )
			&& self::refreshToken() ) {
			$args['headers']['Authorization'] = 'Bearer ' . self::getApiToken();
			$response = $type === 'GET' ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );
		}

		// Transport-level failure: return the WP_Error message string.
		if (is_wp_error($response)) {
        	return $response->get_error_message();
		} else {
			// Otherwise decode the JSON body and return the array (or a decode-error string).
			$body = wp_remote_retrieve_body($response);

			$toReturn = json_decode($body, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				return 'JSON decode error: ' . json_last_error_msg();
			}
			return $toReturn;
		}
	}

	
	/**
	 * Retrieve the API token
	 *
	 * @return string The API token.
	 */
	private static function getApiToken() {
		global $mlsimport;
		return $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
	}


	/**
	 * Api Request to MLSimport API
	 *
	 * @param string $method The API method to call.
	 * @param array $valuesArray The values to pass to the API.
	 * @param string $type The request type (default is 'GET').
	 * @return array The API response data.
	 */

	/**
	 * Fire-and-forget POST to the SaaS API. Refreshes the JWT token (blocking — a
	 * required separate request); returns false without sending if the token is
	 * unavailable. Otherwise issues wp_remote_post() with blocking=false, timeout=0.01
	 * and returns true. The response is never inspected.
	 *
	 * @param string $method     The API method/path to call.
	 * @param array  $valuesArray The request body data.
	 * @return bool True if dispatched, false if token unavailable.
	 */
	public static function globalApiRequestSaasFireAndForget( string $method, array $valuesArray ): bool {
		if ( ! self::validateAndRefreshToken() ) {
			return false;
		}

		$token = self::getApiToken();

		wp_remote_post(
			MLSIMPORT_API_URL . $method,
			[
				'method'   => 'POST',
				'timeout'  => 0.01,
				'blocking' => false,
				'headers'  => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'     => wp_json_encode( $valuesArray ),
			]
		);

		return true;
	}


	/**
	 * Blocking request to the SaaS API returning the decoded response.
	 *
	 * Validates/refreshes the JWT for anything other than the public 'token'
	 * and 'mls' methods, always POSTs the JSON body (regardless of $type),
	 * and normalises errors into a ['success' => false, ...] array. On HTTP 200
	 * the raw decoded body is returned as-is.
	 *
	 * @param string $method      The API method/path to call.
	 * @param array  $valuesArray The request body data.
	 * @param string $type        The nominal request type (default 'GET').
	 * @return mixed Decoded response array, or an error descriptor array.
	 */
	public static function globalApiRequestSaas($method, $valuesArray, $type = 'GET') {
			global $mlsimport;
			 // Skip validation for token and mls requests
			if ($method !== 'token' && $method !== 'mls') {
				// Guarantee a valid token; otherwise return a failure descriptor.
				if (!self::validateAndRefreshToken()) {
					return [
						'success' => false,
						'error_message' => 'Token validation failed'
					];
				}
			}


			// Full endpoint URL.
			$url = MLSIMPORT_API_URL . $method;

			// Attach Bearer auth headers for authenticated methods only.
			$headers = [];
			if ($method !== 'token' && $method !== 'mls') {
				$token =  self::getApiToken();
				$headers = [
					'Authorization' => 'Bearer '.$token,
					'Content-Type' => 'application/json',
				];
			}


			// Request arguments (note: always dispatched via wp_remote_post below).
			$args = [
				'method' => $type,
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => $headers,
				'cookies' => [],
				'body' => !empty($valuesArray) ? wp_json_encode($valuesArray) : null,
			];
			// Always POST (even for logical GETs) — the SaaS expects a JSON body.
			$response = wp_remote_post($url, $args);

			// #208 recovery: a 401 on an authenticated call means the server
			// rejected the Bearer token even though the stored expiry looked
			// valid (revoked server-side, clock skew). Refresh once and retry
			// the same request once; a second 401 falls through to the normal
			// error path below. Token/mls calls carry no Bearer, so no retry.
			if ( 'token' !== $method && 'mls' !== $method
				&& ! is_wp_error( $response )
				&& 401 === intval( $response['response']['code'] ?? 0 )
				&& self::refreshToken() ) {
				$args['headers']['Authorization'] = 'Bearer ' . self::getApiToken();
				$response = wp_remote_post( $url, $args );
			}

			// Transport error → structured failure with WP error code/message.
			if (is_wp_error($response)) {
				return [
					'success' => false,
					'error_code' => $response->get_error_code(),
					'error_message' => esc_html($response->get_error_message())
				];
			}

                        // Extract HTTP status code and raw body.
                        $status_code = isset($response['response']['code']) ? intval($response['response']['code']) : 0;
                        $body        = wp_remote_retrieve_body($response);

                        // 200 → return the decoded payload untouched.
                        if (200 === $status_code) {
                                $receivedData = json_decode($body, true);
                                return $receivedData;
                        }

                        // Non-200: try to pull a human-readable error out of the JSON body.
                        $error_message = 'Unknown error';
                        $error_code    = $status_code;

                        $decoded_body = json_decode($body, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_body)) {
                                // Preferred shape: { error: { message, code } }.
                                if (isset($decoded_body['error']['message'])) {
                                        $error_message = $decoded_body['error']['message'];
                                        if (isset($decoded_body['error']['code'])) {
                                                $error_code = $decoded_body['error']['code'];
                                        }
                                // Fallback shape: { message }.
                                } elseif (isset($decoded_body['message'])) {
                                        $error_message = $decoded_body['message'];
                                }
                        }

                        // Return the normalised error descriptor (the exit() below is unreachable).
                        return [
                                'success' => false,
                                'error_code' => $error_code,
                                'error_message' => esc_html($error_message),
                        ];

			exit();
	}


	
	/**
	 * Check if token is expired and refresh if needed
	 * Call this before any external API request
	 *
	 * @return bool True if token is valid, false if refresh failed
	 */
	private static function validateAndRefreshToken() {
		global $mlsimport;
		
		// Get stored expiry timestamp
		$token_expiry = get_option('mlsimport_token_expiry', 0);
		$current_time = time();
		
		// Check if token is expired (now at/after the stored expiry).
		if ($current_time >= $token_expiry) {
			// Token expired, refresh it
			$refresh_result = self::refreshToken();

			// Propagate refresh failure to the caller.
			if (!$refresh_result) {
				return false;
			}
		}

		// Token is present and not past expiry.
		return true;
	}

	/**
	 * Record the SaaS connection-health state (#208).
	 *
	 * Stores array{status, since} in the mlsimport_connection_health option:
	 * 'healthy', 'credentials_invalid' (server rejected the stored account),
	 * or 'credentials_missing' (nothing configured). Transient failures such
	 * as network timeouts never call this, so a working state is not lost to
	 * a hiccup. Re-recording an unchanged status is skipped so 'since' keeps
	 * pointing at when the state actually began.
	 *
	 * @param string $status New health status keyword.
	 * @return void
	 */
	private static function setConnectionHealth( $status ) {
		$health = get_option( 'mlsimport_connection_health', array() );
		if ( is_array( $health ) && ( $health['status'] ?? '' ) === $status ) {
			return;
		}
		update_option(
			'mlsimport_connection_health',
			array(
				'status' => $status,
				'since'  => time(),
			)
		);

		// #208: a state CHANGE is the incident boundary — broken credentials
		// open the connection incident, a working refresh resolves it. The
		// alerts module dedups, so this cannot spam the SaaS.
		if ( 'healthy' === $status ) {
			if ( function_exists( 'mlsimport_alert_resolve' ) ) {
				mlsimport_alert_resolve( 'connection:credentials' );
			}
		} elseif ( function_exists( 'mlsimport_alert_open' ) ) {
			mlsimport_alert_open( 'connection:credentials', 'connection_broken', array( 'status' => $status ) );
		}
	}

	/**
	 * Request a fresh JWT from the SaaS 'token' endpoint and cache it.
	 *
	 * Reads the stored username/password, POSTs them, and on success stores the
	 * token in a transient plus the expiry timestamp in an option. Bumps the
	 * 'token_failures' telemetry counter on every failure path.
	 *
	 * @return bool True on successful refresh, false otherwise.
	 */
	private static function refreshToken() {
		global $mlsimport;
		
		// Get credentials for token request
		$options = get_option('mlsimport_admin_options');
		// Pull the SaaS account credentials out of the plugin options.
		$username = isset($options['mlsimport_username']) ? $options['mlsimport_username'] : '';
		$password = isset($options['mlsimport_password']) ? $options['mlsimport_password'] : '';

		// No credentials configured → cannot refresh.
		if (empty($username) || empty($password)) {
			mlsimport_telemetry_bump( 'token_failures' );
			self::setConnectionHealth( 'credentials_missing' );
			return false;
		}

		// #208 single-flight: only one process may refresh at a time.
		// add_option() is a plain INSERT, so a concurrent process loses the
		// race and backs off without firing a second token request. A lock
		// older than 60 seconds belongs to a crashed owner (the token request
		// itself times out at 45) and is taken over instead.
		if ( ! add_option( 'mlsimport_token_refresh_lock', time(), '', 'no' ) ) {
			$lock_held_since = intval( get_option( 'mlsimport_token_refresh_lock', 0 ) );
			if ( time() - $lock_held_since < 60 ) {
				return false;
			}
			update_option( 'mlsimport_token_refresh_lock', time() );
		}

		// Prepare token request
		$url = MLSIMPORT_API_URL . 'token';
		$body = wp_json_encode(array(
			'username' => $username,
			'password' => $password
		));
		
		$args = array(
			'method' => 'POST',
			'headers' => array(
				'Content-Type' => 'application/json'
			),
			'body' => $body,
			'timeout' => 45
		);
		
		// Make token request
		$response = wp_remote_post($url, $args);

		// Transport failure → count and abort (lock released for the next try).
		if (is_wp_error($response)) {
			mlsimport_telemetry_bump( 'token_failures' );
			delete_option( 'mlsimport_token_refresh_lock' );
			return false;
		}

		// Decode the JSON token response.
		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		// Reject any response missing success/token/expires.
		if (!isset($data['success']) || !$data['success'] || !isset($data['token']) || !isset($data['expires'])) {
			mlsimport_telemetry_bump( 'token_failures' );
			delete_option( 'mlsimport_token_refresh_lock' );
			// The server answered and said no → the credentials themselves are
			// bad (terminal until the user fixes them). A malformed/partial
			// body is a server hiccup instead and leaves health untouched.
			if ( is_array( $data ) && array_key_exists( 'success', $data ) && ! $data['success'] ) {
				self::setConnectionHealth( 'credentials_invalid' );
			}
			return false;
		}
		
		// Store new token and expiry
		//$mlsimport->admin->mlsimport_saas_store_mls_api_token_transient($data['token']);

		// Cache the token in a transient sized to its remaining lifetime.
		$expires_in = $data['expires'] - time();
		set_transient('mlsimport_saas_token', $data['token'], $expires_in);

		// Persist the absolute expiry so validateAndRefreshToken() can compare against it.
		update_option('mlsimport_token_expiry', intval($data['expires']));

		// First successful SaaS account connection (lifecycle telemetry).
		mlsimport_telemetry_set_once( 'account_connected_at', time() );

		// Refresh finished — release the single-flight lock.
		delete_option( 'mlsimport_token_refresh_lock' );

		// A minted token proves the account works → back to healthy.
		self::setConnectionHealth( 'healthy' );

		return true;
	}








	


	/**
	 * Write logs for import process
	 *
	 * @param string $logs The log message to write.
	 * @param string $type The type of log.
	 */
	private function writeImportLogs($logs, $type) {
		mlsimport_saas_single_write_import_custom_logs($logs, $type);
	}




	



























	








	









	/**
	 * Return user option
	 *
	 * @param int $selected The selected user ID.
	 * @return string The HTML option elements for users.
	 */
	public function mlsimportSaasThemeImportSelectUser($selected) {
		$userOptions = '';
		// Fetch all users to build a <select> of possible property authors.
		$blogusers = get_users(['blog_id' => 1, 'orderby' => 'nicename']);
		foreach ($blogusers as $user) {
			$userOptions .= '<option value="' . esc_attr($user->ID) . '"';
			// Pre-select the currently chosen user.
			if ($user->ID == $selected) {
				$userOptions .= ' selected="selected"';
			}
			$userOptions .= '>' . esc_html($user->user_login) . '</option>';
		}
		return $userOptions;
	}







	/**
	 * Return agent option
	 *
	 * @param int $selected The selected agent ID.
	 * @return string The HTML option elements for agents.
	 */
	public function mlsimportSaasThemeImportSelectAgent($selected) {
		global $mlsimport;
		// Query up to 150 published agents of the theme's agent post type.
		$args = [
			'post_type' => $mlsimport->admin->env_data->get_agent_post_type(),
			'post_status' => 'publish',
			'posts_per_page' => 150,
		];

		$agentSelection = new WP_Query($args);
		// Start with a blank option (no agent).
		$agentOptions = '<option value=""></option>';

		// Build one <option> per agent post.
		while ($agentSelection->have_posts()) {
			$agentSelection->the_post();
			$agentId = get_the_ID();

			$agentOptions .= '<option value="' . esc_attr($agentId) . '"';
			// Pre-select the currently chosen agent.
			if ($agentId == $selected) {
				$agentOptions .= ' selected="selected"';
			}
			$agentOptions .= '>' . esc_html(get_the_title()) . '</option>';
		}
		wp_reset_postdata();

		return $agentOptions;
	}





	












	/**
	 * Delete property via SQL
	 *
	 * @param int $deleteId The ID of the property to delete.
	 * @param string $ListingKey The listing key of the property.
	 */
       public function mlsimportSaasDeletePropertyViaMysql($deleteId, $ListingKey) {
               global $mlsimport;

               // Resolve the post's type and the theme's expected property post type.
               $postType = get_post_type($deleteId);
               $propertyPostType = '';
               if (isset($mlsimport->admin->env_data) && method_exists($mlsimport->admin->env_data, 'get_property_post_type')) {
                       $propertyPostType = $mlsimport->admin->env_data->get_property_post_type();
               }

               // Only delete when the post is actually a property post type.
               if ($postType === $propertyPostType || in_array($postType, ['estate_property', 'property'])) {
                       // Delete attachments using WordPress functions so the files are removed as well
                       $attachments = get_posts([
                               'numberposts' => -1,
                               'post_type'   => 'attachment',
                               'post_parent' => $deleteId,
                               'post_status' => null,
                               'fields'      => 'ids',
                       ]);

                       // Remove each attachment (and its underlying file).
                       foreach ($attachments as $attachmentId) {
                               wp_delete_attachment($attachmentId, true);
                       }

                       // Capture the current status term names for the delete log.
                       $termObjList   = get_the_terms($deleteId, 'property_status');
                       $deleteIdStatus = join(', ', wp_list_pluck($termObjList, 'name'));

                       // Re-read ListingKey from meta; an empty key means a manually added listing.
                       $ListingKey = get_post_meta($deleteId, 'ListingKey', true);
                       if ('' === $ListingKey) { // manually added listing
                               // Never delete user-created listings; log and bail.
                               $logEntry = 'User added listing with id ' . $deleteId . ' (' . $postType . ') (status ' . $deleteIdStatus . ') and ' . $ListingKey . ' NOT DELETED' . PHP_EOL;
                               $this->writeImportLogs($logEntry, 'delete');
                               return;
                       }

                       // Log the reconciliation-driven deletion in the activity feed.
                       mlsimport_record_activity( 'deleted', $deleteId, $ListingKey, intval(get_post_meta($deleteId,'MLSimport_item_inserted',true)), 'reconciliation' );

                       global $wpdb;
                       // Raw SQL delete skips wp_delete_post (too slow), so nothing cleans the
                       // property's term relationships, term counts or listings row. Do that
                       // cleanup explicitly (SQL-first) before removing the post itself.
                       // Standalone mode: purge the plugin's own term/listings relations first.
                       if ( class_exists( 'Mlsimport_Standalone_Row' ) ) {
                               Mlsimport_Standalone_Row::purge_post_relations( $deleteId );
                       }
                       // Raw delete of the post's meta, then the post and any remaining children.
                       $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->postmeta WHERE `post_id` = %d", $deleteId));
                       $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->posts WHERE `post_parent` = %d OR `ID` = %d", $deleteId, $deleteId));
                       mlsimport_telemetry_bump( 'deleted' );

                       $logEntry = 'MYSQL DELETE -> Property with id ' . $deleteId . ' (' . $postType . ') (status ' . $deleteIdStatus . ') and ' . $ListingKey . ' was deleted on ' . current_time('Y-m-d\TH:i') . PHP_EOL;
                       $this->writeImportLogs($logEntry, 'delete');
               }
       }









/**
 * Delegate one incoming property to the explicit Stored Listing Write module.
 *
 * ThemeImport translates the legacy Import Task option names once at this
 * compatibility edge. Listing decisions, common normalization, ordering,
 * persistence, media, activity, and terminal outcomes stay behind write().
 *
 * @param array<string, mixed> $property               Raw RESO property.
 * @param array<string, mixed> $itemIdArray             Import Task identity.
 * @param string               $tipImport               Manual or cron source.
 * @param array<string, mixed> $mlsimportItemOptionData Legacy task options.
 * @return array<string, mixed>|false Public write result, or false if unconfigured.
 */
public function mlsimportSaasPrepareToImportPerItem( $property, $itemIdArray, $tipImport, $mlsimportItemOptionData ) {
	// A ThemeImport object used only for static SaaS/reconciliation helpers has
	// no writer. If a listing call reaches such an object, fail this item without
	// mutating WordPress; shared task execution will continue with the next one.
	if ( null === $this->stored_listing_write ) {
		$this->writeImportLogs(
			empty( $property['ListingKey'] )
				? 'ERROR: No Listing Key ' . PHP_EOL
				: 'ERROR: Stored Listing Write is not configured.' . PHP_EOL,
			(string) $tipImport
		);
		return false;
	}

	// Translate the shallow legacy option array into the stable module settings.
	$settings = array(
		'task_id'             => (int) ( $itemIdArray['item_id'] ?? 0 ),
		'source'              => (string) $tipImport,
		'statuses'            => is_array( $mlsimportItemOptionData['mlsimport_item_standardstatus'] ?? null )
			? $mlsimportItemOptionData['mlsimport_item_standardstatus']
			: array(),
		'user_id'             => (int) ( $mlsimportItemOptionData['mlsimport_item_property_user'] ?? 0 ),
		'assigned_agent_id'   => (int) ( $mlsimportItemOptionData['mlsimport_item_agent'] ?? 0 ),
		'use_mls_agent'       => ! empty( $mlsimportItemOptionData['mlsimport_item_use_mls_agent'] ),
		'post_status'         => (string) ( $mlsimportItemOptionData['mlsimport_item_property_status'] ?? 'publish' ),
		'field_configuration' => is_array( $mlsimportItemOptionData['mlsimport_field_configuration'] ?? null )
			? $mlsimportItemOptionData['mlsimport_field_configuration']
			: array(),
		'title_format'        => (string) ( $mlsimportItemOptionData['mlsimport_item_title_format'] ?? '' ),
		'config_version'      => (string) ( $mlsimportItemOptionData['mlsimport_write_config_version'] ?? '' ),
	);

	return $this->stored_listing_write->write( $property, $settings );
}


        
        
        


	
/**
 * Check for property status against MLS item delete status to see if we keep or delete the listing.
 * @param int $property_id
 * @param string|array $mlsImportItemStatus
 * @return bool True to keep, false to delete
 */
public function check_if_delete_when_status($property_id, $mlsImportItemStatus, $mlsImportItemStatusDelete = null, $mlsImportItemStatusProtect = null) {

    // Resolve the taxonomy field-map, then read the property's current status term.
	$mlsimport_fields_opt = mlsimport_active_field_configuration();
    $mlsimport_status_tax_map = isset($mlsimport_fields_opt['mls-fields-map-taxonomy'])
        ? $mlsimport_fields_opt['mls-fields-map-taxonomy'] : array();
    $post_status = mlsimport_read_property_status($property_id, $mlsimport_status_tax_map);

    // Protected statuses: keep if property status matches
    if (!empty($mlsImportItemStatusProtect)) {
        // An unreadable status cannot prove the listing is NOT protected — keep and log.
        if ('' === $post_status) {
            $this->writeImportLogs('Property with id ' . $property_id . ' KEPT: status unreadable, cannot check it against Protected Statuses' . PHP_EOL, 'delete');
            return true;
        }
        // Normalise the protect list to space-free enum keys.
        $mlsImportItemStatusProtect = is_array($mlsImportItemStatusProtect)
            ? array_map('mlsimport_normalize_status_enum', $mlsImportItemStatusProtect)
            : array(mlsimport_normalize_status_enum($mlsImportItemStatusProtect));
        // Property status is protected → keep it.
        if (in_array($post_status, $mlsImportItemStatusProtect, true)) {
            return true;
        }
    }

    // Default: delete if not protected
    return false;
}




/**
 * Manual-import variant of the keep/delete status check.
 *
 * @param int          $property_id         The property post ID.
 * @param array|string $mlsImportItemStatus Task's selected statuses.
 * @return bool True if the property's status matches the selected set.
 */
public function check_if_delete_when_status_on_manual_import($property_id, $mlsImportItemStatus) {
    // Normalize status arrays/strings to a space-free comparison key so
    // Trestle PrettyEnums labels match the raw enum config values.
    $mlsImportItemStatus = is_array($mlsImportItemStatus)
        ? array_map('mlsimport_normalize_status_enum', $mlsImportItemStatus)
        : mlsimport_normalize_status_enum($mlsImportItemStatus);

    // Resolve the taxonomy field-map, then read the property's current status term.
	$mlsimport_fields_opt = mlsimport_active_field_configuration();
    $mlsimport_status_tax_map = isset($mlsimport_fields_opt['mls-fields-map-taxonomy'])
        ? $mlsimport_fields_opt['mls-fields-map-taxonomy'] : array();
    $post_status = mlsimport_read_property_status($property_id, $mlsimport_status_tax_map);

    // An unreadable status is our read failing, not proof the listing should go — keep and log.
    if ('' === $post_status) {
        $this->writeImportLogs('Property with id ' . $property_id . ' KEPT: status unreadable, deletion requires a readable status' . PHP_EOL, 'delete');
        return true;
    }

    // Keep if status matches "keep" status (array membership or scalar equality).
 	if ((is_array($mlsImportItemStatus) && in_array($post_status, $mlsImportItemStatus, true)) ||
        (!is_array($mlsImportItemStatus) && $post_status === $mlsImportItemStatus)) {
		
        return true;
    }



    // Default: status read but doesn't match the task's selection → delete.
    return false;
}






	
	/**
        * Check if we should keep or delete the listing when still in MLS.
	    * true we keep 
        */
       public function check_if_delete_when_status_when_in_mls($property_id, $mlsimport_item_standardstatus, $mlsimport_item_standardstatusprotect = null) {
           // Resolve the taxonomy field-map, then read the property's current status term.
		   $mlsimport_fields_opt = mlsimport_active_field_configuration();
           $mlsimport_status_tax_map = isset($mlsimport_fields_opt['mls-fields-map-taxonomy'])
               ? $mlsimport_fields_opt['mls-fields-map-taxonomy'] : array();
           $post_status = mlsimport_read_property_status($property_id, $mlsimport_status_tax_map);

           // The listing is still in the MLS feed. An unreadable local status is
           // never proof it should be deleted (every past mass-deletion incident
           // was this read failing) — keep and log.
           if ('' === $post_status) {
               $this->writeImportLogs('Property with id ' . $property_id . ' KEPT: still in MLS feed but local status unreadable' . PHP_EOL, 'delete');
               return true;
           }

           // Protected statuses: keep if property status matches
           if (!empty($mlsimport_item_standardstatusprotect)) {
               // Normalise the protect list to space-free enum keys.
               $mlsimport_item_standardstatusprotect = is_array($mlsimport_item_standardstatusprotect)
                   ? array_map('mlsimport_normalize_status_enum', $mlsimport_item_standardstatusprotect)
                   : array(mlsimport_normalize_status_enum($mlsimport_item_standardstatusprotect));
               // Protected → keep.
               if (in_array($post_status, $mlsimport_item_standardstatusprotect, true)) {
                   return true;
               }
           }

           // Early return if MLS status empty
           if (empty($mlsimport_item_standardstatus)) {
               return true; // default: keep if no status set
           }

           // Normalize standard statuses to a space-free key for comparison
           if (is_array($mlsimport_item_standardstatus)) {
               // Array form → keep when the property's status is a member.
               $mlsimport_item_standardstatus = array_map('mlsimport_normalize_status_enum', $mlsimport_item_standardstatus);
               return in_array($post_status, $mlsimport_item_standardstatus, true);
           }
           // Scalar form → keep on exact (normalised) match.
           return $post_status === mlsimport_normalize_status_enum($mlsimport_item_standardstatus);
       }















}
