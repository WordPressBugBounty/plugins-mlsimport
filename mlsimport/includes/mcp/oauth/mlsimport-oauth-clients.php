<?php
/**
 * AI app sign-in (OAuth): the registered apps (table mlsimport_oauth_clients).
 *
 * A registered app is an AI app that registered itself with an accepted
 * return address. It is not yet approved by anyone; approval creates a
 * Connected AI App (mlsimport-oauth-store.php). Registration is open to the
 * internet, so this file also keeps the table bounded: apps that registered
 * more than a day ago and were never approved are pruned, and the register
 * address counts what it accepts (mlsimport-oauth-guards.php).
 *
 * Table names come only from the functions below, never from request input.
 *
 * Public interface:
 *   mlsimport_oauth_clients_table()
 *   mlsimport_oauth_client_create( $name, $redirect_uris )   store a registration
 *   mlsimport_oauth_client_get( $client_id )                 read one
 *   mlsimport_oauth_clients_pending()                        how many were never approved
 *   mlsimport_oauth_clients_prune()                          drop day-old unapproved ones
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp/oauth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The registered-apps table name.
 *
 * @return string
 */
function mlsimport_oauth_clients_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'mlsimport_oauth_clients';
}

/**
 * Store a registered app and return its client id.
 *
 * @param string   $name          Name the app gave itself (proves nothing).
 * @param string[] $redirect_uris Accepted return addresses it registered.
 * @return string The new client id.
 */
function mlsimport_oauth_client_create( string $name, array $redirect_uris ): string {
	global $wpdb;
	$client_id = mlsimport_oauth_new_token();
	$wpdb->insert(
		mlsimport_oauth_clients_table(),
		array(
			'client_id'     => $client_id,
			'client_name'   => $name,
			'redirect_uris' => wp_json_encode( array_values( $redirect_uris ) ),
			'created_at'    => time(),
		),
		array( '%s', '%s', '%s', '%d' )
	);
	return $client_id;
}

/**
 * Read a registered app.
 *
 * @param string $client_id Client id.
 * @return array|null {client_id, client_name, redirect_uris[]} or null when unknown.
 */
function mlsimport_oauth_client_get( string $client_id ): ?array {
	global $wpdb;
	$table = mlsimport_oauth_clients_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from the function above.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s", $client_id ), ARRAY_A );
	if ( ! is_array( $row ) ) {
		return null;
	}
	$row['redirect_uris'] = (array) json_decode( (string) $row['redirect_uris'], true );
	return $row;
}

/**
 * How many registered apps nobody has approved.
 *
 * An app is approved when a Connected AI App row points at it.
 *
 * @return int
 */
function mlsimport_oauth_clients_pending(): int {
	global $wpdb;
	$clients = mlsimport_oauth_clients_table();
	$grants  = mlsimport_oauth_grants_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from the functions above.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clients} c LEFT JOIN {$grants} g ON g.client_id = c.client_id WHERE g.id IS NULL" );
}

/**
 * Drop registered apps that are more than a day old and were never approved.
 *
 * An approval code lives 10 minutes, so a day-old registration without a
 * Connected AI App will never be approved.
 *
 * @return void
 */
function mlsimport_oauth_clients_prune(): void {
	global $wpdb;
	$clients = mlsimport_oauth_clients_table();
	$grants  = mlsimport_oauth_grants_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from the functions above.
	$wpdb->query( $wpdb->prepare( "DELETE c FROM {$clients} c LEFT JOIN {$grants} g ON g.client_id = c.client_id WHERE g.id IS NULL AND c.created_at < %d", time() - DAY_IN_SECONDS ) );
}
