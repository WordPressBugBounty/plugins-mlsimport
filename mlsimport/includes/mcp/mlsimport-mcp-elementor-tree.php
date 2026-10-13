<?php
/**
 * AI tools (MCP), Elementor sites: pure functions on an Elementor document tree.
 *
 * An Elementor document is a tree: a list of elements, each holding its
 * children in 'elements'. These functions find, insert, remove and replace
 * one element by id anywhere in the tree and give back a new tree; nothing
 * here touches WordPress or Elementor, so the write tools can reason about
 * placement before saving and the functions can be checked in isolation.
 *
 * Public interface:
 *   mlsimport_mcp_elementor_element_id()                       a new 7-hex id
 *   mlsimport_mcp_elementor_tree_find( $elements, $id )        the element and its parent id
 *   mlsimport_mcp_elementor_tree_contains( $element, $id )     is $id in the element's subtree
 *   mlsimport_mcp_elementor_tree_remove( $elements, $id )      the tree without it
 *   mlsimport_mcp_elementor_tree_replace( $elements, $id, $e ) the tree with it replaced
 *   mlsimport_mcp_elementor_tree_insert( $elements, $e, $parent, $after, $position ) the tree with it added
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A new Elementor element id: 7 hex characters, as the editor makes them.
 *
 * @return string
 */
function mlsimport_mcp_elementor_element_id(): string {
	return dechex( wp_rand( 0x1000000, 0xfffffff ) );
}

/**
 * Find an element by id anywhere in the tree.
 *
 * @param array  $elements The elements of one level.
 * @param string $id       The id looked for.
 * @param string $parent_id The id of the element holding this level ('' at the top).
 * @return array|null [ 'element' => the element, 'parent' => its parent's id ], or null.
 */
function mlsimport_mcp_elementor_tree_find( array $elements, string $id, string $parent_id = '' ): ?array {
	foreach ( $elements as $element ) {
		$element = (array) $element;
		if ( (string) ( $element['id'] ?? '' ) === $id ) {
			return array(
				'element' => $element,
				'parent'  => $parent_id,
			);
		}
		$found = mlsimport_mcp_elementor_tree_find( (array) ( $element['elements'] ?? array() ), $id, (string) ( $element['id'] ?? '' ) );
		if ( null !== $found ) {
			return $found;
		}
	}
	return null;
}

/**
 * Is the id the element itself or anywhere below it?
 *
 * @param array  $element One element.
 * @param string $id      The id looked for.
 * @return bool
 */
function mlsimport_mcp_elementor_tree_contains( array $element, string $id ): bool {
	return (string) ( $element['id'] ?? '' ) === $id || null !== mlsimport_mcp_elementor_tree_find( (array) ( $element['elements'] ?? array() ), $id );
}

/**
 * The tree without the element of that id (and its children).
 *
 * @param array  $elements The elements of one level.
 * @param string $id       The id to remove.
 * @return array The level, reindexed.
 */
function mlsimport_mcp_elementor_tree_remove( array $elements, string $id ): array {
	$kept = array();
	foreach ( $elements as $element ) {
		$element = (array) $element;
		if ( (string) ( $element['id'] ?? '' ) === $id ) {
			continue;
		}
		if ( ! empty( $element['elements'] ) ) {
			$element['elements'] = mlsimport_mcp_elementor_tree_remove( (array) $element['elements'], $id );
		}
		$kept[] = $element;
	}
	return $kept;
}

/**
 * The tree with the element of that id replaced by another, in place.
 *
 * @param array  $elements    The elements of one level.
 * @param string $id          The id to replace.
 * @param array  $replacement The element to put there.
 * @return array The level.
 */
function mlsimport_mcp_elementor_tree_replace( array $elements, string $id, array $replacement ): array {
	foreach ( $elements as $index => $element ) {
		$element = (array) $element;
		if ( (string) ( $element['id'] ?? '' ) === $id ) {
			$elements[ $index ] = $replacement;
			continue;
		}
		if ( ! empty( $element['elements'] ) ) {
			$element['elements'] = mlsimport_mcp_elementor_tree_replace( (array) $element['elements'], $id, $replacement );
			$elements[ $index ]  = $element;
		}
	}
	return array_values( $elements );
}

/**
 * The tree with an element added.
 *
 * Step by step:
 * 1. With $after, the element goes right after that sibling, wherever it is.
 * 2. Otherwise it goes into $parent ('' is the top level), first when
 *    $position is start, last otherwise.
 * 3. Neither place found: null, so the caller can refuse.
 *
 * @param array  $elements The elements of one level.
 * @param array  $element  The element to add.
 * @param string $parent_id The id of the element to put it in ('' for the top level).
 * @param string $after    The id of the sibling to put it after ('' for none).
 * @param string $position start or end, when $after is ''.
 * @return array|null The level with the element, or null when the place does not exist.
 */
function mlsimport_mcp_elementor_tree_insert( array $elements, array $element, string $parent_id, string $after, string $position ): ?array {
	// Step 1: after a sibling of this level.
	if ( '' !== $after ) {
		foreach ( $elements as $index => $sibling ) {
			if ( (string) ( $sibling['id'] ?? '' ) === $after ) {
				array_splice( $elements, $index + 1, 0, array( $element ) );
				return array_values( $elements );
			}
		}
	} elseif ( '' === $parent_id ) {
		// Step 2: this level is the top level asked for.
		return 'start' === $position ? array_merge( array( $element ), $elements ) : array_merge( $elements, array( $element ) );
	}

	// Step 3: look deeper.
	foreach ( $elements as $index => $candidate ) {
		$candidate = (array) $candidate;
		$children  = (array) ( $candidate['elements'] ?? array() );
		if ( '' === $after && (string) ( $candidate['id'] ?? '' ) === $parent_id ) {
			$candidate['elements'] = 'start' === $position ? array_merge( array( $element ), $children ) : array_merge( $children, array( $element ) );
			$elements[ $index ]    = $candidate;
			return array_values( $elements );
		}
		if ( empty( $children ) ) {
			continue;
		}
		$deeper = mlsimport_mcp_elementor_tree_insert( $children, $element, $parent_id, $after, $position );
		if ( null !== $deeper ) {
			$candidate['elements'] = $deeper;
			$elements[ $index ]    = $candidate;
			return array_values( $elements );
		}
	}
	return null;
}
