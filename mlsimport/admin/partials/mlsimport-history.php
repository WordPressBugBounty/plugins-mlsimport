<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'administrator' ) ) {
	wp_die( esc_html__( 'You do not have permission to view this page.', 'mlsimport' ) );
}

// Load WP_List_Table base class (admin-only; must not be loaded in mlsimport.php).
require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
// __FILE__ = mlsimport/admin/partials/mlsimport-history.php — dirname x3 = plugin root.
require_once dirname( __FILE__, 3 ) . '/includes/class-mlsimport-activity-list-table.php';

// Filter GET keys — MUST match the keys read in Mlsimport_Activity_List_Table::prepare_items().
// 'mlsimport_action' = action filter; 'mlsimport_item' = import-task filter.
$filter_action = isset( $_GET['mlsimport_action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	? sanitize_text_field( wp_unslash( $_GET['mlsimport_action'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: '';

$filter_item = isset( $_GET['mlsimport_item'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	? absint( $_GET['mlsimport_item'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: 0;

// Search term — matches Listing ID or ListingKey. Key 'mlsimport_s' MUST match prepare_items().
$filter_search = isset( $_GET['mlsimport_s'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	? trim( sanitize_text_field( wp_unslash( $_GET['mlsimport_s'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	: '';

$list_table = new Mlsimport_Activity_List_Table();
$list_table->prepare_items();

$import_task_options = $list_table->get_import_task_options();
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Import History', 'mlsimport' ); ?></h1>
	<p><?php echo esc_html__( 'Showing import activity from the last 30 days.', 'mlsimport' ); ?></p>

	<form method="get" action="" class="notice notice-info mlsimport-history-filters">
		<input type="hidden" name="page" value="mlsimport_history">

		<label for="mlsimport-search"><?php echo esc_html__( 'Search:', 'mlsimport' ); ?></label>
		<input type="search" id="mlsimport-search" name="mlsimport_s" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php echo esc_attr__( 'Listing ID or ListingKey', 'mlsimport' ); ?>">

		<label for="mlsimport-action-filter"><?php echo esc_html__( 'Action:', 'mlsimport' ); ?></label>
		<select id="mlsimport-action-filter" name="mlsimport_action">
			<option value=""><?php echo esc_html__( 'All', 'mlsimport' ); ?></option>
			<option value="added" <?php selected( $filter_action, 'added' ); ?>><?php echo esc_html__( 'Added', 'mlsimport' ); ?></option>
			<option value="edited" <?php selected( $filter_action, 'edited' ); ?>><?php echo esc_html__( 'Edited', 'mlsimport' ); ?></option>
			<option value="deleted" <?php selected( $filter_action, 'deleted' ); ?>><?php echo esc_html__( 'Deleted', 'mlsimport' ); ?></option>
		</select>

		<?php if ( ! empty( $import_task_options ) ) : ?>
		<label for="mlsimport-item-filter"><?php echo esc_html__( 'Import Task:', 'mlsimport' ); ?></label>
		<select id="mlsimport-item-filter" name="mlsimport_item">
			<option value=""><?php echo esc_html__( 'All', 'mlsimport' ); ?></option>
			<?php foreach ( $import_task_options as $task_id => $task_title ) : ?>
				<?php
				// Skip any entry with an empty title — defensive guard (import_item_id=0 is excluded by get_import_task_options()).
				if ( '' === trim( (string) $task_title ) ) {
					continue;
				}
				?>
				<option value="<?php echo esc_attr( (string) $task_id ); ?>" <?php selected( $filter_item, $task_id ); ?>>
					<?php echo esc_html( $task_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php endif; ?>

		<?php submit_button( __( 'Filter', 'mlsimport' ), 'secondary', 'submit', false ); ?>
	</form>

	<?php $list_table->display(); ?>
</div>
