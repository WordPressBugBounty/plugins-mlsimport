<?php
/**
 * Standalone (theme_id 990) search-field catalog + classifier.
 *
 * The single source of truth for "what can a search form filter on". A field is
 * only offered if the fast listings table can actually filter it, which keeps
 * search off slow meta lookups. Two groups, in this display order:
 *
 *   1. taxonomies — every standalone taxonomy (§7). City/State/Zip/Type/Status
 *      are backed by a fast column and matched by the term *name*; Area/County/
 *      Label/Features have no column and are matched by a term-join on *slug*.
 *   2. columns — the fast-table fact columns, EXCEPT the geo coordinates
 *      (latitude/longitude) and the internal identity columns.
 *
 * Each entry also carries how it renders and which query param(s) it submits, so
 * the Gutenberg field picker, the front-end form and the WHERE-builder all read
 * one definition. Pure data + lookup: no WordPress, no DB.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies and describes the fields a standalone search form may filter by.
 */
class Mlsimport_Page_Block_Search_Fields {

	/**
	 * Taxonomy search fields, in display order. Mirrors the standalone taxonomy
	 * registry (§7). Per field:
	 *   label   editor/front-end label.
	 *   tax     the taxonomy slug whose terms populate the control.
	 *   match   'column-in' (fast column IN names) or 'term-join'
	 *           (wp_term_relationships subquery on slug).
	 *   multi   true — every plugin taxonomy is a multi-select.
	 *   value   the option value the control submits: 'name' or 'slug'.
	 *   join    for term-join fields only: 'and' (a listing must carry every chosen
	 *           term — amenities, ADR-0004) or 'or' (any chosen term — locations,
	 *           labels, which a listing only holds one of).
	 */
	private const TAXONOMY_FIELDS = array(
		'property_type'        => array( 'label' => 'Property Type', 'tax' => 'mlsimport_property_type', 'match' => 'column-in', 'multi' => true, 'value' => 'name' ),
		'listing_type'         => array( 'label' => 'Listing Type',  'tax' => 'mlsimport_listing_type',  'match' => 'column-in', 'multi' => true, 'value' => 'name' ),
		'status'               => array( 'label' => 'Status',        'tax' => 'mlsimport_status',        'match' => 'column-in', 'multi' => true, 'value' => 'name' ),
		'city'                 => array( 'label' => 'City',          'tax' => 'mlsimport_city',          'match' => 'column-in', 'multi' => true, 'value' => 'name' ),
		'area'                 => array( 'label' => 'Area',          'tax' => 'mlsimport_area',          'match' => 'term-join', 'multi' => true, 'value' => 'slug', 'join' => 'or' ),
		'county'               => array( 'label' => 'County',        'tax' => 'mlsimport_county',        'match' => 'term-join', 'multi' => true, 'value' => 'slug', 'join' => 'or' ),
		'state'                => array( 'label' => 'State',         'tax' => 'mlsimport_state',         'match' => 'column-in', 'multi' => true, 'value' => 'name' ),
		'zip'                  => array( 'label' => 'ZIP Code',      'tax' => 'mlsimport_zip',           'match' => 'column-in', 'multi' => true, 'value' => 'name' ),
		'high_school_district' => array( 'label' => 'High School District', 'tax' => 'mlsimport_high_school_district', 'match' => 'term-join', 'multi' => true, 'value' => 'slug', 'join' => 'or' ),
		'features'             => array( 'label' => 'Features',      'tax' => 'mlsimport_feature',       'match' => 'term-join', 'multi' => true, 'value' => 'slug', 'join' => 'and' ),
		'label'                => array( 'label' => 'Label',         'tax' => 'mlsimport_label',         'match' => 'term-join', 'multi' => true, 'value' => 'slug', 'join' => 'or' ),
	);

