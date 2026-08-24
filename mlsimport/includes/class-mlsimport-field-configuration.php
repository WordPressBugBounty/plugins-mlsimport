<?php
/**
 * Authoritative Field Configuration domain module.
 *
 * This module hides the legacy `mlsimport_admin_fields_select` parallel-array
 * schema behind a small public interface. Reads normalize malformed historical
 * values and reconcile the current MLS metadata in memory. Later methods in
 * this class own atomic commands and persistence through the injected
 * compare-and-swap boundary, allowing WordPress and isolated tests to use the
 * same state-transition rules.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own Field Configuration normalization, reconciliation, and mutations.
 */
final class Mlsimport_Field_Configuration {

	/** Revision key stored beside the backward-compatible parallel arrays. */
	public const REVISION_KEY = '_mlsimport_field_revision';

	/** @var callable Loads the currently persisted option array. */
	private $load;

	/** @var callable Atomically replaces an expected option array. */
	private $compare_and_swap;

	/**
	 * Create the module around a storage boundary.
	 *
	 * The load callback returns the saved option. The compare-and-swap callback
	 * receives the exact old and new arrays and returns true only when the old
	 * value was still current. Keeping storage at this boundary makes stale-write
	 * behavior testable without coupling domain tests to WordPress internals.
	 *
	 * @param callable $load             Current-value loader.
	 * @param callable $compare_and_swap Atomic persistence callback.
	 */
	public function __construct( callable $load, callable $compare_and_swap ) {
		$this->load             = $load;
		$this->compare_and_swap = $compare_and_swap;
	}

	/**
	 * Return the normalized configuration for the current metadata without saving.
	 *
	 * Processing is deliberately side-effect free: first the legacy schema is
	 * repaired in memory, then missing metadata fields receive initial-setup
	 * defaults, active fields keep their order, and dormant fields remain stored
	 * after the active sequence. Rendering can therefore call this method safely.
	 *
	 * @param array $metadata     Current MLS field map keyed by RESO field name.
	 * @param array $theme_schema Theme defaults keyed by RESO field name.
	 * @param array $taxonomies Active taxonomy slug-to-label map; null skips registry cleanup.
	 * @return array Normalized legacy-compatible option array.
	 */
	public function read( array $metadata, array $theme_schema = array(), $taxonomies = null ): array {
		$stored = call_user_func( $this->load );

		return $this->reconciled_configuration( $stored, $metadata, $theme_schema, $taxonomies );
	}

	/**
	 * Return the normalized projection safe for runtime import and rendering.
	 *
	 * Durable state deliberately retains Dormant MLS Fields. Runtime consumers
	 * must not import or display them, so this projection intersects every legacy
	 * band with current metadata and re-indexes the active order in memory only.
	 *
	 * @param array $metadata     Current MLS field map keyed by RESO name.
	 * @param array $theme_schema Theme defaults keyed by RESO name.
	 * @param mixed $taxonomies   Active taxonomy slug-to-label map, or null.
	 * @return array Active-only legacy-compatible configuration.
	 */
	public function read_active( array $metadata, array $theme_schema = array(), $taxonomies = null ): array {
		$configuration = $this->read( $metadata, $theme_schema, $taxonomies );
		$active_keys   = array_values( array_intersect( array_keys( $configuration['field_order'] ), array_keys( $metadata ) ) );

		foreach ( array( 'mls-fields', 'mls-fields-admin', 'mls-fields-label', 'mls-fields-map-postmeta', 'mls-fields-map-taxonomy' ) as $band ) {
			$configuration[ $band ] = array_intersect_key( $configuration[ $band ], array_flip( $active_keys ) );
		}
		$configuration['field_order'] = array();
		foreach ( $active_keys as $index => $field_key ) {
			$configuration['field_order'][ $field_key ] = $index;
		}

		return $configuration;
	}

