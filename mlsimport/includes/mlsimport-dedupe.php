<?php
/**
 * Cross-connection listing dedupe mechanics (issue #282, decision #267).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * With multiple MLS connections (spec #273) two feeds can carry the SAME
 * physical property under different ListingKeys (Miami AOR + BeachesMLS).
 * Both copies import and update normally under their own (mls_id, listing_key)
 * identity (#278); this module makes the site SHOW exactly one of them:
 *
 *   - Same property = same normalized physical address — built by
 *     mlsimport_dedupe_address_key() (mlsimport-dedupe-address.php) and
 *     stamped on every imported listing as the 'mlsimport_address_key' meta.
 *   - Winner = the copy from the highest-priority connection (priority 1
 *     first, the user-set drag order on the Connections screen).
 *   - Every losing copy gets the 'mlsimport_duplicate_of' meta pointing at
 *     the winning post and is excluded from all front-end queries (search,
 *     sliders, maps, feeds) — both the WP_Query surfaces (pre_get_posts)
 *     and the standalone flat-table reads (its shared FROM clause).
 *   - Hiding is NEVER deleting: when the winner is deleted or stops being
 *     published (trash, draft, an excluded status leaving the market), its
 *     group is re-evaluated and the loser is promoted (flag cleared) so the
 *     property never vanishes while still listed.
 *
 * The whole mechanic is ONE evaluator, mlsimport_dedupe_evaluate(), run from
 * every seam that can change a group: an import write, a post removal (WP
 * delete/status hooks, plus explicit calls on the two intentional raw-SQL
 * delete paths that bypass WP hooks), and a priority reorder. Listings
 * imported before this module exists gain their address key on their next
 * import touch (any outcome, including 'unchanged') — dedupe activates as
 * feeds sync.
 *
 * @since   7.2.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pick the winning post among the copies of one physical property.
 *
 * Rule (decision #267): the copy from the highest-priority connection wins
 * (priority 1 beats 2). Determinism guarantees, so re-evaluation never flaps:
 * a connection missing from the registry ranks below every registered one;
 * a priority tie breaks on the smaller mls_id; copies from the same
 * connection break on the smaller post id.
 *
 * @param array<int, int> $candidates Candidate posts: post_id => mls_id.
 * @param array<int, int> $priorities Registry priorities: mls_id => priority.
 * @return int Winning post id (0 only for an empty candidate list).
 */
function mlsimport_dedupe_pick_winner( array $candidates, array $priorities ): int {
	$winner    = 0;
	$best_rank = null;
	foreach ( $candidates as $post_id => $mls_id ) {
		// Rank tuple compared lexicographically: priority, mls_id, post_id.
		$rank = array( $priorities[ $mls_id ] ?? PHP_INT_MAX, (int) $mls_id, (int) $post_id );
		if ( null === $best_rank || $rank < $best_rank ) {
			$best_rank = $rank;
			$winner    = (int) $post_id;
		}
	}
	return $winner;
}

/**
 * Re-evaluate winner/loser flags for every published copy of one address.
 *
 * Step by step:
 * 1. Collect the published posts carrying this address key (minus one being
 *    deleted, passed as $exclude_id) and each copy's mlsimport_mls_id stamp.
 * 2. Copies from fewer than two connections mean no cross-connection
 *    duplicate exists (detection runs against OTHER connections only, #282):
 *    clear every flag — this is exactly how a surviving loser is PROMOTED
 *    when the winner disappears.
 * 3. Otherwise pick the winner from the registry priorities and flag every
 *    copy from every other connection with 'mlsimport_duplicate_of' pointing
 *    at the winning post; the winning connection's copies are unflagged.
 *
 * Flags are meta only — hiding is never deleting.
 *
 * @param string $address_key Normalized address key of the group.
 * @param string $post_type   Property post type the copies live under.
 * @param int    $exclude_id  Post being deleted right now (still in the DB).
 * @return void
 */
