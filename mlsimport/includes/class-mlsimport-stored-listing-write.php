<?php
/**
 * Coordinate one Stored mode listing write behind a single public operation.
 *
 * ThemeImport passes one raw MLS property and normalized Import Task settings
 * to this module. The module owns the listing-level decision and returns one
 * terminal outcome; WordPress persistence and genuine theme projection stay
 * behind injected boundaries so the orchestration can be tested independently.
 *
 * Listing Change Events (issue #222): this is the only place where the stored
 * and the incoming price/status coexist, so decide_write() reads the stored
 * signals once, diffs them (Mlsimport_Listing_Events_Diff) and carries the
 * resulting events in the internal result under '_events' — the same way
 * '_activity' travels. write() pops them and hands them to the environment
 * only after a committed outcome; a failed write records nothing. Two markers
 * are resolved by the events store: a create carries 'arrived' (back_on_market
 * or new_listing) and a Closed record for a listing that is not stored carries
 * 'closed_seen' (sold, only right after a Removal).
 *
 * Email notifications: the listing details the notification filter needs
 * (Mlsimport_Listing_Event_Facts) are built once per write from the incoming
 * record, travel beside the events under '_facts', and are saved on the post so
 * the next write can detect a changed agent / office / photos / description.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/mlsimport-status-normalize.php';
require_once __DIR__ . '/class-mlsimport-stored-listing-fields.php';
require_once __DIR__ . '/class-mlsimport-listing-events-diff.php';
require_once __DIR__ . '/class-mlsimport-listing-event-facts.php';
require_once __DIR__ . '/class-mlsimport-listing-notification-matcher.php';

/**
 * Apply one incoming MLS property to local Stored mode data.
 */
final class Mlsimport_Stored_Listing_Write {

	/** @var object WordPress persistence boundary. */
	private $environment;

	/** @var object Explicit adapter for the configured Stored mode theme. */
	private $adapter;

	/** @var Mlsimport_Stored_Listing_Fields Shared Field Configuration projection. */
	private $fields;

	/**
	 * Receive the two explicit dependencies needed by every listing write.
	 *
	 * The environment performs WordPress operations while the adapter contains
	 * only the post type and projection rules that genuinely differ by theme.
	 *
	 * @param object $environment WordPress persistence boundary.
	 * @param object $adapter     Configured Stored mode theme adapter.
	 */
	public function __construct( $environment, $adapter ) {
		$this->environment = $environment;
		$this->adapter     = $adapter;
		$this->fields      = new Mlsimport_Stored_Listing_Fields();
	}

	/**
	 * Produce and publish one terminal result for an incoming MLS property.
	 *
	 * The before event wraps every attempt. The private transition returns only
	 * after its mutation commits or rolls back; successful Import History is then
	 * recorded before one success, warning, or failure extension event is emitted.
	 *
	 * @param array<string, mixed> $property Raw property returned by MLSImport SaaS.
	 * @param array<string, mixed> $settings Normalized Import Task write settings.
	 * @return array<string, mixed> One public Stored Listing Write result.
	 */
	public function write( array $property, array $settings ): array {
		$this->environment->before_write( $property, $settings );

		try {
			$result = $this->decide_write( $property, $settings );
		} catch ( Throwable $exception ) {
			$result = array(
				'outcome'    => 'failed',
				'listing_id' => 0,
				'warnings'   => array(),
				'error'      => $exception->getMessage(),
			);
		}

		$activity = (string) ( $result['_activity'] ?? '' );
		unset( $result['_activity'] );
		if ( '' !== $activity ) {
			$this->environment->record_activity(
				$activity,
				(int) ( $result['listing_id'] ?? 0 ),
				$property,
				$settings
			);
		}

		// Listing Change Events (issue #222) travel like '_activity': only a
		// committed create / update / delete carries them; a failure never does.
		$events = is_array( $result['_events'] ?? null ) ? $result['_events'] : array();
		$facts  = is_array( $result['_facts'] ?? null ) ? $result['_facts'] : array();
		unset( $result['_events'], $result['_facts'] );
		if ( ! empty( $events ) ) {
			$this->environment->record_events(
				(int) ( $result['listing_id'] ?? 0 ),
				$property,
				$settings,
				$events,
				$facts
			);
		}

		$event = 'failed' === $result['outcome']
			? 'failure'
			: ( 'saved-with-warnings' === $result['outcome'] ? 'warning' : 'success' );
		$this->environment->publish_result( $event, $result, $property );
		return $result;
	}

