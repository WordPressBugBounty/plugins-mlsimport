<?php
/**
 * Standalone (theme_id 990) page-block render functions.
 *
 * Each page block is a global function with the fixed signature
 * mlsimport_page_block_<slug>( array $args ): string. It reads only from its args,
 * runs them through the tested selection resolver and the existing render layer,
 * and RETURNS an HTML string. One function backs every builder (Shortcode,
 * Gutenberg, Elementor) — the adapters are thin wrappers, this is the single
 * source of markup. See docs/adr/0007 and CONTEXT.md (Page block).
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mlsimport-standalone-render.php';
require_once __DIR__ . '/class-mlsimport-standalone-shortcodes.php';
require_once __DIR__ . '/class-mlsimport-standalone-ajax.php';
require_once __DIR__ . '/class-mlsimport-standalone-settings.php';
require_once __DIR__ . '/class-mlsimport-page-block-selection.php';
require_once __DIR__ . '/class-mlsimport-page-block-search-fields.php';
require_once __DIR__ . '/class-mlsimport-property-lead.php';
require_once __DIR__ . '/class-mlsimport-property-section-assets.php';
require_once __DIR__ . '/featured-card.php';

/**
 * Resolve a block's selection args into the listing-card HTML (the shared engine
 * behind every "set" block). Honours decision 6: explicit IDs render that exact
 * ordered set; otherwise the taxonomy + sort + count selection runs the query.
 *
 * @param array $args Selection args.
 * @return string Card HTML ('' when nothing matches).
 */
function mlsimport_page_block_cards( array $args ): string {
	/** Short-circuit an explicit-set block's cards (live mode addresses listings by ListingKey). @since 6.6 */
	$pre = apply_filters( 'mlsimport_page_block_ids_cards_pre', null, $args, '' );
	if ( is_string( $pre ) ) {
		return $pre;
	}

	$selection = Mlsimport_Page_Block_Selection::resolve( $args );

	if ( 'ids' === $selection['mode'] ) {
		return Mlsimport_Standalone_Render::cards_for_posts( $selection['ids'] );
	}

	$data = Mlsimport_Standalone_Render::prepare( $selection['params'] );
	return Mlsimport_Standalone_Render::render_cards( $data );
}

/**
 * Wrap card HTML in a page-block grid container (or an empty-results message).
 *
 * @param string $slug  Block slug (for the BEM modifier class).
 * @param string $cards Card HTML.
 * @return string
 */
function mlsimport_page_block_grid( string $slug, string $cards ): string {
	$class = 'mlsimport-page-block mlsimport-page-block--' . sanitize_html_class( str_replace( '_', '-', $slug ) );
	if ( '' === $cards ) {
		$empty = '<p class="mlsimport-results__empty">' . esc_html__( 'No listings found.', 'mlsimport' ) . '</p>';
		return '<div class="' . esc_attr( $class ) . '">' . $empty . '</div>';
	}
	return '<div class="' . esc_attr( $class ) . '"><div class="mlsimport-results__grid">' . $cards . '</div></div>';
}

/**
 * Property List — the full listings surface: the same pre-filled filter bar +
 * AJAX repaint + pager as the Half Map's list pane, seeded on first load from the
 * block's initial-filter presets. Same render_grid path as the Half Map and Search
 * Results, so shortcode / Gutenberg / Elementor emit identical markup and the
 * search bar (above the grid) filters it via AJAX. atts_to_args whitelists the
 * presets to the real filter keys (injection-safe: the query binds every value);
 * search_fields / fields_per_row are display config injected after (not filter
 * keys). show_filter_bar off keeps the form in the DOM as the AJAX state carrier.
 *
 * @param array $args Block args (initial-filter presets, count, show_filter_bar,
 *                    search_fields, fields_per_row).
 * @return string
 */
function mlsimport_page_block_item_list( array $args ): string {
	$filter_args = Mlsimport_Standalone_Shortcodes::atts_to_args( $args );

	// The block's "Per page" is the page size unless a preset already carries a limit.
	if ( ! isset( $filter_args['limit'] ) ) {
		$filter_args['limit'] = isset( $args['count'] ) ? max( 1, (int) $args['count'] ) : 12;
	}

	// Search-bar display config — which fields show and how many per row — injected
	// after atts_to_args strips non-filter keys (identical to the Half Map).
	if ( isset( $args['search_fields'] ) && '' !== (string) $args['search_fields'] ) {
		$filter_args['search_fields'] = (string) $args['search_fields'];
	}
	if ( isset( $args['fields_per_row'] ) && '' !== (string) $args['fields_per_row'] ) {
		$filter_args['fields_per_row'] = (string) $args['fields_per_row'];
	}
	$show = isset( $args['show_filter_bar'] ) ? (string) $args['show_filter_bar'] : '1';
	if ( in_array( $show, array( '', '0', 'no', 'false' ), true ) ) {
		$filter_args['hide_search_form'] = true;
	}

	return Mlsimport_Standalone_Render::render_grid( $filter_args );
}

/**
 * List Items by ID — an explicit, ordered set of properties addressed by their
 * IDs, paginated. The full ID list is the result set; count is the page size and
 * paging is GET-based (?page=N), matching the Search Results block. Only the
 * current page's IDs are rendered, then the shared pager.
 *
 * @param array $args Block args (ids, count).
 * @return string
 */
function mlsimport_page_block_list_by_id( array $args ): string {
	/** Short-circuit the List-by-ID block (live mode addresses listings by ListingKey). @since 6.4 */
	$pre = apply_filters( 'mlsimport_page_block_list_by_id_pre', null, $args );
	if ( is_string( $pre ) ) {
		return $pre;
	}

	$selection = Mlsimport_Page_Block_Selection::resolve( $args );
	$ids       = 'ids' === $selection['mode'] ? $selection['ids'] : array();
	if ( empty( $ids ) ) {
		return mlsimport_page_block_grid( 'list_by_id', '' );
	}

	$per_page = isset( $args['count'] ) ? max( 1, (int) $args['count'] ) : 12;
	$total    = count( $ids );
	// Page on a NON-reserved key. This block is dropped onto a normal Page, which is a
	// SINGULAR post, and WP's redirect_canonical() strips a bare ?page= there (it is the
	// reserved <!--nextpage--> var) — so a ?page=2 link 301s back to page 1 and pagination
	// silently never advances. agent-sections.php hit the same wall and pages on its own
	// key; this surface uses `mlsimport_page` for the identical reason.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET paging of a fixed ID set.
	$current  = isset( $_GET['mlsimport_page'] ) ? max( 1, (int) $_GET['mlsimport_page'] ) : 1;

	$page_ids = array_slice( $ids, ( $current - 1 ) * $per_page, $per_page );
	$cards    = Mlsimport_Standalone_Render::cards_for_posts( $page_ids );

	// Same pager markup as the listings grid (render_grid): the <nav> inside a
	// .mlsimport-results__pager wrapper, so every listing surface paginates identically.
	// The pager links carry the same non-reserved `mlsimport_page` key we read above.
	return '<div class="mlsimport-page-block mlsimport-page-block--list-by-id">'
		. '<div class="mlsimport-results__grid">' . $cards . '</div>'
		. '<div class="mlsimport-results__pager">' . Mlsimport_Pagination::render( $total, $per_page, $current, array( 'param' => 'mlsimport_page' ) ) . '</div>'
		. '</div>';
}

/**
 * Saved Properties ("My saved properties") — the client-hydrated view of the
 * visitor's favorites. The server prints only an empty shell (grid + pager +
 * empty/loading states); favorites.js supplies the authoritative { k, p } list
 * (localStorage for anonymous visitors, the bootstrap blob for logged-in users),
 * POSTs it to the mlsimport_saved_cards resolver, and injects the cards + the
 * shared pager. One code path for both auth states — the resolver is the single
 * place that maps a saved list to current published cards. Off-market entries the
 * resolver reports are pruned from the client store.
 *
 * @param array $args Block args (count = per page).
 * @return string
 */
