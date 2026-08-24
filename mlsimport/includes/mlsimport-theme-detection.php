<?php
/**
 * Which supported mode is this site running in?
 *
 * The plugin writes imported listings through one adapter per supported theme,
 * selected by a numeric theme id (see Mlsimport_Stored_Listing_Adapter_Factory):
 *
 *   990 - Standalone: the plugin's own CPTs/taxonomies, works with ANY theme.
 *   991 - WpResidence   992 - Houzez   993 - Real Homes   994 - Wpestate
 *
 * That id is stored in mlsimport_admin_options['mlsimport_theme_used'] and is
 * normally picked by the user in the setup wizard. This file answers the same
 * question BEFORE the user has picked anything, so the wizard can stop asking
 * for information the site already knows and stop treating an unrecognised
 * theme as a broken system (issue #243).
 *
 * The key point: there is no "unsupported" outcome. A site whose theme is not
 * one of the four real-estate themes is not deficient - it is a standalone
 * site, which is a first-class shipped mode.
 *
 * @link       https://mlsimport.com/
 * @since      7.0.0
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map of parent-theme directory slugs to their supported theme id.
 *
 * Keyed on the DIRECTORY slug (what get_template() returns), not on the theme's
 * display Name: the directory is stable, while the Name is editable in
 * style.css and is routinely changed by whitelabel resellers. Using the parent
 * directory also means child themes ("houzez-child") resolve to their parent
 * for free, because get_template() always returns the parent.
 *
 * @return array<string,int> Lowercase theme directory slug => theme id.
 */
function mlsimport_theme_directory_map() {
	return array(
		'wpresidence' => 991,
		'houzez'      => 992,
		'realhomes'   => 993,
		'wpestate'    => 994,
	);
}

/**
 * Resolve the supported theme id this site should run as.
 *
 * Step by step:
 *   1. If the user has already saved a theme in the plugin options, that choice
 *      wins outright. An explicit configuration decision is never second-guessed
 *      by detection - the saved value is what every other code path (the write
 *      adapter, standalone mode) already acts on.
 *   2. Otherwise look at the active parent theme's directory slug and match it
 *      against the four real-estate themes.
 *   3. No match means standalone (990). This is the normal, healthy outcome for
 *      the majority of sites - not a failure.
 *
 * @return int One of 990, 991, 992, 993, 994. Never anything else.
 */
function mlsimport_resolve_theme_id() {
	// Step 1: an explicit saved choice is authoritative. Only values the plugin
	// can actually write with are honoured; a stale or corrupt id falls through
	// to detection rather than routing listings to a non-existent adapter.
	$options  = get_option( 'mlsimport_admin_options' );
	$saved    = ( is_array( $options ) && isset( $options['mlsimport_theme_used'] ) ) ? intval( $options['mlsimport_theme_used'] ) : 0;
	$supported = array( 990, 991, 992, 993, 994 );

	if ( in_array( $saved, $supported, true ) ) {
		return $saved;
	}

	// Step 2: no saved choice - detect from the active parent theme directory.
	$slug = strtolower( (string) get_template() );
	$map  = mlsimport_theme_directory_map();

	if ( isset( $map[ $slug ] ) ) {
		return $map[ $slug ];
	}

	// Step 3: anything else is a standalone site.
	return 990;
}

/**
 * The theme's name as the wizard drops it into a sentence.
 *
 * Three later steps ("connect your %s website", "imported properties from your
 * MLS into %s") each carried their own copy of a theme matcher just to fill in
 * this word. They share this one instead.
 *
 * Standalone deliberately does not read as its selector label, "Standalone
 * (any theme)" - that label belongs in a dropdown, not in a sentence. A
 * standalone site is a WordPress site, and every one of those sentences reads
 * correctly with that word.
 *
 * @return string Theme name for 991-994, or 'WordPress' for standalone.
 */
function mlsimport_theme_copy_label() {
	$theme_id = mlsimport_resolve_theme_id();

	// Standalone has no host theme to name.
	if ( 990 === $theme_id ) {
		return __( 'WordPress', 'mlsimport' );
	}

	return MLSIMPORT_THEME[ $theme_id ];
}

/**
 * Build the theme <select> for the mlsimport_admin_options[$key] field.
 *
 * Lives here, not with the other form helpers, because both of its callers -
 * the wizard account step and the settings page - render exactly one list: the
 * supported themes, preselected with mlsimport_resolve_theme_id(). Rendering it
 * from the raw saved option instead left NO option marked selected on a site
 * that had never answered, so the browser silently fell on the first entry,
 * 990 Standalone, and that became the saved theme (#242).
 *
 * Step by step:
 *   1. Open the select, bound to the mlsimport_admin_options[$key] field.
 *   2. Emit one <option> per supported theme, marking the one that matches the
 *      resolved id as selected.
 *   3. Close and return the markup.
 *
 * @param string $key        Option key (used as the id and the name suffix).
 * @param mixed  $value      Theme id to preselect - pass mlsimport_resolve_theme_id().
 * @param array  $data_array Map of theme id => label (MLSIMPORT_THEME).
 * @return string The rendered <select> HTML.
 */
function mlsiport_mls_select_list( $key, $value, $data_array ) {
	// Step 1 - open the select, binding it to the mlsimport_admin_options field.
	$select = '<select class="mlsimport-2025-select" id="' . esc_attr( $key ) . '" name="mlsimport_admin_options[' . $key . ']">';

	// Only build options when given an array of choices.
	if ( is_array( $data_array ) ) {
		// Step 2 - one <option> per choice. The loop uses its own variable: it
		// used to reuse $key and overwrite the field name mid-render.
		foreach ( $data_array as $theme_id => $theme_label ) {
			$select .= '<option value="' . esc_attr( $theme_id ) . '"';
			// Mark the option matching the resolved theme id as selected.
			if ( intval( $value ) === intval( $theme_id ) ) {
				$select .= ' selected ';
			}
			$select .= '>' . esc_html( $theme_label ) . '</option>';
		}
	}

	// Step 3 - close the select and return the assembled markup.
	$select .= '</select>';
	return $select;
}