	/**
	 * Fast-table column search fields, in display order — the property facts the
	 * operator searches by. Latitude/longitude (geo) and the internal identity
	 * columns are deliberately absent. Per field:
	 *   label    editor/front-end label.
	 *   control  'range' (min/max number pair), 'number', 'date' or 'text'.
	 *   params   the query param(s) the control submits. A range submits two.
	 */
	private const COLUMN_FIELDS = array(
		// One text box that stands in for the four separate location taxonomies: the
		// visitor types (or picks from the autocomplete) a city, area, county or ZIP
		// and the WHERE-builder ORs the value across all four plus the FULLTEXT
		// search_text column, which already carries UnparsedAddress — so a street
		// address typed in full matches too, with no address column to maintain.
		'location'    => array( 'label' => 'Location',           'control' => 'location',   'params' => array( 'location' ) ),
		'price'       => array( 'label' => 'Price',              'control' => 'range',      'params' => array( 'price_min', 'price_max' ) ),
		'beds_baths'  => array( 'label' => 'Beds & Baths',       'control' => 'beds_baths', 'params' => array( 'beds', 'baths' ) ),
		'sqft'        => array( 'label' => 'Living Area (sq ft)', 'control' => 'range',  'params' => array( 'sqft_min', 'sqft_max' ) ),
		'lot'         => array( 'label' => 'Lot Size',           'control' => 'range',  'params' => array( 'lot_min', 'lot_max' ) ),
		'year'        => array( 'label' => 'Year Built',         'control' => 'range',  'params' => array( 'year_min', 'year_max' ) ),
		'list_date'   => array( 'label' => 'Listed After',       'control' => 'date',   'params' => array( 'list_date_min' ) ),
		'garage'      => array( 'label' => 'Garage Spaces',      'control' => 'number', 'params' => array( 'garage_min' ) ),
		'stories'     => array( 'label' => 'Stories',            'control' => 'number', 'params' => array( 'stories' ) ),
		'hoa'         => array( 'label' => 'Max HOA Fee',        'control' => 'number', 'params' => array( 'hoa_max' ) ),
		'dom'         => array( 'label' => 'Max Days on Market', 'control' => 'number', 'params' => array( 'dom_max' ) ),
		'subdivision' => array( 'label' => 'Subdivision',        'control' => 'text',   'params' => array( 'subdivision' ) ),
		// The public MLS number off a sign or flyer (RESO ListingId, e.g. TB8541851):
		// an exact match on the listing_id column, so it lands on that one listing.
		'mls_number'  => array( 'label' => 'MLS #',              'control' => 'text',   'params' => array( 'listing_id' ) ),
	);

	/**
	 * Classify a search field by how the fast table can filter it.
	 *
	 * @param string $field Field key (e.g. 'price', 'city', 'features').
	 * @return string 'taxonomy', 'scalar', or '' when the field is not searchable.
	 */
	public static function classify( string $field ): string {
		if ( isset( self::TAXONOMY_FIELDS[ $field ] ) ) {
			return 'taxonomy';
		}
		if ( isset( self::COLUMN_FIELDS[ $field ] ) ) {
			return 'scalar';
		}
		return '';
	}

	/**
	 * Filter a configured field list to only the searchable fields, preserving the
	 * builder's order. The guard the search form builder applies to its repeater.
	 *
	 * @param string[] $fields Requested field keys, in display order.
	 * @return string[] The subset that is searchable.
	 */
	public static function allowed( array $fields ): array {
		$out = array();
		// Keep a field only if it classifies as taxonomy or scalar; drop unknowns.
		foreach ( $fields as $field ) {
			if ( '' !== self::classify( (string) $field ) ) {
				$out[] = (string) $field;
			}
		}
		return $out;
	}

	/**
	 * Every searchable field key, taxonomies then columns — the order the field
	 * picker and the full search form offer them.
	 *
	 * @return string[]
	 */
	public static function catalog(): array {
		return array_merge( array_keys( self::TAXONOMY_FIELDS ), array_keys( self::COLUMN_FIELDS ) );
	}

