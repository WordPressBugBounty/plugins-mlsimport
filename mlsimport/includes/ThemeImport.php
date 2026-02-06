<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Description of ThemeImport
 *
 * @class ThemeImport
 */
class ThemeImport {


	public $theme;
	public $plugin_name;
	public $enviroment;
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
		if ($method !== 'token') {
			if (!self::validateAndRefreshToken()) {
				return 'Token validation failed';
			}
		}
		
		$url = MLSIMPORT_API_URL . $method;
		$headers = ['Content-Type' => 'text/plain'];

		if ($method !== 'token') {
			$token = self::getApiToken();
			$headers = [
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer '.$token,
			];
		}
	
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
	

		$response = $type === 'GET' ? wp_remote_get($url, $args) : wp_remote_post($url, $args);



		if (is_wp_error($response)) {
        	return $response->get_error_message();
		} else {
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

	public static function globalApiRequestSaas($method, $valuesArray, $type = 'GET') {
			global $mlsimport;
			 // Skip validation for token and mls requests
			if ($method !== 'token' && $method !== 'mls') {
				if (!self::validateAndRefreshToken()) {
					return [
						'success' => false,
						'error_message' => 'Token validation failed'
					];
				}
			}

			
			$url = MLSIMPORT_API_URL . $method;

			$headers = [];
			if ($method !== 'token' && $method !== 'mls') {
				$token =  self::getApiToken();
				$headers = [
					'Authorization' => 'Bearer '.$token,
					'Content-Type' => 'application/json',
				];
			}


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
			$response = wp_remote_post($url, $args);



			if (is_wp_error($response)) {
				return [
					'success' => false,
					'error_code' => $response->get_error_code(),
					'error_message' => esc_html($response->get_error_message())
				];
			}

                        $status_code = isset($response['response']['code']) ? intval($response['response']['code']) : 0;
                        $body        = wp_remote_retrieve_body($response);

                        if (200 === $status_code) {
                                $receivedData = json_decode($body, true);
                                return $receivedData;
                        }

                        $error_message = 'Unknown error';
                        $error_code    = $status_code;

                        $decoded_body = json_decode($body, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_body)) {
                                if (isset($decoded_body['error']['message'])) {
                                        $error_message = $decoded_body['error']['message'];
                                        if (isset($decoded_body['error']['code'])) {
                                                $error_code = $decoded_body['error']['code'];
                                        }
                                } elseif (isset($decoded_body['message'])) {
                                        $error_message = $decoded_body['message'];
                                }
                        }

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
		
		// Check if token is expired
		if ($current_time >= $token_expiry) {
			// Token expired, refresh it
			$refresh_result = self::refreshToken();
			
			if (!$refresh_result) {
				return false;
			}
		}
		
		return true;
	}