	/**
	 * Decide and persist one listing transition without publishing side effects.
	 *
	 * @param array<string, mixed> $property Incoming raw MLS property.
	 * @param array<string, mixed> $settings Normalized Import Task settings.
	 * @return array<string, mixed> Internal result with optional activity marker.
	 */
	private function decide_write( array $property, array $settings ): array {
		// ListingKey is the stable identity for lookup, retry, update, and delete.
		// Reject its absence before either injected dependency can mutate state.
		if ( empty( $property['ListingKey'] ) ) {
			return array(
				'outcome'    => 'failed',
				'listing_id' => 0,
				'warnings'   => array(),
				'error'      => 'ListingKey is missing.',
			);
		}

		// Resolve the existing Managed Listing before deciding whether an excluded
		// status means "skip" or "delete". The adapter supplies only its genuine
		// storage variation: the property post type. Listing identity is composite
		// (issue #278): ListingKey is unique only WITHIN one MLS, so the lookup is
		// qualified by the task connection's mls_id — MLS B's import can never
		// find (and overwrite or delete) MLS A's same-key listing.
		$listing_key = (string) $property['ListingKey'];
		$existing    = $this->environment->find_listing(
			$listing_key,
			$this->adapter->property_post_type(),
			(int) ( $settings['mls_id'] ?? 0 )
		);

		// Compare raw and PrettyEnums status forms through the project's shared
		// normalizer. A missing status is not selected unless explicitly saved as
		// an empty status, which the settings normalizer does not produce.
		$raw_status = $property['StandardStatus'] ?? ( $property['extra_meta']['MlsStatus'] ?? '' );
		$status     = mlsimport_normalize_status_enum( $raw_status );
		$statuses   = array_map(
			'mlsimport_normalize_status_enum',
			is_array( $settings['statuses'] ?? null ) ? $settings['statuses'] : array()
		);

		// Issue #222: read the stored price and status ONCE, before anything is
		// overwritten or deleted, so both the delete branch and the update branch
		// below can say what the listing looked like before this write.
		$price_key = $this->adapter->price_meta_key();
		$signals   = array( 'price' => '', 'status' => '', 'facts' => array() );
		// The listing details of THIS record, for the notification filter. A
		// theme site gets ListPrice / PostalCode only under the theme's meta
		// keys, so the adapter names them for the fallback.
		$facts     = Mlsimport_Listing_Event_Facts::from_property(
			$property,
			$status,
			array( 'price' => $price_key, 'zip' => $this->adapter->zip_meta_key() )
		);
		if ( null !== $existing ) {
			$signals = $this->environment->read_signals(
				(int) ( $existing['id'] ?? 0 ),
				$price_key,
				(array) ( $settings['field_configuration']['mls-fields-map-taxonomy'] ?? array() )
			);
		}

		if ( ! in_array( $status, $statuses, true ) ) {
			// Excluded data never creates a local post. When a Managed Listing
			// already exists, deletion is the complete write: returning here keeps
			// meta, taxonomy, media, title, and agent stages unreachable.
			$is_closed = in_array( $status, Mlsimport_Listing_Notification_Matcher::CLOSED, true );
			$sale      = (string) ( $property['extra_meta']['ClosePrice'] ?? '' );
			if ( null === $existing ) {
				$skipped = array(
					'outcome'    => 'skipped',
					'listing_id' => 0,
					'warnings'   => array(),
					'error'      => '',
				);
				// A Closed record for a listing that is not stored: the events
				// store turns the marker into "sold" only when this listing's
				// latest event is a Removal, and drops it otherwise.
				if ( $is_closed ) {
					$skipped['_facts']  = $facts;
					$skipped['_events'] = array(
						array(
							'type' => 'closed_seen',
							'old'  => '',
							'new'  => $sale,
						),
					);
				}
				return $skipped;
			}

			$listing_id = (int) ( $existing['id'] ?? 0 );
			if ( $this->environment->delete_listing( $existing ) ) {
				// A Removal (CONTEXT.md): the MLS still lists the property under
				// the incoming status; only this Import Task stopped importing it.
				// A Closed status is the one case that is reported as a sale. The
				// notification filter matches a departure against the LAST KNOWN
				// status, so the details carry the stored status, not the new one.
				if ( '' !== $signals['status'] ) {
					$facts['status'] = $signals['status'];
				}
				return array(
					'outcome'    => 'deleted',
					'listing_id' => $listing_id,
					'warnings'   => array(),
					'error'      => '',
					'_activity'  => 'deleted',
					'_facts'     => $facts,
					'_events'    => array(
						$is_closed
							? array(
								'type' => 'sold',
								'old'  => '',
								'new'  => $sale,
							)
							: array(
								'type' => 'removed',
								'old'  => $signals['status'],
								'new'  => $status,
							),
					),
				);
			}

			return array(
				'outcome'    => 'failed',
				'listing_id' => $listing_id,
				'warnings'   => array(),
				'error'      => 'Managed Listing could not be deleted.',
			);
		}

		// Avoid the expensive field/media rewrite only when both independent
		// change signals agree. A missing or unparsable MLS timestamp deliberately
		// falls through to an update because freshness cannot be proven.
		//
		// "Unchanged" skips the projection, taxonomies, title and media — NOT the
		// small theme meta block. Issue #333: Houzez blanks fave_property_id from
		// its own save paths (front-end edit, Auto Property ID), and with a full
		// skip that blank stayed frozen until the MLS touched the listing, while a
		// manual import (no config_version) healed it — the ticket's exact
		// symptom. Re-asserting the block every sync is a handful of no-op meta
		// reads per listing, so the guard keeps its purpose and the site self-heals.
		if ( null !== $existing ) {
			$incoming_mod_raw = (string) ( $property['extra_meta']['ModificationTimestamp'] ?? '' );
			$stored_mod_raw   = (string) ( $existing['modification_timestamp'] ?? '' );
			$config_version   = (string) ( $settings['config_version'] ?? '' );
			$stored_config    = (string) ( $existing['config_version'] ?? '' );
			$incoming_mod     = '' !== $incoming_mod_raw ? strtotime( $incoming_mod_raw ) : false;
			$stored_mod       = '' !== $stored_mod_raw ? strtotime( $stored_mod_raw ) : false;
			if (
				false !== $incoming_mod &&
				false !== $stored_mod &&
				$incoming_mod <= $stored_mod &&
				'' !== $config_version &&
				hash_equals( $stored_config, $config_version )
			) {
				$this->environment->reassert_meta( (int) ( $existing['id'] ?? 0 ), $property );
				return array(
					'outcome'    => 'unchanged',
					'listing_id' => (int) ( $existing['id'] ?? 0 ),
					'warnings'   => array(),
					'error'      => '',
				);
			}
		}

		// Issue #222: an update diffs stored vs incoming signals; a create hands
		// over the 'arrived' marker (never stored as-is — the events store turns
		// it into back_on_market after a Removal, or drops it).
		if ( null === $existing ) {
			$events = array(
				array(
					'type' => 'arrived',
					'old'  => '',
					'new'  => $status,
				),
			);
		} else {
			$events = Mlsimport_Listing_Events_Diff::diff(
				$signals,
				array(
					'price'  => (string) ( $property['extra_meta']['ListPrice'] ?? ( $property['meta'][ $price_key ] ?? '' ) ),
					'status' => $status,
					'facts'  => $facts,
				)
			);
		}

		return $this->persist_required_write( $property, $settings, $existing, $listing_key, $events, $facts );
	}

