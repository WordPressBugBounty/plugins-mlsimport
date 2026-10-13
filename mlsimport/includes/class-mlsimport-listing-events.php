<?php
/**
 * Listing Change Events store: the wp_mlsimport_listing_events table (issue #222).
 *
 * One row per change the MLS data drove, keyed by the composite listing
 * identity (mls_id, listing_key) so the history survives delete-then-recreate.
 * Stored types: price_change, status_change, removed, back_on_market,
 * new_listing, sold, agent_change (listing agent or office) and content_change
 * (photos or description).
 *
 * Each row also carries `facts`: the listing details (city, neighborhood, zip,
 * property type, price, status, address) as they were when the event was
 * recorded. The email notifications filter on them, because a removed listing
 * has no post left to look at (see class-mlsimport-listing-event-facts.php).
 *
 * Responsibilities, in file order:
 *   1. table name, creation (dbDelta) and versioned upgrade on 'init';
 *   2. record(): resolve the event time, turn the two markers into real types
 *      ('arrived' -> back_on_market or new_listing, 'closed_seen' -> sold or
 *      nothing), insert, then fire the WooCommerce-style actions
 *      `mlsimport_listing_event` and `mlsimport_listing_{$type}`;
 *   3. readers: latest() for the Back on Market rule, for_listing() for a
 *      per-listing drill-down;
 *   4. prune(): retention on the daily reconciliation cron, filterable through
 *      `mlsimport_listing_events_retention_months` (default 24).
 *
 * The table is deliberately NOT dropped by uninstall.php (uninstall stays
 * non-destructive). Follows the conventions of mlsimport-activity-log.php.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Schema version. Bump to trigger a dbDelta re-run via maybe_upgrade().
// 1.1 — listing_id (the public MLS number) stored beside the listing key.
// 1.2 — facts (JSON listing details) for the email notification filter.
define( 'MLSIMPORT_LISTING_EVENTS_DB_VERSION', '1.2' );

/**
 * Persist and read Listing Change Events.
 */
final class Mlsimport_Listing_Events {

	/** Event types that are stored as rows. 'arrived' and 'closed_seen' are markers, never stored. */
	const TYPES = array( 'price_change', 'status_change', 'removed', 'back_on_market', 'new_listing', 'sold', 'agent_change', 'content_change' );

	/** Latest-event types after which the listing is no longer stored on the site. */
	const GONE = array( 'removed', 'sold' );

