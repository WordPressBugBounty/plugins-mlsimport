<?php
/**
 * AI Activity: the record of what a Connected AI App changed on the site.
 *
 * Every AI tool call that changes something writes one entry here: which
 * tool, which administrator, which Connected AI App, and when. It is shown
 * on the Tools tab under "Connected AI apps".
 *
 * Two rules:
 *   - AI Activity never stores the values passed to a tool (no passwords, no
 *     MLS credentials), only which tool was called.
 *   - AI Activity is separate from Import History. Import History holds only
 *     added / edited / deleted listing rows and feeds the Saved Search mails;
 *     it is never written from here. An import the AI starts is a normal
 *     manual Import Run and reaches Import History the normal way.
 *
 * Storage: the last 100 entries in one option (not autoloaded).
 *
 * Public interface:
 *   mlsimport_mcp_set_current_app( $name, $grant_id )   sign-in layer: name the app of this request
 *   mlsimport_mcp_current_app()              the Connected AI App of this request ('' if none)
 *   mlsimport_mcp_current_grant()            its row id (0 if none)
 *   mlsimport_ai_activity_record( $tool )    add one entry
 *   mlsimport_ai_activity_entries()          entries, newest first
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option holding the AI Activity entries. */
define( 'MLSIMPORT_AI_ACTIVITY_OPTION', 'mlsimport_ai_activity' );

/** How many entries are kept. */
define( 'MLSIMPORT_AI_ACTIVITY_KEEP', 100 );

/**
 * Name the Connected AI App that made the current request.
 *
 * Called by the sign-in layer once it has matched the request's access token.
 *
 * @param string $name     App name as shown in "Connected AI apps".
 * @param int    $grant_id The Connected AI App's row id (its identity; the name can be changed).
 * @return void
 */
function mlsimport_mcp_set_current_app( string $name, int $grant_id ): void {
	$GLOBALS['mlsimport_mcp_current_app']   = $name;
	$GLOBALS['mlsimport_mcp_current_grant'] = $grant_id;
}

/**
 * The row id of the Connected AI App that made the current request.
 *
 * @return int 0 when the request did not come through a Connected AI App.
 */
function mlsimport_mcp_current_grant(): int {
	return (int) ( $GLOBALS['mlsimport_mcp_current_grant'] ?? 0 );
}

/**
 * The Connected AI App that made the current request.
 *
 * @return string App name, or '' when the request did not come through a
 *                Connected AI App (a developer testing with an Application
 *                Password).
 */
function mlsimport_mcp_current_app(): string {
	return (string) ( $GLOBALS['mlsimport_mcp_current_app'] ?? '' );
}

/**
 * Add one AI Activity entry for a tool call that changes something.
 *
 * Step by step:
 * 1. Build the entry: tool, administrator, Connected AI App, time. The tool's
 *    input is deliberately not a parameter of this function.
 * 2. Put it first and keep only the newest MLSIMPORT_AI_ACTIVITY_KEEP entries.
 *
 * @param string $tool Tool name (for example 'add-mls-connection').
 * @return void
 */
function mlsimport_ai_activity_record( string $tool ): void {
	// Step 1: the entry.
	$entry = array(
		'tool'    => $tool,
		'user_id' => (int) get_current_user_id(),
		'app'     => mlsimport_mcp_current_app(),
		'time'    => time(),
	);

	// Step 2: newest first, capped.
	$entries = mlsimport_ai_activity_entries();
	array_unshift( $entries, $entry );
	update_option( MLSIMPORT_AI_ACTIVITY_OPTION, array_slice( $entries, 0, MLSIMPORT_AI_ACTIVITY_KEEP ), false );
}

/**
 * The AI Activity entries, newest first.
 *
 * @return array<int, array{tool:string, user_id:int, app:string, time:int}>
 */
function mlsimport_ai_activity_entries(): array {
	$entries = get_option( MLSIMPORT_AI_ACTIVITY_OPTION, array() );
	return is_array( $entries ) ? $entries : array();
}