	/**
	 * Persist the create/update transition as one required unit of work.
	 *
	 * The environment starts a database transaction before the base post changes.
	 * Common data and the real theme projection must both succeed before commit;
	 * otherwise rollback removes a new partial post or restores the old version.
	 *
	 * @param array<string, mixed>      $property    Incoming raw MLS property.
	 * @param array<string, mixed>      $settings    Normalized Import Task choices.
	 * @param array<string, mixed>|null $existing    Previous listing, or null for create.
	 * @param string                    $listing_key Stable MLS identity.
	 * @param array<int, array>         $events      Listing Change Events to carry on success.
	 * @param array<string, string>     $facts       Listing details saved on the post and carried with the events.
	 * @return array<string, mixed> Created, updated, or failed result.
	 */
	private function persist_required_write( array $property, array $settings, ?array $existing, string $listing_key, array $events = array(), array $facts = array() ): array {
		$is_new           = null === $existing;
		$listing_id       = $is_new ? 0 : (int) ( $existing['id'] ?? 0 );
		$warnings         = array();
		$media_stage      = null;
		$gallery_switched = false;
		$this->environment->begin_write();

		try {
			if ( $is_new ) {
				// New listings receive all creation-time defaults from the task.
				$listing_id = $this->environment->create_listing(
					array(
						'listing_key'       => $listing_key,
						'post_type'         => $this->adapter->property_post_type(),
						'post_status'       => (string) ( $settings['post_status'] ?? 'publish' ),
						'user_id'           => (int) ( $settings['user_id'] ?? 0 ),
						'assigned_agent_id' => (int) ( $settings['assigned_agent_id'] ?? 0 ),
						'content'           => (string) ( $property['content'] ?? '' ),
						'task_id'           => (int) ( $settings['task_id'] ?? 0 ),
					)
				);
			} else {
				// Existing listings preserve post_status and Assigned Agent while the
				// author, content, and display-source choice remain live task data.
				$updated = $this->environment->update_listing(
					$existing,
					array(
						'user_id'       => (int) ( $settings['user_id'] ?? 0 ),
						'use_mls_agent' => ! empty( $settings['use_mls_agent'] ),
						'content'       => (string) ( $property['content'] ?? '' ),
					)
				);
				if ( ! $updated ) {
					throw new RuntimeException( 'Managed Listing could not be updated.' );
				}
			}

			if ( $listing_id <= 0 ) {
				throw new RuntimeException( 'Managed Listing could not be created.' );
			}
			$field_projection = $this->fields->prepare(
				$property,
				is_array( $settings['field_configuration'] ?? null )
					? $settings['field_configuration']
					: array()
			);
			if ( ! $this->environment->write_required_data( $listing_id, $property, $field_projection, $settings ) ) {
				throw new RuntimeException( 'Required listing data could not be saved.' );
			}
			// Keep the listing details on the post: the next write diffs against
			// them and reconciliation reads them before a deletion.
			$this->environment->store_facts( $listing_id, $facts );

			$context = array(
				'is_new'            => $is_new,
				'assigned_agent_id' => (int) ( $settings['assigned_agent_id'] ?? 0 ),
				'use_mls_agent'     => ! empty( $settings['use_mls_agent'] ),
				'fields'            => $field_projection['theme_fields'],
				'field_configuration' => is_array( $settings['field_configuration'] ?? null )
					? $settings['field_configuration']
					: array(),
			);
			if ( ! $this->adapter->write_theme_projection( $listing_id, $property, $context ) ) {
				throw new RuntimeException( 'Theme listing data could not be saved.' );
			}

			// Stage every replacement before changing the adapter's gallery. The
			// boundary returns only successful attachment IDs in feed order plus
			// photo-specific warnings; those warnings never invalidate core data.
			if ( isset( $property['Media'] ) && is_array( $property['Media'] ) ) {
				$ordered_media = $property['Media'];
				usort(
					$ordered_media,
					static function ( array $left, array $right ): int {
						return (int) ( $left['Order'] ?? PHP_INT_MAX ) <=> (int) ( $right['Order'] ?? PHP_INT_MAX );
					}
				);
				$media_stage = $this->environment->stage_media( $listing_id, $ordered_media );
				$warnings    = is_array( $media_stage['warnings'] ?? null )
					? array_values( $media_stage['warnings'] )
					: array();
				$attachment_ids = is_array( $media_stage['attachment_ids'] ?? null )
					? array_map( 'intval', array_values( $media_stage['attachment_ids'] ) )
					: array();

				// An empty successful set never clears the old gallery. At least one
				// replacement is required before the adapter sees a new final list.
				if ( ! empty( $media_stage['changed'] ) && ! empty( $attachment_ids ) ) {
					if ( ! $this->adapter->write_gallery( $listing_id, $attachment_ids ) ) {
						throw new RuntimeException( 'Theme gallery could not be saved.' );
					}
					$this->environment->activate_media( $listing_id, $media_stage );
					$gallery_switched = true;
				}
			}

			$this->environment->commit_write();
			if ( $gallery_switched && is_array( $media_stage ) ) {
				// Old MLS attachments are removed only after the new gallery is durable.
				// Cleanup can no longer invalidate that committed listing, so surface a
				// warning and leave the replacement gallery in its successful state.
				try {
					$this->environment->finish_media( $listing_id, $media_stage );
				} catch ( Throwable $cleanup_exception ) {
					$warnings[] = $cleanup_exception->getMessage();
				}
			}
			return array(
				'outcome'    => empty( $warnings ) ? ( $is_new ? 'created' : 'updated' ) : 'saved-with-warnings',
				'listing_id' => $listing_id,
				'warnings'   => $warnings,
				'error'      => '',
				'_activity'  => $is_new ? 'created' : 'updated',
				'_events'    => $events,
				'_facts'     => $facts,
			);
		} catch ( Throwable $exception ) {
			// Restore required database state before removing staged resources.
			// Performing attachment deletion inside the failed transaction would
			// itself be undone by rollback and could leave staged files orphaned.
			$this->environment->rollback_write();
			if ( is_array( $media_stage ) ) {
				$this->environment->discard_media( $media_stage );
			}
			return array(
				'outcome'    => 'failed',
				'listing_id' => $listing_id,
				'warnings'   => array(),
				'error'      => $exception->getMessage(),
			);
		}
	}
}
