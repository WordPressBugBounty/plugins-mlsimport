<?php
/**
 * Standalone listings search form. Theme-overridable: copy to your theme's
 * mlsimport/search-form.php. Field name attributes map 1:1 to filter params.
 * Receives $args (current filter values) for pre-filling.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Current filter values passed in for pre-filling; default to an empty set.
$mli_args = isset( $args ) && is_array( $args ) ? $args : array();
// Helper: return a single scalar arg as a string (array-valued args yield '').
$mli_val  = function ( $key ) use ( $mli_args ) {
	return isset( $mli_args[ $key ] ) && ! is_array( $mli_args[ $key ] ) ? (string) $mli_args[ $key ] : '';
};

// Field visibility (the listings block/shortcode's per-field on/off toggles): null
// means show every field (default), else only the listed keys appear. Keywords and
// Sort gate on their own keys; each catalog field gates on its catalog key.
$mli_visible = class_exists( 'Mlsimport_Standalone_Render' ) ? Mlsimport_Standalone_Render::visible_fields( $mli_args ) : null;
// Helper: is this field visible? null visibility means show everything.
$mli_show    = function ( $key ) use ( $mli_visible ) {
	return null === $mli_visible || in_array( $key, $mli_visible, true );
};

// Search fields per row (the listings/half-map "fields per row" setting): a CSS
// custom property the stylesheet turns into the grid column count. Unset/invalid
// emits nothing, so the listings/half-map CSS fallback column count applies.
$mli_cols = function_exists( 'mlsimport_search_cols_style' ) ? mlsimport_search_cols_style( $mli_val( 'fields_per_row' ) ) : '';
?>
<form class="mlsimport-search" role="search"<?php echo $mli_cols; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer CSS var built internally. ?>>
	<?php if ( $mli_show( 'keywords' ) ) : /* free-text keywords input, gated on its own key */ ?>
		<label class="mlsimport-search__field">
			<?php esc_html_e( 'Keywords', 'mlsimport' ); ?>
			<input type="text" name="keywords" value="<?php echo esc_attr( $mli_val( 'keywords' ) ); ?>" placeholder="<?php esc_attr_e( 'Pool, lake view…', 'mlsimport' ); ?>">
		</label>
	<?php endif; ?>

	<?php
	// Every searchable field — taxonomies then fast-table columns (no geo) — from
	// the shared catalog, so this form and the Search Form block never drift.
	if ( class_exists( 'Mlsimport_Page_Block_Search_Fields' ) && function_exists( 'mlsimport_render_search_field' ) ) {
		// Walk every field in the shared catalog (taxonomies then fast-table columns).
		foreach ( Mlsimport_Page_Block_Search_Fields::catalog() as $mli_field ) {
			// Skip fields toggled off for this form.
			if ( ! $mli_show( $mli_field ) ) {
				continue;
			}
			// Fetch the field's definition; render it when the catalog knows the field.
			$mli_def = Mlsimport_Page_Block_Search_Fields::definition( $mli_field );
			if ( null !== $mli_def ) {
				echo mlsimport_render_search_field( $mli_def, $mli_args, '', 'mlsimport-search__field' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
			}
		}
	}
	?>

	<?php
	// Sort is NOT a filter field: it renders in the results toolbar (count left /
	// sort right) above the grid — see Mlsimport_Standalone_Render::render_grid(). It
	// still drives the same AJAX repaint; mlsimport-listings.js reads its
	// select[name="orderby"] from the toolbar, outside this form.

	// Carry every ACTIVE filter that has no visible field as a hidden input.
	//
	// This form is the AJAX layer's only state: mlsimport-listings.js builds the request
	// from its FormData and nothing else. So a filter that is not re-submitted here is
	// DROPPED the first time the visitor refines or paginates — page 1 is server-rendered
	// and looks right, page 2 silently widens. That is reachable whenever a block sets an
	// initial-filter preset whose search field is switched off ("Miami condos", where the
	// City field is hidden so visitors cannot change it) or has no search field at all
	// (the map box). It used to be patched one key at a time — `limit` and `agent` each had
	// their own hardcoded input — which fixed those two and left every other filter broken.
	//
	// One rule instead: a filter is either editable in the bar, or it rides along hidden.
	// For each active-but-not-editable filter, emit hidden input(s) so it rides along on submit.
	foreach ( mlsimport_search_form_hidden_args( $mli_args, $mli_visible ) as $mli_name => $mli_value ) :
		// Multi-value filters (arrays) get one hidden input per value.
		foreach ( (array) $mli_value as $mli_item ) :
			?>
			<input type="hidden" name="<?php echo esc_attr( $mli_name ); ?>" value="<?php echo esc_attr( (string) $mli_item ); ?>">
			<?php
		endforeach;
	endforeach;
	?>

	<button type="submit" class="mlsimport-search__submit"><?php esc_html_e( 'Search', 'mlsimport' ); ?></button>
</form>
