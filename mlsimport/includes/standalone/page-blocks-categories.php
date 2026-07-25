<?php
/**
 * Standalone (theme_id 990) Category widgets: Category Slider + Display Categories.
 *
 * The WPResidence "places" widgets, ported to the MLSImport standalone taxonomies.
 * Both widgets show a set of taxonomy TERMS (property types, listing types, cities,
 * areas, counties, …) as tiles — each tile is the term's featured image
 * (Mlsimport_Term_Meta::IMAGE_KEY), its name, and an optional listing count, linking
 * to the term archive. The Slider lays the tiles in a Splide carousel; the List lays
 * them in a grid with three design types.
 *
 * ONE resolver (mlsimport_category_terms) and ONE tile renderer
 * (mlsimport_category_tile) back BOTH widgets, and — through the page-block
 * dispatcher — the Shortcode, Gutenberg and Elementor surfaces all emit the same
 * markup. The pure helpers (id parsing + CSS-var string) carry no WordPress and are
 * unit-tested in isolation. See page-block-registry.php and docs/adr/0007.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-term-meta.php';

/**
 * Parse a comma list of term ids into a list of positive ints (order preserved,
 * duplicates and non-positive values dropped). Pure: no WordPress.
 *
 * @param mixed $raw Comma string (or already an array).
 * @return int[]
 */
function mlsimport_category_parse_ids( $raw ): array {
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
 * Build a CSS custom-property declaration string from a map, skipping empty
 * values. Pure (no escaping, no WordPress): the caller wraps the result in an
 * esc_attr'd style attribute. e.g. ['--a'=>'8px','--b'=>'','--c'=>'#fff'] =>
 * "--a:8px;--c:#fff".
 *
 * @param array<string,string|int> $vars Custom property => value.
 * @return string
 */
function mlsimport_category_css_vars( array $vars ): string {
	$out = array();
	foreach ( $vars as $prop => $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( '' === $value || null === $value ) {
			continue;
		}
		$out[] = $prop . ':' . $value;
	}
	return implode( ';', $out );
}

/**
 * Resolve a Category widget's selection into an ordered list of term view-models:
 * id, name, url, count and image_url (the term featured image). Shared by both
 * Category widgets across all builders.
 *
 * The operator picks terms per taxonomy — $args carries one entry per plugin
 * taxonomy slug (e.g. $args['mlsimport_city'] = a term-id array, or a comma string
 * on the shortcode). The widget shows EXACTLY the picked terms — in the plugin's
 * taxonomy order then pick order — combining picks across taxonomies. A hand-picked
 * term shows even with no listings.
 *
 * With NOTHING picked (a freshly-inserted widget), it falls back to a sensible
 * default so the tile set is useful on sight rather than blank — see
 * mlsimport_category_default_terms(). The fallback keys on whether any pick was MADE,
 * not on whether the picks resolved: a deliberately picked but missing/deleted term
 * id still renders empty (the picker had an intent; honour it), only a wholly
 * unconfigured widget seeds the default.
 *
 * @param array $args Block args: one key per taxonomy slug.
 * @return array<int,array<string,mixed>>
 */
function mlsimport_category_terms( array $args ): array {
	$valid = class_exists( 'Mlsimport_Standalone_Cpt' ) ? Mlsimport_Standalone_Cpt::taxonomy_slugs() : array();
	if ( empty( $valid ) ) {
		return array();
	}

	$out    = array();
	$picked = false;
	foreach ( $valid as $taxonomy ) {
		$ids = mlsimport_category_parse_ids( $args[ $taxonomy ] ?? '' );
		if ( empty( $ids ) ) {
			continue;
		}
		$picked = true;
		$terms  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $ids,
				'orderby'    => 'include',
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
			$out = array_merge( $out, mlsimport_category_term_vms( $terms ) );
		}
	}

	// Unconfigured widget → seed a default set so it is useful on insert.
	if ( ! $picked ) {
		$out = mlsimport_category_default_terms( $valid );
	}

	return $out;
}

/**
 * The default tile set for an unconfigured Category widget: the first plugin
 * taxonomy (in taxonomy_slugs() order) that has any terms, its most-populated terms
 * first, capped so a large catalog doesn't flood the widget. This makes the Category
 * Slider / Display Categories useful the moment they are dropped in — no manual term
 * picking required — while a configured widget still shows exactly what was picked.
 *
 * @param string[] $valid Plugin taxonomy slugs, in order.
 * @return array<int,array<string,mixed>>
 */
function mlsimport_category_default_terms( array $valid ): array {
	/** Filter how many tiles an unconfigured Category widget seeds. @since 7.1 */
	$limit = (int) apply_filters( 'mlsimport_category_default_count', 8 );
	if ( $limit < 1 || ! function_exists( 'get_terms' ) ) {
		return array();
	}

	foreach ( $valid as $taxonomy ) {
		// hide_empty:false so a brand-new catalog (terms imported before any listing
		// is attached) still previews; orderby count so the biggest categories lead.
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => $limit,
			)
		);
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return mlsimport_category_term_vms( $terms );
		}
	}
	return array();
}

