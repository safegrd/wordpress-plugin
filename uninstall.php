<?php
/**
 * Removes the plugin's settings when it is deleted. Backups already taken
 * stay in SafeGrd.
 *
 * @package SafeGrd
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'safegrd_settings' );
delete_option( 'safegrd_last_run' );
delete_option( 'safegrd_backup_lock' );
delete_option( 'safegrd_job' );
delete_option( 'safegrd_backup_doing' );
delete_option( 'safegrd_repo' );
delete_option( 'safegrd_cache_version' );
delete_option( 'safegrd_slice_key' );
delete_option( 'safegrd_backup_requested' );
delete_option( 'safegrd_restore' );
delete_option( 'safegrd_last_restore' );
delete_option( 'safegrd_restore_failed' );
delete_transient( 'safegrd_plans' );
global $wpdb;
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'safegrd_files' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'safegrd_blobs' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'safegrd_restore_files' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'safegrd_restore_index' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
delete_transient( 'safegrd_connect_session' );
delete_transient( 'safegrd_loopback' );
delete_option( 'safegrd_unsent' );
// What each backup held, as read for the restore picker.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_safegrd_contents' ) . '%', $wpdb->esc_like( '_transient_timeout_safegrd_contents' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own transients, one per backup looked at.
wp_clear_scheduled_hook( 'safegrd_scheduled_backup' );
wp_clear_scheduled_hook( 'safegrd_backup_now' );
wp_unschedule_hook( 'safegrd_continue' );
wp_clear_scheduled_hook( 'safegrd_expire_downloads' );
delete_option( 'safegrd_logs' );
delete_option( 'safegrd_update_labels' );
delete_option( 'safegrd_downloads' );
delete_option( 'safegrd_download_failed' );
// Downloads still on the server: decrypted copies of the site, under uploads/safegrd-downloads.
if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
}
$safegrd_fs        = new WP_Filesystem_Direct( null );
$safegrd_uploads   = wp_upload_dir( null, false );
$safegrd_downloads = rtrim( str_replace( '\\', '/', $safegrd_uploads['basedir'] ), '/' ) . '/safegrd-downloads';
foreach ( (array) glob( $safegrd_downloads . '/*', GLOB_ONLYDIR ) as $safegrd_dir ) {
	foreach ( (array) glob( $safegrd_dir . '/{,.}*', GLOB_BRACE ) as $safegrd_file ) {
		if ( is_file( $safegrd_file ) ) {
			wp_delete_file( $safegrd_file );
		}
	}
	$safegrd_fs->rmdir( $safegrd_dir );
}
foreach ( array( '/index.php', '/.htaccess' ) as $safegrd_file ) {
	if ( is_file( $safegrd_downloads . $safegrd_file ) ) {
		wp_delete_file( $safegrd_downloads . $safegrd_file );
	}
}
if ( is_dir( $safegrd_downloads ) ) {
	$safegrd_fs->rmdir( $safegrd_downloads );
}
