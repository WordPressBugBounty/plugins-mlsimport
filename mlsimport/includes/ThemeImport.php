<?php 
/**
 * ThemeImport — SaaS API client + property import/media pipeline.
 *
 * This class is the heart of the MLSImport data flow. It owns two related jobs:
 *  1) Talking to the MLSImport SaaS API (AWS API Gateway): OAuth token
 *     validation/refresh and the three request helpers
 *     (globalApiRequestCurlSaas / globalApiRequestSaas / *FireAndForget).
 *  2) Turning a decoded RESO property record into a WordPress post: deciding
 *     insert vs update vs delete by status, writing post meta / taxonomies,
 *     attaching media, rebuilding the gallery, setting the featured image and
 *     the title, plus heavy memory-management scaffolding around each step.
 *
 * NOTE: the import/media/gallery logic has known data-loss subtleties (stale
 * featured-image references, gallery overwrites, failed-attachment handling).
 * The inline comments below DESCRIBE what each step does; they do not assert
 * that the behaviour is correct.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Description of ThemeImport
 *
 * @class ThemeImport
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
			return false;
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

		// Transport failure → count and abort.
		if (is_wp_error($response)) {
			mlsimport_telemetry_bump( 'token_failures' );
			return false;
		}

		// Decode the JSON token response.
		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		// Reject any response missing success/token/expires.
		if (!isset($data['success']) || !$data['success'] || !isset($data['token']) || !isset($data['expires'])) {
			mlsimport_telemetry_bump( 'token_failures' );
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

		return true;
	}


/**
 * Parse a batch of listings for one import task (manual/normal path) and
 * import each property, with aggressive per-item memory management.
 *
 * @param array $readyToParseArray The array ready to be parsed.
 * @param array $itemIdArray The item ID array.
 * @param string $batchKey The batch key.
 * @param array $mlsimportItemOptionData The item option data.
 */
public function mlsimportSaasParseSearchArrayPerItem($readyToParseArray, $itemIdArray, $batchKey, $mlsimportItemOptionData) {
    // Start with aggressive memory cleanup
    $this->cleanUpMemory(true);
    
    // Log initial memory usage
    $initialMemory = memory_get_usage(true);

    // Running counter of processed properties + the trimmed working set.
    $counterProp = 0;
    $processedData = [];

    // Only proceed if the payload actually carries a 'data' array of listings.
    if (isset($readyToParseArray['data']) && is_array($readyToParseArray['data'])) {
        // Log total items to process
        $totalItems = count($readyToParseArray['data']);




        // Only keep essential data in memory, discard the rest
        foreach ($readyToParseArray['data'] as $key => $property) {



			// Save only what's needed from each property
            // (only listings with a ListingKey are retained).
            if (isset($property['ListingKey'])) {
                $processedData[$key] = $property;
            }
            // Remove from original array to free memory
            unset($readyToParseArray['data'][$key]);
        }
        
        // Complete unset of the original array
        unset($readyToParseArray);
        $this->cleanUpMemory();


		// Resolve the import task post ID and its running progress counter.
		$mlsimportItemId = intval($itemIdArray['item_id']);

		$current_prop_value = (int) get_post_meta( $mlsimportItemId, 'mlsimport_progress_properties', true );

        
        // Process each property
        foreach ($processedData as $key => $property) {
            // Advance the processed-property counter.
            ++$counterProp;
            
            // Memory usage before processing property
            $memoryBefore = memory_get_usage(true);
            $memoryBeforeMB = round($memoryBefore / 1048576, 2);

            // Identify the listing (used for logging context).
            $listingKey = isset($property['ListingKey']) ? $property['ListingKey'] : 'unknown';
            
            // Clear out database caches that might be polluted
            // so the force-stop flag below is read fresh, not from cache.
            wp_cache_delete('mlsimport_force_stop_' . $itemIdArray['item_id'], 'options');
            $GLOBALS['wpdb']->queries = array();

            // Read the force-stop flag: 'no' means keep importing.
            $status = get_option('mlsimport_force_stop_' . $itemIdArray['item_id']);

            // Not force-stopped → import this property.
            if ($status === 'no') {

				   // Bump and persist the progress counter for the UI.
				$current_prop_value = $current_prop_value + 1;
				update_post_meta( $mlsimportItemId, 'mlsimport_progress_properties', $current_prop_value );



                // Process property and track memory
                $this->mlsimportSaasPrepareToImportPerItem($property, $itemIdArray, 'normal', $mlsimportItemOptionData);
                
                // Memory after processing property
                $memoryAfter = memory_get_usage(true);
                $memoryAfterMB = round($memoryAfter / 1048576, 2);
                $memoryDiff = round(($memoryAfter - $memoryBefore) / 1048576, 2);
                
                
                // Check for memory leak pattern
                // (a >10MB jump for one property triggers a hard cleanup).
                if ($memoryDiff > 10) {
                    // Force cleanup on large increases
                    $this->cleanUpMemory(true);
                }
                
                // Aggressively clean after each property
                unset($property);
                
                // Periodic more intensive cleanup
                // (every 3rd property: flush query + autoloaded-options caches).
                if ($counterProp % 3 == 0) {
                    $this->cleanUpMemory(true);
                    
                    // Free database query cache
                    $GLOBALS['wpdb']->flush();
                    
                    // Clear autoloaded options cache, which can grow large
                    wp_cache_delete('alloptions', 'options');
                    
                    // Log memory after cleanup
                    $memoryAfterCleanup = memory_get_usage(true);
                    $freedMemory = round(($memoryAfter - $memoryAfterCleanup) / 1048576, 2);
                }
            } else {
                // Force-stopped: mark the task completed and stop the loop.
                update_post_meta($itemIdArray['item_id'], 'mlsimport_spawn_status', 'completed');
                break;
            }
            
            // Clear property from processed data to free memory
            unset($processedData[$key]);
        }
    } else {
        // No 'data' array present — nothing to import (branch intentionally empty).
    }
    
    // Final cleanup
    unset($processedData);
    $this->cleanUpMemory(true);
    
    // Log final memory stats
    // (values computed for diagnostics; logging call sites are commented out).
    $finalMemory = memory_get_usage(true);
    $finalMemoryMB = round($finalMemory / 1048576, 2);
    $totalMemoryDiff = round(($finalMemory - $initialMemory) / 1048576, 2);
    $peakMemory = round(memory_get_peak_usage(true) / 1048576, 2);
    
}


/**
 * Comprehensive memory cleanup function
 * 
 * @param bool $intensive Whether to perform intensive cleanup
 */
