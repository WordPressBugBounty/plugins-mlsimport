<?php
/**
 * Live single listing template — the stored single-mlsimport-property.php
 * structure without the post loop. There is no post: every section reads the
 * view model that seam #2 (mlsimport_property_data_pre) serves from the
 * fetched RESO record, so $mli_id stays 0 throughout.
 *
 * Structure is fixed to match the stored page: a full-width hero (gallery,
 * title bar, sticky sub-nav) locked at the top, then the "Arrange Sections"
 * content column beside the sticky booking sidebar.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Open the standalone header (theme-agnostic wrapper).
mlsimport_get_header();

// No WP post in live mode: sections read the view model served by seam #2, so the id stays 0.
$mli_id = 0;

/** This filter is documented in templates/single-mlsimport-property.php */
$mli_palette = (string) apply_filters( 'mlsimport_property_palette', '', $mli_id );
// Base body classes for the live single; the palette (if any) is appended below.
$mli_class   = 'mlsimport-single mlsimport-property-single mlsimport-property-single--live';
// Only append a palette modifier for a recognised palette value.
if ( in_array( $mli_palette, array( 'coastal', 'teal' ), true ) ) {
	$mli_class .= ' mlsimport-palette-' . $mli_palette;
}

// Render order comes from the "Arrange Sections" control (property_sections);
// the page renders the Enabled sections in the order set there. Falls back to
// the prototype default when the setting is empty.
// Saved "Arrange Sections" layout (or the default set when it has never been saved).
$mli_layout = mlsimport_standalone_option( 'property_sections' );
// Use the saved active-section list when present, else the prototype default order.
$mli_active = ( is_array( $mli_layout ) && ! empty( $mli_layout['active'] ) )
	? $mli_layout['active']
	: mlsimport_standalone_sections_default()['active'];

/** This filter is documented in templates/single-mlsimport-property.php */
$mli_active = (array) apply_filters( 'mlsimport_single_property_sections', $mli_active, $mli_id );

// Full-width hero, locked at the top (matches the prototype) — not reorderable.
$mli_locked = array( 'property_gallery', 'title_bar', 'subnav' );
?>
<?php
// Extension slot: before the single-property article.
do_action( 'mlsimport_before_single_property', $mli_id ); ?>
<article class="<?php echo esc_attr( $mli_class ); ?>">
	<?php
	// Render the locked hero sections first, in fixed order (gallery, title bar, sub-nav).
	foreach ( $mli_locked as $mli_slug ) {
		echo mlsimport_render_property_section( $mli_slug, $mli_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	?>
	<div class="mlsimport-property-layout">
		<div class="mlsimport-property-layout__main">
			<?php
			// Content column — rendered in the "Arrange Sections" order, minus the
			// locked hero sections (so they stay full-width and don't duplicate).
			foreach ( $mli_active as $mli_slug ) {
				// Skip the locked hero sections here — already rendered full-width above.
				if ( in_array( $mli_slug, $mli_locked, true ) ) {
					continue;
				}
				echo mlsimport_render_property_section( $mli_slug, $mli_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<div class="mlsimport-property-layout__aside">
			<?php echo /* sticky sidebar: the booking/agent component */ mlsimport_render_property_section( 'booking', $mli_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
</article>
<?php
// Extension slot: after the single-property article.
do_action( 'mlsimport_after_single_property', $mli_id );

// Close the standalone footer.
mlsimport_get_footer();
