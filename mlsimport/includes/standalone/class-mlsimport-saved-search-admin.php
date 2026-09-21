<?php
/**
 * Saved Search in wp-admin (Standalone mode only): the owner's list + privacy.
 *
 *   - "Saved Searches" list under the MLS Import menu. A LIST ONLY: columns
 *     name, email, criteria, status, last sent, created; row actions
 *     Deactivate and Delete. No add, no edit — a Saved Search is only ever
 *     created from the search results, and changing one means saving a new one.
 *   - WordPress privacy tools (Tools -> Export / Erase Personal Data): an
 *     exporter and an eraser, both keyed by the Recipient's email address.
 *
 * Extension points: mlsimport_saved_search_admin_columns.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The wp-admin list screen and the privacy exporter/eraser.
 */
class Mlsimport_Saved_Search_Admin {

	const DEACTIVATE = 'mlsimport_saved_search_deactivate';

	/**
	 * Hook the list screen and the privacy registries.
	 *
	 * @return void
	 */
	public static function register(): void {
		$type = Mlsimport_Saved_Search::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( "bulk_actions-edit-{$type}", '__return_empty_array' );
		add_action( 'admin_post_' . self::DEACTIVATE, array( __CLASS__, 'handle_deactivate' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );

		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Give the Saved Searches list the plugin's own admin colour scheme (cream
	 * canvas, near-black links and focus rings) like every other MLS Import screen.
	 *
	 * mlsimport-admin.css scopes that scheme to any body class containing
	 * "_page_mlsimport" (the class WordPress gives the plugin's menu pages). A post
	 * type list does not get one, so the screen adds a class of that shape itself and
	 * the existing rules apply - no second copy of the palette to keep in step.
	 *
	 * @param string $classes Space-separated admin body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		// Step 1 - only the Saved Searches screen; every other screen is untouched.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Mlsimport_Saved_Search::POST_TYPE !== $screen->post_type ) {
			return $classes;
		}
		// Step 2 - append the class the stylesheet's attribute selector matches.
		return $classes . ' mlsimport_page_mlsimport_saved_search';
	}

	/**
	 * The list's columns. The core Title column is dropped on purpose: it links to
	 * the edit screen, and a Saved Search is not editable.
	 *
	 * @return array<string,string>
	 */
	public static function columns(): array {
		$columns = array(
			'ss_name'      => __( 'Name', 'mlsimport' ),
			'ss_email'     => __( 'Email', 'mlsimport' ),
			'ss_criteria'  => __( 'Criteria', 'mlsimport' ),
			'ss_status'    => __( 'Status', 'mlsimport' ),
			'ss_last_sent' => __( 'Last sent', 'mlsimport' ),
			'ss_created'   => __( 'Created', 'mlsimport' ),
		);
		/** Filter the Saved Searches list columns. @since 7.3 */
		return (array) apply_filters( 'mlsimport_saved_search_admin_columns', $columns );
	}

