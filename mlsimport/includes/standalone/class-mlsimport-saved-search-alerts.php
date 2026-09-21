<?php
/**
 * Saved Search daily alerts (Standalone mode only, ADR-0018).
 *
 * Once a day the site emails each active Saved Search the listings that match
 * it AND that Import History recorded as added or edited since the previous
 * run. There are no instant alerts and no record of what was sent: a listing
 * edited on ten days is mailed on ten days.
 *
 * How one day works, step by step:
 *
 *   1. WP-Cron fires `mlsimport_saved_search_daily` -> run_daily().
 *   2. run_daily() deletes Saved Searches left unconfirmed for 7+ days.
 *   3. It reads Import History (wp_mlsimport_activity) ONCE for the listing ids
 *      added/edited since the site-wide `mlsimport_saved_search_last_run`
 *      timestamp, stores them in the `mlsimport_saved_search_delta` option and
 *      moves the timestamp forward. Every source counts (manual, cron, ...).
 *   4. When the delta is not empty it queues one Action Scheduler action per
 *      ACTIVE Saved Search, so a slow mail server never blocks the cron request
 *      and a failed send is retried/logged by Action Scheduler on its own.
 *   5. Each action -> send_one(): runs the site's OWN search with the saved
 *      criteria, restricted to the delta through the internal `post_ids`
 *      param. No matches -> no mail. Matches -> one mail, capped.
 *
 * Because step 5 is the same query the results page runs, the mail can never
 * disagree with the site: drafts, deleted listings and cross-MLS duplicates the
 * search hides are hidden here too.
 *
 * Import History keeps 30 days, which bounds how far back a late run can reach.
 *
 * Extension points: mlsimport_alert_delta, mlsimport_alert_matches.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and runs the daily Saved Search alert job.
 */
class Mlsimport_Saved_Search_Alerts {

	const CRON        = 'mlsimport_saved_search_daily';
	const SEND_ACTION = 'mlsimport_saved_search_send';
	const LAST_RUN    = 'mlsimport_saved_search_last_run';
	const DELTA       = 'mlsimport_saved_search_delta';

	/**
	 * Hook the cron + the per-search action, and keep the daily event scheduled
	 * only while the feature is usable (Standalone mode + switched on).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::CRON, array( __CLASS__, 'run_daily' ) );
		add_action( self::SEND_ACTION, array( __CLASS__, 'send_one' ) );

		$scheduled = wp_next_scheduled( self::CRON );
		if ( Mlsimport_Saved_Search::enabled() && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		} elseif ( ! Mlsimport_Saved_Search::enabled() && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * Remove the daily event (plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * The daily job: clean up, compute the day's delta once, queue the sends.
	 *
	 * @return void
	 */
	public static function run_daily(): void {
		global $wpdb;

		// Step 1 — a save nobody confirmed within 7 days is abandoned: delete it.
		// Only PENDING searches are ever deleted here.
		foreach ( Mlsimport_Saved_Search::ids_by_status( 'pending', '7 days ago' ) as $stale_id ) {
			wp_delete_post( $stale_id, true );
		}

		// Step 2 — the window: (last run, now]. Import History stamps rows with the
		// site-local current_time( 'mysql' ), so the window uses the same clock. On
		// the very first run there is no last run; look back one day.
		$now   = current_time( 'mysql' );
		$since = (string) get_option( self::LAST_RUN, '' );
		if ( '' === $since ) {
			$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- matches the activity log's clock.
		}

		// Step 3 — the delta: every listing added or edited in the window, once
		// each (DISTINCT: a listing edited three times today is still one card).
		$table = mlsimport_activity_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$delta = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT listing_id FROM {$table} WHERE action IN ('added','edited') AND created_at > %s AND created_at <= %s", $since, $now ) );
		/** Filter the day's changed listing ids before matching. @since 7.3 */
		$delta = array_values( array_filter( array_map( 'intval', (array) apply_filters( 'mlsimport_alert_delta', $delta, $since, $now ) ) ) );

		// Stored once for every queued send; not autoloaded (it can be large).
		update_option( self::DELTA, $delta, false );
		update_option( self::LAST_RUN, $now, false );

		// Step 4 — nothing changed today: no search can match, queue nothing.
		if ( empty( $delta ) ) {
			return;
		}

		// Step 5 — one queued send per ACTIVE Saved Search.
		foreach ( Mlsimport_Saved_Search::ids_by_status( 'active' ) as $search_id ) {
			as_enqueue_async_action( self::SEND_ACTION, array( $search_id ), 'mlsimport' );
		}
	}

	/**
	 * Match one Saved Search against the day's delta and mail it when it matches.
	 *
	 * @param int $search_id Saved Search post ID.
	 * @return void
	 */
	public static function send_one( $search_id ): void {
		// Step 1 — only an ACTIVE search is ever mailed. It may have been
		// unsubscribed (or deleted) between being queued and running.
		$search = Mlsimport_Saved_Search::get( (int) $search_id );
		if ( null === $search || 'active' !== $search['status'] ) {
			return;
		}

		// Step 2 — the site's own search: saved criteria + today's listings only,
		// newest change first, at most "max listings per email" rows. `total` still
		// counts every match, so the mail can say how many more there are.
		$params = array_merge(
			$search['params'],
			array(
				'post_ids' => (array) get_option( self::DELTA, array() ),
				'orderby'  => 'newest_edited',
				'limit'    => max( 1, (int) mlsimport_standalone_option( 'saved_search_email_max' ) ),
				'page'     => 1,
			)
		);
		$data = Mlsimport_Standalone_Render::prepare( $params );
		/** Filter one Saved Search's matches (posts, rows, total) before mailing. @since 7.3 */
		$data = (array) apply_filters( 'mlsimport_alert_matches', $data, $search );

		// Step 3 — no matches means no mail at all (never an "nothing new" mail).
		if ( empty( $data['posts'] ) ) {
			return;
		}

		// Step 4 — send, and stamp "last sent" for the wp-admin list.
		if ( Mlsimport_Saved_Search_Mailer::send_alert( $search, $data ) ) {
			update_post_meta( $search['id'], 'mlsimport_ss_last_sent', current_time( 'mysql' ) );
		}
	}
}
