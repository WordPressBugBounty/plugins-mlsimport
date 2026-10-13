<?php
/**
 * Email: the daily listing-changes digest of one MLS connection.
 *
 * Sent by Mlsimport_Listing_Notification_Digest to the addresses saved in the
 * "Notifications" section of the Alerts page. Two parts, in this order:
 *   1. "Buyers to contact": per buyer, the new or changed listings that match
 *      their saved search;
 *   2. the listing changes of the day.
 * Both use the same table, one row per change: photo, date, what happened,
 * the listing (linked while it still exists on the site), the MLS number and
 * the change in words. Replace this file through the
 * `mlsimport_listing_notification_template` filter.
 *
 * Variables:
 *   $mls_name     string MLS connection name.
 *   $summary      string Counts per kind in words ("10 price drops and 3 ...").
 *   $period_start string Date the first listed change was recorded.
 *   $period_end   string Today's date.
 *   $shown    array[] Event rows to list (already capped), oldest first.
 *   $count    int     All matching changes of the day.
 *   $more     int     Matching changes that are not listed ($count - shown).
 *   $page_url string  wp-admin Listing Changes page.
 *   $buyers    array[] Buyers to contact: { name, email, url, post_ids, rows }
 *                      (`rows`: one event row per matching listing).
 *   $buyer_cap int     Most listings shown per buyer.
 *
 * With no listing change to show ($count is 0) the mail carries only the
 * "Buyers to contact" section.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mli_labels = Mlsimport_Listing_Notification_Matcher::kind_labels();
$mli_cell   = 'padding:8px 10px;border-bottom:1px solid #e7e5e4;text-align:left;vertical-align:top;';

/**
 * Print the table of event rows (shared by both parts of the mail).
 *
 * @param array $rows Event rows.
 */
