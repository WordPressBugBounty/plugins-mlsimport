<?php
/**
 * Admin partial: "Listing Changes" screen (issue #222).
 *
 * Renders the Listing Change Events (new listings, price / status changes,
 * departures, sales, back-on-market, agent and content changes reported by the
 * MLS, last 24 months) through the Mlsimport_Listing_Events_List_Table, with
 * event-type + import-task filters and a search on listing key / MLS # read
 * from the query string. Above the table sits the collapsible "Notifications"
 * section (mlsimport-listing-notifications.php): the daily email setup.
 * Administrator-only. Modelled on mlsimport-history.php, which is untouched.
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

// Block direct access outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Restrict this screen to administrators.
if ( ! current_user_can( 'administrator' ) ) {
	wp_die( esc_html__( 'You do not have permission to view this page.', 'mlsimport' ) );
}

// Load WP_List_Table (admin-only) and the events list table.
require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-mlsimport-listing-events-list-table.php';

// Filter GET keys — MUST match Mlsimport_Listing_Events_List_Table::prepare_items().
$filter_event  = isset( $_GET['mlsimport_event'] ) ? sanitize_key( wp_unslash( $_GET['mlsimport_event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$filter_item   = isset( $_GET['mlsimport_item'] ) ? absint( $_GET['mlsimport_item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$filter_search = isset( $_GET['mlsimport_s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['mlsimport_s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Build and populate the table (applies the filters read above).
$list_table = new Mlsimport_Listing_Events_List_Table();
$list_table->prepare_items();
$import_task_options = $list_table->get_import_task_options();
$event_type_labels   = Mlsimport_Listing_Events::type_labels();
?>
<div class="wrap mlsimport-listing-changes">
	<h1><?php echo esc_html__( 'Alerts', 'mlsimport' ); ?></h1>
	<p><?php echo esc_html__( 'Listing changes reported by the MLS for the last 24 months.', 'mlsimport' ); ?></p>

	<?php
	// Email notifications setup (handles its own form posts).
	require __DIR__ . '/mlsimport-listing-notifications.php';
	?>

	<form method="get" action="" class="notice notice-info mlsimport-history-filters">
		<input type="hidden" name="page" value="mlsimport_listing_changes">

		<label for="mlsimport-search"><?php echo esc_html__( 'Search:', 'mlsimport' ); ?></label>
		<input type="search" id="mlsimport-search" name="mlsimport_s" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php echo esc_attr__( 'MLS # or ListingKey', 'mlsimport' ); ?>">

		<label for="mlsimport-event-filter"><?php echo esc_html__( 'Event:', 'mlsimport' ); ?></label>
		<select id="mlsimport-event-filter" name="mlsimport_event">
			<option value=""><?php echo esc_html__( 'All', 'mlsimport' ); ?></option>
			<?php foreach ( $event_type_labels as $event_type => $event_label ) : ?>
				<option value="<?php echo esc_attr( $event_type ); ?>" <?php selected( $filter_event, $event_type ); ?>><?php echo esc_html( $event_label ); ?></option>
			<?php endforeach; ?>
		</select>

		<?php if ( ! empty( $import_task_options ) ) : ?>
		<label for="mlsimport-item-filter"><?php echo esc_html__( 'Import Task:', 'mlsimport' ); ?></label>
		<select id="mlsimport-item-filter" name="mlsimport_item">
			<option value=""><?php echo esc_html__( 'All', 'mlsimport' ); ?></option>
			<?php foreach ( $import_task_options as $task_id => $task_title ) : ?>
				<option value="<?php echo esc_attr( (string) $task_id ); ?>" <?php selected( $filter_item, $task_id ); ?>><?php echo esc_html( $task_title ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php endif; ?>

		<?php submit_button( __( 'Filter', 'mlsimport' ), 'secondary', 'submit', false ); ?>
	</form>

	<?php $list_table->display(); ?>
</div>
<script>
// A click anywhere on a row opens its listing (the row's data-href). Links
// inside the row (listing title, import task) keep their own target.
document.querySelector( '.mlsimport-listing-changes .wp-list-table' ).addEventListener( 'click', function ( event ) {
	var row = event.target.closest( 'tr[data-href]' );
	if ( row && ! event.target.closest( 'a' ) ) {
		window.location.href = row.dataset.href;
	}
} );
</script>