	/**
	 * Return the prefixed events table name — the ONLY source of the name.
	 * Never accept it from request input, never use it as a prepare placeholder.
	 *
	 * @return string Table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mlsimport_listing_events';
	}

	/**
	 * Create or migrate the table with dbDelta() (idempotent).
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mls_id INT UNSIGNED NOT NULL DEFAULT 0,
  listing_key VARCHAR(191) NOT NULL DEFAULT '',
  listing_id VARCHAR(191) NOT NULL DEFAULT '',
  post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  task_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  event_type VARCHAR(20) NOT NULL DEFAULT '',
  old_value VARCHAR(191) NOT NULL DEFAULT '',
  new_value VARCHAR(191) NOT NULL DEFAULT '',
  facts TEXT NULL,
  event_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY listing (mls_id,listing_key),
  KEY listing_id (listing_id),
  KEY post_id (post_id),
  KEY event_type (event_type),
  KEY created_at (created_at)
) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Run the migration when the stored schema version is outdated.
	 * Hooked to 'init' priority 1 so cron requests get it too.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'mlsimport_listing_events_db_version' ) !== MLSIMPORT_LISTING_EVENTS_DB_VERSION ) {
			self::create_table();
			update_option( 'mlsimport_listing_events_db_version', MLSIMPORT_LISTING_EVENTS_DB_VERSION );
		}
	}

	/**
	 * Store a list of events for one listing and fire one action per row.
	 *
	 * Step 1: read the identity and the optional MLS timestamps from $ctx. A
	 *         missing MLS number (reconciliation knows only the key) is filled
	 *         from the listing's latest stored row, like Import History does.
	 * Step 2: for each event, resolve the markers against the listing's latest
	 *         stored event. 'arrived' (a create) becomes back_on_market when the
	 *         listing had left (removed / sold), else new_listing. 'closed_seen'
	 *         (a Closed record for a listing that is not stored) becomes sold
	 *         only right after a Removal — so an unknown listing, or the same
	 *         Closed record delivered twice, records nothing.
	 * Step 3: resolve event_at (MLS timestamp for that event type when present
	 *         and parsable, else now), insert the row, fire the actions.
	 *
	 * @param array<string, mixed>      $ctx    mls_id, listing_key, listing_id (MLS #),
	 *                                          post_id, task_id, price_changed_at,
	 *                                          status_changed_at, closed_at, facts
	 *                                          (listing details, stored as JSON).
	 * @param array<int, array<string>> $events Events as { type, old, new }.
	 * @return void
	 */
	public static function record( array $ctx, array $events ): void {
		global $wpdb;

		// Step 1: identity + timestamps.
		$mls_id      = (int) ( $ctx['mls_id'] ?? 0 );
		$listing_key = mb_substr( (string) ( $ctx['listing_key'] ?? '' ), 0, 191 );
		$listing_id  = mb_substr( (string) ( $ctx['listing_id'] ?? '' ), 0, 191 );
		$post_id     = (int) ( $ctx['post_id'] ?? 0 );
		$task_id     = (int) ( $ctx['task_id'] ?? 0 );
		$stamps      = array(
			'price_change'  => (string) ( $ctx['price_changed_at'] ?? '' ),
			'status_change' => (string) ( $ctx['status_changed_at'] ?? '' ),
			'sold'          => (string) ( $ctx['closed_at'] ?? '' ),
		);
		$facts       = is_array( $ctx['facts'] ?? null ) ? $ctx['facts'] : array();
		if ( '' === $listing_key ) {
			return;
		}
		$latest = self::latest( $mls_id, $listing_key );
		if ( '' === $listing_id && null !== $latest ) {
			$listing_id = (string) $latest['listing_id'];
		}

		foreach ( $events as $event ) {
			$type = (string) ( $event['type'] ?? '' );
			$old  = (string) ( $event['old'] ?? '' );
			$new  = (string) ( $event['new'] ?? '' );

			// Step 2: markers. A create is Back on Market after the listing had
			// left, else a New Listing; a Closed record is Sold only after a Removal.
			$latest_type = null === $latest ? '' : (string) $latest['event_type'];
			if ( 'arrived' === $type ) {
				$type = in_array( $latest_type, self::GONE, true ) ? 'back_on_market' : 'new_listing';
				if ( 'removed' === $latest_type ) {
					$old = (string) $latest['old_value'];
				}
			}
			if ( 'closed_seen' === $type ) {
				if ( 'removed' !== $latest_type ) {
					continue;
				}
				$type = 'sold';
			}
			if ( ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}

			// Step 3: event time, insert, actions.
			$event_at = self::event_time( $stamps[ $type ] ?? '' );
			$inserted = $wpdb->insert(
				self::table_name(),
				array(
					'mls_id'      => $mls_id,
					'listing_key' => $listing_key,
					'listing_id'  => $listing_id,
					'post_id'     => $post_id,
					'task_id'     => $task_id,
					'event_type'  => $type,
					'old_value'   => mb_substr( $old, 0, 191 ),
					'new_value'   => mb_substr( $new, 0, 191 ),
					'facts'       => empty( $facts ) ? '' : wp_json_encode( $facts ),
					'event_at'    => $event_at,
					'created_at'  => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $inserted ) {
				continue;
			}
			// Later events of this same call see the row just stored.
			$latest = array(
				'event_type' => $type,
				'old_value'  => $old,
				'listing_id' => $listing_id,
			);

			/**
			 * Fires after one Listing Change Event is stored.
			 *
			 * @param string $type        One of Mlsimport_Listing_Events::TYPES.
			 * @param int    $post_id     Property post ID (may no longer exist for a Removal).
			 * @param string $listing_key MLS ListingKey.
			 * @param string $old         Previous value ('' when unknown).
			 * @param string $new         New value ('' when the listing left the feed).
			 * @param array  $ctx         mls_id, task_id, the MLS timestamps and 'facts'.
			 */
			do_action( 'mlsimport_listing_event', $type, $post_id, $listing_key, $old, $new, $ctx );
			do_action( 'mlsimport_listing_' . $type, $type, $post_id, $listing_key, $old, $new, $ctx );
		}
	}

	/**
	 * Return the latest stored event for one listing identity, or null.
	 *
	 * @param int    $mls_id      MLS connection id.
	 * @param string $listing_key MLS ListingKey.
	 * @return array<string, mixed>|null Row as an associative array.
	 */
	public static function latest( int $mls_id, string $listing_key ): ?array {
		$rows = self::for_listing( $mls_id, $listing_key, 1 );
		return $rows[0] ?? null;
	}

	/**
	 * Return a listing's events, newest first.
	 *
	 * @param int    $mls_id      MLS connection id.
	 * @param string $listing_key MLS ListingKey.
	 * @param int    $limit       Maximum rows.
	 * @return array<int, array<string, mixed>> Rows as associative arrays.
	 */
	public static function for_listing( int $mls_id, string $listing_key, int $limit = 50 ): array {
		global $wpdb;
		$table = self::table_name();
		// Table name interpolated directly — it comes only from table_name().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE mls_id = %d AND listing_key = %s ORDER BY id DESC LIMIT %d",
				$mls_id,
				$listing_key,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Human labels for the stored event types (the "Event" filter dropdown of
	 * the Listing Changes screen and the listing-changes AI tool).
	 *
	 * A stored 'removed' row is shown in the table as Under contract, Sold or
	 * Off market (see Mlsimport_Listing_Events_List_Table::column_event_type()), so its filter entry names those.
	 *
	 * @return array<string, string> event_type => label.
	 */
	public static function type_labels(): array {
		return array(
			'new_listing'    => __( 'New listing', 'mlsimport' ),
			'price_change'   => __( 'Price change', 'mlsimport' ),
			'status_change'  => __( 'Status change', 'mlsimport' ),
			'removed'        => __( 'Under contract / Off market', 'mlsimport' ),
			'sold'           => __( 'Sold', 'mlsimport' ),
			'back_on_market' => __( 'Back on market', 'mlsimport' ),
			'agent_change'   => __( 'Listing agent or office changed', 'mlsimport' ),
			'content_change' => __( 'Photos or description changed', 'mlsimport' ),
		);
	}

	/**
	 * Read one page of Listing Changes.
	 *
	 * The one query behind the Listing Changes screen, shared by the wp-admin
	 * table (Mlsimport_Listing_Events_List_Table::prepare_items()) and the AI
	 * tool listing-changes (includes/mcp/mlsimport-mcp-events.php). Moved here
	 * from the wp-admin table unchanged; the optional date filters are the
	 * only addition.
	 *
	 * Step by step:
	 * 1. Build the WHERE clause from the filters; every value is prepared.
	 * 2. Count the matching rows (for the pager).
	 * 3. Fetch the requested page, newest first unless asked otherwise. The
	 *    table name comes only from table_name() and the sort direction is one
	 *    of two fixed words, so both are safe to interpolate.
	 *
	 * @param array $args {
	 *     Every key is optional.
	 *
	 *     @type string $event_type Stored event type; '' = all.
	 *     @type int    $task_id    Import Task post id; 0 = all.
	 *     @type string $search     Part of the ListingKey or the MLS #.
	 *     @type string $date_from  First day to include, 'Y-m-d' (site time).
	 *     @type string $date_to    Last day to include, 'Y-m-d' (site time).
	 *     @type string $order      'ASC' | 'DESC' (default DESC).
	 *     @type int    $page       Page number, from 1.
	 *     @type int    $per_page   Rows per page (default 50).
	 * }
	 * @return array{total:int, rows:array<int, array<string, mixed>>}
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$table    = self::table_name();
		$type     = (string) ( $args['event_type'] ?? '' );
		$task_id  = (int) ( $args['task_id'] ?? 0 );
		$search   = (string) ( $args['search'] ?? '' );
		$order    = 'ASC' === ( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		// Step 1: WHERE — every value prepared.
		$where = 'WHERE 1=1';
		if ( '' !== $type ) {
			$where .= $wpdb->prepare( ' AND event_type = %s', $type );
		}
		if ( $task_id > 0 ) {
			$where .= $wpdb->prepare( ' AND task_id = %d', $task_id );
		}
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND ( listing_key LIKE %s OR listing_id LIKE %s )', $like, $like );
		}
		// Optional whole days. Events are stored in UTC, so a site-time day is
		// converted to its UTC start and end.
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args['date_from'] ?? '' ) ) ) {
			$where .= $wpdb->prepare( ' AND event_at >= %s', get_gmt_from_date( $args['date_from'] . ' 00:00:00' ) );
		}
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args['date_to'] ?? '' ) ) ) {
			$where .= $wpdb->prepare( ' AND event_at <= %s', get_gmt_from_date( $args['date_to'] . ' 23:59:59' ) );
		}

		// Step 2: total for the pager.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

		// Step 3: the page.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} {$where} ORDER BY event_at {$order}, id {$order} LIMIT %d OFFSET %d",
			$per_page,
			( $page - 1 ) * $per_page
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return array(
			'total' => $total,
			'rows'  => $rows ? $rows : array(),
		);
	}

	/**
	 * Delete rows older than the retention window. Hooked to the daily
	 * reconciliation cron like the Import History prune.
	 *
	 * @return void
	 */
	public static function prune(): void {
		global $wpdb;
		/**
		 * Filter how many months of Listing Change Events are kept.
		 *
		 * @param int $months Retention in months (default 24).
		 */
		$months = (int) apply_filters( 'mlsimport_listing_events_retention_months', 24 );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 0, $months ) . ' months', time() ) );
		$table  = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Resolve the event time: the MLS's own timestamp when parsable, else now (UTC).
	 *
	 * @param string $mls_stamp RESO PriceChangeTimestamp / StatusChangeTimestamp / CloseDate, or ''.
	 * @return string MySQL datetime in UTC.
	 */
	private static function event_time( string $mls_stamp ): string {
		$parsed = '' !== $mls_stamp ? strtotime( $mls_stamp ) : false;
		return false !== $parsed ? gmdate( 'Y-m-d H:i:s', $parsed ) : current_time( 'mysql', true );
	}
}

add_action( 'init', array( 'Mlsimport_Listing_Events', 'maybe_upgrade' ), 1 );
add_action( 'mlsimport_reconciliation_event', array( 'Mlsimport_Listing_Events', 'prune' ) );
