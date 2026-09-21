<?php
/**
 * Saved Search (Standalone mode only, ADR-0018): storage and lifecycle.
 *
 * A Saved Search is a set of listing search criteria kept for one Recipient
 * (name + email, no WordPress account needed) so matching listings can be
 * emailed to them once a day. This file owns:
 *
 *   - the non-public `mlsimport_search` post type (one post per search);
 *   - create(): validate a save request, store it as PENDING, mail the
 *     double-opt-in confirmation link;
 *   - confirm(): pending -> active, and tell the site owner;
 *   - unsubscribe(): active/pending -> unsubscribed (the link in every mail);
 *   - get(): read one Saved Search back as a plain array.
 *
 * Lifecycle is one-way: pending -> active -> unsubscribed. There is no pause,
 * no resubscribe and no editing; changing criteria means saving a new search.
 *
 * What it does NOT do: the daily matching + alert mail live in
 * class-mlsimport-saved-search-alerts.php; mail rendering lives in
 * class-mlsimport-saved-search-mailer.php.
 *
 * Extension points (WooCommerce style): mlsimport_saved_search_validation,
 * mlsimport_saved_search_created, mlsimport_saved_search_confirmed,
 * mlsimport_saved_search_deactivated.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores Saved Searches and moves them through their lifecycle.
 */
class Mlsimport_Saved_Search {

	const POST_TYPE = 'mlsimport_search'; // WordPress caps post type names at 20 characters.

	/**
	 * Request keys that pass the listings whitelist but are NOT search criteria:
	 * sorting, paging and the map viewport describe how the visitor was looking
	 * at the results, not which listings they want. They are never stored.
	 */
	const NOT_CRITERIA = array( 'orderby', 'order', 'limit', 'page', 'lat_min', 'lat_max', 'lng_min', 'lng_max' );

