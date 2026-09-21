<?php
/**
 * Saved Search front end (Standalone mode only): everything a visitor touches.
 *
 *   - The "Save this search" button + its modal, printed into the results
 *     toolbar of the SEARCH RESULTS block only (next to the Sort control) via
 *     the `mlsimport_results_toolbar` action. Other listing grids (the archive,
 *     the MLS Listings block) fire the same action but never ask for the button.
 *   - The AJAX endpoint the modal posts to (logged-in and anonymous visitors —
 *     no WordPress account is needed). An agent uses the very same button and
 *     types the client's name and email; the client still has to confirm.
 *   - The two emailed links: ?mlsimport_ss=confirm|unsubscribe&token=...
 *
 * The HTTP/nonce shells here stay thin; the work is in Mlsimport_Saved_Search
 * (create/confirm/unsubscribe) so it is testable without a request.
 *
 * Extension points: mlsimport_results_toolbar (action, fired by render_grid),
 * mlsimport_saved_search_button_html, mlsimport_saved_search_link_message.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Save button/modal and handles its AJAX + the emailed links.
 */
class Mlsimport_Saved_Search_Front {

	const ACTION = 'mlsimport_save_search';

	/** Query arg the emailed links redirect with, so the site shows the answer. */
	const NOTICE_ARG = 'mlsimport_ss_notice';

	/** Notice state => [ link action, ok ] it stands for. */
	const NOTICE_STATES = array(
		'confirmed'    => array( 'confirm', true ),
		'unsubscribed' => array( 'unsubscribe', true ),
		'invalid'      => array( '', false ),
	);

	/**
	 * Hook everything up. Nothing is hooked while the feature is unusable
	 * (not Standalone mode, or switched off), except the emailed links: a
	 * Recipient must always be able to unsubscribe.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle_link' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_notice' ), 22 );
		add_action( 'wp_footer', array( __CLASS__, 'print_notice' ) );

		if ( ! Mlsimport_Saved_Search::enabled() ) {
			return;
		}
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'mlsimport_results_toolbar', array( __CLASS__, 'toolbar_button' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 21 );
	}

	/**
	 * AJAX entry: verify the nonce, run create(), answer JSON.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_ajax_referer( self::ACTION, 'nonce' );

		$result = Mlsimport_Saved_Search::create( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ), 400 );
	}

	/**
	 * Act on an emailed link. Pure of the HTTP layer: takes the query args,
	 * returns what to tell the visitor — or null when the request is not ours.
	 *
	 * @param array $query Request query args (unslashed).
	 * @return array{ok:bool,action:string,message:string}|null
	 */
	public static function link_result( array $query ) {
		$action = isset( $query['mlsimport_ss'] ) ? (string) $query['mlsimport_ss'] : '';
		if ( ! in_array( $action, array( 'confirm', 'unsubscribe' ), true ) ) {
			return null;
		}
		$token = isset( $query['token'] ) ? (string) $query['token'] : '';

		// The link does exactly one thing: confirm, or unsubscribe. Nothing else.
		$ok = 'confirm' === $action
			? Mlsimport_Saved_Search::confirm( $token )
			: Mlsimport_Saved_Search::unsubscribe( $token );

		return array(
			'ok'      => $ok,
			'action'  => $action,
			'message' => self::link_message( $action, $ok ),
		);
	}

	/**
	 * The words shown after an emailed link is used.
	 *
	 * @param string $action 'confirm' or 'unsubscribe'.
	 * @param bool   $ok     Whether the link worked.
	 * @return string
	 */
	public static function link_message( string $action, bool $ok ): string {
		if ( ! $ok ) {
			$message = __( 'This link is not valid any more.', 'mlsimport' );
		} elseif ( 'confirm' === $action ) {
			$message = __( 'Your saved search is confirmed. You will get one email a day when new or updated listings match it.', 'mlsimport' );
		} else {
			$message = __( 'You are unsubscribed. You will not receive any more emails for this saved search.', 'mlsimport' );
		}

		/** Filter the message shown after an emailed link is used. @since 7.3 */
		return (string) apply_filters( 'mlsimport_saved_search_link_message', $message, $action, $ok );
	}

