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
delete_transient( 'safegrd_connect_session' );
wp_clear_scheduled_hook( 'safegrd_scheduled_backup' );
wp_clear_scheduled_hook( 'safegrd_backup_now' );
