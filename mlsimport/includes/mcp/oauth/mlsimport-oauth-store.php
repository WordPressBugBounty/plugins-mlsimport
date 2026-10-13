<?php
/**
 * AI app sign-in (OAuth): storage of approval codes and Connected AI Apps.
 *
 * Three kinds of record:
 *   - Registered apps (table mlsimport_oauth_clients): see
 *     mlsimport-oauth-clients.php. The table is created here.
 *   - Approval codes (transients, 10 minutes, single use): what the consent
 *     screen hands back to the AI app, exchanged once for tokens.
 *   - Connected AI Apps (table mlsimport_oauth_grants): one row per approval
 *     by an administrator. It holds the HASH of the current access token and
 *     refresh token, never the tokens themselves, plus the hashes of the
 *     refresh tokens it replaced (so a replaced one showing up again is
 *     recognised, however many rotations ago it was replaced).
 *
 * Table names come only from the functions below, never from request input.
 *
 * Public interface:
 *   mlsimport_oauth_maybe_install()
 *   mlsimport_oauth_code_create( $data )                     / mlsimport_oauth_code_take( $code )
 *   mlsimport_oauth_grant_create( $client, $redirect_uri, $user_id )
 *   mlsimport_oauth_grant_rotate( $grant_id, $replaced_refresh_hash )
 *   mlsimport_oauth_grant_find( $column, $token )            / mlsimport_oauth_grant_find_replaced( $token )
 *   mlsimport_oauth_grant_touch( $grant_id )                 / mlsimport_oauth_grant_delete( $grant_id )
 *   mlsimport_oauth_grants()                                 / mlsimport_oauth_delete_everything()
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp/oauth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema version. Bump to re-run dbDelta.
 * 1.1: prev_refresh_hash (one generation of reuse detection).
 * 1.2: old_refresh_hashes (every replaced refresh token); prev_refresh_hash
 *      is no longer read (dbDelta leaves the old column in place, unused).
 */
define( 'MLSIMPORT_OAUTH_DB_VERSION', '1.2' );

/** Replaced refresh-token hashes kept per Connected AI App (65 chars each, more than a month of hourly refreshes). */
define( 'MLSIMPORT_OAUTH_REPLACED_KEEP', 1000 );

/**
 * The Connected AI Apps table name.
 *
 * @return string
 */
function mlsimport_oauth_grants_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'mlsimport_oauth_grants';
}

/**
 * Create the two tables when the stored schema version is outdated.
 *
 * Called when AI assistant access starts on a request, so a site that never
 * turns the switch on never gets the tables.
 *
 * @return void
 */
function mlsimport_oauth_maybe_install(): void {
	if ( get_option( 'mlsimport_oauth_db_version' ) === MLSIMPORT_OAUTH_DB_VERSION ) {
		return;
	}

	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	$clients = mlsimport_oauth_clients_table();
	$grants  = mlsimport_oauth_grants_table();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		"CREATE TABLE {$clients} (
  client_id VARCHAR(64) NOT NULL,
  client_name VARCHAR(191) NOT NULL DEFAULT '',
  redirect_uris TEXT NOT NULL,
  created_at INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (client_id),
  KEY created_at (created_at)
) {$charset};"
	);
	dbDelta(
		"CREATE TABLE {$grants} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id VARCHAR(64) NOT NULL DEFAULT '',
  client_name VARCHAR(191) NOT NULL DEFAULT '',
  redirect_domain VARCHAR(191) NOT NULL DEFAULT '',
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  access_hash CHAR(64) NOT NULL DEFAULT '',
  access_expires INT UNSIGNED NOT NULL DEFAULT 0,
  refresh_hash CHAR(64) NOT NULL DEFAULT '',
  old_refresh_hashes TEXT,
  created_at INT UNSIGNED NOT NULL DEFAULT 0,
  last_used INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY access_hash (access_hash),
  KEY refresh_hash (refresh_hash),
  KEY client_id (client_id)
) {$charset};"
	);
	update_option( 'mlsimport_oauth_db_version', MLSIMPORT_OAUTH_DB_VERSION );
}

/**
 * Create a single-use approval code holding the approval's details.
 *
 * @param array $data client_id, redirect_uri, user_id, code_challenge.
 * @return string The code handed to the AI app.
 */
function mlsimport_oauth_code_create( array $data ): string {
	$code = mlsimport_oauth_new_token();
	set_transient( 'mlsimport_oauth_code_' . mlsimport_oauth_hash( $code ), $data, MLSIMPORT_OAUTH_CODE_LIFETIME );
	return $code;
}

/**
 * Take an approval code: read its details and destroy it (single use).
 *
 * @param string $code Code sent by the AI app.
 * @return array|null The approval's details, or null when unknown, used or expired.
 */
function mlsimport_oauth_code_take( string $code ): ?array {
	$key  = 'mlsimport_oauth_code_' . mlsimport_oauth_hash( $code );
	$data = get_transient( $key );
	// Only the request that really removed the code may use it, so two
	// requests arriving together cannot both redeem one code.
	if ( ! delete_transient( $key ) ) {
		return null;
	}
	return is_array( $data ) ? $data : null;
}

/**
 * Create a Connected AI App for an approval and return its first tokens.
 *
 * @param array  $client       The registered app.
 * @param string $redirect_uri The return address the approval used.
 * @param int    $user_id      The administrator who approved.
 * @return array|null {access_token, refresh_token, expires_in}; null only if the row could not be written.
 */
