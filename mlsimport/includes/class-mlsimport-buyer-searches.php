<?php
/**
 * Buyers' saved searches, read the same way in every mode.
 *
 * WHY: the daily listing-changes email has a "Buyers to contact" section. It
 * tells the agent which buyers have a saved search that matches a listing that
 * was just added or changed. Each mode keeps saved searches somewhere else:
 *
 *   Standalone  post type `mlsimport_search` (Mlsimport_Saved_Search); only
 *               CONFIRMED ("active") searches count. Matching runs the site's
 *               own search (Mlsimport_Standalone_Render::prepare()).
 *   WpResidence post type `wpestate_search`; meta `search_arguments` holds the
 *               WP_Query arguments as JSON, `meta_arguments` the extra meta
 *               filters, `user_email` the buyer's address.
 *   Houzez      table `{prefix}houzez_search`; column `query` holds the
 *               WP_Query arguments as base64( serialize() ), `email` the address.
 *   Real Homes  table `{prefix}realhomes_saved_searches`; column
 *               `search_wp_query_args` in the same encoding, `user_id` the buyer.
 *
 * The three themes re-run their stored WP_Query arguments for their own buyer
 * emails; this class does the same, restricted to the changed listings.
 *
 * all() returns every usable search as a plain array:
 *   name, email, url (the buyer's results page, '' when unknown), and either
 *   'params' (standalone criteria) or 'args' (WP_Query arguments) + 'metas'.
 * matches() returns which of the given listing ids one search matches.
 *
 * Stored queries were submitted from a browser, so they are decoded without
 * ever instantiating an object (see decode_query()).
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read saved searches of the active mode and match them against listings.
 */
final class Mlsimport_Buyer_Searches {

	/**
	 * Every saved search that can be matched, from whichever store exists.
	 *
	 * @return array<int, array<string, mixed>> Searches (see the file header).
	 */
	public static function all(): array {
		global $wpdb;
		$searches = array();

		// Standalone: confirmed searches only.
		if ( class_exists( 'Mlsimport_Saved_Search' ) && post_type_exists( Mlsimport_Saved_Search::POST_TYPE ) ) {
			foreach ( Mlsimport_Saved_Search::ids_by_status( 'active' ) as $id ) {
				$search = Mlsimport_Saved_Search::get( $id );
				if ( null !== $search ) {
					$searches[] = array(
						'name'   => $search['name'],
						'email'  => $search['email'],
						'url'    => $search['results_url'],
						'params' => $search['params'],
					);
				}
			}
		}

		// WpResidence: one post per saved search, owned by the buyer.
		if ( post_type_exists( 'wpestate_search' ) ) {
			$ids = get_posts( array( 'post_type' => 'wpestate_search', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
			foreach ( $ids as $id ) {
				$searches[] = array(
					'name'  => self::user_name( (int) get_post_field( 'post_author', $id ) ),
					'email' => (string) get_post_meta( $id, 'user_email', true ),
					'url'   => '',
					'args'  => self::decode_query( (string) get_post_meta( $id, 'search_arguments', true ) ),
					'metas' => self::decode_query( (string) get_post_meta( $id, 'meta_arguments', true ) ),
				);
			}
		}

		// Houzez and Real Homes: one table row per saved search.
		foreach ( array( 'houzez_search', 'realhomes_saved_searches' ) as $name ) {
			$table = $wpdb->prefix . $name;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- fixed table name.
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) as $row ) {
				$user_id    = (int) ( $row['auther_id'] ?? ( $row['user_id'] ?? 0 ) );
				$user       = get_userdata( $user_id );
				$searches[] = array(
					'name'  => self::user_name( $user_id ),
					'email' => (string) ( $row['email'] ?? ( $user ? $user->user_email : '' ) ),
					'url'   => (string) ( $row['url'] ?? '' ),
					'args'  => self::decode_query( (string) ( $row['query'] ?? ( $row['search_wp_query_args'] ?? '' ) ) ),
					'metas' => array(),
				);
			}
		}

		return $searches;
	}

	/**
	 * Which of the given listings one saved search matches.
	 *
	 * Step 1 (standalone): run the site's own search with the saved criteria,
	 *        restricted to the listings through its internal `post_ids` param.
	 * Step 2 (themes): run the stored WP_Query arguments restricted to the
	 *        listings through `post__in`. WpResidence's extra meta filters are
	 *        resolved by the theme's own helper, as its buyer email does.
	 *
	 * @param array<string, mixed> $search   One entry of all().
	 * @param array<int, int>      $post_ids Listing post ids to test.
	 * @return array<int, int> The matching listing ids.
	 */
	public static function matches( array $search, array $post_ids ): array {
		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}

		// Step 1: standalone criteria.
		if ( isset( $search['params'] ) ) {
			$data = Mlsimport_Standalone_Render::prepare(
				array_merge( (array) $search['params'], array( 'post_ids' => $post_ids, 'limit' => count( $post_ids ), 'page' => 1 ) )
			);
			$ids  = array();
			foreach ( (array) ( $data['posts'] ?? array() ) as $post ) {
				$ids[] = is_object( $post ) ? (int) $post->ID : (int) $post;
			}
			return $ids;
		}

		// Step 2: a theme's stored WP_Query arguments.
		$args = (array) ( $search['args'] ?? array() );
		if ( empty( $args ) ) {
			return array();
		}
		if ( ! empty( $search['metas'] ) && function_exists( 'wpestate_add_meta_post_to_search' ) ) {
			$meta_ids = (array) wpestate_add_meta_post_to_search( $search['metas'] );
			if ( ! empty( $meta_ids ) ) {
				$post_ids = array_values( array_intersect( $post_ids, array_map( 'intval', $meta_ids ) ) );
			}
		}
		if ( empty( $post_ids ) ) {
			return array();
		}
		$args['post__in']       = $post_ids;
		$args['fields']         = 'ids';
		$args['posts_per_page'] = count( $post_ids );
		$args['paged']          = 1;
		$args['no_found_rows']  = true;
		unset( $args['date_query'], $args['offset'] );
		return array_map( 'intval', ( new WP_Query( $args ) )->posts );
	}

	/**
	 * Decode a stored search query into a plain array.
	 *
	 * Accepts JSON (WpResidence) or base64( serialize() ) (Houzez, Real Homes).
	 * The value came from a browser request, so unserialize() runs with
	 * `allowed_classes => false`: no object is ever created from it.
	 *
	 * @param string $stored Stored query.
	 * @return array<mixed> Decoded arguments, array() when unreadable.
	 */
	public static function decode_query( string $stored ): array {
		$stored = trim( $stored );
		if ( '' === $stored ) {
			return array();
		}
		$json = json_decode( $stored, true );
		if ( is_array( $json ) ) {
			return $json;
		}
		$raw = base64_decode( $stored, true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes disallowed.
		$data = false !== $raw ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : false;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * A user's display name, '' when the user does not exist (a guest search).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function user_name( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user ? (string) $user->display_name : '';
	}
}
