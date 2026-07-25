<?php
/**
 * Standalone agents archive. Theme-overridable: copy to your theme's
 * mlsimport/archive-mlsimport-agent.php.
 *
 * Renders the agent post-type archive as a grid of agent cards (photo, name,
 * optional phone) wrapped by the plugin's standalone header/footer, followed by
 * the default posts pagination. Runs in the standalone (theme-agnostic) surface.
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
<!-- Agents archive wrapper -->
<div class="mlsimport-agents">
	<!-- Archive title (e.g. "Agents") -->
	<h1 class="mlsimport-agents__title"><?php post_type_archive_title(); ?></h1>
	<!-- Grid of agent cards -->
	<div class="mlsimport-agents__grid">
		<?php
		// Standard WordPress loop over the current archive query.
		while ( have_posts() ) :
			the_post();
			?>
			<!-- One agent card -->
			<article class="mlsimport-agent-card">
				<!-- Card is a single link to the agent's single view -->
				<a class="mlsimport-agent-card__link" href="<?php the_permalink(); ?>">
					<!-- Show the featured image as the agent photo when one is set -->
					<?php if ( has_post_thumbnail() ) : ?>
						<div class="mlsimport-agent-card__photo"><?php the_post_thumbnail( 'medium' ); ?></div>
					<?php endif; ?>
					<h2 class="mlsimport-agent-card__name"><?php the_title(); ?></h2>
				</a>
				<?php
				// Read the agent's preferred phone from post meta.
				$mli_phone = get_post_meta( get_the_ID(), 'mlsimport_ListAgentPreferredPhone', true );
				// Only render the phone line when a value is present.
				if ( '' !== $mli_phone ) :
					?>
					<p class="mlsimport-agent-card__phone"><?php echo esc_html( $mli_phone ); ?></p>
				<?php endif; ?>
			</article>
			<?php
		endwhile;
		?>
	</div>
	<!-- Default archive pagination links -->
	<?php the_posts_pagination(); ?>
</div>
<?php
// Emit the plugin's standalone footer.
mlsimport_get_footer();
