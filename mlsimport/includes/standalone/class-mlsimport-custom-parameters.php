<?php
/**
 * Standalone (theme_id 990) "Custom parameters" filter.
 *
 * The Property List, Property Slider and Map with Listings blocks carry a
 * repeater of rows, each { field, compare, value }:
 *
 *   BuildingName | equals | Kahala Beach
 *
 * Every imported MLS field is stored as post meta on the property, under
 * mlsimport_<Field> (a mapped field) or mlsimport_x_<Field> (a pass-through
 * field). A row keeps only the listings whose meta passes the comparison, and a
 * listing must pass every row (the rows are AND-ed).
 *
 * This class has two steps, both pure (no WordPress, no DB):
 *   1. rows()  — clean the raw block setting (array or JSON string) into valid rows.
 *   2. where() — turn the rows into " AND ..." SQL with %s placeholders + args.
 * The listings query (Mlsimport_Standalone_Listings_Query::full_where) appends
 * the result to its WHERE, so search, map pins and map bounds all honour it.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cleans the Custom parameters rows and builds their WHERE fragment.
 */
class Mlsimport_Custom_Parameters {

	/**
	 * The comparisons the "How" dropdown offers: value => [ IN / NOT IN, SQL test
	 * on meta_value ]. "not equals" is the equals subquery under NOT IN, so a
	 * listing that has no value for the field counts as "not equal" too. Meta is
	 * stored as text, so greater / less than cast it to a number first (as text
	 * "10" sorts before "9"); their value binds as %f.
	 */
	private const COMPARE = array(
		'equals'       => array( 'IN', 'pm.meta_value = %s' ),
		'not_equals'   => array( 'NOT IN', 'pm.meta_value = %s' ),
		'greater_than' => array( 'IN', 'CAST(pm.meta_value AS DECIMAL(20,4)) > %f' ),
		'less_than'    => array( 'IN', 'CAST(pm.meta_value AS DECIMAL(20,4)) < %f' ),
		'contains'     => array( 'IN', 'pm.meta_value LIKE %s' ),
	);

	/**
	 * Clean the raw setting into valid rows.
	 *
	 * Step 1: accept the rows as an array (block attribute, Elementor repeater) or a
	 *         JSON string (the form/map carry it that way through AJAX).
	 * Step 2: keep a row only when its field is a plain MLS field name, its compare
	 *         is a known comparison and it is a row at all.
	 *
	 * @param mixed $raw Rows as array or JSON string.
	 * @return array<int,array{field:string,compare:string,value:string}>
	 */
	public static function rows( $raw ): array {
		// Step 1: a JSON string decodes to the same array shape.
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		// Step 2: keep the valid rows only.
		$rows = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$field   = isset( $row['field'] ) ? trim( (string) $row['field'] ) : '';
			$compare = isset( $row['compare'] ) ? (string) $row['compare'] : '';
			// The field becomes part of a meta key, so it must be a plain field name.
			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $field ) || ! isset( self::COMPARE[ $compare ] ) ) {
				continue;
			}
			$rows[] = array(
				'field'   => $field,
				'compare' => $compare,
				'value'   => isset( $row['value'] ) ? trim( (string) $row['value'] ) : '',
			);
		}
		return $rows;
	}

	/**
	 * Build the WHERE fragment for the rows.
	 *
	 * Each row becomes one post-meta subquery on the listings alias L:
	 *   AND L.post_id IN|NOT IN (SELECT pm.post_id FROM <postmeta> pm
	 *       WHERE pm.meta_key IN (%s, %s) AND <comparison>)
	 * The two meta keys are mlsimport_<Field> and mlsimport_x_<Field>; they and
	 * the value bind as placeholders, so nothing from the setting enters the SQL.
	 *
	 * @param array  $rows     Rows from rows().
	 * @param string $postmeta The postmeta table name ($wpdb->postmeta).
	 * @return array{sql:string,args:array} SQL starting with " AND", or '' for no rows.
	 */
	public static function where( array $rows, string $postmeta ): array {
		$sql  = '';
		$args = array();
		foreach ( $rows as $row ) {
			list( $membership, $test ) = self::COMPARE[ $row['compare'] ];
			$sql   .= " AND L.post_id {$membership} (SELECT pm.post_id FROM {$postmeta} pm WHERE pm.meta_key IN (%s, %s) AND {$test})";
			$args[] = 'mlsimport_' . $row['field'];
			$args[] = 'mlsimport_x_' . $row['field'];
			$args[] = self::bound_value( $row['compare'], $row['value'] );
		}
		return array(
			'sql'  => $sql,
			'args' => $args,
		);
	}

	/**
	 * The value as its comparison binds it.
	 *
	 * - greater / less than: a number (the %f placeholder).
	 * - contains: wrapped in % for LIKE, with the user's own % _ \ escaped
	 *   (what $wpdb->esc_like() does) so they match literally.
	 * - equals / not equals: the text as typed.
	 *
	 * @param string $compare Comparison key.
	 * @param string $value   Value from the row.
	 * @return string|float
	 */
	private static function bound_value( string $compare, string $value ) {
		if ( 'greater_than' === $compare || 'less_than' === $compare ) {
			return (float) $value;
		}
		if ( 'contains' === $compare ) {
			return '%' . addcslashes( $value, '\\%_' ) . '%';
		}
		return $value;
	}
}
