<?php
/**
 * AI tools (MCP), standalone mode only: build a page from the page blocks.
 *
 * The tool create-page writes a WordPress page whose content is a list of
 * the plugin's page blocks (search form, property list, slider, map ...), in
 * the format of the page builder the client uses:
 *   - gutenberg: one dynamic block comment per page block
 *     (<!-- wp:mlsimport/<slug> {options} /-->), the markup the block editor
 *     saves itself. The block's registered attributes decide the value types.
 *   - elementor: the _elementor_data document (one container holding one
 *     MLSImport widget per page block) and the meta Elementor needs to open
 *     the page in its editor.
 * Both paths render through the one page-block dispatcher, so the page looks
 * the same as one built by hand in that editor. The tool add-page-blocks
 * (mlsimport-mcp-standalone-pages-add.php) appends page blocks to a page that
 * exists, with the same checks and value rules; page-blocks, update-page-block
 * and remove-page-block (mlsimport-mcp-standalone-pages-edit.php) read and
 * change the blocks a page already has.
 *
 * Public interface:
 *   mlsimport_mcp_standalone_page_tools()      the tools of this file
 *   mlsimport_mcp_page_blocks_schema()         input schema of a blocks list
 *   mlsimport_mcp_page_blocks_input( $blocks ) check the blocks the AI sent
 *   mlsimport_mcp_gutenberg_name( $slug )      the editor's block name
 *   mlsimport_mcp_gutenberg_attributes( $slug, $options ) typed attributes
 *   mlsimport_mcp_gutenberg_block( $slug, $options ) one block comment
 *   mlsimport_mcp_create_page( $input )        tool: create-page
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
function mlsimport_mcp_standalone_page_tools(): array {
	return array(
		'create-page'     => array(
			'label'       => 'Create a page from page blocks',
			'description' => 'Creates a WordPress page built from the plugin\'s page blocks, for the Gutenberg block editor or for Elementor. Block names and their options come from list-page-blocks. The page is a draft unless status is publish; the answer has the link to preview it and the link to edit it in the chosen builder.',
			'input'       => array(
				'title'   => array( 'type' => 'string' ),
				'builder' => array(
					'type'        => 'string',
					'enum'        => array( 'gutenberg', 'elementor' ),
					'description' => 'The page builder the site uses: gutenberg (the WordPress block editor) or elementor.',
				),
				'blocks'  => mlsimport_mcp_page_blocks_schema(),
				'status'  => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'publish' ),
					'description' => 'draft (default) or publish.',
				),
			),
			'required'    => array( 'title', 'builder', 'blocks' ),
			'callback'    => 'mlsimport_mcp_create_page',
			'changes'     => true,
		),
		'add-page-blocks' => array(
			'label'       => 'Add page blocks to an existing page',
			'description' => 'Appends the plugin\'s page blocks to the end of an existing page. The page builder is read from the page itself: a page built with Elementor gets the blocks as Elementor widgets, any other page gets them as blocks of the block editor. Block names and their options come from list-page-blocks. The answer has the link to preview the page and the link to edit it in its builder.',
			'input'       => array(
				'page_id' => array(
					'type'        => 'integer',
					'description' => 'The id of the page to add to.',
				),
				'blocks'  => mlsimport_mcp_page_blocks_schema(),
			),
			'required'    => array( 'page_id', 'blocks' ),
			'callback'    => 'mlsimport_mcp_add_page_blocks',
			'changes'     => true,
		),
	);
}

/**
 * The input schema of the blocks list, shared by create-page and add-page-blocks.
 *
 * @return array<string, mixed> JSON schema of the blocks array.
 */
function mlsimport_mcp_page_blocks_schema(): array {
	return array(
		'type'        => 'array',
		'description' => 'The page blocks in page order. Each: { "block": slug from list-page-blocks, "options": { option name: value } }.',
		'items'       => array(
			'type'                 => 'object',
			'properties'           => array(
				'block'   => array( 'type' => 'string' ),
				'options' => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'block' ),
			'additionalProperties' => false,
		),
	);
}

/**
 * Check the blocks the AI sent against the page-block manifest.
 *
 * Step by step:
 * 1. At least one block.
 * 2. Every block slug is a registered page block; a wrong one is refused
 *    naming the slugs that exist.
 * 3. Every option is one of that block's options; a wrong one is refused
 *    naming the options of that block.
 *
 * @param array $blocks The blocks input: list of { block, options }.
 * @return array|WP_Error The blocks as [ slug => options ] rows, or the refusal.
 */
function mlsimport_mcp_page_blocks_input( array $blocks ) {
	$manifest = mlsimport_get_page_blocks();
	if ( empty( $blocks ) ) {
		return new WP_Error( 'mlsimport_blocks_missing', __( 'Name at least one page block. See them with list-page-blocks.', 'mlsimport' ) );
	}

	$rows = array();
	foreach ( $blocks as $block ) {
		$slug    = sanitize_key( (string) ( $block['block'] ?? '' ) );
		$options = isset( $block['options'] ) && is_array( $block['options'] ) ? $block['options'] : array();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return new WP_Error(
				'mlsimport_block_unknown',
				/* translators: 1: the unknown block, 2: the block slugs that exist. */
				sprintf( __( '"%1$s" is not a page block. The page blocks are: %2$s.', 'mlsimport' ), $slug, implode( ', ', array_keys( $manifest ) ) )
			);
		}
		$unknown = array_diff( array_map( 'strval', array_keys( $options ) ), array_keys( (array) $manifest[ $slug ]['args'] ) );
		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'mlsimport_block_option_unknown',
				/* translators: 1: the block, 2: the unknown options, 3: the options of that block. */
				sprintf( __( 'The %1$s block has no option %2$s. Its options are: %3$s.', 'mlsimport' ), $slug, implode( ', ', array_map( 'sanitize_text_field', $unknown ) ), implode( ', ', array_keys( (array) $manifest[ $slug ]['args'] ) ) )
			);
		}
		$rows[] = array(
			'slug'    => $slug,
			'options' => $options,
		);
	}
	return $rows;
}

