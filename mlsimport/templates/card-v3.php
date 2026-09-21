<?php
/**
 * Standalone listing card — v3 (image with overlaid price/address). Theme-
 * overridable: copy to your theme's mlsimport/card-v3.php. Receives:
 *   $post WP_Post       The listing post.
 *   $row  object|null   The mlsimport_listings row (searchable scalars).
 *
 * Same data + action slots as v1 (mlsimport_card_view); a full-bleed overlay layout.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build the flattened card view model (same shape as v1); v3 overlays text on a full-bleed image.
$mli = mlsimport_card_view( $post, $row );
?>
<article class="mlsimport-listing-card mlsimport-listing-card--v3" data-id="<?php echo esc_attr( (string) $mli['id'] ); ?>">
	<?php
	// Extension slot: fires before the card link.
	do_action( 'mlsimport_card_before', $post, $row ); ?>
	<a class="mlsimport-listing-card__link" href="<?php echo esc_url( $mli['permalink'] ); ?>">
		<div class="mlsimport-listing-card__media"<?php echo /* inline: attach the background image + a11y attrs only when a thumbnail exists, else nothing */ $mli['has_thumb'] ? ' role="img" aria-label="' . esc_attr( $mli['address'] ) . '" style="background-image:url(\'' . esc_url( $mli['thumb'] ) . '\')"' : ''; ?>>
			<?php
			// Badge row over the media: status when set, then any hooked badges
			// (the plugin's own "Featured" flag) inline after it.
			?>
			<span class="mlsimport-listing-card__badges">
				<?php if ( '' !== $mli['status'] ) : ?>
					<span class="mlsimport-listing-card__badge"><?php echo esc_html( $mli['status'] ); ?></span>
				<?php endif; ?>
				<?php do_action( 'mlsimport_card_badges', $post, $row ); ?>
			</span>
			<div class="mlsimport-listing-card__overlay">
				<?php
				// Extension slot: top of the overlaid body.
				do_action( 'mlsimport_card_body_start', $post, $row ); ?>
				<?php
				// Price at the top of the overlay, only when a formatted price exists.
				if ( '' !== $mli['price_fmt'] ) : ?>
					<span class="mlsimport-listing-card__price"><?php echo esc_html( $mli['price_fmt'] ); ?></span>
				<?php endif; ?>
				<h3 class="mlsimport-listing-card__title"><?php echo esc_html( $mli['address'] ); ?></h3>
				<?php
				// Specs line (beds/baths/area etc.), space-dot joined, only when present.
				if ( ! empty( $mli['specs'] ) ) : ?>
					<p class="mlsimport-listing-card__specs"><?php echo esc_html( implode( ' · ', $mli['specs'] ) ); ?></p>
				<?php endif; ?>
				<?php
				// Extension slot: bottom of the overlaid body.
				do_action( 'mlsimport_card_body_end', $post, $row ); ?>
			</div>
		</div>
	</a>
	<?php
	// Extension slot: after the media/link (outside the anchor).
	do_action( 'mlsimport_card_after_media', $post, $row ); ?>
	<?php
	// Extension slot: after the whole card.
	do_action( 'mlsimport_card_after', $post, $row ); ?>
</article>
