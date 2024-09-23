<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$options                          = get_option( $this->plugin_name . '_admin_options' );
$mlsimport_mls_metadata_populated = get_option( 'mlsimport_mls_metadata_populated', '' );
$permited_tags=mlsimport_allowed_html_tags_content();
$post_type= '';
if (method_exists($this->env_data, 'get_property_post_type')) {
    $post_type = $this->env_data->get_property_post_type();
}

$available_taxonomies  = mlsimport_get_custom_post_type_taxonomies($post_type);



if ( 'yes' ===  $mlsimport_mls_metadata_populated  ) { ?>
<form method="post" name="cleanup_options" action="options.php">
	<?php
		settings_fields( $this->plugin_name . '_admin_fields_select' );
		do_settings_sections( $this->plugin_name . '_admin_fields_select' );
		$options = get_option( $this->plugin_name . '_admin_fields_select' );

		$mlsimport_mls_metadata_theme_schema = get_option( 'mlsimport_mls_metadata_theme_schema', '' );
		$mlsimport_mls_metadata_mls_data     = get_option( 'mlsimport_mls_metadata_mls_data', '' );
		$mlsimport_mls_metadata_mls_data     = json_decode( $mlsimport_mls_metadata_mls_data, true );

		$theme_schema                            = $mlsimport_mls_metadata_theme_schema;
		$metadata_api_call_data_service_property = $mlsimport_mls_metadata_mls_data;


		global $mlsimport;
		$mlsimport->admin->mlsimport_saas_setting_up();
		$mandatory_fields     = '';
		$non_mandatory_fields = '';


		if(is_array($metadata_api_call_data_service_property)){
			foreach ( $metadata_api_call_data_service_property as $key => $value ) {
				$description = 'no description ';
				$mandatory   = 0;

				$is_checkbox       = 0;
				$is_checkbox_admin = 0;



				if ( isset( $options['mls-fields'][ $key ] ) && 1 === intval( $options['mls-fields'][ $key ] ) ) {
					$is_checkbox = 1;
				} elseif ( ! isset( $options['mls-fields'][ $key ] ) ) {
						$is_checkbox = 1;
				}

				if ( isset( $options['mls-fields-admin'][ $key ] ) && 1 === intval(  $options['mls-fields-admin'][ $key ])  ) {
					$is_checkbox_admin = 1;
				}


				if ( array_key_exists( $key, $theme_schema ) ) {
					$mandatory_fields .= '<strong>' . esc_html($key) . '</strong><div class="mls_mandatory_fields">' . esc_html( $value ) . '</div>';
				} else {
					$label_value = '';
					if ( isset( $options['mls-fields-label'][ $key ] ) ) {
						$label_value = $options['mls-fields-label'][ $key ];
					}
					if ( '' ===  $label_value  ) {
						$label_value = $key;
					}

					$label_post_meta='';
					if ( isset( $options['mls-fields-map-postmeta'][ $key ] ) ) {
						$label_post_meta = $options['mls-fields-map-postmeta'][ $key ];
					}
					$label_tax_meta='';
					if ( isset( $options['mls-fields-map-taxonomy'][ $key ] ) ) {
						$label_tax_meta = $options['mls-fields-map-taxonomy'][ $key ];
					}

				
					

					$non_mandatory_fields .= '
						<fieldset class="mlsimport-fieldset">
						
							<h4 class="mlsfield_import_title">' . esc_html($key) . ' </h4>

						
							<div class="mlsimport-fieldset-wrapper">

								<div class="mlsimport-fieldset-item">
									<label>' . esc_html__( 'Label(to not import the label in taxonomy type none )', 'mlsimport' ) . '</label> 
									<input type="text" class="mlsimport-fieldset mlsfield_label" name="' . esc_attr( $this->plugin_name) . '_admin_fields_select[mls-fields-label][' . esc_attr( $key) . ']"  value="' . esc_html($label_value) . '">
								</div>
							
								<div class="mlsimport-fieldset-item">
									<label>'.esc_html__('Import?','mlsimport').'</label>
									<input type="hidden" id="' . esc_attr($this->plugin_name ). '_admin_fields_select-mls_field_' . esc_attr( $key) . '" name="' . esc_attr( $this->plugin_name ). '_admin_fields_select[mls-fields][' . esc_attr( $key) . ']" value="0"/>
									<input type="checkbox"  class="mlsimport_select_import_all" id="' .esc_attr( $this->plugin_name ). '_admin_fields_select-mls_field_' .esc_attr(  $key) . '" name="' . esc_attr( $this->plugin_name ). '_admin_fields_select[mls-fields][' . esc_attr( $key) . ']" value="1"' . checked( $is_checkbox, 1, 0 ) . '/>
								</div>  

								<div class="mlsimport-fieldset-item">
									<label>'.esc_html__('Visible only in admin ?','mlsimport').'</label>
									<input type="hidden" id="' . esc_attr( $this->plugin_name ). '_admin_fields_select-visible-mls_field_' .esc_attr(  $key ). '" name="' . esc_attr( $this->plugin_name ). '_admin_fields_select[mls-fields-admin][' .esc_attr(  $key ). ']" value="0"/>
									<input type="checkbox" class="mlsimport_select_import_admin_all" id="' . esc_attr( $this->plugin_name ). '_admin_fields_select-visible-mls_field_' . esc_attr( $key ). '" name="' . esc_attr( $this->plugin_name ). '_admin_fields_select[mls-fields-admin][' .esc_attr(  $key ) . ']" value="1"' . checked( $is_checkbox_admin, 1, 0 ) . '/>                        
								</div>	

								<div class="mlsimport-fieldset-item">
									<label>' . esc_html__( 'Save field as this post meta (add field id name)', 'mlsimport' ).'</label> 
									<input type="text" class="mlsimport-fieldset mlsfield_map_post_meta" name="' . esc_attr( $this->plugin_name) . '_admin_fields_select[mls-fields-map-postmeta][' . esc_attr( $key) . ']"  value="' . esc_html($label_post_meta) . '">
								</div>

								<div class="mlsimport-fieldset-item">
									<label>' . esc_html__( 'Add Field value as term in category:', 'mlsimport' ).'</label> 
									

									<select class="mlsfield_map_post_tax_select" name="' . esc_attr( $this->plugin_name) . '_admin_fields_select[mls-fields-map-taxonomy][' . esc_attr( $key) . ']" >
									<option value="">'.esc_html('None','mlsimport').'</option>';
									
									foreach($available_taxonomies as $key=>$label){
										$non_mandatory_fields .= '<option value="' . esc_attr($key) . '"';
									
										if ($label_tax_meta == $key) {
											$non_mandatory_fields .= ' selected';
										}
					
										$non_mandatory_fields .= '>' . esc_html($label) . '</option>';
									}

									$non_mandatory_fields .='</select>

						
								</div>


							</div>';
							if( $value !==''){
								$non_mandatory_fields .= '
								<div class="mandatory_fields_wrapper_exp">
									<strong>' . esc_html__( 'Explanation', 'mlsimport' ) . ':</strong> ' . stripslashes(esc_html( $value) ) . '
								</div>';
							}

					$non_mandatory_fields .= '</fieldset>';
				}
			}
		}
	?>
	
	<div class="mandatory_fields_wrapper">
		<h3><?php esc_html_e( 'These fields will be imported (if found) by default:', 'mlsimport' );?> </h3> 
		<?php echo wp_kses ( $mandatory_fields ,$permited_tags ) ;?>

		<div class="mandatory_fields_wrapper">
		<h3>
			<?php echo esc_html_e('Select the extra fields you want to import', 'mlsimport'); ?>:
		</h3>
		
		<div id="mls_import_select_all" class="mls_import_selec_all_class" data-import="import_select"><?php echo esc_html_e('Import - Select All', 'mlsimport'); ?></div>
		<div id="mls_import_select_none" class="mls_import_selec_all_class" data-import="import_select_none"><?php echo esc_html_e('Import - Select None', 'mlsimport'); ?></div>
		<div id="mls_import_admin_select_all" class="mls_import_selec_all_class" data-import="import_admin"><?php echo esc_html_e('Admin Only - Select All', 'mlsimport'); ?></div>
		<div id="mls_import_admin_select_none" class="mls_import_selec_all_class" data-import="import_admin_none"><?php echo esc_html_e('Admin Only - Select None', 'mlsimport'); ?></div>
		<?php 
		echo wp_kses ( $non_mandatory_fields ,$permited_tags );
		?>
	</div>


	

	<input type="hidden" name="<?php echo esc_attr($this->plugin_name ). '_admin_fields_select[mls-fields-admin][force_rand]'; ?>" value="<?php echo esc_attr(wp_rand()); ?>">
 
	<?php submit_button( __( 'Save Changes', 'mlsimport'), 'mlsimport_button', 'submit', true ); ?>
</form>
		
	<?php
} else {
	global $mlsimport;
	$token = $mlsimport->admin->mlsimport_saas_get_mls_api_token_from_transient();
	if ( trim( $token ) !== '' ) {
		?>

	<div class="mlsimport_populate_warning">
		<?php 
		esc_html_e( 'We need to gather some information about your MLS. Please Stand By! ', 'mlsimport' ); 
		?>
	</div>

		<?php
	} else {
		esc_html_e( 'Your are not connected to MLS Import', 'mlsimport' );
	}
}


?>
<input type="hidden" id="mlsimport_saas_get_metadata" value="<?php echo esc_attr($ajax_nonce = wp_create_nonce( "mlsimport_saas_get_metadata" )); ?>" />
	