function mlsimport_page_block_saved( array $args ): string {
	$per_page = isset( $args['count'] ) ? max( 1, (int) $args['count'] ) : 12;

	// In a page-builder preview there is no visitor, and favorites.js never hydrates a
	// widget the builder injects after page load — so the live shell would sit forever on
	// "Loading your saved properties…". Show an author-facing placeholder instead, so the
	// editor makes clear what the block does rather than looking stuck. The real block
	// (below) renders unchanged on the published page.
	if ( mlsimport_is_builder_preview() ) {
		return '<div class="mlsimport-page-block mlsimport-page-block--saved mlsimport-saved mlsimport-saved--preview">'
			. '<p class="mlsimport-results__empty">'
			. esc_html__( 'Saved Properties — on the live page, each visitor sees the listings they have saved here. There is nothing to preview in the editor.', 'mlsimport' )
			. '</p></div>';
	}

	return '<div class="mlsimport-page-block mlsimport-page-block--saved mlsimport-saved" data-mlsimport-saved data-per-page="' . esc_attr( (string) $per_page ) . '">'
		. '<div class="mlsimport-saved__status" data-mlsimport-saved-loading>' . esc_html__( 'Loading your saved properties…', 'mlsimport' ) . '</div>'
		. '<p class="mlsimport-results__empty" data-mlsimport-saved-empty hidden>' . esc_html__( 'You haven\'t saved any properties yet.', 'mlsimport' ) . '</p>'
		. '<div class="mlsimport-results__grid" data-mlsimport-saved-grid></div>'
		. '<div class="mlsimport-results__pager" data-mlsimport-saved-pager></div>'
		. '</div>';
}

/**
 * Search Results — the full listings surface (the same pre-filled filter bar +
 * AJAX repaint + pager as the MLS Listings block), seeded on first load from the
 * GET search the Search Form submitted. atts_to_args whitelists the URL to the
 * real filter keys (injection-safe: the query binds every value), so the refine
 * bar pre-fills with the visitor's search and they can narrow it in place without
 * a reload. The only difference from the MLS Listings block is where the initial
 * args come from — there, saved block attributes; here, the request. Decision 7 / 10.
 *
 * @param array $args Block args (count = per page).
 * @return string
 */
function mlsimport_page_block_results( array $args ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET search; the query binds every value.
	$request = isset( $_GET ) ? wp_unslash( $_GET ) : array();
	$qargs   = Mlsimport_Standalone_Shortcodes::atts_to_args( $request );

	// The block's "Per page" is the page size unless the URL already carries one.
	if ( ! isset( $qargs['limit'] ) ) {
		$qargs['limit'] = isset( $args['count'] ) ? max( 1, (int) $args['count'] ) : 12;
	}

	// Refine-bar display config — the same three controls the MLS Listings block
	// exposes: which fields show, how many per row, and whether the bar shows at all.
	if ( isset( $args['search_fields'] ) && '' !== (string) $args['search_fields'] ) {
		$qargs['search_fields'] = (string) $args['search_fields'];
	}
	if ( isset( $args['fields_per_row'] ) && '' !== (string) $args['fields_per_row'] ) {
		$qargs['fields_per_row'] = (string) $args['fields_per_row'];
	}
	// "Show filter bar" off ('', '0', or Elementor/Gutenberg's empty switcher) hides
	// the bar. render_grid keeps the form in the DOM (CSS hides it) so it stays the
	// state carrier for AJAX paging — the visitor's search survives page changes.
	$show = isset( $args['show_filter_bar'] ) ? (string) $args['show_filter_bar'] : '1';
	if ( in_array( $show, array( '', '0', 'no', 'false' ), true ) ) {
		$qargs['hide_search_form'] = true;
	}

	// This is the one surface that offers "Save this search" (issue #221): the flag
	// tells the mlsimport_results_toolbar listener to print its button here.
	$qargs['saved_search'] = true;

	return Mlsimport_Standalone_Render::render_grid( $qargs );
}

/**
 * Content Slider — the selected listings rendered as a Splide carousel. Reuses the
 * cards and the Splide assets already loaded for property galleries.
 *
 * @param array $args Selection args.
 * @return string
 */
function mlsimport_page_block_slider( array $args ): string {
	/** Short-circuit an explicit-set block's cards (live mode addresses listings by ListingKey). @since 6.6 */
	$pre = apply_filters( 'mlsimport_page_block_ids_cards_pre', null, $args, 'splide__slide' );

	// Each card is wrapped as a Splide slide (<li class="splide__slide">). A
	// dedicated .mlsimport-content-slider class (not the single-property gallery's
	// .mlsimport-property-slider) keeps the gallery's image-cover/fixed-height rules
	// off the property cards. mlsimport-property-slider.js mounts it as a multi-card
	// carousel (arrows, 3/2/1 per view) — the WpResidence content-slider behaviour.
	if ( is_string( $pre ) ) {
		$slides = $pre;
	} else {
		$selection = Mlsimport_Page_Block_Selection::resolve( $args );
		if ( 'ids' === $selection['mode'] ) {
			$slides = Mlsimport_Standalone_Render::cards_for_posts( $selection['ids'], 'splide__slide' );
		} else {
			// Full initial-filter presets (the same set the Half Map / listings block
			// accept): atts_to_args normalizes every listings filter key, and the slider's
			// own friendly How-many + Sort (count/sort, not filter keys) are added from the
			// resolved selection params. atts_to_args wins on overlap (normalized taxonomies).
			$params = Mlsimport_Standalone_Shortcodes::atts_to_args( $args ) + $selection['params'];
			$data   = Mlsimport_Standalone_Render::prepare( $params );
			$slides = Mlsimport_Standalone_Render::render_cards( $data, 'splide__slide' );
		}
	}
	if ( '' === $slides ) {
		return '';
	}

	return '<div class="mlsimport-page-block mlsimport-page-block--slider">'
		. '<div class="mlsimport-content-slider splide" role="group" aria-label="' . esc_attr__( 'Properties', 'mlsimport' ) . '">'
		. '<div class="splide__track"><ul class="splide__list">'
		. $slides
		. '</ul></div></div></div>';
}

/**
 * Whether the current render is a page-builder preview by an editor — the Gutenberg
 * block editor (dynamic blocks render over the REST API) or the Elementor editor (its
 * canvas renders in a front-end preview iframe, and an edited widget re-renders over
 * admin-ajax, which reports edit mode). False for every public front-end request.
 *
 * A block that hydrates client-side (favorites) or stands in a chosen listing uses this
 * to show an author-facing placeholder instead of a live state the editor cannot build.
 *
 * @return bool
 */
function mlsimport_is_builder_preview(): bool {
	// Only ever for someone editing a page — never for a public request.
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->preview ) ) {
		return \Elementor\Plugin::$instance->preview->is_preview_mode()
			|| \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
	return false;
}

/**
 * The listing a page-builder preview stands in with when the author has not picked
 * one yet: the newest published property. Returns 0 anywhere else — a live page
 * must never show a property the author never chose.
 *
 * @return int
 */
