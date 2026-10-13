<?php
/**
 * Admin partial: "Notifications" section of the Listing Changes screen.
 *
 * Included by mlsimport-listing-changes.php (administrator check already done
 * there; `$this` is the Mlsimport_Admin instance). The section lets the admin:
 *
 *   1. tick which kinds of change are emailed (global, one setup per site);
 *   2. type the addresses that receive the daily email (global);
 *   3. switch each MLS connection on or off;
 *   4. open one MLS's filter: every MLS is an accordion row, closed on load,
 *      whose body is rendered by mlsimport-listing-notification-filter.php.
 *
 * What happens in this file, in order:
 *   Step 1: handle a submitted form (nonce + administrator), one of three
 *           actions: save_global, save_filter, reset_filter.
 *   Step 2: read the settings and the connections to show.
 *   Step 3: render the section: the global form (kinds, addresses), then one
 *           accordion row per MLS holding its On switch and its filter form,
 *           then the Save button of the global form.
 *
 * Storage and rules live in Mlsimport_Listing_Notification_Settings; the daily
 * email is sent by Mlsimport_Listing_Notification_Digest.
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

// Block direct access outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mlsimport_notify_page   = admin_url( 'admin.php?page=mlsimport_listing_changes' );
$mlsimport_notify_notice = '';

// Step 1: handle a submitted form.
if ( isset( $_POST['mlsimport_notify_action'] ) && current_user_can( 'administrator' ) ) {
	check_admin_referer( 'mlsimport_listing_notifications' );
	$mlsimport_notify_action = sanitize_key( wp_unslash( $_POST['mlsimport_notify_action'] ) );
	$mlsimport_notify_target = isset( $_POST['mlsimport_notify_mls'] ) ? absint( $_POST['mlsimport_notify_mls'] ) : 0;
	// The settings class sanitizes every value (clean_list / clean_price / is_email).
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$mlsimport_notify_input = isset( $_POST['mlsimport_notify'] ) ? (array) wp_unslash( $_POST['mlsimport_notify'] ) : array();

	if ( 'save_global' === $mlsimport_notify_action ) {
		Mlsimport_Listing_Notification_Settings::save( $mlsimport_notify_input );
		$mlsimport_notify_notice = __( 'Notification settings saved.', 'mlsimport' );
	} elseif ( 'save_filter' === $mlsimport_notify_action && $mlsimport_notify_target > 0 ) {
		Mlsimport_Listing_Notification_Settings::save_filter( $mlsimport_notify_target, $mlsimport_notify_input );
		$mlsimport_notify_notice = __( 'Filter saved.', 'mlsimport' );
	} elseif ( 'reset_filter' === $mlsimport_notify_action && $mlsimport_notify_target > 0 ) {
		Mlsimport_Listing_Notification_Settings::reset_filter( $mlsimport_notify_target );
		$mlsimport_notify_notice = __( 'Filter rebuilt from the import tasks.', 'mlsimport' );
	}
}

// Step 2: what to show.
$mlsimport_notify_settings    = Mlsimport_Listing_Notification_Settings::get();
$mlsimport_notify_connections = Mlsimport_Connections::all();
$mlsimport_notify_kinds       = Mlsimport_Listing_Notification_Matcher::kind_labels();
// The MLS whose filter was just saved or reset: its accordion row stays open.
$mlsimport_notify_open = '' !== $mlsimport_notify_notice && isset( $mlsimport_notify_target ) ? (int) $mlsimport_notify_target : 0;

/**
 * One-line, human summary of an MLS's filter for the list row.
 *
 * @param array $rules Connection rules from the settings class.
 * @return string e.g. "Austin, Lakeway +2 · $500,000 – $900,000 · any type · any status".
 */
