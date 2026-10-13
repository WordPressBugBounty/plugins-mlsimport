<?php
/**
 * Standalone (theme_id 990) Blog Posts page block: blog posts as cards.
 *
 * Free Elementor has no posts grid (its Posts widget is Pro) and the
 * WordPress "Recent Posts" widget is a bare list of titles, so a home or
 * landing page had no way to show a blog row. This block renders the site's
 * blog posts as cards, like the listing cards: featured image, date, title,
 * excerpt and a read-more link, in a responsive grid, with an optional pager.
 *
 * One schema (mlsimport_page_block_blog_args()) feeds the shortcode
 * [mlsimport_blog_posts], the block editor block mlsimport/blog-posts and
 * the Elementor widget mlsimport-blog-posts through the page-block dispatcher
 * (page-block-registry.php), so the three emit the same markup. The style
 * options (mlsimport_page_block_style_blog()) are the card background and
 * radius, the title color and size and the image height, on both builders.
 *
 * Public interface:
 *   mlsimport_page_block_blog_args()           the option schema of the block
 *   mlsimport_page_block_style_blog()          the style option rows of the block
 *   mlsimport_page_block_blog_posts( $args )   the render function
 *   mlsimport_blog_on( $value )                a toggle value read as on / off
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/includes/standalone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The option schema of the Blog Posts block.
 *
 * The columns option is responsive: the Elementor widget shows one control with a
 * desktop / tablet / mobile value, stored as columns, columns_tablet and
 * columns_mobile; the block editor and the shortcode take the three keys.
 *
 * @return array<string, array<string, mixed>> Option => schema row.
 */
function mlsimport_page_block_blog_args(): array {
	$columns = array(
		'1' => '1',
		'2' => '2',
		'3' => '3',
		'4' => '4',
	);
	return array(
		'count'           => array(
			'type'    => 'number',
			'label'   => __( 'How many posts', 'mlsimport' ),
			'default' => 3,
		),
		'category'        => array(
			'type'     => 'multiselect',
			'label'    => __( 'Categories (empty = all)', 'mlsimport' ),
			'default'  => '',
			'taxonomy' => 'category',
		),
		'order'           => array(
			'type'    => 'select',
			'label'   => __( 'Order', 'mlsimport' ),
			'default' => 'newest',
			'options' => array(
				'newest' => __( 'Newest first', 'mlsimport' ),
				'oldest' => __( 'Oldest first', 'mlsimport' ),
				'random' => __( 'Random', 'mlsimport' ),
			),
		),
		'columns'         => array(
			'type'       => 'select',
			'label'      => __( 'Columns', 'mlsimport' ),
			'default'    => '3',
			'options'    => $columns,
			'responsive' => true,
		),
		'columns_tablet'  => array(
			'type'    => 'select',
			'label'   => __( 'Columns (tablet)', 'mlsimport' ),
			'default' => '2',
			'options' => $columns,
		),
		'columns_mobile'  => array(
			'type'    => 'select',
			'label'   => __( 'Columns (mobile)', 'mlsimport' ),
			'default' => '1',
			'options' => $columns,
		),
		'show_image'      => array(
			'type'    => 'toggle',
			'label'   => __( 'Show featured image', 'mlsimport' ),
			'default' => '1',
		),
		'show_date'       => array(
			'type'    => 'toggle',
			'label'   => __( 'Show date', 'mlsimport' ),
			'default' => '1',
		),
		'show_excerpt'    => array(
			'type'    => 'toggle',
			'label'   => __( 'Show excerpt', 'mlsimport' ),
			'default' => '1',
		),
		'excerpt_words'   => array(
			'type'    => 'number',
			'label'   => __( 'Excerpt length (words)', 'mlsimport' ),
			'default' => 20,
		),
		'show_read_more'  => array(
			'type'    => 'toggle',
			'label'   => __( 'Show "Read more"', 'mlsimport' ),
			'default' => '1',
		),
		'hide_pagination' => array(
			'type'    => 'toggle',
			'label'   => __( 'Hide pagination', 'mlsimport' ),
			'default' => '',
		),
	);
}

/**
 * The style options of the Blog Posts block (page-block-style-options.php
 * vocabulary): option => row with the CSS for the block editor and the
 * Elementor Style tab control that does the same.
 *
 * @return array<string, array<string, string>>
 */
function mlsimport_page_block_style_blog(): array {
	$r    = 'mlsimport_page_block_style_row';
	$card = '.mlsimport-blog-card';
	$e    = 'mlsimport_blog_';
	return array(
		'card_background' => $r( 'Card background color', 'color', $card, 'background-color: %s', $e . 'card_background', 'color' ),
		'card_radius'     => $r( 'Card corner radius (px)', 'number', $card, 'border-radius: %spx', $e . 'card_radius', 'dimensions' ),
		'title_color'     => $r( 'Title color', 'color', $card . '__title a', 'color: %s', $e . 'title_color', 'color' ),
		'title_size'      => $r( 'Title font size (px)', 'number', $card . '__title', 'font-size: %spx', $e . 'title_typography', 'font' ),
		'image_height'    => $r( 'Image height (px)', 'number', $card . '__media', 'height: %spx', $e . 'image_height', 'slider' ),
	);
}

/**
 * Read a toggle value as on / off, whatever builder stored it: the block
 * editor's true / false, Elementor's 'yes' / '', the shortcode's '1' / ''.
 *
 * @param mixed $value The stored value.
 * @return bool
 */
