<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// B1 — Schema version. Bump to trigger dbDelta re-run via mlsimport_maybe_upgrade_activity_table().
define( 'MLSIMPORT_ACTIVITY_DB_VERSION', '1.0' );

/**
 * Returns the prefixed activity table name.
 * The ONLY source of the table name — never accept a table name from request input,
 * never use as a $wpdb->prepare placeholder.
 *
 * @return string
 */
function mlsimport_activity_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'mlsimport_activity';
}

/**
 * Creates the activity table via dbDelta().
 * Safe to call multiple times — dbDelta() is idempotent.
 *
 * @return void
 */
function mlsimport_create_activity_table(): void {
	global $wpdb;

	$table_name      = mlsimport_activity_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  action VARCHAR(10) NOT NULL,
  listing_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  listing_key VARCHAR(191) NOT NULL DEFAULT '',
  listing_title VARCHAR(255) NOT NULL DEFAULT '',
  listing_url VARCHAR(255) NOT NULL DEFAULT '',
  import_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  import_item_title VARCHAR(255) NOT NULL DEFAULT '',
  source VARCHAR(20) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY import_item_id (import_item_id),
  KEY action (action)
) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Runs the table migration if the installed schema version is outdated.
 * Hooked to 'init' priority 1 so it runs on wp-cron requests too.
 *
 * @return void
 */
function mlsimport_maybe_upgrade_activity_table(): void {
	if ( get_option( 'mlsimport_activity_db_version' ) !== MLSIMPORT_ACTIVITY_DB_VERSION ) {
		mlsimport_create_activity_table();
		update_option( 'mlsimport_activity_db_version', MLSIMPORT_ACTIVITY_DB_VERSION );
	}
}
add_action( 'init', 'mlsimport_maybe_upgrade_activity_table', 1 );

/**
 * Normalizes a raw source label to one of the canonical values.
 * PURE PHP — no WordPress functions (unit-testable).
 *
 * Maps: normal->manual, cron->cron, manual->manual, import->import,
 *       reconciliation->reconciliation, anything else->other.
 *
 * @param string $raw
 * @return string
 */
function mlsimport_normalize_activity_source( string $raw ): string {
	$normalized = strtolower( trim( $raw ) );

	$map = [
		'normal'          => 'manual',
		'cron'            => 'cron',
		'manual'          => 'manual',
		'import'          => 'import',
		'reconciliation'  => 'reconciliation',
	];

	return isset( $map[ $normalized ] ) ? $map[ $normalized ] : 'other';
}

/**
 * Records ONE activity row for a listing add / edit / delete.
 * Guards $action first — returns before any WP/DB call on invalid input.
 * Snapshots title/URL/import-task title and caps every string to its column width.
 *
 * @param string $action         'added' | 'edited' | 'deleted'
 * @param int    $listing_id      WP post ID of the property
 * @param string $listing_key     MLS ListingKey
 * @param int    $import_item_id  mlsimport_item post ID (0 if unknown)
 * @param string $source          raw label, e.g. 'normal' | 'cron' | 'import' | 'reconciliation'
 * @return void
 */
