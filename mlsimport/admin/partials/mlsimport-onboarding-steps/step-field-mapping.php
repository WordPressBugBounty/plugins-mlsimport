<?php
/**
 * Form-free Field Configuration step for onboarding.
 *
 * Metadata gathering has already initialized the complete configuration on the
 * server. This step renders that same module view and uses the same compact
 * browser command endpoint as the settings tab. The Continue button navigates
 * only; it never submits hundreds of field inputs through options.php.
 *
 * @package    Mlsimport
 * @subpackage Mlsimport/admin/partials/mlsimport-onboarding-steps
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

global $mlsimport;

$metadata_populated = mlsimport_get_connection_option( 'mlsimport_mls_metadata_populated', '' );
if ( 'yes' === $metadata_populated ) {
	$mlsimport->admin->mlsimport_saas_setting_up();
	$metadata      = mlsimport_field_configuration_metadata();
	$theme_schema  = mlsimport_hardocde_theme_schema();
	$configuration = mlsimport_field_configuration()->read( $metadata, $theme_schema, mlsimport_field_configuration_taxonomies() );
	?>
	<div class="mlsimport-field-mapping-content">
		<div class="mlsimport-section">
			<div class="mlsimport-section-inner">
				<button type="button" class="button button-primary mlsimport-wizard-next mlsimport_button">
					<?php esc_html_e( 'Continue', 'mlsimport' ); ?>
				</button>
				<?php
				echo render_mls_field_selection_interface(
					$metadata,
					$configuration,
					array(
						'import_filter'    => 'selected',
						'show_filters'     => true,
						'show_stats'       => true,
						'enable_drag_drop' => true,
						'plugin_name'      => 'mlsimport',
					),
					$theme_schema
				);
				?>
				<p class="mlsimport-field-note">
					<?php esc_html_e( 'You can always refine these field mappings later in MLS Import settings.', 'mlsimport' ); ?>
				</p>
			</div>
		</div>
	</div>
	<?php
} else {
	$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
	if ( '' !== trim( $token ) ) {
		echo '<div class="mlsimport_populate_warning">' . esc_html__( 'We need to gather some information about your MLS. Please Stand By! ', 'mlsimport' ) . '</div>';
	} else {
		esc_html_e( 'You are not connected to MLS Import', 'mlsimport' );
	}
	?>
	<input type="hidden" id="mlsimport_saas_get_metadata" value="<?php echo esc_attr( wp_create_nonce( 'mlsimport_saas_get_metadata' ) ); ?>">
	<?php
}
?>
<script>
jQuery(function ($) {
	'use strict';
	$('.mlsimport-wizard-content-field-mapping .mlsimport-wizard-next').on('click', function () {
		window.location.href = ajaxurl.replace('admin-ajax.php', 'admin.php') + '?page=mlsimport-onboarding&step=import-config';
	});
});
</script>
