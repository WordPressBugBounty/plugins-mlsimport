<?php
/**
 * WordPress storage and external operations for Import Task execution.
 *
 * The execution module decides what an Import Run does. This class translates
 * those decisions into WordPress options, post meta, MLS requests, and the
 * existing theme-specific listing writer.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-mlsimport-import-task-execution-environment.php';

/**
 * Connects the shared Import Task runner to WordPress.
 */
final class Mlsimport_Import_Task_Execution_WordPress_Environment implements Mlsimport_Import_Task_Execution_Environment {

	/** The small site-wide record that prevents two imports from overlapping. */
	private const LOCK_OPTION = 'mlsimport_import_run_lock';

	/** Latest administrator-visible progress and result for each Import Task. */
	private const STATUS_META = 'mlsimport_import_run_status';

	/** @var Mlsimport_Admin Existing admin/API controller. */
	private $admin;

	/**
	 * Receive the existing admin controller at the module boundary.
	 *
	 * @param Mlsimport_Admin $admin Existing admin/API controller.
	 */
	public function __construct( Mlsimport_Admin $admin ) {
		$this->admin = $admin;
	}

	/** {@inheritDoc} */
	public function new_run_id(): string {
		return wp_generate_uuid4();
	}

	/** {@inheritDoc} */
	public function now(): int {
		return time();
	}

	/** {@inheritDoc} */
	public function claim_run( array $run, int $stale_before ): bool {
		$run_id = (string) $run['run_id'];
		update_option( $this->run_option_name( $run_id ), $run, false );

		$lock = $this->lock_from_run( $run );
		if ( ! add_option( self::LOCK_OPTION, $lock, '', false ) ) {
			$current = get_option( self::LOCK_OPTION, array() );
			if ( ! is_array( $current ) || (int) ( $current['activity_at'] ?? 0 ) > $stale_before ) {
				delete_option( $this->run_option_name( $run_id ) );
				return false;
			}
			if ( ! $this->replace_stale_lock( $current, $lock ) ) {
				delete_option( $this->run_option_name( $run_id ) );
				return false;
			}
		}

		$this->write_task_status( $run, array() );
		return true;
	}

	/** {@inheritDoc} */
	public function read_run( string $run_id ): array {
		$run = get_option( $this->run_option_name( $run_id ), array() );
		return is_array( $run ) ? $run : array();
	}

	/** {@inheritDoc} */
	public function owns_run( string $run_id ): bool {
		$lock = get_option( self::LOCK_OPTION, array() );
		return is_array( $lock ) && hash_equals( (string) ( $lock['run_id'] ?? '' ), $run_id );
	}

	/** {@inheritDoc} */
	public function update_run( string $run_id, array $changes ): void {
		$run = array_merge( $this->read_run( $run_id ), $changes );
		update_option( $this->run_option_name( $run_id ), $run, false );
		if ( ! $this->owns_run( $run_id ) ) {
			return;
		}

		update_option( self::LOCK_OPTION, $this->lock_from_run( $run ), false );
		$this->write_task_status( $run, array() );
	}

	/** {@inheritDoc} */
	public function finish_run( string $run_id, array $result ): void {
		$run = array_merge(
			$this->read_run( $run_id ),
			array(
				'state'       => (string) $result['state'],
				'activity_at' => $this->now(),
				'result'      => $result,
			)
		);
		update_option( $this->run_option_name( $run_id ), $run, false );

		// A replaced worker must not overwrite the newer run's task status or
		// release the newer worker's lock.
		if ( $this->owns_run( $run_id ) ) {
			// Import-performance telemetry (issue #216): the owning finisher —
			// and only it, so a replaced zombie cannot overwrite the real
			// numbers — records the snapshot the daily heartbeat ships and the
			// task screen shows. All inputs already live on the run record.
			if ( function_exists( 'mlsimport_telemetry_import_run_snapshot' ) ) {
				mlsimport_telemetry_set(
					'last_import_run',
					mlsimport_telemetry_import_run_snapshot(
						$run,
						$result,
						$this->now(),
						memory_get_peak_usage( true ),
						$this->pending_worker_actions()
					)
				);
			}
			$this->write_task_status( $run, $result );
			delete_option( self::LOCK_OPTION );
		}
		delete_option( $this->run_option_name( $run_id ) );
	}

