<?php
/**
 * Listing change notifications: the saved settings and the import-task prefill.
 *
 * Everything lives in ONE non-autoloaded option, `mlsimport_listing_notifications`:
 *
 *   event_types : notification kinds the admin ticked (see the matcher)
 *   emails      : addresses that receive the daily email
 *   connections : mls_id => {
 *       enabled, city[], neighborhood[], zip[], min_price, max_price,
 *       property_type[], status[], last_event_id
 *   }
 *
 * The first two are global (one setup for the whole site). Each MLS connection
 * has exactly one on/off switch and one filter.
 *
 * Prefill: an MLS that was never saved gets its filter from its import tasks.
 * Several tasks are combined into the one filter — every value of every task,
 * the lowest minimum price and the highest maximum price. When any one task
 * does not restrict a field (empty, or its "select all" box ticked) the
 * combined filter does not restrict it either. Status is never prefilled: it
 * starts empty, which means every status counts.
 *
 * `last_event_id` is the digest's only sent-state: the highest event id already
 * handled for that MLS. save() stamps it with the newest event id the first
 * time an MLS is saved, so changes recorded before the feature was switched on
 * are never emailed; later saves keep it.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read, sanitize and save the notification settings.
 */
final class Mlsimport_Listing_Notification_Settings {

	/** The single settings option. */
	const OPTION = 'mlsimport_listing_notifications';

	/** Filter fields that hold a list of text values. */
	const LIST_FIELDS = array( 'city', 'neighborhood', 'zip', 'property_type', 'status' );

	/**
	 * Return the global settings with defaults applied.
	 *
	 * Default kinds: everything except "photos or description changed", which
	 * is noisy and therefore opt-in.
	 *
	 * @return array<string, mixed> event_types, emails, connections.
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'event_types' => isset( $stored['event_types'] ) && is_array( $stored['event_types'] )
				? array_values( $stored['event_types'] )
				: array_values( array_diff( array_keys( Mlsimport_Listing_Notification_Matcher::kind_labels() ), array( 'content_change' ) ) ),
			'emails'      => isset( $stored['emails'] ) && is_array( $stored['emails'] ) ? array_values( $stored['emails'] ) : array(),
			'connections' => isset( $stored['connections'] ) && is_array( $stored['connections'] ) ? $stored['connections'] : array(),
		);
	}

	/**
	 * Return one MLS's switch and filter: the saved one, else the prefill.
	 *
	 * 'task_count' (how many import tasks the MLS has) is added for the admin
	 * list; 'last_event_id' is null until the MLS is first saved or first seen
	 * by the digest.
	 *
	 * @param int $mls_id MLS connection id.
	 * @return array<string, mixed> Connection rules.
	 */
	public static function connection( int $mls_id ): array {
		$prefill = self::prefill_from_tasks( $mls_id );
		$saved   = self::get()['connections'][ $mls_id ] ?? null;
		if ( ! is_array( $saved ) ) {
			return $prefill;
		}
		// A stored row may hold only the switch and the digest pointer. Merging
		// it over the prefill keeps the prefilled filter until a filter is saved.
		$rules                  = array_merge( $prefill, $saved );
		$rules['enabled']       = ! isset( $saved['enabled'] ) || ! empty( $saved['enabled'] );
		$rules['last_event_id'] = isset( $saved['last_event_id'] ) ? (int) $saved['last_event_id'] : null;
		$rules['task_count']    = $prefill['task_count'];
		return $rules;
	}

