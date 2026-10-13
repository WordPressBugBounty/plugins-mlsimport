<?php
/**
 * Listing change notifications: name an event and test it against a filter.
 *
 * The events table stores a few technical event types. The realtor is told
 * about "notification kinds", which are derived from a stored row:
 *
 *   stored price_change   -> price_drop or price_increase (by direction)
 *   stored removed        -> under_contract (left to Pending / Active Under
 *                            Contract), sold (left with a Closed status) or
 *                            off_market (any other status, or no status)
 *   every other type      -> the same name (new_listing, status_change, sold,
 *                            back_on_market, agent_change, content_change)
 *
 * A disappearance is never called "sold": only a Closed record is.
 *
 * kind_labels() also lists `buyer_match`. It is not the kind of any event; it
 * is the tick box that adds the "Buyers to contact" section to the daily email
 * (see Mlsimport_Buyer_Searches).
 *
 * matches() answers "should this event be emailed for this MLS?":
 *   1. the event's kind must be one the admin ticked;
 *   2. an event with no captured listing details is never filtered out (the
 *      listing left before its details were recorded, so nothing can be said);
 *   3. every filter field that has values must contain the listing's value
 *      (values inside a field are alternatives, fields combine with AND, text
 *      is compared ignoring letter case, statuses in their normalized form);
 *   4. the price must sit inside the minimum / maximum, edges included.
 *
 * Pure: no database and no WordPress state, so it is testable in isolation.
 * Used by the daily digest, the email template and the Listing Changes page.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/mlsimport-status-normalize.php';

/**
 * Derive notification kinds and apply one MLS's notification filter.
 */
final class Mlsimport_Listing_Notification_Matcher {

	/** Statuses that mean "an offer was accepted", in normalized form. */
	const UNDER_CONTRACT = array( 'pending', 'activeundercontract' );

	/** Statuses that mean "the sale closed", in normalized form. */
	const CLOSED = array( 'closed', 'sold' );

	/**
	 * The notification kinds the admin can tick, with their human labels.
	 *
	 * @return array<string, string> kind => label, in display order.
	 */
	public static function kind_labels(): array {
		return array(
			'new_listing'    => __( 'New listing', 'mlsimport' ),
			'price_drop'     => __( 'Price drop', 'mlsimport' ),
			'price_increase' => __( 'Price increase', 'mlsimport' ),
			'status_change'  => __( 'Status change', 'mlsimport' ),
			'under_contract' => __( 'Under contract', 'mlsimport' ),
			'sold'           => __( 'Sold', 'mlsimport' ),
			'off_market'     => __( 'Off market', 'mlsimport' ),
			'back_on_market' => __( 'Back on market', 'mlsimport' ),
			'agent_change'   => __( 'Listing agent or office changed', 'mlsimport' ),
			'content_change' => __( 'Photos or description changed', 'mlsimport' ),
			// Not an event kind: switches the "Buyers to contact" section on
			// (new or changed listings that match a buyer's saved search).
			'buyer_match'    => __( 'Buyers with a matching saved search', 'mlsimport' ),
		);
	}

	/**
	 * A kind with its count, in words: "10 price drops", "1 new listing".
	 * Used by the summary sentence at the top of the daily email.
	 *
	 * @param string $kind  One key of kind_labels().
	 * @param int    $count How many events of that kind.
	 * @return string Counted, lower-case wording.
	 */
	public static function counted( string $kind, int $count ): string {
		$forms = array(
			/* translators: %d: number of events. */
			'new_listing'    => _n( '%d new listing', '%d new listings', $count, 'mlsimport' ),
			/* translators: %d: number of events. */
			'price_drop'     => _n( '%d price drop', '%d price drops', $count, 'mlsimport' ),
			/* translators: %d: number of events. */
			'price_increase' => _n( '%d price increase', '%d price increases', $count, 'mlsimport' ),
			/* translators: %d: number of events. */
			'status_change'  => _n( '%d status change', '%d status changes', $count, 'mlsimport' ),
			/* translators: %d: number of events. */
			'under_contract' => _n( '%d listing under contract', '%d listings under contract', $count, 'mlsimport' ),
			/* translators: %d: number of events. */
			'sold'           => _n( '%d listing sold', '%d listings sold', $count, 'mlsimport' ),
			/* translators: %d: number of events. */
			'off_market'     => _n( '%d listing off market', '%d listings off market', $count, 'mlsimport' ),
			/* translators: %d: number of events. */
			'back_on_market' => _n( '%d listing back on market', '%d listings back on market', $count, 'mlsimport' ),
			/* translators: %d: number of events. */
			'agent_change'   => _n( '%d agent or office change', '%d agent or office changes', $count, 'mlsimport' ),
			/* translators: %d: number of events. */
			'content_change' => _n( '%d photo or description change', '%d photo or description changes', $count, 'mlsimport' ),
		);
		/* translators: %d: number of events. */
		return sprintf( $forms[ $kind ] ?? _n( '%d change', '%d changes', $count, 'mlsimport' ), $count );
	}

