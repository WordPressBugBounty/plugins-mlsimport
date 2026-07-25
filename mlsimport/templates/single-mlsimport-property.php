<?php
/**
 * Standalone single listing template, composed from property sections.
 *
 * Theme-overridable: copy to your theme's mlsimport/single-mlsimport-property.php
 * to change the markup. Each mlsimport_render_property_section() call renders one
 * section (the same function that backs its shortcode/block/Elementor widget) and
 * enqueues only that section's assets.
 *
 * Structure is fixed: a full-width hero (breadcrumbs, gallery, title bar, sticky
 * sub-nav) locked at the top, then a content column beside a sticky sidebar (the
 * agent/booking component). Which sections render in the content column, and in
 * what order, comes from the "Arrange Sections" control (the property_sections
 * setting); the locked hero and the sidebar are not affected by that order.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Open the standalone header (theme-agnostic wrapper).
mlsimport_get_header();

// Standard single loop: one mlsimport_property post.
while ( have_posts() ) :
	the_post();
	$mli_id = get_the_ID();

	/**
	 * Filter the colour palette for the standalone property page.
	 *
	 * @param string $palette '' (warm default) | 'coastal' | 'teal'.
	 * @param int    $mli_id  Property post ID.
	 */
	$mli_palette = (string) apply_filters( 'mlsimport_property_palette', '', $mli_id );
	// Base body classes; a recognised palette modifier is appended below.
	$mli_class   = 'mlsimport-single mlsimport-property-single';
	if ( in_array( $mli_palette, array( 'coastal', 'teal' ), true ) ) {
		$mli_class .= ' mlsimport-palette-' . $mli_palette;
	}

	// Render order comes from the "Arrange Sections" control (property_sections);
	// the page renders the Enabled sections in the order set there. Falls back to
	// the prototype default when the setting is empty, and picks up sections
	// registered since the layout was last saved.
	// Active content-column sections, in the saved "Arrange Sections" order (with newly registered sections appended).
	$mli_active = mlsimport_standalone_active_sections();

	/**
	 * Filter the ordered section slugs rendered on the standalone single page.
	 *
	 * @param string[] $slugs  Active section slugs, in render order.
	 * @param int      $mli_id Property post ID.
	 */
	$mli_active = (array) apply_filters( 'mlsimport_single_property_sections', $mli_active, $mli_id );
	?>
	<?php
	// Full-width hero, locked at the top (matches the prototype) — not reorderable.
	// Breadcrumbs lead, in the conventional spot right under the site header.
	$mli_locked = array( 'breadcrumbs', 'property_gallery', 'title_bar', 'subnav' );
	?>
	<?php
	// Extension slot: before the single-property article.
	do_action( 'mlsimport_before_single_property', $mli_id ); ?>
	<article class="<?php echo esc_attr( $mli_class ); ?>">
		<?php
		// Render the locked hero sections first, in fixed order (breadcrumbs, gallery, title bar, sub-nav).
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
endwhile;

// Close the standalone footer.
mlsimport_get_footer();
