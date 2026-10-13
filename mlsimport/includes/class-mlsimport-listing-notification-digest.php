<?php
/**
 * Listing change notifications: the daily email, one per MLS connection.
 *
 * Runs on the plugin's existing daily cron (`mlsimport_reconciliation_event`),
 * so there is no send-hour setting. For each MLS connection:
 *
 *   1. read the events recorded for that MLS since the digest pointer
 *      (`last_event_id` in the notification settings);
 *   2. keep the ones the admin asked for (ticked kinds + that MLS's filter);
 *   3. when the MLS is switched on, at least one address is saved and at least
 *      one event is kept, send ONE mail to all addresses;
 *   4. move the pointer to the newest event read.
 *
 * The pointer moves even when nothing is sent (MLS switched off, no address,
 * nothing matched), so changes are never saved up and sent later by surprise.
 * It does NOT move when wp_mail() fails, so that day is retried on the next run.
 * An MLS seen for the first time only gets its pointer set: changes recorded
 * before notifications existed are never emailed.
 *
 * The mail lists at most MAX_ROWS changes and says how many more there are (a
 * first import of a whole MLS can produce thousands of "new listing" events).
 *
 * "Buyers to contact" (tick box `buyer_match`): a separate section of the same
 * mail. Every listing of this MLS that was added or changed since the pointer
 * (and still exists) is tested against every buyer's saved search
 * (Mlsimport_Buyer_Searches). The MLS's notification filter does NOT apply
 * here: the buyer's own search is the filter. A mail is sent when either part
 * has something to say.
 *
 * Extension points:
 *   filter mlsimport_listing_notification_recipients ( string[] $emails, int $mls_id )
 *   filter mlsimport_listing_notification_matches    ( bool $matches, array $event, array $rules )
 *   filter mlsimport_listing_notification_subject    ( string $subject, int $mls_id, array $events )
 *   filter mlsimport_listing_notification_template   ( string $path )
 *   action mlsimport_listing_notification_sent       ( int $mls_id, array $events, string[] $emails )
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build and send the daily listing-changes email.
 */
final class Mlsimport_Listing_Notification_Digest {

	/** Most changes listed in one mail; the rest are counted and linked. */
	const MAX_ROWS = 50;

	/** Most buyers listed in one mail, and most listings shown per buyer. */
	const MAX_BUYERS         = 20;
	const MAX_BUYER_LISTINGS = 5;

	/**
	 * Daily cron entry point: one digest per registered MLS connection.
	 *
	 * @return void
	 */
	public static function run(): void {
		foreach ( Mlsimport_Connections::all() as $mls_id => $connection ) {
			self::run_for( (int) $mls_id, (string) ( $connection['mls_name'] ?? '' ) );
		}
	}

	/**
	 * Send the digest of one MLS connection (steps 1-4 of the file header).
	 *
	 * @param int    $mls_id   MLS connection id.
	 * @param string $mls_name Name shown in the subject and the mail.
	 * @return void
	 */
	public static function run_for( int $mls_id, string $mls_name ): void {
		global $wpdb;
		$settings = Mlsimport_Listing_Notification_Settings::get();
		$rules    = Mlsimport_Listing_Notification_Settings::connection( $mls_id );

		// First sight of this MLS: start from "now", send nothing.
		if ( null === $rules['last_event_id'] ) {
			Mlsimport_Listing_Notification_Settings::set_pointer( $mls_id, Mlsimport_Listing_Notification_Settings::newest_event_id() );
			return;
		}

		// Step 1: events since the pointer, oldest first.
		$table = Mlsimport_Listing_Events::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from table_name() only.
				"SELECT * FROM {$table} WHERE mls_id = %d AND id > %d ORDER BY id ASC",
				$mls_id,
				(int) $rules['last_event_id']
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return;
		}
		$newest = (int) end( $rows )['id'];

		// Step 2: keep what the admin asked for.
		$events = array();
		foreach ( $rows as $row ) {
			$matches = Mlsimport_Listing_Notification_Matcher::matches( $row, $rules, $settings['event_types'] );
			/**
			 * Filter whether one event belongs in the daily email.
			 *
			 * @param bool  $matches Result of the kind + filter check.
			 * @param array $row     Events table row.
			 * @param array $rules   The MLS's notification filter.
			 */
			if ( apply_filters( 'mlsimport_listing_notification_matches', $matches, $row, $rules ) ) {
				$events[] = $row;
			}
		}

		/**
		 * Filter the addresses that receive one MLS's daily email.
		 *
		 * @param string[] $emails Saved addresses.
		 * @param int      $mls_id MLS connection id.
		 */
		$emails = array_filter( (array) apply_filters( 'mlsimport_listing_notification_recipients', $settings['emails'], $mls_id ), 'is_email' );