function mlsimport_preview_fallback_property_id(): int {
	if ( ! mlsimport_is_builder_preview() ) {
		return 0;
	}

	$ids = get_posts(
		array(
			'post_type'      => 'mlsimport_property',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Options for a post-type-backed multiselect: every published post of $post_type as
 * an ordered { post_id => title } map. The parallel of mlsimport_category_term_options()
 * for posts — it powers the Team Directory agent picker (and any future post picker).
 * Admin-only callers resolve it; the front end reads the saved ids and never needs
 * the list. An empty selection means "all", so an empty list here is a valid state.
 *
 * @param string $post_type Post type to list.
 * @return array<string,string> post_id => title, or empty if the type is unknown.
 */
function mlsimport_post_type_options( string $post_type ): array {
	if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
		return array();
	}
	$posts = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);
	$options = array();
	foreach ( $posts as $post ) {
		$options[ (string) $post->ID ] = (string) get_the_title( $post );
	}
	return $options;
}

/**
 * Featured Property — one property in one of six designs (decision 11). The design
 * only switches a modifier class; all six are styled in our CSS. Reuses the one
 * card template so the markup never forks.
 *
 * @param array $args Block args (id, design 1-6).
 * @return string
 */
function mlsimport_page_block_featured( array $args ): string {
	/** Short-circuit the Featured card (live mode addresses the listing by ListingKey). @since 6.6 */
	$card = apply_filters( 'mlsimport_page_block_featured_card_pre', null, $args );
	if ( ! is_string( $card ) ) {
		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
		// Nothing picked yet: a builder preview borrows the newest listing so the
		// editor shows a real card instead of an empty widget. Front end stays 0.
		if ( $id <= 0 ) {
			$id = mlsimport_preview_fallback_property_id();
		}
		if ( $id <= 0 ) {
			return '';
		}
		$card = mlsimport_featured_card( $id, isset( $args['design'] ) ? (int) $args['design'] : 1 );
	}

	if ( '' === $card ) {
		return '';
	}
	// Every .mlsimport-featured rule lives in the section stylesheet, which only the
	// single-property page enqueues — without this the card renders unstyled.
	Mlsimport_Property_Section_Assets::enqueue();
	return '<div class="mlsimport-page-block mlsimport-page-block--featured">' . $card . '</div>';
}

/**
 * Map with Listings — the selected listings rendered on a map.
 *
 * Two paths, chosen by the selection:
 *  - hand-picked "by IDs": a small, fixed set, so the markers are embedded
 *    directly and rendered client-side (no clustering needed).
 *  - filter "query": potentially thousands of listings, so the container carries
 *    only the filter + the overall bounds, and the map fetches markers/clusters
 *    for the current viewport over AJAX (mlsimport_markers). This is what keeps a
 *    5k-listing map realistic — the browser never loads the whole feed at once.
 *
 * @param array $args Selection args.
 * @return string
 */
function mlsimport_page_block_map( array $args ): string {
	$selection = Mlsimport_Page_Block_Selection::resolve( $args );

	/** Filter the Leaflet/OSM tile URL (shared with the single-property map). @since 6.3 */
	$tile = (string) apply_filters( 'mlsimport_map_tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png' );

	$base = ' data-tile="' . esc_attr( $tile ) . '"';

	/** Short-circuit the hand-picked marker set (live mode addresses listings by ListingKey). @since 6.6 */
	$pre_markers = apply_filters( 'mlsimport_page_block_map_markers_pre', null, $args );

	// Hand-picked set: embed the chosen listings directly (small by definition).
	if ( is_array( $pre_markers ) || 'ids' === $selection['mode'] ) {
		$markers = is_array( $pre_markers ) ? $pre_markers : Mlsimport_Standalone_Render::markers_for_posts( $selection['ids'] );
		if ( empty( $markers ) ) {
			return '';
		}
		mlsimport_page_block_map_enqueue();
		return '<div class="mlsimport-page-block mlsimport-page-block--map">'
			. '<div class="mlsimport-map" data-mode="ids"' . $base
			. ' data-markers="' . esc_attr( (string) wp_json_encode( $markers ) ) . '"></div>'
			. '</div>';
	}

	// Filter set: fit to all matches, then load each viewport over AJAX. Full initial-
	// filter presets (the same set the Half Map / listings block accept): atts_to_args
	// normalizes every listings filter key, and the map's own friendly How-many (count,
	// not a filter key) is added from the resolved selection params. atts_to_args wins on
	// overlap (normalized taxonomies). Mirrors mlsimport_page_block_slider.
	$params = Mlsimport_Standalone_Shortcodes::atts_to_args( $args ) + $selection['params'];
	$bounds = Mlsimport_Standalone_Listings_Query::bounds( $params );
	if ( null === $bounds ) {
		return '';
	}

	return '<div class="mlsimport-page-block mlsimport-page-block--map">'
		. mlsimport_page_block_map_node( $params, $bounds, $tile )
		. '</div>';
}

/**
 * Build the query-mode map element (the .mlsimport-map div the page-block map
 * script mounts) for a filter selection. Embeds the tile URL, the filter as
 * data-filters, and the optional overall bounds; enqueues the map assets.
 * Shared by the Map block and the Half Map block so the marker container never
 * forks. The caller wraps it (Map block hides itself when there are no bounds;
 * Half Map always shows the map alongside the list).
 *
 * @param array      $params Filter query params (the map's data-filters).
 * @param array|null $bounds Overall bounds {lat_min,lat_max,lng_min,lng_max}, or null.
 * @param string     $tile   Leaflet/OSM tile URL.
 * @return string
 */
function mlsimport_page_block_map_node( array $params, ?array $bounds, string $tile ): string {
	mlsimport_page_block_map_enqueue();

	$attrs = ' data-mode="query"'
		. ' data-tile="' . esc_attr( $tile ) . '"'
		. ' data-filters="' . esc_attr( (string) wp_json_encode( $params ) ) . '"';
	if ( null !== $bounds ) {
		$attrs .= ' data-bounds="' . esc_attr( (string) wp_json_encode( $bounds ) ) . '"';
	}

	return '<div class="mlsimport-map"' . $attrs . '></div>';
}

/**
 * Half Map — a full-height split surface: the MLS listings block (filter bar +
 * AJAX results) on one side, a viewport-clustered map on the other. The same
 * search form drives both panes — the list repaints over AJAX (mlsimport-listings.js)
 * and a thin coordinator (mlsimport-half-map.js) pushes the same params to the map
 * — so the two can never disagree. Initial-filter presets (every listings filter
 * key) seed both panes identically, exactly like the standalone listings block.
 *
 * @param array $args Block args: every filter key (initial filter) + search_fields,
 *                    map_side, height.
 * @return string
 */
function mlsimport_page_block_half_map( array $args ): string {
	// Same atts->args path as the listings block, so the initial filter behaves
	// identically. search_fields is display config (which filters show), not a
	// filter key, so it is injected after atts_to_args strips non-filter keys.
	$filter_args = Mlsimport_Standalone_Shortcodes::atts_to_args( $args );
	if ( isset( $args['search_fields'] ) && '' !== $args['search_fields'] ) {
		$filter_args['search_fields'] = (string) $args['search_fields'];
	}
	// fields_per_row is search-form display config (how many fields per row), not a
	// filter key, so it is injected after atts_to_args strips non-filter keys.
	if ( isset( $args['fields_per_row'] ) && '' !== (string) $args['fields_per_row'] ) {
		$filter_args['fields_per_row'] = (string) $args['fields_per_row'];
	}

	// List pane: the exact MLS listings block (filter bar + AJAX results grid).
	$list = Mlsimport_Standalone_Render::render_grid( $filter_args );

	// Map pane: the same filter as a query-mode map. The map ignores paging.
	$params = $filter_args;
	unset( $params['limit'], $params['page'], $params['search_fields'], $params['fields_per_row'] );

	/** Filter the Leaflet/OSM tile URL (shared with the single-property map). @since 6.3 */
	$tile   = (string) apply_filters( 'mlsimport_map_tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png' );
	$bounds = Mlsimport_Standalone_Listings_Query::bounds( $params );

	// Draw-on-map toolbar. The Clear button is hidden until a shape exists (the
	// coordinator toggles it). mlsimport-half-map.js wires both via data-draw.
	$draw_tools = '';
	/** Filter whether the draw-on-map tools render (polygon search needs the local table — live mode hides them). @since 6.6 */
	if ( apply_filters( 'mlsimport_half_map_draw_tools', true ) ) {
		$draw_tools = '<div class="mlsimport-half-map__draw-tools">'
			. '<button type="button" class="mlsimport-half-map__draw-btn" data-draw="start" data-label-drawing="' . esc_attr__( 'Click start point to finish', 'mlsimport' ) . '">' . esc_html__( 'Draw area', 'mlsimport' ) . '</button>'
			. '<button type="button" class="mlsimport-half-map__draw-btn mlsimport-half-map__draw-btn--clear is-hidden" data-draw="clear">' . esc_html__( 'Clear area', 'mlsimport' ) . '</button>'
			. '</div>';
	}
	$map = '<div class="mlsimport-page-block--map">' . $draw_tools . mlsimport_page_block_map_node( $params, $bounds, $tile ) . '</div>';

	$side   = isset( $args['map_side'] ) && 'left' === $args['map_side'] ? 'left' : 'right';
	$height = isset( $args['height'] ) && '' !== (string) $args['height'] ? (string) $args['height'] : '100vh';

	$out  = '<div class="mlsimport-page-block mlsimport-half-map mlsimport-half-map--map-' . esc_attr( $side ) . '"'
		. ' style="--mlsimport-half-map-height:' . esc_attr( $height ) . '">';
	// Mobile-only List/Map switch (hidden on desktop via CSS).
	$out .= '<div class="mlsimport-half-map__switch" role="tablist">'
		. '<button type="button" class="mlsimport-half-map__tab is-active" data-view="list">' . esc_html__( 'List', 'mlsimport' ) . '</button>'
		. '<button type="button" class="mlsimport-half-map__tab" data-view="map">' . esc_html__( 'Map', 'mlsimport' ) . '</button>'
		. '</div>';
	$out .= '<div class="mlsimport-half-map__list">' . $list . '</div>';
	$out .= '<div class="mlsimport-half-map__map">' . $map . '</div>';
	$out .= '</div>';

	return $out;
}

/**
 * Enqueue the map block's Leaflet/OSM assets + the multi-marker init script.
 * Mirrors the single-property map's enqueue but loads the page-block map script.
 *
 * @return void
 */
function mlsimport_page_block_map_enqueue(): void {
	if ( ! function_exists( 'wp_enqueue_script' ) ) {
		return;
	}
	Mlsimport_Property_Section_Assets::ensure_registered();

	wp_enqueue_style( 'mlsimport-leaflet' );
	wp_enqueue_script( 'mlsimport-leaflet' );
	wp_enqueue_script( 'mlsimport-page-block-map' );

	// AJAX config for viewport marker loading (query mode). Nonce pairs with
	// Mlsimport_Standalone_Ajax::MARKERS_ACTION verified in handle_markers(). The
	// map defaults (starting point + zoom) ride along so the script can fall back to
	// the configured view when there are no listings to fit to.
	wp_localize_script(
		'mlsimport-page-block-map',
		'MLSImportMap',
		array(
			'ajaxurl'    => admin_url( 'admin-ajax.php' ),
			'action'     => Mlsimport_Standalone_Ajax::MARKERS_ACTION,
			'nonce'      => wp_create_nonce( Mlsimport_Standalone_Ajax::MARKERS_ACTION ),
			'zoomInText' => __( 'Zoom in to view listings', 'mlsimport' ),
		) + mlsimport_standalone_map_js_config()
	);
}

/**
 * The "search fields per row" choice as a ready-to-print inline style that sets the
 * --mlsimport-search-cols custom property. The search form, the listings grid form
 * and the half-map list form all lay their fields out as a grid of this many
 * columns, so one property drives every search surface. Only the 3–6 options the
 * widgets offer are honoured; anything unset or out of range returns '' so the
 * surface's own CSS fallback (its default column count) applies instead.
 *
 * @param mixed $raw Configured fields-per-row value.
 * @return string e.g. ' style="--mlsimport-search-cols:4"', or '' when unset/invalid.
 */
function mlsimport_search_cols_style( $raw ): string {
	$cols = (int) $raw;
	if ( $cols < 3 || $cols > 6 ) {
		return '';
	}
	return ' style="--mlsimport-search-cols:' . $cols . '"';
}

/**
 * The active filters the search form must carry as HIDDEN inputs: every filter that is
 * set but has NO visible field the visitor could re-submit it with.
 *
 * Why this exists. The search form is the AJAX layer's entire state —
 * mlsimport-listings.js builds each filter/paginate request from the form's FormData and
 * nothing else. A filter that is not in the form is therefore DROPPED the moment the
 * visitor refines or turns a page: the server-rendered first page honours the block's
 * preset and looks perfectly right, and page 2 silently widens to the unfiltered set.
 *
 * A filter loses its field two ways, both of them things a site builder does on purpose:
 * the block sets a preset and switches that field OFF in "Search fields" (a "Miami condos"
 * landing page, where visitors must not change the city), or the filter has no search
 * field at all (the map box). Either way the fix is the same, so there is one rule rather
 * than a growing list of special cases: a filter is either EDITABLE in the bar, or it
 * rides along HIDDEN.
 *
 * @param array      $args    Current filter values (the render args).
 * @param array|null $visible Visible field keys, or null when every field shows.
 * @return array<string,mixed> name => value, ready to print. A multi-value filter uses a
 *                             "key[]" name so collectParams() re-groups it as an array.
 */
function mlsimport_search_form_hidden_args( array $args, ?array $visible ): array {
	$shows = static function ( string $field ) use ( $visible ): bool {
		return null === $visible || in_array( $field, $visible, true );
	};

	// Every param the visitor CAN edit, because a field that submits it is on screen.
	$editable = array();
	if ( $shows( 'keywords' ) ) {
		$editable[] = 'keywords';
	}
	// The Sort control is a single select named "orderby"; it never submits "order",
	// so the direction always rides along hidden.
	if ( $shows( 'sort' ) ) {
		$editable[] = 'orderby';
	}
	foreach ( Mlsimport_Page_Block_Search_Fields::catalog() as $field ) {
		if ( ! $shows( $field ) ) {
			continue;
		}
		$def = Mlsimport_Page_Block_Search_Fields::definition( $field );
		// A taxonomy field submits its own key; a column field submits its param list.
		$editable = array_merge( $editable, isset( $def['params'] ) ? (array) $def['params'] : array( $field ) );
	}

	$hidden = array();
	foreach ( Mlsimport_Standalone_Shortcodes::filter_keys() as $key ) {
		// 'page' is runtime paging — the JS sets it per request, never the form.
		if ( 'page' === $key || in_array( $key, $editable, true ) ) {
			continue;
		}
		if ( ! isset( $args[ $key ] ) ) {
			continue;
		}
		$value = $args[ $key ];
		if ( is_array( $value ) ) {
			$value = array_values( array_filter( array_map( 'strval', $value ), 'strlen' ) );
			if ( ! $value ) {
				continue;
			}
			$hidden[ $key . '[]' ] = $value;
			continue;
		}
		if ( '' === (string) $value || '0' === (string) $value ) {
			continue;
		}
		$hidden[ $key ] = (string) $value;
	}

	/** Filter the hidden filter inputs the search form carries through an AJAX repaint. @since 6.6 */
	return (array) apply_filters( 'mlsimport_search_form_hidden_args', $hidden, $args, $visible );
}

/**
 * Search Form — a GET form that submits the chosen filters to a results page
 * (decision 7). Each field is a repeater row ( field + optional label ); only rows
 * whose field the fast index can filter survive (the classifier), so the form can
 * never introduce a slow meta search. A comma-list also works (shortcode/legacy).
 *
 * The fields lay out on the same 12-column grid the Contact Form block uses, each
 * row spanning the columns its Width says — the submit button included, so it can
 * sit inline as the last cell of a row instead of always claiming one of its own.
 *
 * @param array $args Block args (results_url, fields, hide_labels, button_*).
 * @return string
 */
function mlsimport_page_block_search_form( array $args ): string {
	$action = isset( $args['results_url'] ) ? (string) $args['results_url'] : '';
	$rows   = mlsimport_page_block_normalize_rows( isset( $args['fields'] ) ? $args['fields'] : array(), 'search' );

	$valid = array();
	foreach ( $rows as $row ) {
		if ( '' !== Mlsimport_Page_Block_Search_Fields::classify( $row['field'] ) ) {
			$valid[] = $row;
		}
	}
	/** Filter the search form's field rows. @since 6.4 */
	$valid = (array) apply_filters( 'mlsimport_search_form_fields', $valid, $args );
	if ( empty( $valid ) ) {
		return '';
	}

	$hide_labels = ! empty( $args['hide_labels'] );

	$out = '<form class="mlsimport-page-block mlsimport-search-form" method="get" action="' . esc_url( $action ) . '">';
	// An optional heading, spanning the full grid row above the fields.
	$title = trim( (string) ( $args['title'] ?? '' ) );
	if ( '' !== $title ) {
		$out .= '<h3 class="mlsimport-search-form__title">' . esc_html( $title ) . '</h3>';
	}
	foreach ( $valid as $row ) {
		$out .= mlsimport_page_block_search_input( $row, $hide_labels );
	}
	$out .= mlsimport_search_form_submit( $args );
	$out .= '</form>';
	return $out;
}

/**
 * The search form's submit button: its configured text, size class, width span and
 * optional icon, tinted by the configured colour. Colour is inline because it is a
 * free-form value the stylesheet cannot enumerate; size and width are classes.
 *
 * @param array $args Block args (button_text, button_color, button_size, button_icon, button_width).
 * @return string
 */
function mlsimport_search_form_submit( array $args ): string {
	$text  = isset( $args['button_text'] ) && '' !== trim( (string) $args['button_text'] )
		? (string) $args['button_text']
		: __( 'Search', 'mlsimport' );
	$size  = in_array( (string) ( $args['button_size'] ?? '' ), array( 'small', 'medium', 'large' ), true ) ? (string) $args['button_size'] : 'medium';
	$class = 'mlsimport-search-form__submit mlsimport-search-form__submit--' . $size
		. mlsimport_page_block_width_class( (string) ( $args['button_width'] ?? '' ), 'mlsimport-search-form__submit' );

	// A colour only reaches the page if it is a real CSS colour literal; anything
	// else is dropped rather than printed into the style attribute.
	$color = (string) ( $args['button_color'] ?? '' );
	$style = preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([\d\s.,%]+\)|[a-zA-Z]+)$/', $color )
		? ' style="background-color:' . esc_attr( $color ) . '"'
		: '';

	$icon = (string) ( $args['button_icon'] ?? '' );
	$img  = '' !== $icon ? '<img class="mlsimport-search-form__submit-icon" src="' . esc_url( $icon ) . '" alt="" />' : '';

	return '<button type="submit" class="' . esc_attr( $class ) . '"' . $style . '>' . $img . '<span>' . esc_html( $text ) . '</span></button>';
}