	/**
	 * Count import worker actions still pending in Action Scheduler.
	 *
	 * Queue-depth evidence for the telemetry snapshot: a healthy finish leaves
	 * zero pending workers, while a growing number means enqueued work is not
	 * being dispatched on this host.
	 *
	 * @return int Pending 'mlsimport_background_process_per_item' actions.
	 */
	private function pending_worker_actions(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => 'mlsimport_background_process_per_item',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		return is_array( $pending ) ? count( $pending ) : 0;
	}

	/**
	 * Fetch one listing group after rejecting an unsupported Stored adapter.
	 *
	 * Provider arguments and the external SaaS call retain their existing admin
	 * boundaries. The adapter configuration check runs first so no listing data
	 * is requested when the site cannot persist it safely. A failed SaaS call is
	 * retried twice (5s pause) so one transient timeout cannot abort a long run.
	 *
	 * @param array<string, mixed> $run   Current Import Run.
	 * @param int                  $skip  Zero-based listing offset.
	 * @param int                  $limit Maximum listings requested.
	 * @return array<string, mixed> Success/data or failure/error response.
	 */
	public function fetch_listing_batch( array $run, int $skip, int $limit ): array {
		$configuration_error = $this->admin->mlsimport_stored_listing_configuration_error();
		if ( '' !== $configuration_error ) {
			return array( 'success' => false, 'error' => $configuration_error );
		}

		$request   = is_array( $run['request'] ?? null ) ? $run['request'] : array();
		$automatic = 'automatic' === (string) ( $run['source'] ?? '' );
		$last_date = $automatic ? get_post_meta( (int) $run['task_id'], 'mlsimport_last_date', true ) : '';
		$arguments = $this->admin->mlsimport_saas_make_listing_requests_arguments(
			(int) $run['task_id'],
			(string) ( $request['last_date'] ?? $last_date ),
			$skip,
			$limit,
			$automatic
		);
		if ( ! is_array( $arguments ) ) {
			return array( 'success' => false, 'error' => 'Listing request could not be built.' );
		}
		// Provider-specific validation happens inside the Provider Family adapter.
		// Stop before the SaaS request and expose its safe message to the Import Run.
		if ( isset( $arguments['mlsimport_provider_error'] ) ) {
			$error = $arguments['mlsimport_provider_error'];
			return array(
				'success' => false,
				'error'   => is_array( $error ) && isset( $error['message'] )
					? (string) $error['message']
					: 'The MLS request could not be prepared.',
			);
		}

		// A connection the SaaS rejected with the stable not_entitled code skips
		// its imports until it is re-entitled (#276) — no request is sent and the
		// Import Run surfaces the failure; other connections are unaffected.
		if ( mlsimport_connection_not_entitled( (int) ( $arguments['mls_id'] ?? 0 ) ) ) {
			return array(
				'success' => false,
				'error'   => 'Your account is not entitled to this MLS. Imports for it are paused.',
			);
		}

		// Proven-previous-version parity: breathe for 100ms between batches so
		// the database and the SaaS API get a gap between bursts of work. The
		// first batch of a run starts immediately.
		if ( $skip > 0 ) {
			usleep( 100000 );
		}

		// One transient SaaS hang (observed: a single 120s cURL timeout at batch
		// 425/1039 while the surrounding 41 fetches took ~2.5s) must not abort a
		// long Import Run. Retry the identical request up to twice before the
		// failure is real; on final failure surface the transport error text
		// (the API client returns it as a string) instead of a generic message.
		$response = null;
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			if ( $attempt > 1 ) {
				// Each retry is a diagnosable event: a run that succeeds only
				// on attempt 2 still tells the log the SaaS call hung once.
				mlsimport_saas_single_write_import_custom_logs(
					'Listings fetch retry ' . $attempt . '/3 at offset ' . $skip . '.' . PHP_EOL,
					'manual'
				);
				sleep( 5 );
			}
			$fetch_started_at = microtime( true );
			$response         = $this->admin->theme_importer->globalApiRequestCurlSaas( 'listings', $arguments, 'POST' );
			$fetch_seconds    = microtime( true ) - $fetch_started_at;
			// A successful but slow fetch is the early warning for the
			// transient 120s hangs observed in production-size runs.
			if ( $fetch_seconds > 10 ) {
				mlsimport_saas_single_write_import_custom_logs(
					'Slow listings fetch: ' . round( $fetch_seconds, 1 ) . 's at offset ' . $skip . ' (attempt ' . $attempt . ').' . PHP_EOL,
					'manual'
				);
			}
			if ( is_array( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
				break;
			}
		}
		if ( ! is_array( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			// The server rejected this mls_id against the account's entitlements
			// (#276): mark this one connection so the next batch/run skips it.
			// The failure below still surfaces — never a silent fallback.
			if ( mlsimport_response_not_entitled( $response ) ) {
				mlsimport_mark_connection_not_entitled( (int) ( $arguments['mls_id'] ?? 0 ) );
			}
			$error = 'Listings request failed.';
			if ( is_string( $response ) && '' !== $response ) {
				$error = $response;
			} elseif ( is_array( $response ) && '' !== (string) ( $response['message'] ?? '' ) ) {
				$error = (string) $response['message'];
			}
			mlsimport_saas_single_write_import_custom_logs(
				'Listings fetch FAILED after 3 attempts at offset ' . $skip . ': ' . $error . PHP_EOL,
				'manual'
			);
			return array( 'success' => false, 'error' => $error );
		}

		return array( 'success' => true, 'data' => $response['data'] );
	}

	/**
	 * Translate live Import Task settings and save one listing through the module.
	 *
	 * The configuration hash includes every update-time choice that can require a
	 * rewrite when MLS data is unchanged. The public listing outcome is translated
	 * into the Import Run's success/error shape without hiding photo warnings.
	 *
	 * @param array<string, mixed> $run     Current Import Run.
	 * @param array<string, mixed> $listing Incoming raw listing.
	 * @return array<string, mixed> Import Run save result.
	 */
	public function save_listing( array $run, array $listing ): array {
		$task_id = (int) $run['task_id'];
		$user_id = (int) get_post_meta( $task_id, 'mlsimport_item_property_user', true );
		if ( 0 === $user_id ) {
			$user_id = (int) get_post_field( 'post_author', $task_id );
		}
		$title_format = (string) get_post_meta( $task_id, 'mlsimport_item_title_format', true );
		if ( '' === $title_format ) {
			// Per-connection sync settings (#275), resolved through the
			// task's OWN connection binding (#277).
			$sync_options = mlsimport_get_connection_option( 'mlsimport_admin_mls_sync', array(), mlsimport_task_mls_id( $task_id ) );
			$title_format = is_array( $sync_options ) ? (string) ( $sync_options['title_format'] ?? '' ) : '';
		}
		// Field configuration for the task's OWN connection (#277) — the
		// shared projection cache, keyed by the task's binding.
		$field_configuration = mlsimport_active_field_configuration( false, mlsimport_task_mls_id( $task_id ) );
		$use_mls_agent       = ! empty( get_post_meta( $task_id, 'mlsimport_item_use_mls_agent', true ) );
		$config_version      = hash(
			'sha256',
			wp_json_encode(
				array(
					'user_id'             => $user_id,
					'title_format'        => $title_format,
					'field_configuration' => $field_configuration,
					'use_mls_agent'       => $use_mls_agent,
				)
			)
		);
		$options = array(
			'mlsimport_item_standardstatus'        => get_post_meta( $task_id, 'mlsimport_item_standardstatus', true ),
			'mlsimport_item_standardstatusprotect' => get_post_meta( $task_id, 'mlsimport_item_standardstatusprotect', true ),
			'mlsimport_item_property_user'         => $user_id,
			'mlsimport_item_agent'                 => get_post_meta( $task_id, 'mlsimport_item_agent', true ),
			'mlsimport_item_use_mls_agent'         => $use_mls_agent,
			'mlsimport_item_property_status'       => get_post_meta( $task_id, 'mlsimport_item_property_status', true ),
			'mlsimport_field_configuration'        => $field_configuration,
			'mlsimport_item_title_format'           => $title_format,
			'mlsimport_write_config_version'        => $config_version,
		);
		// Proven-previous-version parity: suspend the two heavy post-write hook
		// stacks while this one listing is written, so third-party save handlers
		// (SEO indexers, cache purgers, notifiers) do not run once per imported
		// listing and per attachment. Restored in finally so a throwing writer
		// can never leave the site with its save hooks disabled.
		global $wp_filter;
		$suspended_filters = array();
		foreach ( array( 'save_post', 'transition_post_status' ) as $suspended_hook ) {
			if ( isset( $wp_filter[ $suspended_hook ] ) ) {
				$suspended_filters[ $suspended_hook ] = $wp_filter[ $suspended_hook ];
				$wp_filter[ $suspended_hook ]         = new WP_Hook();
			}
		}
		try {
			$saved = $this->admin->theme_importer->mlsimportSaasPrepareToImportPerItem(
				$listing,
				array( 'item_id' => $task_id ),
				'automatic' === (string) ( $run['source'] ?? '' ) ? 'cron' : 'manual',
				$options
			);
		} finally {
			foreach ( $suspended_filters as $suspended_hook => $hook_object ) {
				$wp_filter[ $suspended_hook ] = $hook_object;
			}
		}

		// The one-listing writer is forbidden to flush site-wide caches, so the
		// batch context must release memory after every listing. Without this,
		// each imported property and its attachments stay in the runtime object
		// cache until the worker dies at the PHP memory limit mid-run (observed
		// as a fatal at 75 of 100 listings under a 256M limit). Mirror Action
		// Scheduler's own between-actions cleanup: flush only the runtime cache
		// when supported so an external object cache is not invalidated.
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} elseif ( ! wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
		// Sites running with SAVEQUERIES accumulate every query in memory; the
		// proven cpt-mlsimport import loop cleared this each listing as well.
		global $wpdb;
		$wpdb->queries = array();
		gc_collect_cycles();
		if ( false === $saved || is_wp_error( $saved ) || 'failed' === ( $saved['outcome'] ?? '' ) ) {
			$error = is_wp_error( $saved ) ? $saved->get_error_message() : 'Theme writer reported failure.';
			if ( is_array( $saved ) && '' !== (string) ( $saved['error'] ?? '' ) ) {
				$error = (string) $saved['error'];
			}
			// Name the exact listing: the run result only keeps the LAST error,
			// so without this line a single bad listing among a thousand is
			// impossible to find after the run.
			mlsimport_saas_single_write_import_custom_logs(
				'Listing save FAILED for ' . (string) ( $listing['ListingKey'] ?? 'unknown-key' ) . ': ' . $error . PHP_EOL,
				'manual'
			);
			return array( 'success' => false, 'error' => $error );
		}

		// Photo/meta warnings do not fail the listing, so they never reach the
		// run result — the import log is their only permanent record.
		$warnings = is_array( $saved ) ? (array) ( $saved['warnings'] ?? array() ) : array();
		if ( ! empty( $warnings ) ) {
			mlsimport_saas_single_write_import_custom_logs(
				'Listing ' . (string) ( $listing['ListingKey'] ?? 'unknown-key' ) . ' saved with warnings: '
				. implode( ' | ', array_map( 'strval', $warnings ) ) . PHP_EOL,
				'manual'
			);
		}
		return array(
			'success'  => true,
			'outcome'  => is_array( $saved ) ? (string) ( $saved['outcome'] ?? 'updated' ) : 'updated',
			'warnings' => $warnings,
		);
	}

	/** {@inheritDoc} */
	public function request_stop( int $task_id ): bool {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( ! is_array( $lock ) || $task_id !== (int) ( $lock['task_id'] ?? 0 ) ) {
			return false;
		}
		$run_id                = (string) $lock['run_id'];
		$run                   = $this->read_run( $run_id );
		$run['stop_requested'] = true;
		update_option( $this->run_option_name( $run_id ), $run, false );

		// Stop is final for the administrator: record the stopped result and
		// release the site-wide slot right away so a new import can start
		// immediately. A still-live worker sees stop_requested at its next
		// listing boundary and exits without touching this status (update_run
		// and finish_run both skip status/lock writes once the slot is gone).
		// A dead worker can no longer hold the site locked for 30 minutes.
		$run['state'] = 'stopped';
		$this->write_task_status(
			$run,
			array(
				'state'  => 'stopped',
				'found'  => (int) ( $run['expected'] ?? 0 ),
				// The environment only tracks handled listings; the exact
				// saved/failed split stays with the worker and is not shown
				// for stopped runs.
				'saved'  => (int) ( $run['handled'] ?? 0 ),
				'failed' => 0,
				'error'  => '',
			)
		);
		delete_option( self::LOCK_OPTION );
		return true;
	}

	/** {@inheritDoc} */
	public function stop_requested( string $run_id ): bool {
		$run = $this->read_run( $run_id );
		return true === ( $run['stop_requested'] ?? false );
	}

	/** {@inheritDoc} */
	public function read_task_status( int $task_id ): array {
		$status = get_post_meta( $task_id, self::STATUS_META, true );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Queue the follow-up worker for a chunk hand-off (issue #199).
	 *
	 * Called from inside the running worker whose time budget is spent, so
	 * this must ONLY enqueue: the queue-cleanup path used for fresh starts
	 * and revivals would mark this very worker's own action as failed.
	 *
	 * @param string $run_id Run identity to continue.
	 * @return void
	 */
	public function enqueue_worker( string $run_id ): void {
		as_enqueue_async_action(
			'mlsimport_background_process_per_item',
			array( 'args' => array( 'run_id' => $run_id ) )
		);
		spawn_cron();
	}

	/**
	 * Queue a replacement worker for a silent run (watchdog path).
	 *
	 * The watchdog runs outside any worker, so the full start-style queue
	 * cleanup is correct here: a recorded running action belongs to a killed
	 * process, and a pending one failed to dispatch. Both are cleared before
	 * the fresh worker is queued.
	 *
	 * @param string $run_id Run identity to continue.
	 * @return void
	 */
	public function revive_worker( string $run_id ): void {
		$this->admin->mlsimport_enqueue_import_worker( $run_id );
	}

	/**
	 * Read the run holding the site-wide lock when it belongs to this task.
	 *
	 * @param int $task_id Import Task identifier.
	 * @return array<string, mixed> Active run record or empty array.
	 */
	public function read_active_run( int $task_id ): array {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( ! is_array( $lock ) || $task_id !== (int) ( $lock['task_id'] ?? 0 ) ) {
			return array();
		}
		return $this->read_run( (string) ( $lock['run_id'] ?? '' ) );
	}

	/** {@inheritDoc} */
	public function count_listings( array $run ): array {
		$task_id   = (int) $run['task_id'];
		$last_date = get_post_meta( $task_id, 'mlsimport_last_date', true );
		if ( '' === $last_date ) {
			return array( 'success' => false, 'error' => 'Complete a manual import first.' );
		}
		// Same transient-failure protection as fetch_listing_batch: the count
		// call hits the same SaaS endpoint family, and an hourly run must not
		// abort because one HTTP request hung. Retry the identical request up
		// to twice (5s pause) before the failure is treated as real.
		$response = null;
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			if ( $attempt > 1 ) {
				sleep( 5 );
			}
			$response = $this->admin->mlsimport_make_listing_requests( $task_id, $last_date, '', '', true );
			if ( isset( $response['results'] ) ) {
				break;
			}
		}
		if ( ! isset( $response['results'] ) ) {
			return array( 'success' => false, 'error' => (string) ( $response['message'] ?? 'Listings count failed.' ) );
		}

		return array( 'success' => true, 'found' => max( 0, (int) $response['results'] ) );
	}

	/** {@inheritDoc} */
	public function advance_last_successful_sync_time( int $task_id, int $completed_at ): void {
		update_post_meta( $task_id, 'mlsimport_last_date', wp_date( 'Y-m-d\\TH:i', $completed_at - 7200 ) );
	}

	/**
	 * Replace a stale lock only when its stored value is still unchanged.
	 *
	 * The database comparison prevents two simultaneous replacement requests
	 * from both believing they acquired the site-wide slot.
	 *
	 * @param array<string, mixed> $current Lock value that was read.
	 * @param array<string, mixed> $next    New lock value.
	 * @return bool Whether this request replaced the exact stale value.
	 */
	private function replace_stale_lock( array $current, array $next ): bool {
		global $wpdb;
		$changed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $next ) ),
			array(
				'option_name'  => self::LOCK_OPTION,
				'option_value' => maybe_serialize( $current ),
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return 1 === $changed;
	}

	/**
	 * Store the compact progress shape used by the admin status endpoint.
	 *
	 * @param array<string, mixed> $run    Current run record.
	 * @param array<string, mixed> $result Final result, or empty while active.
	 * @return void
	 */
	private function write_task_status( array $run, array $result ): void {
		$task_id = (int) $run['task_id'];
		update_post_meta(
			$task_id,
			self::STATUS_META,
			array(
				'run_id'   => (string) $run['run_id'],
				'state'    => (string) ( $run['state'] ?? 'waiting' ),
				'handled'  => (int) ( $run['handled'] ?? 0 ),
				'expected' => (int) ( $run['expected'] ?? 0 ),
				'error'    => (string) ( $run['error'] ?? ( $result['error'] ?? '' ) ),
				// The worker's last heartbeat. The Import Tasks list uses it
				// (via mlsimport_task_health()) to flag a 'running' status
				// whose worker silently died — GitHub issue #200.
				'activity_at' => (int) ( $run['activity_at'] ?? 0 ),
				// This method runs inside the worker process, so this is the
				// import worker's real memory — the number administrators need
				// to see. The polling AJAX request's own memory is irrelevant.
				'memory'   => round( memory_get_usage( true ) / 1048576, 2 ),
				'result'   => $result,
			)
		);

		// Existing installations use this value to decide whether hourly sync is
		// allowed. Record the first successful manual import permanently; later
		// stopped or failed manual retries must not remove that eligibility.
		if ( 'manual' === (string) ( $run['source'] ?? '' ) ) {
			$state = (string) ( $run['state'] ?? 'waiting' );
			if ( 'completed' === $state ) {
				update_post_meta( $task_id, 'mlsimport_initial_import_completed', 1 );
				update_post_meta( $task_id, 'mlsimport_spawn_status', 'completed' );
			} elseif ( ! get_post_meta( $task_id, 'mlsimport_initial_import_completed', true ) ) {
				update_post_meta( $task_id, 'mlsimport_spawn_status', 'started' );
			}
		}
	}

	/**
	 * Keep only ownership and heartbeat fields in the site-wide lock.
	 *
	 * @param array<string, mixed> $run Current run record.
	 * @return array<string, int|string> Compact lock value.
	 */
	private function lock_from_run( array $run ): array {
		return array(
			'run_id'      => (string) $run['run_id'],
			'task_id'     => (int) $run['task_id'],
			'activity_at' => (int) ( $run['activity_at'] ?? $this->now() ),
		);
	}

	/**
	 * Build a bounded option key without exposing the run id directly.
	 *
	 * @param string $run_id Run identity.
	 * @return string WordPress option key.
	 */
	private function run_option_name( string $run_id ): string {
		return 'mlsimport_import_run_' . md5( $run_id );
	}
}
