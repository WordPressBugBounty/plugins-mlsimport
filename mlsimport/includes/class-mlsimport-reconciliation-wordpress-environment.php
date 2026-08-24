<?php
/**
 * WordPress infrastructure adapter for the deep reconciliation module.
 *
 * The policy and run sequence live in Mlsimport_Reconciliation. This adapter
 * contains the system-boundary work that policy needs: the singleton option
 * lock, import/sync activity detection, SaaS snapshot callback, batched SQL
 * reads of Managed Listings, raw-SQL deletion cleanup, activity recording, and
 * deduplicated WordPress retry scheduling.
 *
 * Keeping these operations behind the module's environment contract lets tests
 * exercise the one public reconciliation seam while production retains the raw
 * SQL performance required for large listing inventories.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-reconciliation.php';

/**
 * Connects the reconciliation policy to WordPress and MLSImport infrastructure.
 */
final class Mlsimport_Reconciliation_WordPress_Environment implements Mlsimport_Reconciliation_Environment {
	private const LOCK_OPTION = 'mlsimport_reconciliation_running';
	private const RETRY_HOOK  = 'mlsimport_reconciliation_retry_event';
	private const READ_BATCH  = 1000;

	/**
	 * Callback that performs the external SaaS request.
	 *
	 * @var callable
	 */
	private $snapshot_fetcher;

	/**
	 * Unique token owned by this environment after a successful lock claim.
	 *
	 * @var string
	 */
	private $lock_token = '';

	/**
	 * Receive the SaaS fetch at the external API boundary.
	 *
	 * @param callable $snapshot_fetcher Returns the raw reconciliation response.
	 */
	public function __construct( callable $snapshot_fetcher ) {
		$this->snapshot_fetcher = $snapshot_fetcher;
	}

	/**
	 * Atomically claim the singleton run lock with add_option().
	 *
	 * @return bool True only for the process that created the option.
	 */
	public function acquire_lock(): bool {
		$this->lock_token = wp_generate_uuid4();
		if ( add_option( self::LOCK_OPTION, $this->lock_token, '', false ) ) {
			return true;
		}

		$this->lock_token = '';
		return false;
	}

	/**
	 * Release only the lock token owned by this environment instance.
	 *
	 * @return void
	 */
	public function release_lock(): void {
		if ( '' !== $this->lock_token && get_option( self::LOCK_OPTION ) === $this->lock_token ) {
			delete_option( self::LOCK_OPTION );
		}
		$this->lock_token = '';
	}