/**
 * The block editor name of a page block: mlsimport/<slug with dashes>.
 *
 * @param string $slug Page block slug, for example item_list.
 * @return string The registered block name.
 */
function mlsimport_mcp_gutenberg_name( string $slug ): string {
	return 'mlsimport/' . str_replace( '_', '-', $slug );
}

/**
 * The block attributes of a page block's options, in the editor's types.
 *
 * Each option is cast to the type of the registered attribute of that name:
 * boolean, number, array, or string. A list sent for a string attribute
 * becomes the comma list the block's filters read; a boolean sent for a
 * string attribute becomes '1' or '' (the toggle convention of the listings
 * blocks).
 *
 * @param string $slug    Page block slug, for example item_list.
 * @param array  $options Option name => value, as the AI sent them.
 * @return array Attribute name => typed value.
 */
function mlsimport_mcp_gutenberg_attributes( string $slug, array $options ): array {
	$block_type = WP_Block_Type_Registry::get_instance()->get_registered( mlsimport_mcp_gutenberg_name( $slug ) );
	$attributes = $block_type ? (array) $block_type->attributes : array();

	$attrs = array();
	foreach ( $options as $option => $value ) {
		$type = (string) ( $attributes[ $option ]['type'] ?? 'string' );
		if ( 'boolean' === $type ) {
			$attrs[ $option ] = (bool) $value;
		} elseif ( 'number' === $type || 'integer' === $type ) {
			$attrs[ $option ] = $value + 0;
		} elseif ( 'array' === $type || 'object' === $type ) {
			$attrs[ $option ] = (array) $value;
		} elseif ( is_bool( $value ) ) {
			$attrs[ $option ] = $value ? '1' : '';
		} else {
			$attrs[ $option ] = is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value;
		}
	}
	return $attrs;
}

/**
 * One page block as the block editor saves it: a dynamic block comment
 * carrying the typed options as JSON (no options, no JSON).
 *
 * @param string $slug    Page block slug, for example item_list.
 * @param array  $options Option name => value, as the AI sent them.
 * @return string The block comment.
 */
function mlsimport_mcp_gutenberg_block( string $slug, array $options ): string {
	$attrs = mlsimport_mcp_gutenberg_attributes( $slug, $options );
	return '<!-- wp:' . mlsimport_mcp_gutenberg_name( $slug ) . ( empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs ) ) . ' /-->';
}

/**
 * Tool create-page: write a page made of page blocks for one page builder.
 *
 * Step by step:
 * 1. Refuse an unknown block or option, and the elementor builder when
 *    Elementor is not active, before anything is written.
 * 2. Insert the page: for gutenberg its content is the block comments; for
 *    elementor the content is empty and the document is saved with
 *    Elementor's own document save (a failed save removes the page again).
 * 3. Answer with the page id, its preview link and the edit link of the builder.
 *
 * @param array $input {
 *     @type string $title   Page title.
 *     @type string $builder gutenberg or elementor.
 *     @type array  $blocks  List of { block, options }.
 *     @type string $status  draft (default) or publish.
 * }
 * @return array|WP_Error The page id and links, or the refusal.
 */
function mlsimport_mcp_create_page( array $input ) {
	// Step 1: every block and option must exist before anything is written.
	$rows = mlsimport_mcp_page_blocks_input( isset( $input['blocks'] ) && is_array( $input['blocks'] ) ? $input['blocks'] : array() );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$elementor = 'elementor' === ( $input['builder'] ?? '' );
	if ( $elementor && ! class_exists( '\Elementor\Plugin' ) ) {
		return new WP_Error( 'mlsimport_elementor_missing', __( 'Elementor is not active on this site. Use the gutenberg builder, or activate Elementor first.', 'mlsimport' ) );
	}

	$content = array();
	foreach ( $elementor ? array() : $rows as $row ) {
		$content[] = mlsimport_mcp_gutenberg_block( $row['slug'], $row['options'] );
	}

	// Step 2: the page. A draft unless the client asked to publish.
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
			'post_status'  => 'publish' === ( $input['status'] ?? '' ) ? 'publish' : 'draft',
			'post_content' => wp_slash( implode( "\n\n", $content ) ),
		),
		true
	);
	if ( is_wp_error( $page_id ) ) {
		return $page_id;
	}
	$edit_url = (string) get_edit_post_link( $page_id, 'raw' );
	if ( $elementor ) {
		$edit_url = mlsimport_mcp_elementor_save( (int) $page_id, $rows );
		if ( is_wp_error( $edit_url ) ) {
			wp_delete_post( $page_id, true );
			return $edit_url;
		}
	}

	// Step 3: the answer: where to look at it and where to edit it.
	return array(
		'page_id'  => (int) $page_id,
		'url'      => (string) get_permalink( $page_id ),
		'edit_url' => $edit_url,
	);
}
