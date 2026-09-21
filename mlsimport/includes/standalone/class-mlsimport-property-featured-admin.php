<?php
/**
 * Standalone (theme_id 990) — "Featured" on the Properties admin list (issue #288).
 *
 * The property edit screen already has a "Featured listing" checkbox (post meta
 * 'mlsimport_featured' = '1'). This file adds the two list-screen conveniences:
 *
 *   1. Bulk actions "Mark featured" / "Remove featured", so an owner can flag many
 *      listings without opening each one.
 *   2. A "Featured" column, so they can see at a glance which listings are flagged.
 *
 * The front end reads the flag from the fast listings table, not from post meta, so
 * every change made here re-indexes the post — Mlsimport_Standalone_Row::upsert()
 * then copies the meta into the row's `featured` column. Writing the meta alone
 * would change nothing a visitor sees.
 *
 * Kept apart from Mlsimport_Property_Columns (which owns the column SET) so neither
 * file outgrows the 300-line limit; this class only appends its one column.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk actions + list column for the "Featured listing" flag.
 */
class Mlsimport_Property_Featured_Admin {

	/** Post meta the "Featured listing" checkbox saves ('1' when ticked). */
	private const META = 'mlsimport_featured';

	/**
	 * Register the admin hooks. Hooked on init; only wires up in the admin.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'bulk_actions-edit-mlsimport_property', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-mlsimport_property', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		// Priority 20: Mlsimport_Property_Columns::columns() (priority 10) rebuilds the
		// whole column set, so the Featured column is appended after it has run.
		add_filter( 'manage_mlsimport_property_posts_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_mlsimport_property_posts_custom_column', array( __CLASS__, 'render' ), 10, 2 );
		// Priority 20, after Mlsimport_Property_Columns::sortable_columns() has run.
		add_filter( 'manage_edit-mlsimport_property_sortable_columns', array( __CLASS__, 'sortable' ), 20 );
		add_filter( 'posts_clauses', array( __CLASS__, 'orderby_featured' ), 10, 2 );
	}

	/**
	 * Make the Featured header clickable. `true` = the first click sorts DESC, so
	 * featured listings land on top straight away.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable( $columns ): array {
		$columns                       = (array) $columns;
		$columns['mlsimport_featured'] = array( 'mlsimport_featured', true );
		return $columns;
	}

	/**
	 * Sort by the featured flag when the header is clicked. A LEFT JOIN, not a
	 * meta_key orderby: most listings have no meta row at all, and meta_key would
	 * drop every one of them from the list. Newest first inside each group.
	 *
	 * @param array    $clauses SQL clause fragments.
	 * @param WP_Query $query   Current query.
	 * @return array
	 */
	public static function orderby_featured( $clauses, $query ): array {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $clauses;
		}
		if ( 'mlsimport_property' !== $query->get( 'post_type' ) || 'mlsimport_featured' !== $query->get( 'orderby' ) ) {
			return $clauses;
		}
		global $wpdb;
		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} mlsimport_featured_pm ON ( {$wpdb->posts}.ID = mlsimport_featured_pm.post_id AND mlsimport_featured_pm.meta_key = %s )", self::META );
		$order              = ( 'ASC' === strtoupper( (string) $query->get( 'order' ) ) ) ? 'ASC' : 'DESC';
		$clauses['orderby'] = "( mlsimport_featured_pm.meta_value = '1' ) $order, {$wpdb->posts}.post_date DESC";
		return $clauses;
	}

	/**
	 * Offer the two bulk actions in the Properties list dropdown.
	 *
	 * @param array $actions Bulk actions (key => label).
	 * @return array
	 */
	public static function bulk_actions( $actions ): array {
		$actions                              = (array) $actions;
		$actions['mlsimport_mark_featured']   = __( 'Mark featured', 'mlsimport' );
		$actions['mlsimport_remove_featured'] = __( 'Remove featured', 'mlsimport' );
		return $actions;
	}

	/**
	 * Apply "Mark featured" / "Remove featured" to the ticked listings.
	 *
	 * WordPress has already verified the bulk nonce and that the user may edit
	 * this post type before calling the filter; each post is still checked
	 * individually so a listing the user cannot edit is skipped.
	 *
	 * @param string $redirect Where WordPress sends the browser afterwards.
	 * @param string $action   The chosen bulk action key.
	 * @param array  $post_ids The ticked post IDs.
	 * @return string The redirect URL, carrying the changed count for notice().
	 */
	public static function handle_bulk( $redirect, $action, $post_ids ) {
		// Step 1: leave every bulk action that is not ours untouched.
		if ( 'mlsimport_mark_featured' !== $action && 'mlsimport_remove_featured' !== $action ) {
			return $redirect;
		}
		$value   = 'mlsimport_mark_featured' === $action ? '1' : '';
		$changed = 0;

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			// Step 2: only our post type, and only listings this user may edit. The
			// capability check is skipped under WP-CLI, which has no current user.
			if ( 'mlsimport_property' !== get_post_type( $post_id ) ) {
				continue;
			}
			if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			// Step 3: write the same meta the edit-screen checkbox writes...
			update_post_meta( $post_id, self::META, $value );
			// Step 4: ...then re-index, which is what moves the flag into the fast
			// listings table the front end sorts and badges from.
			if ( class_exists( 'Mlsimport_Standalone_Reindex' ) ) {
				Mlsimport_Standalone_Reindex::rebuild_post( $post_id );
			}
			++$changed;

			/** Fires after a listing's featured flag changed from the list screen. @since 7.2.2 */
			do_action( 'mlsimport_property_featured_changed', $post_id, '1' === $value );
		}

		// Step 5: hand the count to notice() through the redirect URL.
		return add_query_arg( 'mlsimport_featured_changed', $changed, (string) $redirect );
	}

	/**
	 * Confirm a finished bulk action on the Properties list.
	 *
	 * @return void
	 */
	public static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only count for a notice.
		if ( ! isset( $_GET['mlsimport_featured_changed'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$count = absint( wp_unslash( $_GET['mlsimport_featured_changed'] ) );
		/* translators: %s: number of listings. */
		$text = sprintf( _n( '%s listing updated.', '%s listings updated.', $count, 'mlsimport' ), number_format_i18n( $count ) );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Add the Featured column just before the native Date column.
	 *
	 * @param array $columns Columns (key => label).
	 * @return array
	 */
	public static function columns( $columns ): array {
		$out = array();
		foreach ( (array) $columns as $key => $label ) {
			// Slot ours in immediately ahead of Date.
			if ( 'date' === $key ) {
				$out['mlsimport_featured'] = esc_html__( 'Featured', 'mlsimport' );
			}
			$out[ $key ] = $label;
		}
		// No Date column on this screen (hidden by another plugin): append at the end.
		if ( ! isset( $out['mlsimport_featured'] ) ) {
			$out['mlsimport_featured'] = esc_html__( 'Featured', 'mlsimport' );
		}
		return $out;
	}

	/**
	 * Render the Featured cell: a star + "Featured" for a flagged listing, a dash
	 * (WordPress's usual "nothing here") otherwise.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Property post ID.
	 * @return void
	 */
	public static function render( $column, $post_id ): void {
		if ( 'mlsimport_featured' !== $column ) {
			return;
		}
		if ( '1' === (string) get_post_meta( $post_id, self::META, true ) ) {
			echo '<span class="dashicons dashicons-star-filled" style="color:#dba617" aria-hidden="true"></span> ' . esc_html__( 'Featured', 'mlsimport' );
			return;
		}
		echo '<span aria-hidden="true">&#8212;</span>';
	}
}