	private static function refreshToken() {
		global $mlsimport;
		
		// Get credentials for token request
		$options = get_option('mlsimport_admin_options');
		$username = isset($options['mlsimport_username']) ? $options['mlsimport_username'] : '';
		$password = isset($options['mlsimport_password']) ? $options['mlsimport_password'] : '';
		
		if (empty($username) || empty($password)) {
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
		
		if (is_wp_error($response)) {
			return false;
		}
		
		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		
		if (!isset($data['success']) || !$data['success'] || !isset($data['token']) || !isset($data['expires'])) {
			return false;
		}
		
		// Store new token and expiry
		//$mlsimport->admin->mlsimport_saas_store_mls_api_token_transient($data['token']);
		
		$expires_in = $data['expires'] - time();
		set_transient('mlsimport_saas_token', $data['token'], $expires_in);

		update_option('mlsimport_token_expiry', intval($data['expires']));
		
		
		return true;
	}


/**
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
    
    $counterProp = 0;
    $processedData = [];
    
    if (isset($readyToParseArray['data']) && is_array($readyToParseArray['data'])) {
        // Log total items to process
        $totalItems = count($readyToParseArray['data']);
        
	



        // Only keep essential data in memory, discard the rest
        foreach ($readyToParseArray['data'] as $key => $property) {
         

			
			// Save only what's needed from each property
            if (isset($property['ListingKey'])) {
                $processedData[$key] = $property;
            }
            // Remove from original array to free memory
            unset($readyToParseArray['data'][$key]);
        }
        
        // Complete unset of the original array
        unset($readyToParseArray);
        $this->cleanUpMemory();


		$mlsimportItemId = intval($itemIdArray['item_id']);

		$current_prop_value = (int) get_post_meta( $mlsimportItemId, 'mlsimport_progress_properties', true );

        
        // Process each property
        foreach ($processedData as $key => $property) {
            ++$counterProp;
            
            // Memory usage before processing property
            $memoryBefore = memory_get_usage(true);
            $memoryBeforeMB = round($memoryBefore / 1048576, 2);
            
            $listingKey = isset($property['ListingKey']) ? $property['ListingKey'] : 'unknown';
            
            // Clear out database caches that might be polluted
            wp_cache_delete('mlsimport_force_stop_' . $itemIdArray['item_id'], 'options');
            $GLOBALS['wpdb']->queries = array();
            
            $status = get_option('mlsimport_force_stop_' . $itemIdArray['item_id']);
            
            if ($status === 'no') {

				   
				$current_prop_value = $current_prop_value + 1;
				update_post_meta( $mlsimportItemId, 'mlsimport_progress_properties', $current_prop_value );



                // Process property and track memory
                $this->mlsimportSaasPrepareToImportPerItem($property, $itemIdArray, 'normal', $mlsimportItemOptionData);
                
                // Memory after processing property
                $memoryAfter = memory_get_usage(true);
                $memoryAfterMB = round($memoryAfter / 1048576, 2);
                $memoryDiff = round(($memoryAfter - $memoryBefore) / 1048576, 2);
                
                
                // Check for memory leak pattern
                if ($memoryDiff > 10) {
                    // Force cleanup on large increases
                    $this->cleanUpMemory(true);
                }
                
                // Aggressively clean after each property
                unset($property);
                
                // Periodic more intensive cleanup
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
                update_post_meta($itemIdArray['item_id'], 'mlsimport_spawn_status', 'completed');
                break;
            }
            
            // Clear property from processed data to free memory
            unset($processedData[$key]);
        }
    } else {
    }
    
    // Final cleanup
    unset($processedData);
    $this->cleanUpMemory(true);
    
    // Log final memory stats
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
        foreach ($taxonomies as $taxonomy) {
            wp_cache_delete($taxonomy . '_relationships', 'terms');
        }
        
        // Clear WordPress database cache
        global $wpdb;
        if (is_object($wpdb)) {
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
            'mlsimport_item_standardstatusdelete'  => get_post_meta($itemIdArray['item_id'], 'mlsimport_item_standardstatusdelete', true),
            'mlsimport_item_property_user'         => get_post_meta($itemIdArray['item_id'], 'mlsimport_item_property_user', true),
            'mlsimport_item_agent'                 => get_post_meta($itemIdArray['item_id'], 'mlsimport_item_agent', true),
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
		if ($query->have_posts()) {
			$query->the_post();
			$propertyId = get_the_ID();
			wp_reset_postdata();
			return $propertyId;
		} else {
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
		if (is_array($taxonomies)) {
			foreach ($taxonomies as $taxonomy => $term) {
				if (is_wp_error($taxonomy)) {
				
					continue; // Skip this iteration
				}
				
				if (taxonomy_exists($taxonomy)) {
					wp_delete_object_term_relationships($propertyId, $taxonomy);
				} else {
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

		foreach (array_chunk($fieldValues, 5) as $chunk) {
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
					if (is_null($term)) {
						// Insert the term if it doesn't exist
						$wpdb->insert($wpdb->terms, [
							'name' => $value,
							'slug' => sanitize_title($value),
							'term_group' => 0
						]);

						$termId = $wpdb->insert_id;

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

					if (!empty($termTaxonomyId)) {
						// Insert term relationship
						$wpdb->replace($wpdb->term_relationships, [
							'object_id' => $propertyId,
							'term_taxonomy_id' => $termTaxonomyId
						]);
						// Increment the term count
						$wpdb->query($wpdb->prepare(
							"UPDATE $wpdb->term_taxonomy SET count = count + 1 WHERE term_taxonomy_id = %d",
							$termTaxonomyId
						));
					} else {
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

		$titleFormat = esc_html(get_post_meta($mlsImportPostId, 'mlsimport_item_title_format', true));

		if ('' === $titleFormat) {
			$options = get_option('mlsimport_admin_mls_sync');
			$titleFormat = $options['title_format'];
		}

		$titleArray = $this->strBetweenAll($titleFormat, '{', '}');

		$propertyExtraMetaArrayLowerCase = array_change_key_case($property['extra_meta'], CASE_LOWER);

		foreach ($titleArray as $key => $value) {
			$replace = '';
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
			$titleFormat = str_replace('{' . $value . '}', $replace, $titleFormat);
		}

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
		if (isset($property['extra_meta']['BathroomsTotalDecimal']) && floatval($property['extra_meta']['BathroomsTotalDecimal']) > 0) {
			$bathrooms = floatval($property['extra_meta']['BathroomsTotalDecimal']);
			$property['meta']['property_bathrooms'] = $bathrooms;
			$property['meta']['fave_property_bathrooms'] = $bathrooms;
			$property['meta']['REAL_HOMES_property_bathrooms'] = $bathrooms;
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
	public function mlsimportSassAttachMediaToPost($propertyId, $media, $isInsert,$media_attachments,$featuredImageKey) {

		$mediaHistory = [];

		if ($isInsert === 'no') {
			$mediaHistory[] = 'Media - We have edit - images are not replaced';
			return $media_attachments;
			//return implode('</br>', $mediaHistory);
		}

		global $mlsimport;
		include_once ABSPATH . 'wp-admin/includes/image.php';
		$hasFeatured = false;

	


		add_filter('intermediate_image_sizes_advanced', [$this, 'wpcUnsetImageSizes']);

		
		if (is_array($media)) {
			foreach ($media as $key=>$image) {
				if (isset($image['MediaCategory']) && $image['MediaCategory'] !== 'Property Photo' && $image['MediaCategory'] !== 'Photo') {
					continue;
				}

				if ( empty( $image['MediaURL'] ) ) {
					continue;
				}



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
			
	
					$attachId = wp_insert_attachment($attachment, $file);
					if (is_wp_error($attachId)) {
					} else {
						$mediaHistory[] = 'Media - Added ' . $file . ' as attachment ' . $attachId;
						$media_attachments[]=$attachId;


						$mlsimport->admin->env_data->enviroment_image_save($propertyId, $attachId);
						update_post_meta($attachId, 'is_mlsimport', 1);
						
						if ($key===$featuredImageKey){
						
				
						set_post_thumbnail($propertyId, $attachId);
						
						} else {
						}
					}
				} else {
				}
			}
		} else {
			$mediaHistory[] = 'Media data is blank - there are no images';
		}

		remove_filter('intermediate_image_sizes_advanced', [$this, 'wpcUnsetImageSizes']);

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
		$blogusers = get_users(['blog_id' => 1, 'orderby' => 'nicename']);
		foreach ($blogusers as $user) {
			$userOptions .= '<option value="' . esc_attr($user->ID) . '"';
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
		$args = [
			'post_type' => $mlsimport->admin->env_data->get_agent_post_type(),
			'post_status' => 'publish',
			'posts_per_page' => 150,
		];

		$agentSelection = new WP_Query($args);
		$agentOptions = '<option value=""></option>';

		while ($agentSelection->have_posts()) {
			$agentSelection->the_post();
			$agentId = get_the_ID();

			$agentOptions .= '<option value="' . esc_attr($agentId) . '"';
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
		if ($deleteId > 0) {
			$args = [
				'numberposts' => -1,
				'post_type' => 'attachment',
				'post_parent' => $deleteId,
				'post_status' => null,
				'orderby' => 'menu_order',
				'order' => 'ASC',
			];
			$postAttachments = get_posts($args);

			foreach ($postAttachments as $attachment) {
				wp_delete_post($attachment->ID);
			}

			wp_delete_post($deleteId);
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

		while ($offset < $length) {
			$found = $this->strBetween($string, $start, $end, $includeDelimiters, $offset);
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
		if ($string === '' || $start === '' || $end === '') {
			return null;
		}

		$startLength = strlen($start);
		$endLength = strlen($end);

		$startPos = strpos($string, $start, $offset);
		if ($startPos === false) {
			return null;
		}

		$endPos = strpos($string, $end, $startPos + $startLength);
		if ($endPos === false) {
			return null;
		}

		$length = $endPos - $startPos + ($includeDelimiters ? $endLength : -$startLength);
		if (!$length) {
			return '';
		}

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

               $postType = get_post_type($deleteId);
               $propertyPostType = '';
               if (isset($mlsimport->admin->env_data) && method_exists($mlsimport->admin->env_data, 'get_property_post_type')) {
                       $propertyPostType = $mlsimport->admin->env_data->get_property_post_type();
               }

               if ($postType === $propertyPostType || in_array($postType, ['estate_property', 'property'])) {
                       // Delete attachments using WordPress functions so the files are removed as well
                       $attachments = get_posts([
                               'numberposts' => -1,
                               'post_type'   => 'attachment',
                               'post_parent' => $deleteId,
                               'post_status' => null,
                               'fields'      => 'ids',
                       ]);

                       foreach ($attachments as $attachmentId) {
                               wp_delete_attachment($attachmentId, true);
                       }

                       $termObjList   = get_the_terms($deleteId, 'property_status');
                       $deleteIdStatus = join(', ', wp_list_pluck($termObjList, 'name'));

                       $ListingKey = get_post_meta($deleteId, 'ListingKey', true);
                       if ('' === $ListingKey) { // manually added listing
                               $logEntry = 'User added listing with id ' . $deleteId . ' (' . $postType . ') (status ' . $deleteIdStatus . ') and ' . $ListingKey . ' NOT DELETED' . PHP_EOL;
                               $this->writeImportLogs($logEntry, 'delete');
                               return;
                       }

                       global $wpdb;
                       $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->postmeta WHERE `post_id` = %d", $deleteId));
                       $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->posts WHERE `post_parent` = %d OR `ID` = %d", $deleteId, $deleteId));

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
	set_time_limit(0);
	global $mlsimport;

	// Log initial memory
	$memStart = memory_get_usage(true);
	$memStartMB = round($memStart / 1048576, 2);

	$mlsImportItemStatus 		= $mlsimportItemOptionData['mlsimport_item_standardstatus'];
	$mlsImportItemStatusDelete 	= $mlsimportItemOptionData['mlsimport_item_standardstatusdelete'];
	$newAuthor 					= $mlsimportItemOptionData['mlsimport_item_property_user'];
	$newAgent 					= $mlsimportItemOptionData['mlsimport_item_agent'];
	$propertyStatus 			= $mlsimportItemOptionData['mlsimport_item_property_status'];

	if (is_array($mlsImportItemStatus)) {
		$mlsImportItemStatus = array_map('strtolower', $mlsImportItemStatus);
	}

	if (!isset($property['ListingKey']) || empty($property['ListingKey'])) {
		$this->writeImportLogs('ERROR: No Listing Key ' . PHP_EOL, $tipImport);
		return;
	}

	ob_start();

	$ListingKey 		= $property['ListingKey'];
	$listingPostType 	= $mlsimport->admin->env_data->get_property_post_type();

	// Memory before property ID lookup
	$memBeforeRetrieve = memory_get_usage(true);
	
	$propertyId 		= intval($this->mlsimportSaasRetrievePropertyById($ListingKey, $listingPostType));
	
	// Memory after property ID lookup
	$memAfterRetrieve = memory_get_usage(true);
	
	$status 			= isset($property['StandardStatus']) ? strtolower($property['StandardStatus']) : strtolower($property['extra_meta']['MlsStatus']);
	
	$this->writeImportLogs('FIxing: on inserting ' .$status.'-->'.json_encode($mlsImportItemStatus). PHP_EOL, $tipImport);

	$isInsert			= $this->shouldInsertProperty($propertyId, $status, $mlsImportItemStatus, $tipImport);

	$log = $this->mlsimportMemUsage() . '==========' . wp_json_encode($mlsImportItemStatus) . '/' . $newAuthor . '/' . $newAgent . '/' . $propertyStatus . '/ We have property with $ListingKey=' . $ListingKey . ' id=' . $propertyId . ' with status ' . $status . ' is insert? ' . $isInsert . PHP_EOL;
	$this->writeImportLogs($log, $tipImport);

	$propertyHistory 	= [];
	$content 			= $property['content'] ?? '';
	$submitTitle 		= $ListingKey;

	// Memory before insert/update
	$memBeforeInsert = memory_get_usage(true);

	if ($isInsert === 'yes') {
		$post = [
			'post_title' 	=> $submitTitle,
			'post_content' 	=> $content,
			'post_status' 	=> $propertyStatus,
			'post_type' 	=> $listingPostType,
			'post_author' 	=> $newAuthor,
		];

		$propertyId = wp_insert_post($post);
	
		if (is_wp_error($propertyId)) {
			$this->writeImportLogs('ERROR: on inserting ' . PHP_EOL, $tipImport);
		} else {
			update_post_meta($propertyId, 'ListingKey', $ListingKey);
			update_post_meta($propertyId, 'MLSimport_item_inserted', $itemIdArray['item_id'],);
			update_post_meta($propertyId, 'mlsImportItemStatusDelete', $mlsImportItemStatusDelete);
			
			$propertyHistory[] = date('F j, Y, g:i a') . ': We Inserted the property with Default title :  ' . $submitTitle . ' and received id:' . $propertyId;
		}

		clean_post_cache($propertyId);

	} elseif ($propertyId !== 0) {

			
		// Memory before checking existing property
		$memBeforeCheck = memory_get_usage(true);

		$keep = $this->check_if_delete_when_status_on_manual_import($propertyId,$mlsImportItemStatus,$mlsImportItemStatusDelete);


		if(!$keep){
			$log = 'Property with ID ' . $propertyId . ' and with name ' . get_the_title($propertyId) . ' has a status of <strong>' . $status . ' </strong> and will be deleted' . PHP_EOL;
	
			// Memory before delete
			$memBeforeDelete = memory_get_usage(true);
			
			$this->deleteProperty($propertyId, $ListingKey);
			
			// Memory after delete
			$memAfterDelete = memory_get_usage(true);
			
			$this->writeImportLogs($log, $tipImport);
		} else {	
			update_post_meta($propertyId, 'mlsImportItemStatusDelete', $mlsImportItemStatusDelete);
			
			// Memory before updating
			$memBeforeUpdate = memory_get_usage(true);
			
			$propertyHistory = $this->updateExistingProperty($propertyId,$mlsImportItemStatusDelete, $content, $listingPostType, $newAuthor, $status, $mlsImportItemStatus, $propertyHistory, $tipImport, $ListingKey);
			
			// Memory after updating
			$memAfterUpdate = memory_get_usage(true);
		}
	}

	// Memory after insert/update
	$memAfterInsert = memory_get_usage(true);

	if ($propertyId === 0) {
		$this->writeImportLogs('ERROR property id is 0' . PHP_EOL, $tipImport);
		return;
	}

	// Memory before processing details
	$memBeforeDetails = memory_get_usage(true);

	$newTitle = $this->processPropertyDetails($property, $propertyId, $tipImport, $propertyHistory, $newAgent, $itemIdArray,$isInsert);

	// Memory after processing details
	$memAfterDetails = memory_get_usage(true);

	$log = PHP_EOL . 'Ending on Property ' . $propertyId . ', ListingKey: ' . $ListingKey . ' , is insert? ' . $isInsert . ' with new title: ' . $newTitle . '  ' . PHP_EOL;
	$this->writeImportLogs($log, $tipImport);

	clean_post_cache($propertyId);

	// More aggressive memory cleanup
	// First clear specific large arrays in property data
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
	$dummy = str_repeat('x', 1024 * 1024);
	unset($dummy);

	// Final memory usage
	$memEnd = memory_get_usage(true);
	$memEndMB = round($memEnd / 1048576, 2);
	$memDiff = round(($memEnd - $memStart) / 1048576, 2);
	
	// If we see a significant memory increase, log a warning
	if ($memDiff > 5) {
	}
	
	// Restore WordPress hooks
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


		if ($propertyId !== 0 || !is_array($mlsImportItemStatus)) {
			return 'no';
			
		}

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
		if(is_array($mlsImportItemStatus)){
			if (!in_array(strtolower($status), $mlsImportItemStatus, true)) {
				return 'no';
			}
	
			if ($tipImport === 'cron' && !in_array($status, $mlsImportItemStatus, true)) {
				return 'no';
			}
	
		}else{
			if(!in_array($status, $activeStatuses, true) ){
				return 'no';
			}
		}
	
		return 'yes';
	}

	
/**
 * Check for property status against MLS item delete status to see if we keep or delete the listing.
 * @param int $property_id
 * @param string|array $mlsImportItemStatus
 * @param string|array $mlsImportItemStatusDelete
 * @return bool True to keep, false to delete
 */
public function check_if_delete_when_status($property_id, $mlsImportItemStatus, $mlsImportItemStatusDelete) {
    // Normalize status arrays/strings to lowercase
    $mlsImportItemStatus = is_array($mlsImportItemStatus)
        ? array_map('strtolower', $mlsImportItemStatus)
        : strtolower($mlsImportItemStatus);

    $mlsImportItemStatusDelete = is_array($mlsImportItemStatusDelete)
        ? array_map('strtolower', $mlsImportItemStatusDelete)
        : strtolower($mlsImportItemStatusDelete);

    // Get post_status based on post type/taxonomy
    $post_status = '';
    if (post_type_exists('estate_property')) {
        $terms = get_the_terms($property_id, 'property_status');
        if (!empty($terms) && is_array($terms)) {
            $post_status = strtolower($terms[0]->name);
        }
    } elseif (post_type_exists('property') && taxonomy_exists('property_label')) {
        $terms = get_the_terms($property_id, 'property_label');
        if (!empty($terms) && is_array($terms)) {
            $post_status = strtolower($terms[0]->name);
        }
    } else {
        $post_status = strtolower(get_post_meta($property_id, 'inspiry_property_label', true));
    }


    // Keep if status matches "keep" status
    /* deactivated becausee we should check only delete stautuses not import statuse
 	if ((is_array($mlsImportItemStatus) && in_array($post_status, $mlsImportItemStatus, true)) ||
        (!is_array($mlsImportItemStatus) && $post_status === $mlsImportItemStatus)) {
        return true;
    }
	*/

    // Delete if status matches "delete" status
    if (!empty($mlsImportItemStatusDelete)) {
        if ((is_array($mlsImportItemStatusDelete) && in_array($post_status, $mlsImportItemStatusDelete, true)) ||
            (!is_array($mlsImportItemStatusDelete) && $post_status === $mlsImportItemStatusDelete)) {
            return false;
        }
    }

    // Default: keep
    return true;
}




public function check_if_delete_when_status_on_manual_import($property_id, $mlsImportItemStatus, $mlsImportItemStatusDelete) {
    // Normalize status arrays/strings to lowercase
    $mlsImportItemStatus = is_array($mlsImportItemStatus)
        ? array_map('strtolower', $mlsImportItemStatus)
        : strtolower($mlsImportItemStatus);

    $mlsImportItemStatusDelete = is_array($mlsImportItemStatusDelete)
        ? array_map('strtolower', $mlsImportItemStatusDelete)
        : strtolower($mlsImportItemStatusDelete);

    // Get post_status based on post type/taxonomy
    $post_status = '';
    if (post_type_exists('estate_property')) {
        $terms = get_the_terms($property_id, 'property_status');
        if (!empty($terms) && is_array($terms)) {
            $post_status = strtolower($terms[0]->name);
        }
    } elseif (post_type_exists('property') && taxonomy_exists('property_label')) {
        $terms = get_the_terms($property_id, 'property_label');
        if (!empty($terms) && is_array($terms)) {
            $post_status = strtolower($terms[0]->name);
        }
    } else {
        $post_status = strtolower(get_post_meta($property_id, 'inspiry_property_label', true));
    }



    // Keep if status matches "keep" status
 	if ((is_array($mlsImportItemStatus) && in_array($post_status, $mlsImportItemStatus, true)) ||
        (!is_array($mlsImportItemStatus) && $post_status === $mlsImportItemStatus)) {
		
        return true;
    }



    // Default: keep
    return false;
}




	
	/**
        * Check if we should keep or delete the listing when still in MLS.
	    * true we keep 
        */
       public function check_if_delete_when_status_when_in_mls($property_id,$mlsimport_item_standardstatus) {
           // Get the inserted item and its MLS standard status

        

           // Default to false
           $post_status = '';

           // Check for post status based on post type/taxonomy
           if (post_type_exists('estate_property')) {
               // WPResidence
               $terms = get_the_terms($property_id, 'property_status');
               if (!empty($terms) && is_array($terms)) {
                   $post_status = $terms[0]->name;
               }
           } elseif (post_type_exists('property') && taxonomy_exists('property_label')) {
               // Houzez
               $terms = get_the_terms($property_id, 'property_label');
               if (!empty($terms) && is_array($terms)) {
                   $post_status = $terms[0]->name;
               }
           } else {
               // RealHomes
               $post_status = get_post_meta($property_id, 'inspiry_property_label', true);
           }

           // Early return if MLS status empty
           if (empty($mlsimport_item_standardstatus)) {
               return true; // default: keep if no status set
           }

           // If it's an array, check for post status in it; otherwise, compare string
           if (is_array($mlsimport_item_standardstatus)) {
               return in_array($post_status, $mlsimport_item_standardstatus, true);
           }
           return $post_status === $mlsimport_item_standardstatus;
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
	private function updateExistingProperty($propertyId,$mlsImportItemStatusDelete, $content, $listingPostType, $newAuthor, $status, $mlsImportItemStatus, &$propertyHistory, $tipImport, $ListingKey) {
		
		
		$post = [
			'ID' => $propertyId,
			'post_content' => $content,
			'post_type' => $listingPostType,
			'post_author' => $newAuthor,
		];

		$log = 'Property with ID ' . $propertyId . ' and with name ' . get_the_title($propertyId) . ' has a status of <strong>' . $status . '</strong> and will be Edited</br>';
		$this->writeImportLogs($log, $tipImport);

		$propertyId = wp_update_post($post);
		if (is_wp_error($propertyId)) {
			$this->writeImportLogs('ERROR: on edit ' . PHP_EOL, $tipImport);
		} else {
			$submitTitle = get_the_title($propertyId);
			$propertyHistory[] = gmdate('F j, Y, g:i a') . ': Property with title: ' . $submitTitle . ', id:' . $propertyId . ', ListingKey:' . $ListingKey . ', Status:' . $status . ' will be edited';
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
 */
private function processPropertyDetails($property, $propertyId, $tipImport, &$propertyHistory, $newAgent, $itemIdArray, $isInsert) {
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

        foreach ($timestampFields as $tsField) {
            if (!empty($property['extra_meta'][$tsField])) {
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
        $taxonomy_overrides = array();
        if (isset($options['mls-fields-map-taxonomy']) && is_array($options['mls-fields-map-taxonomy'])) {
            foreach ($options['mls-fields-map-taxonomy'] as $field_key => $mapped_tax) {
                if ($mapped_tax === '') {
                    continue;
                }
                if (isset($theme_schema[$field_key]) && isset($theme_schema[$field_key]['type']) &&
                    $theme_schema[$field_key]['type'] === 'taxonomy' && isset($theme_schema[$field_key]['name'])) {
                    $default_tax = $theme_schema[$field_key]['name'];
                    if ($default_tax !== $mapped_tax) {
                        $taxonomy_overrides[$default_tax] = $mapped_tax;
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
            foreach ($taxChunk as $taxonomy => $term) {
                if (isset($taxonomy_overrides[$taxonomy])) {
                    $taxonomy = $taxonomy_overrides[$taxonomy];
                }
                wp_cache_delete("{$taxonomy}_term_counts", 'counts');
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
        if ($metaCount > 20 && method_exists($wpdb, 'prepare')) {
            $meta_values = [];
            foreach ($property['meta'] as $metaName => $metaValue) {
                if (is_array($metaValue)) {
                    $metaValue = implode(', ', array_map('trim', $metaValue));
                } else {
                    $metaValue = preg_replace('/\s*,\s*/', ', ', trim($metaValue));
                }

                // Build history separately
                $propertyHistory[] = 'Updated Meta ' . $metaName . ' with meta_value ' . $metaValue;
                
                // First delete existing
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
    $extraMetaResult = $mlsimport->admin->env_data->mlsimportSaasSetExtraMeta($propertyId, $property);
    if (isset($extraMetaResult['property_history'])) {
        $propertyHistory = array_merge($propertyHistory, (array)$extraMetaResult['property_history']);
    }
    
    // 8. PROCESS MEDIA IN CHUNKS
    $memBeforeMedia = memory_get_usage(true);
    

    if (isset($property['Media']) && is_array($property['Media'])) {
		$media_attachments=array();

        $mediaCount = count($property['Media']);
        


		// Sort media by Order field if it exists
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

		if ($featuredImageKey === null && $orderOneKey !== null) {
			$featuredImageKey = $orderOneKey;
		}

		// Use Order = 1 image if no preferred image was found
		if ($featuredImageKey === null && $orderOneKey !== null) {
			$featuredImageKey = $orderOneKey;
		}

		// Priority 3: Use first image if nothing else found
		if ($featuredImageKey === null && !empty($property['Media'])) {
			$featuredImageKey = 0;
		}


        unset($property['Media']);

		if ($isInsert !== 'no') {
			delete_post_meta($propertyId, 'fave_property_images');
			delete_post_meta($propertyId, 'REAL_HOMES_property_images');
			delete_post_meta($propertyId, 'wpestate_property_gallery');
		}

        
        foreach ($mediaChunks as $index => $mediaChunk) {
            $media_attachments = $this->mlsimportSassAttachMediaToPost($propertyId, $mediaChunk, $isInsert,$media_attachments,$featuredImageKey);
           // $mediaHistoryParts[] = $chunkHistory;
            
            // Free memory
            unset($mediaChunk);
            //unset($chunkHistory);
            gc_collect_cycles();
            
            // Incremental progress report
        }
        

		$mlsimport->admin->env_data->enviroment_image_save_gallery($propertyId, $media_attachments);
	
        // Combine all chunks
       // $mediaHistory = implode('</br>', $mediaHistoryParts);
       // $propertyHistory = array_merge($propertyHistory, (array)$mediaHistory);
        
        // Clean up
        unset($mediaChunks);
        unset($mediaHistoryParts);
        unset($mediaHistory);
        unset($originalMedia);
    } else {
        $mediaHistory = $this->mlsimportSassAttachMediaToPost($propertyId, $property['Media'] ?? [], $isInsert,$featuredImageKey);
        $propertyHistory = array_merge($propertyHistory, (array)$mediaHistory);
    }
    

    $memAfterMedia = memory_get_usage(true);
           //  " MB, Diff: " . round(($memAfterMedia - $memBeforeMedia) / 1048576, 2) . " MB");
    
    // Update title
    $newTitle = $this->mlsimportSaasUpdatePropertyTitle($propertyId, $itemIdArray['item_id'], $property);
    $propertyHistory[] = 'Updated title to  ' . $newTitle . '</br>';
    
    // Correlation update
    $mlsimport->admin->env_data->correlationUpdateAfter($isInsert, $propertyId, [], $newAgent);
    
    // 9. COMMIT TRANSACTION
    if (method_exists($wpdb, 'query')) {
        $wpdb->query('COMMIT');
        $wpdb->query('SET autocommit = 1');
    }
    
    // Save property history - using direct SQL if history is large
    if (!empty($propertyHistory)) {
        if (intval(get_option('mlsimport-disable-history', 1)) === 1) {
            $propertyHistory[] = '---------------------------------------------------------------</br>';
            $propertyHistory = implode('</br>', $propertyHistory);
            
            // 10. USE DIRECT SQL FOR LARGE HISTORY
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





}
