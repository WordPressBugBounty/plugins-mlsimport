<?php
/**
 * The Tools tab actions as plain functions.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The Tools tab (MLSImport > Settings > Tools) lets an administrator switch
 * system logs and property history on or off, pick the theme in use, clear
 * the plugin's cached data, clear the field data, and delete the imported
 * properties of chosen taxonomy terms. That work used to sit inside the
 * tab's form handler and inside AJAX handlers, which read $_POST and print
 * their answer. It lives here now, as functions that take plain values and
 * return plain values, so two callers share one copy of each action:
 *
 *   - the Tools tab: admin/partials/mlsimport-administrative-options.php and
 *     the AJAX handlers in admin/class-mlsimport-admin.php check the nonce
 *     and the capability, then call these functions;
 *   - the AI tools (includes/mcp/mlsimport-mcp-tools-tab.php and
 *     mlsimport-mcp-destructive.php) check the administrator capability, then
 *     call the same functions.
 *
 * Nothing here checks permissions or reads the request. The code was moved
 * from the handlers unchanged.
 *
 * Public interface:
 *   mlsimport_tools_switches()                          the three switches as saved
 *   mlsimport_tools_save_switches( $switches )          save the named switches
 *   mlsimport_tools_clear_cache()                       drop the plugin's cached data
 *   mlsimport_tools_clear_fields_data( $mls_id )        forget one connection's field data
 *   mlsimport_tools_taxonomy_terms( $taxonomy )         the terms of one taxonomy
 *   mlsimport_tools_properties_by_term( ... )           the properties of chosen terms
 *   mlsimport_tools_delete_properties_batch( ... )      delete up to 5 of them
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three Tools tab switches as they are saved.
 *
 * @return array{logs:int, history:int, theme:int} logs: 1 = system logs on
 *         (default off); history: 1 = property history on (default on);
 *         theme: the theme id in use (saved choice, else the detected theme,
 *         else standalone 990).
 */
function mlsimport_tools_switches(): array {
	return array(
		'logs'    => intval( get_option( 'mlsimport_disable_logs' ) ),
		'history' => intval( get_option( 'mlsimport-disable-history', 1 ) ),
		'theme'   => mlsimport_resolve_theme_id(),
	);
}

/**
 * Save the Tools tab switches that are named.
 *
 * Step by step:
 * 1. 'logs' (0 / 1): the system-logs switch.
 * 2. 'history' (0 / 1): the property-history switch.
 * 3. 'theme' (a theme id): merged as the single key mlsimport_theme_used into
 *    mlsimport_admin_options, so every other stored value survives untouched.
 * A switch that is not in $switches keeps its value.
 *
 * @param array $switches Any of: logs, history, theme.
 * @return void
 */
function mlsimport_tools_save_switches( array $switches ): void {
	// Step 1: system logs.
	if ( isset( $switches['logs'] ) ) {
		update_option( 'mlsimport_disable_logs', intval( $switches['logs'] ) );
	}
	// Step 2: property history.
	if ( isset( $switches['history'] ) ) {
		update_option( 'mlsimport-disable-history', intval( $switches['history'] ) );
	}
	// Step 3: the theme in use.
	if ( isset( $switches['theme'] ) ) {
		$mlsimport_admin_options = get_option( 'mlsimport_admin_options', array() );
		$mlsimport_admin_options = is_array( $mlsimport_admin_options ) ? $mlsimport_admin_options : array();
		$mlsimport_admin_options['mlsimport_theme_used'] = intval( $switches['theme'] );
		update_option( 'mlsimport_admin_options', $mlsimport_admin_options );
	}
}

/**
 * Clear all MLSImport caches/transients and the metadata-populated flag,
 * forcing the next request to re-fetch everything.
 *
 * @return void
 */
function mlsimport_tools_clear_cache(): void {
	// Drop every cached token/metadata/schema transient.
	delete_transient( 'mlsimport_token_request' );
	delete_transient( 'mlsimport_metadata_api_call_data_service_property' );
	delete_transient( 'mls_import_meta_enums' );
	delete_transient( 'mls_import_meta' );
	delete_transient( 'mlsimport_plugin_data_schema' );
	delete_transient( 'mlsimport_ready_to_go_mlsimport_data' );
	delete_transient( 'mlsimport_saas_token' );

	// Force a fresh metadata pull next load (current connection only, #275).
	mlsimport_delete_connection_option( 'mlsimport_mls_metadata_populated' );
}

