<?php
/**
 * Friendly wording for upstream MLS query errors.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The SaaS forwards the MLS's own OData error text verbatim, prefixed with
 * "MLS API error:". When a customer sets one of the two Internet display
 * filters (Internet Entire Listing Display / Internet Address display) to
 * "yes" on an Import Task, AWS adds an `InternetEntireListingDisplayYN eq true`
 * (or `InternetAddressDisplayYN eq true`) filter to the RESO query. Not every
 * MLS defines those properties, and the MLS then answers with text such as:
 *
 *   The property 'InternetEntireListingDisplayYN', used in a query
 *   expression, is not defined in type 'ODataService.Property'.
 *
 * That sentence tells the customer nothing about what to change. The only
 * thing they can do is set the corresponding Import Task option back to "no",
 * so the message shown on the task screen, in the hourly count and in the
 * Import Run log must say exactly that.
 *
 * The single rule: a raw MLS error message that names one of those two
 * fields is replaced by a sentence naming the Import Task option to switch
 * to "no". Any other message passes through unchanged. On the Import Task
 * screen the same lookup also yields the id of the option's dropdown, so the
 * warning can carry an anchor link that scrolls straight to it.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find the Internet display field an MLS error message is about.
 *
 * Step by step:
 * 1. Walk the two Internet display RESO fields together with the label of the
 *    Import Task option that adds each one to the query and the id of that
 *    option's dropdown on the Import Task screen (the id the form builder
 *    derives: strtolower( 'mlsimport_item_' . RESO key )).
 * 2. Match the message case-insensitively on the field name WITHOUT its
 *    "Internet" prefix, so RMLS's `InternetAddressDisplayYn` spelling and a
 *    message that lost its prefix (seen in production) both match.
 * 3. Return that field's description, or an empty array when the message is
 *    about something else.
 *
 * @param string $message Raw or already rewritten error message.
 * @return array{field:string,label:string,anchor:string}|array Empty when no match.
 */
function mlsimport_mls_error_display_field( string $message ): array {
	$display_fields = array(
		'EntireListingDisplayYN' => array(
			'field'  => 'InternetEntireListingDisplayYN',
			'label'  => esc_html__( 'Internet Entire Listing Display', 'mlsimport' ),
			'anchor' => 'mlsimport_item_internetentirelistingdisplayyn',
		),
		'AddressDisplayYN'       => array(
			'field'  => 'InternetAddressDisplayYN',
			'label'  => esc_html__( 'Internet Address display', 'mlsimport' ),
			'anchor' => 'mlsimport_item_internetaddressdisplayyn',
		),
	);

	foreach ( $display_fields as $needle => $display_field ) {
		if ( false !== stripos( $message, $needle ) ) {
			return $display_field;
		}
	}

	return array();
}

/**
 * Rewrite an MLS query error that names an unsupported Internet display field.
 *
 * Step by step:
 * 1. Look the message up with mlsimport_mls_error_display_field().
 * 2. No match: return the message untouched — this helper never hides an
 *    error it does not understand.
 * 3. Match: return the sentence naming the field and the Import Task option
 *    to set to "no". Plain text, safe for the Import Run log and telemetry.
 *
 * @param string $message Raw error message as returned by the SaaS/MLS.
 * @return string The friendly sentence, or the original message.
 */
function mlsimport_friendly_mls_error( string $message ): string {
	$display_field = mlsimport_mls_error_display_field( $message );
	if ( array() === $display_field ) {
		return $message;
	}

	return sprintf(
		/* translators: 1: RESO field name, 2: Import Task option label. */
		esc_html__( '%1$s is not supported by your MLS. Please set the "%2$s" option of this Import Task to "no".', 'mlsimport' ),
		$display_field['field'],
		$display_field['label']
	);
}

/**
 * Anchor link that scrolls the Import Task screen to the option's dropdown.
 *
 * Step by step:
 * 1. Look the (already rewritten) warning message up; the friendly sentence
 *    still names the field, so the same lookup applies.
 * 2. No match: return '' so ordinary warnings render unchanged.
 * 3. Match: return an escaped `<a href="#dropdown-id">` the task screen
 *    appends to the warning. The browser's native hash jump does the scroll;
 *    the select's `scroll-margin-top` keeps it clear of the admin bar.
 *
 * @param string $message The warning message shown on the Import Task screen.
 * @return string Anchor HTML, or '' when the message is not about these fields.
 */
function mlsimport_friendly_mls_error_anchor( string $message ): string {
	$display_field = mlsimport_mls_error_display_field( $message );
	if ( array() === $display_field ) {
		return '';
	}

	return sprintf(
		' <a class="mlsimport-warning-jump" href="#%1$s">%2$s</a>',
		esc_attr( $display_field['anchor'] ),
		/* translators: %s: Import Task option label. */
		esc_html( sprintf( __( 'Go to the "%s" option', 'mlsimport' ), $display_field['label'] ) )
	);
}