	/**
	 * template_redirect shell for the emailed links: act on the token, then send
	 * the visitor on, where print_notice() shows the answer inside the site's own
	 * layout. A confirmed search lands on the results page it was saved from, with
	 * its criteria applied; an unsubscribe or a dead link lands on the home page.
	 * The token never reaches the landing URL.
	 *
	 * @return void
	 */
	public static function handle_link(): void {
		// Step 1 — act on the token; null means the request is not one of our links.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the emailed token is the credential.
		$query  = wp_unslash( $_GET );
		$result = self::link_result( $query );
		if ( null === $result ) {
			return;
		}

		// Step 2 — the notice state, and where it is shown. Only a successful confirm
		// goes back to the saved results page (stored at save time, same-host checked
		// there); the home page is the fallback should that URL be missing.
		$target = home_url( '/' );
		if ( ! $result['ok'] ) {
			$state = 'invalid';
		} elseif ( 'confirm' === $result['action'] ) {
			$state  = 'confirmed';
			$saved  = Mlsimport_Saved_Search::results_url_for_token( (string) $query['token'] );
			$target = '' !== $saved ? $saved : $target;
		} else {
			$state = 'unsubscribed';
		}

		// Step 3 — redirect with the state; print_notice() strips it from the address bar.
		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $state, $target ) );
		exit;
	}

	/**
	 * The notice state in the current request, or '' when there is none.
	 *
	 * @return string
	 */
	private static function notice_state(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, whitelisted below.
		$state = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		return isset( self::NOTICE_STATES[ $state ] ) ? $state : '';
	}

	/**
	 * Load the notice style on the landing page. Hooked apart from enqueue() so it
	 * also works when the feature is switched off (unsubscribe must always work).
	 *
	 * @return void
	 */
	public static function enqueue_notice(): void {
		if ( '' === self::notice_state() || ! apply_filters( 'mlsimport_standalone_styles', true ) ) {
			return;
		}
		wp_enqueue_style( 'mlsimport-saved-search', MLSIMPORT_PLUGIN_URL . 'public/css/mlsimport-saved-search.css', array(), MLSIMPORT_VERSION );
	}

	/**
	 * Print the link's answer as a banner over the landing page. Fixed-position
	 * from wp_footer, so it shows on any theme, classic or block.
	 *
	 * @return void
	 */
	public static function print_notice(): void {
		$state = self::notice_state();
		if ( '' === $state ) {
			return;
		}
		list( $action, $ok ) = self::NOTICE_STATES[ $state ];
		$message             = self::link_message( $action, $ok );

		printf(
			'<div class="mlsimport-ss-notice%1$s" role="status" data-mlsimport-ss-notice><p class="mlsimport-ss-notice__text">%2$s</p><button type="button" class="mlsimport-ss-notice__close" aria-label="%3$s" onclick="this.parentNode.remove()">&times;</button></div>',
			$ok ? '' : ' is-error',
			esc_html( $message ),
			esc_attr__( 'Close', 'mlsimport' )
		);

		// Drop the arg from the address bar at once (a refresh or a shared link must
		// not show the message again), then fade the box out after a few seconds.
		printf(
			'<script>(function(){var a=%1$s;try{var u=new URL(location.href);if(u.searchParams.has(a)){u.searchParams.delete(a);history.replaceState(null,"",u.pathname+u.search+u.hash);}}catch(e){}setTimeout(function(){var n=document.querySelector("[data-mlsimport-ss-notice]");if(!n){return;}n.classList.add("is-hiding");setTimeout(function(){n.remove();},300);},6000);})();</script>',
			wp_json_encode( self::NOTICE_ARG )
		);
	}

	/**
	 * Print the button + modal into a results toolbar — only when the surface
	 * asked for it ($args['saved_search'], set by the Search Results block).
	 *
	 * @param array $args The grid's render args.
	 * @return void
	 */
	public static function toolbar_button( $args ): void {
		// Step 1 — only the Search Results block opts in, and only while enabled
		// (re-checked here: the setting may be filtered per request).
		if ( empty( $args['saved_search'] ) || ! Mlsimport_Saved_Search::enabled() ) {
			return;
		}

		// Step 2 — a logged-in visitor gets their own name/email prefilled. An agent
		// saving for a client simply overwrites them.
		$user  = wp_get_current_user();
		$name  = $user->exists() ? (string) $user->display_name : '';
		$email = $user->exists() ? (string) $user->user_email : '';

		// Step 3 — button + native <dialog> (no modal library; Esc/backdrop close for
		// free). The JS reads the current filters from the grid's own search form.
		$html  = '<button type="button" class="mlsimport-save-search__open" data-mlsimport-save-search>' . esc_html__( 'Save this search', 'mlsimport' ) . '</button>';
		$html .= '<dialog class="mlsimport-save-search" data-mlsimport-save-search-dialog>';
		$html .= '<form class="mlsimport-save-search__form" method="dialog">';
		$html .= '<h3 class="mlsimport-save-search__title">' . esc_html__( 'Save this search', 'mlsimport' ) . '</h3>';
		$html .= '<p class="mlsimport-save-search__lead">' . esc_html__( 'Get one email a day with new and updated listings that match.', 'mlsimport' ) . '</p>';
		$html .= '<input type="text" name="mlsimport_name" required placeholder="' . esc_attr__( 'Name', 'mlsimport' ) . '" value="' . esc_attr( $name ) . '" />';
		$html .= '<input type="email" name="mlsimport_email" required placeholder="' . esc_attr__( 'Email', 'mlsimport' ) . '" value="' . esc_attr( $email ) . '" />';
		// Honeypot: hidden from people, irresistible to bots.
		$html .= '<input type="text" name="mlsimport_hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true" />';
		$html .= mlsimport_property_lead_consent_field();
		$html .= '<p class="mlsimport-save-search__message" role="status" aria-live="polite"></p>';
		$html .= '<div class="mlsimport-save-search__actions">';
		$html .= '<button type="button" class="mlsimport-save-search__cancel" data-mlsimport-save-search-cancel>' . esc_html__( 'Cancel', 'mlsimport' ) . '</button>';
		$html .= '<button type="submit" class="mlsimport-save-search__submit">' . esc_html__( 'Save search', 'mlsimport' ) . '</button>';
		$html .= '</div>';
		// Top-right "x", last in the DOM so the dialog still autofocuses the Name field
		// (CSS pins it to the corner). Shares the Cancel button's data attribute, so the JS closes
		// the dialog from either one with the same listener.
		$html .= '<button type="button" class="mlsimport-save-search__close" data-mlsimport-save-search-cancel aria-label="' . esc_attr__( 'Close', 'mlsimport' ) . '">&times;</button>';
		$html .= '</form></dialog>';

		/** Filter the Save button + modal markup. @since 7.3 */
		echo apply_filters( 'mlsimport_saved_search_button_html', $html, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic value is escaped above.
	}

	/**
	 * Enqueue the modal's script + style, with the AJAX envelope.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$url = MLSIMPORT_PLUGIN_URL;
		$ver = MLSIMPORT_VERSION;

		if ( apply_filters( 'mlsimport_standalone_styles', true ) ) {
			wp_enqueue_style( 'mlsimport-saved-search', $url . 'public/css/mlsimport-saved-search.css', array( 'mlsimport-listings' ), $ver );
		}
		wp_enqueue_script( 'mlsimport-saved-search', $url . 'public/js/mlsimport-saved-search.js', array(), $ver, true );
		wp_localize_script(
			'mlsimport-saved-search',
			'MLSImportSavedSearch',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION,
				'nonce'   => wp_create_nonce( self::ACTION ),
				'error'   => __( 'Sorry, your search could not be saved. Please try again.', 'mlsimport' ),
			)
		);
	}
}
