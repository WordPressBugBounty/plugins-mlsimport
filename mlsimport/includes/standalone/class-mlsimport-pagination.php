<?php
/**
 * Standalone (theme_id 990) pagination component.
 *
 * One source of pager markup for every listings surface: the AJAX listings grid
 * (Mlsimport_Standalone_Render::render_grid + the AJAX repaint) and the GET-based
 * Search Results page block. Given a total, a page size and the current page it
 * returns a <nav> of page controls (or '' for a single page).
 *
 * Each control is an anchor carrying BOTH a real ?page= URL (shareable / no-JS /
 * the GET block) and a data-page hook (the listings JS intercepts these to repaint
 * the grid via AJAX). The page list is windowed so a large set collapses to
 * first … current±2 … last instead of printing every page.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the shared listings pager.
 */
class Mlsimport_Pagination {

	/**
	 * Page numbers to keep either side of the current page before collapsing the
	 * run to an ellipsis.
	 */
	const WINDOW = 2;

	/**
	 * Render the pager.
	 *
	 * @param int   $total    Full match count (not the page count).
	 * @param int   $per_page Page size.
	 * @param int   $current  Current page (1-based).
	 * @param array $opts      { @type string $label aria-label for the nav. @type string $param
	 *                          query-arg key for the page links (default 'page'). Singular pages
	 *                          must use a non-reserved key — WP's redirect_canonical strips ?page=
	 *                          on a single post that has no <!--nextpage--> content. }
	 * @return string Pager HTML, or '' when there is a single page.
	 */
	public static function render( int $total, int $per_page, int $current, array $opts = array() ): string {
		$per_page = max( 1, $per_page );
		$pages    = (int) ceil( $total / $per_page );
		if ( $pages < 2 ) {
			return '';
		}
		$current = min( max( 1, $current ), $pages );
		$label   = isset( $opts['label'] ) ? (string) $opts['label'] : __( 'Listings pagination', 'mlsimport' );
		$param   = isset( $opts['param'] ) ? (string) $opts['param'] : 'page';

		$out = '<nav class="mlsimport-pager" role="navigation" aria-label="' . esc_attr( $label ) . '">';
		if ( $current > 1 ) {
			$out .= self::link( $current - 1, __( 'Previous', 'mlsimport' ), $param, 'mlsimport-pager__prev', 'prev' );
		}
		foreach ( self::page_list( $pages, $current ) as $page ) {
			if ( 0 === $page ) {
				$out .= '<span class="mlsimport-pager__gap" aria-hidden="true">&hellip;</span>';
			} elseif ( $page === $current ) {
				$out .= '<span class="mlsimport-pager__btn is-active" aria-current="page">' . esc_html( (string) $page ) . '</span>';
			} else {
				$out .= self::link( $page, (string) $page, $param );
			}
		}
		if ( $current < $pages ) {
			$out .= self::link( $current + 1, __( 'Next', 'mlsimport' ), $param, 'mlsimport-pager__next', 'next' );
		}
		$out .= '</nav>';
		return $out;
	}

	/**
	 * One page control: a real ?<param>= link that the AJAX layer hooks via data-page.
	 *
	 * @param int    $page        Target page.
	 * @param string $label       Visible label.
	 * @param string $param       Query-arg key for the page number.
	 * @param string $extra_class Extra BEM modifier class.
	 * @param string $rel         Optional rel attribute (prev/next).
	 * @return string
	 */
	private static function link( int $page, string $label, string $param = 'page', string $extra_class = '', string $rel = '' ): string {
		$class = 'mlsimport-pager__btn' . ( '' !== $extra_class ? ' ' . $extra_class : '' );
		$rel   = '' !== $rel ? ' rel="' . esc_attr( $rel ) . '"' : '';
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( $param, $page ) ) . '"'
			. ' data-page="' . esc_attr( (string) $page ) . '"' . $rel . '>' . esc_html( $label ) . '</a>';
	}

	/**
	 * The page-number sequence to render: page 1, the window around the current
	 * page, and the last page — with 0 marking an ellipsis gap where pages are
	 * skipped. E.g. pages=20 current=10 -> [1,0,8,9,10,11,12,0,20].
	 *
	 * @param int $pages   Total pages.
	 * @param int $current Current page.
	 * @return int[]
	 */
	private static function page_list( int $pages, int $current ): array {
		$keep = array();
		for ( $page = 1; $page <= $pages; $page++ ) {
			if ( 1 === $page || $pages === $page || ( $page >= $current - self::WINDOW && $page <= $current + self::WINDOW ) ) {
				$keep[] = $page;
			}
		}

		$list = array();
		$prev = 0;
		foreach ( $keep as $page ) {
			if ( $prev && $page - $prev > 1 ) {
				$list[] = 0; // Gap marker.
			}
			$list[] = $page;
			$prev    = $page;
		}
		return $list;
	}
}
