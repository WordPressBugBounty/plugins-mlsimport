<?php
/**
 * AI tools (MCP), standalone mode only: listings.
 *
 * These tools exist only when the site runs in standalone mode (theme 990),
 * where the plugin owns the property pages. Each function is the
 * body of one AI tool, a thin wrapper around the code the site already uses:
 *   - search-listings: the front-end search itself. The filters go through
 *     Mlsimport_Standalone_Shortcodes::atts_to_args() (the list of filters a
 *     request may use) and Mlsimport_Standalone_Listings_Query::search().
 *   - get-listing: one property's saved fields, its categories and its link.
 *
 * Agents and visitors' saved searches live in mlsimport-mcp-standalone-people.php.
 *
 * Changing a listing lives in mlsimport-mcp-standalone-listing-edit.php.
 *
 * Public interface:
 *   mlsimport_mcp_standalone_listing_tools()        the tools of this file
 *   mlsimport_mcp_catalog_values( $class, $id )     saved values of a field catalog
 *   mlsimport_mcp_require_post( $id, $type, $what ) refusal for a wrong post
 *   mlsimport_mcp_search_listings( $input )         tool: search-listings
 *   mlsimport_mcp_get_listing( $input )             tool: get-listing
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The AI tools of this file, as the tool list expects them.
 *
 * Each entry: label, description, input, required, callback, changes. See
 * mlsimport_mcp_ability_definitions() in mlsimport-mcp-abilities.php.
 *
 * @return array<string, array<string, mixed>> Tools keyed by tool name.
 */
function mlsimport_mcp_standalone_listing_tools(): array {
	$post_id = array(
		'type'        => 'integer',
		'description' => 'The WordPress post id of the property, from search-listings.',
	);

	return array(
		'search-listings' => array(
			'label'       => 'Search the listings',
			'description' => 'Searches the listings on the site with the filters of the front-end search. Filters (all optional): price_min, price_max, beds (a minimum; 0 means studios only), baths (a minimum), sqft_min, sqft_max, lot_min, lot_max, year_min, year_max, hoa_max, dom_max, garage_min, stories, subdivision, listing_id (the MLS number), list_date_min, location, keywords, agent; lists: city, state, zip, area, county, high_school_district, label, status, property_type, listing_type, features; orderby and order. 20 results per page unless limit says otherwise (at most 50).',
			'input'       => array(
				'filters' => array(
					'type'                 => 'object',
					'description'          => 'The filters, keyed by the names above.',
					'additionalProperties' => true,
				),
				'page'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'limit'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
			),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_search_listings',
			'changes'     => false,
		),
		'get-listing'     => array(
			'label'       => 'One listing',
			'description' => 'Reads one property: its saved fields (price, rooms, address, dates, agent ...), the other imported fields it has (imported_fields), its text, its categories per taxonomy, who gets its leads (lead_agent: a local agent or "feed" for the MLS listing agent), its gallery (attachment ids and urls) and the link to its page.',
			'input'       => array( 'post_id' => $post_id ),
			'required'    => array( 'post_id' ),
			'callback'    => 'mlsimport_mcp_get_listing',
			'changes'     => false,
		),
	);
}

/**
 * The saved values of every field of an edit screen's field catalog.
 *
 * Works for both catalogs (Mlsimport_Property_Metabox and
 * Mlsimport_Agent_Metabox): each lists its fields with fields() and names a
 * field's post meta with meta_key().
 *
 * @param string $catalog Class name of the catalog.
 * @param int    $post_id The property or agent post.
 * @return array<string, mixed> Field key => saved value.
 */
function mlsimport_mcp_catalog_values( string $catalog, int $post_id ): array {
	$values = array();
	foreach ( $catalog::fields() as $field ) {
		$values[ $field['key'] ] = get_post_meta( $post_id, $catalog::meta_key( $field['key'] ), true );
	}
	return $values;
}

/**
 * Refuse a post id that is not of the expected post type.
 *
 * @param int    $post_id   Post id from the tool input.
 * @param string $post_type The post type it must be.
 * @param string $next_step Sentence naming where the right id comes from.
 * @return WP_Error|null The refusal, or null.
 */
