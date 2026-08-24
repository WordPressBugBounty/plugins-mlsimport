<?php
/**
 * WordPress persistence adapter for the Field Configuration module.
 *
 * The browser exposes one mutation endpoint with four fixed POST variables:
 * action, nonce, revision, and one JSON command. This file translates that
 * request into the domain module, performs an exact database compare-and-swap,
 * refreshes WordPress's option cache, and emits the authoritative result. The
 * former chunk, individual, bulk, and position handlers intentionally do not
 * survive as alternate mutation paths.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decode current MLS metadata into the domain module's field map.
 *
 * Metadata can be stored as the original JSON string or as an already decoded
 * array. Both shapes are accepted here; malformed or absent metadata becomes
 * an empty map so every caller reaches the same normalization path.
 *
 * @return array Current MLS metadata keyed by RESO field name.
 */
function mlsimport_field_configuration_metadata(): array {
	$metadata = get_option( 'mlsimport_mls_metadata_mls_data', '' );
	$metadata = is_string( $metadata ) ? json_decode( $metadata, true ) : $metadata;

	return is_array( $metadata ) ? $metadata : array();
}

/**
 * Return taxonomy destinations registered for the active property post type.
 *
 * The adapter is resolved on demand because metadata gathering and AJAX calls
 * run after the plugin has created its active theme environment.
 *
 * @return array Taxonomy slug-to-label map accepted by mutation validation.
 */
function mlsimport_field_configuration_taxonomies(): array {
	global $mlsimport;

	$post_type = '';
	if ( isset( $mlsimport->admin->env_data ) && method_exists( $mlsimport->admin->env_data, 'get_property_post_type' ) ) {
		$post_type = $mlsimport->admin->env_data->get_property_post_type();
	}

	$taxonomies = mlsimport_get_custom_post_type_taxonomies( $post_type );

	return is_array( $taxonomies ) ? $taxonomies : array();
}

/**
 * Atomically replace the one Field Configuration option.
 *
 * WordPress's update_option() has no expected-value condition, so two tabs can
 * both pass an application-level revision check and the slower request can
 * overwrite the newer one. The UPDATE below includes the exact serialized old
 * value in its WHERE clause. One request wins; the other updates zero rows and
 * is reported by the module as stale. Cache and public option hooks are updated
 * once only after the database confirms the replacement.
 *
 * @param array $expected    Exact option value previously loaded.
 * @param array $replacement Complete normalized replacement.
 * @return bool True only when this caller won the compare-and-swap.
 */
function mlsimport_field_configuration_compare_and_swap( array $expected, array $replacement ): bool {
	global $wpdb;

	$option_name = 'mlsimport_admin_fields_select';
	$row         = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$option_name
		),
		ARRAY_A
	);

	// add_option() uses the option_name unique key as the atomic first-write gate.
	if ( null === $row ) {
		if ( array() !== $expected ) {
			return false;
		}

		$added = add_option( $option_name, $replacement, '', false );
		if ( ! $added ) {
			wp_cache_delete( $option_name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		} elseif ( function_exists( 'mlsimport_active_field_configuration' ) ) {
			mlsimport_active_field_configuration( true );
		}

		return $added;
	}

	$old_serialized = maybe_serialize( $expected );
	$new_serialized = maybe_serialize( $replacement );
	$updated        = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
			$new_serialized,
			$option_name,
			$old_serialized
		)
	);
	if ( 1 !== $updated ) {
		// Another request won after this process loaded its option. Discard every
		// local option-cache route so the module can report the winner's revision.
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return false;
	}

	// Mirror update_option() cache behavior while preserving the existing
	// autoload choice. Large 1,000-field configurations are not newly autoloaded.
	$alloptions = wp_load_alloptions( true );
	if ( isset( $alloptions[ $option_name ] ) ) {
		$alloptions[ $option_name ] = $new_serialized;
		wp_cache_set( 'alloptions', $alloptions, 'options' );
	} else {
		wp_cache_set( $option_name, $new_serialized, 'options' );
	}

	mlsimport_active_field_configuration( true );
	do_action( "update_option_{$option_name}", $expected, $replacement, $option_name );
	do_action( 'updated_option', $option_name, $expected, $replacement );

	return true;
}

/**
 * Construct the authoritative module around the WordPress option store.
 *
 * The loader always returns an array and the writer delegates to the exact
 * compare-and-swap adapter above. Each request gets a small stateless service;
 * all durable state remains in the one backward-compatible WordPress option.
 *
 * @return Mlsimport_Field_Configuration Configured domain service.
 */
