<?php
/**
 * AI tools (MCP): term picks of the page blocks, by id, name or slug.
 *
 * The Categories widgets (Category Slider, Display Categories) and the Blog
 * Posts block hold one term picker per taxonomy: a `multiselect` option with
 * a `taxonomy` in the page-block registry. The builders store term IDS in
 * them, and the widgets drop anything that is not a positive id
 * (mlsimport_category_parse_ids()). An AI knows the cities by NAME, so every
 * tool that writes such an option turns the names (or slugs, or ids) into
 * term ids first, and refuses a value that matches no term instead of saving
 * it silently (#372).
 *
 * Public interface:
 *   mlsimport_mcp_term_pick_args( $slug )              option => taxonomy of a block's term pickers
 *   mlsimport_mcp_term_picks( $taxonomy, $raw )        ids, names or slugs -> term ids (or the refusal)
 *   mlsimport_mcp_resolve_term_picks( $slug, $options ) the options with every term pick as ids
 *   mlsimport_mcp_term_pick_names( $slug, $options )    the picked terms as { id, name } rows, to echo back
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The term pickers of one page block: option name => taxonomy slug.
 *
 * @param string $slug Page block slug, for example category_slider.
 * @return array<string, string> Empty when the block has no term picker.
 */
function mlsimport_mcp_term_pick_args( string $slug ): array {
	$args  = (array) ( mlsimport_get_page_blocks()[ $slug ]['args'] ?? array() );
	$picks = array();
	foreach ( $args as $key => $arg ) {
		if ( 'multiselect' === ( $arg['type'] ?? '' ) && ! empty( $arg['taxonomy'] ) ) {
			$picks[ (string) $key ] = (string) $arg['taxonomy'];
		}
	}
	return $picks;
}

/**
 * Turn a list of term ids, names or slugs into term ids of one taxonomy.
 *
 * Step by step:
 * 1. A comma string or a list; blanks are dropped, order is kept.
 * 2. A whole number is a term id and must exist in the taxonomy; anything
 *    else is looked up by name, then by slug (WordPress matches the name
 *    without regard to case).
 * 3. The first value that matches no term refuses the whole list, naming
 *    the value and the taxonomy. Duplicates collapse to one.
 *
 * @param string $taxonomy The taxonomy slug.
 * @param mixed  $raw      The value as the AI sent it.
 * @return string[]|WP_Error The term ids as strings (the form the pickers store), or mlsimport_term_unknown.
 */
function mlsimport_mcp_term_picks( string $taxonomy, $raw ) {
	// Step 1.
	if ( is_array( $raw ) ) {
		$values = $raw;
	} elseif ( is_string( $raw ) || is_int( $raw ) ) {
		$values = explode( ',', (string) $raw );
	} else {
		$values = array();
	}

	$ids = array();
	foreach ( $values as $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			continue;
		}
		// Step 2.
		if ( ctype_digit( $value ) ) {
			$term = get_term( (int) $value, $taxonomy );
		} else {
			$term = get_term_by( 'name', $value, $taxonomy );
			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
			}
		}
		// Step 3.
		if ( ! $term || is_wp_error( $term ) ) {
			$object = get_taxonomy( $taxonomy );
			return new WP_Error(
				'mlsimport_term_unknown',
				sprintf(
					/* translators: 1: the value sent, 2: the taxonomy name. */
					__( '"%1$s" is not one of the %2$s on this site. list-delete-terms names them with their ids.', 'mlsimport' ),
					sanitize_text_field( $value ),
					$object ? $object->labels->name : $taxonomy
				)
			);
		}
		$id = (string) $term->term_id;
		if ( ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
	}
	return $ids;
}

/**
 * The options of one page block with every term pick resolved to term ids.
 *
 * Options that are not term pickers pass through untouched. An empty pick
 * (an empty list or '') stays empty: it clears the picker.
 *
 * @param string $slug    Page block slug.
 * @param array  $options Option name => value, as the AI sent them.
 * @return array|WP_Error The options, or the refusal of the first unknown term.
 */
function mlsimport_mcp_resolve_term_picks( string $slug, array $options ) {
	foreach ( mlsimport_mcp_term_pick_args( $slug ) as $key => $taxonomy ) {
		if ( ! array_key_exists( $key, $options ) || null === $options[ $key ] ) {
			continue;
		}
		$ids = mlsimport_mcp_term_picks( $taxonomy, $options[ $key ] );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$options[ $key ] = $ids;
	}
	return $options;
}

/**
 * The picked terms of one page block's options, as the AI should read them
 * back: option name => list of { id, name }, in pick order.
 *
 * Reads the stored ids (a list or a comma string); an id that is no longer a
 * term is left out, so the answer shows what the widget will really show.
 * Pickers with nothing picked are left out.
 *
 * @param string $slug    Page block slug.
 * @param array  $options Option name => stored value.
 * @return array<string, array<int, array{id:int, name:string}>>
 */
function mlsimport_mcp_term_pick_names( string $slug, array $options ): array {
	$picked = array();
	foreach ( mlsimport_mcp_term_pick_args( $slug ) as $key => $taxonomy ) {
		$rows = array();
		foreach ( mlsimport_category_parse_ids( $options[ $key ] ?? '' ) as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$rows[] = array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
				);
			}
		}
		if ( ! empty( $rows ) ) {
			$picked[ $key ] = $rows;
		}
	}
	return $picked;
}