function mlsimport_blog_on( $value ): bool {
	if ( is_bool( $value ) ) {
		return $value;
	}
	return ! in_array( strtolower( trim( (string) $value ) ), array( '', '0', 'no', 'false' ), true );
}

/**
 * Blog Posts: the posts as cards.
 *
 * Step by step:
 * 1. Query the published posts: how many, which categories (ids as a comma
 *    list or a list), the order, and the page when the pager is shown.
 * 2. One card per post with the parts the toggles allow.
 * 3. The grid wrapper carries the columns per device as CSS variables the
 *    stylesheet reads; the pager comes after the grid unless hidden.
 *
 * @param array $args Block args, see mlsimport_page_block_blog_args().
 * @return string HTML.
 */
function mlsimport_page_block_blog_posts( array $args ): string {
	// Step 1: the query.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET paging of published posts.
	$asked      = isset( $_GET['mlsimport_page'] ) ? absint( wp_unslash( $_GET['mlsimport_page'] ) ) : 1;
	$paged      = mlsimport_blog_on( $args['hide_pagination'] ?? '' ) ? 1 : max( 1, $asked );
	$order      = (string) ( $args['order'] ?? 'newest' );
	$query      = array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => max( 1, (int) ( $args['count'] ?? 3 ) ),
		'paged'               => $paged,
		'orderby'             => 'random' === $order ? 'rand' : 'date',
		'order'               => 'oldest' === $order ? 'ASC' : 'DESC',
		'ignore_sticky_posts' => true,
		'no_found_rows'       => mlsimport_blog_on( $args['hide_pagination'] ?? '' ),
	);
	$categories = mlsimport_category_parse_ids( $args['category'] ?? '' );
	if ( ! empty( $categories ) ) {
		$query['category__in'] = $categories;
	}
	$posts = new WP_Query( $query );

	// Step 2: the cards.
	$cards = '';
	foreach ( $posts->posts as $post ) {
		$cards .= mlsimport_blog_card( $post, $args );
	}

	// Step 3: the wrapper, the grid and the pager.
	$vars = mlsimport_category_css_vars(
		array(
			'--mlsimport-blog-cols'        => (string) max( 1, (int) ( $args['columns'] ?? 3 ) ),
			'--mlsimport-blog-cols-tablet' => (string) max( 1, (int) ( $args['columns_tablet'] ?? 2 ) ),
			'--mlsimport-blog-cols-mobile' => (string) max( 1, (int) ( $args['columns_mobile'] ?? 1 ) ),
		)
	);
	$html = '<div class="mlsimport-page-block mlsimport-page-block--blog-posts mlsimport-blog" style="' . esc_attr( $vars ) . '">';
	if ( '' === $cards ) {
		$html .= '<p class="mlsimport-results__empty">' . esc_html__( 'No posts yet.', 'mlsimport' ) . '</p>';
	} else {
		$html .= '<div class="mlsimport-blog__grid">' . $cards . '</div>';
	}
	if ( ! mlsimport_blog_on( $args['hide_pagination'] ?? '' ) && (int) $posts->found_posts > (int) $query['posts_per_page'] ) {
		// The same pager as the listing grids, on the non-reserved mlsimport_page key
		// (a bare ?page= on a singular page is WordPress's <!--nextpage--> var).
		$html .= '<div class="mlsimport-results__pager">' . Mlsimport_Pagination::render( (int) $posts->found_posts, (int) $query['posts_per_page'], $paged, array( 'param' => 'mlsimport_page' ) ) . '</div>';
	}
	return $html . '</div>';
}

/**
 * One blog card: image, date, title, excerpt, read-more, as the toggles allow.
 *
 * @param WP_Post $post The post.
 * @param array   $args Block args.
 * @return string HTML.
 */
function mlsimport_blog_card( $post, array $args ): string {
	$url   = (string) get_permalink( $post );
	$title = (string) get_the_title( $post );
	$html  = '<article class="mlsimport-blog-card">';

	if ( mlsimport_blog_on( $args['show_image'] ?? '1' ) ) {
		$image = (string) get_the_post_thumbnail_url( $post, 'large' );
		$html .= '' !== $image
			? '<a class="mlsimport-blog-card__media" href="' . esc_url( $url ) . '" style="background-image:url(' . esc_url( $image ) . ')" aria-label="' . esc_attr( $title ) . '"></a>'
			: '<a class="mlsimport-blog-card__media mlsimport-blog-card__media--empty" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $title ) . '"></a>';
	}
	$html .= '<div class="mlsimport-blog-card__body">';
	if ( mlsimport_blog_on( $args['show_date'] ?? '1' ) ) {
		$html .= '<time class="mlsimport-blog-card__date" datetime="' . esc_attr( get_the_date( 'c', $post ) ) . '">' . esc_html( get_the_date( '', $post ) ) . '</time>';
	}
	$html .= '<h3 class="mlsimport-blog-card__title"><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></h3>';
	if ( mlsimport_blog_on( $args['show_excerpt'] ?? '1' ) ) {
		$words = max( 1, (int) ( $args['excerpt_words'] ?? 20 ) );
		$html .= '<p class="mlsimport-blog-card__excerpt">' . esc_html( wp_trim_words( wp_strip_all_tags( (string) get_the_excerpt( $post ) ), $words ) ) . '</p>';
	}
	if ( mlsimport_blog_on( $args['show_read_more'] ?? '1' ) ) {
		$html .= '<a class="mlsimport-blog-card__more" href="' . esc_url( $url ) . '">' . esc_html__( 'Read more', 'mlsimport' ) . '</a>';
	}
	return $html . '</div></article>';
}