/**
 * Reset one connection's field-mapping configuration so the field selector
 * starts fresh (also clears its metadata-populated flag).
 *
 * Only that connection is touched (#275) - other connections' mappings stay
 * isolated.
 *
 * @param int $mls_id The connection; 0 = the current connection (the Tools
 *                    tab button).
 * @return void
 */
function mlsimport_tools_clear_fields_data( int $mls_id = 0 ): void {
	mlsimport_delete_connection_option( 'mlsimport_mls_metadata_populated', $mls_id );
	mlsimport_delete_connection_option( 'mlsimport_admin_fields_select', $mls_id );
}

/**
 * The terms of one taxonomy, for the "delete properties by term" picker.
 *
 * @param string $taxonomy An existing taxonomy.
 * @return array<int, array{slug:string, name:string, count:int}> All terms
 *         (including empty ones), by name.
 */
function mlsimport_tools_taxonomy_terms( string $taxonomy ): array {
	// Fetch all terms (including empties) and flatten to id/slug/name/count.
	// The id is what the page blocks' term pickers store (#372).
	$terms  = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ) );
	$result = array();
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$result[] = array(
				'id'    => (int) $term->term_id,
				'slug'  => $term->slug,
				'name'  => $term->name,
				'count' => $term->count,
			);
		}
	}
	return $result;
}

/**
 * One page of the property ids that carry any of the chosen terms.
 *
 * @param string   $taxonomy Taxonomy of the terms.
 * @param string[] $terms    Term slugs.
 * @param int      $per_page How many ids to fetch.
 * @return WP_Query The query; ->posts holds the ids, ->found_posts the total.
 */
function mlsimport_tools_properties_by_term( string $taxonomy, array $terms, int $per_page ): WP_Query {
	global $mlsimport;

	return new WP_Query(
		array(
			'post_type'      => $mlsimport->admin->env_data->get_property_post_type(),
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $terms,
				),
			),
			'fields'         => 'ids',
		)
	);
}

/**
 * Delete imported properties matching the chosen taxonomy terms, 5 at a time.
 *
 * The caller repeats the call until 'done' is true.
 *
 * Why 5: deleting one listing also removes every photo and all its thumbnail
 * sizes from disk, which on managed hosting can take over 10 seconds per
 * listing. A page of 20 then ran close to 4 minutes and the host killed the
 * request before it answered (GoDaddy, 502 via Cloudflare); the listings were
 * gone but the browser saw an error and stopped. 5 keeps a request around a
 * minute on such a host, and a fast host simply makes a few more requests.
 *
 * Step by step:
 * 1. Query one page (5) of property ids matching the term selection.
 * 2. Delete each property in this batch via the theme importer's SQL delete.
 * 3. Compute how many still match after this batch; done when none remain.
 * 4. Update term counts only when all deletions are complete.
 *
 * @param string   $taxonomy Taxonomy of the terms.
 * @param string[] $terms    Term slugs.
 * @return array{deleted:int, remaining:int, total:int, done:bool}
 */
function mlsimport_tools_delete_properties_batch( string $taxonomy, array $terms ): array {
	global $mlsimport;

	// Step 1: one page of matching property ids.
	$prop_selection = mlsimport_tools_properties_by_term( $taxonomy, $terms, 5 );
	$deleted        = 0;

	// Step 2: delete this batch.
	foreach ( $prop_selection->posts as $delete_id ) {
		$mlsimport->admin->theme_importer->mlsimportSaasDeletePropertyViaMysql( $delete_id, ' delete from tools ' );
		++$deleted;
	}

	// Step 3: what is left.
	$remaining = $prop_selection->found_posts - $deleted;
	$done      = ( $remaining <= 0 );

	// Step 4: recount every taxonomy on the property post type in one pass.
	if ( $done ) {
		$all_taxonomies = get_object_taxonomies( $mlsimport->admin->env_data->get_property_post_type() );
		foreach ( $all_taxonomies as $tax_name ) {
			$all_terms = get_terms( array( 'taxonomy' => $tax_name, 'hide_empty' => false, 'fields' => 'ids' ) );
			if ( ! is_wp_error( $all_terms ) && ! empty( $all_terms ) ) {
				wp_update_term_count_now( $all_terms, $tax_name );
			}
		}
	}

	return array(
		'deleted'   => $deleted,
		'remaining' => max( 0, $remaining ),
		'total'     => (int) $prop_selection->found_posts,
		'done'      => $done,
	);
}
