<?php
/**
 * Standalone listings archive. Theme-overridable: copy to your theme's
 * mlsimport/archive-mlsimport-property.php. Renders the full search UI.
 *
 * Wraps the standalone header/footer around a self-querying results grid
 * (Mlsimport_Standalone_Render::render_grid). It decides which search-form
 * filters to show (from settings) and, on taxonomy term archives, seeds the grid
 * so it filters by the queried term rather than showing every listing.
 *
 * @package Mlsimport
 */

// Block direct requests: bail unless WordPress is bootstrapped.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Emit the plugin's standalone header (theme-agnostic wrapper).
mlsimport_get_header();
?>
<!-- Listings archive wrapper -->
<div class="mlsimport-archive">
	<!-- Archive title (e.g. "Properties") -->
	<h1 class="mlsimport-archive__title"><?php post_type_archive_title(); ?></h1>
	<?php
	// Which search-form filters to show, from the "Taxonomy filters" setting — a
	// per-filter on/off toggle list ("Archive search filters"). Its active list
	// becomes render_grid's search_fields (a comma list of the fields to keep); all
	// filters off passes the "none" marker so the bar shows no fields. Unset falls
	// back to the default (Status, City and Property Type on).
	// Accumulator for the arguments passed to render_grid().
	$mli_archive_args = array();
	// Resolve which filters to render from the "Archive search filters" setting.
	if ( function_exists( 'mlsimport_standalone_option' ) ) {
		$mli_filters = mlsimport_standalone_option( 'archive_search_fields' );
		// Only override when the setting supplies an explicit active list.
		if ( is_array( $mli_filters ) && isset( $mli_filters['active'] ) ) {
			// Normalise the active list to a clean array of non-empty strings.
			$mli_active                        = array_values( array_filter( array_map( 'strval', (array) $mli_filters['active'] ) ) );
			// Empty list => "none" marker (no fields); else a comma list of fields to keep.
			$mli_archive_args['search_fields'] = empty( $mli_active ) ? 'none' : implode( ',', $mli_active );
		}
		// Cards per row is an archive-only setting: pass it as the grid's columns so it
		// applies here (taxonomy + CPT archives) but never to the page-builder blocks.
		$mli_archive_args['columns'] = (int) mlsimport_standalone_option( 'cards_per_row', 3 );
	}

	// On a taxonomy term archive, pre-filter by the queried term. The grid runs its
	// own query (independent of the main archive query) and would otherwise ignore
	// the term and show every listing; seeding the matching search field also
	// pre-selects it in the search form. Column-in fields carry the term name, term-
	// join fields the slug — matching the option values the field renders.
	// On a taxonomy term archive, seed the matching search field so the grid filters by that term.
	if ( is_tax() && class_exists( 'Mlsimport_Page_Block_Search_Fields' ) ) {
		// The queried term for this archive.
		$mli_term = get_queried_object();
		if ( $mli_term instanceof WP_Term ) {
			// Map the term's taxonomy to the search field that drives it.
			$mli_field = Mlsimport_Page_Block_Search_Fields::field_for_taxonomy( $mli_term->taxonomy );
			if ( null !== $mli_field ) {
				// Column-in fields carry the term name; term-join fields carry the slug.
				$mli_archive_args[ $mli_field['key'] ] = array( 'slug' === $mli_field['value'] ? $mli_term->slug : $mli_term->name );
			}
		}
	}

	// Let extensions inspect/adjust args before the grid renders.
	do_action( 'mlsimport_before_archive', $mli_archive_args );
	// The grid renders its own query (independent of the main archive query).
	echo Mlsimport_Standalone_Render::render_grid( $mli_archive_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_grid escapes at source.
	// Fire the after-archive hook once the grid is emitted.
	do_action( 'mlsimport_after_archive', $mli_archive_args );
	?>
</div>
<?php
// Emit the plugin's standalone footer.
mlsimport_get_footer();