	/**
	 * Register the post type. Never public on the front end. In wp-admin it shows
	 * as a plain LIST under the MLS Import menu (columns + row actions come from
	 * Mlsimport_Saved_Search_Admin): nobody can add one there — a Saved Search is
	 * only ever created from the search results — so create_posts is denied.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Saved Searches', 'mlsimport' ),
					'singular_name' => __( 'Saved Search', 'mlsimport' ),
					'not_found'     => __( 'No saved searches yet.', 'mlsimport' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'mlsimport_plugin_options',
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Whether the feature is switched on (Design Settings -> Saved Search) AND the
	 * site runs in Standalone mode — the only mode that has the fast table.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return function_exists( 'mlsimport_is_standalone_mode' )
			&& mlsimport_is_standalone_mode()
			&& 'no' !== mlsimport_standalone_option( 'saved_search_enabled', 'yes' );
	}

	/**
	 * Validate a save request and store it as a PENDING Saved Search.
	 *
	 * Pure of the nonce/HTTP layer (the AJAX shell calls this), so it is testable
	 * on its own. $input is the raw unslashed request: the modal's fields plus the
	 * results page's current filter params as top-level keys.
	 *
	 * @param array $input Raw (unslashed) request fields.
	 * @return array{ok:bool,message:string,id:int}
	 */
	public static function create( array $input ): array {
		// Step 1 — honeypot: a filled hidden field is a bot. Answer "success" so it
		// learns nothing, store nothing.
		if ( ! empty( $input['mlsimport_hp'] ) ) {
			return self::result( true, __( 'Check your email to confirm your saved search.', 'mlsimport' ) );
		}

		// Step 2 — the Recipient: a name and a valid email are both required.
		$name  = isset( $input['mlsimport_name'] ) ? sanitize_text_field( (string) $input['mlsimport_name'] ) : '';
		$email = isset( $input['mlsimport_email'] ) ? sanitize_email( (string) $input['mlsimport_email'] ) : '';
		if ( '' === $name || ! is_email( $email ) ) {
			return self::result( false, __( 'Please provide your name and a valid email.', 'mlsimport' ) );
		}

		// Step 3 — consent is enforced here; the browser's `required` is not enough.
		if ( empty( $input['mlsimport_consent'] ) ) {
			return self::result( false, __( 'Please accept the privacy policy to save your search.', 'mlsimport' ) );
		}

		// Step 4 — the criteria. atts_to_args() is the same whitelist the results
		// page itself applies to the URL, so only real filter keys survive (an
		// injected `post_ids` is dropped here). Then strip sort/paging/viewport.
		$params = Mlsimport_Standalone_Shortcodes::atts_to_args( $input );
		$params = array_diff_key( $params, array_flip( self::NOT_CRITERIA ) );
		if ( empty( $params ) ) {
			return self::result( false, __( 'Choose at least one filter first.', 'mlsimport' ) );
		}

		// Step 5 — the results page the search was saved from. Every alert mail links
		// back to it, so it must be a URL on THIS site: anything else would let a
		// stranger make the site email arbitrary links to a victim's inbox.
		$results_url = isset( $input['results_url'] ) ? esc_url_raw( (string) $input['results_url'] ) : '';
		if ( '' === $results_url || wp_parse_url( $results_url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return self::result( false, __( 'This search cannot be saved from here.', 'mlsimport' ) );
		}
		// Keep the PAGE, replace its query with the saved criteria. The visitor's
		// address bar is not trustworthy for this: the results page refines over AJAX
		// without ever updating the URL, so its query can describe an older search.
		$results_url = esc_url_raw( add_query_arg( urlencode_deep( $params ), strtok( $results_url, '?#' ) ) );

		// Step 6 — extension validation (reCAPTCHA, blocklists, rate limits...): a
		// non-empty errors list rejects the save with its first message.
		/** Filter Saved Search validation errors. @since 7.3 */
		$errors = (array) apply_filters( 'mlsimport_saved_search_validation', array(), $input, $params );
		if ( ! empty( $errors ) ) {
			return self::result( false, (string) reset( $errors ) );
		}

		// Step 7 — store. The post is only a container; everything lives in meta.
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name . ' <' . $email . '>',
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return self::result( false, __( 'Sorry, your search could not be saved. Please try again.', 'mlsimport' ) );
		}
		$id = (int) $id;

		// The token is the only credential the confirm/unsubscribe links carry.
		update_post_meta( $id, 'mlsimport_ss_name', $name );
		update_post_meta( $id, 'mlsimport_ss_email', $email );
		update_post_meta( $id, 'mlsimport_ss_params', $params );
		update_post_meta( $id, 'mlsimport_ss_results_url', $results_url );
		update_post_meta( $id, 'mlsimport_ss_status', 'pending' );
		update_post_meta( $id, 'mlsimport_ss_token', wp_generate_password( 32, false ) );
		update_post_meta( $id, 'mlsimport_ss_user_id', get_current_user_id() );

		/** Fires after a Saved Search is stored as pending. @since 7.3 */
		do_action( 'mlsimport_saved_search_created', $id, self::get( $id ) );

		// Step 8 — double opt-in: nothing is sent until this link is clicked.
		Mlsimport_Saved_Search_Mailer::send_confirmation( self::get( $id ) );

		return self::result( true, __( 'Check your email to confirm your saved search.', 'mlsimport' ), $id );
	}

	/**
	 * Confirm a pending Saved Search from its emailed link: pending -> active, then
	 * tell the site owner. Any other state is left alone (a second click on the
	 * link, or a click after unsubscribing, changes nothing and notifies nobody).
	 *
	 * @param string $token Token from the confirmation link.
	 * @return bool True when the search is active after the call.
	 */
	public static function confirm( string $token ): bool {
		$id = self::id_for_token( $token );
		if ( ! $id ) {
			return false;
		}
		$status = (string) get_post_meta( $id, 'mlsimport_ss_status', true );
		if ( 'pending' !== $status ) {
			return 'active' === $status;
		}

		update_post_meta( $id, 'mlsimport_ss_status', 'active' );

		/** Fires when the Recipient confirms a Saved Search. @since 7.3 */
		do_action( 'mlsimport_saved_search_confirmed', $id, self::get( $id ) );

		// The owner hears about a Saved Search only now — never about unconfirmed ones.
		Mlsimport_Saved_Search_Mailer::send_owner_notice( self::get( $id ) );
		return true;
	}

