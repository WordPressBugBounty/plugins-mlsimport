<?php
/**
 * Admin partial: the notification filter form of ONE MLS connection.
 *
 * Included by mlsimport-listing-notifications.php once per MLS, as the body of
 * that MLS's accordion row (so every id below carries the MLS id). Variables it
 * relies on from the including file:
 *   $mlsimport_notify_edit        int    the MLS this form belongs to
 *   $mlsimport_notify_connections array  Mlsimport_Connections::all()
 *   $mlsimport_notify_page        string the Listing Changes page URL
 * `$this` is the Mlsimport_Admin instance.
 *
 * The controls are the same ones the Import Task screen uses, filled from the
 * same source (`mlsimport_saas_return_mls_fields( $mls_id )`):
 *   - City: searchable multi-select with chips (mlsimport-searchable-select.js);
 *   - Property type and Status: multi-select lists;
 *   - Neighborhood and Zip: comma-separated text boxes;
 *   - Price: minimum and maximum.
 * An empty field does not restrict. Status starts empty (every status counts).
 *
 * Step 1: read this MLS's current rules and the option lists.
 * Step 2: render the form (action save_filter) and the small reset form
 *         (action reset_filter); both post back to the including partial.
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

// Block direct access outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Step 1: current rules + option lists of this MLS.
$mlsimport_filter_rules = Mlsimport_Listing_Notification_Settings::connection( $mlsimport_notify_edit );
$mlsimport_filter_name  = (string) $mlsimport_notify_connections[ $mlsimport_notify_edit ]['mls_name'];
// Both forms post back to the page and land on this MLS's accordion row.
$mlsimport_filter_action = $mlsimport_notify_page . '#mlsimport-notify-mls-' . $mlsimport_notify_edit;
// The field builder prints a warning when the MLS has no metadata yet; that
// warning belongs to the Import Task screen, so it is discarded here.
ob_start();
$mlsimport_filter_fields = (array) $this->mlsimport_saas_return_mls_fields( $mlsimport_notify_edit );
ob_end_clean();

/**
 * Options of one picker: the MLS's own values plus any saved value that is not
 * in that list (an "extra city" typed on the import task, for example).
 *
 * @param string $field_key  City | PropertyType | StandardStatus.
 * @param array  $saved      Saved values of the filter field.
 * @return array<int, string>
 */
$mlsimport_filter_options = static function ( string $field_key, array $saved ) use ( $mlsimport_filter_fields ): array {
	$values = isset( $mlsimport_filter_fields[ $field_key ]['values'] ) && is_array( $mlsimport_filter_fields[ $field_key ]['values'] )
		? array_map( 'strval', $mlsimport_filter_fields[ $field_key ]['values'] )
		: array();
	return array_values( array_filter( array_unique( array_merge( $values, $saved ) ), 'strlen' ) );
};

