<?php
/**
 * Email: the daily Saved Search alert — today's new and updated listings.
 *
 * Table-based HTML with inline styles only (mail clients strip <style> and
 * ignore flex/grid). Override by copying this file to
 * yourtheme/mlsimport/emails/saved-search-alert.php.
 *
 * Layout, top to bottom: logo or company name -> intro line -> the saved
 * criteria -> listing cards -> "See all listings" button -> footer (company
 * details, MLS attribution, unsubscribe link).
 *
 * Variables:
 *   $search          array    The Saved Search (name, email, results_url, ...).
 *   $intro           string   The intro line, {name}/{site_name} already filled.
 *   $criteria        string[] Readable "Label: value" lines.
 *   $data            array    posts (WP_Post[]), rows (by post id), total (int).
 *   $unsubscribe_url string   Tokenized unsubscribe link.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Branding: button colour from "Main Color"; header shows the site logo when the
// theme has one, else the company name, else the site name.
$mli_brand   = sanitize_hex_color( (string) mlsimport_standalone_option( 'brand_color', '' ) );
$mli_brand   = $mli_brand ? $mli_brand : '#2563eb';
$mli_company = (string) mlsimport_standalone_option( 'company_name', '' );
$mli_company = '' !== $mli_company ? $mli_company : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
$mli_phone   = (string) mlsimport_standalone_option( 'company_phone', '' );
$mli_logo_id = (int) get_theme_mod( 'custom_logo' );
$mli_logo    = $mli_logo_id ? (string) wp_get_attachment_image_url( $mli_logo_id, 'medium' ) : '';
$mli_mls     = mlsimport_standalone_mls_logo_url();
$mli_more    = (int) $data['total'] - count( $data['posts'] );
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;">

	<tr><td style="padding:24px 24px 8px;">
		<?php if ( '' !== $mli_logo ) : ?>
			<img src="<?php echo esc_url( $mli_logo ); ?>" alt="<?php echo esc_attr( $mli_company ); ?>" style="max-height:48px;max-width:240px;border:0;">
		<?php else : ?>
			<span style="font-size:20px;font-weight:bold;"><?php echo esc_html( $mli_company ); ?></span>
		<?php endif; ?>
	</td></tr>

	<tr><td style="padding:8px 24px;font-size:16px;line-height:1.5;"><?php echo esc_html( $intro ); ?></td></tr>

	<tr><td style="padding:0 24px 16px;font-size:13px;line-height:1.5;color:#6b7280;"><?php echo esc_html( implode( ' · ', $criteria ) ); ?></td></tr>

	<?php
	foreach ( $data['posts'] as $mli_post ) :
		// The same view model the site's listing cards use, so price/specs/photo
		// read exactly as they do on the results page.
		$mli_row = isset( $data['rows'][ $mli_post->ID ] ) ? $data['rows'][ $mli_post->ID ] : null;
		$mli     = mlsimport_card_view( $mli_post, $mli_row );
		?>
		<tr><td style="padding:0 24px 16px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;">
				<?php if ( '' !== $mli['thumb'] ) : ?>
					<tr><td><a href="<?php echo esc_url( $mli['permalink'] ); ?>"><img src="<?php echo esc_url( $mli['thumb'] ); ?>" alt="<?php echo esc_attr( $mli['address'] ); ?>" width="550" style="width:100%;height:auto;display:block;border:0;border-radius:8px 8px 0 0;"></a></td></tr>
				<?php endif; ?>
				<tr><td style="padding:12px 16px;">
					<?php if ( '' !== $mli['price_fmt'] ) : ?>
						<div style="font-size:20px;font-weight:bold;"><?php echo esc_html( $mli['price_fmt'] ); ?></div>
					<?php endif; ?>
					<div style="font-size:15px;padding:4px 0;"><a href="<?php echo esc_url( $mli['permalink'] ); ?>" style="color:#1f2937;text-decoration:none;"><?php echo esc_html( $mli['address'] ); ?></a></div>
					<div style="font-size:13px;color:#6b7280;">
						<?php echo esc_html( implode( ' · ', array_filter( array_merge( $mli['specs'], array( $mli['status'] ) ) ) ) ); ?>
					</div>
					<?php
					// Per-listing attribution belongs on the card: each listing can come
					// from a different office. Same wording as the property page (#169).
					if ( '' !== $mli['office'] ) :
						?>
						<div style="font-size:12px;color:#9ca3af;padding-top:6px;">
							<?php
							/* translators: %s: listing office name. */
							echo esc_html( sprintf( __( 'Listing courtesy of %s', 'mlsimport' ), $mli['office'] ) );
							?>
						</div>
					<?php endif; ?>
				</td></tr>
			</table>
		</td></tr>
	<?php endforeach; ?>

	<tr><td align="center" style="padding:8px 24px 24px;">
		<?php if ( $mli_more > 0 ) : ?>
			<div style="font-size:13px;color:#6b7280;padding-bottom:12px;">
				<?php
				/* translators: %d: number of further matching listings not shown in the email. */
				echo esc_html( sprintf( _n( '+ %d more listing matches your search today.', '+ %d more listings match your search today.', $mli_more, 'mlsimport' ), $mli_more ) );
				?>
			</div>
		<?php endif; ?>
		<a href="<?php echo esc_url( $search['results_url'] ); ?>" style="background:<?php echo esc_attr( $mli_brand ); ?>;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;display:inline-block;font-size:15px;"><?php esc_html_e( 'See all listings for this search', 'mlsimport' ); ?></a>
	</td></tr>

	<tr><td style="padding:16px 24px 24px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#6b7280;">
		<div><?php echo esc_html( trim( $mli_company . ( '' !== $mli_phone ? ' · ' . $mli_phone : '' ) ) ); ?></div>
		<?php if ( '' !== $mli_mls ) : ?>
			<div style="padding-top:8px;"><img src="<?php echo esc_url( $mli_mls ); ?>" alt="" style="max-height:32px;border:0;"></div>
		<?php endif; ?>
		<?php
		// The email's own disclaimer (Saved Search tab), not the property page's:
		// that one is written per listing, and an email lists many — the office is
		// on each card above instead. Only site-wide tokens fill here.
		$mli_disclaimer = strtr(
			(string) mlsimport_standalone_option( 'saved_search_email_disclaimer', '' ),
			array(
				'{year}'      => date_i18n( 'Y' ),
				'{site_name}' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);
		if ( '' !== trim( $mli_disclaimer ) ) {
			echo wp_kses_post( wpautop( $mli_disclaimer ) );
		}
		?>
		<div style="padding-top:8px;">
			<?php esc_html_e( 'You receive this email because you saved a search on our site.', 'mlsimport' ); ?>
			<a href="<?php echo esc_url( $unsubscribe_url ); ?>" style="color:#6b7280;"><?php esc_html_e( 'Unsubscribe', 'mlsimport' ); ?></a>
		</div>
	</td></tr>

</table>
</td></tr>
</table>