	/**
	 * Build one MLS's starting filter from its import tasks.
	 *
	 * Step 1: find the tasks bound to this MLS.
	 * Step 2: read each task's values; a ticked "select all" box empties the list.
	 * Step 3: combine them (see combine()).
	 *
	 * @param int $mls_id MLS connection id.
	 * @return array<string, mixed> Connection rules, enabled, status empty.
	 */
	public static function prefill_from_tasks( int $mls_id ): array {
		// Step 1: tasks of this connection, oldest first so the order is stable.
		$task_ids = get_posts(
			array(
				'post_type'      => 'mlsimport_item',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => 'mlsimport_item_mls_id',
				'meta_value'     => (string) $mls_id,
			)
		);

		// Step 2: one plain array per task.
		$tasks = array();
		foreach ( $task_ids as $task_id ) {
			$all_cities = 1 === (int) get_post_meta( $task_id, 'mlsimport_item_city_check', true );
			$all_types  = 1 === (int) get_post_meta( $task_id, 'mlsimport_item_propertytype_check', true );
			$tasks[]    = array(
				'city'          => $all_cities ? array() : array_merge(
					self::clean_list( get_post_meta( $task_id, 'mlsimport_item_city', true ) ),
					self::clean_list( get_post_meta( $task_id, 'mlsimport_item_extracity', true ) )
				),
				'neighborhood'  => self::clean_list( get_post_meta( $task_id, 'mlsimport_item_subdivisionname', true ) ),
				'zip'           => self::clean_list( get_post_meta( $task_id, 'mlsimport_item_postalcode', true ) ),
				'property_type' => $all_types ? array() : self::clean_list( get_post_meta( $task_id, 'mlsimport_item_propertytype', true ) ),
				'min_price'     => self::clean_price( get_post_meta( $task_id, 'mlsimport_item_min_price', true ) ),
				'max_price'     => self::clean_price( get_post_meta( $task_id, 'mlsimport_item_max_price', true ) ),
			);
		}

		// Step 3.
		return self::combine( $tasks );
	}

	/**
	 * Combine several import tasks into the one filter of an MLS.
	 *
	 * List fields: the union of all tasks, unless one task has no values for
	 * the field — then the field is unrestricted. Prices: lowest minimum and
	 * highest maximum, where "no limit" (0) in any task wins.
	 *
	 * @param array<int, array<string, mixed>> $tasks Task values from prefill_from_tasks().
	 * @return array<string, mixed> Connection rules.
	 */
	public static function combine( array $tasks ): array {
		$rules = array(
			'enabled'       => true,
			'city'          => array(),
			'neighborhood'  => array(),
			'zip'           => array(),
			'property_type' => array(),
			'status'        => array(),
			'min_price'     => 0.0,
			'max_price'     => 0.0,
			'last_event_id' => null,
			'task_count'    => count( $tasks ),
		);
		if ( empty( $tasks ) ) {
			return $rules;
		}

		foreach ( array( 'city', 'neighborhood', 'zip', 'property_type' ) as $field ) {
			$lists = array_column( $tasks, $field );
			// One task without values means the field is not restricted at all.
			$rules[ $field ] = in_array( array(), $lists, true )
				? array()
				: array_values( array_unique( array_merge( ...$lists ) ) );
		}

		$maximums           = array_column( $tasks, 'max_price' );
		$rules['min_price'] = (float) min( array_column( $tasks, 'min_price' ) );
		$rules['max_price'] = in_array( 0.0, $maximums, true ) ? 0.0 : (float) max( $maximums );
		return $rules;
	}

