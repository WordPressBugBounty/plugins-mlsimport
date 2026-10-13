<?php
/**
 * AI tools (MCP), standalone mode only: changing a listing.
 *
 * These tools exist only when the site runs in standalone mode (theme 990).
 * Each function is the body of one AI tool, a thin wrapper around the code
 * the site already uses:
 *   - update-listing: Mlsimport_Property_Metabox::save_fields(), the write of
 *     the property edit screen, then a re-index of that property.
 *   - set-featured: Mlsimport_Property_Featured_Admin::set_featured(), the
 *     write of the "Mark featured" bulk action.
 *   - reindex: Mlsimport_Standalone_Reindex (the same as `wp mlsimport reindex`).
 *
 * A field that is not named in an update keeps its value: the tool starts
 * from the saved values, lays the named ones over them
 * (mlsimport_mcp_catalog_overlay()), and hands the whole set to the edit
 * screen's own write, which expects every field.
 *
 * Searching and reading listings live in mlsimport-mcp-standalone-listings.php.
 *
 * Public interface:
 *   mlsimport_mcp_standalone_listing_edit_tools()    the tools of this file
 *   mlsimport_mcp_catalog_overlay( $class, $id, $f ) saved values + the named changes
 *   mlsimport_mcp_update_listing( $input )           tool: update-listing
 *   mlsimport_mcp_set_featured( $input )             tool: set-featured
 *   mlsimport_mcp_reindex( $input )                  tool: reindex
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
function mlsimport_mcp_standalone_listing_edit_tools(): array {
	$post_id = array(
		'type'        => 'integer',
		'description' => 'The WordPress post id of the property, from search-listings.',
	);
	$fields  = array(
		'type'                 => 'object',
		'description'          => 'The fields to change, keyed by field name. A field you do not name keeps its value.',
		'additionalProperties' => true,
	);

	return array(
		'update-listing' => array(
			'label'       => 'Change a listing',
			'description' => 'Changes one property the way its edit screen does: its fields (the names get-listing returns under fields and imported_fields), its title, its text, its status, its categories and who gets its leads. Only what you name changes. A value changed here is overwritten by the next import of that listing when the MLS sends that field.',
			'input'       => array(
				'post_id'    => $post_id,
				'fields'     => $fields,
				'title'      => array( 'type' => 'string' ),
				'content'    => array(
					'type'        => 'string',
					'description' => 'The property text (description).',
				),
				'status'     => array(
					'type' => 'string',
					'enum' => array( 'publish', 'draft', 'private' ),
				),
				'categories' => array(
					'type'                 => 'object',
					'description'          => 'Term names per taxonomy, keyed by taxonomy (mlsimport_city, mlsimport_status, mlsimport_feature ...). A named taxonomy gets exactly these terms (a new name creates the term); an empty list clears it. Taxonomies you do not name keep their terms.',
					'additionalProperties' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'lead_agent' => array(
					'type'        => array( 'integer', 'string' ),
					'description' => 'Who gets the leads of this property: an agent id from list-agents, or "feed" for the MLS feed\'s own listing agent.',
				),
			),
			'required'    => array( 'post_id' ),
			'callback'    => 'mlsimport_mcp_update_listing',
			'changes'     => true,
		),
		'set-featured'   => array(
			'label'       => 'Mark a listing featured',
			'description' => 'Marks one property as featured, or removes the mark. Featured listings are shown first when that setting is on.',
			'input'       => array(
				'post_id'  => $post_id,
				'featured' => array( 'type' => 'boolean' ),
			),
			'required'    => array( 'post_id', 'featured' ),
			'callback'    => 'mlsimport_mcp_set_featured',
			'changes'     => true,
		),
		'reindex'        => array(
			'label'       => 'Rebuild the search index',
			'description' => 'Rebuilds the fast search table the listing pages read from. With post_id: that one property. Without: every property, which can take long on a site with thousands of listings. Use it when search results do not match what a property page shows.',
			'input'       => array( 'post_id' => $post_id ),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_reindex',
			'changes'     => true,
		),
	);
}

/**
 * Lay the named fields over the saved ones, refusing a field the catalog lacks.
 *
 * @param string $catalog Class name of the field catalog.
 * @param int    $post_id The property or agent post.
 * @param array  $fields  The fields the AI named.
 * @return array|WP_Error Every catalog field with its value to save, or the refusal.
 */
function mlsimport_mcp_catalog_overlay( string $catalog, int $post_id, array $fields ) {
	$values = mlsimport_mcp_catalog_values( $catalog, $post_id );
	foreach ( $fields as $key => $value ) {
		if ( ! array_key_exists( (string) $key, $values ) ) {
			return new WP_Error(
				'mlsimport_field_unknown',
				sprintf(
					/* translators: 1: the field name sent, 2: the list of field names. */
					__( '"%1$s" is not a field that can be changed here. The fields are: %2$s.', 'mlsimport' ),
					sanitize_text_field( (string) $key ),
					implode( ', ', array_keys( $values ) )
				)
			);
		}
		// A checkbox is "ticked" by any non-empty value; false must untick it.
		$values[ (string) $key ] = is_bool( $value ) ? ( $value ? '1' : '' ) : $value;
	}
	return $values;
}

/**
 * Tool update-listing: change one property as its edit screen does.
 *
 * Step by step:
 * 1. The post must be a property.
 * 2. Check everything before writing anything: the fields split into catalog
 *    fields and imported (dynamic) fields, anything else is refused; the
 *    taxonomies must be the plugin's; the lead agent must be "feed" or a
 *    published agent.
 * 3. Write through the edit screen's own functions: catalog fields, imported
 *    fields, lead agent, then the post itself (title, text, status), the
 *    terms, and the re-index the screen does too.
 *
 * @param array $input post_id, fields, title, content, status, categories, lead_agent.
 * @return array|WP_Error The property as saved (see get-listing), or the refusal.
 */
