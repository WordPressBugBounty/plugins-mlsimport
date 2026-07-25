<?php
/**
 * Standalone (theme_id 990) Agents Directory page block ("Team Directory").
 *
 * A grid/directory of ALL agents (the mlsimport_agent CPT), each tile linking to
 * that agent's page — the "meet the team" surface, distinct from the single Agent
 * Card and the per-agent listings grid. Offices are not a separate entity in this
 * plugin (they are the ListOfficeName text on each agent), so an office shows as a
 * tile subtitle, not as its own tile.
 *
 * Like the Category widgets, ONE resolver (mlsimport_agents_directory_items) and
 * ONE tile renderer (mlsimport_agent_directory_tile) back the block — and, through
 * the page-block dispatcher, the Shortcode, Gutenberg and Elementor surfaces all
 * emit the same markup. The featured-first sort carries no WordPress and is
 * unit-tested in isolation. See page-block-registry.php and docs/adr/0007.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order agent view-models featured-first, preserving the incoming order within the
 * featured and non-featured groups (a stable partition). The resolver feeds this a
 * name-ordered list, so the result is "featured agents (by name), then the rest (by
 * name)". Pure: no WordPress.
 *
 * @param array<int,array<string,mixed>> $items View-models carrying a 'featured' flag.
 * @return array<int,array<string,mixed>>
 */
function mlsimport_agents_sort_featured_first( array $items ): array {
	$featured = array();
	$rest     = array();
	foreach ( $items as $vm ) {
		if ( ! empty( $vm['featured'] ) ) {
			$featured[] = $vm;
		} else {
			$rest[] = $vm;
		}
	}
	return array_merge( $featured, $rest );
}

/**
 * Parse a comma list of agent post ids into positive ints (order preserved,
 * duplicates and non-positive values dropped). Pure: no WordPress.
 *
 * @param mixed $raw Comma string (or already an array).
 * @return int[]
 */
function mlsimport_agents_parse_ids( $raw ): array {
	if ( is_array( $raw ) ) {
		$parts = $raw;
	} elseif ( is_string( $raw ) ) {
		$parts = explode( ',', $raw );
	} else {
		$parts = array();
	}

	$ids = array();
	foreach ( $parts as $part ) {
		$id = (int) trim( (string) $part );
		if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
	}
	return $ids;
}

/**
 * Resolve the block's selection args into an ordered list of agent view-models:
 * id, name, url (the agent's own page), photo_url, office, title and a featured
 * flag. An explicit id list keeps that exact given order; otherwise every published
 * agent is returned featured-first (then by name), optionally capped and/or limited
 * to featured agents.
 *
 * @param array $args { agents, count, featured_only, sort }.
 * @return array<int,array<string,mixed>>
 */