function mlsimport_mcp_require_post( int $post_id, string $post_type, string $next_step ) {
	if ( $post_type === get_post_type( $post_id ) ) {
		return null;
	}
	return new WP_Error( 'mlsimport_post_not_found', $next_step );
}

/**
 * Tool search-listings: the front-end search.
 *
 * @param array $input filters, page, limit.
 * @return array The total and one page of listings.
 */
function mlsimport_mcp_search_listings( array $input ): array {
	// Step 1: only the filters a search request may use, in the search's own shape.
	$filters = isset( $input['filters'] ) && is_array( $input['filters'] ) ? $input['filters'] : array();
	$args    = Mlsimport_Standalone_Shortcodes::atts_to_args( $filters );

	// Step 2: one page; never the whole table.
	$args['limit'] = min( 50, max( 1, (int) ( $input['limit'] ?? 20 ) ) );
	$args['page']  = max( 1, (int) ( $input['page'] ?? 1 ) );

	// Step 3: the front-end search itself.
	$result = Mlsimport_Standalone_Listings_Query::search( $args );

	// Step 4: the columns of each row, without the long search text.
	$listings = array();
	foreach ( $result['rows'] as $row ) {
		$row = (array) $row;
		unset( $row['search_text'], $row['id'] );
		$listings[] = array(
			'title' => get_the_title( (int) $row['post_id'] ),
			'url'   => (string) get_permalink( (int) $row['post_id'] ),
		) + $row;
	}

	return array(
		'total'    => (int) $result['total'],
		'page'     => $args['page'],
		'listings' => $listings,
	);
}

/**
 * Tool get-listing: one property's fields, categories and link.
 *
 * @param array $input {
 *     @type int $post_id The property.
 * }
 * @return array|WP_Error The property, or the refusal.
 */
function mlsimport_mcp_get_listing( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$refusal = mlsimport_mcp_require_post( $post_id, 'mlsimport_property', __( 'That is not a property. Find the post id with search-listings.', 'mlsimport' ) );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// The property's categories, per taxonomy of the plugin.
	$categories = array();
	foreach ( Mlsimport_Standalone_Cpt::taxonomy_labels() as $taxonomy => $label ) {
		$names = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $names ) && ! empty( $names ) ) {
			$categories[ $taxonomy ] = $names;
		}
	}

	// The gallery as the edit screen's Media tab shows it (Photos Count applied).
	$gallery = array();
	foreach ( mlsimport_property_gallery_ids( $post_id ) as $attachment_id ) {
		$gallery[] = array(
			'id'  => $attachment_id,
			'url' => (string) wp_get_attachment_url( $attachment_id ),
		);
	}

	// The lead agent: a local agent post, or 'feed' for the MLS feed's own agent.
	$agent_id   = (int) get_post_meta( $post_id, 'mlsimport_list_agent_id', true );
	$use_feed   = (bool) intval( get_post_meta( $post_id, 'mlsimport_use_mls_agent', true ) );
	$lead_agent = ( ! $use_feed && $agent_id > 0 && get_post( $agent_id ) ) ? array(
		'id'   => $agent_id,
		'name' => get_the_title( $agent_id ),
	) : 'feed';

	return array(
		'post_id'         => $post_id,
		'title'           => get_the_title( $post_id ),
		'status'          => (string) get_post_status( $post_id ),
		'url'             => (string) get_permalink( $post_id ),
		'mls_id'          => (int) get_post_meta( $post_id, 'mlsimport_mls_id', true ),
		'content'         => (string) get_post_field( 'post_content', $post_id ),
		'fields'          => mlsimport_mcp_catalog_values( 'Mlsimport_Property_Metabox', $post_id ),
		'imported_fields' => Mlsimport_Property_Metabox::dynamic_field_values( $post_id ),
		'categories'      => $categories,
		'lead_agent'      => $lead_agent,
		'gallery'         => $gallery,
	);
}