/**
 * Render one search input from a repeater row, resolving the field's definition
 * from the catalog so taxonomies become term dropdowns and columns their proper
 * number/range/date/text inputs. The row may override the field label and supplies
 * its own placeholder and grid width.
 *
 * @param array $row         { field, label, placeholder, width }.
 * @param bool  $hide_labels Drop the visible label and lean on the placeholder.
 * @return string
 */
function mlsimport_page_block_search_input( array $row, bool $hide_labels = false ): string {
	$def = Mlsimport_Page_Block_Search_Fields::definition( (string) $row['field'] );
	if ( null === $def ) {
		return '';
	}
	$class = 'mlsimport-search-form__field' . mlsimport_page_block_width_class( (string) $row['width'], 'mlsimport-search-form__field' );
	return mlsimport_render_search_field( $def, array(), (string) $row['label'], $class, array(
		'placeholder' => (string) $row['placeholder'],
		'hide_label'  => $hide_labels,
		// Slider bound overrides; only the range control reads them.
		'min_value'   => (string) ( $row['min_value'] ?? '' ),
		'max_value'   => (string) ( $row['max_value'] ?? '' ),
	) );
}

/**
 * Render one search field — the shared renderer behind both the Search Form block
 * and the front-end search-form.php template, so the two never drift. Taxonomies
 * render as a term <select> (multi for column-IN / features, single otherwise);
 * columns render as a range pair, a number, a date, or a text input.
 *
 * @param array  $def            Field definition (Mlsimport_Page_Block_Search_Fields::definition).
 * @param array  $values         Current values keyed by query param (for pre-fill); empty = none.
 * @param string $label_override Optional label replacing the catalog label.
 * @param string $field_class    Wrapper class (the form's own field class namespace).
 * @param array  $opts           { placeholder: string, hide_label: bool }. Hiding the
 *                               label moves it into the placeholder when the row set
 *                               none, so a control is never left unnamed.
 * @return string Markup, or '' when a taxonomy field has no terms.
 */