function mlsimport_mcp_update_listing( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$refusal = mlsimport_mcp_require_post( $post_id, 'mlsimport_property', __( 'That is not a property. Find the post id with search-listings.', 'mlsimport' ) );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// Step 2a: catalog fields and imported fields, every other name refused.
	$fields   = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
	$imported = array_intersect_key( $fields, Mlsimport_Property_Metabox::dynamic_field_values( $post_id ) );
	$values   = mlsimport_mcp_catalog_overlay( 'Mlsimport_Property_Metabox', $post_id, array_diff_key( $fields, $imported ) );
	if ( is_wp_error( $values ) ) {
		return $values;
	}

	// Step 2b: the taxonomies must be the plugin's.
	$categories = isset( $input['categories'] ) && is_array( $input['categories'] ) ? $input['categories'] : array();
	$taxonomies = array_keys( Mlsimport_Standalone_Cpt::taxonomy_labels() );
	$unknown    = array_diff( array_keys( $categories ), $taxonomies );
	if ( ! empty( $unknown ) ) {
		return new WP_Error(
			'mlsimport_taxonomy_unknown',
			/* translators: 1: the unknown taxonomy names, 2: the property taxonomies. */
			sprintf( __( 'No property taxonomy is called %1$s. The taxonomies are: %2$s.', 'mlsimport' ), implode( ', ', array_map( 'sanitize_key', $unknown ) ), implode( ', ', $taxonomies ) )
		);
	}

	// Step 2c: the lead agent: "feed", or a published agent post.
	$lead = isset( $input['lead_agent'] ) ? (string) $input['lead_agent'] : null;
	if ( null !== $lead && Mlsimport_Property_Metabox::LEAD_AGENT_FEED !== $lead && 'feed' !== $lead ) {
		$agent = ctype_digit( $lead ) ? get_post( (int) $lead ) : null;
		if ( ! $agent || 'mlsimport_agent' !== $agent->post_type || 'publish' !== $agent->post_status ) {
			return new WP_Error(
				'mlsimport_agent_unknown',
				/* translators: %s: the lead agent sent. */
				sprintf( __( 'There is no published agent "%s". Use an agent id from list-agents, or "feed" for the MLS listing agent.', 'mlsimport' ), sanitize_text_field( $lead ) )
			);
		}
	}

	// Step 3: the edit screen's own writes.
	if ( ! empty( $fields ) ) {
		Mlsimport_Property_Metabox::save_fields( $post_id, $values );
		Mlsimport_Property_Metabox::save_dynamic_fields( $post_id, $imported );
	}
	if ( null !== $lead ) {
		Mlsimport_Property_Metabox::save_lead_agent( $post_id, 'feed' === $lead ? Mlsimport_Property_Metabox::LEAD_AGENT_FEED : $lead );
	}
	$post = array();
	if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
		$post['post_title'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( isset( $input['content'] ) ) {
		$post['post_content'] = wp_kses_post( (string) $input['content'] );
	}
	if ( isset( $input['status'] ) && in_array( $input['status'], array( 'publish', 'draft', 'private' ), true ) ) {
		$post['post_status'] = $input['status'];
	}
	if ( ! empty( $post ) ) {
		wp_update_post( wp_slash( array( 'ID' => $post_id ) + $post ) );
	}
	foreach ( $categories as $taxonomy => $names ) {
		wp_set_object_terms( $post_id, array_values( array_filter( array_map( 'sanitize_text_field', (array) $names ), 'strlen' ) ), $taxonomy );
	}
	Mlsimport_Standalone_Reindex::rebuild_post( $post_id );

	return mlsimport_mcp_get_listing( array( 'post_id' => $post_id ) );
}

/**
 * Tool set-featured: mark one property featured, or remove the mark.
 *
 * @param array $input post_id, featured.
 * @return array|WP_Error The new state, or the refusal.
 */
function mlsimport_mcp_set_featured( array $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$refusal = mlsimport_mcp_require_post( $post_id, 'mlsimport_property', __( 'That is not a property. Find the post id with search-listings.', 'mlsimport' ) );
	if ( null !== $refusal ) {
		return $refusal;
	}

	// The bulk action's own write (meta + re-index + action).
	Mlsimport_Property_Featured_Admin::set_featured( $post_id, ! empty( $input['featured'] ) );
	return array(
		'post_id'  => $post_id,
		'featured' => ! empty( $input['featured'] ),
	);
}

/**
 * Tool reindex: rebuild the fast search table for one property or for all.
 *
 * @param array $input {
 *     @type int $post_id One property; leave out for every property.
 * }
 * @return array|WP_Error How many rows were rebuilt, or the refusal.
 */
function mlsimport_mcp_reindex( array $input ) {
	// Every property: the same as `wp mlsimport reindex`.
	if ( empty( $input['post_id'] ) ) {
		return array( 'rebuilt' => Mlsimport_Standalone_Reindex::rebuild_all() );
	}

	// One property.
	$post_id = (int) $input['post_id'];
	$refusal = mlsimport_mcp_require_post( $post_id, 'mlsimport_property', __( 'That is not a property. Find the post id with search-listings.', 'mlsimport' ) );
	if ( null !== $refusal ) {
		return $refusal;
	}
	return array( 'rebuilt' => Mlsimport_Standalone_Reindex::rebuild_post( $post_id ) ? 1 : 0 );
}
