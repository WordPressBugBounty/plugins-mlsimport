<?php
/**
 * Import Task settings: the one function that stores them.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * An Import Task (post type mlsimport_item) keeps its settings as post meta:
 * how many listings to import, the title format, the price range, the filters
 * (cities, counties, statuses, property types, postal codes ...) and the
 * hourly auto-sync switch. Two callers store them and both use this file, so
 * there is one save path:
 *
 *   - the Import Task editor: Mlsimport_Admin::mlsimport_item_product_save_metaboxes()
 *     checks the request (autosave, nonce, edit rights) and passes $_POST;
 *   - the AI tool update-task (includes/mcp/mlsimport-mcp-task-write.php)
 *     passes an array of the same shape.
 *
 * The code was moved here from the editor's save handler unchanged.
 *
 * Public interface:
 *   mlsimport_task_setting_keys()                  the meta keys a save may write
 *   mlsimport_task_blank_keys()                    the keys cleared when not sent
 *   mlsimport_task_save_settings( $id, $posted )   store the settings
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every Import Task meta key a settings save may write.
 *
 * A filter "X" of the task form is stored under 'mlsimport_item_x' and, for
 * the filters that offer "Select All", its flag under 'mlsimport_item_x_check'.
 *
 * @return string[]
 */
function mlsimport_task_setting_keys(): array {
	return array(
		'mlsimport_item_how_many',
		'mlsimport_item_title_format',
		'mlsimport_item_agent',
		'mlsimport_item_use_mls_agent',
		'mlsimport_item_property_status',
		'mlsimport_item_property_user',
		'mlsimport_item_min_price',
		'mlsimport_item_max_price',
		'mlsimport_item_city_check',
		'mlsimport_item_city',
		'mlsimport_item_city[]',
		'mlsimport_item_countyorparish_check',
		'mlsimport_item_countyorparish',
		'mlsimport_item_country_check',
		'mlsimport_item_country',
		'mlsimport_item_mlsstatus_check',
		'mlsimport_item_mlsstatus',
		'mlsimport_item_propertysubtype_check',
		'mlsimport_item_propertysubtype',
		'mlsimport_item_propertytype_check',
		'mlsimport_item_propertytype',
		'mlsimport_item_standardstatus_check',
		'mlsimport_item_standardstatus',
		'mlsimport_item_standardstatusprotect_check',
		'mlsimport_item_standardstatusprotect',
		'mlsimport_item_internetentirelistingdisplayyn',
		'mlsimport_item_internetaddressdisplayyn',
		'mlsimport_item_stat_cron',
		'mlsimport_item_listagentkey',
		'mlsimport_item_listagentmlsid',
		'mlsimport_item_buyeragentmlsid',
		'mlsimport_item_listofficekey',
		'mlsimport_item_postalcode',
		'mlsimport_item_listofficemlsid',
		'mlsimport_item_listingid',
		'mlsimport_item_listingkey',
		'mlsimport_item_extracity',
		'mlsimport_item_extracounty',
		'mlsimport_item_exclude_listofficemlsid',
		'mlsimport_item_exclude_listofficekey',
		'mlsimport_item_exclude_listagentmlsid',
		'mlsimport_item_exclude_listagentkey',
		'mlsimport_item_customparameters',
		'mlsimport_item_mlsareamajor',
		'mlsimport_item_subdivisionname',
	);
}

/**
 * The keys that are reset to '' when a save does not send them.
 *
 * A browser sends nothing for an empty multi-select or an unticked checkbox,
 * so "not sent" means "cleared" for these keys.
 *
 * @return string[]
 */
function mlsimport_task_blank_keys(): array {
	return array(
		'mlsimport_item_use_mls_agent',
		'mlsimport_item_standardstatus',
		'mlsimport_item_standardstatusprotect',
		'mlsimport_item_city',
		'mlsimport_item_countyorparish',
		'mlsimport_item_country',
		'mlsimport_item_propertysubtype',
		'mlsimport_item_propertytype',
		'mlsimport_item_listingid',
		'mlsimport_item_listingkey',
		'mlsimport_item_customparameters',
		'mlsimport_item_mlsareamajor',
		'mlsimport_item_subdivisionname',
	);
}

/**
 * Store the settings of one Import Task.
 *
 * The caller has already decided that the save is allowed (the editor checks
 * the nonce and edit rights; an AI tool checks the administrator capability).
 *
 * Step by step:
 * 1. Connection binding (#277): stamp the task's MLS once, at creation. The
 *    helper is immutable for a bound task, so a sent value can never re-bind
 *    it; that is why the MLS id is not one of the generic keys below.
 * 2. Store each setting that was sent, sanitized (arrays recursively).
 * 3. Reset to '' every "blank key" that was not sent, so a cleared selection
 *    really clears.
 * 4. Status is the one filter that must never end up empty: the stored listing
 *    writer accepts a listing only when its status is in this list, so an
 *    empty list skips every listing (and deletes the stored ones) while the
 *    run still reports them as saved. The editor shows Active when nothing is
 *    saved, so an empty selection (key not sent, or sent empty) is stored as
 *    Active: what the screen shows is what the writer reads.
 *
 * @param int   $post_id Import Task post id.
 * @param array $posted  The settings, keyed like the task form's fields
 *                       ('mlsimport_item_city' => array( ... ), ...).
 * @return void
 */
function mlsimport_task_save_settings( int $post_id, array $posted ): void {
	// Step 1: bind the task to its MLS Connection (first save only).
	mlsimport_bind_task_connection(
		$post_id,
		isset( $posted['mlsimport_item_mls_id'] ) ? (int) $posted['mlsimport_item_mls_id'] : 0
	);

	// Step 2: store each sent key (recursively sanitized; key sanitized too).
	foreach ( mlsimport_task_setting_keys() as $key ) {
		if ( isset( $posted[ $key ] ) ) {
			update_post_meta( $post_id, sanitize_key( $key ), mlsimport_sanitize_multi_dimensional_array( $posted[ $key ] ) );
		}
	}

	// Step 3: reset every blank key that was not sent.
	foreach ( mlsimport_task_blank_keys() as $key ) {
		if ( ! isset( $posted[ $key ] ) ) {
			update_post_meta( $post_id, $key, '' );
		}
	}

	// Step 4: an empty Status selection is stored as Active (see above).
	if ( empty( $posted['mlsimport_item_standardstatus'] ) ) {
		update_post_meta( $post_id, 'mlsimport_item_standardstatus', array( 'Active' ) );
	}
}
