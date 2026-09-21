<?php
/**
 * Saved Search mails (Standalone mode only): the three emails the feature sends.
 *
 *   1. send_confirmation() — to the Recipient, right after a save: the
 *      double-opt-in link. Nothing else is ever sent until it is clicked.
 *   2. send_owner_notice() — to the site owner, when a Recipient confirms.
 *   3. send_alert()        — to the Recipient, once a day, the matching listings.
 *
 * Every body is a theme-overridable template in templates/emails/ (copy it to
 * yourtheme/mlsimport/emails/ to restyle), resolved by the same loader as every
 * other standalone template. All mails go through wp_mail() as HTML, and every
 * mail to a Recipient carries the tokenized unsubscribe link plus a
 * List-Unsubscribe header so mail clients show their own unsubscribe button.
 *
 * Extension points: mlsimport_alert_email_subject, mlsimport_alert_email_body,
 * mlsimport_alert_email_headers, mlsimport_alert_sent.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and sends the Saved Search emails.
 */
class Mlsimport_Saved_Search_Mailer {

	/**
	 * The emailed link for one action on one Saved Search.
	 *
	 * @param string $action 'confirm' or 'unsubscribe'.
	 * @param string $token  The Saved Search token.
	 * @return string
	 */
	public static function link( string $action, string $token ): string {
		return add_query_arg(
			array(
				'mlsimport_ss' => $action,
				'token'        => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Mail the double-opt-in confirmation link to the Recipient.
	 *
	 * @param array $search Saved Search (Mlsimport_Saved_Search::get()).
	 * @return bool Whether wp_mail accepted it.
	 */
	public static function send_confirmation( array $search ): bool {
		/* translators: %s: site name. */
		$subject = sprintf( __( 'Confirm your saved search on %s', 'mlsimport' ), self::site_name() );
		$body    = self::render(
			'emails/saved-search-confirm.php',
			array(
				'search'      => $search,
				'confirm_url' => self::link( 'confirm', $search['token'] ),
				'criteria'    => self::criteria_lines( $search['params'] ),
			)
		);
		return self::send( $search['email'], $subject, $body, self::headers( $search ) );
	}

	/**
	 * Tell the site owner a Recipient confirmed a Saved Search. Goes to the
	 * "lead recipient" setting, falling back to the site admin email.
	 *
	 * @param array $search Saved Search.
	 * @return bool
	 */
	public static function send_owner_notice( array $search ): bool {
		$setting = (string) mlsimport_standalone_option( 'lead_recipient', '' );
		$to      = is_email( $setting ) ? $setting : (string) get_option( 'admin_email' );

		/* translators: %s: recipient name. */
		$subject = sprintf( __( 'New saved search: %s', 'mlsimport' ), $search['name'] );
		$body    = self::render(
			'emails/saved-search-owner.php',
			array(
				'search'   => $search,
				'criteria' => self::criteria_lines( $search['params'] ),
			)
		);
		// Reply goes straight to the person who saved the search.
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . $search['name'] . ' <' . $search['email'] . '>' );
		return self::send( $to, $subject, $body, $headers );
	}

	/**
	 * Mail today's matching listings to the Recipient.
	 *
	 * @param array $search Saved Search.
	 * @param array $data   Mlsimport_Standalone_Render::prepare() payload for the
	 *                      listings to show (already capped): posts, rows, total.
	 * @return bool
	 */
	public static function send_alert( array $search, array $data ): bool {
		$tokens  = array(
			'{site_name}' => self::site_name(),
			'{name}'      => $search['name'],
		);
		$subject = strtr( (string) mlsimport_standalone_option( 'saved_search_email_subject' ), $tokens );
		$intro   = strtr( (string) mlsimport_standalone_option( 'saved_search_email_intro' ), $tokens );

		$body = self::render(
			'emails/saved-search-alert.php',
			array(
				'search'          => $search,
				'intro'           => $intro,
				'criteria'        => self::criteria_lines( $search['params'] ),
				'data'            => $data,
				'unsubscribe_url' => self::link( 'unsubscribe', $search['token'] ),
			)
		);

		/** Filter the alert email subject. @since 7.3 */
		$subject = (string) apply_filters( 'mlsimport_alert_email_subject', $subject, $search, $data );
		/** Filter the alert email HTML body. @since 7.3 */
		$body = (string) apply_filters( 'mlsimport_alert_email_body', $body, $search, $data );
		/** Filter the alert email headers. @since 7.3 */
		$headers = (array) apply_filters( 'mlsimport_alert_email_headers', self::headers( $search ), $search, $data );

		$sent = self::send( $search['email'], $subject, $body, $headers );

		/** Fires after an alert mail attempt. @since 7.3 */
		do_action( 'mlsimport_alert_sent', $sent, $search, $data );
		return (bool) $sent;
	}

	/**
	 * The criteria as readable "Label: value" lines, for the mail and wp-admin.
	 * A param the visitor-facing catalog has no label for falls back to its key.
	 *
	 * @param array $params Stored search params.
	 * @return string[]
	 */
	public static function criteria_lines( array $params ): array {
		$labels = array(
			'price_min' => __( 'Min price', 'mlsimport' ),
			'price_max' => __( 'Max price', 'mlsimport' ),
			'beds'      => __( 'Beds (min)', 'mlsimport' ),
			'baths'     => __( 'Baths (min)', 'mlsimport' ),
			'sqft_min'  => __( 'Min sq ft', 'mlsimport' ),
			'sqft_max'  => __( 'Max sq ft', 'mlsimport' ),
			'location'  => __( 'Location', 'mlsimport' ),
			'keywords'  => __( 'Keywords', 'mlsimport' ),
			'polygon'   => __( 'Area drawn on the map', 'mlsimport' ),
		) + Mlsimport_Page_Block_Search_Fields::labels();

		$lines = array();
		foreach ( $params as $key => $value ) {
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : ucwords( str_replace( '_', ' ', (string) $key ) );
			// A drawn polygon is a coordinate string nobody can read; name it only.
			if ( 'polygon' === $key ) {
				$lines[] = $label;
				continue;
			}
			$lines[] = $label . ': ' . implode( ', ', array_map( 'strval', (array) $value ) );
		}
		return $lines;
	}

	/**
	 * Headers for a mail to the Recipient: HTML + the List-Unsubscribe pair.
	 *
	 * @param array $search Saved Search.
	 * @return string[]
	 */
	private static function headers( array $search ): array {
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'List-Unsubscribe: <' . self::link( 'unsubscribe', $search['token'] ) . '>',
		);
		// A reply reaches the company (Social & Contact → Email), not the WordPress
		// default mailbox. The From address itself stays the site's own: a foreign
		// domain there fails SPF/DMARC and lands the alert in spam.
		$company_email = (string) mlsimport_standalone_option( 'lead_recipient', '' );
		if ( is_email( $company_email ) ) {
			$headers[] = 'Reply-To: ' . self::sender_name() . ' <' . $company_email . '>';
		}
		return $headers;
	}

	/**
	 * The From name the Recipient sees: the company name, else the site name —
	 * never WordPress's default "WordPress".
	 *
	 * @return string
	 */
	private static function sender_name(): string {
		$company = trim( (string) mlsimport_standalone_option( 'company_name', '' ) );
		return '' !== $company ? $company : self::site_name();
	}

	/**
	 * wp_mail() with our From name, scoped to this one send so no other plugin's
	 * mail is renamed.
	 *
	 * @param string   $to      Recipient.
	 * @param string   $subject Subject.
	 * @param string   $body    HTML body.
	 * @param string[] $headers Headers.
	 * @return bool
	 */
	private static function send( string $to, string $subject, string $body, array $headers ): bool {
		$name = static function () {
			return self::sender_name();
		};
		add_filter( 'wp_mail_from_name', $name );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_filter( 'wp_mail_from_name', $name );
		return (bool) $sent;
	}

	/**
	 * Render one email template to a string. $vars become local variables of the
	 * template (a fixed, plugin-built array — never request input).
	 *
	 * @param string $template Template name under templates/.
	 * @param array  $vars     Variables the template reads.
	 * @return string
	 */
	private static function render( string $template, array $vars ): string {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- keys are fixed by the callers above.
		extract( $vars, EXTR_SKIP );
		ob_start();
		include Mlsimport_Standalone_Template::locate( $template );
		return (string) ob_get_clean();
	}

	/**
	 * The site name as plain text (get_bloginfo HTML-encodes it).
	 *
	 * @return string
	 */
	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