	/**
	 * Reconcile freshly gathered metadata and persist it as one configuration change.
	 *
	 * A byte-for-byte equivalent normalized state is a successful no-op: it does
	 * not increase the revision or invoke storage. Otherwise the complete option
	 * receives the next revision and is replaced through compare-and-swap, which
	 * prevents a concurrent administrator change from being overwritten.
	 *
	 * @param array $metadata     Current MLS field map.
	 * @param array $theme_schema Theme mapping defaults.
	 * @param array $taxonomies Active taxonomy slug-to-label map.
	 * @return array Field Configuration Result.
	 */
	public function reconcile( array $metadata, array $theme_schema = array(), array $taxonomies = array() ): array {
		$result = $this->reconcile_once( $metadata, $theme_schema, $taxonomies );

		// A lost compare-and-swap here means another writer (typically a
		// concurrent metadata gather from a second admin page) persisted first.
		// Unlike change(), reconciliation carries no user intent that could be
		// overwritten: rerun it once on top of the winner's write. Identical
		// concurrent gathers then converge to changed=false success instead of
		// a failure that would clear the metadata-populated flag.
		if ( ! $result['success'] && 'stale_revision' === ( $result['error']['code'] ?? '' ) ) {
			$result = $this->reconcile_once( $metadata, $theme_schema, $taxonomies );
		}

		return $result;
	}

	/**
	 * One reconciliation attempt against the current stored configuration.
	 *
	 * @param array $metadata     Current MLS field map.
	 * @param array $theme_schema Theme mapping defaults.
	 * @param array $taxonomies   Active taxonomy slug-to-label map.
	 * @return array Field Configuration Result.
	 */
	private function reconcile_once( array $metadata, array $theme_schema, array $taxonomies ): array {
		$stored        = call_user_func( $this->load );
		$stored        = is_array( $stored ) ? $stored : array();
		$configuration = $this->reconciled_configuration( $stored, $metadata, $theme_schema, $taxonomies );

		if ( $stored === $configuration ) {
			return array(
				'success'       => true,
				'changed'       => false,
				'revision'      => $configuration[ self::REVISION_KEY ],
				'configuration' => $configuration,
			);
		}

		$failure = $this->persist_replacement( $stored, $configuration, (int) ( $stored[ self::REVISION_KEY ] ?? 0 ) );
		if ( null !== $failure ) {
			return $failure;
		}

		return array(
			'success'       => true,
			'changed'       => true,
			'revision'      => $configuration[ self::REVISION_KEY ],
			'configuration' => $configuration,
		);
	}

	/**
	 * Replace Field Configuration from an exported settings payload.
	 *
	 * Imported revisions never cross sites: the replacement is normalized using
	 * current metadata and receives the next local revision. Active taxonomy
	 * destinations are checked before the complete replacement reaches storage.
	 *
	 * @param array $incoming     Exported legacy-compatible configuration.
	 * @param array $metadata     Current MLS field map.
	 * @param array $theme_schema Theme defaults for fields missing in the export.
	 * @param array $taxonomies   Allowed active taxonomy destinations.
	 * @return array Field Configuration Result.
	 */
	public function import_configuration( array $incoming, array $metadata, array $theme_schema, array $taxonomies ): array {
		$stored       = call_user_func( $this->load );
		$stored       = is_array( $stored ) ? $stored : array();
		$current      = $this->reconciled_configuration( $stored, $metadata, $theme_schema, $taxonomies );
		$local_revision = $current[ self::REVISION_KEY ];

		$allowed_taxonomies = array_keys( $taxonomies );
		foreach ( array_keys( $metadata ) as $field_key ) {
			$taxonomy = $this->plain_text( $incoming['mls-fields-map-taxonomy'][ $field_key ] ?? '' );
			if ( '' !== $taxonomy && ! in_array( $taxonomy, $allowed_taxonomies, true ) ) {
				return $this->error_result( 'invalid_taxonomy', 'The imported taxonomy is not available for the active property type.', $local_revision );
			}
		}
		$replacement = $this->reconciled_configuration( $incoming, $metadata, $theme_schema, $taxonomies );

		$replacement[ self::REVISION_KEY ] = $local_revision;
		if ( $stored === $replacement ) {
			return array(
				'success'       => true,
				'changed'       => false,
				'revision'      => $local_revision,
				'configuration' => $replacement,
			);
		}

		$failure = $this->persist_replacement( $stored, $replacement, $local_revision );
		if ( null !== $failure ) {
			return $failure;
		}

		return array(
			'success'       => true,
			'changed'       => true,
			'revision'      => $replacement[ self::REVISION_KEY ],
			'configuration' => $replacement,
		);
	}