	/**
	 * Detect the existing hourly-sync lock or any manual import still started.
	 *
	 * @return bool True when reconciliation must postpone.
	 */
	public function import_or_sync_is_active(): bool {
		if ( get_transient( 'mlsimport_cron_running' ) ) {
			return true;
		}

		global $wpdb;
		// Intentional uncached lock-state read at the infrastructure boundary.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_import = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT posts.ID
				 FROM {$wpdb->posts} posts
				 INNER JOIN {$wpdb->postmeta} state
				    ON posts.ID = state.post_id AND state.meta_key = %s
				 WHERE posts.post_type = 'mlsimport_item'
				   AND posts.post_status != 'trash'
				   AND state.meta_value = %s
				 LIMIT 1",
				'mlsimport_spawn_status',
				'started'
			)
		);

		return ! empty( $active_import );
	}

	/**
	 * Fetch the raw response from the configured SaaS boundary.
	 *
	 * @return array<string, mixed> Raw snapshot response.
	 * @throws RuntimeException When the boundary does not return an array.
	 */
	public function fetch_snapshot(): array {
		$result = call_user_func( $this->snapshot_fetcher );
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( 'Reconciliation snapshot response was not an array.' );
		}
		return $result;
	}

	/**
	 * Read all active Managed Listings in stable primary-key batches.
	 *
	 * Ownership requires the creating `MLSimport_item_inserted` marker. Draft and
	 * trash posts are excluded in SQL. The creating task and its Protected
	 * Statuses are joined into each row; status is read only when protection
	 * exists because unprotected absence needs no status lookup.
	 *
	 * @return array<int, array<string, mixed>> Complete Managed Listing inventory.
	 * @throws RuntimeException When a database batch cannot be read completely.
	 */
	public function read_managed_listings(): array {
		global $wpdb;

		$listings = array();
		$last_id  = 0;
		$fields   = mlsimport_active_field_configuration();
		$tax_map  = isset( $fields['mls-fields-map-taxonomy'] ) ? $fields['mls-fields-map-taxonomy'] : array();

		do {
			$wpdb->last_error = '';
			// Intentional batched raw read; ADR-0010 keeps this path SQL-first.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT posts.ID,
					        listing_key.meta_value AS listing_key,
					        owner.meta_value AS import_task_id,
					        task.ID AS readable_task_id,
					        MAX(protection.meta_value) AS protected_statuses
					 FROM {$wpdb->posts} posts
					 INNER JOIN {$wpdb->postmeta} listing_key
					    ON posts.ID = listing_key.post_id AND listing_key.meta_key = %s
					 INNER JOIN {$wpdb->postmeta} owner
					    ON posts.ID = owner.post_id AND owner.meta_key = %s AND owner.meta_value != ''
					 LEFT JOIN {$wpdb->posts} task
					    ON task.ID = CAST(owner.meta_value AS UNSIGNED)
					   AND task.post_type = 'mlsimport_item' AND task.post_status != 'trash'
					 LEFT JOIN {$wpdb->postmeta} protection
					    ON protection.post_id = task.ID AND protection.meta_key = %s
					 WHERE posts.ID > %d
					   AND posts.post_status NOT IN ('draft', 'trash')
					 GROUP BY posts.ID, listing_key.meta_value, owner.meta_value, task.ID
					 ORDER BY posts.ID ASC
					 LIMIT %d",
					'ListingKey',
					'MLSimport_item_inserted',
					'mlsimport_item_standardstatusprotect',
					$last_id,
					self::READ_BATCH
				),
				ARRAY_A
			);

			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				throw new RuntimeException( 'Unable to read the complete Managed Listing inventory.' );
			}

			foreach ( $rows as $row ) {
				$last_id   = (int) $row['ID'];
				$protected = maybe_unserialize( $row['protected_statuses'] );
				$protected = is_array( $protected ) ? $protected : ( '' === (string) $protected ? array() : array( $protected ) );
				$protected = array_values( array_filter( array_map( 'mlsimport_normalize_status_enum', $protected ) ) );

				$status = '';
				if ( ! empty( $protected ) ) {
					$status = mlsimport_read_property_status( $last_id, $tax_map );
				}

				$listings[] = array(
					'id'                 => $last_id,
					'listing_key'        => (string) $row['listing_key'],
					'import_task_id'     => (int) $row['import_task_id'],
					'import_task_exists' => ! empty( $row['readable_task_id'] ),
					'protected_statuses' => $protected,
					'status_readable'    => empty( $protected ) || '' !== $status,
					'status'             => $status,
				);
			}
			$row_count = count( $rows );
		} while ( self::READ_BATCH === $row_count );

		return $listings;
	}

	/**
	 * Delete one validated Managed Listing with the intentional raw-SQL path.
	 *
	 * Attachments and term relationships use WordPress cleanup APIs. Plugin-owned
	 * standalone rows, comments, postmeta, child posts, and the property post are
	 * then removed before a successful Import History row is recorded.
	 *
	 * @param int    $listing_id  Property post ID.
	 * @param string $listing_key Expected ListingKey from the completed plan.
	 * @param string $reason      Stable successful-deletion reason code.
	 * @return bool True only when the property post was removed.
	 */
	public function delete_managed_listing( int $listing_id, string $listing_key, string $reason ): bool {
		global $mlsimport, $wpdb;

		$post_type = get_post_type( $listing_id );
		$owner_id  = (int) get_post_meta( $listing_id, 'MLSimport_item_inserted', true );
		$live_key  = (string) get_post_meta( $listing_id, 'ListingKey', true );
		if ( ! $post_type || $owner_id <= 0 || '' === $live_key || $live_key !== $listing_key ) {
			return false;
		}

		$allowed_types = array( 'estate_property', 'property', 'mlsimport_property' );
		if ( isset( $mlsimport->admin->env_data ) && method_exists( $mlsimport->admin->env_data, 'get_property_post_type' ) ) {
			$allowed_types[] = $mlsimport->admin->env_data->get_property_post_type();
		}
		if ( ! in_array( $post_type, array_unique( $allowed_types ), true ) ) {
			return false;
		}

		$attachments = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'attachment',
				'post_parent' => $listing_id,
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);
		foreach ( $attachments as $attachment_id ) {
			if ( false === wp_delete_attachment( (int) $attachment_id, true ) ) {
				return false;
			}
		}

		if ( class_exists( 'Mlsimport_Standalone_Row' ) ) {
			Mlsimport_Standalone_Row::purge_post_relations( $listing_id );
		}
		wp_delete_object_term_relationships( $listing_id, get_object_taxonomies( $post_type ) );

		// Intentional raw cleanup sequence required by ADR-0008.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$comments_deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE commentmeta FROM {$wpdb->commentmeta} commentmeta
				 INNER JOIN {$wpdb->comments} comments ON commentmeta.comment_id = comments.comment_ID
				 WHERE comments.comment_post_ID = %d",
				$listing_id
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$comments = $wpdb->delete( $wpdb->comments, array( 'comment_post_ID' => $listing_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$meta = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d OR post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d)", $listing_id, $listing_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$posts = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_parent = %d OR ID = %d", $listing_id, $listing_id ) );

		if ( false === $comments_deleted || false === $comments || false === $meta || false === $posts || $posts < 1 ) {
			return false;
		}

		clean_post_cache( $listing_id );
		mlsimport_record_activity( 'deleted', $listing_id, $listing_key, $owner_id, 'reconciliation', '', '', $reason );
		mlsimport_telemetry_bump( 'deleted' );
		return true;
	}

	/**
	 * Schedule one deduplicated single retry after the requested delay.
	 *
	 * @param int $delay_seconds Delay from current time.
	 * @return void
	 */
	public function schedule_retry( int $delay_seconds ): void {
		if ( false === wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + $delay_seconds, self::RETRY_HOOK );
		}
	}
}
