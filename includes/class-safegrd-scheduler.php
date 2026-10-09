<?php
/**
 * When backups run: once a day or once a week through WP-Cron, and on demand.
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
	/** How often backups may run, and the schedule the server is told for each. */
	const FREQUENCIES = array(
		'daily'  => '@daily',
		'weekly' => '@weekly',
	);

	const SLICE_ACTION = 'safegrd_slice';
	const SLICE_KEY    = 'safegrd_slice_key';
	const PING_ACTION  = 'safegrd_ping';
	/** The last loopback check: '' when the site reached itself, else why not. */
	const LOOPBACK = 'safegrd_loopback';

	public static function init() {
		add_action( 'wp_ajax_nopriv_' . self::SLICE_ACTION, array( __CLASS__, 'slice_request' ) );
		add_action( 'wp_ajax_' . self::SLICE_ACTION, array( __CLASS__, 'slice_request' ) );
		add_action( 'wp_ajax_nopriv_' . self::PING_ACTION, array( __CLASS__, 'ping' ) );
		add_action( 'wp_ajax_' . self::PING_ACTION, array( __CLASS__, 'ping' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::NOW_HOOK, array( __CLASS__, 'run_requested' ) );
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'run_requested' ) );
		add_action( 'safegrd_expire_downloads', array( 'SafeGrd_Restore', 'downloads' ) );
	}

	/** How often this site backs up: daily (the default) or weekly. */
	public static function frequency() {
		$f = SafeGrd_Settings::get( 'frequency', 'daily' );
		return isset( self::FREQUENCIES[ $f ] ) ? $f : 'daily';
	}

	/** The schedule the server is told, as the console reads it. */
	public static function schedule_expr() {
		return self::FREQUENCIES[ self::frequency() ];
	}

	private static function interval() {
		return 'weekly' === self::frequency() ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
	}

	/**
	 * Schedules the backup, due at once: WP-Cron runs due events on the
	 * next request to the site, which after connecting from wp-admin is the
	 * Tools page reloading.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::frequency(), self::HOOK );
		}
	}

	/**
	 * The hour of the day backups start at, in the site's timezone, or -1
	 * for whenever the last one did.
	 */
	public static function hour() {
		$h = SafeGrd_Settings::get( 'hour', -1 );
		return is_numeric( $h ) && (int) $h >= 0 && (int) $h <= 23 ? (int) $h : -1;
	}

	/**
	 * Sets how often backups run, and at what hour. The next is due one
	 * interval after the last backup, or at once when that has passed; with
	 * an hour, at the first time that hour comes round from then.
	 *
	 * @param string   $frequency daily or weekly.
	 * @param int|null $hour      0 to 23 in the site's timezone, -1 for any, null to keep it.
	 * @return bool False for a frequency or an hour the plugin does not offer.
	 */
	public static function set_frequency( $frequency, $hour = null ) {
		if ( ! isset( self::FREQUENCIES[ $frequency ] ) || ( null !== $hour && ( (int) $hour < -1 || (int) $hour > 23 ) ) ) {
			return false;
		}
		$values = array( 'frequency' => $frequency );
		if ( null !== $hour ) {
			$values['hour'] = (int) $hour;
		}
		SafeGrd_Settings::update( $values );
		$last = SafeGrd_Settings::last_run();
		self::schedule_from( empty( $last['started_at'] ) ? time() : strtotime( $last['started_at'] ) + self::interval() );
		return true;
	}

	/**
	 * After a restore: the site's WP-Cron is the backup's, whose backup was
	 * already due, and whose schedule may not be this site's. The next
	 * backup is one interval out, on this site's frequency.
	 */
	public static function reschedule_after_restore() {
		wp_clear_scheduled_hook( self::NOW_HOOK );
		self::schedule_from( time() + self::interval() );
	}

	/**
	 * Schedules the recurring backup from $at, moved to the chosen hour.
	 * WP-Cron then repeats it at that time of day.
	 */
	private static function schedule_from( $at ) {
		$at = max( time(), (int) $at );
		$h  = self::hour();
		if ( $h >= 0 ) {
			$day  = new DateTimeImmutable( '@' . $at );
			$day  = $day->setTimezone( wp_timezone() );
			$slot = $day->setTime( $h, 0 );
			if ( $slot->getTimestamp() < $at ) {
				$slot = $slot->modify( '+1 day' )->setTime( $h, 0 );
			}
			$at = $slot->getTimestamp();
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( $at, self::frequency(), self::HOOK );
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
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for loopback requests.
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

	/** The loopback check's answer. */
	public static function ping() {
		wp_die( 'safegrd-ok', '', array( 'response' => 200 ) );
	}

	/**
	 * Why this site cannot reach itself, or '' when it can. WP-Cron and the
	 * backup's slices both start with a request from the site to itself,
	 * so on a host that blocks it no scheduled backup runs. Checked with
	 * one request and remembered for 12 hours, or 10 minutes after a
	 * failure.
	 *
	 * @param bool $fresh Check again instead of using the last answer.
	 */
	public static function loopback_problem( $fresh = false ) {
		$cached = get_transient( self::LOOPBACK );
		if ( ! $fresh && false !== $cached ) {
			return (string) $cached;
		}
		$url  = admin_url( 'admin-ajax.php' );
		$resp = wp_remote_post(
			$url,
			array(
				'timeout'   => 10,
				'body'      => array( 'action' => self::PING_ACTION ),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for loopback requests.
			)
		);
		if ( is_wp_error( $resp ) ) {
			$problem = sprintf( 'This site cannot reach itself at %s: %s.', $url, rtrim( $resp->get_error_message(), '.' ) );
		} elseif ( 'safegrd-ok' !== trim( wp_remote_retrieve_body( $resp ) ) ) {
			$problem = sprintf( 'This site reached %s but got HTTP %d instead of the plugin\'s answer. A security plugin, firewall or host rule may block requests to admin-ajax.php.', $url, (int) wp_remote_retrieve_response_code( $resp ) );
		} else {
			$problem = '';
		}
		set_transient( self::LOOPBACK, $problem, '' === $problem ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		return $problem;
	}

	/**
	 * What to do about a site that cannot reach itself.
	 *
	 * @param bool $page Whether it is said on the Tools page, which runs
	 *                   a backup or restore itself while it is open.
	 */
	public static function loopback_remedy( $page = false ) {
		$s = sprintf(
			'Scheduled backups do not run until it can. Run WP-Cron from the server\'s cron instead (*/15 * * * * cd %s && wp cron event run --due-now), or add define( \'ALTERNATE_WP_CRON\', true ); to wp-config.php.',
			untrailingslashit( ABSPATH )
		);
		return $page ? $s . ' While this page is open, it runs a backup or restore itself.' : $s;
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
		if ( SafeGrd_Restore::job() ) {
			( new SafeGrd_Restore() )->run();
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
		if ( SafeGrd_Restore::job() ) {
			( new SafeGrd_Restore() )->run();
			return;
		}
		( new SafeGrd_Backup() )->run( false );
	}
}
