<?php
/**
 * Standalone agent box. Theme-overridable: copy to your theme's
 * mlsimport/parts/agent-box.php. Receives $agent_id (mlsimport_agent post ID).
 *
 * @package Mlsimport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Resolve the passed-in agent id (template arg $agent_id); 0 when missing.
$mli_agent_id = isset( $agent_id ) ? (int) $agent_id : 0;
// Bail out unless we have a real mlsimport_agent post to render.
if ( ! $mli_agent_id || 'mlsimport_agent' !== get_post_type( $mli_agent_id ) ) {
	return;
}

// Agent display name (post title).
$mli_name  = get_the_title( $mli_agent_id );
// Contact + affiliation fields, read from the agent's RESO-derived post meta.
$mli_email = get_post_meta( $mli_agent_id, 'mlsimport_ListAgentEmail', true );
$mli_phone = get_post_meta( $mli_agent_id, 'mlsimport_ListAgentPreferredPhone', true );
$mli_office = get_post_meta( $mli_agent_id, 'mlsimport_ListOfficeName', true );
?>
<aside class="mlsimport-agent-box">
	<h3 class="mlsimport-agent-box__name">
		<a href="<?php echo esc_url( get_permalink( $mli_agent_id ) ); ?>"><?php echo esc_html( $mli_name ); ?></a>
	</h3>
	<?php if ( '' !== $mli_office ) : /* office affiliation line, only when set */ ?>
		<p class="mlsimport-agent-box__office"><?php echo esc_html( $mli_office ); ?></p>
	<?php endif; ?>
	<ul class="mlsimport-agent-box__contact">
		<?php if ( '' !== $mli_phone ) : /* phone row; tel: href strips all but digits and + */ ?>
			<li><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $mli_phone ) ); ?>"><?php echo esc_html( $mli_phone ); ?></a></li>
		<?php endif; ?>
		<?php if ( '' !== $mli_email ) : /* email row; mailto: link */ ?>
			<li><a href="mailto:<?php echo esc_attr( $mli_email ); ?>"><?php echo esc_html( $mli_email ); ?></a></li>
		<?php endif; ?>
	</ul>
</aside>