function mlsimport_field_configuration(): Mlsimport_Field_Configuration {
	return new Mlsimport_Field_Configuration(
		static function () {
			$value = get_option( 'mlsimport_admin_fields_select', array() );
			return is_array( $value ) ? $value : array();
		},
		'mlsimport_field_configuration_compare_and_swap'
	);
}

/**
 * Return normalized durable state for consumers that must remove dormant data.
 *
 * Theme custom-field registries need both active fields to add and dormant
 * fields to remove from theme-owned display definitions. This read still enters
 * through the module, preserving normalization and taxonomy rules without
 * exposing a direct option read as an alternate persistence boundary.
 *
 * @return array Normalized active-and-dormant Field Configuration.
 */
function mlsimport_normalized_field_configuration(): array {
	return mlsimport_field_configuration()->read(
		mlsimport_field_configuration_metadata(),
		mlsimport_hardocde_theme_schema(),
		mlsimport_field_configuration_taxonomies()
	);
}

/**
 * Return the active-only configuration for import and display consumers.
 *
 * The durable option retains Dormant MLS Fields so their choices can return.
 * Runtime consumers use this projection to exclude those fields consistently
 * without each theme reimplementing metadata intersection and array ordering.
 *
 * The projection is cached for the request because import adapters consult it
 * once per listing. Rebuilding and sorting 1,000 parallel fields for every
 * listing would turn schema safety into an avoidable import bottleneck.
 *
 * @param bool $refresh Rebuild after this request has changed the option.
 * @return array Normalized configuration containing current metadata fields only.
 */
function mlsimport_active_field_configuration( bool $refresh = false ): array {
	static $configuration = null;

	if ( null !== $configuration && ! $refresh ) {
		return $configuration;
	}

	$configuration = mlsimport_field_configuration()->read_active(
		mlsimport_field_configuration_metadata(),
		mlsimport_hardocde_theme_schema(),
		mlsimport_field_configuration_taxonomies()
	);

	return $configuration;
}

/**
 * Persist metadata initialization/reconciliation once on the server.
 *
 * @param array $metadata     Newly gathered MLS metadata.
 * @param array $theme_schema Active theme defaults.
 * @return array Field Configuration Result.
 */
function mlsimport_reconcile_field_configuration( array $metadata, array $theme_schema ): array {
	return mlsimport_field_configuration()->reconcile( $metadata, $theme_schema, mlsimport_field_configuration_taxonomies() );
}

/**
 * Import an exported configuration through the same schema and storage owner.
 *
 * @param array $incoming Exported legacy-compatible option array.
 * @return array Field Configuration Result.
 */
function mlsimport_import_field_configuration( array $incoming ): array {
	return mlsimport_field_configuration()->import_configuration(
		$incoming,
		mlsimport_field_configuration_metadata(),
		mlsimport_hardocde_theme_schema(),
		mlsimport_field_configuration_taxonomies()
	);
}

/**
 * Handle the sole browser Field Configuration mutation endpoint.
 *
 * Security and request-shape validation happen before decoding the compact
 * command. The module then validates domain rules and either returns an
 * authoritative saved result or a stable error code used by the queue UI.
 *
 * The handler terminates through WordPress JSON helpers. It intentionally has
 * no return type because those helpers stop execution after sending a response.
 */
function mlsimport_ajax_change_field_configuration() {
	check_ajax_referer( 'mlsimport_field_selector_nonce', 'security' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'error' => array( 'code' => 'forbidden', 'message' => 'You are not allowed to change Field Configuration.' ) ), 403 );
	}

	if ( ! isset( $_POST['revision'], $_POST['command'] ) || ! is_scalar( $_POST['revision'] ) || ! is_scalar( $_POST['command'] ) ) {
		wp_send_json_error( array( 'error' => array( 'code' => 'invalid_request', 'message' => 'Revision and command are required.' ) ), 400 );
	}

	$revision = max( 0, (int) wp_unslash( $_POST['revision'] ) );
	$command  = json_decode( wp_unslash( (string) $_POST['command'] ), true );
	if ( ! is_array( $command ) ) {
		wp_send_json_error( array( 'error' => array( 'code' => 'invalid_json', 'message' => 'The Field Configuration command is not valid JSON.' ) ), 400 );
	}

	$result = mlsimport_field_configuration()->change(
		$revision,
		$command,
		mlsimport_field_configuration_metadata(),
		mlsimport_field_configuration_taxonomies(),
		mlsimport_hardocde_theme_schema()
	);
	if ( ! $result['success'] ) {
		$status = 'stale_revision' === $result['error']['code'] ? 409 : ( 'persistence_failed' === $result['error']['code'] ? 500 : 422 );
		wp_send_json_error( $result, $status );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_mlsimport_change_field_configuration', 'mlsimport_ajax_change_field_configuration' );
