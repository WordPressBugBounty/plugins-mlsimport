<?php
/**
 * Single-MLS -> multi-MLS migration (issue #274, decisions #270 / #263 / #266).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * An existing single-MLS install must silently become a one-connection
 * multi-MLS install on plugin update — and be able to roll the plugin back
 * with zero loss. The rules (decision #270):
 *
 *   COPY, NEVER MOVE. Flat globals are copied into the priority-1 connection
 *   record and into "_{mls_id}"-suffixed options. The originals are left
 *   untouched, so a downgraded plugin reads them exactly as before. No
 *   reverse migration exists or is needed.
 *
 *   PURE-LOCAL. No SaaS calls — migration works offline and never fails on
 *   network state.
 *
 *   SET-BASED IDEMPOTENT STAMPING. Existing listing posts, import tasks and
 *   standalone rows get their provenance (which MLS they came from) via three
 *   SQL statements, each scoped to not-yet-stamped rows, so a crashed run
 *   resumes safely on the next load.
 *
 *   LOAD-TIME GATE. The 'mlsimport_multimls_migrated' flag is compared on
 *   every load (same pattern as mlsimport_installed_version) and written only
 *   after EVERY step succeeds; until then each load retries and the per-step
 *   "already done" guards make retries no-ops.
 *
 * The option mapping itself is a PURE function (flat state in => connection
 * record + option copies out) so it is unit-testable without WordPress.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translate one slot-named credential option key into its adapter-generic name.
 *
 * Flat credentials are provider-slot-named ('mlsimport_tresle_client_id'), so
 * two same-provider MLSs collide. Connection records store them under generic
 * names instead (decision #263: creds => {client_id, client_secret, ...}).
 * Every credential key the plugin has ever stored ends in exactly one of the
 * generic names below, so the suffix IS the generic name.
 *
 * @param string $field Slot-named option key (e.g. 'mlsimport_rapattoni_username').
 * @return string Generic credential name, or '' when the key is not a credential.
 */
function mlsimport_multimls_generic_credential_name( string $field ): string {
	// Longest suffixes first so 'client_secret' never half-matches as 'secret'.
	foreach ( array( 'client_secret', 'client_id', 'mls_token', 'username', 'password' ) as $generic ) {
		if ( substr( $field, -strlen( $generic ) ) === $generic ) {
			return $generic;
		}
	}
	return '';
}

/**
 * PURE option mapping: flat single-MLS state in => migration plan out.
 *
 * Step by step:
 * 1. Read the MLS id from the flat admin options ('mlsimport_mls_name' holds
 *    the numeric id). Empty/non-numeric => unconfigured install: the plan is
 *    a null connection with nothing to copy (clean zero-connections state).
 * 2. Resolve the provider adapter from the saved type (numeric-range fallback
 *    inside the Provider Family module covers legacy installs with no type).
 * 3. Copy the adapter's declared credential fields out of the flat options
 *    into generic names — values verbatim, credentials are never sanitized.
 * 4. Assemble the priority-1 connection record (status from the connection
 *    test flag, live config as stored).
 * 5. List the option copies: field selection, sync settings and the three
 *    metadata blobs become "_{mls_id}"-suffixed copies. The theme schema
 *    stays global (decision #264) and is deliberately absent here.
 *
 * @param array $flat {
 *     Flat single-MLS state, read by the caller.
 *
 *     @type array  $admin_options   The mlsimport_admin_options array.
 *     @type string $provider_type   Saved provider type for this MLS ('' when none).
 *     @type string $connection_test The mlsimport_connection_test flag ('yes' or '').
 *     @type array  $live_config     The mlsimport_live_mls_config array.
 * }
 * @return array {
 *     @type array|null $connection   Priority-1 record, or null when unconfigured.
 *     @type array      $copy_options Source option name => suffixed destination name.
 * }
 */
