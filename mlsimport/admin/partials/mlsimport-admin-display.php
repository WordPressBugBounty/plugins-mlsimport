<?php
/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link      http://mlsimport.com/
 * @since      1.0.0
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<!-- This file should primarily consist of HTML with a little bit of PHP. -->
<div class="wrap">
	<h2><?php esc_html_e( 'MLS Import Options', 'mlsimport' ); ?></h2>

	<?php
		// Grab all options
		$options = get_option( $this->plugin_name );
		$active_tab = 'display_options';
	if ( isset( $_GET['tab'] ) ) {
		$active_tab = sanitize_text_field  ( wp_unslash(  $_GET['tab'] ) );
	}
	?>

	<div class="nav-tab-wrapper mlsimport-tab-wrapper">
		<a href="?page=mlsimport_plugin_options&tab=display_options" class="nav-tab  		  <?php echo   'display_options' 		===  $active_tab  ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'MLS/RESO Api Options','mlsimport' ); ?></a>
		<a href="?page=mlsimport_plugin_options&tab=field_options"   class="nav-tab    		  <?php echo   'field_options' 			 === $active_tab  ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Select Import fields', 'mlsimport' ); ?></a>
		<a href="?page=mlsimport_plugin_options&tab=administrative_options"  class="nav-tab   <?php echo    'administrative_options' === $active_tab  ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Tools', 'mlsimport' ); ?></a>
	</div>


	<?php 
	$extra_class='';
	if ( 'display_options' === $active_tab ) {
		$extra_class='mlsimport_2025_card_admin_options';
	} ?>
	
	<div class="content-nav-tab  <?php echo esc_attr($extra_class);?>   mlsimport_2025_card mlsimport_2025_card_left_oriented <?php echo  'display_options' === $active_tab  ? 'content-nav-tab-active' : ''; ?>">
		<?php
		if ( 'display_options' ===  $active_tab  ) {
			include_once '' . $this->plugin_name . '-admin-options.php';
		}
		?>
<?php if ( 'display_options' === $active_tab ) : ?>
                <div class="mlsimport-steps">
                <ol>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=mlsimport_plugin_options' ) ); ?>"><?php esc_html_e( 'Add your  MLSimport & MLS credentials.', 'mlsimport' ); ?></a></li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=mlsimport_plugin_options&tab=field_options' ) ); ?>"><?php esc_html_e( 'Select import fields.', 'mlsimport' ); ?></a></li>
                        <li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mlsimport_item' ) ); ?>"><?php esc_html_e( 'Create an import task & fetch listings.', 'mlsimport' ); ?></a></li>
                </ol>
        </div>
<?php endif; ?>


	</div>
		
	<div class="content-nav-tab  mlsimport_2025_card mlsimport_2025_card_left_oriented <?php echo 'field_options' === $active_tab  ? 'content-nav-tab-active' : ''; ?>">    
		<?php
		if ( 'field_options' === $active_tab  ) {
			include_once '' . $this->plugin_name . '-admin-fields-select.php';
		}
		?>
	</div>
		
  
	
	<div class="content-nav-tab  mlsimport_2025_card  mlsimport_2025_card_left_oriented <?php echo  'administrative_options' === $active_tab  ? 'content-nav-tab-active' : ''; ?>">
		<?php
		if ( 'administrative_options' === $active_tab  ) {
			include_once '' . $this->plugin_name . '-administrative-options.php';
		}
		?>
	</div>
	
	   
	
</div>