function mlsimport_record_activity( string $action, int $listing_id, string $listing_key, int $import_item_id, string $source = '' ): void {
	$valid_actions = [ 'added', 'edited', 'deleted' ];

	if ( ! in_array( $action, $valid_actions, true ) ) {
		return;
	}

	global $wpdb;

	// Snapshot listing title — fall back to listing_key if empty.
	$raw_title = get_the_title( $listing_id );
	if ( '' === $raw_title ) {
		$raw_title = $listing_key;
	}
	$listing_title = mb_substr( wp_strip_all_tags( $raw_title ), 0, 255 );

	// Snapshot listing URL.
	$permalink   = get_permalink( $listing_id );
	$listing_url = ( false !== $permalink ) ? mb_substr( $permalink, 0, 255 ) : '';

	// Snapshot import task title.
	$import_item_title = mb_substr( wp_strip_all_tags( get_the_title( $import_item_id ) ), 0, 255 );

	// Normalize source.
	$normalized_source = mlsimport_normalize_activity_source( $source );

	// Cap listing_key to its column width.
	$listing_key_capped = mb_substr( $listing_key, 0, 191 );

	$data = [
		'action'            => $action,
		'listing_id'        => $listing_id,
		'listing_key'       => $listing_key_capped,
		'listing_title'     => $listing_title,
		'listing_url'       => $listing_url,
		'import_item_id'    => $import_item_id,
		'import_item_title' => $import_item_title,
		'source'            => $normalized_source,
		'created_at'        => current_time( 'mysql' ),
	];

	$format = [
		'%s', // action
		'%d', // listing_id
		'%s', // listing_key
		'%s', // listing_title
		'%s', // listing_url
		'%d', // import_item_id
		'%s', // import_item_title
		'%s', // source
		'%s', // created_at
	];

	$wpdb->insert( mlsimport_activity_table_name(), $data, $format );

	// A new activity row makes the cached 24h banner summary stale. Drop the
	// transient so the next banner view recomputes — keeps the banner current
	// after every import without waiting out the 15-minute TTL.
	delete_transient( 'mlsimport_activity_banner_summary' );
}

/**
 * Aggregates activity rows from the last $hours.
 * Query uses GROUP BY import_item_id, action — safe under ONLY_FULL_GROUP_BY.
 * Table name is interpolated directly (never as a prepare placeholder).
 *
 * @param int $hours Window in hours. Default 24.
 * @return array {
 *   'totals' => ['added'=>int, 'edited'=>int, 'deleted'=>int],
 *   'by_item' => [ import_item_id => ['title'=>string, 'added'=>int, 'edited'=>int, 'deleted'=>int] ]
 * }
 */
function mlsimport_get_activity_summary( int $hours = 24 ): array {
	global $wpdb;

	$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $hours * HOUR_IN_SECONDS );

	$table = mlsimport_activity_table_name();

	$sql  = $wpdb->prepare(
		"SELECT import_item_id, action, COUNT(*) AS cnt, MAX(import_item_title) AS import_item_title FROM {$table} WHERE created_at >= %s GROUP BY import_item_id, action",
		$cutoff
	);
	$rows = $wpdb->get_results( $sql );

	$totals  = [ 'added' => 0, 'edited' => 0, 'deleted' => 0 ];
	$by_item = [];

	if ( ! empty( $rows ) ) {
		foreach ( $rows as $row ) {
			$item_id = (int) $row->import_item_id;
			$act     = $row->action;
			$cnt     = (int) $row->cnt;
			$title   = (string) $row->import_item_title;

			if ( isset( $totals[ $act ] ) ) {
				$totals[ $act ] += $cnt;
			}

			if ( ! isset( $by_item[ $item_id ] ) ) {
				$by_item[ $item_id ] = [
					'title'   => $title,
					'added'   => 0,
					'edited'  => 0,
					'deleted' => 0,
				];
			}

			if ( isset( $by_item[ $item_id ][ $act ] ) ) {
				$by_item[ $item_id ][ $act ] += $cnt;
			}

			// Keep most recent title if available.
			if ( '' !== $title ) {
				$by_item[ $item_id ]['title'] = $title;
			}
		}
	}

	return [
		'totals'  => $totals,
		'by_item' => $by_item,
	];
}

/**
 * Returns a cached (15-minute transient) version of the 24-hour activity summary.
 * The recorder does NOT bust this transient.
 *
 * @return array Same structure as mlsimport_get_activity_summary().
 */
function mlsimport_get_activity_banner_data(): array {
	$cached = get_transient( 'mlsimport_activity_banner_summary' );
	if ( false !== $cached ) {
		return $cached;
	}

	$data = mlsimport_get_activity_summary( 24 );
	set_transient( 'mlsimport_activity_banner_summary', $data, 15 * MINUTE_IN_SECONDS );

	return $data;
}

