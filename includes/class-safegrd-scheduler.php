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
	/** The next slice of a backup under way. */
	const CONTINUE_HOOK = 'safegrd_continue';
	/** The schedule the server is told this site runs. */
	const SCHEDULE = '@daily';

	const SLICE_ACTION = 'safegrd_slice';
	const SLICE_KEY    = 'safegrd_slice_key';

	public static function init() {
		add_action( 'wp_ajax_nopriv_' . self::SLICE_ACTION, array( __CLASS__, 'slice_request' ) );
		add_action( 'wp_ajax_' . self::SLICE_ACTION, array( __CLASS__, 'slice_request' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::NOW_HOOK, array( __CLASS__, 'run_requested' ) );
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'run_requested' ) );
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
		self::continue_cancel();
	}

	/**
	 * Starts the next slice at once, in a request of its own: a loopback to
	 * admin-ajax.php that does not wait for an answer. A WP-Cron event a
	 * minute out stands behind it, for a host that blocks loopbacks.
	 */
	public static function continue_now() {
		self::continue_later( MINUTE_IN_SECONDS );
		self::kick();
	}

	/**
	 * Fires the loopback that runs one slice. The key is this site's own,
	 * so nobody else can make it run backups.
	 */
	public static function kick() {
		$key = get_option( self::SLICE_KEY, '' );
		if ( '' === $key ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_option( self::SLICE_KEY, $key, false );
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'blocking'  => false,
				'timeout'   => 0.01,
				'body'      => array(
					'action' => self::SLICE_ACTION,
					'key'    => $key,
				),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/** The loopback's handler: one slice, when the key is this site's. */
	public static function slice_request() {
		$key  = get_option( self::SLICE_KEY, '' );
		$sent = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a loopback, authenticated by the site's own key.
		if ( '' === $key || ! hash_equals( $key, $sent ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		self::run_requested();
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/** Asks for a slice after a while: a retry, or a watchdog. */
	public static function continue_later( $seconds ) {
		self::continue_cancel();
		wp_schedule_single_event( time() + (int) $seconds, self::CONTINUE_HOOK, array( microtime( true ) ) );
	}

	public static function continue_cancel() {
		wp_unschedule_hook( self::CONTINUE_HOOK );
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
		update_option( SafeGrd_Backup::REQUESTED, time(), false );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::NOW_HOOK );
		self::kick();
		return true;
	}

	public static function next_run() {
		$t = wp_next_scheduled( self::HOOK );
		return $t ? (int) $t : 0;
	}

	/**
	 * The daily run: one slice of the backup under way, or the first of a
	 * new one.
	 */
	public static function run() {
		if ( ! SafeGrd_Settings::connected() ) {
			return;
		}
		( new SafeGrd_Backup() )->run( true );
	}

	/**
	 * A continuation: one slice of the backup under way, or the first of the
	 * one Back up now asked for. Never a backup nobody asked for.
	 */
	public static function run_requested() {
		if ( ! SafeGrd_Settings::connected() ) {
			return;
		}
		( new SafeGrd_Backup() )->run( false );
	}
}