// The three list pickers: filter field => ( label, RESO field, help, searchable ).
$mlsimport_filter_pickers = array(
	'city'          => array( __( 'City', 'mlsimport' ), 'City', __( 'Nothing selected means every city.', 'mlsimport' ), true ),
	'property_type' => array( __( 'Property type', 'mlsimport' ), 'PropertyType', __( 'Nothing selected means every type.', 'mlsimport' ), false ),
	'status'        => array( __( 'Status', 'mlsimport' ), 'StandardStatus', __( 'Nothing selected means every status. A listing that leaves is matched by the last status it had.', 'mlsimport' ), false ),
);
?>
<div class="mlsimport-notify__filter">
	<h3 class="mlsimport-notify__heading">
		<?php
		/* translators: %s: MLS name. */
		echo esc_html( sprintf( __( 'Filter for %s', 'mlsimport' ), $mlsimport_filter_name ) );
		?>
	</h3>
	<p class="mlsimport-notify__help"><?php esc_html_e( 'Only listings that match every field below are emailed. The Alerts table keeps showing everything.', 'mlsimport' ); ?></p>

	<form method="post" action="<?php echo esc_url( $mlsimport_filter_action ); ?>">
		<?php wp_nonce_field( 'mlsimport_listing_notifications' ); ?>
		<input type="hidden" name="mlsimport_notify_action" value="save_filter">
		<input type="hidden" name="mlsimport_notify_mls" value="<?php echo esc_attr( (string) $mlsimport_notify_edit ); ?>">

		<div class="mlsimport-notify__grid">
			<?php foreach ( $mlsimport_filter_pickers as $mlsimport_filter_key => $mlsimport_picker ) : ?>
				<?php
				$mlsimport_picker_saved = (array) $mlsimport_filter_rules[ $mlsimport_filter_key ];
				$mlsimport_picker_id    = 'mlsimport-notify-' . $mlsimport_filter_key . '-' . $mlsimport_notify_edit;
				?>
				<fieldset class="mlsimport-fieldset mlsimport-notify__field">
					<label class="mlsimport-label" for="<?php echo esc_attr( $mlsimport_picker_id ); ?>"><?php echo esc_html( $mlsimport_picker[0] ); ?></label>
					<p class="mlsimport-exp"><?php echo esc_html( $mlsimport_picker[2] ); ?></p>
					<?php if ( $mlsimport_picker[3] ) : ?>
						<div class="mlsimport-selected-chips" aria-live="polite"></div>
						<input type="text" class="mlsimport-select-search" placeholder="<?php esc_attr_e( 'Type to search…', 'mlsimport' ); ?>" aria-label="<?php esc_attr_e( 'Type to search…', 'mlsimport' ); ?>" autocomplete="off">
					<?php endif; ?>
					<select multiple size="<?php echo $mlsimport_picker[3] ? '12' : '6'; ?>" id="<?php echo esc_attr( $mlsimport_picker_id ); ?>" class="mlsimport-select mlsimport-2025-select<?php echo $mlsimport_picker[3] ? ' mlsimport-searchable-select' : ''; ?>" name="mlsimport_notify[<?php echo esc_attr( $mlsimport_filter_key ); ?>][]">
						<?php foreach ( $mlsimport_filter_options( $mlsimport_picker[1], $mlsimport_picker_saved ) as $mlsimport_option ) : ?>
							<option value="<?php echo esc_attr( $mlsimport_option ); ?>" <?php selected( in_array( $mlsimport_option, $mlsimport_picker_saved, true ) ); ?>><?php echo esc_html( $mlsimport_option ); ?></option>
						<?php endforeach; ?>
					</select>
				</fieldset>
			<?php endforeach; ?>

			<fieldset class="mlsimport-fieldset mlsimport-notify__field">
				<label class="mlsimport-label" for="mlsimport-notify-neighborhood-<?php echo esc_attr( (string) $mlsimport_notify_edit ); ?>"><?php esc_html_e( 'Neighborhood', 'mlsimport' ); ?></label>
				<p class="mlsimport-exp"><?php esc_html_e( 'Subdivision names, separated by commas. Empty means every neighborhood.', 'mlsimport' ); ?></p>
				<input type="text" id="mlsimport-notify-neighborhood-<?php echo esc_attr( (string) $mlsimport_notify_edit ); ?>" class="mlsimport-select mlsimport-input mlsimport-2025-input" name="mlsimport_notify[neighborhood]" value="<?php echo esc_attr( implode( ', ', (array) $mlsimport_filter_rules['neighborhood'] ) ); ?>">

				<label class="mlsimport-label" for="mlsimport-notify-zip-<?php echo esc_attr( (string) $mlsimport_notify_edit ); ?>"><?php esc_html_e( 'Zip', 'mlsimport' ); ?></label>
				<p class="mlsimport-exp"><?php esc_html_e( 'Postal codes, separated by commas. Empty means every zip.', 'mlsimport' ); ?></p>
				<input type="text" id="mlsimport-notify-zip-<?php echo esc_attr( (string) $mlsimport_notify_edit ); ?>" class="mlsimport-select mlsimport-input mlsimport-2025-input" name="mlsimport_notify[zip]" value="<?php echo esc_attr( implode( ', ', (array) $mlsimport_filter_rules['zip'] ) ); ?>">

				<label class="mlsimport-label" for="mlsimport-notify-min-price-<?php echo esc_attr( (string) $mlsimport_notify_edit ); ?>"><?php esc_html_e( 'Price between', 'mlsimport' ); ?></label>
				<p class="mlsimport-exp"><?php esc_html_e( 'Empty means no limit. A price change is matched by the new price.', 'mlsimport' ); ?></p>
				<span class="mlsimport-notify__prices">
					<input type="text" id="mlsimport-notify-min-price-<?php echo esc_attr( (string) $mlsimport_notify_edit ); ?>" class="mlsimport-input mlsimport-2025-input" name="mlsimport_notify[min_price]" value="<?php echo esc_attr( (float) $mlsimport_filter_rules['min_price'] > 0 ? (string) (float) $mlsimport_filter_rules['min_price'] : '' ); ?>" placeholder="<?php esc_attr_e( 'minimum', 'mlsimport' ); ?>" aria-label="<?php esc_attr_e( 'Minimum price', 'mlsimport' ); ?>">
					<span><?php esc_html_e( 'and', 'mlsimport' ); ?></span>
					<input type="text" class="mlsimport-input mlsimport-2025-input" name="mlsimport_notify[max_price]" value="<?php echo esc_attr( (float) $mlsimport_filter_rules['max_price'] > 0 ? (string) (float) $mlsimport_filter_rules['max_price'] : '' ); ?>" placeholder="<?php esc_attr_e( 'maximum', 'mlsimport' ); ?>" aria-label="<?php esc_attr_e( 'Maximum price', 'mlsimport' ); ?>">
				</span>
			</fieldset>
		</div>

		<p class="mlsimport-notify__actions">
			<button type="submit" class="button mlsimport_button save_data"><?php esc_html_e( 'Save filter', 'mlsimport' ); ?></button>
		</p>
	</form>

	<form method="post" action="<?php echo esc_url( $mlsimport_filter_action ); ?>" class="mlsimport-notify__reset">
		<?php wp_nonce_field( 'mlsimport_listing_notifications' ); ?>
		<input type="hidden" name="mlsimport_notify_action" value="reset_filter">
		<input type="hidden" name="mlsimport_notify_mls" value="<?php echo esc_attr( (string) $mlsimport_notify_edit ); ?>">
		<button type="submit" class="button-link"><?php esc_html_e( 'Reset from import tasks', 'mlsimport' ); ?></button>
		<span class="mlsimport-notify__help">
			<?php
			/* translators: %d: number of import tasks. */
			echo esc_html( sprintf( _n( 'Rebuilds the filter from this MLS\'s %d import task.', 'Rebuilds the filter from this MLS\'s %d import tasks, combined.', (int) $mlsimport_filter_rules['task_count'], 'mlsimport' ), (int) $mlsimport_filter_rules['task_count'] ) );
			?>
		</span>
	</form>
</div>
