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
 * Decode one connection's MLS metadata into the domain module's field map.
 *
 * Metadata can be stored as the original JSON string or as an already decoded
 * array. Both shapes are accepted here; malformed or absent metadata becomes
 * an empty map so every caller reaches the same normalization path. Since
 * multi-MLS (#275) the blob is per-connection: the resolver returns the
 * "_{mls_id}"-suffixed option for the given (or current) connection.
 *
 * @param int $mls_id Connection whose metadata to read; 0 = current connection.
 * @return array That connection's MLS metadata keyed by RESO field name.
 */
function mlsimport_field_configuration_metadata( int $mls_id = 0 ): array {
	$metadata = mlsimport_get_connection_option( 'mlsimport_mls_metadata_mls_data', '', $mls_id );
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
 * @param array  $expected    Exact option value previously loaded.
 * @param array  $replacement Complete normalized replacement.
 * @param string $option_name Concrete (per-connection) option to swap; ''
 *                            resolves the current connection's option (#275).
 * @return bool True only when this caller won the compare-and-swap.
 */
function mlsimport_field_configuration_compare_and_swap( array $expected, array $replacement, string $option_name = '' ): bool {
	global $wpdb;

	if ( '' === $option_name ) {
		$option_name = mlsimport_connection_option_name( 'mlsimport_admin_fields_select' );
	}
	$row = $wpdb->get_row(
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
		} else {
			if ( function_exists( 'mlsimport_active_field_configuration' ) ) {
				mlsimport_active_field_configuration( true );
			}
			// The theme-adapter resync listens on the BASE option's hook name
			// (loader binds add/update_option_mlsimport_admin_fields_select).
			// Per-connection storage (#275) uses a suffixed option name, so
			// WordPress fires "add_option_{$option_name}" instead — announce the
			// field-selection change on the base name explicitly.
			if ( 'mlsimport_admin_fields_select' !== $option_name ) {
				do_action( 'update_option_mlsimport_admin_fields_select', $expected, $replacement, $option_name );
			}
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
	// Announce on the BASE hook name: the theme-adapter resync listener is
	// bound to update_option_mlsimport_admin_fields_select regardless of which
	// connection's suffixed option (#275) actually stored the change.
	do_action( 'update_option_mlsimport_admin_fields_select', $expected, $replacement, 'mlsimport_admin_fields_select' );
	do_action( 'updated_option', $option_name, $expected, $replacement );

	return true;
}

/**
 * Construct the authoritative module around one connection's option store.
 *
 * Step by step (#275 — per-connection field mapping):
 * 1. Resolve the concrete option name once: the given connection's suffixed
 *    'mlsimport_admin_fields_select_{mls_id}', or the current connection's
 *    when no mls_id is passed (every legacy call site).
 * 2. Bind BOTH the loader and the compare-and-swap writer to that one name,
 *    so load and persistence can never address different connections. The
 *    revision key and CAS semantics are unchanged — just scoped per option.
 *
 * @param int $mls_id Connection to bind to; 0 = current connection.
 * @return Mlsimport_Field_Configuration Configured domain service.
 */
function mlsimport_field_configuration( int $mls_id = 0 ): Mlsimport_Field_Configuration {
	// Step 1: one resolution, shared by both storage callbacks.
	$option_name = mlsimport_connection_option_name( 'mlsimport_admin_fields_select', $mls_id );

	// Step 2: loader and CAS are closures over the same resolved name.
	return new Mlsimport_Field_Configuration(
		static function () use ( $option_name ) {
			$value = get_option( $option_name, array() );
			return is_array( $value ) ? $value : array();
		},
		static function ( array $expected, array $replacement ) use ( $option_name ) {
			return mlsimport_field_configuration_compare_and_swap( $expected, $replacement, $option_name );
		}
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
 * listing would turn schema safety into an avoidable import bottleneck. The
 * cache is keyed by connection (#277 — a task-bound import may read another
 * connection's projection); a refresh drops EVERY cached projection so the
 * existing compare-and-swap invalidation stays a single call.
 *
 * @param bool $refresh Rebuild after this request has changed the option.
 * @param int  $mls_id  Connection to read; 0 = the current connection.
 * @return array Normalized configuration containing current metadata fields only.
 */
function mlsimport_active_field_configuration( bool $refresh = false, int $mls_id = 0 ): array {
	static $configurations = array();

	// One cache slot per resolved connection; 0 resolves to the current one so
	// legacy callers and task-scoped callers share a slot when they coincide.
	$slot = $mls_id > 0 ? $mls_id : mlsimport_current_mls_id();

	if ( $refresh ) {
		$configurations = array();
	}
	if ( isset( $configurations[ $slot ] ) ) {
		return $configurations[ $slot ];
	}

	$configurations[ $slot ] = mlsimport_field_configuration( $mls_id )->read_active(
		mlsimport_field_configuration_metadata( $mls_id ),
		mlsimport_hardocde_theme_schema(),
		mlsimport_field_configuration_taxonomies()
	);

	return $configurations[ $slot ];
}

/**
 * Persist metadata initialization/reconciliation once on the server.
 *
 * @param array $metadata     Newly gathered MLS metadata.
 * @param array $theme_schema Active theme defaults.
 * @param int   $mls_id       Connection to reconcile; 0 = current connection.
 * @return array Field Configuration Result.
 */
function mlsimport_reconcile_field_configuration( array $metadata, array $theme_schema, int $mls_id = 0 ): array {
	return mlsimport_field_configuration( $mls_id )->reconcile( $metadata, $theme_schema, mlsimport_field_configuration_taxonomies() );
}

/**
 * Import an exported configuration through the same schema and storage owner.
 *
 * The target connection's own metadata blob drives normalization; fields the
 * blob does not know become dormant until that MLS's metadata is gathered.
 *
 * @param array $incoming Exported legacy-compatible option array.
 * @param int   $mls_id   Connection to import into; 0 = current connection.
 * @return array Field Configuration Result.
 */
function mlsimport_import_field_configuration( array $incoming, int $mls_id = 0 ): array {
	return mlsimport_field_configuration( $mls_id )->import_configuration(
		$incoming,
		mlsimport_field_configuration_metadata( $mls_id ),
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

	// Per-connection scope: the tab posts the mls_id it was rendered for; a
	// request without one (legacy) resolves to the current connection.
	$mls_id = mlsimport_field_mapping_request_scope( isset( $_POST['mls_id'] ) && is_scalar( $_POST['mls_id'] ) ? wp_unslash( $_POST['mls_id'] ) : null );

	$result = mlsimport_field_configuration( $mls_id )->change(
		$revision,
		$command,
		mlsimport_field_configuration_metadata( $mls_id ),
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
