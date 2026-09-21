<?php
/**
 * Email: tell the site owner a Recipient confirmed a Saved Search.
 *
 * Sent once, on confirmation (never for unconfirmed saves). Override by copying
 * this file to yourtheme/mlsimport/emails/saved-search-owner.php.
 *
 * Variables:
 *   $search   array    The Saved Search (name, email, results_url, ...).
 *   $criteria string[] Readable "Label: value" lines of the saved criteria.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1f2937;max-width:600px;margin:0 auto;padding:24px;">
	<p><?php esc_html_e( 'A visitor confirmed a saved search and will now receive daily listing emails.', 'mlsimport' ); ?></p>
	<p>
		<strong><?php esc_html_e( 'Name:', 'mlsimport' ); ?></strong> <?php echo esc_html( $search['name'] ); ?><br>
		<strong><?php esc_html_e( 'Email:', 'mlsimport' ); ?></strong> <?php echo esc_html( $search['email'] ); ?>
	</p>
	<ul>
		<?php foreach ( $criteria as $mli_line ) : ?>
			<li><?php echo esc_html( $mli_line ); ?></li>
		<?php endforeach; ?>
	</ul>
	<p><a href="<?php echo esc_url( $search['results_url'] ); ?>"><?php esc_html_e( 'See the listings for this search', 'mlsimport' ); ?></a></p>
</div>
