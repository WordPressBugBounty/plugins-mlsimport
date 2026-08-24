<?php
/**
 * Prepare selected RESO fields for one Stored Listing Write.
 *
 * This implementation owns the rules formerly copied across theme adapters:
 * selection, scalar/array normalization, Rooms formatting, custom post-meta and
 * taxonomy mapping, labels, administrator visibility, and configured ordering.
 * It performs no WordPress writes; the listing module passes its projection to
 * the persistence boundary and the active theme adapter.
 *
 * @package MLSImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert raw extra_meta into common and theme-specific persistence groups.
 */
final class Mlsimport_Stored_Listing_Fields {

	/**
	 * Prepare every enabled extra_meta field in one ordered pass.
	 *
	 * Mapped values leave the adapter seam as common post meta or taxonomy data.
	 * Unmapped values retain their display label, administrator flag, and order
	 * so the adapter can store only its genuine repeater/custom-field variation.
	 *
	 * @param array<string, mixed> $property      Incoming raw MLS property.
	 * @param array<string, mixed> $configuration Active Field Configuration.
	 * @return array<string, array<mixed>> Common and adapter field groups.
	 */
	public function prepare( array $property, array $configuration ): array {
		$result = array(
			'post_meta'    => array(),
			'taxonomies'   => array(),
			'theme_fields' => array(),
		);
		$extra = is_array( $property['extra_meta'] ?? null ) ? $property['extra_meta'] : array();
		$selected = is_array( $configuration['mls-fields'] ?? null )
			? $configuration['mls-fields']
			: array();
		$postmeta_map = is_array( $configuration['mls-fields-map-postmeta'] ?? null )
			? $configuration['mls-fields-map-postmeta']
			: array();
		$taxonomy_map = is_array( $configuration['mls-fields-map-taxonomy'] ?? null )
			? $configuration['mls-fields-map-taxonomy']
			: array();
		$labels = is_array( $configuration['mls-fields-label'] ?? null )
			? $configuration['mls-fields-label']
			: array();
		$admin = is_array( $configuration['mls-fields-admin'] ?? null )
			? $configuration['mls-fields-admin']
			: array();
		$order = $this->order_map( $configuration['field_order'] ?? array() );

		foreach ( $extra as $field => $raw_value ) {
			$field = (string) $field;
			if ( 1 !== (int) ( $selected[ $field ] ?? 0 ) ) {
				continue;
			}

			// Rooms has one cross-theme string format and one stable common key.
			if ( 'Rooms' === $field && is_array( $raw_value ) ) {
				$rooms = $this->format_rooms( $raw_value );
				if ( '' !== $rooms ) {
					$result['post_meta']['rooms'] = $rooms;
				}
				continue;
			}

			$value = $this->normalize_value( $raw_value );
			$postmeta_key = trim( (string) ( $postmeta_map[ $field ] ?? '' ) );
			if ( '' !== $postmeta_key ) {
				$result['post_meta'][ $postmeta_key ] = $value;
				continue;
			}

			$taxonomy = trim( (string) ( $taxonomy_map[ $field ] ?? '' ) );
			if ( '' !== $taxonomy ) {
				$label = trim( (string) ( $labels[ $field ] ?? '' ) );
				if ( 'none' === strtolower( $label ) ) {
					$label = '';
				}
				$term  = trim( $label . ' ' . $value );
				if ( '' !== $term ) {
					$result['taxonomies'][ $taxonomy ] = array( $term );
				}
				continue;
			}

			$result['theme_fields'][] = array(
				'field' => $field,
				'label' => '' !== trim( (string) ( $labels[ $field ] ?? '' ) )
					? trim( (string) $labels[ $field ] )
					: $field,
				'value' => $value,
				'admin' => 1 === (int) ( $admin[ $field ] ?? 0 ),
				'order' => $order[ $field ] ?? PHP_INT_MAX,
			);
		}

		usort(
			$result['theme_fields'],
			static function ( array $left, array $right ): int {
				$order_compare = (int) $left['order'] <=> (int) $right['order'];
				return 0 !== $order_compare ? $order_compare : strcmp( $left['field'], $right['field'] );
			}
		);
		return $result;
	}

	/**
	 * Normalize scalar, list, and nested-array values to one stored string.
	 *
	 * @param mixed $value Raw RESO field value.
	 * @return string Trimmed single-line value with normalized comma spacing.
	 */
	public function normalize_value( $value ): string {
		if ( ! is_array( $value ) ) {
			return (string) preg_replace( '/\s*,\s*/', ', ', trim( (string) $value ) );
		}

		$parts = array();
		foreach ( $value as $part ) {
			if ( is_array( $part ) ) {
				$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $part ) : json_encode( $part );
				if ( false !== $encoded && null !== $encoded ) {
					$parts[] = $encoded;
				}
				continue;
			}
			$part = trim( (string) $part );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Format RESO Rooms as readable pipe-separated summaries.
	 *
	 * @param array<int, mixed> $rooms Raw RESO Rooms collection.
	 * @return string Formatted rooms, or an empty string when no row is usable.
	 */
	private function format_rooms( array $rooms ): string {
		$formatted = array();
		foreach ( $rooms as $room ) {
			if ( ! is_array( $room ) ) {
				continue;
			}
			$type = trim( (string) ( $room['RoomType'] ?? '' ) );
			if ( '' === $type ) {
				continue;
			}
			$details = array();
			$level   = trim( (string) ( $room['RoomLevel'] ?? '' ) );
			if ( '' !== $level ) {
				$details[] = $level;
			}
			$length = trim( (string) ( $room['RoomLength'] ?? '' ) );
			$width  = trim( (string) ( $room['RoomWidth'] ?? '' ) );
			$units  = trim( (string) ( $room['RoomLengthWidthUnits'] ?? '' ) );
			$size   = '' !== $length && '' !== $width ? $length . ' x ' . $width : $length . $width;
			if ( '' !== $size ) {
				$details[] = trim( $size . ' ' . $units );
			} elseif ( '' !== $units ) {
				$details[] = $units;
			}
			$formatted[] = $type . ( empty( $details ) ? '' : ': ' . implode( ', ', $details ) );
		}
		return implode( ' | ', $formatted );
	}

	/**
	 * Accept both ordered field-name lists and older field=>position maps.
	 *
	 * @param mixed $saved_order Field Configuration order value.
	 * @return array<string, int> Field name to zero-based order.
	 */
	private function order_map( $saved_order ): array {
		if ( ! is_array( $saved_order ) ) {
			return array();
		}
		$map = array();
		foreach ( $saved_order as $key => $value ) {
			if ( is_int( $key ) ) {
				$map[ (string) $value ] = $key;
			} else {
				$map[ (string) $key ] = (int) $value;
			}
		}
		return $map;
	}
}
