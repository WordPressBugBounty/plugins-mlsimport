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
	// Active tab comes from ?tab= (sanitised) and resolves through the single
	// tab rule (mlsimport_settings_active_tab): Connections is the default and
	// the retired 'display_options' value aliases to it — old bookmarks and
	// links keep landing on the one connection surface.
	$active_tab = mlsimport_settings_active_tab(
		isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : ''
	);
	?>

	<div class="nav-tab-wrapper mlsimport-tab-wrapper">
		<a href="?page=mlsimport_plugin_options&tab=connections"     class="nav-tab    		  <?php echo   'connections' 			 === $active_tab  ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Connections', 'mlsimport' ); ?></a>
		<a href="?page=mlsimport_plugin_options&tab=field_options"   class="nav-tab    		  <?php echo   'field_options' 			 === $active_tab  ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Listing Details', 'mlsimport' ); ?></a>
		<a href="?page=mlsimport_plugin_options&tab=administrative_options"  class="nav-tab   <?php echo    'administrative_options' === $active_tab  ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Tools', 'mlsimport' ); ?></a>
	</div>

	<div class="content-nav-tab  mlsimport_2025_card mlsimport_2025_card_left_oriented <?php echo 'connections' === $active_tab  ? 'content-nav-tab-active' : ''; ?>">
		<?php
		// Connections tab panel (#280) — the multi-MLS Connections screen.
		if ( 'connections' === $active_tab ) {
			include_once '' . $this->plugin_name . '-connections.php';
		}
		?>
	</div>

	<div class="content-nav-tab  mlsimport_2025_card mlsimport_2025_card_left_oriented <?php echo 'field_options' === $active_tab  ? 'content-nav-tab-active' : ''; ?>">
		<?php
		// Tab 2 panel — load the field-selection partial only when active.
		if ( 'field_options' === $active_tab  ) {
			include_once '' . $this->plugin_name . '-admin-fields-select.php';
		}
		?>
	</div>
		
  
	
	<div class="content-nav-tab  mlsimport_2025_card  mlsimport_2025_card_left_oriented <?php echo  'administrative_options' === $active_tab  ? 'content-nav-tab-active' : ''; ?>">
		<?php
		// Tab 3 panel — load the Tools / administrative options partial only when active.
		if ( 'administrative_options' === $active_tab  ) {
			include_once '' . $this->plugin_name . '-administrative-options.php';
		}
		?>
	</div>

</div>