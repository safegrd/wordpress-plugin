<?php
/**
 * Backs up the site before WordPress updates a plugin, a theme or core, when
 * the operator turned it on under Tools, SafeGrd, Settings.
 *
 * The hook is upgrader_pre_download: it fires before anything is downloaded
 * or replaced, for plugins, themes and core alike, from the Updates screen,
 * the Plugins screen, WP-CLI and background auto-updates. upgrader_pre_install
 * is not used because the core updater never calls it.
 *
 * A backup runs in slices, and an update is one request, so the update
 * waits at most WAIT seconds for it (no limit from the command line, where
 * PHP has none). A backup not done by then finishes in the background while
 * the update goes ahead. A failed backup never stops the update: holding a
 * site on a plugin with a known hole is worse than updating without a fresh
 * copy.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Updates {
	/** A backup that finished this recently is the restore point; no new one. */
	const FRESH = HOUR_IN_SECONDS;
	/** Seconds an update in a web request waits for its backup. */
	const WAIT = 20;
	/** Snapshot id => what it was taken before, newest first. */
	const LABELS = 'safegrd_update_labels';
	/** Labels kept. */
	const KEEP = 50;

	/**
	 * The snapshot this request's updates start from, once decided: a
	 * backup's id, or '' when there is none. Null until the first update.
	 *
	 * @var string|null
	 */
	private static $snapshot = null;

	public static function init() {
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'before_download' ), 10, 4 );
	}

	/** Whether the operator turned it on. Off until they do. */
	public static function enabled() {
		return (bool) SafeGrd_Settings::get( 'before_updates', false );
	}

	/**
	 * The filter. Returns $reply as it came: the update goes ahead whatever
	 * happened to the backup.
	 *
	 * @param mixed       $reply      False, or what another plugin answered for the download.
	 * @param string      $package    The package URL.
	 * @param WP_Upgrader $upgrader   The upgrader running.
	 * @param array       $hook_extra Which plugin or theme; empty for core.
	 */
	public static function before_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $reply ) || ! self::enabled() ) {
			return $reply;
		}
		$item = self::describe( (string) $package, $upgrader, is_array( $hook_extra ) ? $hook_extra : array() );
		if ( '' === $item ) {
			return $reply;
		}
		// A bulk update, or core trying a second package, asks again in the
		// same request: one backup covers all of it.
		if ( null === self::$snapshot ) {
			self::$snapshot = self::backup( $item, $upgrader );
		} elseif ( '' !== self::$snapshot ) {
			self::add_label( self::$snapshot, $item );
		}
		return $reply;
	}

	/**
	 * What is being updated, as "Akismet 5.3 to 5.3.1", or '' for anything
	 * that is not an update of a plugin, a theme or core: an install, a
	 * translation, or this plugin itself.
	 */
	private static function describe( $package, $upgrader, array $hook_extra ) {
		if ( class_exists( 'Core_Upgrader' ) && $upgrader instanceof Core_Upgrader ) {
			$to = preg_match( '#/wordpress-(\d+(?:\.\d+)+)#', $package, $m ) ? $m[1] : '';
			return trim( 'WordPress ' . get_bloginfo( 'version' ) . ( '' === $to ? '' : ' to ' . $to ) );
		}
		if ( isset( $hook_extra['action'] ) && 'update' !== $hook_extra['action'] ) {
			return '';
		}
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$file = (string) $hook_extra['plugin'];
			if ( plugin_basename( SAFEGRD_FILE ) === $file ) {
				return '';
			}
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all  = get_plugins();
			$name = isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : dirname( $file );
			$from = isset( $all[ $file ]['Version'] ) ? $all[ $file ]['Version'] : '';
			$u    = get_site_transient( 'update_plugins' );
			$to   = is_object( $u ) && isset( $u->response[ $file ]->new_version ) ? $u->response[ $file ]->new_version : '';
			return self::from_to( $name, $from, $to );
		}
		if ( ! empty( $hook_extra['theme'] ) ) {
			$slug  = (string) $hook_extra['theme'];
			$theme = wp_get_theme( $slug );
			$u     = get_site_transient( 'update_themes' );
			$to    = is_object( $u ) && isset( $u->response[ $slug ]['new_version'] ) ? $u->response[ $slug ]['new_version'] : '';
			return self::from_to( $theme->exists() ? $theme->get( 'Name' ) : $slug, $theme->exists() ? $theme->get( 'Version' ) : '', $to );
		}
		return '';
	}

	private static function from_to( $name, $from, $to ) {
		$s = trim( $name . ' ' . $from );
		return '' === (string) $to ? $s : $s . ' to ' . $to;
	}

	/**
	 * Takes the backup this update starts from, or finds the one that
	 * already is, and says which in the update's output.
	 *
	 * @return string The snapshot the update starts from, or ''.
	 */
	private static function backup( $item, $upgrader ) {
		if ( ! SafeGrd_Settings::connected() || '' !== SafeGrd_Site::refusal() ) {
			return '';
		}
		if ( SafeGrd_Restore::job() ) {
			self::say( $upgrader, 'SafeGrd: a restore is under way, so the update goes ahead without a backup first.' );
			return '';
		}
		if ( SafeGrd_Backup::running() ) {
			self::say( $upgrader, 'SafeGrd: a backup is already running. The update goes ahead without waiting for it.' );
			return '';
		}
		$last = SafeGrd_Settings::last_run();
		$t    = 'completed' === ( $last['status'] ?? '' ) ? strtotime( (string) ( $last['finished_at'] ?? '' ) ) : 0;
		if ( $t && time() - $t < self::FRESH && ! empty( $last['snapshot_id'] ) ) {
			self::add_label( $last['snapshot_id'], $item );
			self::say( $upgrader, sprintf( 'SafeGrd: backed up %s ago (%s), so no new backup before this update.', human_time_diff( $t ), $last['snapshot_id'] ) );
			return $last['snapshot_id'];
		}

		self::say( $upgrader, 'SafeGrd: backing up before the update.' );
		update_option( SafeGrd_Backup::REQUESTED, time(), false );
		$cli   = 'cli' === PHP_SAPI;
		$until = microtime( true ) + self::WAIT;
		$run   = array( 'status' => 'idle' );
		do {
			$budget = SafeGrd_Backup::default_budget();
			if ( ! $cli ) {
				$left   = $until - microtime( true );
				$budget = 0.0 === (float) $budget ? $left : min( $budget, $left );
				$budget = max( 1.0, $budget );
			}
			$job        = new SafeGrd_Backup( null, $budget );
			$job->chain = false;
			$job->label = 'Before update: ' . $item;
			$run        = $job->run( false );
		} while ( 'running' === ( $run['status'] ?? '' ) && ( $cli || microtime( true ) < $until ) );

		switch ( $run['status'] ?? '' ) {
			case 'completed':
				self::say( $upgrader, sprintf( 'SafeGrd: backed up %s in %ds. The update goes ahead.', $run['snapshot_id'], (int) $run['seconds'] ) );
				return (string) $run['snapshot_id'];
			case 'failed':
				self::say( $upgrader, 'SafeGrd: the backup before this update failed: ' . rtrim( (string) ( $run['message'] ?? '' ), '.' ) . '. The update goes ahead. Tools, SafeGrd has the log.' );
				return '';
			case 'running':
				SafeGrd_Scheduler::continue_now();
				self::say( $upgrader, sprintf( 'SafeGrd: the backup did not finish in %d seconds. The update goes ahead, and the backup finishes after it: files it had not read yet are backed up as updated.', self::WAIT ) );
				return '';
		}
		self::say( $upgrader, 'SafeGrd: ' . rtrim( (string) ( $run['message'] ?? 'no backup started' ), '.' ) . '. The update goes ahead.' );
		return '';
	}

	/** One line in the update's output: the screen, WP-CLI, or the auto-update email. */
	private static function say( $upgrader, $line ) {
		if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
			$upgrader->skin->feedback( esc_html( $line ) );
		}
	}

	/**
	 * Notes that $item was updated after $snapshot_id was taken, for Recent
	 * backups. The server does not keep it.
	 */
	public static function add_label( $snapshot_id, $item ) {
		$labels = self::labels();
		$label  = isset( $labels[ $snapshot_id ] ) ? $labels[ $snapshot_id ] : 'Before update: ';
		$prefix = 'Before update: ';
		if ( 0 !== strpos( $label, $prefix ) ) {
			$label = $prefix;
		}
		$items = '' === substr( $label, strlen( $prefix ) ) ? array() : explode( '; ', substr( $label, strlen( $prefix ) ) );
		if ( ! in_array( $item, $items, true ) ) {
			$items[] = $item;
		}
		self::set_label( $snapshot_id, $prefix . implode( '; ', $items ) );
	}

	/** Records a snapshot's label as a whole, as a backup that finished wrote it. */
	public static function set_label( $snapshot_id, $label ) {
		if ( '' === (string) $snapshot_id || '' === (string) $label ) {
			return;
		}
		$labels = self::labels();
		unset( $labels[ $snapshot_id ] );
		$labels = array_slice( array( $snapshot_id => substr( (string) $label, 0, 500 ) ) + $labels, 0, self::KEEP, true );
		if ( false === get_option( self::LABELS, false ) ) {
			add_option( self::LABELS, $labels, '', 'no' );
		} else {
			update_option( self::LABELS, $labels, false );
		}
	}

	/**
	 * @return array snapshot id => label.
	 */
	public static function labels() {
		$l = get_option( self::LABELS, array() );
		return is_array( $l ) ? $l : array();
	}

	/** One snapshot's label, or ''. */
	public static function label( $snapshot_id ) {
		$l = self::labels();
		return isset( $l[ $snapshot_id ] ) ? (string) $l[ $snapshot_id ] : '';
	}
}
