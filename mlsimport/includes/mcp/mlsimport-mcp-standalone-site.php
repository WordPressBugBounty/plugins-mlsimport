<?php
/**
 * AI tools (MCP), standalone mode only: design settings, page blocks and
 * category (term) settings.
 *
 * These tools exist only when the site runs in standalone mode (theme 990).
 * Each function is the body of one AI tool, a thin wrapper around the code
 * the site already uses:
 *   - get-design-settings / update-design-settings: the standalone design
 *     settings. The list of settings comes from mlsimport_standalone_settings_schema()
 *     (built from the one field registry), so a new setting appears here by
 *     itself, as it does on the settings page and in the Customizer. Saving
 *     goes through the option's registered sanitizer,
 *     mlsimport_sanitize_standalone_options().
 *   - list-page-blocks: mlsimport_get_page_blocks() and
 *     mlsimport_get_property_sections(), with the shortcode of each, so the
 *     AI can build pages.
 *   - term-settings / update-term-settings: the image and the HTML text of a
 *     category term, stored by Mlsimport_Term_Meta::save_values().
 *
 * A design setting that looks like a key or token (for example the map
 * service key) is never returned: it is reported as "set" or "not set".
 *
 * Public interface:
 *   mlsimport_mcp_standalone_site_tools()            the tools of this file
 *   mlsimport_mcp_get_design_settings()              tool: get-design-settings
 *   mlsimport_mcp_update_design_settings( $input )   tool: update-design-settings
 *   mlsimport_mcp_list_page_blocks()                 tool: list-page-blocks
 *   mlsimport_mcp_term_settings( $input )            tool: term-settings
 *   mlsimport_mcp_update_term_settings( $input )     tool: update-term-settings
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
function mlsimport_mcp_standalone_site_tools(): array {
	$term = array(
		'taxonomy' => array(
			'type'        => 'string',
			'description' => 'The taxonomy slug, for example mlsimport_city. list-delete-terms lists them.',
		),
		'term'     => array(
			'type'        => 'string',
			'description' => 'The term slug, from list-delete-terms.',
		),
	);

	return array(
		'get-design-settings'    => array(
			'label'       => 'Design settings',
			'description' => 'Reads the standalone design settings, grouped as on the settings page: every setting with its label, its kind, its allowed values and its current value.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_get_design_settings',
			'changes'     => false,
		),
		'update-design-settings' => array(
			'label'       => 'Change the design settings',
			'description' => 'Changes standalone design settings. Only the settings you name are changed; names and allowed values come from get-design-settings. A value that is not allowed falls back to the default of that setting.',
			'input'       => array(
				'settings' => array(
					'type'                 => 'object',
					'description'          => 'The settings to change, keyed by setting name.',
					'additionalProperties' => true,
				),
			),
			'required'    => array( 'settings' ),
			'callback'    => 'mlsimport_mcp_update_design_settings',
			'changes'     => true,
		),
		'list-page-blocks'       => array(
			'label'       => 'Page blocks and property sections',
			'description' => 'Reads the page blocks (search form, results, sliders, category tiles ...) and the single-property sections, each with its options and the shortcode to paste into a page. To build a page from page blocks, use create-page with the block slugs and option names read here; to add them to a page that exists, use add-page-blocks; to see, change or remove the blocks a page has, use page-blocks, update-page-block and remove-page-block.',
			'input'       => array(),
			'required'    => array(),
			'callback'    => 'mlsimport_mcp_list_page_blocks',
			'changes'     => false,
		),
		'term-settings'          => array(
			'label'       => 'Settings of a category term',
			'description' => 'Reads the image and the HTML text of one category term (a city, a property type ...), shown on that term\'s page and on category tiles.',
			'input'       => $term,
			'required'    => array( 'taxonomy', 'term' ),
			'callback'    => 'mlsimport_mcp_term_settings',
			'changes'     => false,
		),
		'update-term-settings'   => array(
			'label'       => 'Change the settings of a category term',
			'description' => 'Changes the image and/or the HTML text of one category term. image_id is the id of an image in the Media Library; 0 removes the image. A part you do not name keeps its value.',
			'input'       => $term + array(
				'image_id' => array( 'type' => 'integer' ),
				'html'     => array( 'type' => 'string' ),
			),
			'required'    => array( 'taxonomy', 'term' ),
			'callback'    => 'mlsimport_mcp_update_term_settings',
			'changes'     => true,
		),
	);
}

/**
 * Tool get-design-settings: every design setting with its current value.
 *
 * @return array The settings, grouped in sections.
 */