$mlsimport_notify_summary = static function ( array $rules ): string {
	// Up to two values of a list field, then "+N".
	$short = static function ( array $values, string $any ): string {
		if ( empty( $values ) ) {
			return $any;
		}
		$more = count( $values ) - 2;
		return implode( ', ', array_slice( $values, 0, 2 ) ) . ( $more > 0 ? ' +' . $more : '' );
	};
	$parts = array( $short( $rules['city'], __( 'any city', 'mlsimport' ) ) );
	if ( ! empty( $rules['neighborhood'] ) ) {
		$parts[] = $short( $rules['neighborhood'], '' );
	}
	if ( ! empty( $rules['zip'] ) ) {
		$parts[] = $short( $rules['zip'], '' );
	}
	$min = (float) $rules['min_price'];
	$max = (float) $rules['max_price'];
	if ( $min > 0 || $max > 0 ) {
		$parts[] = ( $min > 0 ? number_format_i18n( $min ) : '0' ) . ' – ' . ( $max > 0 ? number_format_i18n( $max ) : __( 'no maximum', 'mlsimport' ) );
	} else {
		$parts[] = __( 'any price', 'mlsimport' );
	}
	$parts[] = $short( $rules['property_type'], __( 'any type', 'mlsimport' ) );
	$parts[] = $short( $rules['status'], __( 'any status', 'mlsimport' ) );
	return implode( ' · ', $parts );
};
?>
<details class="mlsimport-notify" id="mlsimport-notify" open>
	<summary class="mlsimport-notify__summary">
		<span class="mlsimport-notify__title"><?php esc_html_e( 'Notifications', 'mlsimport' ); ?></span>
	</summary>

	<?php if ( '' !== $mlsimport_notify_notice ) : ?>
		<div class="notice notice-success inline mlsimport-notify__notice"><p><?php echo esc_html( $mlsimport_notify_notice ); ?></p></div>
	<?php endif; ?>

	<form method="post" id="mlsimport-notify-form" action="<?php echo esc_url( $mlsimport_notify_page . '#mlsimport-notify' ); ?>" class="mlsimport-notify__form">
		<?php wp_nonce_field( 'mlsimport_listing_notifications' ); ?>
		<input type="hidden" name="mlsimport_notify_action" value="save_global">

		<h3 class="mlsimport-notify__heading"><?php esc_html_e( 'Email me about', 'mlsimport' ); ?></h3>
		<div class="mlsimport-notify__kinds">
			<?php foreach ( $mlsimport_notify_kinds as $mlsimport_kind => $mlsimport_kind_label ) : ?>
				<label class="mlsimport-notify__kind">
					<input type="checkbox" name="mlsimport_notify[event_types][]" value="<?php echo esc_attr( $mlsimport_kind ); ?>" <?php checked( in_array( $mlsimport_kind, $mlsimport_notify_settings['event_types'], true ) ); ?>>
					<?php echo esc_html( $mlsimport_kind_label ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="mlsimport-notify__help"><?php esc_html_e( '"Sold" is reported only when the MLS sends a closed record. A listing that simply leaves the feed is reported as "Off market".', 'mlsimport' ); ?></p>

		<h3 class="mlsimport-notify__heading"><label for="mlsimport-notify-emails"><?php esc_html_e( 'Send to', 'mlsimport' ); ?></label></h3>
		<input type="text" id="mlsimport-notify-emails" class="mlsimport-input mlsimport-2025-input mlsimport-notify__emails" name="mlsimport_notify[emails]" value="<?php echo esc_attr( implode( ', ', $mlsimport_notify_settings['emails'] ) ); ?>" placeholder="<?php esc_attr_e( 'agent@example.com, office@example.com', 'mlsimport' ); ?>">
		<p class="mlsimport-notify__help"><?php esc_html_e( 'One or more addresses, separated by commas. One email per MLS per day, and none on a day with no matching change.', 'mlsimport' ); ?></p>

	</form>

	<h3 class="mlsimport-notify__heading mlsimport-notify__heading--section"><?php esc_html_e( 'Notification Emails', 'mlsimport' ); ?></h3>
	<?php if ( empty( $mlsimport_notify_connections ) ) : ?>
		<p class="mlsimport-notify__help"><?php esc_html_e( 'No MLS connection is set up yet.', 'mlsimport' ); ?></p>
	<?php else : ?>
		<?php
		// One accordion row per MLS, all closed on load (the one whose filter was
		// just saved or reset stays open). The filter has its own <form>, so the
		// row sits outside the global form and its On switch joins that form
		// through the `form` attribute.
		?>
		<div class="mlsimport-notify__list">
			<?php foreach ( $mlsimport_notify_connections as $mlsimport_mls_id => $mlsimport_connection ) : ?>
				<?php
				$mlsimport_notify_edit = (int) $mlsimport_mls_id;
				$mlsimport_rules       = Mlsimport_Listing_Notification_Settings::connection( $mlsimport_notify_edit );
				$mlsimport_enabled_key = 'mlsimport_notify[connections][' . $mlsimport_notify_edit . '][enabled]';
				?>
				<details class="mlsimport-notify__mls" id="mlsimport-notify-mls-<?php echo esc_attr( (string) $mlsimport_notify_edit ); ?>" <?php echo $mlsimport_notify_open === $mlsimport_notify_edit ? 'open' : ''; ?>>
					<summary class="mlsimport-notify__mls-summary">
						<strong class="mlsimport-notify__mls-name"><?php echo esc_html( '' !== (string) $mlsimport_connection['mls_name'] ? (string) $mlsimport_connection['mls_name'] : 'MLS ' . $mlsimport_mls_id ); ?></strong>
						<input type="hidden" form="mlsimport-notify-form" name="<?php echo esc_attr( $mlsimport_enabled_key ); ?>" value="0">
						<label>
							<input type="checkbox" form="mlsimport-notify-form" name="<?php echo esc_attr( $mlsimport_enabled_key ); ?>" value="1" <?php checked( ! empty( $mlsimport_rules['enabled'] ) ); ?>>
							<?php esc_html_e( 'On', 'mlsimport' ); ?>
						</label>
						<span class="mlsimport-notify__mls-filter"><?php echo esc_html( $mlsimport_notify_summary( $mlsimport_rules ) ); ?></span>
						<span class="mlsimport-notify__help">
							<?php
							/* translators: %d: number of import tasks. */
							echo esc_html( sprintf( _n( '%d import task', '%d import tasks', (int) $mlsimport_rules['task_count'], 'mlsimport' ), (int) $mlsimport_rules['task_count'] ) );
							?>
						</span>
					</summary>
					<?php include __DIR__ . '/mlsimport-listing-notification-filter.php'; ?>
				</details>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<p class="mlsimport-notify__actions">
		<button type="submit" form="mlsimport-notify-form" class="button mlsimport_button save_data"><?php esc_html_e( 'Save notifications', 'mlsimport' ); ?></button>
	</p>
</details>