/**
 * Build term view-models (id, name, url, count, image_url) from WP_Term objects.
 * The image is the term featured image (Mlsimport_Term_Meta::IMAGE_KEY).
 *
 * @param array $terms WP_Term list from get_terms().
 * @return array<int,array<string,mixed>>
 */
function mlsimport_category_term_vms( array $terms ): array {
	$out = array();
	foreach ( $terms as $term ) {
		if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
			continue;
		}
		$image_id  = (int) get_term_meta( $term->term_id, Mlsimport_Term_Meta::IMAGE_KEY, true );
		$image_url = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'large' ) : '';
		$link      = get_term_link( $term );
		$out[]     = array(
			'id'        => (int) $term->term_id,
			'name'      => (string) $term->name,
			'url'       => is_wp_error( $link ) ? '' : (string) $link,
			'count'     => (int) $term->count,
			'image_url' => $image_url,
		);
	}
	return $out;
}

/**
 * The terms of a taxonomy as an id => name option map, for the Category widgets'
 * per-taxonomy term pickers. Called ONLY by the block-editor / Elementor adapters
 * when they build the inspector controls — never on the front end, which reads the
 * saved picks and never needs the option list.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return array<string,string> term id => term name (ordered by name).
 */
function mlsimport_category_term_options( string $taxonomy ): array {
	if ( ! function_exists( 'get_terms' ) || ! taxonomy_exists( $taxonomy ) ) {
		return array();
	}
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
		return array();
	}
	$options = array();
	foreach ( $terms as $term ) {
		$options[ (string) $term->term_id ] = (string) $term->name;
	}
	return $options;
}

/**
 * Render one term tile — the single tile markup shared by both Category widgets.
 * The term image is the featured image; the title (and optional listing count)
 * overlay it; the whole tile links to the term archive.
 *
 * @param array $vm         Term view-model from mlsimport_category_terms().
 * @param bool  $show_count Append a "N listings" tagline.
 * @return string
 */
function mlsimport_category_tile( array $vm, bool $show_count ): string {
	$url  = '' !== $vm['url'] ? $vm['url'] : '#';
	$name = (string) $vm['name'];

	$img = '' !== $vm['image_url']
		? '<span class="mlsimport-cat-tile__img" style="background-image:url(' . esc_url( $vm['image_url'] ) . ');" role="img" aria-label="' . esc_attr( $name ) . '"></span>'
		: '<span class="mlsimport-cat-tile__img mlsimport-cat-tile__img--empty" aria-hidden="true"></span>';

	$tagline = '';
	if ( $show_count ) {
		$n       = (int) $vm['count'];
		/* translators: %s: number of listings in a category. */
		$label   = sprintf( _n( '%s listing', '%s listings', $n, 'mlsimport' ), number_format_i18n( $n ) );
		$tagline = '<span class="mlsimport-cat-tile__tagline">' . esc_html( $label ) . '</span>';
	}

	return '<a class="mlsimport-cat-tile" href="' . esc_url( $url ) . '">'
		. $img
		. '<span class="mlsimport-cat-tile__overlay"></span>'
		. '<span class="mlsimport-cat-tile__body">'
		. '<span class="mlsimport-cat-tile__title">' . esc_html( $name ) . '</span>'
		. $tagline
		. '</span>'
		. '</a>';
}

/**
 * Shared style attribute for a Category widget wrapper — the tile look controls
 * (height, gap, radius, padding, title/tagline margins, typography) as CSS custom
 * properties the tile CSS reads. Only set values are emitted.
 *
 * @param array    $args Block args.
 * @param string[] $keys Which look controls apply to this widget.
 * @return string ' style="…"' or ''.
 */
function mlsimport_category_style_attr( array $args, array $keys ): string {
	$px = static function ( $v ) {
		return ( '' === (string) $v || null === $v ) ? '' : (int) $v . 'px';
	};

	$all = array(
		'--mlsimport-cat-height'     => isset( $args['item_height'] ) ? (string) $args['item_height'] : '',
		'--mlsimport-cat-gap'        => isset( $args['gap'] ) ? $px( $args['gap'] ) : '',
		'--mlsimport-cat-radius'     => isset( $args['border_radius'] ) ? $px( $args['border_radius'] ) : '',
		'--mlsimport-cat-pad'        => isset( $args['text_padding'] ) ? $px( $args['text_padding'] ) : '',
		'--mlsimport-cat-title-mb'   => isset( $args['title_margin'] ) ? $px( $args['title_margin'] ) : '',
		'--mlsimport-cat-tagline-mb' => isset( $args['tagline_margin'] ) ? $px( $args['tagline_margin'] ) : '',
		'--mlsimport-cat-title-size' => isset( $args['title_size'] ) ? $px( $args['title_size'] ) : '',
		'--mlsimport-cat-title-color' => isset( $args['title_color'] ) ? (string) $args['title_color'] : '',
		'--mlsimport-cat-cols'       => isset( $args['per_row'] ) ? (string) (int) $args['per_row'] : '',
		'--mlsimport-cat-min-width'  => isset( $args['min_width'] ) ? $px( $args['min_width'] ) : '',
		'--mlsimport-cat-border-w'   => isset( $args['border_width'] ) ? $px( $args['border_width'] ) : '',
		'--mlsimport-cat-border-c'   => isset( $args['border_color'] ) ? (string) $args['border_color'] : '',
	);

	$map = array();
	foreach ( $keys as $key ) {
		if ( isset( $all[ $key ] ) ) {
			$map[ $key ] = $all[ $key ];
		}
	}

	$style = mlsimport_category_css_vars( $map );
	return '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '';
}