	/**
	 * Apply and persist one compact Field Configuration Change.
	 *
	 * The caller supplies the revision it rendered. The module validates that
	 * revision and every command identifier before mutating a copy of the current
	 * configuration. Only the complete validated replacement reaches storage.
	 * Successful results expose domain field names rather than legacy band names.
	 *
	 * @param int   $expected_revision Browser revision that produced the command.
	 * @param array $command           Decoded compact command.
	 * @param array $metadata          Current MLS fields keyed by RESO name.
	 * @param array $taxonomies        Allowed taxonomy slugs, as keys or values.
	 * @param array $theme_schema      Initial defaults for any missing field.
	 * @return array Field Configuration Result.
	 */
	public function change( int $expected_revision, array $command, array $metadata, array $taxonomies, array $theme_schema = array() ): array {
		$stored        = call_user_func( $this->load );
		$stored        = is_array( $stored ) ? $stored : array();
		$configuration = $this->reconciled_configuration( $stored, $metadata, $theme_schema, $taxonomies );
		$revision      = $configuration[ self::REVISION_KEY ];

		if ( $expected_revision !== $revision ) {
			return $this->error_result( 'stale_revision', 'Field Configuration changed in another browser. Reload before saving.', $revision );
		}

		$type = isset( $command['type'] ) && is_scalar( $command['type'] ) ? (string) $command['type'] : '';
		if ( 'move' === $type ) {
			return $this->move_field( $stored, $configuration, $command, $metadata );
		}

		$property = isset( $command['property'] ) && is_scalar( $command['property'] ) ? (string) $command['property'] : '';
		$value    = $command['value'] ?? '';
		$band     = array(
			'import'   => 'mls-fields',
			'admin'    => 'mls-fields-admin',
			'label'    => 'mls-fields-label',
			'postmeta' => 'mls-fields-map-postmeta',
			'taxonomy' => 'mls-fields-map-taxonomy',
		)[ $property ] ?? '';
		if ( '' === $band ) {
			return $this->error_result( 'invalid_property', 'The Field Configuration property is not editable.', $revision );
		}
		if ( 'bulk' === $type && ! in_array( $property, array( 'import', 'admin' ), true ) ) {
			return $this->error_result( 'invalid_property', 'Bulk changes may edit only import or administrator visibility.', $revision );
		}

		if ( in_array( $property, array( 'import', 'admin' ), true ) ) {
			$value = $this->boolean_value( $value );
		} else {
			$value = $this->plain_text( $value );
		}

		if ( 'taxonomy' === $property && '' !== $value ) {
			$allowed_taxonomies = array_keys( $taxonomies );
			if ( ! in_array( $value, $allowed_taxonomies, true ) ) {
				return $this->error_result( 'invalid_taxonomy', 'The taxonomy is not available for the active property type.', $revision );
			}
		}

		if ( 'set' === $type ) {
			$field_keys = array( isset( $command['field'] ) && is_scalar( $command['field'] ) ? (string) $command['field'] : '' );
		} elseif ( 'bulk' === $type && isset( $command['fields'] ) && is_array( $command['fields'] ) ) {
			$field_keys = array_values( array_unique( array_map( 'strval', $command['fields'] ) ) );
		} else {
			return $this->error_result( 'invalid_command', 'Unknown Field Configuration command.', $revision );
		}

		// Validate every named field before changing the copy. This ordering is the
		// all-or-nothing boundary for bulk commands.
		foreach ( $field_keys as $field_key ) {
			if ( '' === $field_key || ! array_key_exists( $field_key, $metadata ) ) {
				return $this->error_result( 'unknown_field', 'The MLS field is not available in current metadata.', $revision );
			}
		}

		foreach ( $field_keys as $field_key ) {
			$configuration[ $band ][ $field_key ] = $value;
			if ( 'postmeta' === $property && '' !== $value ) {
				$configuration['mls-fields-map-taxonomy'][ $field_key ] = '';
			}
			if ( 'taxonomy' === $property && '' !== $value ) {
				$configuration['mls-fields-map-postmeta'][ $field_key ] = '';
			}
		}

		$changed = $configuration !== $this->reconciled_configuration( $stored, $metadata, $theme_schema, $taxonomies );
		if ( ! $changed ) {
			return $this->success_result( $configuration, $field_keys, false );
		}

		$failure = $this->persist_replacement( $stored, $configuration, $revision );
		if ( null !== $failure ) {
			return $failure;
		}

		return $this->success_result( $configuration, $field_keys, true );
	}