function mlsimport_dedupe_evaluate( string $address_key, string $post_type, int $exclude_id = 0 ): void {
	if ( '' === $address_key || '' === $post_type ) {
		return;
	}

	// Step 1: the group's published copies and their owning connections.
	// Deliberately direct SQL, not WP_Query: this module's own front-end
	// exclusion (pre_get_posts below) hides flagged losers from property
	// queries, and the evaluator MUST see them — otherwise a hidden loser
	// could never be promoted when its winner disappears.
	global $wpdb;
	$sql = $wpdb->prepare(
		"SELECT P.ID, MLS.meta_value AS mls_id
		 FROM {$wpdb->posts} P
		 INNER JOIN {$wpdb->postmeta} ADR
		         ON ADR.post_id = P.ID
		        AND ADR.meta_key = 'mlsimport_address_key'
		        AND ADR.meta_value = %s
		 LEFT JOIN {$wpdb->postmeta} MLS
		        ON MLS.post_id = P.ID
		       AND MLS.meta_key = 'mlsimport_mls_id'
		 WHERE P.post_type = %s AND P.post_status = 'publish' AND P.ID != %d",
		$address_key,
		$post_type,
		$exclude_id
	);
	$candidates = array();
	foreach ( (array) $wpdb->get_results( $sql ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates[ (int) $row->ID ] = (int) $row->mls_id;
	}
	if ( empty( $candidates ) ) {
		return;
	}

	// Step 2: one connection (or none) => no duplicate => everyone visible.
	if ( count( array_unique( $candidates ) ) < 2 ) {
		foreach ( array_keys( $candidates ) as $post_id ) {
			delete_post_meta( $post_id, 'mlsimport_duplicate_of' );
		}
		return;
	}

	// Step 3: flag every copy that is not from the winning connection.
	$priorities = array();
	foreach ( Mlsimport_Connections::all() as $mls_id => $record ) {
		$priorities[ (int) $mls_id ] = (int) $record['priority'];
	}
	$winner     = mlsimport_dedupe_pick_winner( $candidates, $priorities );
	$winner_mls = $candidates[ $winner ];
	foreach ( $candidates as $post_id => $mls_id ) {
		if ( $mls_id === $winner_mls ) {
			delete_post_meta( $post_id, 'mlsimport_duplicate_of' );
		} else {
			update_post_meta( $post_id, 'mlsimport_duplicate_of', $winner );
		}
	}
}

/**
 * Stamp the address key and re-evaluate after one import write.
 *
 * Hooked on the Stored Listing Write success/warning events, which fire only
 * after persistence committed. Step by step:
 * 1. Writes that changed the post — created / updated / saved-with-warnings —
 *    always re-settle their group. An 'unchanged' outcome matters only when
 *    the post predates this module and carries NO key yet: stamping it then
 *    is how legacy listings join dedupe without waiting for an MLS change.
 *    ('deleted' went through wp_delete_post, whose hook re-evaluates.)
 * 2. Stamp the fresh normalized address key on the post.
 * 3. When the key CHANGED, re-evaluate the OLD group too — the copy that
 *    left it may have been that group's winner.
 * 4. Re-evaluate the new key's group.
 *
 * @param array<string, mixed> $result   Public Stored Listing Write result.
 * @param array<string, mixed> $property Incoming raw property payload.
 * @return void
 */
function mlsimport_dedupe_after_write( $result, $property ): void {
	// Step 1: only outcomes that can change a dedupe group proceed.
	$listing_id = (int) ( $result['listing_id'] ?? 0 );
	$outcome    = (string) ( $result['outcome'] ?? '' );
	$previous   = $listing_id > 0 ? (string) get_post_meta( $listing_id, 'mlsimport_address_key', true ) : '';
	$writes     = in_array( $outcome, array( 'created', 'updated', 'saved-with-warnings' ), true );
	$backfill   = 'unchanged' === $outcome && '' === $previous;
	if ( $listing_id <= 0 || ( ! $writes && ! $backfill ) ) {
		return;
	}

	$post_type = (string) get_post_type( $listing_id );
	$key       = mlsimport_dedupe_address_key( is_array( $property ) ? $property : array() );

	// Step 2: stamp (or clear) the identity this evaluation runs under.
	if ( '' === $key ) {
		delete_post_meta( $listing_id, 'mlsimport_address_key' );
	} else {
		update_post_meta( $listing_id, 'mlsimport_address_key', $key );
	}

	// Step 3: a changed address releases this copy from its old group.
	if ( '' !== $previous && $previous !== $key ) {
		mlsimport_dedupe_evaluate( $previous, $post_type );
	}

	// Step 4: settle the group the copy belongs to now.
	mlsimport_dedupe_evaluate( $key, $post_type );
}

/**
 * Re-evaluate a deleted listing's group so its loser is promoted.
 *
 * before_delete_post fires while the post row and meta still exist, so the
 * key is readable and the doomed post is excluded from the evaluation by id.
 * Non-listing posts carry no address key and return immediately.
 *
 * @param int $post_id Post being permanently deleted.
 * @return void
 */
function mlsimport_dedupe_on_delete( $post_id ): void {
	$post_id = (int) $post_id;
	$key     = (string) get_post_meta( $post_id, 'mlsimport_address_key', true );
	if ( '' !== $key ) {
		mlsimport_dedupe_evaluate( $key, (string) get_post_type( $post_id ), $post_id );
	}
}

/**
 * Re-evaluate when a listing enters or leaves the published set.
 *
 * One transition_post_status hook covers every status seam at once: trash,
 * untrash, quick-edit to draft/pending, scheduled publish. Only transitions
 * that cross the 'publish' boundary matter — the evaluator's candidate set
 * is published posts, so an un-published winner promotes its loser here and
 * a re-published copy re-enters its group. Fires after the status is saved,
 * so the plain evaluation already sees the correct set. Import-time inserts
 * pass through harmlessly: their address key is not stamped yet.
 *
 * @param string  $new_status Status after the transition.
 * @param string  $old_status Status before the transition.
 * @param WP_Post $post       The post transitioning.
 * @return void
 */
function mlsimport_dedupe_on_status_change( $new_status, $old_status, $post ): void {
	if ( $new_status === $old_status || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
		return;
	}
	$key = (string) get_post_meta( (int) $post->ID, 'mlsimport_address_key', true );
	if ( '' !== $key ) {
		mlsimport_dedupe_evaluate( $key, (string) $post->post_type );
	}
}

/**
 * Re-evaluate every currently flagged group after a priority reorder.
 *
 * Every multi-connection group carries at least one flagged loser (the
 * import-time evaluation guarantees it), so the flagged posts enumerate
 * exactly the groups a priority change can re-decide. Deterministic: the
 * same order always produces the same winners (#282 acceptance).
 *
 * @return void
 */
function mlsimport_dedupe_reevaluate_flagged(): void {
	$flagged = get_posts(
		array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'mlsimport_duplicate_of', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			// This read enumerates hidden posts by definition — it must never
			// be filtered by the front-end exclusion below.
			'mlsimport_include_hidden' => true,
		)
	);
	$groups = array();
	foreach ( $flagged as $post_id ) {
		$key  = (string) get_post_meta( (int) $post_id, 'mlsimport_address_key', true );
		$type = (string) get_post_type( (int) $post_id );
		if ( '' !== $key && '' !== $type ) {
			$groups[ $type . '|' . $key ] = array( $key, $type );
		}
	}
	foreach ( $groups as $group ) {
		mlsimport_dedupe_evaluate( $group[0], $group[1] );
	}
}

