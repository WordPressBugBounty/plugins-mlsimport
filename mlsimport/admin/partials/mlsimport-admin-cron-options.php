<?php 
/**
 * Admin partial: "Property Update logs" viewer.
 *
 * Renders the contents of the cron log file (logs/cron_logs.log) inside the admin
 * area so the operator can review what the automated import/reconciliation runs
 * did. Small logs are printed inline; oversized logs point the user at the file.
 *
 * @package    mlsimport
 * @subpackage mlsimport/admin/partials
 */

// Block direct access outside of WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<form method="post" name="cleanup_options" action="">
	<?php
	// Ensure plugin settings/state are initialised before rendering.
	$mlsimport->admin->setting_up();
	// Legacy option read (kept for compatibility; not otherwise used here).
	$old_data = get_option( 'mlsimport_cron_logs' );
	?>

	<h1> Property Update logs</h1>

	<?php
	// Ensure the WP_Filesystem globals are available (loaded lazily below).
	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();
	}
	// Absolute path to the cron log file and its current size in bytes.
	$path      = WP_PLUGIN_DIR . '/mlsimport/logs/cron_logs.log';
	$file_size = filesize( $path );



	// Show the log file size to the operator.
	$file_size_message = sprintf( esc_html__( 'Cron Log File Size is %s bytes', 'mlsimport' ), $file_size );
	echo esc_html($file_size_message . '<br><br>');

	// Only inline the log when it is under ~3 MB; otherwise it is too big to render.
	if ( $file_size < 3000000 ) {
		// Open the log for reading (die with a message if it cannot be opened).
		$myfile = fopen( $path, 'r' ) or die( 'Unable to open file!' );
		// Read and print the whole file, escaping HTML so log text can't inject markup.
		if ( $file_size > 0 ) {
			echo htmlspecialchars(fread($myfile, $file_size), ENT_QUOTES, 'UTF-8');
		}
		fclose( $myfile );
	} else {
		// Too large to display — point the operator at the file on disk.
		esc_html_e(' The file is too large to be displayed. You can read it in mlsimport/logs/cron_logs.log','mlsimport');
	}
	?>      
</form>