		// Step 3: send, unless there is nothing to say or nobody to tell. The
		// buyer matching runs only when a mail can actually go out.
		$can_send = ! empty( $rules['enabled'] ) && ! empty( $emails );
		$buyers   = $can_send && in_array( 'buyer_match', $settings['event_types'], true ) ? self::buyers( $rows ) : array();
		if ( $can_send && ( ! empty( $events ) || ! empty( $buyers ) ) ) {
			if ( ! self::send( $mls_id, $mls_name, $events, array_values( $emails ), $buyers ) ) {
				// Mail failed: keep the pointer so this day is retried.
				return;
			}
		}

		// Step 4: everything up to here is handled.
		Mlsimport_Listing_Notification_Settings::set_pointer( $mls_id, $newest );
	}

	/**
	 * Find the buyers whose saved search matches a new or changed listing.
	 *
	 * Step 1: the listings to test — every event since the pointer that is not
	 *         a departure (removed / sold), whose post is still published.
	 * Step 2: test them against each saved search; keep the searches that match.
	 *
	 * @param array<int, array<string, mixed>> $rows All event rows of this MLS since the pointer.
	 * @return array<int, array<string, mixed>> Buyers as { name, email, url, post_ids, rows }
	 *         (`rows`: the newest event row of each matching listing, for the mail's table).
	 */
	private static function buyers( array $rows ): array {
		// Step 1: candidate listings, each with its newest event (rows are oldest first).
		$latest = array();
		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			if ( $post_id > 0 && ! in_array( $row['event_type'], Mlsimport_Listing_Events::GONE, true ) && 'publish' === get_post_status( $post_id ) ) {
				$latest[ $post_id ] = $row;
			}
		}
		if ( empty( $latest ) ) {
			return array();
		}
		$post_ids = array_keys( $latest );

		// Step 2: one entry per matching saved search.
		$buyers = array();
		foreach ( Mlsimport_Buyer_Searches::all() as $search ) {
			$matched = Mlsimport_Buyer_Searches::matches( $search, $post_ids );
			if ( ! empty( $matched ) ) {
				$buyers[] = array(
					'name'     => (string) $search['name'],
					'email'    => (string) $search['email'],
					'url'      => (string) $search['url'],
					'post_ids' => $matched,
					'rows'     => array_values( array_intersect_key( $latest, array_flip( $matched ) ) ),
				);
			}
			if ( count( $buyers ) >= self::MAX_BUYERS ) {
				break;
			}
		}
		return $buyers;
	}

	/**
	 * Render and send one mail.
	 *
	 * @param int                              $mls_id   MLS connection id.
	 * @param string                           $mls_name MLS name.
	 * @param array<int, array<string, mixed>> $events   Matching event rows, oldest first.
	 * @param array<int, string>               $emails   Recipients.
	 * @param array<int, array<string, mixed>> $buyers   Buyers to contact (see buyers()).
	 * @return bool Whether wp_mail accepted the mail.
	 */
	private static function send( int $mls_id, string $mls_name, array $events, array $emails, array $buyers = array() ): bool {
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$count = count( $events );
		$subject = sprintf(
			/* translators: 1: number of changes, 2: MLS name, 3: date. */
			_n( '%1$d Listing Alert on %2$s - %3$s', '%1$d Listing Alerts on %2$s - %3$s', $count, 'mlsimport' ),
			$count,
			$mls_name,
			wp_date( get_option( 'date_format' ) )
		);
		// A mail that carries only buyers to contact says so in its subject.
		if ( 0 === $count ) {
			$subject = sprintf(
				/* translators: 1: site name, 2: number of buyers, 3: MLS name, 4: date. */
				_n( '[%1$s] %2$d buyer to contact - %3$s - %4$s', '[%1$s] %2$d buyers to contact - %3$s - %4$s', count( $buyers ), 'mlsimport' ),
				$site,
				count( $buyers ),
				$mls_name,
				wp_date( get_option( 'date_format' ) )
			);
		}
		/**
		 * Filter the daily email subject.
		 *
		 * @param string $subject Subject line.
		 * @param int    $mls_id  MLS connection id.
		 * @param array  $events  Matching event rows.
		 */
		$subject = (string) apply_filters( 'mlsimport_listing_notification_subject', $subject, $mls_id, $events );

		// Template variables (fixed names, read by the template file).
		$shown    = array_slice( $events, 0, self::MAX_ROWS );
		$more     = $count - count( $shown );
		$page_url = admin_url( 'admin.php?page=mlsimport_listing_changes' );

		// The opening sentence: the period the changes were recorded in, and
		// how many of each kind, e.g. "10 price drops and 3 listings under contract".
		$counts = array_count_values( array_map( array( 'Mlsimport_Listing_Notification_Matcher', 'kind' ), $events ) );
		$parts  = array();
		foreach ( array_keys( Mlsimport_Listing_Notification_Matcher::kind_labels() ) as $kind ) {
			if ( ! empty( $counts[ $kind ] ) ) {
				$parts[] = Mlsimport_Listing_Notification_Matcher::counted( $kind, (int) $counts[ $kind ] );
			}
		}
		$summary      = wp_sprintf( '%l', $parts );
		$period_end   = wp_date( get_option( 'date_format' ) );
		$period_start = $count > 0 ? get_date_from_gmt( (string) $events[0]['created_at'], get_option( 'date_format' ) ) : $period_end;
		$buyer_cap    = self::MAX_BUYER_LISTINGS;
		/**
		 * Filter the path of the daily email template.
		 *
		 * @param string $path Absolute path of the template file.
		 */
		$template = (string) apply_filters( 'mlsimport_listing_notification_template', MLSIMPORT_PLUGIN_PATH . 'templates/emails/listing-changes-digest.php' );
		ob_start();
		include $template;
		$body = (string) ob_get_clean();

		$sent = (bool) wp_mail( $emails, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( $sent ) {
			/**
			 * Fires after one MLS's daily email was accepted for sending.
			 *
			 * @param int      $mls_id MLS connection id.
			 * @param array    $events Matching event rows.
			 * @param string[] $emails Recipients.
			 */
			do_action( 'mlsimport_listing_notification_sent', $mls_id, $events, $emails );
		}
		return $sent;
	}

	/**
	 * Say in words what one event changed, for the mail's "Change" column.
	 *
	 * @param array<string, mixed> $row Events table row.
	 * @return string Plain text (the template escapes it).
	 */
	public static function describe( array $row ): string {
		$old   = (string) ( $row['old_value'] ?? '' );
		$new   = (string) ( $row['new_value'] ?? '' );
		$facts = json_decode( (string) ( $row['facts'] ?? '' ), true );
		$price = is_array( $facts ) ? (string) ( $facts['price'] ?? '' ) : '';

		switch ( Mlsimport_Listing_Notification_Matcher::kind( $row ) ) {
			case 'price_drop':
			case 'price_increase':
				$percent = (float) $old > 0 ? abs( ( (float) $new - (float) $old ) / (float) $old * 100 ) : 0;
				return sprintf( '%1$s → %2$s (%3$s%%)', self::money( $old ), self::money( $new ), number_format_i18n( $percent, 1 ) );
			case 'new_listing':
				/* translators: %s: list price. */
				return is_numeric( $price ) ? sprintf( __( 'Listed at %s', 'mlsimport' ), self::money( $price ) ) : '';
			case 'sold':
				/* translators: %s: closing price. */
				return is_numeric( $new ) ? sprintf( __( 'Sold for %s', 'mlsimport' ), self::money( $new ) ) : '';
			case 'under_contract':
			case 'off_market':
				/* translators: %s: status. */
				$was = '' !== $old ? sprintf( __( 'was %s', 'mlsimport' ), mlsimport_status_label( $old ) ) : '';
				/* translators: %s: status. */
				$now = '' !== $new ? sprintf( __( 'now %s', 'mlsimport' ), mlsimport_status_label( $new ) ) : __( 'no longer on the MLS', 'mlsimport' );
				return trim( $was . ', ' . $now, ', ' );
			case 'content_change':
				return $new;
			case 'agent_change':
				return '' !== $old ? $old . ' → ' . $new : $new;
			default:
				// status_change, back_on_market: statuses, shown in readable words.
				$new = mlsimport_status_label( $new );
				return '' !== $old ? mlsimport_status_label( $old ) . ' → ' . $new : $new;
		}
	}

	/**
	 * Format a price for the mail.
	 *
	 * @param string $value Numeric price.
	 * @return string Formatted price.
	 */
	private static function money( string $value ): string {
		if ( ! is_numeric( $value ) ) {
			return $value;
		}
		return function_exists( 'mlsimport_format_price' ) ? (string) mlsimport_format_price( $value ) : number_format_i18n( (float) $value );
	}
}

// After the events prune (priority 10) on the same daily cron.
add_action( 'mlsimport_reconciliation_event', array( 'Mlsimport_Listing_Notification_Digest', 'run' ), 20 );
