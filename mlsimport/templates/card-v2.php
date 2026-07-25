<?php
/**
 * Standalone listing card — v2 (horizontal split: photo left, details right).
 * Theme-overridable: copy to your theme's mlsimport/card-v2.php. Receives:
 *   $post WP_Post       The listing post.
 *   $row  object|null   The mlsimport_listings row (searchable scalars).
 *
 * Same data + action slots as v1 (mlsimport_card_view); a different layout.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build the flattened card view model (same shape as v1); only the layout differs here.
$mli = mlsimport_card_view( $post, $row );
?>
<article class="mlsimport-listing-card mlsimport-listing-card--v2" data-id="<?php echo esc_attr( (string) $mli['id'] ); ?>">
	<?php
	// Extension slot: fires before the card link.
	do_action( 'mlsimport_card_before', $post, $row ); ?>
	<a class="mlsimport-listing-card__link" href="<?php echo esc_url( $mli['permalink'] ); ?>">
		<?php
		// Photo-left half of the split: renders only when a thumbnail is available.
		if ( $mli['has_thumb'] ) : ?>
			<div class="mlsimport-listing-card__media" role="img" aria-label="<?php echo esc_attr( $mli['address'] ); ?>" style="background-image:url('<?php echo esc_url( $mli['thumb'] ); ?>')">
				<?php
				// Status badge overlaid on the media, only when set.
				if ( '' !== $mli['status'] ) : ?>
					<span class="mlsimport-listing-card__badge"><?php echo esc_html( $mli['status'] ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="mlsimport-listing-card__body">
			<?php
			// Extension slot: top of the card body.
			do_action( 'mlsimport_card_body_start', $post, $row ); ?>
			<?php
			// Price leads the v2 body, only when a formatted price exists.
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
			// Agency row (office name only in v2), only when the office is set.
			if ( '' !== $mli['office'] ) : ?>
				<div class="mlsimport-listing-card__agency"><span class="mlsimport-listing-card__office"><?php echo esc_html( $mli['office'] ); ?></span></div>
			<?php endif; ?>
			<?php
			// Extension slot: bottom of the card body.
			do_action( 'mlsimport_card_body_end', $post, $row ); ?>
		</div>
	</a>
	<?php
	// Extension slot: after the media/link (outside the anchor).
	do_action( 'mlsimport_card_after_media', $post, $row ); ?>
	<?php
	// Extension slot: after the whole card.
	do_action( 'mlsimport_card_after', $post, $row ); ?>
</article>