	/**
	 * Validate and persist one relative field move.
	 *
	 * The command identifies only the moving field, anchor field, and side. The
	 * server derives the complete order, keeps dormant fields behind active ones,
	 * and reorders every legacy parallel array together before the atomic save.
	 *
	 * @param array $stored        Exact raw value read from storage.
	 * @param array $configuration Normalized working configuration.
	 * @param array $command       Compact move command.
	 * @param array $metadata      Current active MLS fields.
	 * @return array Field Configuration Result.
	 */
	private function move_field( array $stored, array $configuration, array $command, array $metadata ): array {
		$revision = $configuration[ self::REVISION_KEY ];
		$baseline = $configuration;
		$field    = isset( $command['field'] ) && is_scalar( $command['field'] ) ? (string) $command['field'] : '';
		$anchor   = isset( $command['anchor'] ) && is_scalar( $command['anchor'] ) ? (string) $command['anchor'] : '';
		$position = isset( $command['position'] ) && is_scalar( $command['position'] ) ? (string) $command['position'] : '';

		if ( $field === $anchor || ! array_key_exists( $field, $metadata ) || ! array_key_exists( $anchor, $metadata ) ) {
			return $this->error_result( 'unknown_field', 'Both move fields must be available in current MLS metadata.', $revision );
		}
		if ( ! in_array( $position, array( 'before', 'after' ), true ) ) {
			return $this->error_result( 'invalid_position', 'A field may move only before or after its anchor.', $revision );
		}

		$ordered_keys = array_keys( $configuration['field_order'] );
		$active_keys  = array_values( array_intersect( $ordered_keys, array_keys( $metadata ) ) );
		$dormant_keys = array_values( array_diff( $ordered_keys, array_keys( $metadata ) ) );
		$active_keys  = array_values( array_diff( $active_keys, array( $field ) ) );
		$anchor_index = array_search( $anchor, $active_keys, true );
		$insert_index = 'before' === $position ? $anchor_index : $anchor_index + 1;
		array_splice( $active_keys, $insert_index, 0, array( $field ) );

		$all_keys = array_merge( $active_keys, $dormant_keys );
		$configuration['field_order'] = array();
		foreach ( $all_keys as $index => $field_key ) {
			$configuration['field_order'][ $field_key ] = $index;
		}
		$configuration = $this->order_parallel_arrays( $configuration, $all_keys );

		if ( $baseline === $configuration ) {
			$result          = $this->success_result( $configuration, array( $field ), false );
			$result['order'] = $active_keys;
			return $result;
		}

		$failure = $this->persist_replacement( $stored, $configuration, $revision );
		if ( null !== $failure ) {
			return $failure;
		}

		$result          = $this->success_result( $configuration, array( $field ), true );
		$result['order'] = $active_keys;
		return $result;
	}

	/**
	 * Rebuild every parallel legacy band in one authoritative sequence.
	 *
	 * The option schema stores the same field keys in five independent arrays.
	 * Reconstructing each band from the shared key list prevents a move from
	 * changing only one band and silently separating a field from its values.
	 *
	 * @param array $configuration Normalized Field Configuration arrays.
	 * @param array $ordered_keys  Complete active-then-dormant field sequence.
	 * @return array Configuration with all parallel arrays in the same order.
	 */
	private function order_parallel_arrays( array $configuration, array $ordered_keys ): array {
		foreach ( array( 'mls-fields', 'mls-fields-admin', 'mls-fields-label', 'mls-fields-map-postmeta', 'mls-fields-map-taxonomy' ) as $band ) {
			$ordered = array();
			foreach ( $ordered_keys as $field_key ) {
				$ordered[ $field_key ] = $configuration[ $band ][ $field_key ];
			}
			$configuration[ $band ] = $ordered;
		}

		return $configuration;
	}

	/**
	 * Build the authoritative browser result for the affected MLS fields.
	 *
	 * Legacy option band names stay inside this module. The result translates
	 * them into stable domain properties so the browser can replace optimistic
	 * input values with the exact values that actually reached storage.
	 *
	 * @param array $configuration Saved or normalized Field Configuration.
	 * @param array $field_keys    Fields whose authoritative values are needed.
	 * @param bool  $changed       Whether persistence changed the stored option.
	 * @return array Successful Field Configuration Result.
	 */
	private function success_result( array $configuration, array $field_keys, bool $changed ): array {
		$fields = array();
		foreach ( $field_keys as $field_key ) {
			$fields[ $field_key ] = array(
				'import'   => $configuration['mls-fields'][ $field_key ],
				'admin'    => $configuration['mls-fields-admin'][ $field_key ],
				'label'    => $configuration['mls-fields-label'][ $field_key ],
				'postmeta' => $configuration['mls-fields-map-postmeta'][ $field_key ],
				'taxonomy' => $configuration['mls-fields-map-taxonomy'][ $field_key ],
			);
		}

		return array(
			'success'  => true,
			'changed'  => $changed,
			'revision' => $configuration[ self::REVISION_KEY ],
			'fields'   => $fields,
		);
	}