	/**
	 * The field picker's options: field key => label, taxonomies then columns.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$out = array();
		// Taxonomy fields first (they lead the picker), then the column fields.
		foreach ( self::TAXONOMY_FIELDS as $key => $def ) {
			$out[ $key ] = $def['label'];
		}
		foreach ( self::COLUMN_FIELDS as $key => $def ) {
			$out[ $key ] = $def['label'];
		}
		return $out;
	}

	/**
	 * The field keys that render as a dual-handle range slider. The search form's
	 * per-row "slider minimum/maximum" overrides only apply to these, so the editor
	 * can show those two controls conditionally instead of on every row.
	 *
	 * @return string[]
	 */
	public static function range_fields(): array {
		$out = array();
		// A range control is the only one with a configurable floor and ceiling.
		foreach ( self::COLUMN_FIELDS as $key => $def ) {
			if ( 'range' === $def['control'] ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * The full definition for one field, normalized with its group, or null when
	 * the field is not searchable. Taxonomy fields gain group=taxonomy + tax/match/
	 * multi/value; column fields gain group=column + control/params.
	 *
	 * @param string $field Field key.
	 * @return array|null
	 */
	public static function definition( string $field ) {
		if ( isset( self::TAXONOMY_FIELDS[ $field ] ) ) {
			return array_merge( array( 'key' => $field, 'group' => 'taxonomy' ), self::TAXONOMY_FIELDS[ $field ] );
		}
		if ( isset( self::COLUMN_FIELDS[ $field ] ) ) {
			return array_merge( array( 'key' => $field, 'group' => 'column' ), self::COLUMN_FIELDS[ $field ] );
		}
		return null;
	}

	/**
	 * The search field that filters a given taxonomy, as { key, value }, or null
	 * when the taxonomy has no search field. Lets a taxonomy term archive pre-filter
	 * the grid (and pre-select the search form) by its queried term: 'key' is the
	 * query param, 'value' says whether that param carries the term name or slug.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array{key:string,value:string}|null
	 */
	public static function field_for_taxonomy( string $taxonomy ) {
		// Reverse-lookup: find the search field whose 'tax' matches this taxonomy.
		foreach ( self::TAXONOMY_FIELDS as $key => $def ) {
			if ( $def['tax'] === $taxonomy ) {
				return array( 'key' => $key, 'value' => $def['value'] );
			}
		}
		return null;
	}

	/**
	 * The term-join taxonomy fields: query param => { tax, join }. The listings
	 * query resolves each submitted slug to a term_taxonomy_id and filters by them
	 * per the join mode ('and' = carry every term, 'or' = carry any). Lets the query
	 * stay in sync with the catalog without re-declaring the join set.
	 *
	 * @return array<string,array{tax:string,join:string}>
	 */
	public static function term_join_params(): array {
		$out = array();
		// Collect only the term-join fields; column-in fields filter via a fast column.
		foreach ( self::TAXONOMY_FIELDS as $key => $def ) {
			if ( 'term-join' === $def['match'] ) {
				$out[ $key ] = array(
					'tax'  => $def['tax'],
					// Default to 'or'; only an explicit 'and' requires every chosen term.
					'join' => isset( $def['join'] ) && 'and' === $def['join'] ? 'and' : 'or',
				);
			}
		}
		return $out;
	}

	/**
	 * The taxonomies the combined Location field reaches beyond the fast city/zip
	 * columns — area and county, which have no column of their own. One list, read
	 * by both the autocomplete suggestion source and the WHERE-builder's location
	 * clause, so the two can never offer and match different things.
	 *
	 * @return string[] Taxonomy slugs.
	 */
	public static function location_taxonomies(): array {
		return array( self::TAXONOMY_FIELDS['area']['tax'], self::TAXONOMY_FIELDS['county']['tax'] );
	}
}
