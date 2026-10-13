<?php
/**
 * AI assistant access (MCP): the two copies of the MCP Adapter.
 *
 * WHAT THIS FILE DOES
 * -------------------
 * PHP can hold only one copy of the MCP Adapter classes. This file has the
 * two small helpers the bootstrap needs to deal with that:
 *   1. the loader of OUR bundled copy (vendor/wordpress/), used when no other
 *      plugin loaded the adapter;
 *   2. the reader of the version of a copy ANOTHER plugin already loaded, so
 *      the bootstrap can decide whether that copy is the one we tested.
 *
 * Why the version is read from a file: the adapter defines its version
 * constant (WP_MCP_VERSION) only in its main plugin file, mcp-adapter.php. A
 * plugin that bundles the adapter and loads it with an autoloader (Elementor
 * does) never runs that file, so the constant does not exist. The version is
 * then read from the mcp-adapter.php that sits beside the loaded classes.
 *
 * Loaded by mlsimport-mcp-bootstrap.php on every request.
 *
 * Public interface:
 *   mlsimport_mcp_autoload()                 loader of the bundled copy
 *   mlsimport_mcp_adapter_version_of()       version of the copy a class file belongs to
 *   mlsimport_mcp_loaded_adapter_version()   version of the copy loaded right now
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the bundled MCP Adapter classes on demand (PSR-4, two packages).
 *
 * Registered only when the bundled copy is used.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
function mlsimport_mcp_autoload( string $class ): void {
	$packages = array(
		'WP\\MCP\\'       => MLSIMPORT_PLUGIN_PATH . 'vendor/wordpress/mcp-adapter/includes/',
		'WP\\McpSchema\\' => MLSIMPORT_PLUGIN_PATH . 'vendor/wordpress/php-mcp-schema/src/',
	);
	foreach ( $packages as $prefix => $directory ) {
		if ( 0 !== strpos( $class, $prefix ) ) {
			continue;
		}
		$file = $directory . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
		return;
	}
}

/**
 * Read the version of the adapter copy a class file belongs to.
 *
 * The adapter keeps its classes in <copy>/includes/Core/ and its main file,
 * which carries the version, in <copy>/mcp-adapter.php.
 *
 * Step by step:
 * 1. Go from includes/Core/McpAdapter.php up to the folder of the copy.
 * 2. Read the start of mcp-adapter.php there (read only, never executed).
 * 3. Return the value of its WP_MCP_VERSION line, or '' when anything is missing.
 *
 * @param string $class_file Full path of the loaded McpAdapter.php.
 * @return string The version (for example '0.6.1'), or '' when it cannot be read.
 */
function mlsimport_mcp_adapter_version_of( string $class_file ): string {
	// Step 1: the folder of the copy.
	if ( '' === $class_file ) {
		return '';
	}
	$main_file = dirname( $class_file, 3 ) . '/mcp-adapter.php';
	if ( ! is_readable( $main_file ) ) {
		return '';
	}

	// Step 2: the version is defined near the top of the file.
	$start = (string) file_get_contents( $main_file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

	// Step 3: the define( 'WP_MCP_VERSION', 'x.y.z' ) line.
	if ( preg_match( "/WP_MCP_VERSION['\"]\\s*,\\s*['\"]([0-9][0-9A-Za-z.\\-]*)['\"]/", $start, $found ) ) {
		return $found[1];
	}
	return '';
}

/**
 * The version of the adapter copy that is loaded on this request.
 *
 * Call only when the adapter class exists.
 *
 * Step by step:
 * 1. The constant, when the copy was started from its main file.
 * 2. Otherwise the main file beside the loaded class (autoloaded copies).
 *
 * @return string The version, or '' when it cannot be read.
 */
function mlsimport_mcp_loaded_adapter_version(): string {
	// Step 1: the constant.
	if ( defined( 'WP_MCP_VERSION' ) ) {
		return (string) WP_MCP_VERSION;
	}

	// Step 2: the file the loaded class came from.
	$class = new ReflectionClass( 'WP\\MCP\\Core\\McpAdapter' );
	return mlsimport_mcp_adapter_version_of( (string) $class->getFileName() );
}