private function cleanUpMemory($intensive = false) {
    // Basic cleanup
    wp_cache_flush();
    gc_collect_cycles();

    // Intensive mode adds object/post/term/db cache clearing and extra GC passes.
    if ($intensive) {
        // Clear WordPress object cache
        global $wp_object_cache;
        if (is_object($wp_object_cache) && method_exists($wp_object_cache, 'flush')) {
            $wp_object_cache->flush();
        }
        
        // Clear WordPress post caches
        clean_post_cache(0);
        
        // Safe term cache clearing - avoid SQL errors
        wp_cache_delete('get_terms', 'terms');
        wp_cache_delete('term_meta', 'terms');
        delete_option('category_children');
        
        // Clear taxonomy-specific caches for common taxonomies
        $taxonomies = array('category', 'post_tag', 'property_status', 'property_type', 'property_feature', 'property_label', 'property_area', 'property_city', 'property_state', 'property_neighborhood');
        // Drop each taxonomy's cached relationships bucket.
        foreach ($taxonomies as $taxonomy) {
            wp_cache_delete($taxonomy . '_relationships', 'terms');
        }
        
        // Clear WordPress database cache
        global $wpdb;
        if (is_object($wpdb)) {
            // Reset the saved-query log and flush wpdb's internal caches.
            $wpdb->queries = array();
            if (method_exists($wpdb, 'flush')) {
                $wpdb->flush();
            }
        }
        
        // Multiple garbage collection passes can sometimes help
        gc_collect_cycles();
        gc_collect_cycles();
    }
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
	 * Get memory usage
	 *
	 * @return string The memory usage in MB.
	 */
	public function mlsimportMemUsage() {
		$memUsage = memory_get_usage(true);
		$memUsageShow = round($memUsage / 1048576, 2);
		return $memUsageShow . 'mb ';
	}



	


    /**
     * Parse and import property data for a single MLSimport item in CRON.
     * Logs memory usage for each significant operation.
     *
     * @param array  $readyToParseArray The array with listing data (from API).
     * @param array  $itemIdArray       The array with current MLSimport item info.
     * @param string $batchKey          The batch identifier for logging.
     */
    public function mlsimportSaasCronParseSearchArrayPerItem($readyToParseArray, $itemIdArray, $batchKey) {
        // Gather relevant meta for this MLSimport item
        $mlsimportItemOptionData = [
            'mlsimport_item_standardstatus'        => get_post_meta($itemIdArray['item_id'], 'mlsimport_item_standardstatus', true),
            'mlsimport_item_standardstatusprotect'  => get_post_meta($itemIdArray['item_id'], 'mlsimport_item_standardstatusprotect', true),
            'mlsimport_item_property_user'         => get_post_meta($itemIdArray['item_id'], 'mlsimport_item_property_user', true),
            'mlsimport_item_agent'                 => get_post_meta($itemIdArray['item_id'], 'mlsimport_item_agent', true),
            'mlsimport_item_use_mls_agent'         => get_post_meta($itemIdArray['item_id'], 'mlsimport_item_use_mls_agent', true),
            'mlsimport_item_property_status'       => get_post_meta($itemIdArray['item_id'], 'mlsimport_item_property_status', true),
        ];

        $count = isset($readyToParseArray['data']) && is_array($readyToParseArray['data']) ? count($readyToParseArray['data']) : 0;
        $log = '[Memory] Start batch ' . $batchKey . ' with ' . $count . ' listings: ' . (memory_get_usage(true) / 1024 / 1024) . ' MB';
        $this->writeImportLogs($log, 'cron');

        if ($count === 0) {
            $this->writeImportLogs('[Memory] No data to parse in batch ' . $batchKey, 'cron');
            return;
        }

        foreach ($readyToParseArray['data'] as $key => $property) {
            // Log at the start of each property (optional, comment out if too verbose)
            //$log = '[Memory] Before import property #' . $key . ': ' . (memory_get_usage(true) / 1024 / 1024) . ' MB';
            //$this->writeImportLogs($log, 'cron');

            $logs = 'In CRON parse search array, listing no ' . $key . ' from batch ' . $batchKey . ' with ListingKey: ' . $property['ListingKey'] . PHP_EOL;
            $this->writeImportLogs($logs, 'cron');

            // Main per-property import function (handles mapping/import/update)
            $this->mlsimportSaasPrepareToImportPerItem($property, $itemIdArray, 'cron', $mlsimportItemOptionData);

            // Clean up per-iteration memory
            unset($property);
            if (($key + 1) % 20 === 0) {
                gc_collect_cycles();
                $log = '[Memory] After importing ' . ($key + 1) . ' listings in batch ' . $batchKey . ': ' . (memory_get_usage(true) / 1024 / 1024) . ' MB';
                $this->writeImportLogs($log, 'cron');
            }
        }
        // Final memory log for this batch
        $this->writeImportLogs('[Memory] End batch ' . $batchKey . ': ' . (memory_get_usage(true) / 1024 / 1024) . ' MB', 'cron');

        // Housekeeping
        unset($readyToParseArray, $mlsimportItemOptionData);
        gc_collect_cycles();
    }











	/**
	 * Check if property already imported
	 *
	 * @param string $key The key to search for.
	 * @param string $postType The post type to search within (default is 'estate_property').
	 * @return int The post ID if found, or 0 if not found.
	 */
	public function mlsimportSaasRetrievePropertyById($key, $postType = 'estate_property') {
		// Look up any post of $postType whose ListingKey meta equals $key (IDs only).
		$args = [
			'post_type' => $postType,
			'post_status' => 'any',
			'meta_query' => [
				[
					'key' => 'ListingKey',
					'value' => $key,
					'compare' => '=',
				],
			],
			'fields' => 'ids',
		];

		$query = new WP_Query($args);
		// Match found → return the first post ID.
		if ($query->have_posts()) {
			$query->the_post();
			$propertyId = get_the_ID();
			wp_reset_postdata();
			return $propertyId;
		} else {
			// No existing property with this ListingKey.
			wp_reset_postdata();
			return 0;
		}
	}




	/**
	 * Clear taxonomy
	 *
	 * @param int $propertyId The property ID.
	 * @param array $taxonomies The taxonomies to clear.
	 */
	public function mlsimportSaasClearPropertyForTaxonomy($propertyId, $taxonomies) {
		// Only iterate when given a taxonomy map.
		if (is_array($taxonomies)) {
			// Walk each taxonomy key from the incoming property data.
			foreach ($taxonomies as $taxonomy => $term) {
				// Defensive: skip a taxonomy key that is itself a WP_Error.
				if (is_wp_error($taxonomy)) {
				
					continue; // Skip this iteration
				}

				// Remove the property's existing term relationships for this taxonomy.
				if (taxonomy_exists($taxonomy)) {
					wp_delete_object_term_relationships($propertyId, $taxonomy);
				} else {
					// Unknown taxonomy — nothing to clear.
				}
			}
		}
	}





	/**
	 * Set taxonomy for property
	 *
	 * @param string $taxonomy The taxonomy to set.
	 * @param int $propertyId The property ID.
	 * @param mixed $fieldValues The values to set.
	 */
	public function mlsimportSaasUpdateTaxonomyForProperty($taxonomy, $propertyId, $fieldValues) {
		global $wpdb;

		// Convert comma-separated values to array if necessary
		if (!is_array($fieldValues)) {
			$fieldValues = strpos($fieldValues, ',') !== false ? explode(',', $fieldValues) : [$fieldValues];
		}

		// Trim values and remove empty ones
		$fieldValues = array_filter(array_map('trim', $fieldValues));

		// Start a database transaction
		$wpdb->query('START TRANSACTION');
		$taxLog = [];

		// Process the term values in chunks of 5 (memory-friendly).
		foreach (array_chunk($fieldValues, 5) as $chunk) {
			// Each individual term value in the chunk.
			foreach ($chunk as $value) {
				if (!empty($value)) {
					// Check if the term already exists
					$term = $wpdb->get_row($wpdb->prepare(
						"SELECT t.*, tt.* FROM $wpdb->terms t
						INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
						WHERE t.name = %s AND tt.taxonomy = %s",
						$value, $taxonomy
					));

					$taxLog[] = json_encode($term);
					// Term not found → create the term + its term_taxonomy row.
					if (is_null($term)) {
						// Insert the term if it doesn't exist
						$wpdb->insert($wpdb->terms, [
							'name' => $value,
							'slug' => sanitize_title($value),
							'term_group' => 0
						]);

						$termId = $wpdb->insert_id;

						// Term insert succeeded → add the taxonomy mapping row.
						if ($termId) {
							// Insert term taxonomy
							$wpdb->insert($wpdb->term_taxonomy, [
								'term_id' => $termId,
								'taxonomy' => $taxonomy,
								'description' => '',
								'parent' => 0,
								'count' => 0
							]);

							$termTaxonomyId = $wpdb->insert_id;
						} else {
							// Term insert failed → log and skip this value.
							$taxLog[] = 'Error inserting term';
							continue;
						}
					} else {
						// Term exists, get term_id and term_taxonomy_id
						$termId = $term->term_id;
						$termTaxonomyId = $wpdb->get_var($wpdb->prepare(
							"SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = %d AND taxonomy = %s",
							$termId, $taxonomy
						));
					}

					// With a valid term_taxonomy_id, link the property to the term and bump the count.
					if (!empty($termTaxonomyId)) {
						// Insert term relationship
						$wpdb->replace($wpdb->term_relationships, [
							'object_id' => $propertyId,
							'term_taxonomy_id' => $termTaxonomyId
						]);
						// Location taxonomies (browse-by-city/area widgets) use a publish-aware
						// recompute so the displayed count matches the publish-only archive.
						// A blind +1 is not idempotent (re-imports inflate it) and ignores
						// post status, so a new city could show a count while its archive is
						// empty. These terms are low-cardinality (tens of listings), so the
						// COUNT is cheap. High-cardinality grouping taxonomies keep the O(1)
						// increment to avoid scanning thousands of rows per import.
						$location_taxonomies = array('property_city', 'property_area', 'property_state', 'property_neighborhood');
						// Location taxonomy → recompute count from publish-only posts.
						if (in_array($taxonomy, $location_taxonomies, true)) {
							// Mirrors WordPress' _update_post_term_count callback in SQL.
							$wpdb->query($wpdb->prepare(
								"UPDATE $wpdb->term_taxonomy tt
								SET count = (
									SELECT COUNT(*) FROM $wpdb->term_relationships tr
									INNER JOIN $wpdb->posts p ON p.ID = tr.object_id
									WHERE tr.term_taxonomy_id = tt.term_taxonomy_id
										AND p.post_status = 'publish'
								)
								WHERE tt.term_taxonomy_id = %d",
								$termTaxonomyId
							));
						} else {
							// High-cardinality taxonomy → cheap O(1) count increment.
							$wpdb->query($wpdb->prepare(
								"UPDATE $wpdb->term_taxonomy SET count = count + 1 WHERE term_taxonomy_id = %d",
								$termTaxonomyId
							));
						}
					} else {
						// No term_taxonomy_id resolved → record the failure.
						$taxLog[] = 'Error: term_taxonomy_id is null';
					}
				}
			}
			// Flush the cache to free up memory
			wp_cache_flush();
			// Run garbage collection
			gc_collect_cycles();
		}
		// Commit the transaction
		$wpdb->query('COMMIT');

		// Clear term cache selectively
		wp_cache_delete("{$taxonomy}_terms", 'terms');
		wp_cache_delete("{$taxonomy}_children", 'terms');
		
		// Restore the term metadata filter
		add_filter('get_term_metadata', [$wpdb->terms, 'cache_term_counts'], 10, 2);

		// Log memory usage
		// if (!empty($taxLog)) {
		//     $taxLogStr = implode(PHP_EOL, $taxLog);
		//     mlsimport_saas_single_write_import_custom_logs($taxLogStr, 'normal');
		//     unset($taxLogStr);
		// }
	}




	/**
	 * Set Property Title
	 *
	 * @param int $propertyId The property ID.
	 * @param int $mlsImportPostId The MLS import post ID.
	 * @param array $property The property data.
	 * @return string The updated title format.
	 */
	public function mlsimportSaasUpdatePropertyTitle($propertyId, $mlsImportPostId, $property) {
		global $mlsimport;

		// Per-task title template; falls back to the global sync setting when unset.
		$titleFormat = esc_html(get_post_meta($mlsImportPostId, 'mlsimport_item_title_format', true));

		if ('' === $titleFormat) {
			$options = get_option('mlsimport_admin_mls_sync');
			$titleFormat = $options['title_format'];
		}

		// Extract the {token} placeholders present in the template.
		$titleArray = $this->strBetweenAll($titleFormat, '{', '}');

		// Lowercased copy of extra_meta for case-insensitive lookups below.
		$propertyExtraMetaArrayLowerCase = array_change_key_case($property['extra_meta'], CASE_LOWER);

		// Resolve each placeholder to its value and substitute it into the template.
		foreach ($titleArray as $key => $value) {
			$replace = '';
			// Map the known placeholder names to their source in the property data.
			switch ($value) {
				case 'Address':
					$replace = $property['adr_title'] ?? '';
					break;
				case 'City':
					$replace = $property['adr_city'] ?? '';
					break;
				case 'CountyOrParish':
					$replace = $property['adr_county'] ?? '';
					break;
				case 'PropertyType':
					$replace = $property['adr_type'] ?? '';
					break;
				case 'Bedrooms':
					$replace = $property['adr_bedrooms'] ?? '';
					break;
				case 'Bathrooms':
					$replace = $property['adr_bathrooms'] ?? '';
					break;
				case 'ListingKey':
					$replace = $property['ListingKey'];
					break;
				case 'ListingId':
					$replace = $property['adr_listingid'] ?? '';
					break;
				case 'StateOrProvince':
					$replace = $property['extra_meta']['StateOrProvince'] ?? '';
					break;
				case 'PostalCode':
					$replace = $property['meta']['property_zip'] ?? $property['meta']['fave_property_zip'] ?? '';
					$replace = is_array($replace) ? strval($replace[0]) : strval($replace);
					break;
				case 'StreetNumberNumeric':
					$replace = $propertyExtraMetaArrayLowerCase['streetnumbernumeric'] ?? '';
					break;
				case 'StreetName':
					$replace = $propertyExtraMetaArrayLowerCase['streetname'] ?? '';
					break;
			}
			// Replace this {token} occurrence with the resolved value.
			$titleFormat = str_replace('{' . $value . '}', $replace, $titleFormat);
		}

		// Persist the computed title (also reused as the post slug).
		$post = [
			'ID' => $propertyId,
			'post_title' => $titleFormat,
			'post_name' => $titleFormat,
		];

		wp_update_post($post);

		return $titleFormat;
	}

	




	/**
	 * Prepare meta data for property
	 *
	 * @param array $property The property data.
	 * @return array The property data with prepared meta.
	 */
	public function mlsimportSaasPrepareMetaForProperty($property) {
		// BathroomsTotalDecimal is not provided by every MLS (e.g. BrightMLS sends only
		// BathroomsTotalInteger / BathroomsFull). Fall back so the theme Overview value
		// is not wiped to empty.
		$bathroomsRaw = $property['extra_meta']['BathroomsTotalDecimal']
			?? $property['extra_meta']['BathroomsTotalInteger']
			?? $property['extra_meta']['BathroomsFull']
			?? '';
		$bathrooms    = ( '' === $bathroomsRaw || null === $bathroomsRaw ) ? '' : floatval($bathroomsRaw);
		$property['meta']['property_bathrooms'] = $bathrooms;
		$property['meta']['fave_property_bathrooms'] = $bathrooms;
		$property['meta']['REAL_HOMES_property_bathrooms'] = $bathrooms;
	
		// PostalCode is commonly provided in normalized meta (property_zip) rather than extra_meta.
		// Mirror it into extra_meta when missing so field mappings (postmeta/taxonomy) can process it.
		if (!isset($property['extra_meta']) || !is_array($property['extra_meta'])) {
			$property['extra_meta'] = array();
		}

		$postal_code = '';
		if (isset($property['meta']) && is_array($property['meta'])) {
			if (!empty($property['meta']['property_zip'])) {
				$postal_code = $property['meta']['property_zip'];
			} elseif (!empty($property['meta']['fave_property_zip'])) {
				$postal_code = $property['meta']['fave_property_zip'];
			} elseif (!empty($property['meta']['REAL_HOMES_property_zip'])) {
				$postal_code = $property['meta']['REAL_HOMES_property_zip'];
			}
		}

		if (is_array($postal_code)) {
			$postal_code = reset($postal_code);
		}
		$postal_code = trim((string) $postal_code);

		if ('' !== $postal_code && empty($property['extra_meta']['PostalCode'])) {
			$property['extra_meta']['PostalCode'] = $postal_code;
		}

		return $property;
	}




	
	/**
	 * Attach media to post
	 *
	 * @param int $propertyId The property ID.
	 * @param array $media The media data.
	 * @param string $isInsert Whether the property is being inserted.
	 * @return string The media history log.
	 */
	public function mlsimportSassAttachMediaToPost($propertyId, $media, $isInsert,$media_attachments,$featuredImageKey, $shouldRefreshMedia = false) {

		$mediaHistory = [];

		// Editing an existing property with unchanged media → keep current images, do nothing.
		if ($isInsert === 'no' && !$shouldRefreshMedia) {
			$mediaHistory[] = 'Media - We have edit - images are not replaced';
			return $media_attachments;
		}

		global $mlsimport;
		// Needed for wp_insert_attachment / image handling helpers.
		include_once ABSPATH . 'wp-admin/includes/image.php';
		$hasFeatured = false;




		// Suppress generation of intermediate image sizes while importing (see wpcUnsetImageSizes).
		add_filter('intermediate_image_sizes_advanced', [$this, 'wpcUnsetImageSizes']);


		// Only iterate when we actually received a media array.
		if (is_array($media)) {
			// Walk each incoming media item for this chunk.
			foreach ($media as $key=>$image) {
				// Skip anything that isn't a property photo (e.g. documents, virtual tours).
				if (isset($image['MediaCategory']) && $image['MediaCategory'] !== 'Property Photo' && $image['MediaCategory'] !== 'Photo') {
					continue;
				}

				// Skip items without a usable image URL.
				if ( empty( $image['MediaURL'] ) ) {
					continue;
				}



				// Build and insert the attachment record pointing at the remote MediaURL.
				if (isset($image['MediaURL'])) {
					$file = $image['MediaURL'];
					$attachment = [
						'guid' => $file,
						'post_status' => 'inherit',
						'post_content' => '',
						'post_parent' => $propertyId,
						'post_mime_type' => $image['MimeType'] ?? 'image/jpeg',
						'post_title' => $image['MediaKey'] ?? '',
					];


					// Create the attachment post; $attachId is an int (0 on failure).
					$attachId = wp_insert_attachment($attachment, $file);
					// wp_insert_attachment() returns 0 (int) on failure, not a
					// WP_Error, so is_wp_error() alone lets a failed insert through
					// and records a 0 — which poisons the gallery and makes the
					// featured-image fallback set_post_thumbnail($id, 0) a no-op.
					// Failed insert (WP_Error or 0) → skip; success → record + wire up the attachment.
					if (is_wp_error($attachId) || ! $attachId) {
						// Insert failed; do not add a 0/invalid id to the gallery.
					} else {
						$mediaHistory[] = 'Media - Added ' . $file . ' as attachment ' . $attachId;
						// Collect the new attachment id for the gallery list.
						$media_attachments[]=$attachId;


						// Let the theme adapter store the image on the property, and flag it as ours.
						$mlsimport->admin->env_data->enviroment_image_save($propertyId, $attachId);
						update_post_meta($attachId, 'is_mlsimport', 1);

						// This item is the chosen featured image → set it as the post thumbnail.
						if ($key===$featuredImageKey){
						
				
						set_post_thumbnail($propertyId, $attachId);
						
						} else {
							// Not the featured image — nothing extra to do.
						}
					}
				} else {
					// No MediaURL key — item skipped.
				}
			}
		} else {
			// $media was not an array — no images to attach.
			$mediaHistory[] = 'Media data is blank - there are no images';
		}

		// Restore normal intermediate-size generation.
		remove_filter('intermediate_image_sizes_advanced', [$this, 'wpcUnsetImageSizes']);

		// Return the accumulated attachment ids (gallery built by the caller).
		return $media_attachments;
		//return implode('</br>', $mediaHistory);
	}


	/**
	 * Unset image sizes
	 *
	 * @param array $sizes The sizes to unset.
	 * @return array The modified sizes array.
	 */
	public function wpcUnsetImageSizes($sizes) {
		// Return an empty size set so WordPress generates no intermediate thumbnails.
		return [];
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
	 * Delete property
	 *
	 * @param int $deleteId The ID of the property to delete.
	 * @param string $ListingKey The listing key of the property.
	 */
	public function deleteProperty($deleteId, $ListingKey) {
		// Only act on a valid post ID.
		if ($deleteId > 0) {
			// Record the deletion in the activity log before removing anything.
			mlsimport_record_activity( 'deleted', $deleteId, get_post_meta($deleteId,'ListingKey',true), intval(get_post_meta($deleteId,'MLSimport_item_inserted',true)), 'import' );
			// Gather all child attachments of this property.
			$args = [
				'numberposts' => -1,
				'post_type' => 'attachment',
				'post_parent' => $deleteId,
				'post_status' => null,
				'orderby' => 'menu_order',
				'order' => 'ASC',
			];
			$postAttachments = get_posts($args);

			// Delete each attachment post.
			foreach ($postAttachments as $attachment) {
				wp_delete_post($attachment->ID);
			}

			// Delete the property post itself and bump the telemetry counter.
			wp_delete_post($deleteId);
			mlsimport_telemetry_bump( 'deleted' );
			$logEntry = 'Property with id ' . $deleteId . ' and ' . $ListingKey . ' was deleted on ' . current_time('Y-m-d\TH:i') . PHP_EOL;
			$this->writeImportLogs($logEntry, 'delete');
		}
	}




	/**
	 * Return array with title items
	 *
	 * @param string $string The input string.
	 * @param string $start The start delimiter.
	 * @param string $end The end delimiter.
	 * @param bool $includeDelimiters Whether to include the delimiters in the result.
	 * @param int $offset The offset to start searching from.
	 * @return array The array of strings found between the delimiters.
	 */
	public function strBetweenAll(string $string, string $start, string $end, bool $includeDelimiters = false, int &$offset = 0): array {
		$strings = [];
		$length = strlen($string);

		// Repeatedly extract the next delimited substring until none remain.
		while ($offset < $length) {
			$found = $this->strBetween($string, $start, $end, $includeDelimiters, $offset);
			// No further match → stop.
			if ($found === null) {
				break;
			}

			$strings[] = $found;
			$offset += strlen($includeDelimiters ? $found : $start . $found . $end); // move offset to the end of the newfound string
		}

		return $strings;
	}

	/**
	 * Find string between delimiters
	 *
	 * @param string $string The input string.
	 * @param string $start The start delimiter.
	 * @param string $end The end delimiter.
	 * @param bool $includeDelimiters Whether to include the delimiters in the result.
	 * @param int $offset The offset to start searching from.
	 * @return string|null The string found between the delimiters, or null if not found.
	 */
	public function strBetween(string $string, string $start, string $end, bool $includeDelimiters = false, int &$offset = 0): ?string {
		// Empty inputs cannot yield a match.
		if ($string === '' || $start === '' || $end === '') {
			return null;
		}

		$startLength = strlen($start);
		$endLength = strlen($end);

		// Locate the start delimiter from the current offset.
		$startPos = strpos($string, $start, $offset);
		if ($startPos === false) {
			return null;
		}

		// Locate the end delimiter after the start delimiter.
		$endPos = strpos($string, $end, $startPos + $startLength);
		if ($endPos === false) {
			return null;
		}

		// Compute the substring length (with or without delimiters).
		$length = $endPos - $startPos + ($includeDelimiters ? $endLength : -$startLength);
		// Empty span between adjacent delimiters.
		if (!$length) {
			return '';
		}

		// Advance offset to the substring start and slice it out.
		$offset = $startPos + ($includeDelimiters ? 0 : $startLength);

		return substr($string, $offset, $length);
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
 * Prepare to import per item
 *
 * @param array $property The property data.
 * @param array $itemIdArray The item ID array.
 * @param string $tipImport The import type.
 * @param array $mlsimportItemOptionData The item option data.
 */
public function mlsimportSaasPrepareToImportPerItem($property, $itemIdArray, $tipImport, $mlsimportItemOptionData) {
	// Pre-execution memory optimization
	wp_cache_flush();
	gc_collect_cycles();
	
	// Temporarily disable WordPress hooks that might add to memory usage
	// (saved into $saved_filters and restored at the end of the method).
	global $wp_filter;
	$saved_filters = array();
	if (isset($wp_filter['transition_post_status'])) {
		$saved_filters['transition_post_status'] = $wp_filter['transition_post_status'];
		unset($wp_filter['transition_post_status']);
	}
	if (isset($wp_filter['save_post'])) {
		$saved_filters['save_post'] = $wp_filter['save_post'];
		$wp_filter['save_post'] = new WP_Hook();
	}
	// Allow unlimited execution time for a potentially slow import.
	set_time_limit(0);
	global $mlsimport;

	// Log initial memory
	$memStart = memory_get_usage(true);
	$memStartMB = round($memStart / 1048576, 2);

	// Unpack the per-task configuration (which statuses to keep, author/agent, post status).
	$mlsImportItemStatus 		= $mlsimportItemOptionData['mlsimport_item_standardstatus'];
	$newAuthor 					= intval($mlsimportItemOptionData['mlsimport_item_property_user']);
	// Task has no assign-to-user set → fall back to the task's own author so listings are never saved with post_author 0.
	if (0 === $newAuthor) {
		$newAuthor = intval(get_post_field('post_author', $itemIdArray['item_id']));
	}
	$newAgent 					= $mlsimportItemOptionData['mlsimport_item_agent'];
	$useMlsAgent 				= ! empty($mlsimportItemOptionData['mlsimport_item_use_mls_agent']);
	$propertyStatus 			= $mlsimportItemOptionData['mlsimport_item_property_status'];

	// Normalise the configured statuses to space-free enum keys for comparison.
	if (is_array($mlsImportItemStatus)) {
		$mlsImportItemStatus = array_map('mlsimport_normalize_status_enum', $mlsImportItemStatus);
	}

	// A listing with no ListingKey cannot be identified/imported → abort this item.
	if (!isset($property['ListingKey']) || empty($property['ListingKey'])) {
		$this->writeImportLogs('ERROR: No Listing Key ' . PHP_EOL, $tipImport);
		return;
	}

	// Buffer any stray output produced during import (discarded later).
	ob_start();

	// Identify the listing and the target post type.
	$ListingKey 		= $property['ListingKey'];
	$listingPostType 	= $mlsimport->admin->env_data->get_property_post_type();

	// Memory before property ID lookup
	$memBeforeRetrieve = memory_get_usage(true);
	
	// Look up whether this ListingKey already exists (0 if new).
	$propertyId 		= intval($this->mlsimportSaasRetrievePropertyById($ListingKey, $listingPostType));
	
	// Memory after property ID lookup
	$memAfterRetrieve = memory_get_usage(true);

	// Determine the live MLS status (StandardStatus, falling back to extra_meta MlsStatus).
	$status 			= isset($property['StandardStatus']) ? mlsimport_normalize_status_enum($property['StandardStatus']) : mlsimport_normalize_status_enum($property['extra_meta']['MlsStatus']);
	
	$this->writeImportLogs('FIxing: on inserting ' .$status.'-->'.json_encode($mlsImportItemStatus). PHP_EOL, $tipImport);

	// Decide insert vs not, based on existence + status match.
	$isInsert			= $this->shouldInsertProperty($propertyId, $status, $mlsImportItemStatus, $tipImport);

	$log = $this->mlsimportMemUsage() . '==========' . wp_json_encode($mlsImportItemStatus) . '/' . $newAuthor . '/' . $newAgent . '/' . $propertyStatus . '/ We have property with $ListingKey=' . $ListingKey . ' id=' . $propertyId . ' with status ' . $status . ' is insert? ' . $isInsert . PHP_EOL;
	$this->writeImportLogs($log, $tipImport);

	$propertyHistory 	= [];
	$content 			= $property['content'] ?? '';
	$submitTitle 		= $ListingKey;

	// Memory before insert/update
	$memBeforeInsert = memory_get_usage(true);

	$activityAction = '';

	// Incoming MLS modification time (unix). The hourly delta sync uses a
	// rolling 2-hour overlap window, so the SAME listing is returned on
	// several consecutive runs. We compare this against the last value we
	// recorded (mlsimport_synced_mod) to count a listing as "edited" only
	// when it actually changed since our last import — not on every re-touch.
	$incomingMod = isset($property['extra_meta']['ModificationTimestamp'])
		? strtotime((string) $property['extra_meta']['ModificationTimestamp'])
		: 0;
	if (false === $incomingMod) {
		$incomingMod = 0;
	}

	// INSERT branch: create a brand-new property post.
	if ($isInsert === 'yes') {
		$post = [
			'post_title' 	=> $submitTitle,
			'post_content' 	=> $content,
			'post_status' 	=> $propertyStatus,
			'post_type' 	=> $listingPostType,
			'post_author' 	=> $newAuthor,
		];

		$propertyId = wp_insert_post($post);

		// On failure log; on success stamp identifying meta + record the "added" action.
		if (is_wp_error($propertyId)) {
			$this->writeImportLogs('ERROR: on inserting ' . PHP_EOL, $tipImport);
		} else {
			update_post_meta($propertyId, 'ListingKey', $ListingKey);
			update_post_meta($propertyId, 'MLSimport_item_inserted', $itemIdArray['item_id'],);
			$activityAction = 'added';
			// Store the incoming modification time to seed later change detection.
			update_post_meta($propertyId, 'mlsimport_synced_mod', $incomingMod);
			$propertyHistory[] = date('F j, Y, g:i a') . ': We Inserted the property with Default title :  ' . $submitTitle . ' and received id:' . $propertyId;
			mlsimport_telemetry_bump( 'imported' );
		}

		clean_post_cache($propertyId);

	// Not inserting, but the property already exists → update-or-delete branch.
	} elseif ($propertyId !== 0) {

			
		// Memory before checking existing property
		$memBeforeCheck = memory_get_usage(true);

		// Decide whether the existing listing's status still qualifies to be kept.
		$keep = $this->shouldKeepExistingListing($status, $mlsImportItemStatus);


		// Status no longer qualifies → delete the existing property.
		if(!$keep){
			$log = 'Property with ID ' . $propertyId . ' and with name ' . get_the_title($propertyId) . ' has a status of <strong>' . $status . ' </strong> and will be deleted' . PHP_EOL;
	
			// Memory before delete
			$memBeforeDelete = memory_get_usage(true);
			
			$this->deleteProperty($propertyId, $ListingKey);
			
			// Memory after delete
			$memAfterDelete = memory_get_usage(true);
			
			$this->writeImportLogs($log, $tipImport);
		} else {
			// Status still qualifies → update the existing property in place.
			// Memory before updating
			$memBeforeUpdate = memory_get_usage(true);

			$propertyHistory = $this->updateExistingProperty($propertyId, $content, $listingPostType, $newAuthor, $status, $mlsImportItemStatus, $propertyHistory, $tipImport, $ListingKey);

			// Count as "edited" only when the listing actually changed since our
			// last import. A missing incoming timestamp (0) means we can't tell,
			// so fall back to recording the edit.
			$storedMod = (int) get_post_meta($propertyId, 'mlsimport_synced_mod', true);
			if (0 === $incomingMod || $incomingMod > $storedMod) {
				$activityAction = 'edited';
				if ($incomingMod > 0) {
					update_post_meta($propertyId, 'mlsimport_synced_mod', $incomingMod);
				}
			}

			// Memory after updating
			$memAfterUpdate = memory_get_usage(true);
		}
	}

	// Memory after insert/update
	$memAfterInsert = memory_get_usage(true);

	// Guard: without a valid property id there is nothing further to process.
	if ($propertyId === 0) {
		$this->writeImportLogs('ERROR property id is 0' . PHP_EOL, $tipImport);
		return;
	}

	// Memory before processing details
	$memBeforeDetails = memory_get_usage(true);

	// Write meta/taxonomies/media/gallery/title for this property; returns the final title.
	$newTitle = $this->processPropertyDetails($property, $propertyId, $tipImport, $propertyHistory, $newAgent, $itemIdArray, $isInsert, $useMlsAgent);

	if ( $activityAction !== '' ) {
		// MLS # (RESO ListingId) and the raw status, snapshotted from the incoming feed
		// so the history row is identifiable — and so a later delete can carry them forward.
		$activityMlsId  = (string) ( $property['adr_listingid'] ?? ( $property['ListingId'] ?? '' ) );
		$activityStatus = (string) ( $property['StandardStatus'] ?? ( $property['extra_meta']['MlsStatus'] ?? '' ) );
		mlsimport_record_activity( $activityAction, $propertyId, $ListingKey, isset($itemIdArray['item_id']) ? intval($itemIdArray['item_id']) : 0, $tipImport, $activityMlsId, $activityStatus );
	}

	// Memory after processing details
	$memAfterDetails = memory_get_usage(true);

	$log = PHP_EOL . 'Ending on Property ' . $propertyId . ', ListingKey: ' . $ListingKey . ' , is insert? ' . $isInsert . ' with new title: ' . $newTitle . '  ' . PHP_EOL;
	$this->writeImportLogs($log, $tipImport);

	clean_post_cache($propertyId);

	// More aggressive memory cleanup
	// First clear specific large arrays in property data
	// (element-by-element unset of the big sub-arrays before dropping them wholesale).
	if (isset($property['Media']) && is_array($property['Media'])) {
		foreach ($property['Media'] as $key => $media) {
			unset($property['Media'][$key]);
		}
	}
	if (isset($property['extra_meta']) && is_array($property['extra_meta'])) {
		foreach ($property['extra_meta'] as $key => $value) {
			unset($property['extra_meta'][$key]);
		}
	}
	if (isset($property['meta']) && is_array($property['meta'])) {
		foreach ($property['meta'] as $key => $value) {
			unset($property['meta'][$key]);
		}
	}
	if (isset($property['taxonomies']) && is_array($property['taxonomies'])) {
		foreach ($property['taxonomies'] as $key => $value) {
			unset($property['taxonomies'][$key]);
		}
	}
	
	// Then unset the main arrays
	unset($property['Media']);
	unset($property['extra_meta']);
	unset($property['meta']);
	unset($property['taxonomies']);
	unset($property);
	
	// Clear any post caches that might have been created
	clean_post_cache($propertyId);
	
	// Clear other variables that hold large data
	unset($log);
	unset($propertyHistory);
	$GLOBALS['wpdb']->queries = array();
	
	// Clear WordPress specific caches
	wp_cache_delete('get_term_meta', 'terms');  
	wp_cache_delete('terms', 'terms');
	wp_cache_delete('term_meta', 'terms');
	wp_cache_delete('get_terms', 'terms');
	
	// Clear post related caches
	wp_cache_delete('post_meta_' . $propertyId, 'post_meta');
	wp_cache_delete($propertyId, 'posts');
	
	// Force multiple garbage collection cycles
	gc_collect_cycles();
	gc_collect_cycles();
	
	// Close and discard any output buffer content
	ob_end_clean();
	
	// Try to trigger PHP's internal memory cleanup
	// (allocate then free 1MB to nudge the allocator).
	$dummy = str_repeat('x', 1024 * 1024);
	unset($dummy);

	// Final memory usage
	$memEnd = memory_get_usage(true);
	$memEndMB = round($memEnd / 1048576, 2);
	$memDiff = round(($memEnd - $memStart) / 1048576, 2);
	
	// If we see a significant memory increase, log a warning
	// (threshold branch left empty — no warning currently emitted).
	if ($memDiff > 5) {
	}
	
	// Restore WordPress hooks
	// (put back the transition_post_status / save_post callbacks saved earlier).
	global $wp_filter;
	if (!empty($saved_filters)) {
		foreach ($saved_filters as $hook => $filter) {
			$wp_filter[$hook] = $filter;
		}
	}
}


        
        
        

	/**
	 * Check if the property should be inserted
	 *
	 * @param int $propertyId The property ID.
	 * @param string $status The property status.
	 * @param array $mlsImportItemStatus The MLS import item status.
	 * @param string $tipImport The import type.
	 * @return string 'yes' or 'no' indicating if the property should be inserted.
	 */
	private function shouldInsertProperty($propertyId, $status, $mlsImportItemStatus, $tipImport): string{
		$this->writeImportLogs(
			"Checking: on inserting {$propertyId}={$status} vs " . 
			json_encode($mlsImportItemStatus) . " -- {$tipImport}" . PHP_EOL,
			$tipImport
		);


		// Already exists (id != 0) OR no status array configured → do not insert.
		if ($propertyId !== 0 || !is_array($mlsImportItemStatus)) {
			return 'no';
			
		}

		// Fallback set of statuses treated as "active" when no explicit array is given.
		$activeStatuses = [
			'active',
			'active under contract',
			'active with contract',
			'activewithcontract',
			'status',
			'activeundercontract',
			'comingsoon',
			'coming soon',
			'pending'
		];
		// Explicit status array configured → the live status must be in it.
		if(is_array($mlsImportItemStatus)){
			// Case-insensitive check against the configured statuses.
			if (!in_array(strtolower($status), $mlsImportItemStatus, true)) {
				return 'no';
			}

			// Cron path additionally requires a case-sensitive match.
			if ($tipImport === 'cron' && !in_array($status, $mlsImportItemStatus, true)) {
				return 'no';
			}
	
		}else{
			// No configured array → only insert recognised "active" statuses.
			if(!in_array($status, $activeStatuses, true) ){
				return 'no';
			}
		}

		// Passed all checks → insert.
		return 'yes';
	}

	
/**
 * Check for property status against MLS item delete status to see if we keep or delete the listing.
 * @param int $property_id
 * @param string|array $mlsImportItemStatus
 * @return bool True to keep, false to delete
 */
public function check_if_delete_when_status($property_id, $mlsImportItemStatus, $mlsImportItemStatusDelete = null, $mlsImportItemStatusProtect = null) {

    // Resolve the taxonomy field-map, then read the property's current status term.
    $mlsimport_status_tax_map = ( $mlsimport_fields_opt = get_option('mlsimport_admin_fields_select') ) && isset($mlsimport_fields_opt['mls-fields-map-taxonomy'])
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
    $mlsimport_status_tax_map = ( $mlsimport_fields_opt = get_option('mlsimport_admin_fields_select') ) && isset($mlsimport_fields_opt['mls-fields-map-taxonomy'])
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
 * Decide whether to keep an existing listing the (filtered) feed returned again.
 *
 * Uses the live MLS status — the SAME basis shouldInsertProperty() uses to
 * decide an insert. The old code compared the stored property_status taxonomy
 * term instead, which can be empty, remapped, or theme-labeled; when it did not
 * equal the RESO status, keep said "delete" while insert said "yes", producing
 * an add/delete/add cycle on every sync. See issue #152.
 *
 * @param string       $status              Live MLS StandardStatus (lowercased).
 * @param array|string $mlsImportItemStatus Task's selected RESO statuses (lowercased).
 * @return bool True to keep/update, false to delete.
 */
public function shouldKeepExistingListing($status, $mlsImportItemStatus): bool {
    return is_array($mlsImportItemStatus) && in_array($status, $mlsImportItemStatus, true);
}




	
	/**
        * Check if we should keep or delete the listing when still in MLS.
	    * true we keep 
        */
       public function check_if_delete_when_status_when_in_mls($property_id, $mlsimport_item_standardstatus, $mlsimport_item_standardstatusprotect = null) {
           // Resolve the taxonomy field-map, then read the property's current status term.
           $mlsimport_status_tax_map = ( $mlsimport_fields_opt = get_option('mlsimport_admin_fields_select') ) && isset($mlsimport_fields_opt['mls-fields-map-taxonomy'])
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







	/**
	 * Update existing property
	 *
	 * @param int $propertyId The property ID.
	 * @param string $content The post content.
	 * @param string $listingPostType The listing post type.
	 * @param int $newAuthor The new author ID.
	 * @param string $status The property status.
	 * @param array $mlsImportItemStatus The MLS import item status.
	 * @param array $propertyHistory The property history.
	 * @param string $tipImport The import type.
	 * @param string $ListingKey The listing key.
	 * @return array Updated property history.
	 */
	private function updateExistingProperty($propertyId, $content, $listingPostType, $newAuthor, $status, $mlsImportItemStatus, &$propertyHistory, $tipImport, $ListingKey) {


		// Build the update payload (content/type/author; status handled elsewhere).
		$post = [
			'ID' => $propertyId,
			'post_content' => $content,
			'post_type' => $listingPostType,
			'post_author' => $newAuthor,
		];

		$log = 'Property with ID ' . $propertyId . ' and with name ' . get_the_title($propertyId) . ' has a status of <strong>' . $status . '</strong> and will be Edited</br>';
		$this->writeImportLogs($log, $tipImport);

		// Apply the update; log on error, otherwise append a history entry.
		$propertyId = wp_update_post($post);
		if (is_wp_error($propertyId)) {
			$this->writeImportLogs('ERROR: on edit ' . PHP_EOL, $tipImport);
		} else {
			$submitTitle = get_the_title($propertyId);
			$propertyHistory[] = gmdate('F j, Y, g:i a') . ': Property with title: ' . $submitTitle . ', id:' . $propertyId . ', ListingKey:' . $ListingKey . ', Status:' . $status . ' will be edited';
			mlsimport_telemetry_bump( 'updated' );
		}
		clean_post_cache( $propertyId );
		
		return $propertyHistory;
	}

/**
 * Process property details with memory tracking and optimization
 *
 * @param array $property The property data.
 * @param int $propertyId The property ID.
 * @param string $tipImport The import type.
 * @param array $propertyHistory The property history.
 * @param int $newAgent The new agent ID.
 * @param array $itemIdArray The item ID array.
 * @param string $isInsert If is a property insert
 * @param bool $useMlsAgent Whether the task uses the feed's own listing agent.
 */
private function processPropertyDetails($property, $propertyId, $tipImport, &$propertyHistory, $newAgent, $itemIdArray, $isInsert, $useMlsAgent) {
    global $mlsimport, $wpdb;
    


   	// Normalize timestamp fields in extra_meta to format like "May 17, 2025 at 06:26am"
    if (isset($property['extra_meta']) && is_array($property['extra_meta'])) {
        $timestampFields = [
            'StatusChangeTimestamp',
            'STELLAR_BOMDate',
            'PriceChangeTimestamp',
            'PhotosChangeTimestamp',
            'BridgeModificationTimestamp',
            'ModificationTimestamp',
            'OriginalEntryTimestamp',
            'MajorChangeTimestamp'
        ];

        // Reformat each recognised timestamp field into a human-readable string.
        foreach ($timestampFields as $tsField) {
            if (!empty($property['extra_meta'][$tsField])) {
                // Parse the raw value; only rewrite it when parsing succeeds.
                $timestamp = strtotime($property['extra_meta'][$tsField]);
                if ($timestamp !== false) {
                    $property['extra_meta'][$tsField] = gmdate('F j, Y \a\t h:ia', $timestamp);
                }
            }
        }
    }



    // 1. DISABLE AUTOCOMMIT FOR BATCH PROCESSING
    // This reduces memory by preventing DB auto-commits between operations
    if (method_exists($wpdb, 'query')) {
        $wpdb->query('SET autocommit = 0');
    }
    
    // 2. TEMPORARY DISABLE ACTIONS THAT CONSUME MEMORY
    $suspended_actions = [];
    foreach (['save_post', 'added_post_meta', 'updated_post_meta'] as $action) {
        if (has_action($action)) {
            $suspended_actions[$action] = true;
            remove_all_actions($action);
        }
    }
    
    // Initial memory
    $memStart = memory_get_usage(true);
    
    $log = PHP_EOL . $this->mlsimportMemUsage() . '====before tax======' . PHP_EOL;
    $this->writeImportLogs($log, $tipImport);
    
    // 3. OPTIMIZE TAXONOMY PROCESSING
    if (isset($property['taxonomies']) && is_array($property['taxonomies'])) {
        $memBeforeTax = memory_get_usage(true);

        // Load taxonomy mapping options
        $options = get_option('mlsimport_admin_fields_select');
        $theme_schema = mlsimport_hardocde_theme_schema();
        // Map of default-taxonomy-slug => user-chosen-taxonomy-slug.
        $taxonomy_overrides = array();
        if (isset($options['mls-fields-map-taxonomy']) && is_array($options['mls-fields-map-taxonomy'])) {
            // For each user-mapped field, derive its default taxonomy from the schema.
            foreach ($options['mls-fields-map-taxonomy'] as $field_key => $mapped_tax) {
                // Unmapped field → skip.
                if ($mapped_tax === '') {
                    continue;
                }
                // Only fields that are taxonomy-typed in the schema contribute an override.
                if (isset($theme_schema[$field_key]) && isset($theme_schema[$field_key]['type']) &&
                    $theme_schema[$field_key]['type'] === 'taxonomy' && isset($theme_schema[$field_key]['name'])) {
                    $default_tax = $theme_schema[$field_key]['name'];
                    // Record an override only when the mapping actually differs.
                    if ($default_tax !== $mapped_tax) {
                        $taxonomy_overrides[$default_tax] = $mapped_tax;
                    }
                }
            }
        }

        // Theme-agnostic override. The local hardcoded schema above is the
        // WPResidence mapping, so its default-taxonomy slugs do NOT match the
        // taxonomies the server built when a different theme (e.g. Houzez) was
        // used — the slug-based overrides then silently miss. For the core
        // fields that also arrive with a top-level copy, locate the field's
        // value inside the server-built taxonomies and redirect THAT taxonomy to
        // the user's mapped one. Result: "map field -> Category" moves the value
        // out of the server default and into the chosen taxonomy, on any theme.
        $core_field_value_sources = array(
            'StandardStatus' => 'StandardStatus',
            'PropertyType'   => 'adr_type',
            'City'           => 'adr_city',
            'CountyOrParish' => 'adr_county',
        );
        if (isset($options['mls-fields-map-taxonomy']) && is_array($options['mls-fields-map-taxonomy'])) {
            // For each core field, find which server-built taxonomy carries its value.
            foreach ($core_field_value_sources as $reso_field => $top_level_key) {
                $mapped_tax = isset($options['mls-fields-map-taxonomy'][$reso_field]) ? $options['mls-fields-map-taxonomy'][$reso_field] : '';
                // Skip when unmapped or the top-level value is absent/empty.
                if ($mapped_tax === '' || !isset($property[$top_level_key]) || '' === $property[$top_level_key]) {
                    continue;
                }
                // The field's value we expect to find inside a server taxonomy's terms.
                $field_value = trim((string) $property[$top_level_key]);
                // Scan each server taxonomy for a term equal to that value.
                foreach ($property['taxonomies'] as $server_tax => $server_terms) {
                    // Already the target taxonomy → nothing to redirect.
                    if ($server_tax === $mapped_tax) {
                        continue;
                    }
                    // Normalise the server terms to a trimmed string list.
                    $term_list = is_array($server_terms) ? $server_terms : array($server_terms);
                    $term_list = array_map('trim', array_map('strval', $term_list));
                    // Value lives in this taxonomy → redirect it to the user's mapped taxonomy.
                    if (in_array($field_value, $term_list, true)) {
                        $taxonomy_overrides[$server_tax] = $mapped_tax;
                    }
                }
            }
        }

        // Disable term counting temporarily (major memory saver)
        wp_defer_term_counting(true);
        
        remove_filter('get_term_metadata', 'lazyload_term_meta', 10);
        wp_cache_delete('get_ancestors', 'taxonomy');
        
        // Clear existing taxonomies
        $this->mlsimportSaasClearPropertyForTaxonomy($propertyId, $property['taxonomies']);
        
        // 4. PROCESS TAXONOMIES IN CHUNKS
        $taxChunks = array_chunk($property['taxonomies'], 5, true);
        foreach ($taxChunks as $taxChunk) {
            // Assign each taxonomy's terms to the property.
            foreach ($taxChunk as $taxonomy => $term) {
                // Redirect to the user-mapped taxonomy when an override exists.
                if (isset($taxonomy_overrides[$taxonomy])) {
                    $taxonomy = $taxonomy_overrides[$taxonomy];
                }
                wp_cache_delete("{$taxonomy}_term_counts", 'counts');
                // Create/link the terms for this taxonomy on the property.
                $this->mlsimportSaasUpdateTaxonomyForProperty($taxonomy, $propertyId, $term);
                $propertyHistory[] = 'Updated Taxonomy ' . $taxonomy . ' with terms ' . wp_json_encode($term);
                
                // Memory cleanup after each taxonomy
                wp_cache_delete('term_meta', 'terms');
                wp_cache_delete($taxonomy, 'terms');
            }
            
            // 5. FORCE GC AFTER EACH CHUNK
            gc_collect_cycles();
        }
        
        // Restore term filter and clean up
        add_filter('get_term_metadata', 'lazyload_term_meta', 10, 2);
        delete_option('category_children');
        
        // Re-enable term counting
        wp_defer_term_counting(false);
        
        $memAfterTax = memory_get_usage(true);
              //   " MB, Total Diff: " . round(($memAfterTax - $memBeforeTax) / 1048576, 2) . " MB");
    }
    
    // 6. FLUSH SPECIFIC CACHES INSTEAD OF ALL
    // More targeted than wp_cache_flush()
    wp_cache_delete('terms', 'terms');
    wp_cache_delete('term_meta', 'terms');
    wp_cache_delete("post_meta_{$propertyId}", 'post_meta');
    wp_cache_delete($propertyId, 'posts');
    
    // Prepare meta data
    $property = $this->mlsimportSaasPrepareMetaForProperty($property);
    
    // 7. BATCH META UPDATES
    if (isset($property['meta']) && is_array($property['meta'])) {
        $memBeforeMeta = memory_get_usage(true);
        $metaCount = count($property['meta']);

        // Use direct SQL for batch meta updates if many fields
        if ($metaCount > 0 && method_exists($wpdb, 'prepare')) {
            $meta_values = [];
            // Normalise each meta value and build the delete+insert set.
            foreach ($property['meta'] as $metaName => $metaValue) {
                // Arrays become a comma-joined string; scalars get comma spacing normalised.
                if (is_array($metaValue)) {
                    $metaValue = implode(', ', array_map('trim', $metaValue));
                } else {
                    $metaValue = preg_replace('/\s*,\s*/', ', ', trim($metaValue));
                }

                // Build history separately
                $propertyHistory[] = 'Updated Meta ' . $metaName . ' with meta_value ' . $metaValue;

                // First delete existing
                // (clear any prior row so the batch insert doesn't duplicate the key).
                $wpdb->delete(
                    $wpdb->postmeta,
                    ['post_id' => $propertyId, 'meta_key' => $metaName],
                    ['%d', '%s']
                );

                // Collect for batch insert
                $meta_values[] = $wpdb->prepare(
                    "(%d, %s, %s)",
                    $propertyId,
                    $metaName,
                    $metaValue
                );
            }

            // Batch insert all meta at once
            if (!empty($meta_values)) {
                $wpdb->query("INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " .
                             implode(", ", $meta_values));
            }
        } else {
			// Dead code - left intentianaly 
            // Standard approach for fewer meta fields
            foreach ($property['meta'] as $metaName => $metaValue) {
                if (is_array($metaValue)) {
                    $metaValue = implode(', ', array_map('trim', $metaValue));
                } else {
                    $metaValue = preg_replace('/\s*,\s*/', ', ', trim($metaValue));
                }
                update_post_meta($propertyId, $metaName, $metaValue);
                $propertyHistory[] = 'Updated Meta ' . $metaName . ' with meta_value ' . $metaValue;
            }
        }

        $memAfterMeta = memory_get_usage(true);
            //     " MB, Diff: " . round(($memAfterMeta - $memBeforeMeta) / 1048576, 2) . " MB");
    }
    
    // Extra meta processing
    // (theme adapter writes provider-specific extra_meta and may add history rows).
    $extraMetaResult = $mlsimport->admin->env_data->mlsimportSaasSetExtraMeta($propertyId, $property);
    if (isset($extraMetaResult['property_history'])) {
        $propertyHistory = array_merge($propertyHistory, (array)$extraMetaResult['property_history']);
    }
    
    // 8. PROCESS MEDIA IN CHUNKS
    $memBeforeMedia = memory_get_usage(true);


    // Only handle media when the payload carries a Media array.
    if (isset($property['Media']) && is_array($property['Media'])) {
		// Accumulator of newly-attached image ids for the gallery.
		$media_attachments=array();

        $mediaCount = count($property['Media']);

		// Detect if media has changed for existing properties
		// (on insert we always attach; on edit we only refresh when images differ).
		$shouldRefreshMedia = false;
		if ($isInsert === 'no') {
			$shouldRefreshMedia = $this->hasMediaChanged($propertyId, $property['Media']);
			// Changed → delete our old MLS attachments so they can be rebuilt.
			if ($shouldRefreshMedia) {
				$this->writeImportLogs('Media changed for property ' . $propertyId . ', refreshing ' . $mediaCount . ' images', $tipImport);
				$this->deleteExistingMlsAttachments($propertyId);
			} else {
				// Unchanged → leave existing images untouched.
				$this->writeImportLogs('Media unchanged for property ' . $propertyId . ', skipping image refresh', $tipImport);
			}
		}

		// Sort media by Order field if it exists
		// (orders the images ascending so galleries follow the feed's sequence).
		if (isset($property['Media'][0]['Order'])) {
			$order = array_column($property['Media'], 'Order');
			array_multisort($order, SORT_ASC, $property['Media']);
		}

        // Process in chunks of 5
        $mediaChunks = array_chunk($property['Media'], 5,true);
        $mediaHistoryParts = [];

        // Clear original array to free memory
        $originalMedia = $property['Media'];

		// Find featured image in single loop
		// (choose which media item becomes the post thumbnail).
		$featuredImageKey = null;
		$orderOneKey = null;

		// First priority: Look for PreferredPhotoYN = 1
		foreach ($property['Media'] as $key => $mediaItem) {
			// Priority 1: PreferredPhotoYN = 1 (immediate selection)
			if (isset($mediaItem['PreferredPhotoYN']) && $mediaItem['PreferredPhotoYN'] == 1) {
				$featuredImageKey = $key;
				break;
			}


			// Priority 2: Store Order = 1 key for potential use
			if ($orderOneKey === null && isset($mediaItem['Order']) && $mediaItem['Order'] == 1) {
				$orderOneKey = $key;
			}

		}

		// No preferred photo found → fall back to the Order == 1 image.
		if ($featuredImageKey === null && $orderOneKey !== null) {
			$featuredImageKey = $orderOneKey;
		}

		// Use Order = 1 image if no preferred image was found
		// (duplicate of the check above — same effect).
		if ($featuredImageKey === null && $orderOneKey !== null) {
			$featuredImageKey = $orderOneKey;
		}

		// Priority 3: Use first image if nothing else found
		if ($featuredImageKey === null && !empty($property['Media'])) {
			$featuredImageKey = 0;
		}


        // Drop the source Media array (chunks already captured above).
        unset($property['Media']);

		// On insert or media refresh, clear the theme gallery meta before rebuilding it.
		if ($isInsert !== 'no' || $shouldRefreshMedia) {
			delete_post_meta($propertyId, 'fave_property_images');
			delete_post_meta($propertyId, 'REAL_HOMES_property_images');
			delete_post_meta($propertyId, 'wpestate_property_gallery');
		}


        // Attach each chunk of images, threading the growing $media_attachments list.
        foreach ($mediaChunks as $index => $mediaChunk) {
            $media_attachments = $this->mlsimportSassAttachMediaToPost($propertyId, $mediaChunk, $isInsert,$media_attachments,$featuredImageKey, $shouldRefreshMedia);
           // $mediaHistoryParts[] = $chunkHistory;

            // Free memory
            unset($mediaChunk);
            //unset($chunkHistory);
            gc_collect_cycles();

            // Incremental progress report
        }


		// Only rewrite the gallery when we actually (re)built the attachment list
		// (insert or media refresh). On the unchanged-media path $media_attachments
		// is empty, and overwriting would wipe the existing gallery.
		if ($isInsert !== 'no' || $shouldRefreshMedia) {
			// Persist the rebuilt attachment id list as the theme gallery meta.
			$mlsimport->admin->env_data->enviroment_image_save_gallery($propertyId, $media_attachments);

			// Guarantee a featured image. When the feed has no preferred photo
			// (PreferredPhotoYN) and no Order == 1 image — as with AMPRE/PropTx,
			// whose PreferredPhotoYN is empty and Order is not 1-based — fall back
			// to the first attached image so the property always has a thumbnail.
			if ( ! empty( $media_attachments ) && ! has_post_thumbnail( $propertyId ) ) {
				set_post_thumbnail( $propertyId, reset( $media_attachments ) );
			}
		}

        // Combine all chunks
       // $mediaHistory = implode('</br>', $mediaHistoryParts);
       // $propertyHistory = array_merge($propertyHistory, (array)$mediaHistory);
        
        // Clean up
        unset($mediaChunks);
        unset($mediaHistoryParts);
        unset($mediaHistory);
        unset($originalMedia);
    } else {
        // No Media array on the property → fallback call with an empty media set.
        $mediaHistory = $this->mlsimportSassAttachMediaToPost($propertyId, $property['Media'] ?? [], $isInsert,$featuredImageKey);
        $propertyHistory = array_merge($propertyHistory, (array)$mediaHistory);
    }
    

    $memAfterMedia = memory_get_usage(true);
           //  " MB, Diff: " . round(($memAfterMedia - $memBeforeMedia) / 1048576, 2) . " MB");
    
    // Update title
    // (recompute the post title from the template now that meta is in place).
    $newTitle = $this->mlsimportSaasUpdatePropertyTitle($propertyId, $itemIdArray['item_id'], $property);
    $propertyHistory[] = 'Updated title to  ' . $newTitle . '</br>';
    
    // Correlation update
    // (theme adapter wires up agent/user correlation; $useMlsAgent controls source).
    $mlsimport->admin->env_data->correlationUpdateAfter($isInsert, $propertyId, ['use_mls_agent' => $useMlsAgent], $newAgent);
    
    // 9. COMMIT TRANSACTION
    if (method_exists($wpdb, 'query')) {
        $wpdb->query('COMMIT');
        $wpdb->query('SET autocommit = 1');
    }
    
    // Save property history - using direct SQL if history is large
    if (!empty($propertyHistory)) {
        // Only persist history when the history option is enabled (defaults to 1).
        if (intval(get_option('mlsimport-disable-history', 1)) === 1) {
            $propertyHistory[] = '---------------------------------------------------------------</br>';
            // Flatten the history rows into a single HTML string.
            $propertyHistory = implode('</br>', $propertyHistory);
            
            // 10. USE DIRECT SQL FOR LARGE HISTORY
            // (large blobs bypass update_post_meta to avoid its overhead).
            if (strlen($propertyHistory) > 10000 && method_exists($wpdb, 'update')) {
                $wpdb->update(
                    $wpdb->postmeta,
                    ['meta_value' => $propertyHistory],
                    ['post_id' => $propertyId, 'meta_key' => 'mlsimport_property_history'],
                    ['%s'],
                    ['%d', '%s']
                );
            } else {
                update_post_meta($propertyId, 'mlsimport_property_history', $propertyHistory);
            }
        }
    }
    
    // 11. RESTORE ACTIONS
    // (the add/remove pair below merely re-marks the previously-suspended actions).
    if (!empty($suspended_actions)) {
        foreach ($suspended_actions as $action => $true) {
            add_action($action, '_wp_action_exists_' . $action);
            remove_action($action, '_wp_action_exists_' . $action);
        }
    }
    
    // 12. FINAL CLEANUP
    $property = null;
    $propertyHistory = null;
    wp_cache_flush();
    gc_collect_cycles();
    
    // Final memory stats
    $memEnd = memory_get_usage(true);

    return $newTitle;
}


/**
 * Check if incoming MLS media differs from existing MLS-imported attachments.
 *
 * Compares incoming MediaURL values against the GUIDs of existing attachments
 * that have the is_mlsimport meta flag. Uses ID-only queries for memory efficiency.
 *
 * @param int   $propertyId    The property post ID.
 * @param array $incomingMedia Array of media items, each with a 'MediaURL' key.
 * @return bool True if images need refresh, false if unchanged.
 */
private function hasMediaChanged($propertyId, $incomingMedia) {
    // Fetch the ids of the property's existing MLS-imported attachments.
    $existing = get_posts([
        'post_type'   => 'attachment',
        'post_parent' => $propertyId,
        'post_status' => 'inherit',
        'meta_key'    => 'is_mlsimport',
        'meta_value'  => 1,
        'fields'      => 'ids',
        'numberposts' => -1,
    ]);

    // Map existing attachments to their source URLs (stored in the guid).
    $existingUrls = array_map(function ($id) {
        return get_post_field('guid', $id);
    }, $existing);

    // Collect the incoming MediaURL values (dropping empties).
    $incomingUrls = array_filter(array_column($incomingMedia, 'MediaURL'));

    // Sort both lists so the comparison is order-independent.
    sort($existingUrls);
    sort($incomingUrls);

    // Different URL sets → media changed.
    return $existingUrls !== $incomingUrls;
}


/**
 * Delete all MLS-imported attachments for a property.
 *
 * Only deletes attachments that have the is_mlsimport post meta set to 1.
 * Manually uploaded attachments are preserved.
 *
 * @param int $propertyId The property post ID.
 */
private function deleteExistingMlsAttachments($propertyId) {
    // Fetch only attachments flagged as MLS-imported (is_mlsimport = 1).
    $mlsAttachments = get_posts([
        'post_type'   => 'attachment',
        'post_parent' => $propertyId,
        'post_status' => 'inherit',
        'meta_key'    => 'is_mlsimport',
        'meta_value'  => 1,
        'fields'      => 'ids',
        'numberposts' => -1,
    ]);

    // Force-delete each one (removes the underlying file too).
    foreach ($mlsAttachments as $attachId) {
        wp_delete_post($attachId, true);
    }

    // The old featured image is one of the attachments we just deleted, so the
    // stale _thumbnail_id now points at nothing. Clear it — otherwise
    // has_post_thumbnail() stays true and the fresh featured image is never set.
    delete_post_thumbnail($propertyId);
}



}