function mlsimport_mcp_get_design_settings(): array {
	$sections = array();
	foreach ( mlsimport_standalone_settings_schema() as $section ) {
		$fields = array();
		foreach ( $section['fields'] as $field ) {
			$value = mlsimport_standalone_option( $field['key'] );
			// A key or token is reported as set / not set, never as its value.
			if ( 1 === preg_match( '/(api_key|secret|token|password)/', $field['key'] ) ) {
				$value = '' === (string) $value ? 'not set' : 'set';
			}
			$row = array(
				'name'  => $field['key'],
				'label' => $field['label'],
				'type'  => $field['type'],
				'value' => $value,
			);
			if ( is_array( $field['options'] ) ) {
				$row['allowed_values'] = $field['options'];
			}
			$fields[] = $row;
		}
		$sections[] = array(
			'section'  => $section['title'],
			'settings' => $fields,
		);
	}
	return array( 'sections' => $sections );
}

/**
 * Tool update-design-settings: change the named design settings.
 *
 * @param array $input {
 *     @type array $settings Setting name => new value.
 * }
 * @return array|WP_Error The settings as saved, or the refusal.
 */
function mlsimport_mcp_update_design_settings( array $input ) {
	$changes = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();

	// Step 1: only settings the registry knows.
	$known   = mlsimport_standalone_field_registry();
	$unknown = array_diff( array_map( 'strval', array_keys( $changes ) ), array_keys( $known ) );
	if ( empty( $changes ) || ! empty( $unknown ) ) {
		return new WP_Error(
			'mlsimport_setting_unknown',
			empty( $changes )
				? __( 'Name at least one setting to change. See the settings with get-design-settings.', 'mlsimport' )
				/* translators: %s: the unknown setting names. */
				: sprintf( __( 'These are not design settings: %s. See the settings with get-design-settings.', 'mlsimport' ), implode( ', ', array_map( 'sanitize_text_field', $unknown ) ) )
		);
	}

	// Step 2: the saved settings with the named ones laid over them. The
	// sanitizer puts a setting that is missing back to its default, so the
	// whole set is always saved.
	$saved = get_option( MLSIMPORT_STANDALONE_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();
	foreach ( $changes as $name => $value ) {
		// The registry's text kinds are stored as text.
		$saved[ (string) $name ] = is_scalar( $value ) && 'int' !== $known[ $name ]['type'] ? (string) $value : $value;
	}

	// Step 3: update_option() runs the option's registered sanitizer
	// (mlsimport_sanitize_standalone_options()), as the settings page's save does.
	update_option( MLSIMPORT_STANDALONE_OPTION, $saved );

	return mlsimport_mcp_get_design_settings();
}

/**
 * Tool list-page-blocks: the page blocks and property sections with shortcodes.
 *
 * @return array blocks and property_sections; each with label, shortcode, options.
 */
function mlsimport_mcp_list_page_blocks(): array {
	// One row per manifest entry; $prefix is the shortcode prefix of the kind.
	$rows = static function ( array $manifest, string $prefix ): array {
		$list = array();
		foreach ( $manifest as $slug => $definition ) {
			$options = array();
			foreach ( (array) $definition['args'] as $name => $option ) {
				// taxonomy marks a term picker: it takes term ids, names or slugs.
				$options[ $name ] = array_intersect_key( (array) $option, array_flip( array( 'type', 'label', 'default', 'options', 'taxonomy' ) ) );
			}
			$list[] = array(
				'slug'      => (string) $slug,
				'label'     => (string) $definition['label'],
				'shortcode' => '[' . $prefix . $slug . ']',
				'options'   => $options,
			);
		}
		return $list;
	};

	return array(
		'blocks'            => $rows( mlsimport_get_page_blocks(), 'mlsimport_' ),
		'property_sections' => $rows( mlsimport_get_property_sections(), 'mlsimport_property_' ),
		'note'              => __( 'An option is written inside the shortcode, for example [mlsimport_results limit="12"]. create-page builds a Gutenberg or Elementor page from the page blocks, add-page-blocks appends them to an existing page (not the property sections, which belong to the single-property template). A multiselect option with a taxonomy (the city, property type ... pickers of the Categories blocks) takes a list of term names, slugs or ids; list-delete-terms names the terms of a taxonomy.', 'mlsimport' ),
	);
}

/**
 * Find a term of one of the plugin's taxonomies, or refuse.
 *
 * @param array $input taxonomy, term (slug).
 * @return WP_Term|WP_Error The term, or the refusal naming the next step.
 */
function mlsimport_mcp_plugin_term( array $input ) {
	$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
	$term     = in_array( $taxonomy, Mlsimport_Standalone_Cpt::taxonomy_slugs(), true )
		? get_term_by( 'slug', sanitize_title( (string) ( $input['term'] ?? '' ) ), $taxonomy )
		: false;
	if ( ! $term ) {
		return new WP_Error( 'mlsimport_term_unknown', __( 'That taxonomy or term does not exist. See them with list-delete-terms.', 'mlsimport' ) );
	}
	return $term;
}

/**
 * Tool term-settings: the image and HTML text of one category term.
 *
 * @param array $input taxonomy, term.
 * @return array|WP_Error The term's settings, or the refusal.
 */
function mlsimport_mcp_term_settings( array $input ) {
	$term = mlsimport_mcp_plugin_term( $input );
	if ( is_wp_error( $term ) ) {
		return $term;
	}

	$image_id = (int) get_term_meta( $term->term_id, Mlsimport_Term_Meta::IMAGE_KEY, true );
	return array(
		'taxonomy'  => $term->taxonomy,
		'term'      => $term->slug,
		'name'      => $term->name,
		'image_id'  => $image_id,
		'image_url' => $image_id > 0 ? (string) wp_get_attachment_url( $image_id ) : '',
		'html'      => (string) get_term_meta( $term->term_id, Mlsimport_Term_Meta::HTML_KEY, true ),
	);
}

/**
 * Tool update-term-settings: change the image and/or HTML text of one term.
 *
 * @param array $input taxonomy, term, image_id, html.
 * @return array|WP_Error The term's settings as saved, or the refusal.
 */
function mlsimport_mcp_update_term_settings( array $input ) {
	$term = mlsimport_mcp_plugin_term( $input );
	if ( is_wp_error( $term ) ) {
		return $term;
	}

	// Step 1: an image must be an image of the Media Library.
	$image_id = isset( $input['image_id'] )
		? Mlsimport_Term_Meta::image_id( (int) $input['image_id'] )
		: (int) get_term_meta( $term->term_id, Mlsimport_Term_Meta::IMAGE_KEY, true );
	if ( isset( $input['image_id'] ) && $image_id > 0 && ! wp_attachment_is_image( $image_id ) ) {
		return new WP_Error( 'mlsimport_image_unknown', __( 'That image_id is not an image in the Media Library.', 'mlsimport' ) );
	}

	// Step 2: the term screens' own write. A part that was not named keeps
	// its value: the image is sent back as it is, the HTML as null.
	Mlsimport_Term_Meta::save_values( (int) $term->term_id, $image_id, isset( $input['html'] ) ? (string) $input['html'] : null );

	return mlsimport_mcp_term_settings( $input );
}
