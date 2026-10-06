<?php
/**
 * When backups run: once a day through WP-Cron, and on demand.
 *
 * WP-Cron runs its events in a request of their own (a loopback to
 * wp-cron.php, or the server's cron when DISABLE_WP_CRON is set), so a
 * backup started here does not hold up a visitor's page.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Scheduler {
	const HOOK     = 'safegrd_scheduled_backup';
	const NOW_HOOK = 'safegrd_backup_now';
	/** The schedule the server is told this site runs. */
	const SCHEDULE = '@daily';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::NOW_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedules the daily backup, due at once: WP-Cron runs due events on the
	 * next request to the site, which after connecting from wp-admin is the
	 * Tools page reloading.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::NOW_HOOK );
	}

	/**
	 * Queues one backup now and asks WP-Cron to start it.
	 *
	 * @return bool Whether it was queued.
	 */
	public static function backup_now() {
		if ( SafeGrd_Backup::running() || wp_next_scheduled( self::NOW_HOOK ) ) {
			return false;
		}
		wp_schedule_single_event( time(), self::NOW_HOOK );
		spawn_cron();
		return true;
	}

	public static function next_run() {
		$t = wp_next_scheduled( self::HOOK );
		return $t ? (int) $t : 0;
	}

	public static function run() {
		if ( ! SafeGrd_Settings::connected() ) {
			return;
		}
		( new SafeGrd_Backup() )->run();
	}
}
