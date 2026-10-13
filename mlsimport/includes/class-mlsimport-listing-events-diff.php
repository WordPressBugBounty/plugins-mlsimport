<?php
/**
 * Pure diff of a listing's stored signals against its incoming signals.
 *
 * The Stored Listing Write module is the only place where the old and the
 * new value of a listing coexist. Before it overwrites the stored price and
 * status it reads them, and this class turns "stored vs incoming" into a list
 * of Listing Change Events (see CONTEXT.md). It is deliberately free of any
 * WordPress call so it can be unit-tested with plain PHPUnit.
 *
 * Signals are `array( 'price' => string, 'status' => string, 'facts' => array )`.
 * Both statuses must already be normalized by mlsimport_normalize_status_enum();
 * the price is the raw numeric string as stored / as sent by the MLS; 'facts'
 * is the Mlsimport_Listing_Event_Facts snapshot (stored one vs incoming one).
 *
 * Rules, in order:
 *   1. price  — skipped when either side is '' or non-numeric (no baseline or
 *               unreadable); `price_change` when the two floats differ.
 *   2. status — skipped when either side is '' (an unreadable stored status is
 *               no baseline; an empty incoming status carries no information,
 *               mirroring the "empty status never deletes" invariant);
 *               `status_change` when the normalized values differ.
 *   3. agent  — `agent_change` when the listing agent key or the listing office
 *               key differs; a key that is '' on either side is skipped (a
 *               listing written before the snapshot existed has no baseline).
 *               Old / new values are "Agent name, Office name".
 *   4. content — `content_change` when the photos timestamp or the description
 *               fingerprint differs (same '' rule); the new value names what
 *               changed: "photos", "description" or "photos, description".
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turn stored + incoming listing signals into Listing Change Events.
 */
final class Mlsimport_Listing_Events_Diff {

	/**
	 * Compare stored signals with incoming signals and list what changed.
	 *
	 * Step 1: price — both sides must be numeric, then compare as floats so
	 *         "450000" and "450000.00" are the same price.
	 * Step 2: status — both sides must be non-empty, then compare the normalized
	 *         strings exactly.
	 *
	 * @param array<string, mixed> $stored   Stored 'price', 'status' and 'facts'.
	 * @param array<string, mixed> $incoming Incoming 'price', 'status' and 'facts'.
	 * @return array<int, array<string, string>> Events as { type, old, new }.
	 */
	public static function diff( array $stored, array $incoming ): array {
		$events = array();

		// Step 1: price change. Only when there is a numeric baseline AND a
		// numeric incoming value; anything else is "unknown", not "changed".
		$old_price = (string) ( $stored['price'] ?? '' );
		$new_price = (string) ( $incoming['price'] ?? '' );
		if ( is_numeric( $old_price ) && is_numeric( $new_price ) && (float) $old_price !== (float) $new_price ) {
			$events[] = array(
				'type' => 'price_change',
				'old'  => $old_price,
				'new'  => $new_price,
			);
		}

		// Step 2: status change. An empty side is "unknown", never a transition:
		// the feed omitted the status, or the stored one could not be read.
		$old_status = (string) ( $stored['status'] ?? '' );
		$new_status = (string) ( $incoming['status'] ?? '' );
		if ( '' !== $old_status && '' !== $new_status && $old_status !== $new_status ) {
			$events[] = array(
				'type' => 'status_change',
				'old'  => $old_status,
				'new'  => $new_status,
			);
		}

		// Step 3: listing agent / office. Compared by key, shown by name.
		$old_facts = is_array( $stored['facts'] ?? null ) ? $stored['facts'] : array();
		$new_facts = is_array( $incoming['facts'] ?? null ) ? $incoming['facts'] : array();
		if ( self::differs( $old_facts, $new_facts, 'agent' ) || self::differs( $old_facts, $new_facts, 'office' ) ) {
			$events[] = array(
				'type' => 'agent_change',
				'old'  => self::agent_label( $old_facts ),
				'new'  => self::agent_label( $new_facts ),
			);
		}

		// Step 4: photos / description.
		$changed = array();
		if ( self::differs( $old_facts, $new_facts, 'photos' ) ) {
			$changed[] = 'photos';
		}
		if ( self::differs( $old_facts, $new_facts, 'remarks' ) ) {
			$changed[] = 'description';
		}
		if ( ! empty( $changed ) ) {
			$events[] = array(
				'type' => 'content_change',
				'old'  => '',
				'new'  => implode( ', ', $changed ),
			);
		}

		return $events;
	}

	/**
	 * Whether one snapshot value changed. An empty side is "unknown", never a change.
	 *
	 * @param array<string, mixed> $old   Stored snapshot.
	 * @param array<string, mixed> $new   Incoming snapshot.
	 * @param string               $field Snapshot key.
	 * @return bool
	 */
	private static function differs( array $old, array $new, string $field ): bool {
		$before = (string) ( $old[ $field ] ?? '' );
		$after  = (string) ( $new[ $field ] ?? '' );
		return '' !== $before && '' !== $after && $before !== $after;
	}

	/**
	 * "Agent name, Office name" of one snapshot, skipping blanks.
	 *
	 * @param array<string, mixed> $facts Snapshot.
	 * @return string
	 */
	private static function agent_label( array $facts ): string {
		return implode( ', ', array_filter( array( (string) ( $facts['agent_name'] ?? '' ), (string) ( $facts['office_name'] ?? '' ) ), 'strlen' ) );
	}
}
