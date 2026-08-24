<?php
/**
 * Unsupported Provider Family result.
 *
 * The public Provider Family module returns this object when a saved provider
 * type is unknown. Returning a normal result object lets every caller show the
 * same safe error without catching exceptions or silently using Bridge rules.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only adapter-shaped result for an unsupported saved provider type.
 */
class UnsupportedResoClass extends ResoBase {
	/** @var bool Unknown providers cannot execute Direct MLS requests. */
	protected $direct_access_supported = false;

	/** @var string The unrecognized saved provider type. */
	private $unsupported_type;

	/**
	 * Store the invalid type so the error identifies the broken configuration.
	 *
	 * @param string $unsupported_type Unrecognized provider type from saved config.
	 */
	public function __construct( $unsupported_type ) {
		$this->unsupported_type = (string) $unsupported_type;
	}

	/**
	 * Return the exact unrecognized type for logs and diagnostics.
	 *
	 * @return string
	 */
	public function type() {
		return $this->unsupported_type;
	}

	/**
	 * Report that requests cannot be made with this adapter.
	 *
	 * @return bool
	 */
	public function supported() {
		return false;
	}

	/**
	 * Return a stable safe error for admin screens and request callers.
	 *
	 * @return array{code:string,message:string}
	 */
	public function error() {
		return array(
			'code'    => 'unsupported_provider',
			'message' => 'This MLS provider type is not supported.',
		);
	}
}