function mlsimport_render_search_field( array $def, array $values = array(), string $label_override = '', string $field_class = 'mlsimport-search-form__field', array $opts = array() ): string {
	$label = '' !== $label_override ? $label_override : (string) $def['label'];

	$hide_label  = ! empty( $opts['hide_label'] );
	$placeholder = isset( $opts['placeholder'] ) ? (string) $opts['placeholder'] : '';
	// A hidden label has to survive somewhere: it becomes the placeholder unless the
	// row wrote one. With the label visible, an unset placeholder stays unset.
	if ( $hide_label && '' === $placeholder ) {
		$placeholder = $label;
	}
	// aria-label keeps the control named for assistive tech once the <span> is gone.
	$open = '<label class="' . esc_attr( $field_class ) . '"' . ( $hide_label ? ' aria-label="' . esc_attr( $label ) . '"' : '' ) . '>'
		. ( $hide_label ? '' : '<span>' . esc_html( $label ) . '</span>' );
	$ph   = '' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '';

	if ( 'taxonomy' === $def['group'] ) {
		/** Short-circuit a search field's options before terms are queried (live mode answers from the MLS enums). @since 6.6 */
		$pairs = apply_filters( 'mlsimport_search_field_options_pre', null, $def );

		if ( ! is_array( $pairs ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $def['tax'],
					'hide_empty' => false,
				)
			);

			$is_slug = 'slug' === $def['value'];
			$pairs   = array();
			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$pairs[ (string) ( $is_slug ? $term->slug : $term->name ) ] = (string) $term->name;
				}
			}
		}

		/** Filter a search field's option list, value => label. @since 6.4 */
		$pairs = (array) apply_filters( 'mlsimport_search_field_options', $pairs, $def );
		if ( array() === $pairs ) {
			return '';
		}

		$key     = (string) $def['key'];
		$multi   = ! empty( $def['multi'] );
		$current = isset( $values[ $key ] ) ? array_map( 'strval', (array) $values[ $key ] ) : array();
		$name    = $multi ? $key . '[]' : $key;

		// A select has no placeholder attribute, so its empty first option carries the
		// text instead: the row's placeholder when set, else the usual "Any".
		$empty_text = '' !== $placeholder ? $placeholder : __( 'Any', 'mlsimport' );

		$options = $multi ? '' : '<option value="">' . esc_html( $empty_text ) . '</option>';
		foreach ( $pairs as $value => $text ) {
			$value    = (string) $value;
			$options .= '<option value="' . esc_attr( $value ) . '"' . ( in_array( $value, $current, true ) ? ' selected' : '' ) . '>' . esc_html( (string) $text ) . '</option>';
		}

		if ( $multi ) {
			return $open
				. '<select name="' . esc_attr( $name ) . '" multiple class="mlsimport-multiselect" data-placeholder="' . esc_attr( $empty_text ) . '">' . $options . '</select>'
				. '</label>';
		}

		return $open
			. '<select name="' . esc_attr( $name ) . '">' . $options . '</select>'
			. '</label>';
	}

	// Rich column controls (WPResidence-style popups): every range column (price,
	// living area, lot size, year built) → the same dual-handle slider popup, beds_baths
	// → one popup with Beds + Baths min-tile rows. Each still submits the same plain
	// query params via hidden inputs, so the WHERE-builder is untouched and JS-off forms
	// degrade to the hidden values.
	$key = (string) $def['key'];
	if ( 'beds_baths' === $key ) {
		return mlsimport_render_beds_baths_field( $def, $values, $label, $field_class, $opts );
	}
	if ( 'range' === $def['control'] ) {
		return mlsimport_render_range_slider_field( $def, $values, $label, $field_class, $opts );
	}

	$params = (array) $def['params'];
	$val    = static function ( $param ) use ( $values ) {
		return isset( $values[ $param ] ) && ! is_array( $values[ $param ] ) ? (string) $values[ $param ] : '';
	};

	$param = (string) $params[0];

	// The combined Location box: a plain text input the autocomplete script attaches
	// to by its data attribute. It degrades to a free-text search with JS off — the
	// WHERE-builder matches a typed city/ZIP/area/county/address either way.
	if ( 'location' === $def['control'] ) {
		// A <div>, not a <span>: a field's only direct <span> child is its label, so
		// "has this field a visible label?" stays a single unambiguous check.
		return $open
			. '<div class="mlsimport-location">'
			. '<input type="text" name="' . esc_attr( $param ) . '" value="' . esc_attr( $val( $param ) ) . '"' . $ph
			. ' autocomplete="off" data-mlsimport-location="1" />'
			. '<ul class="mlsimport-location__list" role="listbox" hidden></ul>'
			. '</div>'
			. '</label>';
	}

	$type = 'number' === $def['control'] ? 'number' : ( 'date' === $def['control'] ? 'date' : 'text' );
	return $open
		. '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $param ) . '" value="' . esc_attr( $val( $param ) ) . '"' . $ph . ' />'
		. '</label>';
}

/**
 * The price slider's upper bound: the highest listed price (cached a day), or a
 * 1,000,000 fallback on an empty index. Filterable so a site can pin its own
 * ceiling. The min is always 0.
 *
 * @return int
 */
function mlsimport_search_price_ceiling(): int {
	$cached = get_transient( 'mlsimport_search_price_ceiling' );
	if ( false === $cached ) {
		global $wpdb;
		$table = Mlsimport_Standalone_Table::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max    = (int) $wpdb->get_var( "SELECT MAX(price) FROM {$table}" );
		$cached = $max > 0 ? $max : 1000000;
		set_transient( 'mlsimport_search_price_ceiling', $cached, DAY_IN_SECONDS );
	}
	/** Filter the price slider's upper bound. @since 6.4 */
	return max( 1, (int) apply_filters( 'mlsimport_search_price_ceiling', (int) $cached ) );
}

/**
 * Resolve a range column's slider metadata — its source column, number format and
 * unit suffix. Year built reads its lower bound from the data (floored) so the
 * slider spans real years, not 0..now. Kept beside the bounds query so the field
 * catalog stays pure data.
 *
 * @param string $key Field key ('price', 'sqft', 'lot', 'year').
 * @return array { column: string, format: string, unit: string, floored: bool }
 */
function mlsimport_search_range_meta( string $key ): array {
	$map = array(
		'price' => array( 'column' => 'price',       'format' => 'money',  'unit' => '',    'floored' => false ),
		'sqft'  => array( 'column' => 'living_area', 'format' => 'number', 'unit' => 'ft²', 'floored' => false ),
		'lot'   => array( 'column' => 'lot_size',    'format' => 'number', 'unit' => 'ft²', 'floored' => false ),
		'year'  => array( 'column' => 'year_built',  'format' => 'year',   'unit' => '',    'floored' => true ),
	);
	return isset( $map[ $key ] ) ? $map[ $key ] : array( 'column' => '', 'format' => 'number', 'unit' => '', 'floored' => false );
}

/**
 * The min/max bounds for a range column's slider, cached a day. The max is the
 * column's highest value; the min is 0 unless $floored (year built), where it is
 * the lowest non-zero value so the slider spans the real years. Price keeps its
 * own filterable ceiling helper and never comes through here.
 *
 * @param string $column  Fast-table column (living_area, lot_size, year_built).
 * @param bool   $floored Compute a real lower bound instead of 0.
 * @return array { min: int, max: int }
 */
