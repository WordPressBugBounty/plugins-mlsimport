<?php
/**
 * Standalone single agent profile, composed from agent sections.
 *
 * Theme-overridable: copy to your theme's mlsimport/single-mlsimport-agent.php.
 * Mirrors the single-property page's section principles — a hero locked at the
 * top, a sticky sub-nav, then a content column (About, Listings, Credentials)
 * beside a sticky contact rail. Each mlsimport_agent_* call returns one section's
 * HTML (already escaped at source) and the page enqueues the agent assets once.
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Open the standalone header (theme-agnostic wrapper).
mlsimport_get_header();

// Standard single loop: one mlsimport_agent post.
while ( have_posts() ) :
	the_post();
	$mli_agent_id = (int) get_the_ID();

	// Enqueue the agent page's CSS/JS once.
	mlsimport_agent_enqueue();

	/** Filter the colour palette for the standalone agent page. @since 6.4 */
	$mli_palette = (string) apply_filters( 'mlsimport_agent_palette', '', $mli_agent_id );
	// Base body classes; a recognised palette modifier is appended below.
	$mli_class   = 'mlsimport-single mlsimport-agent-single';
	if ( in_array( $mli_palette, array( 'coastal', 'teal' ), true ) ) {
		$mli_class .= ' mlsimport-palette-' . $mli_palette;
	}

	// Content-column order comes from the agent "Arrange Sections" control
	// (agent_sections); the hero + sub-nav (top) and the contact rail (sidebar)
	// are fixed. Falls back to the default order when the setting is empty, and
	// surfaces catalog sections a saved order predates (e.g. About) at their
	// catalog position.
	$mli_agent_active = mlsimport_standalone_agent_active_sections();

	/**
	 * Filter the ordered agent content-column section slugs.
	 *
	 * @param string[] $slugs        Active section slugs, in render order.
	 * @param int      $mli_agent_id Agent post ID.
	 */
	$mli_agent_active = (array) apply_filters( 'mlsimport_single_agent_sections', $mli_agent_active, $mli_agent_id );

	// Extension slot: before the single-agent article.
	do_action( 'mlsimport_before_single_agent', $mli_agent_id );
	?>
	<article class="<?php echo esc_attr( $mli_class ); ?>">
		<div class="mlsimport-agent-wrap">
			<?php
			// Fixed top of the page: hero, then the sticky sub-nav.
			echo mlsimport_agent_hero( $mli_agent_id );   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo mlsimport_agent_subnav( $mli_agent_id );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<div class="mlsimport-agent-layout">
				<div class="mlsimport-agent-layout__main">
					<?php
					// Content column: render each active section in the configured order.
					foreach ( $mli_agent_active as $mli_agent_slug ) {
						echo mlsimport_render_agent_section( $mli_agent_slug, $mli_agent_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</div>
				<?php echo /* fixed sidebar: the sticky contact rail */ mlsimport_agent_contact_rail( $mli_agent_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
	</article>
	<?php
	// Extension slot: after the single-agent article.
	do_action( 'mlsimport_after_single_agent', $mli_agent_id );
endwhile;

// Close the standalone footer.
mlsimport_get_footer();