/**
 * Deletes activity rows older than 30 days.
 * Hooked to 'mlsimport_reconciliation_event' (existing daily cron).
 *
 * @return void
 */
function mlsimport_prune_activity_log(): void {
	global $wpdb;

	$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );

	$table = mlsimport_activity_table_name();

	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff )
	);
}
add_action( 'mlsimport_reconciliation_event', 'mlsimport_prune_activity_log' );

// ---------------------------------------------------------------------------
// B2 — Banner render + AJAX dismissal handler
// ---------------------------------------------------------------------------

/**
 * Renders the dismissible sync-status banner on wp-admin pages.
 * Shows only when:
 *   1. current user is an administrator,
 *   2. the last-24h activity totals are non-zero,
 *   3. the per-user 'mlsimport_activity_banner_dismissed' meta is NOT today's date.
 *
 * Mirrors the inline-script pattern from mlsimport_handle_dismiss_protected_notice()
 * in mlsimport.php.
 *
 * @return void
 */
function mlsimport_render_activity_banner(): void {

	if ( ! current_user_can( 'administrator' ) ) {
		return;
	}

	$data    = mlsimport_get_activity_banner_data();
	$totals  = $data['totals'];
	$by_item = $data['by_item'];

	$today     = current_time( 'Y-m-d' );
	$dismissed = get_user_meta( get_current_user_id(), 'mlsimport_activity_banner_dismissed', true );
	if ( $dismissed === $today ) {
		return;
	}

	$nonce        = wp_create_nonce( 'mlsimport_activity_banner' );
	$history_url  = esc_url( admin_url( 'admin.php?page=mlsimport_history' ) );

	?>
	<div class="notice notice-info is-dismissible mlsimport-activity-banner">
		<div class="mlsimport-activity-banner__counts">
			<span class="mlsimport-activity-banner__title">
				<?php echo esc_html__( 'MLSImport activity', 'mlsimport' ); ?>
				<span class="mlsimport-activity-banner__period"><?php echo esc_html__( 'last 24 hours', 'mlsimport' ); ?></span>
			</span>
			<span class="mlsimport-activity-banner__totals">
				<?php
				$total_added   = (int) $totals['added'];
				$total_edited  = (int) $totals['edited'];
				$total_deleted = (int) $totals['deleted'];
				?>
				<span class="mlsimport-activity-stat mlsimport-activity-stat--added"><strong><?php echo esc_html( number_format_i18n( $total_added ) ); ?></strong> <?php echo esc_html( _n( 'property', 'properties', $total_added, 'mlsimport' ) ); ?> <?php echo esc_html__( 'added', 'mlsimport' ); ?></span>
				<span class="mlsimport-activity-stat mlsimport-activity-stat--edited"><strong><?php echo esc_html( number_format_i18n( $total_edited ) ); ?></strong> <?php echo esc_html( _n( 'property', 'properties', $total_edited, 'mlsimport' ) ); ?> <?php echo esc_html__( 'edited', 'mlsimport' ); ?></span>
				<span class="mlsimport-activity-stat mlsimport-activity-stat--deleted"><strong><?php echo esc_html( number_format_i18n( $total_deleted ) ); ?></strong> <?php echo esc_html( _n( 'property', 'properties', $total_deleted, 'mlsimport' ) ); ?> <?php echo esc_html__( 'deleted', 'mlsimport' ); ?></span>
			</span>
		</div>
		<?php
		if ( ! empty( $by_item ) ) :
			// Show only the 5 most active tasks (by total added + edited + deleted).
			usort(
				$by_item,
				function ( $a, $b ) {
					$a_total = (int) $a['added'] + (int) $a['edited'] + (int) $a['deleted'];
					$b_total = (int) $b['added'] + (int) $b['edited'] + (int) $b['deleted'];
					return $b_total <=> $a_total;
				}
			);
			$by_item = array_slice( $by_item, 0, 5 );
			?>
		<ul class="mlsimport-activity-banner__breakdown">
			<?php
			foreach ( $by_item as $item ) :
				$task_title = trim( (string) $item['title'] );
				if ( '' === $task_title ) {
					$task_title = __( 'Unknown import task', 'mlsimport' );
				}
				$added   = (int) $item['added'];
				$edited  = (int) $item['edited'];
				$deleted = (int) $item['deleted'];
				?>
				<li>
					<span class="mlsimport-activity-banner__task"><?php echo esc_html__( 'Task Name:', 'mlsimport' ); ?> <?php echo esc_html( $task_title ); ?></span>
					<span class="mlsimport-activity-banner__chips">
						<span class="mlsimport-activity-chip mlsimport-activity-chip--added<?php echo 0 === $added ? ' is-zero' : ''; ?>"><?php echo esc_html( number_format_i18n( $added ) ); ?> <?php echo esc_html( _n( 'property', 'properties', $added, 'mlsimport' ) ); ?> <?php echo esc_html__( 'added', 'mlsimport' ); ?></span>
						<span class="mlsimport-activity-chip mlsimport-activity-chip--edited<?php echo 0 === $edited ? ' is-zero' : ''; ?>"><?php echo esc_html( number_format_i18n( $edited ) ); ?> <?php echo esc_html( _n( 'property', 'properties', $edited, 'mlsimport' ) ); ?> <?php echo esc_html__( 'edited', 'mlsimport' ); ?></span>
						<span class="mlsimport-activity-chip mlsimport-activity-chip--deleted<?php echo 0 === $deleted ? ' is-zero' : ''; ?>"><?php echo esc_html( number_format_i18n( $deleted ) ); ?> <?php echo esc_html( _n( 'property', 'properties', $deleted, 'mlsimport' ) ); ?> <?php echo esc_html__( 'deleted', 'mlsimport' ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<p class="mlsimport-activity-banner__actions">
			<a class="mlsimport-activity-banner__link" href="<?php echo $history_url; ?>"><?php echo esc_html__( 'View full 30-day history', 'mlsimport' ); ?> <span aria-hidden="true">&rarr;</span></a>
		</p>
		<span class="mlsimport-activity-banner-nonce" style="display:none;" data-nonce="<?php echo esc_attr( $nonce ); ?>"></span>
	</div>
	<script>
	(function() {
		var banner = document.querySelector('.mlsimport-activity-banner');
		if ( ! banner ) { return; }
		var dismissBtn = banner.querySelector('.notice-dismiss');
		if ( ! dismissBtn ) { return; }
		var nonce = banner.querySelector('.mlsimport-activity-banner-nonce').getAttribute('data-nonce');
		dismissBtn.addEventListener('click', function() {
			var xhr = new XMLHttpRequest();
			xhr.open('POST', ajaxurl);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr.send('action=mlsimport_dismiss_activity_banner&_ajax_nonce=' + encodeURIComponent(nonce));
		});
	})();
	</script>
	<?php
}
add_action( 'admin_notices', 'mlsimport_render_activity_banner' );

/**
 * AJAX handler: persists banner dismissal for the current user for today.
 *
 * @return void
 */
function mlsimport_ajax_dismiss_activity_banner(): void {
	check_ajax_referer( 'mlsimport_activity_banner' );

	if ( ! current_user_can( 'administrator' ) ) {
		wp_send_json_error();
	}

	update_user_meta( get_current_user_id(), 'mlsimport_activity_banner_dismissed', current_time( 'Y-m-d' ) );

	wp_send_json_success();
}
add_action( 'wp_ajax_mlsimport_dismiss_activity_banner', 'mlsimport_ajax_dismiss_activity_banner' );