	/**
	 * Print one cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Saved Search post ID.
	 * @return void
	 */
	public static function column( $column, $post_id ): void {
		$search = Mlsimport_Saved_Search::get( (int) $post_id );
		if ( null === $search ) {
			return;
		}
		$statuses = array(
			'pending'      => __( 'Pending confirmation', 'mlsimport' ),
			'active'       => __( 'Active', 'mlsimport' ),
			'unsubscribed' => __( 'Unsubscribed', 'mlsimport' ),
		);

		switch ( $column ) {
			case 'ss_name':
				// The first column also carries the row actions, so it links to the
				// live results page — the one useful place to go from here.
				echo '<strong><a href="' . esc_url( $search['results_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $search['name'] ) . '</a></strong>';
				break;
			case 'ss_email':
				echo '<a href="' . esc_url( 'mailto:' . $search['email'] ) . '">' . esc_html( $search['email'] ) . '</a>';
				break;
			case 'ss_criteria':
				echo esc_html( implode( ' · ', Mlsimport_Saved_Search_Mailer::criteria_lines( $search['params'] ) ) );
				break;
			case 'ss_status':
				echo esc_html( isset( $statuses[ $search['status'] ] ) ? $statuses[ $search['status'] ] : $search['status'] );
				break;
			case 'ss_last_sent':
				echo esc_html( '' !== $search['last_sent'] ? mysql2date( get_option( 'date_format' ), $search['last_sent'] ) : '—' );
				break;
			case 'ss_created':
				echo esc_html( mysql2date( get_option( 'date_format' ), $search['created'] ) );
				break;
		}
	}

	/**
	 * Row actions: Deactivate (while it can still send) and Delete. Edit, Quick
	 * Edit, Trash and View are removed — none applies to a Saved Search.
	 *
	 * @param array   $actions Core row actions.
	 * @param WP_Post $post    Row post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( Mlsimport_Saved_Search::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		$actions = array();

		if ( 'unsubscribed' !== get_post_meta( $post->ID, 'mlsimport_ss_status', true ) ) {
			$url                   = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DEACTIVATE . '&id=' . $post->ID ), self::DEACTIVATE . '_' . $post->ID );
			$actions['deactivate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Deactivate', 'mlsimport' ) . '</a>';
		}
		// Permanent delete (no Trash stop: a trashed search would linger with its
		// personal data for 30 days for no benefit).
		$actions['delete'] = '<a class="submitdelete" href="' . esc_url( (string) get_delete_post_link( $post->ID, '', true ) ) . '">' . esc_html__( 'Delete', 'mlsimport' ) . '</a>';
		return $actions;
	}

	/**
	 * The Deactivate row action: nonce + capability, then the same deactivate()
	 * the emailed unsubscribe link uses. Back to the list afterwards.
	 *
	 * @return void
	 */
	public static function handle_deactivate(): void {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( self::DEACTIVATE . '_' . $id );
		if ( ! current_user_can( 'delete_post', $id ) || null === Mlsimport_Saved_Search::get( $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mlsimport' ) );
		}
		Mlsimport_Saved_Search::deactivate( $id );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Mlsimport_Saved_Search::POST_TYPE ) );
		exit;
	}

	/**
	 * Register the personal-data exporter with WordPress.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['mlsimport-saved-search'] = array(
			'exporter_friendly_name' => __( 'MLSImport saved searches', 'mlsimport' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal-data eraser with WordPress.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['mlsimport-saved-search'] = array(
			'eraser_friendly_name' => __( 'MLSImport saved searches', 'mlsimport' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export every Saved Search whose Recipient is $email. The secret token is
	 * deliberately NOT exported: it is a credential, not the person's data.
	 *
	 * @param string $email Email address being exported.
	 * @return array{data:array,done:bool}
	 */
	public static function export( $email ): array {
		$data = array();
		foreach ( self::ids_for_email( (string) $email ) as $id ) {
			$search = Mlsimport_Saved_Search::get( $id );
			$data[] = array(
				'group_id'    => 'mlsimport-saved-searches',
				'group_label' => __( 'Saved searches', 'mlsimport' ),
				'item_id'     => 'mlsimport-saved-search-' . $id,
				'data'        => array(
					array( 'name' => __( 'Name', 'mlsimport' ), 'value' => $search['name'] ),
					array( 'name' => __( 'Email', 'mlsimport' ), 'value' => $search['email'] ),
					array( 'name' => __( 'Criteria', 'mlsimport' ), 'value' => implode( '; ', Mlsimport_Saved_Search_Mailer::criteria_lines( $search['params'] ) ) ),
					array( 'name' => __( 'Status', 'mlsimport' ), 'value' => $search['status'] ),
					array( 'name' => __( 'Created', 'mlsimport' ), 'value' => $search['created'] ),
					array( 'name' => __( 'Last sent', 'mlsimport' ), 'value' => $search['last_sent'] ),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Permanently delete every Saved Search whose Recipient is $email.
	 *
	 * @param string $email Email address being erased.
	 * @return array{items_removed:int,items_retained:bool,messages:array,done:bool}
	 */
	public static function erase( $email ): array {
		$removed = 0;
		foreach ( self::ids_for_email( (string) $email ) as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				++$removed;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * IDs of every Saved Search stored for one Recipient email (exact match).
	 *
	 * @param string $email Recipient email.
	 * @return int[]
	 */
	private static function ids_for_email( string $email ): array {
		if ( ! is_email( $email ) ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Mlsimport_Saved_Search::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => 'mlsimport_ss_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}
}