function mlsimport_search_range_bounds( string $column, bool $floored ): array {
	$cache_key = 'mlsimport_search_bounds_' . $column;
	$cached    = get_transient( $cache_key );
	if ( false === $cached || ! is_array( $cached ) ) {
		global $wpdb;
		$table = Mlsimport_Standalone_Table::table_name();
		// $column is one of a fixed internal set (never user input).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max = (int) $wpdb->get_var( "SELECT MAX({$column}) FROM {$table}" );
		$min = 0;
		if ( $floored ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$min = (int) $wpdb->get_var( "SELECT MIN(NULLIF({$column}, 0)) FROM {$table}" );
		}
		$cached = array( 'min' => $min, 'max' => $max );
		set_transient( $cache_key, $cached, DAY_IN_SECONDS );
	}
	return $cached;
}

/**
 * Format a range value for display. 'money' → "$1,234"; 'year' → "1985" (no
 * grouping); 'number' → "1,234 ft²". $compact gives the short toggle form
 * ($900K, 1M ft²); years are never compacted. Mirrors formatRange() in the JS.
 *
 * @param float  $n       The value.
 * @param string $format  'money' | 'number' | 'year'.
 * @param string $unit    Unit suffix for 'number' (e.g. ft²).
 * @param bool   $compact Short form for the narrow toggle button.
 * @return string
 */
function mlsimport_format_range_value( float $n, string $format, string $unit, bool $compact ): string {
	if ( 'year' === $format ) {
		return (string) (int) $n;
	}
	$prefix = 'money' === $format ? '$ ' : '';
	$suffix = ( 'money' !== $format && '' !== $unit ) ? ' ' . $unit : '';
	if ( $compact ) {
		if ( $n >= 1000000 ) {
			return $prefix . rtrim( rtrim( number_format( $n / 1000000, 1 ), '0' ), '.' ) . 'M' . $suffix;
		}
		if ( $n >= 1000 ) {
			return $prefix . number_format( $n / 1000, 0 ) . 'K' . $suffix;
		}
	}
	return $prefix . number_format( $n ) . $suffix;
}

/**
 * Render a range column (price, living area, lot size, year built) as a dropdown
 * popup with a dual-handle slider plus min/max inputs (WPResidence feel, vanilla).
 * The visible inputs and slider are presentation only; two hidden inputs named for
 * the field's query params carry the actual values. An untouched min (floor) or max
 * (ceiling) submits empty, so the filter only applies once the user narrows it. JS
 * enhances; without JS the hidden values still submit.
 *
 * @param array  $def         Field definition (a 'range' control).
 * @param array  $values      Current values keyed by query param.
 * @param string $label       Resolved field label.
 * @param string $field_class Wrapper class (the form's field namespace).
 * @return string
 */
function mlsimport_render_range_slider_field( array $def, array $values, string $label, string $field_class, array $opts = array() ): string {
	$key  = (string) $def['key'];
	$meta = mlsimport_search_range_meta( $key );

	// This control has no input to hang a placeholder on: its closed state is the
	// toggle button, so the placeholder becomes the toggle's "nothing chosen" text.
	$hide_label  = ! empty( $opts['hide_label'] );
	$placeholder = isset( $opts['placeholder'] ) ? (string) $opts['placeholder'] : '';

	if ( 'price' === $key ) {
		$floor   = 0;
		$ceiling = mlsimport_search_price_ceiling();
	} else {
		$bounds  = mlsimport_search_range_bounds( $meta['column'], (bool) $meta['floored'] );
		$floor   = (int) $bounds['min'];
		$ceiling = (int) $bounds['max'];
	}
	// A row may pin either end of the slider. Empty means "keep the computed bound",
	// which is why these are checked as strings — a configured floor of 0 is real.
	if ( isset( $opts['min_value'] ) && '' !== (string) $opts['min_value'] ) {
		$floor = (int) $opts['min_value'];
	}
	if ( isset( $opts['max_value'] ) && '' !== (string) $opts['max_value'] ) {
		$ceiling = (int) $opts['max_value'];
	}
	$ceiling = max( $floor + 1, $ceiling );
	$step    = 'year' === $meta['format'] ? 1 : 0; // 0 → the JS picks a nice step.

	$param_min = (string) $def['params'][0];
	$param_max = (string) $def['params'][1];
	$cur_min   = isset( $values[ $param_min ] ) && '' !== (string) $values[ $param_min ] ? (int) $values[ $param_min ] : null;
	$cur_max   = isset( $values[ $param_max ] ) && '' !== (string) $values[ $param_max ] ? (int) $values[ $param_max ] : null;

	$lo  = null !== $cur_min ? $cur_min : $floor;
	$hi  = null !== $cur_max ? $cur_max : $ceiling;
	$has = ( null !== $cur_min || null !== $cur_max );

	$fmt     = static function ( $n ) use ( $meta ) {
		return mlsimport_format_range_value( (float) $n, $meta['format'], $meta['unit'], false );
	};
	$compact = static function ( $n ) use ( $meta ) {
		return mlsimport_format_range_value( (float) $n, $meta['format'], $meta['unit'], true );
	};

	$default = '' !== $placeholder ? $placeholder : ( $hide_label ? $label : __( 'Any', 'mlsimport' ) );
	$toggle  = $has ? ( $compact( $lo ) . ' – ' . $compact( $hi ) ) : $default;

	/* translators: %s: field label, e.g. "Living Area". */
	$min_aria = sprintf( __( 'Minimum %s', 'mlsimport' ), $label );
	/* translators: %s: field label, e.g. "Living Area". */
	$max_aria = sprintf( __( 'Maximum %s', 'mlsimport' ), $label );

	$out  = '<div class="' . esc_attr( $field_class ) . ' mlsimport-range"' . ( $hide_label ? ' aria-label="' . esc_attr( $label ) . '"' : '' ) . ' data-mlsimport-range data-format="' . esc_attr( $meta['format'] ) . '" data-unit="' . esc_attr( $meta['unit'] ) . '">';
	$out .= $hide_label ? '' : '<span>' . esc_html( $label ) . '</span>';
	$out .= '<button type="button" class="mlsimport-range__toggle" data-default="' . esc_attr( $default ) . '">' . esc_html( $toggle ) . '</button>';
	$out .= '<div class="mlsimport-range__popup">';
	$out .= '<div class="mlsimport-range__fields">';
	$out .= '<input type="text" class="mlsimport-range__display mlsimport-range__display--min" inputmode="numeric" value="' . esc_attr( $fmt( $lo ) ) . '" aria-label="' . esc_attr( $min_aria ) . '" />';
	$out .= '<span class="mlsimport-range__sep">–</span>';
	$out .= '<input type="text" class="mlsimport-range__display mlsimport-range__display--max" inputmode="numeric" value="' . esc_attr( $fmt( $hi ) ) . '" aria-label="' . esc_attr( $max_aria ) . '" />';
	$out .= '</div>';
	$out .= '<div class="mlsimport-range__slider" data-min="' . esc_attr( (string) $floor ) . '" data-max="' . esc_attr( (string) $ceiling ) . '"' . ( $step > 0 ? ' data-step="' . esc_attr( (string) $step ) . '"' : '' ) . '>';
	$out .= '<div class="mlsimport-range__rail"></div>';
	$out .= '<div class="mlsimport-range__range"></div>';
	$out .= '<span class="mlsimport-range__handle mlsimport-range__handle--min" tabindex="0" role="slider" aria-label="' . esc_attr( $min_aria ) . '"></span>';
	$out .= '<span class="mlsimport-range__handle mlsimport-range__handle--max" tabindex="0" role="slider" aria-label="' . esc_attr( $max_aria ) . '"></span>';
	$out .= '</div>';
	$out .= '<div class="mlsimport-range__actions">';
	$out .= '<button type="button" class="mlsimport-range__reset">' . esc_html__( 'Reset', 'mlsimport' ) . '</button>';
	$out .= '<button type="button" class="mlsimport-range__done">' . esc_html__( 'Done', 'mlsimport' ) . '</button>';
	$out .= '</div>';
	$out .= '<input type="hidden" name="' . esc_attr( $param_min ) . '" class="mlsimport-range__value-min" value="' . esc_attr( null !== $cur_min ? (string) $cur_min : '' ) . '" />';
	$out .= '<input type="hidden" name="' . esc_attr( $param_max ) . '" class="mlsimport-range__value-max" value="' . esc_attr( null !== $cur_max ? (string) $cur_max : '' ) . '" />';
	$out .= '</div>'; // .mlsimport-range__popup
	$out .= '</div>'; // .mlsimport-range
	return $out;
}

/**
 * Render the combined Beds & Baths field as one dropdown popup with two rows of
 * "N+" minimum tiles (WPResidence feel, vanilla). Two hidden inputs named beds and
 * baths carry the chosen minimums; an unselected row submits empty. JS enhances;
 * without JS the hidden values still submit.
 *
 * @param array  $def         Field definition (key is 'beds_baths').
 * @param array  $values      Current values keyed by query param.
 * @param string $label       Resolved field label.
 * @param string $field_class Wrapper class (the form's field namespace).
 * @return string
 */