function mlsimport_agents_directory_items( array $args ): array {
	if ( ! post_type_exists( 'mlsimport_agent' ) ) {
		return array();
	}

	$ids           = mlsimport_agents_parse_ids( $args['agents'] ?? '' );
	$count         = isset( $args['count'] ) ? max( 0, (int) $args['count'] ) : 0;
	$featured_only = ! in_array( (string) ( $args['featured_only'] ?? '' ), array( '', '0', 'no', 'false' ), true );
	// Order: 'featured' (verified-first, then A–Z — the default), or a plain
	// alphabetical A–Z / Z–A by name. An explicit id set always keeps its own order.
	$sort          = isset( $args['sort'] ) && '' !== (string) $args['sort'] ? (string) $args['sort'] : 'featured';

	$query = array(
		'post_type'      => 'mlsimport_agent',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'name_desc' === $sort ? 'DESC' : 'ASC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	);
	if ( ! empty( $ids ) ) {
		// Explicit set: that exact ordered list.
		$query['post__in'] = $ids;
		$query['orderby']  = 'post__in';
	}
	if ( $featured_only ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- operator-set flag on a small CPT.
		$query['meta_key']   = 'mlsimport_featured';
		$query['meta_value'] = '1';
	}

	$agent_ids = get_posts( $query );
	if ( empty( $agent_ids ) ) {
		return array();
	}

	$out = array();
	foreach ( $agent_ids as $aid ) {
		$aid   = (int) $aid;
		$photo = (int) get_post_thumbnail_id( $aid );
		$out[] = array(
			'id'        => $aid,
			'name'      => (string) get_the_title( $aid ),
			'url'       => (string) get_permalink( $aid ),
			'photo_url' => $photo > 0 ? (string) wp_get_attachment_image_url( $photo, 'medium' ) : '',
			'office'    => (string) get_post_meta( $aid, 'mlsimport_ListOfficeName', true ),
			'title'     => (string) get_post_meta( $aid, 'mlsimport_JobTitle', true ),
			'featured'  => '1' === (string) get_post_meta( $aid, 'mlsimport_featured', true ),
		);
	}

	// Verified-first only for the default order on a non-explicit set; the plain
	// alphabetical orders keep the query's title order, and an id set keeps its own.
	if ( empty( $ids ) && 'featured' === $sort ) {
		$out = mlsimport_agents_sort_featured_first( $out );
	}
	if ( $count > 0 ) {
		$out = array_slice( $out, 0, $count );
	}

	return $out;
}

/**
 * Render one agent tile — the single tile markup for the directory. Photo (or a
 * neutral placeholder), name, optional job title and optional office subtitle; the
 * whole tile links to the agent's own page. Reuses the .mlsimport-agent-card
 * vocabulary the agents archive already ships.
 *
 * @param array $vm    Agent view-model from mlsimport_agents_directory_items().
 * @param array $flags { show_title, show_office } booleans.
 * @return string
 */
function mlsimport_agent_directory_tile( array $vm, array $flags ): string {
	$url  = '' !== (string) $vm['url'] ? (string) $vm['url'] : '#';
	$name = (string) $vm['name'];

	$photo = '' !== (string) $vm['photo_url']
		? '<img class="mlsimport-agent-card__img" src="' . esc_url( $vm['photo_url'] ) . '" alt="" loading="lazy" />'
		: '<span class="mlsimport-agent-card__img mlsimport-agent-card__img--empty" aria-hidden="true"></span>';

	$title = '';
	if ( ! empty( $flags['show_title'] ) && '' !== (string) $vm['title'] ) {
		$title = '<span class="mlsimport-agent-card__title">' . esc_html( $vm['title'] ) . '</span>';
	}

	$office = '';
	if ( ! empty( $flags['show_office'] ) && '' !== (string) $vm['office'] ) {
		$office = '<span class="mlsimport-agent-card__office">' . esc_html( $vm['office'] ) . '</span>';
	}

	return '<a class="mlsimport-agent-card mlsimport-agent-card--dir" href="' . esc_url( $url ) . '">'
		. '<span class="mlsimport-agent-card__photo">' . $photo . '</span>'
		. '<span class="mlsimport-agent-card__body">'
		. '<span class="mlsimport-agent-card__name">' . esc_html( $name ) . '</span>'
		. $title
		. $office
		. '</span>'
		. '</a>';
}

/**
 * Agents Directory — every agent (or a hand-picked/featured subset) as a grid of
 * tiles, each linking to that agent's page. Backs the [mlsimport_agents_directory]
 * shortcode, the mlsimport/agents-directory block and the Elementor "Team Directory"
 * widget.
 *
 * @param array $args Block args.
 * @return string
 */
function mlsimport_page_block_agents_directory( array $args ): string {
	$items = mlsimport_agents_directory_items( $args );
	if ( empty( $items ) ) {
		return '';
	}

	$show_title  = ! in_array( (string) ( $args['show_title'] ?? '1' ), array( '', '0', 'no', 'false' ), true );
	$show_office = ! in_array( (string) ( $args['show_office'] ?? '1' ), array( '', '0', 'no', 'false' ), true );
	$flags       = array( 'show_title' => $show_title, 'show_office' => $show_office );

	$tiles = '';
	foreach ( $items as $vm ) {
		$tiles .= mlsimport_agent_directory_tile( $vm, $flags );
	}

	// Look controls as CSS custom properties the grid CSS reads; columns always set.
	$per_row     = in_array( (string) ( $args['per_row'] ?? '4' ), array( '2', '3', '4', '5', '6' ), true ) ? (string) $args['per_row'] : '4';
	$style_parts = array( '--mlsimport-agents-cols:' . $per_row );
	if ( isset( $args['gap'] ) && '' !== (string) $args['gap'] ) {
		$style_parts[] = '--mlsimport-agents-gap:' . (int) $args['gap'] . 'px';
	}
	if ( isset( $args['border_radius'] ) && '' !== (string) $args['border_radius'] ) {
		$style_parts[] = '--mlsimport-agents-radius:' . (int) $args['border_radius'] . 'px';
	}
	$style = ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"';

	return '<div class="mlsimport-page-block mlsimport-page-block--agents-directory mlsimport-agents-directory"' . $style . '>'
		. $tiles
		. '</div>';
}