/**
 * Hide flagged duplicates from every front-end WP_Query surface.
 *
 * Applies to any query targeting a property post type EXCEPT:
 *   - queries that opt out with 'mlsimport_include_hidden' — the plugin's
 *     internal reads that MUST see hidden copies (the import identity
 *     lookup, telemetry sampling, the standalone reindex);
 *   - wp-admin SCREEN queries (is_admin without AJAX) — the admin listings
 *     table deliberately shows hidden duplicates (#267; badge deferred).
 *     AJAX queries stay covered because theme search/slider/map surfaces
 *     commonly fetch through admin-ajax;
 *   - singular queries — hiding is about result LISTS, a direct permalink
 *     still resolves.
 *
 * "Targeting a property post type" means an explicit post_type match, or a
 * taxonomy archive whose queried taxonomy is registered to a property post
 * type (those main queries carry an empty post_type). Registered at a LATE
 * priority so themes that inject their post type from their own
 * pre_get_posts callbacks are still seen. The clause is a NOT EXISTS meta
 * condition AND-combined with whatever meta_query the surface already set.
 *
 * @param WP_Query $query The query being prepared.
 * @return void
 */
function mlsimport_dedupe_exclude_hidden( $query ): void {
	if (
		$query->get( 'mlsimport_include_hidden' )
		|| ( is_admin() && ! wp_doing_ajax() )
		|| $query->is_singular()
	) {
		return;
	}
	$property = array( 'estate_property', 'property', 'mlsimport_property' );
	$queried  = array_filter( (array) ( $query->get( 'post_type' ) ?: array() ) );
	$targets  = ! empty( array_intersect( $queried, $property ) );
	if ( ! $targets && empty( $queried ) && $query->is_tax() && isset( $query->tax_query->queries ) ) {
		// Property-taxonomy archives (city, category, ...) query with an
		// empty post_type; resolve the taxonomy's owning post types instead.
		foreach ( (array) $query->tax_query->queries as $clause ) {
			$taxonomy = get_taxonomy( (string) ( $clause['taxonomy'] ?? '' ) );
			if ( $taxonomy && ! empty( array_intersect( (array) $taxonomy->object_type, $property ) ) ) {
				$targets = true;
				break;
			}
		}
	}
	if ( ! $targets ) {
		return;
	}

	$not_flagged = array(
		'key'     => 'mlsimport_duplicate_of',
		'compare' => 'NOT EXISTS',
	);
	$existing    = $query->get( 'meta_query' );
	$query->set(
		'meta_query',
		empty( $existing )
			? array( $not_flagged )
			: array(
				'relation' => 'AND',
				$existing,
				$not_flagged,
			)
	);
}

// Import writes re-settle their group; WP-level removals and publish-boundary
// status changes promote survivors; late priority lets theme pre_get_posts
// callbacks set their post type before the exclusion looks at it.
add_action( 'mlsimport_stored_listing_write_success', 'mlsimport_dedupe_after_write', 10, 2 );
add_action( 'mlsimport_stored_listing_write_warning', 'mlsimport_dedupe_after_write', 10, 2 );
add_action( 'before_delete_post', 'mlsimport_dedupe_on_delete' );
add_action( 'transition_post_status', 'mlsimport_dedupe_on_status_change', 10, 3 );
add_action( 'pre_get_posts', 'mlsimport_dedupe_exclude_hidden', 999 );
