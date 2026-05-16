<?php
/**
 * Fires when the plugin is deleted from the Plugins screen.
 *
 * Data is removed only when explicitly enabled (option or constant), so
 * uninstalling by default does not wipe metrics tables.
 *
 * @package OIS_Conversion_Suite
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wipe = ( defined( 'OISCL_UNINSTALL_WIPE_DATA' ) && OISCL_UNINSTALL_WIPE_DATA )
	|| (string) get_option( 'oiscl_delete_data_on_uninstall', '' ) === '1';

if ( ! $wipe ) {
	return;
}

global $wpdb;

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'oiscl_block_metrics' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'oiscl_page_settings' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'oiscl_utm_references' );

delete_option( 'oiscl_settings' );
delete_option( 'oiscl_general_settings' );
delete_option( 'oiscl_custom_dashboards' );
delete_option( 'oiscl_report_templates' );
delete_option( 'oiscl_scheduled_report_jobs' );
delete_option( 'oiscl_delete_data_on_uninstall' );

// Best-effort removal of per-URL rule options (oiscl_rules_*).
$like = $wpdb->esc_like( 'oiscl_rules_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

$log_dir = WP_CONTENT_DIR . '/ois-logs/';
if ( is_dir( $log_dir ) ) {
	$stack = array( $log_dir );
	while ( $stack ) {
		$dir = array_pop( $stack );
		$items = @scandir( $dir );
		if ( ! is_array( $items ) ) {
			continue;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$stack[] = $path;
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}
