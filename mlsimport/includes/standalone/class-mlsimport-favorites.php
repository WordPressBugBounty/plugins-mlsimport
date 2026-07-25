<?php
/**
 * Standalone (theme_id 990) property favorites — a real, persisted "save" feature
 * shared by the single-property page and the listing cards.
 *
 * A favorite is a { k: RESO ListingKey, p: WordPress post_id } pair. ListingKey is
 * the durable identity (it survives a reconciliation re-import that mints a new
 * post_id, and it is the only stable id in live/passthrough mode); post_id is a
 * render hint + the fast path in stored mode. Everything dedupes by ListingKey.
 *
 * Two stores, one shape (a JSON array of { k, p } objects):
 *   - Anonymous visitors: the browser's localStorage is authoritative (client-only;
 *     the toggle never touches the server). Handled entirely in favorites.js.
 *   - Logged-in users: this user-meta store is authoritative. Every toggle is a
 *     nonce-protected AJAX write here; the saved list is printed once per page as a
 *     bootstrap blob so favorites.js can flip the matching hearts (uniform client
 *     hydration — the card/button markup is identical for everyone and cache-safe).
 *
 * On login the client merges its localStorage list up into this store (union by
 * ListingKey) then clears localStorage, so there is exactly one source of truth per
 * auth state and no divergence.
 *
 * Three AJAX endpoints (the auth boundary is explicit, not a runtime branch):
 *   - mlsimport_fav_toggle : priv only  — write one save/remove to user meta.
 *   - mlsimport_fav_merge  : priv only  — union a localStorage list into user meta.
 *   - mlsimport_saved_cards: priv+nopriv — read-only. Resolve a { k, p } list to
 *                            the CURRENT published posts and render their cards +
 *                            pager. Only surface a favorite that still exists.
 *   - mlsimport_fav_nonce  : nopriv     — mint a fresh nonce so a full-page-cached
 *                            anonymous "My saved" view isn't broken by a stale one.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Favorites store + AJAX + the card/button controls.
 */
class Mlsimport_Favorites {

	/** User-meta key holding the saved list (array of array{k:string,p:int}). */
	const META_KEY = 'mlsimport_favorites';

	/** Shared nonce action for every favorites request. */
	const NONCE = 'mlsimport_fav';

	/**
	 * Hard server-side ceiling on any incoming/stored list. Not a user-facing cap
	 * (nobody legitimately saves 500 homes) — it bounds the merge/resolve endpoints
	 * so a forged payload can't make the resolver do thousands of lookups.
	 */
	const MAX = 500;