/**
 * Category Slider — the selected taxonomy terms as a Splide carousel of image
 * tiles. Reuses the property slider's Splide assets; data-per-page drives how many
 * tiles show per view. Backs the [mlsimport_category_slider] shortcode, the
 * mlsimport/category-slider block and the Elementor "Category Slider" widget.
 *
 * @param array $args Block args.
 * @return string
 */
function mlsimport_page_block_category_slider( array $args ): string {
	$terms = mlsimport_category_terms( $args );
	if ( empty( $terms ) ) {
		return '';
	}

	$per_row = in_array( (string) ( $args['per_row'] ?? '3' ), array( '1', '2', '3', '4', '6' ), true ) ? (string) $args['per_row'] : '3';
	$design  = in_array( (string) ( $args['design'] ?? '1' ), array( '1', '2', '3' ), true ) ? (string) $args['design'] : '1';
	$show    = ! in_array( (string) ( $args['show_count'] ?? '1' ), array( '', '0', 'no', 'false' ), true );
	$gap     = ( isset( $args['gap'] ) && '' !== (string) $args['gap'] ) ? (int) $args['gap'] . 'px' : '';

	$slides = '';
	foreach ( $terms as $vm ) {
		$slides .= '<li class="splide__slide">' . mlsimport_category_tile( $vm, $show ) . '</li>';
	}

	$gap_attr = '' !== $gap ? ' data-gap="' . esc_attr( $gap ) . '"' : '';

	$style = mlsimport_category_style_attr(
		$args,
		array( '--mlsimport-cat-height', '--mlsimport-cat-gap', '--mlsimport-cat-radius', '--mlsimport-cat-pad', '--mlsimport-cat-title-mb', '--mlsimport-cat-tagline-mb', '--mlsimport-cat-title-size', '--mlsimport-cat-title-color' )
	);

	// The design modifier rides the outer wrapper — an ancestor of every tile — so the
	// one design ruleset (mlsimport_category_tile markup is identical everywhere) styles
	// the slider tiles exactly as it does the Display Categories grid tiles.
	return '<div class="mlsimport-page-block mlsimport-page-block--category-slider mlsimport-cat--design-' . esc_attr( $design ) . '"' . $style . '>'
		. '<div class="mlsimport-category-slider splide" data-per-page="' . esc_attr( $per_row ) . '"' . $gap_attr . ' role="group" aria-label="' . esc_attr__( 'Categories', 'mlsimport' ) . '">'
		. '<div class="splide__track"><ul class="splide__list">'
		. $slides
		. '</ul></div></div></div>';
}

/**
 * Display Categories — the selected taxonomy terms as a grid of image tiles, in one
 * of three design types. display_grid switches from a fixed column count (per_row)
 * to an auto-fill grid of a minimum unit width. Backs the
 * [mlsimport_category_list] shortcode, the mlsimport/category-list block and the
 * Elementor "Display Categories" widget.
 *
 * @param array $args Block args.
 * @return string
 */
function mlsimport_page_block_category_list( array $args ): string {
	$terms = mlsimport_category_terms( $args );
	if ( empty( $terms ) ) {
		return '';
	}

	$design = in_array( (string) ( $args['design'] ?? '1' ), array( '1', '2', '3' ), true ) ? (string) $args['design'] : '1';
	$grid   = ! in_array( (string) ( $args['display_grid'] ?? '' ), array( '', '0', 'no', 'false' ), true );
	$show   = ! in_array( (string) ( $args['show_count'] ?? '1' ), array( '', '0', 'no', 'false' ), true );

	$tiles = '';
	foreach ( $terms as $vm ) {
		$tiles .= mlsimport_category_tile( $vm, $show );
	}

	$style = mlsimport_category_style_attr(
		$args,
		array( '--mlsimport-cat-height', '--mlsimport-cat-gap', '--mlsimport-cat-radius', '--mlsimport-cat-pad', '--mlsimport-cat-title-size', '--mlsimport-cat-title-color', '--mlsimport-cat-cols', '--mlsimport-cat-min-width', '--mlsimport-cat-border-w', '--mlsimport-cat-border-c' )
	);

	$classes = 'mlsimport-page-block mlsimport-page-block--category-list mlsimport-cat-list mlsimport-cat--design-' . $design . ' mlsimport-cat-list--design-' . $design;
	if ( $grid ) {
		$classes .= ' mlsimport-cat-list--autogrid';
	}

	return '<div class="' . esc_attr( $classes ) . '"' . $style . '>' . $tiles . '</div>';
}
