<?php
/**
 * Standalone (theme_id 990) mlsimport_listings flat search table.
 *
 * One row per listing, one column per filterable/sortable field (§8). Created
 * via dbDelta() and versioned by the mlsimport_listings_db_version option, so
 * activation and the guarded admin_init upgrade are idempotent.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the standalone listings search table.
 */
class Mlsimport_Standalone_Table {

	/**
	 * Schema version. Bump when the CREATE TABLE below changes.
	 */
	private const DB_VERSION = '1';

	/**
	 * Fully-qualified table name (with the site's table prefix).
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'mlsimport_listings';
	}

	/**
	 * Create or upgrade the table via dbDelta(), then record the schema version.
	 *
	 * @return void
	 */
	public static function create(): void {
		global $wpdb;

		// dbDelta() lives in the admin upgrade file, not loaded on the front end.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Prefixed table name + the site's charset/collation for the CREATE TABLE.
		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		// One row per listing; one column per filterable/sortable field, plus indexes
		// on the common filter/sort combinations and a FULLTEXT index for keywords.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			listing_key VARCHAR(64) NOT NULL DEFAULT '',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			mls_id VARCHAR(64) NOT NULL DEFAULT '',
			modification_timestamp DATETIME DEFAULT NULL,
			price DECIMAL(15,2) DEFAULT NULL,
			bedrooms DECIMAL(5,1) DEFAULT NULL,
			bathrooms DECIMAL(5,1) DEFAULT NULL,
			living_area DECIMAL(12,2) DEFAULT NULL,
			lot_size DECIMAL(12,2) DEFAULT NULL,
			year_built SMALLINT UNSIGNED DEFAULT NULL,
			latitude DECIMAL(10,7) DEFAULT NULL,
			longitude DECIMAL(10,7) DEFAULT NULL,
			city VARCHAR(128) NOT NULL DEFAULT '',
			state VARCHAR(64) NOT NULL DEFAULT '',
			zip VARCHAR(16) NOT NULL DEFAULT '',
			property_type VARCHAR(64) NOT NULL DEFAULT '',
			listing_type VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(32) NOT NULL DEFAULT '',
			list_date DATETIME DEFAULT NULL,
			garage_spaces SMALLINT DEFAULT NULL,
			stories SMALLINT DEFAULT NULL,
			hoa_fee DECIMAL(10,2) DEFAULT NULL,
			days_on_market INT DEFAULT NULL,
			subdivision VARCHAR(128) NOT NULL DEFAULT '',
			search_text TEXT,
			PRIMARY KEY  (id),
			UNIQUE KEY listing_key (listing_key),
			KEY post_id (post_id),
			KEY modification_timestamp (modification_timestamp),
			KEY status_type_price (status, property_type, price),
			KEY city_status_price (city, status, price),
			KEY lat_lng (latitude, longitude),
			KEY price (price),
			KEY list_date (list_date),
			FULLTEXT KEY search_text (search_text)
		) {$collate};";

		// dbDelta diffs the schema and creates/alters columns/indexes as needed.
		dbDelta( $sql );

		// Record the schema version so maybe_upgrade() can skip until the next bump.
		update_option( 'mlsimport_listings_db_version', self::DB_VERSION );
	}

	/**
	 * Run create() only when the stored schema version is out of date. Cheap
	 * enough to call on every admin_init.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		// Stored version matches the code's DB_VERSION: nothing to do.
		if ( get_option( 'mlsimport_listings_db_version' ) === self::DB_VERSION ) {
			return;
		}
		// Out of date (or never created): (re)run the idempotent CREATE TABLE.
		self::create();
	}
}