function mlsimport_multimls_migration_plan( array $flat ): array {
	$options = isset( $flat['admin_options'] ) && is_array( $flat['admin_options'] )
		? $flat['admin_options']
		: array();

	// Step 1: the MLS id is the whole trigger — no id means nothing to migrate.
	$mls_id = (int) trim( (string) ( $options['mlsimport_mls_name'] ?? '' ) );
	if ( $mls_id <= 0 ) {
		return array(
			'connection'   => null,
			'copy_options' => array(),
		);
	}

	// Step 2: resolve the adapter; its type() is the authoritative stored type.
	$adapter = Mlsimport_Provider_Family::adapter( (string) ( $flat['provider_type'] ?? '' ), $mls_id );

	// Step 3: slot-named flat credentials => generic-named record credentials.
	$creds = array();
	foreach ( $adapter->credential_fields() as $field ) {
		$generic = mlsimport_multimls_generic_credential_name( (string) $field );
		if ( '' !== $generic ) {
			// Credential values are stored verbatim (trim only) — see #204.
			$creds[ $generic ] = trim( (string) ( $options[ $field ] ?? '' ) );
		}
	}

	// Step 4: the migrated install's single connection is priority 1.
	$connection = array(
		'mls_id'        => $mls_id,
		'mls_name'      => (string) ( $options['mlsimport_mls_name_front'] ?? '' ),
		'provider_type' => $adapter->type(),
		'creds'         => $creds,
		'status'        => 'yes' === ( $flat['connection_test'] ?? '' ) ? 'yes' : '',
		'live_config'   => isset( $flat['live_config'] ) && is_array( $flat['live_config'] ) ? $flat['live_config'] : array(),
		'priority'      => 1,
	);

	// Step 5: the large per-MLS state becomes suffixed copies (COPIES — the
	// flat sources stay untouched so downgrade keeps working).
	$copy_options = array();
	foreach ( array(
		'mlsimport_admin_fields_select',
		'mlsimport_admin_mls_sync',
		'mlsimport_mls_metadata_mls_data',
		'mlsimport_mls_metadata_mls_enums',
		'mlsimport_mls_metadata_populated',
	) as $base ) {
		$copy_options[ $base ] = $base . '_' . $mls_id;
	}

	return array(
		'connection'   => $connection,
		'copy_options' => $copy_options,
	);
}

/**
 * Run the one-time single-MLS -> multi-MLS migration (gated, resumable).
 *
 * Step by step:
 * 1. GATE: bail immediately when the migration flag says the run completed.
 * 2. Gather the flat state and build the pure plan above.
 * 3. Unconfigured install: write an empty (non-autoloaded) connections
 *    option, set the flag, done — clean zero-connections state.
 * 4. Register the priority-1 connection (skipped when a retry already did).
 * 5. Copy each planned option to its suffixed name (skipped per-option when
 *    a retry already created the copy; the flat source is never touched).
 * 6. Ensure the standalone table schema is current (the mls_id column and
 *    composite unique index arrive via the schema-version upgrade path).
 * 7. Stamp provenance with three set-based idempotent statements, each
 *    scoped to rows not yet stamped:
 *      a. listing posts (identified by '_mlsimport_listing_key') get
 *         'mlsimport_mls_id',
 *      b. import tasks (post_type mlsimport_item) get 'mlsimport_item_mls_id',
 *      c. standalone rows still at mls_id = 0 get the connection's id.
 * 8. Write the flag ONLY now — any earlier failure leaves it unset so the
 *    next load retries, and steps 4-7 are all no-ops for finished work.
 *
 * @return bool True when the migration is complete (now or previously).
 */
