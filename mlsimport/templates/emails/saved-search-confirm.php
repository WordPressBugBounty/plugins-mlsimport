<?php
/**
 * Email: confirm a Saved Search (double opt-in).
 *
 * Sent to the Recipient right after a search is saved. Nothing else is mailed
 * until the button is clicked. Override by copying this file to
 * yourtheme/mlsimport/emails/saved-search-confirm.php.
 *
 * Variables:
 *   $search      array    The Saved Search (name, email, ...).
 *   $confirm_url string   The tokenized confirmation link.
 *   $criteria    string[] Readable "Label: value" lines of the saved criteria.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Button colour follows the site's Main Color setting, with a neutral fallback.
$mli_brand = sanitize_hex_color( (string) mlsimport_standalone_option( 'brand_color', '' ) );
$mli_brand = $mli_brand ? $mli_brand : '#2563eb';
?>
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1f2937;max-width:600px;margin:0 auto;padding:24px;">
	<p>
		<?php
		/* translators: %s: recipient name. */
		echo esc_html( sprintf( __( 'Hi %s,', 'mlsimport' ), $search['name'] ) );
		?>
	</p>
	<p><?php esc_html_e( 'Please confirm that you want to receive a daily email with new and updated listings for this search:', 'mlsimport' ); ?></p>
	<ul>
		<?php foreach ( $criteria as $mli_line ) : ?>
			<li><?php echo esc_html( $mli_line ); ?></li>
		<?php endforeach; ?>
	</ul>
	<p style="margin:24px 0;">
		<a href="<?php echo esc_url( $confirm_url ); ?>" style="background:<?php echo esc_attr( $mli_brand ); ?>;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;display:inline-block;"><?php esc_html_e( 'Confirm my saved search', 'mlsimport' ); ?></a>
	</p>
	<p style="font-size:13px;color:#6b7280;"><?php esc_html_e( 'If you did not ask for this, ignore this email — nothing will be sent to you.', 'mlsimport' ); ?></p>
</div>
