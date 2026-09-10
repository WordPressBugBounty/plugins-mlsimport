<?php
/**
 * Field-mapping connection scope resolver (per-connection field mapping UI).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The Field Configuration storage went per-connection with #275
 * (mlsimport_admin_fields_select_{mls_id}), but every UI surface stayed
 * hard-bound to the CURRENT connection: the field_options tab, its mutation
 * AJAX (mlsimport_change_field_configuration), and the metadata-gather AJAX
 * all resolved mls_id 0. With 2+ connections a user could only ever see and
 * edit the current connection's mapping.
 *
 * This file holds the ONE rule that opens those surfaces to a chosen
 * connection: a request may name a scope ($_GET['mls'] on the tab,
 * $_POST['mls_id'] on the endpoints), and it resolves the same single way
 * everywhere:
 *
 *   requested id is a registered connection  => that connection,
 *   anything else (absent, garbage, unknown) => the current connection.
 *
 * The fallback keeps every legacy caller and single-connection install on
 * exactly the pre-multi-MLS path (current resolves to 0 => flat options on an
 * unconfigured install, per mlsimport-connection-options.php).
 *
 * Pure function — no WordPress calls — so the rule is unit-testable
 * (tests/field-mapping/FieldMappingStage1FieldScopeTest.php).
 *
 * @since   7.3.0
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve which connection a field-mapping surface addresses.
 *
 * Step by step:
 * 1. Cast the raw request value (query/post string) to int; non-numeric
 *    garbage becomes 0 and can never match a registered id.
 * 2. A positive id that is one of the registered connections wins.
 * 3. Everything else resolves to the current connection — the legacy scope.
 *
 * @param mixed $requested      Raw request value ($_GET['mls'] / $_POST['mls_id']).
 * @param array $registered_ids mls_ids of the registered connections.
 * @param int   $current_id     The current connection's mls_id (0 = unconfigured).
 * @return int The mls_id to scope reads/writes to.
 */
function mlsimport_field_mapping_scope( $requested, array $registered_ids, int $current_id ): int {
	// Step 1: request values arrive as strings; garbage casts to 0.
	$requested = is_scalar( $requested ) ? (int) $requested : 0;

	// Step 2: only a registered connection can be addressed.
	if ( $requested > 0 && in_array( $requested, array_map( 'intval', $registered_ids ), true ) ) {
		return $requested;
	}

	// Step 3: the legacy scope — current connection.
	return $current_id;
}

/**
 * Resolve a request's field-mapping scope against the live registry.
 *
 * Thin WordPress wrapper so the three call sites (tab partial, mutation AJAX,
 * gather AJAX) resolve identically without repeating the registry/current
 * lookups. Requires WordPress; the rule itself lives in the pure function.
 *
 * @param mixed $requested Raw request value ($_GET['mls'] / $_POST['mls_id']).
 * @return int The mls_id to scope reads/writes to.
 */
function mlsimport_field_mapping_request_scope( $requested ): int {
	return mlsimport_field_mapping_scope(
		$requested,
		array_keys( Mlsimport_Connections::all() ),
		mlsimport_current_mls_id()
	);
}
