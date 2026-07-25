<?php
/**
 * Standalone (theme_id 990) page-block listing selection resolver.
 *
 * Encodes the one selection model shared by every "set" page block (item list,
 * slider, map, toolbar, list-by-id, agent listings): given the block's args,
 * decide whether to render an explicit, ordered set of property IDs or to run the
 * listings query (taxonomy + sort + count). Pure: no WordPress, no DB — the
 * returned params feed Mlsimport_Standalone_Query unchanged.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a page block's selection args into a query- or ids-mode instruction.
 */
class Mlsimport_Page_Block_Selection {

	/**
	 * Resolve selection args into a discriminated render instruction.
	 *
	 * @param array $args Page block selection args.
	 * @return array{mode:string,params?:array,ids?:int[]} 'query' with params, or 'ids' with ids.
	 */
	public static function resolve( array $args ): array {
		$ids = self::parse_ids( isset( $args['ids'] ) ? $args['ids'] : '' );
		if ( $ids ) {
			return array(
				'mode' => 'ids',
				'ids'  => $ids,
			);
		}

		return array(
			'mode'   => 'query',
			'params' => self::query_params( $args ),
		);
	}

	/**
	 * Map selection args to the Mlsimport_Standalone_Query params shape.
	 *
	 * @param array $args Page block selection args.
	 * @return array
	 */
	private static function query_params( array $args ): array {
		$params = array();

		if ( isset( $args['count'] ) && (int) $args['count'] > 0 ) {
			$params['limit'] = (int) $args['count'];
		}

		// The sort token is forwarded as-is; Mlsimport_Standalone_Query owns the
		// token => (column, direction) vocabulary and expands it when ordering. An
		// empty/unknown token leaves orderby unset, so the site default order applies.
		if ( isset( $args['sort'] ) && '' !== $args['sort'] ) {
			$params['orderby'] = (string) $args['sort'];
		}

		// Only known taxonomy/filter keys forward to the query; builder-only keys
		// (layout, design, …) are ignored so they never reach the SQL.
		foreach ( self::TAXONOMY_KEYS as $key ) {
			if ( isset( $args[ $key ] ) && array() !== $args[ $key ] && '' !== $args[ $key ] ) {
				$params[ $key ] = $args[ $key ];
			}
		}

		return $params;
	}

	/**
	 * Taxonomy-backed selection keys a page block may forward to the query, in a
	 * deterministic order. Each is consumed by Mlsimport_Standalone_Query.
	 */
	private const TAXONOMY_KEYS = array(
		'city',
		'state',
		'zip',
		'property_type',
		'listing_type',
		'status',
		'features',
	);

	/**
	 * Parse a comma/newline/space list (or array) of property IDs into an ordered
	 * int list, preserving the given order and dropping non-positive/blank entries.
	 *
	 * @param mixed $raw Delimited string (comma, newline or space) or array of IDs.
	 * @return int[]
	 */
	private static function parse_ids( $raw ): array {
		// Accept either an array or a delimited string. The textarea control invites
		// one ID per line, so split on any run of commas/whitespace, not commas alone.
		$values = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );

		$ids = array();
		// Cast each entry, keeping only positive IDs and preserving the given order.
		foreach ( $values as $value ) {
			$id = (int) trim( (string) $value );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