	/**
	 * Return the notification kind of one stored event row.
	 *
	 * @param array<string, mixed> $row Events table row (event_type, old_value, new_value).
	 * @return string One key of kind_labels(), or the stored type when unknown.
	 */
	public static function kind( array $row ): string {
		$type = (string) ( $row['event_type'] ?? '' );
		$old  = (string) ( $row['old_value'] ?? '' );
		$new  = (string) ( $row['new_value'] ?? '' );

		// A price change is told by its direction.
		if ( 'price_change' === $type ) {
			return (float) $new < (float) $old ? 'price_drop' : 'price_increase';
		}

		// A removal is told by the status the listing left with.
		if ( 'removed' === $type ) {
			$status = mlsimport_normalize_status_enum( $new );
			if ( in_array( $status, self::UNDER_CONTRACT, true ) ) {
				return 'under_contract';
			}
			return in_array( $status, self::CLOSED, true ) ? 'sold' : 'off_market';
		}

		return $type;
	}

	/**
	 * Decide whether one event is emailed under one MLS's filter.
	 *
	 * @param array<string, mixed> $row   Events table row, 'facts' as stored JSON.
	 * @param array<string, mixed> $rules Filter: city[], neighborhood[], zip[],
	 *                                    property_type[], status[], min_price, max_price.
	 * @param array<int, string>   $kinds Notification kinds the admin ticked.
	 * @return bool Whether the event belongs in the email.
	 */
	public static function matches( array $row, array $rules, array $kinds ): bool {
		// Step 1: the kind must be selected.
		if ( ! in_array( self::kind( $row ), $kinds, true ) ) {
			return false;
		}

		// Step 2: no captured details means nothing to filter on.
		$facts = json_decode( (string) ( $row['facts'] ?? '' ), true );
		if ( ! is_array( $facts ) || empty( $facts ) ) {
			return true;
		}

		// Step 3: list fields. A field with no values does not restrict.
		foreach ( array( 'city', 'neighborhood', 'zip', 'property_type', 'status' ) as $field ) {
			$allowed = array_filter( array_map( 'strval', (array) ( $rules[ $field ] ?? array() ) ), 'strlen' );
			if ( empty( $allowed ) ) {
				continue;
			}
			$fold  = 'status' === $field ? 'mlsimport_normalize_status_enum' : array( __CLASS__, 'fold' );
			$value = call_user_func( $fold, (string) ( $facts[ $field ] ?? '' ) );
			if ( '' === $value || ! in_array( $value, array_map( $fold, $allowed ), true ) ) {
				return false;
			}
		}

		// Step 4: price range, edges included. Zero means "no limit".
		$min = (float) ( $rules['min_price'] ?? 0 );
		$max = (float) ( $rules['max_price'] ?? 0 );
		if ( $min > 0 || $max > 0 ) {
			$price = $facts['price'] ?? '';
			if ( ! is_numeric( $price ) ) {
				return false;
			}
			if ( ( $min > 0 && (float) $price < $min ) || ( $max > 0 && (float) $price > $max ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Fold a text value for comparison: trimmed and lowercased.
	 *
	 * @param string $value City, neighborhood, zip or property type.
	 * @return string Comparison key.
	 */
	public static function fold( string $value ): string {
		return strtolower( trim( $value ) );
	}
}
