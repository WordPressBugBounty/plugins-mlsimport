<?php
/**
 * Live mode: the on/off flag, cache TTL and manual config overrides.
 *
 * Options only + a small procedural admin screen under the Standalone Design
 * menu. The React settings app is untouched. The overrides exist so a site
 * can run live mode before (or without) the SaaS config handshake; any
 * override left empty defers to the fetched config.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the live-mode switch is on (the flag only — not the full gate).
 *
 * @return bool
 */
function mlsimport_live_enabled(): bool {
	return '1' === get_option( 'mlsimport_live_enabled', '' );
}

/**
 * Cache TTL in seconds for live reads. Option-driven, default 1 hour.
 *
 * @return int
 */
function mlsimport_live_cache_ttl(): int {
	$ttl = (int) get_option( 'mlsimport_live_cache_ttl', 3600 );
	if ( $ttl < 60 ) {
		$ttl = 3600;
	}
	/** Filter the live-mode cache TTL (seconds). @since 6.4 */
	return (int) apply_filters( 'mlsimport_live_cache_ttl', $ttl );
}

/**
 * Manual per-MLS config overrides (api_import_url, type, expand,
 * field_corellation). Empty values are dropped so they defer to the config
 * fetched from the SaaS at setup.
 *
 * @return array
 */
function mlsimport_live_config_overrides(): array {
	$overrides = get_option( 'mlsimport_live_overrides', array() );
	if ( ! is_array( $overrides ) ) {
		return array();
	}
	return array_filter(
		$overrides,
		static function ( $value ) {
			return is_string( $value ) && '' !== trim( $value );
		}
	);
}

/**
 * Register the Live Mode screen under Standalone Design.
 *
 * @return void
 */
function mlsimport_live_settings_menu(): void {
	add_submenu_page(
		'mlsimport_standalone_settings',
		esc_html__( 'Live Mode', 'mlsimport' ),
		esc_html__( 'Live Mode', 'mlsimport' ),
		'manage_options',
		'mlsimport_live_settings',
		'mlsimport_live_settings_page'
	);
}

/**
 * Render + save the Live Mode settings screen.
 *
 * @return void
 */
function mlsimport_live_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice = '';
	if ( isset( $_POST['mlsimport_live_settings_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['mlsimport_live_settings_nonce'] ), 'mlsimport_live_settings' ) ) {
		if ( isset( $_POST['mlsimport_live_clear_cache'] ) ) {
			mlsimport_live_cache_clear();
			$notice = esc_html__( 'Live cache cleared.', 'mlsimport' );
		} elseif ( isset( $_POST['mlsimport_live_refresh_config'] ) ) {
			$config = mlsimport_live_config_refresh();
			$notice = false === $config
				? esc_html__( 'Could not fetch the MLS configuration. Check the MLSImport connection.', 'mlsimport' )
				: esc_html__( 'MLS configuration refreshed.', 'mlsimport' );
		} else {
			$was_enabled = mlsimport_live_enabled();
			update_option( 'mlsimport_live_enabled', isset( $_POST['mlsimport_live_enabled'] ) ? '1' : '' );
			$ttl = isset( $_POST['mlsimport_live_cache_ttl'] ) ? absint( wp_unslash( $_POST['mlsimport_live_cache_ttl'] ) ) : 3600;
			update_option( 'mlsimport_live_cache_ttl', max( 60, $ttl ) );

			$overrides = array();
			foreach ( array( 'api_import_url', 'api_token_url', 'api_media_url', 'type', 'expand', 'field_corellation' ) as $key ) {
				$raw = isset( $_POST[ 'mlsimport_live_override_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'mlsimport_live_override_' . $key ] ) ) : '';
				if ( '' !== trim( $raw ) ) {
					$overrides[ $key ] = trim( $raw );
				}
			}
			update_option( 'mlsimport_live_overrides', $overrides );

			if ( mlsimport_live_enabled() !== $was_enabled ) {
				// Deferred flush: the toggle changed after init, so this
				// request's in-memory rules still reflect the OLD gate state.
				// The next request re-registers routes with the new one.
				delete_option( 'rewrite_rules' );
			}
			$notice = esc_html__( 'Live mode settings saved.', 'mlsimport' );
		}
	}

	$config    = function_exists( 'mlsimport_live_config' ) ? mlsimport_live_config() : array();
	$overrides = get_option( 'mlsimport_live_overrides', array() );
	$overrides = is_array( $overrides ) ? $overrides : array();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Live MLS Mode', 'mlsimport' ); ?></h1>
		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Live mode reads listings directly from your MLS on demand and stores nothing on this site. Recommended for shared/weak hosting.', 'mlsimport' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'mlsimport_live_settings', 'mlsimport_live_settings_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable live mode', 'mlsimport' ); ?></th>
					<td><label><input type="checkbox" name="mlsimport_live_enabled" value="1" <?php checked( mlsimport_live_enabled() ); ?>> <?php esc_html_e( 'Read listings live from the MLS (no local storage)', 'mlsimport' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="mlsimport_live_cache_ttl"><?php esc_html_e( 'Cache lifetime (seconds)', 'mlsimport' ); ?></label></th>
					<td><input type="number" min="60" id="mlsimport_live_cache_ttl" name="mlsimport_live_cache_ttl" value="<?php echo esc_attr( (string) mlsimport_live_cache_ttl() ); ?>"></td>
				</tr>
				<?php
				foreach ( array(
					'api_import_url'    => __( 'API import URL override', 'mlsimport' ),
					'api_token_url'     => __( 'API token URL override', 'mlsimport' ),
					'api_media_url'     => __( 'API media URL override', 'mlsimport' ),
					'type'              => __( 'Provider type override', 'mlsimport' ),
					'expand'            => __( 'Expand override', 'mlsimport' ),
					'field_corellation' => __( 'Field correlation override (JSON)', 'mlsimport' ),
				) as $key => $label ) :
					$fetched = isset( $config[ $key ] ) && is_string( $config[ $key ] ) ? $config[ $key ] : '';
					?>
				<tr>
					<th scope="row"><label for="mlsimport_live_override_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="mlsimport_live_override_<?php echo esc_attr( $key ); ?>" name="mlsimport_live_override_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $overrides[ $key ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $fetched ); ?>">
					</td>
				</tr>
				<?php endforeach; ?>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'mlsimport' ); ?></button>
				<button type="submit" class="button" name="mlsimport_live_refresh_config" value="1"><?php esc_html_e( 'Refresh MLS config', 'mlsimport' ); ?></button>
				<button type="submit" class="button" name="mlsimport_live_clear_cache" value="1"><?php esc_html_e( 'Clear live cache', 'mlsimport' ); ?></button>
			</p>
		</form>
	</div>
	<?php
}