$mli_table = static function ( array $rows ) use ( $mli_labels, $mli_cell ): void {
	?>
	<table style="width:100%;border-collapse:collapse;font-size:13px;">
		<tr>
			<th style="<?php echo esc_attr( $mli_cell ); ?>"></th>
			<th style="<?php echo esc_attr( $mli_cell ); ?>"><?php esc_html_e( 'Date', 'mlsimport' ); ?></th>
			<th style="<?php echo esc_attr( $mli_cell ); ?>"><?php esc_html_e( 'What happened', 'mlsimport' ); ?></th>
			<th style="<?php echo esc_attr( $mli_cell ); ?>"><?php esc_html_e( 'Listing', 'mlsimport' ); ?></th>
			<th style="<?php echo esc_attr( $mli_cell ); ?>"><?php esc_html_e( 'MLS #', 'mlsimport' ); ?></th>
			<th style="<?php echo esc_attr( $mli_cell ); ?>"><?php esc_html_e( 'Change', 'mlsimport' ); ?></th>
		</tr>
		<?php foreach ( $rows as $mli_row ) : ?>
			<?php
			// The address captured with the event; the listing key when there is none.
			$mli_facts   = json_decode( (string) ( $mli_row['facts'] ?? '' ), true );
			$mli_address = is_array( $mli_facts ) && '' !== (string) ( $mli_facts['address'] ?? '' ) ? (string) $mli_facts['address'] : (string) $mli_row['listing_key'];
			$mli_city    = is_array( $mli_facts ) ? (string) ( $mli_facts['city'] ?? '' ) : '';
			// Link only while the listing still exists on the site.
			$mli_post = (int) $mli_row['post_id'];
			$mli_link = $mli_post > 0 && get_post_status( $mli_post ) ? get_permalink( $mli_post ) : '';
			$mli_kind = Mlsimport_Listing_Notification_Matcher::kind( $mli_row );
			// Thumbnail: the photo captured with the event, else the featured
			// image of the listing while it still exists, else an empty box.
			$mli_photo = is_array( $mli_facts ) ? (string) ( $mli_facts['photo'] ?? '' ) : '';
			if ( '' === $mli_photo && $mli_link ) {
				$mli_photo = (string) get_the_post_thumbnail_url( $mli_post, 'medium' );
			}
			?>
			<tr>
				<td style="<?php echo esc_attr( $mli_cell ); ?>width:100px;">
					<?php if ( '' !== $mli_photo ) : ?>
						<img src="<?php echo esc_url( $mli_photo ); ?>" width="100" height="75" alt="" style="display:block;width:100px;height:75px;object-fit:cover;border-radius:3px;">
					<?php else : ?>
						<div style="width:100px;height:75px;border-radius:3px;background:#f3efe7;"></div>
					<?php endif; ?>
				</td>
				<td style="<?php echo esc_attr( $mli_cell ); ?>white-space:nowrap;"><?php echo esc_html( get_date_from_gmt( (string) $mli_row['event_at'], 'M j' ) ); ?></td>
				<td style="<?php echo esc_attr( $mli_cell ); ?>"><strong><?php echo esc_html( $mli_labels[ $mli_kind ] ?? $mli_kind ); ?></strong></td>
				<td style="<?php echo esc_attr( $mli_cell ); ?>">
					<?php if ( $mli_link ) : ?>
						<a href="<?php echo esc_url( $mli_link ); ?>" style="color:#e85d3c;"><?php echo esc_html( $mli_address ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $mli_address ); ?>
					<?php endif; ?>
					<?php if ( '' !== $mli_city ) : ?>
						<br><span style="color:#78716c;"><?php echo esc_html( $mli_city ); ?></span>
					<?php endif; ?>
				</td>
				<td style="<?php echo esc_attr( $mli_cell ); ?>"><?php echo esc_html( (string) $mli_row['listing_id'] ); ?></td>
				<td style="<?php echo esc_attr( $mli_cell ); ?>"><?php echo esc_html( Mlsimport_Listing_Notification_Digest::describe( $mli_row ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
	<?php
};
?>
<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1c1917;max-width:720px;margin:0 auto;padding:24px;">
	<h2 style="font-size:18px;margin:0 0 4px;"><?php echo esc_html( $mls_name ); ?></h2>
	<?php if ( ! empty( $buyers ) ) : ?>
		<h3 style="font-size:16px;margin:16px 0 6px;"><?php esc_html_e( 'Buyers to contact', 'mlsimport' ); ?></h3>
		<p style="margin:0 0 12px;color:#44403c;"><?php esc_html_e( 'These buyers have a saved search that matches listings that are new or were just changed. You may want to contact them.', 'mlsimport' ); ?></p>
		<?php foreach ( $buyers as $mli_buyer ) : ?>
			<?php
			$mli_total = count( $mli_buyer['post_ids'] );
			$mli_name  = '' !== $mli_buyer['name'] ? $mli_buyer['name'] : __( 'A visitor', 'mlsimport' );
			?>
			<div style="margin:0 0 14px;padding:12px 14px;border:1px solid #e7e5e4;border-radius:8px;">
				<p style="margin:0 0 6px;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: buyer name, 2: number of listings. */
							_n( 'For the buyer %1$s there is %2$d new or changed listing they may be interested in.', 'For the buyer %1$s there are %2$d new or changed listings they may be interested in.', $mli_total, 'mlsimport' ),
							$mli_name,
							$mli_total
						)
					);
					?>
					<?php if ( '' !== $mli_buyer['email'] ) : ?>
						<br><span style="color:#57534e;"><?php esc_html_e( 'Contact:', 'mlsimport' ); ?> <?php echo esc_html( $mli_buyer['email'] ); ?></span>
					<?php endif; ?>
				</p>
				<?php $mli_table( array_slice( (array) $mli_buyer['rows'], 0, $buyer_cap ) ); ?>
				<?php if ( $mli_total > $buyer_cap ) : ?>
					<p style="margin:6px 0 0;color:#78716c;">
						<?php
						/* translators: %d: number of listings not shown. */
						echo esc_html( sprintf( _n( 'And %d more listing.', 'And %d more listings.', $mli_total - $buyer_cap, 'mlsimport' ), $mli_total - $buyer_cap ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( '' !== $mli_buyer['url'] ) : ?>
					<p style="margin:6px 0 0;"><a href="<?php echo esc_url( $mli_buyer['url'] ); ?>" style="color:#e85d3c;"><?php esc_html_e( 'Open this buyer\'s search', 'mlsimport' ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php if ( $count > 0 ) : ?>
	<p style="margin:0 0 8px;color:#44403c;">
		<?php
		if ( $period_start === $period_end ) {
			/* translators: 1: date, 2: counted changes, e.g. "10 price drops and 3 status changes". */
			echo esc_html( sprintf( __( 'On %1$s there were %2$s. You can review them below.', 'mlsimport' ), $period_end, $summary ) );
		} else {
			/* translators: 1: start date, 2: end date, 3: counted changes, e.g. "10 price drops and 3 status changes". */
			echo esc_html( sprintf( __( 'Between %1$s and %2$s there were %3$s. You can review them below.', 'mlsimport' ), $period_start, $period_end, $summary ) );
		}
		?>
	</p>
	<?php endif; ?>
	<p style="margin:0 0 16px;color:#78716c;font-size:12px;">
		<?php
		echo wp_kses(
			sprintf(
				/* translators: %s: link to the notification settings. */
				__( 'If you no longer want these emails, open %s and switch this MLS off or remove your address.', 'mlsimport' ),
				'<a href="' . esc_url( $page_url . '#mlsimport-notify' ) . '" style="color:#e85d3c;">' . esc_html__( 'Alerts, Notifications', 'mlsimport' ) . '</a>'
			),
			array( 'a' => array( 'href' => array(), 'style' => array() ) )
		);
		?>
	</p>
	<?php if ( $count > 0 ) : ?>
	<?php $mli_table( $shown ); ?>
	<?php if ( $more > 0 ) : ?>
		<p style="margin:16px 0 0;">
			<?php
			/* translators: %d: number of changes not listed in the email. */
			echo esc_html( sprintf( _n( 'And %d more change.', 'And %d more changes.', $more, 'mlsimport' ), $more ) );
			?>
		</p>
	<?php endif; ?>
	<?php endif; ?>

	<p style="margin:16px 0 0;"><a href="<?php echo esc_url( $page_url ); ?>" style="color:#e85d3c;"><?php esc_html_e( 'See all listing changes', 'mlsimport' ); ?></a></p>
	<p style="margin:16px 0 0;color:#78716c;font-size:12px;"><?php esc_html_e( 'You receive this email because your address is saved under Alerts, Notifications, in the MLSImport plugin.', 'mlsimport' ); ?></p>
</div>