function mlsimport_oauth_grant_create( array $client, string $redirect_uri, int $user_id ): ?array {
	global $wpdb;
	$wpdb->insert(
		mlsimport_oauth_grants_table(),
		array(
			'client_id'          => $client['client_id'],
			'client_name'        => $client['client_name'],
			'redirect_domain'    => mlsimport_oauth_redirect_domain( $redirect_uri ),
			'user_id'            => $user_id,
			'old_refresh_hashes' => '',
			'created_at'         => time(),
			'last_used'          => time(),
		),
		array( '%s', '%s', '%s', '%d', '%s', '%d', '%d' )
	);
	// A new row has no refresh token yet: '' is the hash being replaced.
	return mlsimport_oauth_grant_rotate( (int) $wpdb->insert_id, '' );
}

/**
 * Give a Connected AI App a new access token and a new refresh token.
 *
 * The new pair is written only if the row still holds the refresh token
 * the caller validated: the UPDATE names that hash, so of two refreshes
 * racing with the same token exactly one wins and the other gets null
 * (the row changed under it). The replaced hash is appended to the row's
 * history, which keeps the newest MLSIMPORT_OAUTH_REPLACED_KEEP entries.
 *
 * @param int    $grant_id              Connected AI App row id.
 * @param string $replaced_refresh_hash The refresh hash the row must still hold ('' on a new row).
 * @return array|null {access_token, refresh_token, expires_in}, or null when the row no longer holds that hash.
 */
function mlsimport_oauth_grant_rotate( int $grant_id, string $replaced_refresh_hash ): ?array {
	global $wpdb;
	$table   = mlsimport_oauth_grants_table();
	$access  = mlsimport_oauth_new_token();
	$refresh = mlsimport_oauth_new_token();
	$rows    = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the function above.
			"UPDATE {$table} SET old_refresh_hashes = RIGHT( CONCAT( COALESCE( old_refresh_hashes, '' ), IF( refresh_hash = '', '', CONCAT( refresh_hash, ' ' ) ) ), %d ), access_hash = %s, access_expires = %d, refresh_hash = %s, last_used = %d WHERE id = %d AND refresh_hash = %s",
			MLSIMPORT_OAUTH_REPLACED_KEEP * 65,
			mlsimport_oauth_hash( $access ),
			time() + MLSIMPORT_OAUTH_ACCESS_LIFETIME,
			mlsimport_oauth_hash( $refresh ),
			time(),
			$grant_id,
			$replaced_refresh_hash
		)
	);
	if ( 1 !== $rows ) {
		return null;
	}
	return array(
		'access_token'  => $access,
		'refresh_token' => $refresh,
		'expires_in'    => MLSIMPORT_OAUTH_ACCESS_LIFETIME,
	);
}

/**
 * Find the Connected AI App a current token belongs to.
 *
 * @param string $column 'access_hash' or 'refresh_hash'.
 * @param string $token  The token as sent by the AI app.
 * @return array|null The row, or null when no app holds this token.
 */
function mlsimport_oauth_grant_find( string $column, string $token ): ?array {
	global $wpdb;
	if ( '' === $token || ! in_array( $column, array( 'access_hash', 'refresh_hash' ), true ) ) {
		return null;
	}
	$table = mlsimport_oauth_grants_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the function above; column from the fixed list checked above.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s", mlsimport_oauth_hash( $token ) ), ARRAY_A );
	return is_array( $row ) ? $row : null;
}

/**
 * Find the Connected AI App whose refresh token this WAS, before a rotation
 * replaced it. The table holds a handful of rows, so the text scan is cheap.
 *
 * @param string $token The refresh token as sent by the AI app.
 * @return array|null The row, or null when no app ever held this token (within the history kept).
 */
function mlsimport_oauth_grant_find_replaced( string $token ): ?array {
	global $wpdb;
	if ( '' === $token ) {
		return null;
	}
	$table = mlsimport_oauth_grants_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the function above.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE old_refresh_hashes LIKE %s", '%' . $wpdb->esc_like( mlsimport_oauth_hash( $token ) ) . '%' ), ARRAY_A );
	return is_array( $row ) ? $row : null;
}

/**
 * Record that a Connected AI App was just used.
 *
 * @param int $grant_id Connected AI App row id.
 * @return void
 */
function mlsimport_oauth_grant_touch( int $grant_id ): void {
	global $wpdb;
	$wpdb->update( mlsimport_oauth_grants_table(), array( 'last_used' => time() ), array( 'id' => $grant_id ), array( '%d' ), array( '%d' ) );
}

/**
 * Disconnect one Connected AI App. Its tokens stop working at once.
 *
 * @param int $grant_id Connected AI App row id.
 * @return void
 */
function mlsimport_oauth_grant_delete( int $grant_id ): void {
	global $wpdb;
	$wpdb->delete( mlsimport_oauth_grants_table(), array( 'id' => $grant_id ), array( '%d' ) );
}

/**
 * Every Connected AI App, newest first (for the Tools tab list).
 *
 * @return array[]
 */
function mlsimport_oauth_grants(): array {
	global $wpdb;
	$table = mlsimport_oauth_grants_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the function above.
	return (array) $wpdb->get_results( "SELECT id, client_name, redirect_domain, user_id, created_at, last_used FROM {$table} ORDER BY id DESC", ARRAY_A );
}

/**
 * Remove every Connected AI App and every registered app.
 *
 * Runs when the Tools tab switch is turned off: turning it on again starts clean.
 *
 * @return void
 */
function mlsimport_oauth_delete_everything(): void {
	global $wpdb;
	if ( get_option( 'mlsimport_oauth_db_version' ) !== MLSIMPORT_OAUTH_DB_VERSION ) {
		return;
	}
	$clients = mlsimport_oauth_clients_table();
	$grants  = mlsimport_oauth_grants_table();
	$wpdb->query( "DELETE FROM {$grants}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the function above.
	$wpdb->query( "DELETE FROM {$clients}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the function above.
}
