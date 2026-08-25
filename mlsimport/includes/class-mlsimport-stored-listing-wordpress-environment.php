<?php
/**
 * WordPress persistence boundary for the Stored Listing Write module.
 *
 * This class translates the module's create/update/delete, required fields,
 * transaction, media, Import History, and extension-event decisions into
 * WordPress operations. It never selects a theme and never reads the global
 * MLSImport admin object; the configured adapter is injected beside it.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-stored-listing-media.php';
require_once __DIR__ . '/class-mlsimport-stored-listing-title.php';

/**
 * Perform WordPress operations requested by one listing-level write.
 */
final class Mlsimport_Stored_Listing_WordPress_Environment {

	/** @var Mlsimport_Stored_Listing_Media Staged media implementation. */
	private $media;

	/** @var Mlsimport_Stored_Listing_Title Shared title-token resolver. */
	private $title;

	/** @var array<int, int> Post IDs whose caches need cleaning after a transaction. */
	private $touched_ids = array();

	/**
	 * Assemble the concrete WordPress helpers owned by the module.
	 */
	public function __construct() {
		$this->media = new Mlsimport_Stored_Listing_Media();
		$this->title = new Mlsimport_Stored_Listing_Title();
	}

	/**
	 * Find a Managed Listing and the versions needed by the unchanged check.
	 *
	 * @param string $listing_key Stable MLS identity.
	 * @param string $post_type   Configured theme property post type.
	 * @return array<string, mixed>|null Existing listing snapshot, or null.
	 */
	public function find_listing( string $listing_key, string $post_type ): ?array {
		$ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_mlsimport_listing_key',
				'meta_value'     => $listing_key,
				'no_found_rows'  => true,
			)
		);
		if ( empty( $ids ) ) {
			return null;
		}

		$listing_id = (int) reset( $ids );
		$post       = get_post( $listing_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$modification = (string) get_post_meta( $listing_id, 'mlsimport_stored_modification_timestamp', true );
		if ( '' === $modification ) {
			$legacy_mod = get_post_meta( $listing_id, 'mlsimport_synced_mod', true );
			if ( is_numeric( $legacy_mod ) && (int) $legacy_mod > 0 ) {
				$modification = gmdate( 'c', (int) $legacy_mod );
			}
		}

		return array(
			'id'                     => $listing_id,
			'listing_key'            => $listing_key,
			'post_type'              => $post_type,
			'post_status'            => (string) $post->post_status,
			'user_id'                => (int) $post->post_author,
			'content'                => (string) $post->post_content,
			'modification_timestamp' => $modification,
			'config_version'         => (string) get_post_meta( $listing_id, 'mlsimport_stored_write_config_version', true ),
		);
	}

	/**
	 * Permanently delete one excluded Managed Listing through WordPress hooks.
	 *
	 * @param array<string, mixed> $listing Existing listing snapshot.
	 * @return bool Whether WordPress deleted the post.
	 */
	public function delete_listing( array $listing ): bool {
		return false !== wp_delete_post( (int) ( $listing['id'] ?? 0 ), true );
	}

	/**
	 * Create the base post and stamp its stable identity and owning Import Task.
	 *
	 * @param array<string, mixed> $listing Creation-time values from the module.
	 * @return int New post ID, or zero when WordPress rejects it.
	 */
	public function create_listing( array $listing ): int {
		// GitHub issue #286: a listing without its stable identity can never be
		// found by a later import and would duplicate forever. Refuse the write.
		if ( '' === (string) ( $listing['listing_key'] ?? '' ) ) {
			return 0;
		}
		$listing_id = wp_insert_post(
			array(
				'post_title'   => (string) $listing['listing_key'],
				'post_content' => (string) $listing['content'],
				'post_status'  => (string) $listing['post_status'],
				'post_type'    => (string) $listing['post_type'],
				'post_author'  => (int) $listing['user_id'],
			),
			true
		);
		if ( is_wp_error( $listing_id ) || ! $listing_id ) {
			return 0;
		}

		$listing_id = (int) $listing_id;
		$this->touched_ids[] = $listing_id;
		// GitHub issue #286: identity lives in protected (underscore) meta so no
		// theme custom-field save or Custom Fields box edit can blank it. The
		// legacy visible 'ListingKey' row is intentionally no longer written.
		update_post_meta( $listing_id, '_mlsimport_listing_key', (string) $listing['listing_key'] );
		update_post_meta( $listing_id, 'MLSimport_item_inserted', (int) ( $listing['task_id'] ?? 0 ) );
		return $listing_id;
	}

	/**
	 * Apply only update-time post fields, preserving status and Assigned Agent.
	 *
	 * @param array<string, mixed> $existing Existing listing snapshot.
	 * @param array<string, mixed> $changes  Live User, content, and display choice.
	 * @return bool Whether WordPress accepted the update.
	 */
	public function update_listing( array $existing, array $changes ): bool {
		$listing_id = (int) ( $existing['id'] ?? 0 );
		$result     = wp_update_post(
			array(
				'ID'           => $listing_id,
				'post_content' => (string) $changes['content'],
				'post_author'  => (int) $changes['user_id'],
			),
			true
		);
		$this->touched_ids[] = $listing_id;
		return ! is_wp_error( $result ) && $listing_id === (int) $result;
	}

	/**
	 * Start the database unit that protects required post/meta/taxonomy changes.
	 */
	public function begin_write(): void {
		global $wpdb;
		$this->touched_ids = array();
		$wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Persist normalized core meta, mapped fields, taxonomies, title, and versions.
	 *
	 * @param int                  $listing_id Managed Listing post ID.
	 * @param array<string, mixed> $property   Incoming raw property.
	 * @param array<string, mixed> $projection Shared Field Configuration projection.
	 * @param array<string, mixed> $settings   Task title and version values.
	 * @return bool Whether all required WordPress operations succeeded.
	 */
	public function write_required_data( int $listing_id, array $property, array $projection, array $settings ): bool {
		$meta = is_array( $property['meta'] ?? null ) ? $property['meta'] : array();
		$bathrooms = $property['extra_meta']['BathroomsTotalDecimal']
			?? ( $property['extra_meta']['BathroomsTotalInteger'] ?? ( $property['extra_meta']['BathroomsFull'] ?? '' ) );
		foreach ( array( 'property_bathrooms', 'fave_property_bathrooms', 'REAL_HOMES_property_bathrooms' ) as $key ) {
			$meta[ $key ] = '' === $bathrooms || null === $bathrooms ? '' : (float) $bathrooms;
		}
		foreach ( $meta as $key => $value ) {
			// API-produced core/theme meta is already shaped for WordPress. Preserve
			// arrays so update_post_meta() retains its normal serialization contract;
			// only selected raw extra_meta uses the Field module's string rules.
			update_post_meta( $listing_id, (string) $key, $value );
		}
		foreach ( (array) ( $projection['post_meta'] ?? array() ) as $key => $value ) {
			update_post_meta( $listing_id, (string) $key, (string) $value );
		}

		$taxonomies = is_array( $property['taxonomies'] ?? null ) ? $property['taxonomies'] : array();
		foreach ( (array) ( $projection['taxonomies'] ?? array() ) as $taxonomy => $terms ) {
			$taxonomies[ $taxonomy ] = array_values( array_unique( array_merge( (array) ( $taxonomies[ $taxonomy ] ?? array() ), (array) $terms ) ) );
		}
		foreach ( $taxonomies as $taxonomy => $terms ) {
			$result = wp_set_object_terms( $listing_id, (array) $terms, (string) $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				return false;
			}
		}

		$title = $this->title->build( (string) ( $settings['title_format'] ?? '' ), $property );
		$title_result = wp_update_post(
			array(
				'ID'         => $listing_id,
				'post_title' => $title,
				'post_name'  => $title,
			),
			true
		);
		if ( is_wp_error( $title_result ) ) {
			return false;
		}

		$modification = (string) ( $property['extra_meta']['ModificationTimestamp'] ?? '' );
		update_post_meta( $listing_id, 'mlsimport_stored_modification_timestamp', $modification );
		update_post_meta( $listing_id, 'mlsimport_synced_mod', '' !== $modification ? (int) strtotime( $modification ) : 0 );
		update_post_meta( $listing_id, 'mlsimport_stored_write_config_version', (string) ( $settings['config_version'] ?? '' ) );
		return true;
	}

	/**
	 * Commit the required database changes and refresh touched post caches.
	 *
	 * @return void
	 */
	public function commit_write(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$this->clean_touched_caches();
	}

	/**
	 * Roll back the required database changes and refresh touched post caches.
	 *
	 * @return void
	 */
	public function rollback_write(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		$this->clean_touched_caches();
	}

	/**
	 * Stage one ordered media replacement through the owned media helper.
	 *
	 * @param int               $listing_id Managed Listing post ID.
	 * @param array<int, mixed> $media      Feed-ordered media rows.
	 * @return array<string, mixed> Staged media result.
	 */
	public function stage_media( int $listing_id, array $media ): array {
		return $this->media->stage( $listing_id, $media );
	}

	/**
	 * Activate the staged featured photo inside the required transaction.
	 *
	 * @param int                  $listing_id Managed Listing post ID.
	 * @param array<string, mixed> $stage      Successful media stage.
	 * @return void
	 */
	public function activate_media( int $listing_id, array $stage ): void {
		$this->media->activate( $listing_id, $stage );
	}

	/**
	 * Delete replaced attachments only after the replacement commits.
	 *
	 * @param int                  $listing_id Managed Listing post ID.
	 * @param array<string, mixed> $stage      Successful media stage.
	 * @return void
	 */
	public function finish_media( int $listing_id, array $stage ): void {
		unset( $listing_id );
		$this->media->finish( $stage );
	}

	/**
	 * Delete staged attachments after the required write rolls back.
	 *
	 * @param array<string, mixed> $stage Failed attempt's media stage.
	 * @return void
	 */
	public function discard_media( array $stage ): void {
		$this->media->discard( $stage );
	}

	/**
	 * Publish the listing-level before extension hook for every attempt.
	 *
	 * @param array<string, mixed> $property Incoming raw property.
	 * @param array<string, mixed> $settings Normalized task settings.
	 * @return void
	 */
	public function before_write( array $property, array $settings ): void {
		do_action( 'mlsimport_stored_listing_write_before', $property, $settings );
	}

	/**
	 * Record Import History and telemetry only after persistence succeeds.
	 *
	 * @param string               $action     Created, updated, or deleted.
	 * @param int                  $listing_id Managed Listing post ID.
	 * @param array<string, mixed> $property   Incoming raw property.
	 * @param array<string, mixed> $settings   Normalized task settings.
	 * @return void
	 */
	public function record_activity( string $action, int $listing_id, array $property, array $settings ): void {
		$history_action = array( 'created' => 'added', 'updated' => 'edited', 'deleted' => 'deleted' )[ $action ] ?? $action;
		mlsimport_record_activity(
			$history_action,
			$listing_id,
			(string) ( $property['ListingKey'] ?? '' ),
			(int) ( $settings['task_id'] ?? 0 ),
			(string) ( $settings['source'] ?? 'manual' ),
			(string) ( $property['adr_listingid'] ?? ( $property['ListingId'] ?? '' ) ),
			(string) ( $property['StandardStatus'] ?? ( $property['extra_meta']['MlsStatus'] ?? '' ) )
		);
		if ( function_exists( 'mlsimport_telemetry_bump' ) ) {
			$telemetry_action = array( 'created' => 'imported', 'updated' => 'edited', 'deleted' => 'deleted' )[ $action ] ?? $action;
			mlsimport_telemetry_bump( $telemetry_action );
		}
	}

	/**
	 * Publish the terminal success, warning, or failure extension hook.
	 *
	 * @param string               $kind     Terminal event kind.
	 * @param array<string, mixed> $result   Public listing result.
	 * @param array<string, mixed> $property Incoming raw property.
	 * @return void
	 */
	public function publish_result( string $kind, array $result, array $property ): void {
		do_action( 'mlsimport_stored_listing_write_' . $kind, $result, $property );
	}

	/**
	 * Clean touched post caches so committed or restored DB state is authoritative.
	 *
	 * @return void
	 */
	private function clean_touched_caches(): void {
		foreach ( array_unique( $this->touched_ids ) as $listing_id ) {
			clean_post_cache( $listing_id );
		}
		$this->touched_ids = array();
	}
}