function mlsimport_render_beds_baths_field( array $def, array $values, string $label, string $field_class, array $opts = array() ): string {
	// Like the range control, this one closes to a toggle button rather than an
	// input, so the placeholder becomes the toggle's "nothing chosen" text.
	$hide_label  = ! empty( $opts['hide_label'] );
	$placeholder = isset( $opts['placeholder'] ) ? (string) $opts['placeholder'] : '';

	$beds  = isset( $values['beds'] ) && '' !== (string) $values['beds'] ? (string) (int) $values['beds'] : '';
	$baths = isset( $values['baths'] ) && '' !== (string) $values['baths'] ? (string) (int) $values['baths'] : '';

	$parts = array();
	if ( '' !== $beds ) {
		/* translators: %s: minimum bedrooms, e.g. "2+". */
		$parts[] = sprintf( __( '%s bd', 'mlsimport' ), $beds . '+' );
	}
	if ( '' !== $baths ) {
		/* translators: %s: minimum bathrooms, e.g. "2+". */
		$parts[] = sprintf( __( '%s ba', 'mlsimport' ), $baths . '+' );
	}
	$empty   = '' !== $placeholder ? $placeholder : ( $hide_label ? $label : __( 'Beds | Baths', 'mlsimport' ) );
	$default = esc_attr( $empty );
	$toggle  = empty( $parts ) ? $empty : implode( ' · ', $parts );

	// One row of 1+..6+ tiles for a group ('beds' or 'baths'), the matching one marked.
	$grid = static function ( string $group, string $cur ) {
		$out = '<div class="mlsimport-bedsbaths__grid" data-group="' . esc_attr( $group ) . '">';
		for ( $i = 1; $i <= 6; $i++ ) {
			$val      = (string) $i;
			$selected = $val === $cur ? ' is-selected' : '';
			$out     .= '<button type="button" class="mlsimport-bedsbaths__item' . $selected . '" data-value="' . esc_attr( $val ) . '">' . esc_html( $val . '+' ) . '</button>';
		}
		return $out . '</div>';
	};

	$out  = '<div class="' . esc_attr( $field_class ) . ' mlsimport-bedsbaths"' . ( $hide_label ? ' aria-label="' . esc_attr( $label ) . '"' : '' ) . ' data-mlsimport-bedsbaths>';
	$out .= $hide_label ? '' : '<span>' . esc_html( $label ) . '</span>';
	$out .= '<button type="button" class="mlsimport-bedsbaths__toggle" data-default="' . $default . '">' . esc_html( $toggle ) . '</button>';
	$out .= '<div class="mlsimport-bedsbaths__popup">';
	$out .= '<h4 class="mlsimport-bedsbaths__heading">' . esc_html__( 'Beds', 'mlsimport' ) . '</h4>';
	$out .= $grid( 'beds', $beds );
	$out .= '<h4 class="mlsimport-bedsbaths__heading">' . esc_html__( 'Baths', 'mlsimport' ) . '</h4>';
	$out .= $grid( 'baths', $baths );
	$out .= '<div class="mlsimport-bedsbaths__actions">';
	$out .= '<button type="button" class="mlsimport-bedsbaths__reset">' . esc_html__( 'Reset', 'mlsimport' ) . '</button>';
	$out .= '<button type="button" class="mlsimport-bedsbaths__done">' . esc_html__( 'Done', 'mlsimport' ) . '</button>';
	$out .= '</div>';
	$out .= '<input type="hidden" name="beds" class="mlsimport-bedsbaths__value" data-group="beds" value="' . esc_attr( $beds ) . '" />';
	$out .= '<input type="hidden" name="baths" class="mlsimport-bedsbaths__value" data-group="baths" value="' . esc_attr( $baths ) . '" />';
	$out .= '</div>'; // .mlsimport-bedsbaths__popup
	$out .= '</div>'; // .mlsimport-bedsbaths
	return $out;
}

/**
 * Contact Form — a configurable field set (repeater rows) that submits to the
 * shared lead endpoint. A contact lead carries no property/agent, so the recipient
 * resolves to the "Contact form recipients" setting (wired via the lead recipient
 * filter). A comma-list also works (shortcode/legacy).
 *
 * @param array $args Block args (title, fields, hide_labels, input_size, show_consent, button_*).
 * @return string
 */
function mlsimport_page_block_contact_form( array $args ): string {
	$rows = mlsimport_page_block_normalize_rows( isset( $args['fields'] ) ? $args['fields'] : 'name,email,message', 'contact' );
	/** Filter the contact form's field rows. @since 6.4 */
	$rows = (array) apply_filters( 'mlsimport_contact_form_fields', $rows, $args );
	if ( empty( $rows ) ) {
		return '';
	}

	$hide_labels = ! empty( $args['hide_labels'] );
	// Field size and button alignment are presets the stylesheet enumerates, so they
	// ride on the form as modifier classes rather than inline styles.
	$size  = mlsimport_page_block_size( $args['input_size'] ?? '' );
	$align = in_array( (string) ( $args['button_align'] ?? '' ), array( 'start', 'center', 'end', 'stretch' ), true )
		? (string) $args['button_align']
		: 'start';
	$class = 'mlsimport-page-block mlsimport-contact-form'
		. ' mlsimport-contact-form--' . $size
		. ' mlsimport-contact-form--btn-' . $align;

	$out  = '<form class="' . esc_attr( $class ) . '" method="post" data-mlsimport-lead="contact">';
	$out .= wp_nonce_field( Mlsimport_Property_Lead::NONCE, 'nonce', true, false );
	// Honeypot — a bot that fills this is dropped server-side.
	$out .= '<input type="text" name="mlsimport_hp" value="" class="mlsimport-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />';
	$out .= '<input type="hidden" name="action" value="' . esc_attr( Mlsimport_Property_Lead::ACTION ) . '" />';
	$out .= '<input type="hidden" name="mlsimport_context" value="contact" />';

	// An optional heading, spanning the full grid row above the fields.
	$title = trim( (string) ( $args['title'] ?? '' ) );
	if ( '' !== $title ) {
		$out .= '<h3 class="mlsimport-contact-form__title">' . esc_html( $title ) . '</h3>';
	}

	foreach ( $rows as $row ) {
		$out .= mlsimport_page_block_contact_input( $row, $hide_labels );
	}

	// The consent checkbox, worded by the site-wide consent settings — the same
	// field the property lead forms render, so consent reads identically everywhere.
	if ( ! empty( $args['show_consent'] ) && function_exists( 'mlsimport_property_lead_consent_field' ) ) {
		$out .= '<div class="mlsimport-contact-form__consent">' . mlsimport_property_lead_consent_field() . '</div>';
	}

	$out .= mlsimport_contact_form_submit( $args );
	$out .= '<div class="mlsimport-property-lead-form__status mlsimport-contact-form__message" role="status"></div>';
	$out .= '</form>';
	return $out;
}

/**
 * The contact form's submit button: its configured text, size class, width span and
 * colour. Mirrors mlsimport_search_form_submit() — the two forms share a grid and a
 * control vocabulary, so their buttons are built the same way.
 *
 * @param array $args Block args (button_text, button_color, button_size, button_width).
 * @return string
 */
function mlsimport_contact_form_submit( array $args ): string {
	$text  = isset( $args['button_text'] ) && '' !== trim( (string) $args['button_text'] )
		? (string) $args['button_text']
		: __( 'Send', 'mlsimport' );
	$class = 'mlsimport-contact-form__submit mlsimport-contact-form__submit--' . mlsimport_page_block_size( $args['button_size'] ?? '' )
		. mlsimport_page_block_width_class( (string) ( $args['button_width'] ?? '' ), 'mlsimport-contact-form__submit' );

	// A colour only reaches the page if it is a real CSS colour literal; anything
	// else is dropped rather than printed into the style attribute.
	$color = (string) ( $args['button_color'] ?? '' );
	$style = preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([\d\s.,%]+\)|[a-zA-Z]+)$/', $color )
		? ' style="background-color:' . esc_attr( $color ) . '"'
		: '';

	return '<button type="submit" class="' . esc_attr( $class ) . '"' . $style . '><span>' . esc_html( $text ) . '</span></button>';
}

/**
 * Normalize a size arg to one of the three steps, defaulting to medium.
 *
 * @param mixed $raw Configured size.
 * @return string small | medium | large
 */
function mlsimport_page_block_size( $raw ): string {
	return in_array( (string) $raw, array( 'small', 'medium', 'large' ), true ) ? (string) $raw : 'medium';
}

