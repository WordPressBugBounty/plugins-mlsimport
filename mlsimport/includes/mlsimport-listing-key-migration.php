<?php
/**
 * One-time migration of the listing identity into protected meta (issue #286).
 *
 * Older versions stored each Managed Listing's identity in the visible
 * 'ListingKey' post meta. WordPress compares meta keys case-insensitively, so
 * that row is the SAME database row as the theme-projected 'listingkey' custom
 * field; WPResidence's edit screen saved that field blank and erased the
 * identity, making the next import duplicate the listing. Identity now lives
 * in '_mlsimport_listing_key' (underscore = protected meta, invisible to theme
 * custom-field saves and the Custom Fields box).
 *
 * Step by step:
 *   1. On init, bail immediately when the 'mlsimport_listing_key_migrated'
 *      option says the copy already ran.
 *   2. Otherwise copy every non-empty legacy 'ListingKey' value into a new
 *      '_mlsimport_listing_key' row for posts that do not have one yet, in a
 *      single INSERT..SELECT so a 16k-listing site migrates in one statement.
 *   3. Record the flag so the copy never runs again. The legacy rows are left
 *      in place untouched; nothing reads them any more.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy legacy 'ListingKey' identities into '_mlsimport_listing_key' once.
 *
 * Safe to call on every request: after the first successful run the option
 * flag short-circuits it before any database work.
 *
 * @return void
 */
function mlsimport_migrate_listing_key_identity(): void {
	// Step 1: the copy is one-time; the option flag makes later calls free.
	if ( get_option( 'mlsimport_listing_key_migrated' ) ) {
		return;
	}

	global $wpdb;

	// Step 2: copy each post's non-empty legacy identity into the protected
	// key, skipping posts that already carry one (re-runs stay idempotent).
	// Intentional direct query: update_post_meta() per post would issue tens of
	// thousands of statements on large sites.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
		 SELECT legacy.post_id, '_mlsimport_listing_key', legacy.meta_value
		 FROM {$wpdb->postmeta} legacy
		 LEFT JOIN {$wpdb->postmeta} protected_key
		    ON protected_key.post_id = legacy.post_id
		   AND protected_key.meta_key = '_mlsimport_listing_key'
		 WHERE legacy.meta_key = 'ListingKey'
		   AND legacy.meta_value != ''
		   AND protected_key.meta_id IS NULL"
	);

	// Step 3: mark the copy done so it never runs again.
	update_option( 'mlsimport_listing_key_migrated', defined( 'MLSIMPORT_VERSION' ) ? MLSIMPORT_VERSION : '1' );
}

// Run before any import work can happen in the same request: both admin
// screens and cron entry points pass through init.
add_action( 'init', 'mlsimport_migrate_listing_key_identity', 5 );