	/**
	 * Sanitize and store the global part of the section: the ticked kinds, the
	 * addresses and each MLS's on/off switch. Filters are not touched.
	 *
	 * Step 1: keep only known kinds and valid email addresses.
	 * Step 2: store the switch of every submitted MLS.
	 *
	 * @param array<string, mixed> $raw event_types[], emails (text or list),
	 *                                  connections[mls_id][enabled].
	 * @return void
	 */
	public static function save( array $raw ): void {
		$settings = self::get();

		// Step 1: global part.
		$kinds                   = array_keys( Mlsimport_Listing_Notification_Matcher::kind_labels() );
		$settings['event_types'] = array_values( array_intersect( array_map( 'strval', (array) ( $raw['event_types'] ?? array() ) ), $kinds ) );
		$settings['emails']      = array_values( array_filter( self::clean_list( $raw['emails'] ?? array() ), 'is_email' ) );

		// Step 2: switches.
		foreach ( (array) ( $raw['connections'] ?? array() ) as $mls_id => $input ) {
			$row            = self::saved_row( $settings, (int) $mls_id );
			$row['enabled'] = ! empty( ( (array) $input )['enabled'] );
			$settings['connections'][ (int) $mls_id ] = $row;
		}

		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Sanitize and store one MLS's filter. From now on it replaces the
	 * import-task prefill for that MLS.
	 *
	 * @param int                  $mls_id MLS connection id.
	 * @param array<string, mixed> $raw    city[], property_type[], status[],
	 *                                     neighborhood / zip (comma text), min_price, max_price.
	 * @return void
	 */
	public static function save_filter( int $mls_id, array $raw ): void {
		$settings = self::get();
		$row      = self::saved_row( $settings, $mls_id );
		foreach ( self::LIST_FIELDS as $field ) {
			$row[ $field ] = self::clean_list( $raw[ $field ] ?? array() );
		}
		$row['min_price'] = self::clean_price( $raw['min_price'] ?? '' );
		$row['max_price'] = self::clean_price( $raw['max_price'] ?? '' );
		$settings['connections'][ $mls_id ] = $row;
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Return one MLS's stored row, ready to be changed and saved back.
	 *
	 * A row that is stored for the first time gets its digest pointer set to
	 * the newest event id, so older changes are never emailed.
	 *
	 * @param array<string, mixed> $settings Settings from get().
	 * @param int                  $mls_id   MLS connection id.
	 * @return array<string, mixed> Stored row with 'last_event_id' set.
	 */
	private static function saved_row( array $settings, int $mls_id ): array {
		$row = is_array( $settings['connections'][ $mls_id ] ?? null ) ? $settings['connections'][ $mls_id ] : array();
		if ( ! isset( $row['last_event_id'] ) ) {
			$row['last_event_id'] = self::newest_event_id();
		}
		return $row;
	}

	/**
	 * Forget one MLS's saved filter so the import-task prefill applies again.
	 * The switch and the digest pointer are kept.
	 *
	 * @param int $mls_id MLS connection id.
	 * @return void
	 */
	public static function reset_filter( int $mls_id ): void {
		$settings = self::get();
		$settings['connections'][ $mls_id ] = array_intersect_key( self::saved_row( $settings, $mls_id ), array( 'enabled' => 1, 'last_event_id' => 1 ) );
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Store the digest pointer of one MLS (highest event id already handled).
	 *
	 * @param int $mls_id   MLS connection id.
	 * @param int $event_id Event id.
	 * @return void
	 */
	public static function set_pointer( int $mls_id, int $event_id ): void {
		$settings = self::get();
		$settings['connections'][ $mls_id ] = array( 'last_event_id' => $event_id ) + self::saved_row( $settings, $mls_id );
		update_option( self::OPTION, $settings, false );
	}

	/** @return int The newest event id in the events table (0 when it is empty). */
	public static function newest_event_id(): int {
		global $wpdb;
		$table = Mlsimport_Listing_Events::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
	}

	/**
	 * Turn a list or comma-separated text into a clean list of text values.
	 *
	 * @param mixed $value Array of values, or one comma-separated string.
	 * @return array<int, string> Trimmed, tag-free, non-empty, unique values.
	 */
	public static function clean_list( $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$items = array_map(
			static function ( $item ): string {
				return is_scalar( $item ) ? sanitize_text_field( (string) $item ) : '';
			},
			$items
		);
		return array_values( array_unique( array_filter( $items, 'strlen' ) ) );
	}

	/**
	 * Turn a typed price into a number; blank or unreadable is 0 (no limit).
	 *
	 * @param mixed $value Typed price, thousands separators allowed.
	 * @return float Price, never negative.
	 */
	public static function clean_price( $value ): float {
		$digits = preg_replace( '/[^0-9.]/', '', is_scalar( $value ) ? (string) $value : '' );
		return is_numeric( $digits ) ? max( 0.0, (float) $digits ) : 0.0;
	}
}