	/**
	 * Register the store, the controls and the AJAX endpoints.
	 *
	 * @return void
	 */
	public static function register(): void {
		// The card heart hooks the (relocated) after-media slot on every card.
		add_action( 'mlsimport_card_after_media', array( __CLASS__, 'render_card_heart' ), 10, 2 );

		add_action( 'wp_ajax_mlsimport_fav_toggle', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'wp_ajax_mlsimport_fav_merge', array( __CLASS__, 'handle_merge' ) );

		add_action( 'wp_ajax_mlsimport_saved_cards', array( __CLASS__, 'handle_saved_cards' ) );
		add_action( 'wp_ajax_nopriv_mlsimport_saved_cards', array( __CLASS__, 'handle_saved_cards' ) );

		// Fresh-nonce mint for the anonymous saved view on cached pages.
		add_action( 'wp_ajax_mlsimport_fav_nonce', array( __CLASS__, 'handle_nonce' ) );
		add_action( 'wp_ajax_nopriv_mlsimport_fav_nonce', array( __CLASS__, 'handle_nonce' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Store
	 * --------------------------------------------------------------------- */

	/**
	 * The logged-in user's saved list, normalised to array{k:string,p:int}[].
	 *
	 * @param int $user_id User id (0 = current).
	 * @return array
	 */
	public static function get( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}
		return self::sanitize_list( get_user_meta( $user_id, self::META_KEY, true ) );
	}

	/**
	 * Persist a saved list for a user (deduped by ListingKey, capped).
	 *
	 * @param int   $user_id User id.
	 * @param array $list    Raw list.
	 * @return array The stored (deduped) list.
	 */
	public static function save( int $user_id, array $list ): array {
		$list = self::sanitize_list( $list );
		update_user_meta( $user_id, self::META_KEY, $list );
		return $list;
	}

	/**
	 * Normalise any incoming list to unique array{k,p} entries. Dedupe key is the
	 * ListingKey (k): an entry without a key is meaningless and dropped. Truncated
	 * to MAX so a forged payload can't blow up the store or the resolver.
	 *
	 * @param mixed $raw Array, or a JSON string of one.
	 * @return array
	 */
	public static function sanitize_list( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$k = isset( $entry['k'] ) ? sanitize_text_field( (string) $entry['k'] ) : '';
			if ( '' === $k || isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = array(
				'k' => $k,
				'p' => isset( $entry['p'] ) ? absint( $entry['p'] ) : 0,
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/* --------------------------------------------------------------------- *
	 * AJAX: writes (logged-in only)
	 * --------------------------------------------------------------------- */

	/**
	 * Save or remove one favorite for the current user.
	 *
	 * @return void
	 */
	public static function handle_toggle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'not-logged-in' ), 403 );
		}

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$k  = isset( $_POST['k'] ) ? sanitize_text_field( wp_unslash( $_POST['k'] ) ) : '';
		$p  = isset( $_POST['p'] ) ? absint( $_POST['p'] ) : 0;
		if ( '' === $k || ! in_array( $op, array( 'save', 'remove' ), true ) ) {
			wp_send_json_error( array( 'message' => 'bad-request' ), 400 );
		}

		$list = self::get( $user_id );
		$list = array_values( array_filter( $list, static fn( $e ) => $e['k'] !== $k ) );
		if ( 'save' === $op ) {
			array_unshift( $list, array( 'k' => $k, 'p' => $p ) );
		}
		$list = self::save( $user_id, $list );

		wp_send_json_success( array( 'count' => count( $list ) ) );
	}

	/**
	 * Union an anonymous localStorage list into the user's saved list on login.
	 *
	 * @return void
	 */
	public static function handle_merge(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'not-logged-in' ), 403 );
		}

		$incoming = isset( $_POST['list'] ) ? wp_unslash( $_POST['list'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_list().
		$incoming = self::sanitize_list( $incoming );

		// Union by ListingKey: existing saves win (keep their post_id hint), new
		// anon entries are appended. Idempotent — a retried merge can't duplicate.
		$existing = self::get( $user_id );
		$have     = array();
		foreach ( $existing as $e ) {
			$have[ $e['k'] ] = true;
		}
		foreach ( $incoming as $e ) {
			if ( ! isset( $have[ $e['k'] ] ) ) {
				$existing[]      = $e;
				$have[ $e['k'] ] = true;
			}
		}
		$merged = self::save( $user_id, $existing );

		wp_send_json_success( array( 'list' => $merged ) );
	}

	/* --------------------------------------------------------------------- *
	 * AJAX: read (public — resolves a client list to current published cards)
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve a { k, p } list to the current cards for one page, plus a pager and
	 * the keys that are gone (so the client can prune them). Read-only, renders
	 * only published listings — a forged post_id can't surface private content.
	 *
	 * @return void
	 */
	public static function handle_saved_cards(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$incoming = isset( $_POST['list'] ) ? wp_unslash( $_POST['list'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_list().
		$list     = self::sanitize_list( $incoming );
		$page     = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? max( 1, min( 48, (int) $_POST['per_page'] ) ) : 12;

		$resolved = self::resolve( $list );
		$ids      = $resolved['ids'];
		$total    = count( $ids );

		$page_ids = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );
		$cards    = $page_ids ? Mlsimport_Standalone_Render::cards_for_posts( $page_ids ) : '';

		$pager = class_exists( 'Mlsimport_Pagination' )
			? Mlsimport_Pagination::render( $total, $per_page, $page, array( 'param' => 'mlsimport_page' ) )
			: '';

		wp_send_json_success(
			array(
				'html'  => $cards,
				'pager' => $pager,
				'total' => $total,
				'prune' => $resolved['prune'],
			)
		);
	}

	/**
	 * Map a { k, p } list to the CURRENT published post ids (order preserved), and
	 * report the ListingKeys that no longer resolve to any live listing.
	 *
	 * The mlsimport_listings row is the authoritative post_id<->listing_key index
	 * (UNIQUE listing_key), so one query maps both the saved keys and the saved
	 * post_ids to whatever post currently holds them. An entry is pruned only when
	 * BOTH its post_id and its ListingKey miss (two-key confirmation) — a genuinely
	 * off-market listing, not a transient miss.
	 *
	 * @param array $list array{k:string,p:int}[].
	 * @return array{ids:int[],prune:string[]}
	 */
	public static function resolve( array $list ): array {
		global $wpdb;
		if ( empty( $list ) ) {
			return array( 'ids' => array(), 'prune' => array() );
		}

		$keys = array();
		$pids = array();
		foreach ( $list as $e ) {
			if ( '' !== $e['k'] ) {
				$keys[] = $e['k'];
			}
			if ( $e['p'] > 0 ) {
				$pids[] = $e['p'];
			}
		}

		$table = $wpdb->prefix . 'mlsimport_listings';

		// One indexed lookup: rows whose listing_key OR post_id is in the saved set.
		$by_key = array();
		$by_pid = array();
		$clauses = array();
		$params  = array();
		if ( $keys ) {
			$clauses[] = 'listing_key IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')';
			$params    = array_merge( $params, $keys );
		}
		if ( $pids ) {
			$clauses[] = 'post_id IN (' . implode( ', ', array_fill( 0, count( $pids ), '%d' ) ) . ')';
			$params    = array_merge( $params, $pids );
		}
		if ( $clauses ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, listing_key FROM {$table} WHERE " . implode( ' OR ', $clauses ), $params ) );
			foreach ( (array) $rows as $r ) {
				$by_key[ (string) $r->listing_key ] = (int) $r->post_id;
				$by_pid[ (int) $r->post_id ]        = (int) $r->post_id;
			}
		}

		// Keep only post ids that are still a published standalone property, so a
		// trashed/draft listing (or a forged post_id) never renders.
		$candidate = array_values( array_unique( array_map( 'intval', array_merge( array_values( $by_key ), array_keys( $by_pid ) ) ) ) );
		$live      = array();
		if ( $candidate ) {
			$published = get_posts(
				array(
					'post_type'      => 'mlsimport_property',
					'post_status'    => 'publish',
					'post__in'       => $candidate,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$live = array_fill_keys( array_map( 'intval', $published ), true );
		}

		// Walk the saved list IN ORDER: each entry resolves to its current live post
		// (key first, then post_id hint). Two-key miss => prune.
		$ids   = array();
		$prune = array();
		$added = array();
		foreach ( $list as $e ) {
			$pid = 0;
			if ( '' !== $e['k'] && isset( $by_key[ $e['k'] ] ) && isset( $live[ $by_key[ $e['k'] ] ] ) ) {
				$pid = $by_key[ $e['k'] ];
			} elseif ( $e['p'] > 0 && isset( $live[ $e['p'] ] ) ) {
				$pid = $e['p'];
			}

			if ( $pid > 0 ) {
				if ( ! isset( $added[ $pid ] ) ) {
					$ids[]         = $pid;
					$added[ $pid ] = true;
				}
			} elseif ( '' !== $e['k'] ) {
				$prune[] = $e['k'];
			}
		}

		return array( 'ids' => $ids, 'prune' => $prune );
	}

	/**
	 * Mint a fresh favorites nonce (public). Nonces are CSRF tokens, not secrets;
	 * minting one freely is safe — the state-changing endpoints are still priv-only
	 * and tied to the logged-in session. This exists so a full-page-cached anonymous
	 * saved view can refresh a stale, baked-in nonce before it calls saved_cards.
	 *
	 * @return void
	 */
	public static function handle_nonce(): void {
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE ) ) );
	}

	/* --------------------------------------------------------------------- *
	 * Controls
	 * --------------------------------------------------------------------- */

	/**
	 * The card heart (icon-only), hooked into mlsimport_card_after_media. Rendered
	 * unsaved for everyone; favorites.js flips saved entries on hydration. The card
	 * carries both ids so the client can build a { k, p } entry from either surface.
	 *
	 * @param WP_Post     $post The listing post.
	 * @param object|null $row  The mlsimport_listings row (carries listing_key).
	 * @return void
	 */
	public static function render_card_heart( $post, $row ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$pid = (int) $post->ID;
		$key = self::listing_key_for( $pid, $row );
		if ( '' === $key ) {
			return; // No stable identity — nothing to persist against.
		}
		echo self::heart_html( $pid, $key, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * The single-property page's labeled Save/Saved button. Replaces the former
	 * cosmetic button; same slot, driven by the same favorites.js off the same store.
	 *
	 * @param int    $post_id     Property post id (0 in live/passthrough mode).
	 * @param string $listing_key RESO ListingKey.
	 * @return string
	 */
	public static function single_button_html( int $post_id, string $listing_key ): string {
		if ( '' === $listing_key ) {
			return '';
		}
		$heart = function_exists( 'mlsimport_property_icon' ) ? mlsimport_property_icon( 'heart' ) : '';
		return '<button type="button" class="mlsimport-property-title-bar__action mlsimport-fav" data-mlsimport-fav'
			. ' data-id="' . esc_attr( (string) $post_id ) . '"'
			. ' data-listing-key="' . esc_attr( $listing_key ) . '"'
			. ' aria-pressed="false">'
			. $heart
			. '<span data-fav-label>' . esc_html__( 'Save', 'mlsimport' ) . '</span></button>';
	}

	/**
	 * The icon-only heart control markup.
	 *
	 * @param int    $post_id     Property post id.
	 * @param string $listing_key RESO ListingKey.
	 * @param bool   $saved       Initial saved state (server always prints false).
	 * @return string
	 */
	public static function heart_html( int $post_id, string $listing_key, bool $saved = false ): string {
		$heart = function_exists( 'mlsimport_property_icon' ) ? mlsimport_property_icon( 'heart' ) : '';
		$cls   = 'mlsimport-fav mlsimport-fav--heart' . ( $saved ? ' is-saved' : '' );
		return '<button type="button" class="' . esc_attr( $cls ) . '" data-mlsimport-fav'
			. ' data-id="' . esc_attr( (string) $post_id ) . '"'
			. ' data-listing-key="' . esc_attr( $listing_key ) . '"'
			. ' aria-pressed="' . ( $saved ? 'true' : 'false' ) . '"'
			. ' aria-label="' . esc_attr__( 'Save this property', 'mlsimport' ) . '">'
			. $heart . '</button>';
	}

	/**
	 * The ListingKey for a listing: the fast-table row wins (it is the authoritative
	 * index), then the post meta, so both stored and (row-fed) surfaces resolve it.
	 *
	 * @param int         $post_id Property post id.
	 * @param object|null $row     The mlsimport_listings row, when the caller has it.
	 * @return string
	 */
	public static function listing_key_for( int $post_id, $row = null ): string {
		if ( is_object( $row ) && isset( $row->listing_key ) && '' !== (string) $row->listing_key ) {
			return (string) $row->listing_key;
		}
		return (string) get_post_meta( $post_id, 'mlsimport_ListingKey', true );
	}

	/**
	 * Print the per-page bootstrap for favorites.js: endpoints, a nonce, whether the
	 * viewer is logged in, and (logged-in only) their saved { k, p } list so the
	 * client can flip the matching hearts without a round-trip.
	 *
	 * @return array
	 */
	public static function bootstrap(): array {
		$logged_in = is_user_logged_in();
		return array(
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'loggedIn' => $logged_in,
			'list'     => $logged_in ? self::get() : array(),
			'i18n'     => array(
				'save'  => __( 'Save', 'mlsimport' ),
				'saved' => __( 'Saved', 'mlsimport' ),
			),
		);
	}
}