	/**
	 * Stop a Saved Search for good (the unsubscribe link, or wp-admin "Deactivate").
	 * The post is kept so the owner still sees it in the list as unsubscribed.
	 *
	 * @param string $token Token from the unsubscribe link.
	 * @return bool True when a Saved Search with that token exists.
	 */
	public static function unsubscribe( string $token ): bool {
		$id = self::id_for_token( $token );
		if ( ! $id ) {
			return false;
		}
		self::deactivate( $id );
		return true;
	}

	/**
	 * Mark one Saved Search unsubscribed by id. Shared by the emailed link and the
	 * wp-admin row action so both fire the same hook.
	 *
	 * @param int $id Saved Search post ID.
	 * @return void
	 */
	public static function deactivate( int $id ): void {
		if ( 'unsubscribed' === get_post_meta( $id, 'mlsimport_ss_status', true ) ) {
			return;
		}
		update_post_meta( $id, 'mlsimport_ss_status', 'unsubscribed' );

		/** Fires when a Saved Search stops sending (link or admin). @since 7.3 */
		do_action( 'mlsimport_saved_search_deactivated', $id, self::get( $id ) );
	}

	/**
	 * Read one Saved Search as a plain array, or null when the id is not one.
	 *
	 * @param int $id Saved Search post ID.
	 * @return array{id:int,name:string,email:string,params:array,results_url:string,status:string,token:string,last_sent:string,user_id:int,created:string}|null
	 */
	public static function get( int $id ) {
		if ( self::POST_TYPE !== get_post_type( $id ) ) {
			return null;
		}
		return array(
			'id'          => $id,
			'name'        => (string) get_post_meta( $id, 'mlsimport_ss_name', true ),
			'email'       => (string) get_post_meta( $id, 'mlsimport_ss_email', true ),
			'params'      => (array) get_post_meta( $id, 'mlsimport_ss_params', true ),
			'results_url' => (string) get_post_meta( $id, 'mlsimport_ss_results_url', true ),
			'status'      => (string) get_post_meta( $id, 'mlsimport_ss_status', true ),
			'token'       => (string) get_post_meta( $id, 'mlsimport_ss_token', true ),
			'last_sent'   => (string) get_post_meta( $id, 'mlsimport_ss_last_sent', true ),
			'user_id'     => (int) get_post_meta( $id, 'mlsimport_ss_user_id', true ),
			'created'     => (string) get_post_field( 'post_date', $id ),
		);
	}

	/**
	 * IDs of every Saved Search in a given status, optionally only those created
	 * before a cutoff (the daily job's "unconfirmed for 7 days" cleanup).
	 *
	 * @param string $status 'pending' | 'active' | 'unsubscribed'.
	 * @param string $before Optional strtotime()-style cutoff, e.g. '7 days ago'.
	 * @return int[]
	 */
	public static function ids_by_status( string $status, string $before = '' ): array {
		$query = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'mlsimport_ss_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $status, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		);
		if ( '' !== $before ) {
			$query['date_query'] = array( array( 'before' => $before ) );
		}
		return array_map( 'intval', get_posts( $query ) );
	}

	/**
	 * The results page (with the saved criteria in its query) a token's Saved Search
	 * was saved from. The confirm link lands the Recipient there.
	 *
	 * @param string $token Token from an emailed link.
	 * @return string URL, or '' when the token matches nothing.
	 */
	public static function results_url_for_token( string $token ): string {
		$id = self::id_for_token( $token );
		return $id ? (string) get_post_meta( $id, 'mlsimport_ss_results_url', true ) : '';
	}

	/**
	 * Find the Saved Search a link token belongs to.
	 *
	 * @param string $token Token from an emailed link.
	 * @return int Post ID, or 0 when the token matches nothing.
	 */
	private static function id_for_token( string $token ): int {
		// Tokens are 32 alphanumerics; refuse anything else before touching the DB.
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'mlsimport_ss_token', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Shape the { ok, message, id } answer create() returns.
	 *
	 * @param bool   $ok      Whether the save succeeded.
	 * @param string $message Visitor-facing message.
	 * @param int    $id      New Saved Search ID (0 when nothing was stored).
	 * @return array{ok:bool,message:string,id:int}
	 */
	private static function result( bool $ok, string $message, int $id = 0 ): array {
		return array(
			'ok'      => $ok,
			'message' => $message,
			'id'      => $id,
		);
	}
}