/**
 * Render one contact input from a row, named with the mlsimport_ prefix the lead
 * processor reads. Honours the row's type, choices, placeholder, required flag
 * and width. A dropdown or radio row with no choices renders nothing — there is
 * no field to answer.
 *
 * @param array $row         { name, type, label, placeholder, options, required, width }.
 * @param bool  $hide_labels Drop the visible label and lean on the placeholder.
 * @return string
 */
function mlsimport_page_block_contact_input( array $row, bool $hide_labels = false ): string {
	$key = (string) $row['name'];
	if ( '' === $key ) {
		return '';
	}
	$label    = '' !== (string) $row['label'] ? (string) $row['label'] : ucwords( str_replace( '_', ' ', $key ) );
	$name     = 'mlsimport_' . $key;
	$required = ( 'yes' === $row['required'] || true === $row['required'] || '1' === (string) $row['required'] ) ? ' required' : '';
	$class    = 'mlsimport-contact-form__field' . mlsimport_page_block_width_class( (string) $row['width'] );
	$choices  = mlsimport_page_block_choices( (string) $row['options'] );

	// A hidden label has to survive somewhere: it becomes the placeholder unless the
	// row wrote one, and aria-label keeps the control named once the <span> is gone.
	$placeholder = (string) $row['placeholder'];
	if ( $hide_labels && '' === $placeholder ) {
		$placeholder = $label;
	}
	$ph   = '' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '';
	$aria = $hide_labels ? ' aria-label="' . esc_attr( $label ) . '"' : '';

	if ( 'radio' === $row['type'] ) {
		if ( empty( $choices ) ) {
			return '';
		}
		$out = '<fieldset class="' . esc_attr( $class ) . ' mlsimport-contact-form__field--radio"><legend>' . esc_html( $label ) . '</legend>';
		foreach ( $choices as $choice ) {
			$out .= '<label><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $choice ) . '"' . $required . ' /><span>' . esc_html( $choice ) . '</span></label>';
		}
		return $out . '</fieldset>';
	}

	if ( 'checkbox' === $row['type'] ) {
		return '<label class="' . esc_attr( $class ) . ' mlsimport-contact-form__field--checkbox">'
			. '<input type="checkbox" name="' . esc_attr( $name ) . '" value="yes"' . $required . ' />'
			. '<span>' . esc_html( $label ) . '</span></label>';
	}

	if ( 'select' === $row['type'] ) {
		if ( empty( $choices ) ) {
			return '';
		}
		$empty = '' !== $placeholder ? $placeholder : __( 'Select…', 'mlsimport' );
		$input = '<select name="' . esc_attr( $name ) . '"' . $required . $aria . '><option value="">' . esc_html( $empty ) . '</option>';
		foreach ( $choices as $choice ) {
			$input .= '<option value="' . esc_attr( $choice ) . '">' . esc_html( $choice ) . '</option>';
		}
		$input .= '</select>';
	} elseif ( 'textarea' === $row['type'] ) {
		$input = '<textarea name="' . esc_attr( $name ) . '" rows="4"' . $ph . $required . $aria . '></textarea>';
	} else {
		$type  = in_array( $row['type'], array( 'email', 'tel' ), true ) ? $row['type'] : 'text';
		$input = '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '"' . $ph . $required . $aria . ' />';
	}

	$caption = $hide_labels ? '' : '<span>' . esc_html( $label ) . '</span>';
	return '<label class="' . esc_attr( $class ) . '">' . $caption . $input . '</label>';
}

/**
 * Split a row's comma-separated choice list into trimmed, non-empty choices. The
 * choice text is both the submitted value and the visible option label.
 *
 * @param string $raw Comma list.
 * @return array<int,string>
 */
function mlsimport_page_block_choices( string $raw ): array {
	if ( '' === trim( $raw ) ) {
		return array();
	}
	return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static function ( $v ) {
		return '' !== $v;
	} ) );
}

/**
 * The modifier class for a field's width. Unknown or empty widths get no class —
 * a field spans the full row by default.
 *
 * @param string $width One of two_thirds | half | third | quarter.
 * @return string Leading-space class, or ''.
 */
function mlsimport_page_block_width_class( string $width, string $base = 'mlsimport-contact-form__field' ): string {
	if ( ! in_array( $width, array( 'two_thirds', 'half', 'third', 'quarter' ), true ) ) {
		return '';
	}
	return ' ' . $base . '--' . str_replace( '_', '-', $width );
}

/**
 * Normalize a form's fields arg into a list of complete rows. Accepts a repeater
 * array (rows from Gutenberg/Elementor), or a comma-list string / array of field
 * keys (shortcode/legacy) which becomes default rows.
 *
 * A comma-list item may carry a width after a colon — "key:width", e.g.
 * fields="location:half,price:third,mls_number:third" — so a shortcode can lay
 * its fields out on a grid the way the block/Elementor repeater can. A bare key
 * (no colon) keeps the default full width; an unknown width is simply ignored by
 * mlsimport_page_block_width_class(), so the field still renders.
 *
 * @param mixed  $raw  Repeater rows, comma string, or array of keys.
 * @param string $kind 'search' | 'contact'.
 * @return array<int,array<string,mixed>>
 */
function mlsimport_page_block_normalize_rows( $raw, string $kind ): array {
	if ( is_string( $raw ) ) {
		$raw = array_filter( array_map( 'trim', explode( ',', $raw ) ), static function ( $v ) {
			return '' !== $v;
		} );
	}
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$rows = array();
	foreach ( $raw as $item ) {
		if ( is_array( $item ) ) {
			$rows[] = mlsimport_page_block_normalize_row( $item, $kind );
		} elseif ( is_string( $item ) && '' !== trim( $item ) ) {
			// Split "key:width" BEFORE sanitizing: sanitize_key() strips the colon, so
			// sanitizing the whole item would glue the two halves into one bogus key.
			$parts        = explode( ':', trim( $item ), 2 );
			$row          = mlsimport_page_block_row_from_key( sanitize_key( $parts[0] ), $kind );
			// The width is sanitized on its own; a bare key leaves the default ''.
			$row['width'] = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
			$rows[]       = $row;
		}
	}
	return $rows;
}

/**
 * Fill a repeater row's missing keys with defaults for its kind.
 *
 * @param array  $row  Partial row.
 * @param string $kind 'search' | 'contact'.
 * @return array
 */
function mlsimport_page_block_normalize_row( array $row, string $kind ): array {
	if ( 'search' === $kind ) {
		return array(
			'field'       => isset( $row['field'] ) ? sanitize_key( (string) $row['field'] ) : '',
			'label'       => isset( $row['label'] ) ? (string) $row['label'] : '',
			'placeholder' => isset( $row['placeholder'] ) ? (string) $row['placeholder'] : '',
			// Slider bound overrides. Only a numeric value is a bound; anything else
			// (empty, stray text) normalizes to '' and the computed bound stands.
			'min_value'   => isset( $row['min_value'] ) && is_numeric( $row['min_value'] ) ? (string) (int) $row['min_value'] : '',
			'max_value'   => isset( $row['max_value'] ) && is_numeric( $row['max_value'] ) ? (string) (int) $row['max_value'] : '',
			'width'       => isset( $row['width'] ) ? (string) $row['width'] : '',
		);
	}

	$label = isset( $row['label'] ) ? (string) $row['label'] : '';
	$name  = isset( $row['name'] ) && '' !== (string) $row['name'] ? sanitize_key( (string) $row['name'] ) : sanitize_key( $label );
	return array(
		'name'        => $name,
		'type'        => isset( $row['type'] ) ? (string) $row['type'] : 'text',
		'label'       => $label,
		'placeholder' => isset( $row['placeholder'] ) ? (string) $row['placeholder'] : '',
		'options'     => isset( $row['options'] ) ? (string) $row['options'] : '',
		'required'    => isset( $row['required'] ) ? $row['required'] : '',
		'width'       => isset( $row['width'] ) ? (string) $row['width'] : '',
	);
}

/**
 * Build a default row from a bare field key (the comma-list path).
 *
 * @param string $key  Field key.
 * @param string $kind 'search' | 'contact'.
 * @return array
 */
function mlsimport_page_block_row_from_key( string $key, string $kind ): array {
	if ( 'search' === $kind ) {
		return array( 'field' => $key, 'label' => '', 'placeholder' => '', 'min_value' => '', 'max_value' => '', 'width' => '' );
	}

	$type = 'email' === $key ? 'email' : ( 'phone' === $key ? 'tel' : ( 'message' === $key ? 'textarea' : 'text' ) );
	return array(
		'name'        => $key,
		'type'        => $type,
		'label'       => '',
		'placeholder' => '',
		'options'     => '',
		'required'    => in_array( $key, array( 'name', 'email' ), true ) ? 'yes' : '',
		'width'       => '',
	);
}
