<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<form method="post" name="cleanup_options" action="">
	<?php
	$mlsimport->admin->setting_up();
	$old_data = get_option( 'mlsimport_cron_logs' );
	?>

	<h1> Property Update logs</h1>

	<?php
	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();
	}
	$path      = WP_PLUGIN_DIR . '/mlsimport/logs/cron_logs.log';
	$file_size = filesize( $path );



	$file_size_message = sprintf( esc_html__( 'Cron Log File Size is %s bytes', 'mlsimport' ), $file_size );
	echo esc_html($file_size_message . '<br><br>');

	if ( $file_size < 3000000 ) {
		$myfile = fopen( $path, 'r' ) or die( 'Unable to open file!' );
		if ( $file_size > 0 ) {
			echo htmlspecialchars(fread($myfile, $file_size), ENT_QUOTES, 'UTF-8');
		}
		fclose( $myfile );
	} else {
		esc_html_e(' The file is too large to be displayed. You can read it in mlsimport/logs/cron_logs.log','mlsimport');
	}
	?>      
</form>