	/**
	 * Build a stable rejected result without exposing storage implementation.
	 *
	 * Every validation and persistence failure uses the same public envelope.
	 * The browser can therefore decide between retry and reload from `code`
	 * without knowing whether WordPress options or another store is underneath.
	 *
	 * @param string $code     Machine-readable failure code.
	 * @param string $message  Administrator-facing explanation.
	 * @param int    $revision Latest revision known to the module.
	 * @return array Failed Field Configuration Result.
	 */
	private function error_result( string $code, string $message, int $revision ): array {
		return array(
			'success'  => false,
			'changed'  => false,
			'revision' => $revision,
			'error'    => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Increment the revision and atomically persist one complete replacement.
	 *
	 * All mutation paths pass through this method. It advances the revision only
	 * for a real change, attempts compare-and-swap once, and converts a failed
	 * attempt into the shared stale-or-storage error contract.
	 *
	 * @param array $stored         Exact raw option used as the CAS expectation.
	 * @param array $replacement    Complete validated replacement, updated in place.
	 * @param int   $prior_revision Revision associated with the attempted change.
	 * @return array|null Failure result, or null when persistence succeeds.
	 */
	private function persist_replacement( array $stored, array &$replacement, int $prior_revision ) {
		$replacement[ self::REVISION_KEY ]++;
		if ( ! call_user_func( $this->compare_and_swap, $stored, $replacement ) ) {
			return $this->storage_failure_result( $prior_revision );
		}

		return null;
	}

	/**
	 * Distinguish a concurrent replacement from an unchanged storage failure.
	 *
	 * A failed compare-and-swap is followed by one fresh read. A different
	 * revision means another writer won and the browser must reload; an unchanged
	 * revision means storage itself failed and the queued command may be retried.
	 *
	 * @param int $attempted_revision Revision observed before the failed write.
	 * @return array Failed Field Configuration Result.
	 */
	private function storage_failure_result( int $attempted_revision ): array {
		$current          = call_user_func( $this->load );
		$current_revision = is_array( $current ) ? max( 0, (int) ( $current[ self::REVISION_KEY ] ?? 0 ) ) : 0;

		if ( $current_revision !== $attempted_revision ) {
			return $this->error_result( 'stale_revision', 'Field Configuration changed in another browser. Reload before saving.', $current_revision );
		}

		return $this->error_result( 'persistence_failed', 'Field Configuration could not be saved.', $current_revision );
	}

	/**
	 * Normalize the saved arrays and reconcile them with current MLS metadata.
	 *
	 * Existing active fields retain their saved sequence. New fields are appended
	 * alphabetically using the same defaults as initial setup. Dormant fields stay
	 * after all active fields so a returning field reappears at the end instead of
	 * reclaiming a stale position.
	 *
	 * @param mixed $stored       Raw option value.
	 * @param array $metadata     Current MLS field map.
	 * @param array $theme_schema Theme mapping defaults.
	 * @param mixed $taxonomies Active taxonomy slug-to-label map, or null to skip registry cleanup.
	 * @return array Reconciled option array.
	 */
	private function reconciled_configuration( $stored, array $metadata, array $theme_schema, $taxonomies = null ): array {
		$stored = is_array( $stored ) ? $stored : array();
		$bands  = array(
			'mls-fields',
			'mls-fields-admin',
			'mls-fields-label',
			'mls-fields-map-postmeta',
			'mls-fields-map-taxonomy',
		);

		$field_keys = array();
		foreach ( array_merge( $bands, array( 'field_order' ) ) as $band ) {
			if ( isset( $stored[ $band ] ) && is_array( $stored[ $band ] ) ) {
				$field_keys = array_merge( $field_keys, array_keys( $stored[ $band ] ) );
			}
		}
		$field_keys = array_values( array_unique( array_map( 'strval', $field_keys ) ) );

		$order = isset( $stored['field_order'] ) && is_array( $stored['field_order'] )
			? $stored['field_order']
			: array();
		usort(
			$field_keys,
			static function ( $left, $right ) use ( $order ) {
				$left_order  = isset( $order[ $left ] ) && is_numeric( $order[ $left ] ) ? (int) $order[ $left ] : PHP_INT_MAX;
				$right_order = isset( $order[ $right ] ) && is_numeric( $order[ $right ] ) ? (int) $order[ $right ] : PHP_INT_MAX;
				return $left_order === $right_order ? strcmp( $left, $right ) : $left_order <=> $right_order;
			}
		);

		$metadata_keys = array_values( array_map( 'strval', array_keys( $metadata ) ) );
		$new_keys      = array_values( array_diff( $metadata_keys, $field_keys ) );
		sort( $new_keys, SORT_STRING );
		$active_keys   = array_values( array_intersect( $field_keys, $metadata_keys ) );
		$dormant_keys  = array_values( array_diff( $field_keys, $metadata_keys ) );
		$ordered_keys  = array_merge( $active_keys, $new_keys, $dormant_keys );

		$configuration = array();
		foreach ( $bands as $band ) {
			$configuration[ $band ] = array();
		}
		// Ordering is filled per key below; seed it so a site with no fields yet
		// still returns the complete band set instead of a missing field_order.
		$configuration['field_order'] = array();

		foreach ( $ordered_keys as $index => $field_key ) {
			$is_new = ! in_array( $field_key, $field_keys, true );
			$is_active = in_array( $field_key, $metadata_keys, true );
			$schema = isset( $theme_schema[ $field_key ] ) && is_array( $theme_schema[ $field_key ] )
				? $theme_schema[ $field_key ]
				: array();

			$configuration['mls-fields'][ $field_key ] = $is_new
				? ( empty( $schema ) ? 0 : 1 )
				: $this->boolean_value( $stored['mls-fields'][ $field_key ] ?? 0 );
			$configuration['mls-fields-admin'][ $field_key ] = $is_new
				? 0
				: $this->boolean_value( $stored['mls-fields-admin'][ $field_key ] ?? 0 );
			$configuration['mls-fields-label'][ $field_key ] = $is_new
				? ''
				: $this->plain_text( $stored['mls-fields-label'][ $field_key ] ?? '' );
			$configuration['mls-fields-map-postmeta'][ $field_key ] = $is_new
				? ''
				: $this->plain_text( $stored['mls-fields-map-postmeta'][ $field_key ] ?? '' );

			$taxonomy_default = isset( $schema['type'], $schema['name'] ) && 'taxonomy' === $schema['type']
				? $this->plain_text( $schema['name'] )
				: '';
			$taxonomy_value   = $is_new
				? $taxonomy_default
				: $this->plain_text( $stored['mls-fields-map-taxonomy'][ $field_key ] ?? '' );
			if ( $is_active && is_array( $taxonomies ) && '' !== $taxonomy_value && ! array_key_exists( $taxonomy_value, $taxonomies ) ) {
				$taxonomy_value = '';
			}
			if ( '' !== $configuration['mls-fields-map-postmeta'][ $field_key ] ) {
				$taxonomy_value = '';
			}
			$configuration['mls-fields-map-taxonomy'][ $field_key ] = $taxonomy_value;
			$configuration['field_order'][ $field_key ]             = $index;
		}

		$configuration[ self::REVISION_KEY ] = max( 0, (int) ( $stored[ self::REVISION_KEY ] ?? 0 ) );

		return $configuration;
	}

	/**
	 * Normalize checkbox-like legacy values to the public integer schema.
	 *
	 * Historical settings forms saved several truthy spellings. Only the known
	 * spellings become 1; all other input becomes 0 so callers never receive a
	 * mixture of booleans, strings, and integers.
	 *
	 * @param mixed $value Raw legacy checkbox value.
	 * @return int Either 1 or 0.
	 */
	private function boolean_value( $value ): int {
		return in_array( $value, array( 1, '1', true, 'yes', 'true', 'on' ), true ) ? 1 : 0;
	}

	/**
	 * Normalize free text as trimmed, tag-free Unicode text.
	 *
	 * Scalar input is stripped of tags, line breaks are collapsed to spaces, and
	 * surrounding whitespace is removed. Non-scalar input becomes an empty
	 * string rather than leaking an array or object into the option schema.
	 *
	 * @param mixed $value Raw label or mapping destination.
	 * @return string Safe normalized text.
	 */
	private function plain_text( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value, true ) : strip_tags( $value );

		return trim( preg_replace( '/[\r\n\t]+/u', ' ', $value ) );
	}
}
