<?php
/**
 * Standalone (theme_id 990) single-property lead endpoint.
 *
 * One AJAX action (mlsimport_property_lead) backs all four lead forms (agent
 * contact, agent form, sidebar form, schedule a tour). It validates a nonce +
 * honeypot, then emails the listing's linked agent (falling back to the settings
 * recipient, then the site admin). The work lives in process() so it is testable
 * without the HTTP/nonce layer. See the build plan, decision 5.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/property-sections.php';

/**
 * Registers and handles the shared property-lead AJAX endpoint.
 */
class Mlsimport_Property_Lead {

	const ACTION = 'mlsimport_property_lead';
	const NONCE  = 'mlsimport_property_lead';

	/**
	 * Hook the AJAX action for logged-in and anonymous visitors.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * AJAX entry: verify the nonce, run process(), return JSON.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$result = self::process( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ), 400 );
	}

	/**
	 * Validate and dispatch a lead. Pure of the nonce/HTTP layer.
	 *
	 * @param array $input Raw (unslashed) request fields.
	 * @return array{ok:bool,message:string,to:string}
	 */
	public static function process( array $input ): array {
		// Honeypot: a filled hidden field means a bot — drop silently as success.
		if ( ! empty( $input['mlsimport_hp'] ) ) {
			return array( 'ok' => true, 'message' => __( 'Thank you.', 'mlsimport' ), 'to' => '' );
		}

		$name    = isset( $input['mlsimport_name'] ) ? sanitize_text_field( $input['mlsimport_name'] ) : '';
		$email   = isset( $input['mlsimport_email'] ) ? sanitize_email( $input['mlsimport_email'] ) : '';
		$phone   = isset( $input['mlsimport_phone'] ) ? sanitize_text_field( $input['mlsimport_phone'] ) : '';
		$message = isset( $input['mlsimport_message'] ) ? sanitize_textarea_field( $input['mlsimport_message'] ) : '';
		$tour    = isset( $input['mlsimport_tour_date'] ) ? sanitize_text_field( $input['mlsimport_tour_date'] ) : '';
		$pid     = isset( $input['property_id'] ) ? absint( $input['property_id'] ) : 0;
		$aid     = isset( $input['agent_id'] ) ? absint( $input['agent_id'] ) : 0;

		if ( '' === $name || ! is_email( $email ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Please provide your name and a valid email.', 'mlsimport' ),
				'to'      => '',
			);
		}

		// Property lead forms (they alone carry the hidden property_id field)
		// render a mandatory privacy-consent checkbox — enforce it here too, the
		// browser `required` alone is not enough. Agent-rail and page-block
		// contact submissions have no property_id and are unaffected.
		if ( isset( $input['property_id'] ) && empty( $input['mlsimport_consent'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Please accept the privacy policy to send your message.', 'mlsimport' ),
				'to'      => '',
			);
		}

		// Extension validation (reCAPTCHA, blocklists...): a non-empty errors list
		// rejects the submission with the first message.
		/** Filter lead validation errors. @since 6.3 */
		$errors = (array) apply_filters( 'mlsimport_property_lead_validation', array(), $input, $pid );
		if ( ! empty( $errors ) ) {
			return array(
				'ok'      => false,
				'message' => (string) reset( $errors ),
				'to'      => '',
			);
		}

		$data = array(
			'name'        => $name,
			'email'       => $email,
			'phone'       => $phone,
			'message'     => $message,
			'tour'        => $tour,
			'property_id' => $pid,
			'agent_id'    => $aid,
		);

		/** Fires when a valid lead is submitted (CRM capture point). @since 6.3 */
		do_action( 'mlsimport_property_lead_submitted', $data, $pid );

		$to      = self::recipient( $pid, $aid );
		$subject = sprintf(
			/* translators: %s: listing title or agent name. */
			__( 'New enquiry: %s', 'mlsimport' ),
			$pid ? get_the_title( $pid ) : ( $aid ? get_the_title( $aid ) : __( 'Property', 'mlsimport' ) )
		);
		/** Filter the lead email subject. @since 6.3 */
		$subject = (string) apply_filters( 'mlsimport_property_lead_subject', $subject, $pid );

		$lines = array(
			__( 'Name:', 'mlsimport' ) . ' ' . $name,
			__( 'Email:', 'mlsimport' ) . ' ' . $email,
		);
		if ( '' !== $phone ) {
			$lines[] = __( 'Phone:', 'mlsimport' ) . ' ' . $phone;
		}
		if ( '' !== $tour ) {
			$lines[] = __( 'Requested tour date:', 'mlsimport' ) . ' ' . $tour;
		}
		// Custom contact-form fields (the page-block contact builder) — any extra
		// mlsimport_* input beyond the standard set is appended by its label.
		$standard = array( 'mlsimport_name', 'mlsimport_email', 'mlsimport_phone', 'mlsimport_message', 'mlsimport_tour_date', 'mlsimport_hp', 'mlsimport_context' );
		foreach ( $input as $field_key => $field_value ) {
			if ( 0 !== strpos( (string) $field_key, 'mlsimport_' ) || in_array( $field_key, $standard, true ) ) {
				continue;
			}
			$value = sanitize_text_field( is_array( $field_value ) ? implode( ', ', $field_value ) : (string) $field_value );
			if ( '' === $value ) {
				continue;
			}
			$label   = ucwords( str_replace( '_', ' ', substr( (string) $field_key, strlen( 'mlsimport_' ) ) ) );
			$lines[] = $label . ': ' . $value;
		}
		if ( $pid ) {
			$lines[] = __( 'Listing:', 'mlsimport' ) . ' ' . get_permalink( $pid );
		}
		if ( '' !== $message ) {
			$lines[] = '';
			$lines[] = $message;
		}

		$body = implode( "\n", $lines );
		/** Filter the lead email body. @since 6.3 */
		$body = (string) apply_filters( 'mlsimport_property_lead_email_body', $body, $data, $pid );

		$headers = array( 'Reply-To: ' . $name . ' <' . $email . '>' );
		$sent    = wp_mail( $to, $subject, $body, $headers );

		/** Fires after the lead mail attempt (CRM/observe point). @since 6.3 */
		do_action( 'mlsimport_property_lead_mail_sent', $sent, $data, $pid );

		return array(
			'ok'      => (bool) $sent,
			'message' => $sent
				? __( 'Your message has been sent.', 'mlsimport' )
				: __( 'Sorry, your message could not be sent. Please try again.', 'mlsimport' ),
			'to'      => $to,
		);
	}

	/**
	 * Resolve the recipient: the listing's linked agent post (property lead) or
	 * the agent's own email (agent-page lead), else settings recipient, else the
	 * site admin. Feed-only agent data (no local agent post) never receives the
	 * lead. Filterable via mlsimport_property_lead_recipient.
	 *
	 * @param int $property_id Property post ID.
	 * @param int $agent_id    Agent post ID (agent-page leads).
	 * @return string
	 */
	public static function recipient( int $property_id, int $agent_id = 0 ): string {
		$to = '';

		if ( $property_id ) {
			$vm = mlsimport_property_data( $property_id );
			// Only an agent that exists on the site (a linked mlsimport_agent post,
			// added or imported → agent.id > 0) receives the lead. Agent data shown
			// straight from the property's MLS feed meta has no local post, so the
			// lead routes to the settings recipient below.
			if ( ! empty( $vm['agent']['id'] ) && ! empty( $vm['agent']['email'] ) && is_email( $vm['agent']['email'] ) ) {
				$to = $vm['agent']['email'];
			}
		} elseif ( $agent_id ) {
			$email = (string) get_post_meta( $agent_id, 'mlsimport_ListAgentEmail', true );
			if ( is_email( $email ) ) {
				$to = $email;
			}
		}
		if ( '' === $to ) {
			$setting = (string) mlsimport_standalone_option( 'lead_recipient', '' );
			$to      = is_email( $setting ) ? $setting : (string) get_option( 'admin_email' );
		}

		/**
		 * Filter the resolved lead recipient email.
		 *
		 * @param string $to          Recipient email.
		 * @param int    $property_id Property post ID.
		 * @param int    $agent_id    Agent post ID (agent-page leads).
		 */
		return (string) apply_filters( 'mlsimport_property_lead_recipient', $to, $property_id, $agent_id );
	}
}
