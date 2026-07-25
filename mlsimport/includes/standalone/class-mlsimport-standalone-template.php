<?php
/**
 * Standalone (theme_id 990) template loader.
 *
 * Resolves a template name to the active theme's mlsimport/ override (child then
 * parent theme, via locate_template) if present, else the plugin's bundled
 * templates/ directory — the WooCommerce override pattern, so any theme can
 * restyle the front-end without editing the plugin.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves standalone front-end templates, theme-overridable.
 */
class Mlsimport_Standalone_Template {

	/**
	 * Absolute path to the template to use for $name.
	 *
	 * @param string $name Template filename (e.g. 'card.php' or 'parts/map.php').
	 * @return string
	 */
	public static function locate( string $name ): string {
		// Normalize to a relative name (a leading slash would break the concatenation).
		$name = ltrim( $name, '/' );
		/** Filter the template filename before lookup. @since 6.3 */
		$name = (string) apply_filters( 'mlsimport_template_name', $name );
		/** Filter the theme override sub-directory. @since 6.3 */
		$subdir = (string) apply_filters( 'mlsimport_template_subdir', 'mlsimport/' );

		// Prefer the active theme's mlsimport/ override (child then parent); if the
		// theme ships none, fall back to the plugin's bundled templates/ copy.
		$override = locate_template( array( $subdir . $name ) );
		$path     = ( '' !== $override ) ? $override : self::default_dir() . $name;

		/** Filter the resolved template path (full override). @since 6.3 */
		return (string) apply_filters( 'mlsimport_locate_template', $path, $name, $subdir );
	}

	/**
	 * The plugin's bundled templates directory (with trailing slash).
	 *
	 * @return string
	 */
	public static function default_dir(): string {
		return dirname( __DIR__, 2 ) . '/templates/';
	}
}

/**
 * get_header() that stays silent when the active theme has no header.php.
 *
 * WordPress 6.x emits a deprecation notice when get_header() runs against a
 * theme without header.php; bundled standalone templates call this instead so a
 * header-less theme doesn't fill the log with notices.
 *
 * @param string|null $name Optional specialised header name.
 * @return void
 */
function mlsimport_get_header( $name = null ): void {
	// Only call get_header() when the theme actually provides a header.php.
	if ( '' !== locate_template( 'header.php' ) ) {
		get_header( $name );
	}
}

/**
 * get_footer() that stays silent when the active theme has no footer.php.
 *
 * @param string|null $name Optional specialised footer name.
 * @return void
 */
function mlsimport_get_footer( $name = null ): void {
	// Only call get_footer() when the theme actually provides a footer.php.
	if ( '' !== locate_template( 'footer.php' ) ) {
		get_footer( $name );
	}
}
