<?php
/**
 * Standalone listing card — v1 (default). Theme-overridable: copy to your theme's
 * mlsimport/card.php to restyle. Receives:
 *   $post WP_Post       The listing post.
 *   $row  object|null   The mlsimport_listings row (searchable scalars).
 *
 * Compact, price-forward card. Data comes from mlsimport_card_view(); the card
 * action slots are the in-card extension points (see includes/standalone/listing-card.php).
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Build the flattened card view model (id, permalink, thumb, status, price_fmt, specs, office, logo, has_thumb).
$mli = mlsimport_card_view( $post, $row );
?>
<article class="mlsimport-listing-card mlsimport-listing-card--v1" data-id="<?php echo esc_attr( (string) $mli['id'] ); ?>">
	<?php
	// Extension slot: fires before the card link.
	do_action( 'mlsimport_card_before', $post, $row ); ?>
	<a class="mlsimport-listing-card__link" href="<?php echo esc_url( $mli['permalink'] ); ?>">
		<?php
		// Media block renders only when a thumbnail is available.
		if ( $mli['has_thumb'] ) : ?>
			<div class="mlsimport-listing-card__media" role="img" aria-label="<?php echo esc_attr( $mli['address'] ); ?>" style="background-image:url('<?php echo esc_url( $mli['thumb'] ); ?>')">
				<?php
				// Badge row over the media: status (e.g. Active/Sold) when set, then any
				// hooked badges (the plugin's own "Featured" flag) inline after it.
				?>
				<span class="mlsimport-listing-card__badges">
					<?php if ( '' !== $mli['status'] ) : ?>
						<span class="mlsimport-listing-card__badge"><?php echo esc_html( $mli['status'] ); ?></span>
					<?php endif; ?>
					<?php do_action( 'mlsimport_card_badges', $post, $row ); ?>
				</span>
			</div>
		<?php endif; ?>
		<div class="mlsimport-listing-card__body">
			<?php
			// Extension slot: top of the card body.
			do_action( 'mlsimport_card_body_start', $post, $row ); ?>
			<?php
			// Price first (v1 is price-forward), only when a formatted price exists.
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
			// Agency row shows when either the office name or the MLS logo is set.
			if ( '' !== $mli['office'] || '' !== $mli['logo'] ) : ?>
				<div class="mlsimport-listing-card__agency">
					<span class="mlsimport-listing-card__office"><?php echo esc_html( $mli['office'] ); ?></span>
					<?php
					// MLS/source logo, only when set.
					if ( '' !== $mli['logo'] ) : ?>
						<img class="mlsimport-listing-card__mls" src="<?php echo esc_url( $mli['logo'] ); ?>" alt="<?php esc_attr_e( 'MLS', 'mlsimport' ); ?>" loading="lazy" />
					<?php endif; ?>
				</div>
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