function mlsimport_multimls_migrate(): bool {
	global $wpdb;

	// Step 1: the completed flag makes every later call free.
	if ( get_option( 'mlsimport_multimls_migrated' ) ) {
		return true;
	}

	// Step 2: flat state in, pure plan out.
	$admin_options = get_option( 'mlsimport_admin_options', array() );
	$mls_id_raw    = is_array( $admin_options ) ? (string) ( $admin_options['mlsimport_mls_name'] ?? '' ) : '';
	$plan          = mlsimport_multimls_migration_plan(
		array(
			'admin_options'   => is_array( $admin_options ) ? $admin_options : array(),
			'provider_type'   => Mlsimport_Provider_Family::saved_type( $mls_id_raw ),
			'connection_test' => (string) get_option( 'mlsimport_connection_test', '' ),
			'live_config'     => (array) get_option( 'mlsimport_live_mls_config', array() ),
		)
	);

	// Step 3: nothing configured => clean zero-connections state and done.
	if ( null === $plan['connection'] ) {
		if ( false === get_option( Mlsimport_Connections::OPTION, false ) ) {
			add_option( Mlsimport_Connections::OPTION, array(), '', 'no' );
		}
		update_option( 'mlsimport_multimls_migrated', MLSIMPORT_VERSION );
		return true;
	}

	$mls_id = (int) $plan['connection']['mls_id'];

	// Step 4: register the connection once; a retry that already saved it skips.
	if ( null === Mlsimport_Connections::get( $mls_id ) ) {
		if ( ! Mlsimport_Connections::save( $plan['connection'] ) ) {
			return false;
		}
	}

	// Step 5: copy the large per-MLS state. Copies are non-autoloaded (read on
	// demand only); each copy is skipped when a prior retry already made it.
	foreach ( $plan['copy_options'] as $source => $destination ) {
		$value = get_option( $source, false );
		if ( false === $value || false !== get_option( $destination, false ) ) {
			continue;
		}
		add_option( $destination, $value, '', 'no' );
	}

	// Step 6: the mls_id column + composite unique index come from the
	// standalone table's own versioned upgrade path (idempotent).
	Mlsimport_Standalone_Table::maybe_upgrade();

	// Step 7a: stamp listing posts. DISTINCT guards against a post carrying
	// duplicate identity rows; the LEFT JOIN scopes to not-yet-stamped posts.
	// Direct SQL is intentional: per-post meta calls would issue tens of
	// thousands of statements on large sites (same as the #286 migration).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$stamped_listings = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
			 SELECT DISTINCT identity.post_id, 'mlsimport_mls_id', %d
			 FROM {$wpdb->postmeta} identity
			 LEFT JOIN {$wpdb->postmeta} stamped
			    ON stamped.post_id = identity.post_id
			   AND stamped.meta_key = 'mlsimport_mls_id'
			 WHERE identity.meta_key = '_mlsimport_listing_key'
			   AND stamped.meta_id IS NULL",
			$mls_id
		)
	);
	if ( false === $stamped_listings ) {
		return false;
	}

	// Step 7b: stamp import tasks (every status — trashed tasks can be
	// restored and must keep their binding).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$stamped_tasks = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
			 SELECT p.ID, 'mlsimport_item_mls_id', %d
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} stamped
			    ON stamped.post_id = p.ID
			   AND stamped.meta_key = 'mlsimport_item_mls_id'
			 WHERE p.post_type = 'mlsimport_item'
			   AND stamped.meta_id IS NULL",
			$mls_id
		)
	);
	if ( false === $stamped_tasks ) {
		return false;
	}

	// Step 7c: stamp standalone rows still at the DEFAULT 0 backstop.
	$table = Mlsimport_Standalone_Table::table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$stamped_rows = $wpdb->query(
		$wpdb->prepare( "UPDATE {$table} SET mls_id = %d WHERE mls_id = 0", $mls_id )
	);
	if ( false === $stamped_rows ) {
		return false;
	}

	// The set-based stamps above write postmeta with direct SQL, which never
	// invalidates the object cache — on a persistent-cache site get_post_meta
	// would keep serving the pre-stamp (empty) meta until eviction, so tasks
	// read as unbound. One flush for a one-time migration clears all of it.
	wp_cache_flush();

	// Step 8: every step succeeded — only now does the gate close.
	update_option( 'mlsimport_multimls_migrated', MLSIMPORT_VERSION );
	return true;
}

// Priority 6: AFTER the listing-key identity migration (init priority 5,
// includes/mlsimport-listing-key-migration.php) — stamping finds listing posts
// by '_mlsimport_listing_key', which that migration creates on old sites.
add_action( 'init', 'mlsimport_multimls_migrate', 6 );
