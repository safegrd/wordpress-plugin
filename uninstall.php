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
delete_option( 'safegrd_repo' );
delete_option( 'safegrd_cache_version' );
delete_option( 'safegrd_slice_key' );
delete_option( 'safegrd_backup_requested' );
delete_transient( 'safegrd_plans' );
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'safegrd_files' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'safegrd_blobs' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
delete_transient( 'safegrd_connect_session' );
wp_clear_scheduled_hook( 'safegrd_scheduled_backup' );
wp_clear_scheduled_hook( 'safegrd_backup_now' );
wp_unschedule_hook( 'safegrd_continue' );
