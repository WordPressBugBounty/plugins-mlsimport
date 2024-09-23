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

	<h2 class="nav-tab-wrapper mlsimport-tab-wrapper">
		<a href="?page=mlsimport_plugin_options&tab=display_options" class="nav-tab  		  <?php echo   'display_options' 		===  $active_tab  ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'MLS/RESO Api Options','mlsimport' ); ?></a>
		<a href="?page=mlsimport_plugin_options&tab=field_options"   class="nav-tab    		  <?php echo   'field_options' 			 === $active_tab  ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Select Import fields', 'mlsimport' ); ?></a>
		<a href="?page=mlsimport_plugin_options&tab=administrative_options"  class="nav-tab   <?php echo    'administrative_options' === $active_tab  ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Tools', 'mlsimport' ); ?></a>
	</h2>
	
	<div class="content-nav-tab <?php echo  'display_options' === $active_tab  ? 'content-nav-tab-active' : ''; ?>">
		<?php
		if ( 'display_options' ===  $active_tab  ) {
			include_once '' . $this->plugin_name . '-admin-options.php';
		}
		?>
	</div>
		
	<div class="content-nav-tab <?php echo 'field_options' === $active_tab  ? 'content-nav-tab-active' : ''; ?>">    
		<?php
		if ( 'field_options' === $active_tab  ) {
			include_once '' . $this->plugin_name . '-admin-fields-select.php';
		}
		?>
	</div>
		
  
	
	<div class="content-nav-tab <?php echo  'administrative_options' === $active_tab  ? 'content-nav-tab-active' : ''; ?>">
		<?php
		if ( 'administrative_options' === $active_tab  ) {
			include_once '' . $this->plugin_name . '-administrative-options.php';
		}
		?>
	</div>
	
	   
	
</div>