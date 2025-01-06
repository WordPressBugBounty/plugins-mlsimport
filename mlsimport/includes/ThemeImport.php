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
		$url = MLSIMPORT_API_URL . $method;
		$headers = ['Content-Type' => 'text/plain'];

		if ($method !== 'token') {
			$token = self::getApiToken();
			$headers = [
				'Content-Type' => 'application/json',
				'authorizationToken' => $token,
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
			$url = MLSIMPORT_API_URL . $method;

			$headers = [];
			if ($method !== 'token' && $method !== 'mls') {
				$token =  self::getApiToken();
				$headers = [
					'authorizationToken' => $token,
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

			if (isset($response['response']['code']) && $response['response']['code'] === 200) {
				$receivedData = json_decode(wp_remote_retrieve_body($response), true);
				return $receivedData;
			} else {
				return ['success' => false];
			}

			exit();
	}








	/**
	 * Parse Result Array
	 *
	 * @param array $readyToParseArray The array ready to be parsed.
	 * @param array $itemIdArray The item ID array.
	 * @param string $batchKey The batch key.
	 * @param array $mlsimportItemOptionData The item option data.
	 */
		
	public function mlsimportSaasParseSearchArrayPerItem($readyToParseArray, $itemIdArray, $batchKey, $mlsimportItemOptionData) {
		$logs = '';

		wp_cache_flush();
		gc_collect_cycles();
		$counterProp = 0;

		if (isset($readyToParseArray['data'])) {
			foreach ($readyToParseArray['data'] as $key => $property) {
				++$counterProp;

				$logs = $this->mlsimportMemUsage() . '=== In parse search array, listing no ' . $key . ' from batch ' . $batchKey . ' with ListingKey: ' . $property['ListingKey'] . PHP_EOL;
				$this->writeImportLogs($logs, 'import');

				wp_cache_delete('mlsimport_force_stop_' . $itemIdArray['item_id'], 'options');

				$status = get_option('mlsimport_force_stop_' . $itemIdArray['item_id']);
				$logs = $this->mlsimportMemUsage() . ' / on Batch ' . $itemIdArray['batch_counter'] . ', Item ID: ' . $itemIdArray['item_id'] . '/' . $counterProp . ' check ListingKey ' . $property['ListingKey'] . ' - stop command issued ? ' . $status . PHP_EOL;
				$this->writeImportLogs($logs, 'import');

				if ($status === 'no') {
					$logs = 'Will proceed to import - Memory Used ' . $this->mlsimportMemUsage() . PHP_EOL;
					$this->writeImportLogs($logs, 'import');
					$this->mlsimportSaasPrepareToImportPerItem($property, $itemIdArray, 'normal', $mlsimportItemOptionData);
				} else {
					update_post_meta($itemIdArray['item_id'], 'mlsimport_spawn_status', 'completed');
				}
				unset($logs);
			}
		}

		unset($readyToParseArray);
		unset($logs);
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
	 * Parse Result Array in CRON
	 *
	 * @param array $readyToParseArray The array ready to be parsed.
	 * @param array $itemIdArray The item ID array.
	 * @param string $batchKey The batch key.
	 */
	public function mlsimportSaasCronParseSearchArrayPerItem($readyToParseArray, $itemIdArray, $batchKey) {
		$mlsimportItemOptionData = [
			'mlsimport_item_standardstatus' 		=> get_post_meta($itemIdArray['item_id'], 'mlsimport_item_standardstatus', true),
			'mlsimport_item_standardstatusdelete'	=> get_post_meta($itemIdArray['item_id'], 'mlsimport_item_standardstatusdelete', true),
			'mlsimport_item_property_user' 			=> get_post_meta($itemIdArray['item_id'], 'mlsimport_item_property_user', true),
			'mlsimport_item_agent' 					=> get_post_meta($itemIdArray['item_id'], 'mlsimport_item_agent', true),
			'mlsimport_item_property_status' 		=> get_post_meta($itemIdArray['item_id'], 'mlsimport_item_property_status', true),
		];

		foreach ($readyToParseArray['data'] as $key => $property) {
			$logs = 'In CRON parse search array, listing no ' . $key . ' from batch ' . $batchKey . ' with ListingKey: ' . $property['ListingKey'] . PHP_EOL;
			$this->writeImportLogs($logs, 'cron');
			$this->mlsimportSaasPrepareToImportPerItem($property, $itemIdArray, 'cron', $mlsimportItemOptionData);
		}
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
					error_log('Error with taxonomy: ' . $taxonomy->get_error_message());
					continue; // Skip this iteration
				}
				
				if (taxonomy_exists($taxonomy)) {
					wp_delete_object_term_relationships($propertyId, $taxonomy);
				} else {
					error_log("Taxonomy does not exist: {$taxonomy}");
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
	public function mlsimportSassAttachMediaToPost($propertyId, $media, $isInsert) {
		$mediaHistory = [];
		if ($isInsert === 'no') {
			$mediaHistory[] = 'Media - We have edit - images are not replaced';
			return implode('</br>', $mediaHistory);
		}

		global $mlsimport;
		include_once ABSPATH . 'wp-admin/includes/image.php';
		$hasFeatured = false;

		delete_post_meta($propertyId, 'fave_property_images');
		delete_post_meta($propertyId, 'REAL_HOMES_property_images');

		add_filter('intermediate_image_sizes_advanced', [$this, 'wpcUnsetImageSizes']);

		// Sorting media
		if (isset($media[0]['Order'])) {
			$order = array_column($media, 'Order');
			array_multisort($order, SORT_ASC, $media);
		}

		if (is_array($media)) {
			foreach ($media as $image) {
				if (isset($image['MediaCategory']) && $image['MediaCategory'] !== 'Photo') {
					continue;
				}

				$file = $image['MediaURL'];

				if (isset($image['MediaURL'])) {
					$attachment = [
						'guid' => $image['MediaURL'],
						'post_status' => 'inherit',
						'post_content' => '',
						'post_parent' => $propertyId,
						'post_mime_type' => $image['MimeType'] ?? 'image/jpg',
						'post_title' => $image['MediaKey'] ?? '',
					];

					$attachId = wp_insert_attachment($attachment, $file);

					$mediaHistory[] = 'Media - Added ' . $image['MediaURL'] . ' as attachment ' . $attachId;
					$mlsimport->admin->env_data->enviroment_image_save($propertyId, $attachId);

					update_post_meta($attachId, 'is_mlsimport', 1);
					if (!$hasFeatured) {
						set_post_thumbnail($propertyId, $attachId);
						$hasFeatured = true;
					}
				}
			}
		} else {
			$mediaHistory[] = 'Media data is blank - there are no images';
		}

		remove_filter('intermediate_image_sizes_advanced', [$this, 'wpcUnsetImageSizes']);

		return implode('</br>', $mediaHistory);
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
		$postType = get_post_type($deleteId);

		if (in_array($postType, ['estate_property', 'property'])) {
			$termObjList = get_the_terms($deleteId, 'property_status');
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
		set_time_limit(0);
		global $mlsimport;

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
		$propertyId 		= intval($this->mlsimportSaasRetrievePropertyById($ListingKey, $listingPostType));
		$status 			= isset($property['StandardStatus']) ? strtolower($property['StandardStatus']) : strtolower($property['extra_meta']['MlsStatus']);
		
		
		$this->writeImportLogs('FIxing: on inserting ' .$status.'-->'.json_encode($mlsImportItemStatus). PHP_EOL, $tipImport);


		$isInsert			= $this->shouldInsertProperty($propertyId, $status, $mlsImportItemStatus, $tipImport);

		$log = $this->mlsimportMemUsage() . '==========' . wp_json_encode($mlsImportItemStatus) . '/' . $newAuthor . '/' . $newAgent . '/' . $propertyStatus . '/ We have property with $ListingKey=' . $ListingKey . ' id=' . $propertyId . ' with status ' . $status . ' is insert? ' . $isInsert . PHP_EOL;
		$this->writeImportLogs($log, $tipImport);

		$propertyHistory 	= [];
		$content 			= $property['content'] ?? '';
		$submitTitle 		= $ListingKey;

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

				
				
				$propertyHistory[] = date('F j, Y, g:i a') . ': We Inserted the property with Default title :  ' . $submitTitle . ' and received id:' . $propertyId.'. The delete statuses are '.$keep_on_delete;
			}

			clean_post_cache( $propertyId );

		} elseif ($propertyId !== 0) {

			$keep = $this->check_if_delete_when_status($property_id);

			if(!$keep){
				$log = 'Property with ID ' . $propertyId . ' and with name ' . get_the_title($propertyId) . ' has a status of <strong>' . $status . ' / '.$post_status.'</strong> and will be deleted' . PHP_EOL;
				$this->deleteProperty($propertyId, $ListingKey);
				$this->writeImportLogs($log, $tipImport);
			}else{	
				update_post_meta($propertyId, 'mlsImportItemStatusDelete', $mlsImportItemStatusDelete);
				$propertyHistory = $this->updateExistingProperty($propertyId,$mlsImportItemStatusDelete, $content, $listingPostType, $newAuthor, $status, $mlsImportItemStatus, $propertyHistory, $tipImport, $ListingKey);
			}
		}






		if ($propertyId === 0) {
			$this->writeImportLogs('ERROR property id is 0' . PHP_EOL, $tipImport);
			return;
		}

		$newTitle = $this->processPropertyDetails($property, $propertyId, $tipImport, $propertyHistory, $newAgent, $itemIdArray,$isInsert);

		$log = PHP_EOL . 'Ending on Property ' . $propertyId . ', ListingKey: ' . $ListingKey . ' , is insert? ' . $isInsert . ' with new title: ' . $newTitle . '  ' . PHP_EOL;
		$this->writeImportLogs($log, $tipImport);

		clean_post_cache( $propertyId );

		ob_end_clean();
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
	 * Check for property status agains mls item delete status to see if we keep or delete the listing
	 *
	 * 
	 */
	public function check_if_delete_when_status($property_id){
		$delete_status= get_post_meta( $property_id, 'mlsImportItemStatusDelete', true );
	

		if (post_type_exists('estate_property')) {
			// wpresidence
			$termObjList = get_the_terms($property_id, 'property_status');
				
				if(isset($termObjList) && is_array($termObjList)){
						$post_status = $termObjList[0]->name;
				}
		
				print 'from wpresidence : '.$post_status.' | ';
                          
		}else if(post_type_exists('property') && taxonomy_exists('property_label')){
			// houzez
			$termObjList = get_the_terms($property_id, 'property_label');
                        
				if(isset($termObjList) && is_array($termObjList)){
						$post_status = $termObjList[0]->name;
				}
				print 'from houzez : '.$post_status.' | ';
		} else {
			//real homes
			$post_status = get_post_meta( $property_id, 'inspiry_property_label', true );
			print 'from real homes : '.$post_status.' | ';
		}

		$keep=true;
		if(!empty($delete_status)){
			
			if(is_array($delete_status) && in_array($post_status, $delete_status)){
				$keep=false;
			
			}else if($post_status==$delete_status){
				$keep=false;
			}

		}

		print  wp_kses_post('</br></br>' .$property_id. ' ------------------------- Check keep when not found: '.$property_id.' /'.$post_status.'<-</br>');
		var_dump($delete_status);
		print '</br>';

		return $keep;
	}




	/**
	 * Check for property status agains mls item standart status to see if we keep or delete the listing 
	 * Function is used when we detect the property still exist in mls
	 *
	 * 
	 */

	 public function check_if_delete_when_status_when_in_mls($property_id){
		   
		$MLSimport_item_inserted = get_post_meta( $property_id, 'MLSimport_item_inserted', true );
		$mlsimport_item_standardstatus= get_post_meta( $MLSimport_item_inserted, 'mlsimport_item_standardstatus', true );
	 
		if (post_type_exists('estate_property')) {
			// wpresidence
			$termObjList = get_the_terms($property_id, 'property_status');
						
			if(isset($termObjList) && is_array($termObjList)){
				$post_status = $termObjList[0]->name;
			}
	
			print 'from wpresidence : '.$post_status.' | ';
						
		}else if(post_type_exists('property') && taxonomy_exists('property_label')){
			// houzez
			$termObjList = get_the_terms($property_id, 'property_label');
			
			if(isset($termObjList) && is_array($termObjList)){
					$post_status = $termObjList[0]->name;
			}
			print 'from houzez : '.$post_status.' | ';
		} else {
			//real homes
			$post_status = get_post_meta( $property_id, 'inspiry_property_label', true );
			print 'from real homes : '.$post_status.' | ';
		}


		var_dump($mlsimport_item_standardstatus); 
		print '****';


		$keep=true;
		if(!empty($mlsimport_item_standardstatus)){
			
			if(is_array($mlsimport_item_standardstatus) && in_array($post_status, $mlsimport_item_standardstatus)){
				$keep=true;
			
			}else if($post_status==$mlsimport_item_standardstatus){
				$keep=true;
			}else{
				$keep=false;
			}

		}

		print  wp_kses_post('</br></br>' .$property_id. ' Property Found in mls - check keep when found: '.$property_id.' /post_status: '.$post_status.'<-</br>');
		var_dump($mlsimport_item_standardstatus);
		print '</br>';

		return $keep;
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
	 * Process property details
	 *
	 * @param array $property The property data.
	 * @param int $propertyId The property ID.
	 * @param string $tipImport The import type.
	 * @param array $propertyHistory The property history.
	 * @param int $newAgent The new agent ID.
	 * @param array $itemIdArray The item ID array.
	 * @param string $isInsert If is a property insert
	 */
	private function processPropertyDetails($property, $propertyId, $tipImport, &$propertyHistory, $newAgent, $itemIdArray,	$isInsert) {
		global $mlsimport;
		$log = PHP_EOL . $this->mlsimportMemUsage() . '====before tax======' . PHP_EOL;
		$this->writeImportLogs($log, $tipImport);

		if (isset($property['taxonomies']) && is_array($property['taxonomies'])) {
			remove_filter('get_term_metadata', 'lazyload_term_meta', 10);
			wp_cache_delete('get_ancestors', 'taxonomy');

			$this->mlsimportSaasClearPropertyForTaxonomy($propertyId, $property['taxonomies']);

			foreach ($property['taxonomies'] as $taxonomy => $term) {
				wp_cache_delete("{$taxonomy}_term_counts", 'counts');
				$this->mlsimportSaasUpdateTaxonomyForProperty($taxonomy, $propertyId, $term);
				$propertyHistory[] = 'Updated Taxonomy ' . $taxonomy . ' with terms ' . wp_json_encode($term);
			}

			add_filter('get_term_metadata', 'lazyload_term_meta', 10, 2);
			delete_option('category_children');
		}

		wp_cache_flush();

		$property = $this->mlsimportSaasPrepareMetaForProperty($property);

		if (isset($property['meta']) && is_array($property['meta'])) {
			foreach ($property['meta'] as $metaName => $metaValue) {
				if (is_array($metaValue)) {
					$metaValue = implode(',', $metaValue);
				}
				update_post_meta($propertyId, $metaName, $metaValue);
				$propertyHistory[] = 'Updated Meta ' . $metaName . ' with meta_value ' . $metaValue;
			}
		}

		$extraMetaResult = $mlsimport->admin->env_data->mlsimportSaasSetExtraMeta($propertyId, $property);
		if (isset($extraMetaResult['property_history'])) {
			$propertyHistory = array_merge($propertyHistory, (array)$extraMetaResult['property_history']);
		}

		$mediaHistory = $this->mlsimportSassAttachMediaToPost($propertyId, $property['Media'], $isInsert);
		$propertyHistory = array_merge($propertyHistory, (array)$mediaHistory);

		$newTitle = $this->mlsimportSaasUpdatePropertyTitle($propertyId, $itemIdArray['item_id'], $property);
		$propertyHistory[] = 'Updated title to  ' . $newTitle . '</br>';

		$mlsimport->admin->env_data->correlationUpdateAfter($isInsert, $propertyId, [], $newAgent);

		if (!empty($propertyHistory)) {
			if (intval(get_option('mlsimport-disable-history', 1)) === 1) {
				$propertyHistory[] = '---------------------------------------------------------------</br>';
				$propertyHistory = implode('</br>', $propertyHistory);
				update_post_meta($propertyId, 'mlsimport_property_history', $propertyHistory);
			}
		}

		return $newTitle;
	}




}
