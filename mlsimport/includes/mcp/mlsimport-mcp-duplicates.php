<?php
/**
 * AI tools (MCP): duplicates across MLS Connections.
 *
 * When two MLS Connections send the same property, the copy of the higher
 * priority MLS is shown and the other is hidden (includes/mlsimport-dedupe.php).
 *
 * Each function here is the body of one AI tool, reading what the plugin
 * already records:
 *   - list-duplicates: the listings hidden as a duplicate of another MLS's
 *     copy (mlsimport_dedupe_flagged_ids() + the meta mlsimport_duplicate_of).
 *   - reevaluate-duplicates: mlsimport_dedupe_reevaluate_flagged().
 *
 * The hourly sync and the reconciliation have NO tools: an AI app can neither
 * read nor start them (owner decision).
 *
 * Public interface:
 *   mlsimport_mcp_duplicates_tools()        the tools of this file
 *   mlsimport_mcp_list_duplicates()         tool: list-duplicates
 *   mlsimport_mcp_reevaluate_duplicates()   tool: reevaluate-duplicates
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file, as the tool list expects them.
 *
 * Each entry: label, description, input, required, callback, changes. See
 * mlsimport_mcp_ability_definitions() in mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_duplicates_tools(): array {
	return array(
		'list-duplicates'       => array(
			'label'       => 'Listings hidden as duplicates',
			'description' => 'Reads the listings that are hidden because another MLS Connection sent the same property, and which copy is shown instead. The copy of the higher priority MLS wins (reorder-mls-connections).',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_duplicates',
			'changes'     => false,
		),
		'reevaluate-duplicates' => array(
			'label'       => 'Decide the duplicates again',
			'description' => 'Decides again, for every property that two MLS Connections both sent, which copy is shown. Uses the current priority order.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_reevaluate_duplicates',
			'changes'     => true,
		),
	);
}

/**
 * Tool list-duplicates: the listings hidden as a duplicate.
 *
 * @return array The hidden copies (at most 100) with the copy shown instead.
 */
function mlsimport_mcp_list_duplicates(): array {
	$connections = Mlsimport_Connections::all();
	$name        = static function ( int $post_id ) use ( $connections ): string {
		return (string) ( $connections[ (int) get_post_meta( $post_id, 'mlsimport_mls_id', true ) ]['mls_name'] ?? '' );
	};

	// The flagged posts, the same list the re-evaluation walks.
	$flagged    = mlsimport_dedupe_flagged_ids();
	$duplicates = array();
	foreach ( array_slice( $flagged, 0, 100 ) as $post_id ) {
		$post_id      = (int) $post_id;
		$shown_id     = (int) get_post_meta( $post_id, 'mlsimport_duplicate_of', true );
		$duplicates[] = array(
			'hidden_post_id' => $post_id,
			'title'          => get_the_title( $post_id ),
			'hidden_mls'     => $name( $post_id ),
			'shown_post_id'  => $shown_id,
			'shown_mls'      => $name( $shown_id ),
		);
	}

	return array(
		'total'      => count( $flagged ),
		'duplicates' => $duplicates,
	);
}

/**
 * Tool reevaluate-duplicates: decide every duplicate group again.
 *
 * @return array How many listings are hidden as a duplicate afterwards.
 */
function mlsimport_mcp_reevaluate_duplicates(): array {
	mlsimport_dedupe_reevaluate_flagged();
	return array(
		'done'         => true,
		'hidden_count' => count( mlsimport_dedupe_flagged_ids() ),
	);
}
