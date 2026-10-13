<?php
/**
 * AI tools (MCP): the two-call confirmation of the destructive tools.
 *
 * WHAT THIS FILE DOES
 * -------------------
 * Every tool that deletes something for good (remove-mls-connection,
 * delete-properties-by-term, clear-field-data, delete-task, delete-listing,
 * delete-page, delete-post, delete-menu, ...) takes two calls:
 *
 *   1. The first call (no confirmation code) deletes nothing. It answers with
 *      exactly what would be deleted and a one-time confirmation code that is
 *      valid for 10 minutes.
 *   2. The second call, with that code, deletes.
 *
 * WHAT IT GUARANTEES, AND WHAT IT DOES NOT
 * A code works once, only for the same tool with the same inputs, only for
 * the administrator it was given to, and only for the Connected AI App it
 * was given to. So nothing is deleted by a single call, the AI has the exact
 * consequences in hand before it can delete, and a code cannot be carried
 * from one app or user to another.
 *
 * It does NOT prove that a human said yes: the same AI app receives the code
 * and sends it back, and the plugin has no way to see the conversation. The
 * human gate is the AI app's own approval control (Claude asks before a tool
 * marked destructive runs, unless the client turned that off for this
 * connector). Every tool that goes through this file is marked destructive
 * so that control applies.
 *
 * How a code is kept: a transient named after the code holds a fingerprint of
 * the call (tool + inputs + administrator + app). The second call must produce
 * the same fingerprint. The transient is deleted when the code is used.
 *
 * Public interface:
 *   mlsimport_mcp_confirmation_fingerprint( $tool, $input )           the call's fingerprint
 *   mlsimport_mcp_confirmation_issue( $tool, $input )                 a new one-time code
 *   mlsimport_mcp_confirm_or_preview( $tool, $input, $would_delete )  the gate each destructive tool calls
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long a confirmation code is valid, in seconds (10 minutes). */
define( 'MLSIMPORT_MCP_CONFIRM_SECONDS', 10 * MINUTE_IN_SECONDS );

/**
 * The fingerprint of one destructive call: tool + inputs + administrator +
 * Connected AI App.
 *
 * Step by step:
 * 1. Drop the confirmation code itself; it is not part of what is confirmed.
 * 2. Sort the inputs by name so the order they were sent in does not matter.
 *    Lists keep their order.
 * 3. Hash tool, administrator, app (its row id, not its changeable name;
 *    0 for a developer signed in without an app) and inputs together.
 *
 * @param string $tool  Tool name.
 * @param array  $input The tool's input.
 * @return string The fingerprint.
 */
function mlsimport_mcp_confirmation_fingerprint( string $tool, array $input ): string {
	// Step 1 + 2: the inputs without the code, in a fixed order.
	unset( $input['confirmation_code'] );
	ksort( $input );

	// Step 3: one hash for the whole call.
	return hash( 'sha256', $tool . '|' . (int) get_current_user_id() . '|' . mlsimport_mcp_current_grant() . '|' . wp_json_encode( $input ) );
}

/**
 * Create a one-time confirmation code for one destructive call.
 *
 * @param string $tool  Tool name.
 * @param array  $input The tool's input.
 * @return string The code to send back with the second call.
 */
function mlsimport_mcp_confirmation_issue( string $tool, array $input ): string {
	$code = wp_generate_password( 10, false );
	set_transient(
		'mlsimport_mcp_confirm_' . hash( 'sha256', $code ),
		mlsimport_mcp_confirmation_fingerprint( $tool, $input ),
		MLSIMPORT_MCP_CONFIRM_SECONDS
	);
	return $code;
}

/**
 * The gate every destructive tool passes before it deletes.
 *
 * Step by step:
 * 1. No code in the input: this is the first call. Delete nothing; answer
 *    with what would be deleted and a new code.
 * 2. A code in the input: look it up. It must exist (not used, not older
 *    than 10 minutes) and belong to this same tool, inputs, administrator
 *    and app. Otherwise refuse, and say how to get a new code.
 * 3. The code is right: use it up and let the tool go ahead.
 *
 * @param string $tool         Tool name.
 * @param array  $input        The tool's input (may hold 'confirmation_code').
 * @param string $would_delete Plain sentence: exactly what would be deleted.
 * @return array|WP_Error|null The preview (first call), the refusal (bad
 *                             code), or null when the tool may delete now.
 */
function mlsimport_mcp_confirm_or_preview( string $tool, array $input, string $would_delete ) {
	$code = isset( $input['confirmation_code'] ) ? trim( (string) $input['confirmation_code'] ) : '';

	// Step 1: the first call.
	if ( '' === $code ) {
		return array(
			'deleted'            => false,
			'would_delete'       => $would_delete,
			'confirmation_code'  => mlsimport_mcp_confirmation_issue( $tool, $input ),
			'code_valid_seconds' => MLSIMPORT_MCP_CONFIRM_SECONDS,
			'note'               => __( 'Nothing was deleted. Tell the client exactly what would be deleted and ask them to confirm. Only after they say yes, call this tool again with the same inputs and this confirmation_code.', 'mlsimport' ),
		);
	}

	// Step 2: the code must match this exact call.
	$key    = 'mlsimport_mcp_confirm_' . hash( 'sha256', $code );
	$stored = get_transient( $key );
	if ( ! is_string( $stored ) || ! hash_equals( $stored, mlsimport_mcp_confirmation_fingerprint( $tool, $input ) ) ) {
		return new WP_Error(
			'mlsimport_confirmation_invalid',
			__( 'That confirmation code is not valid for this call: it was already used, is older than 10 minutes, or was given for other inputs. Nothing was deleted. Call the tool again without a confirmation code to get a new one.', 'mlsimport' )
		);
	}

	// Step 3: one use only. Of two calls sent at the same moment with the
	// same code, only the one that really removes it goes on.
	if ( ! delete_transient( $key ) ) {
		return new WP_Error(
			'mlsimport_confirmation_invalid',
			__( 'That confirmation code was already used. Nothing was deleted. Call the tool again without a confirmation code to get a new one.', 'mlsimport' )
		);
	}
	return null;
}
