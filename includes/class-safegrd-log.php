<?php
/**
 * What each backup, restore and download printed, kept for the last runs so
 * the Tools page can show a run's log and support can read it. Lines are
 * buffered and written once per request: a run of many slices writes its
 * log once per slice, not once per line.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Log {
	const OPTION = 'safegrd_logs';
	/** Runs kept, newest first. */
	const RUNS = 20;
	/** Lines kept per run: the first and the last, with a gap between. */
	const LINES = 400;

	/** @var array run key => {kind, label, status, lines} added this request */
	private static $pending = array();
	private static $hooked  = false;

	/**
	 * Adds one line to a run's log.
	 *
	 * @param string $key   The run: a snapshot id, or restore-/download- and the job's id.
	 * @param string $kind  backup, restore or download.
	 * @param string $label What the run is of, as the page names it.
	 * @param string $line  One line, as the run printed it.
	 */
	public static function add( $key, $kind, $label, $line ) {
		if ( '' === (string) $key || '' === (string) $line ) {
			return;
		}
		if ( ! isset( self::$pending[ $key ] ) ) {
			self::$pending[ $key ] = array(
				'kind'  => $kind,
				'label' => $label,
				'first' => time(),
				'lines' => array(),
			);
		}
		self::$pending[ $key ]['lines'][] = gmdate( 'H:i:s' ) . ' ' . substr( (string) $line, 0, 500 );
		if ( ! self::$hooked ) {
			self::$hooked = true;
			register_shutdown_function( array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Marks how a run ended: completed, failed, restored or ready.
	 */
	public static function finish( $key, $kind, $label, $status ) {
		if ( '' === (string) $key ) {
			return;
		}
		if ( ! isset( self::$pending[ $key ] ) ) {
			self::$pending[ $key ] = array(
				'kind'  => $kind,
				'label' => $label,
				'lines' => array(),
			);
		}
		self::$pending[ $key ]['status'] = $status;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			register_shutdown_function( array( __CLASS__, 'flush' ) );
		}
	}

	/** Writes what this request added. */
	public static function flush() {
		if ( ! self::$pending ) {
			return;
		}
		$runs = self::all();
		foreach ( self::$pending as $key => $p ) {
			$r = isset( $runs[ $key ] ) ? $runs[ $key ] : array(
				'kind'    => $p['kind'],
				'label'   => $p['label'],
				'started' => isset( $p['first'] ) ? $p['first'] : time(),
				'status'  => 'running',
				'lines'   => array(),
			);
			unset( $runs[ $key ] );
			$r['updated'] = time();
			if ( isset( $p['status'] ) ) {
				$r['status'] = $p['status'];
			}
			$lines = array_merge( $r['lines'], $p['lines'] );
			if ( count( $lines ) > self::LINES ) {
				$head  = array_slice( $lines, 0, 50 );
				$tail  = array_slice( $lines, - ( self::LINES - 51 ) );
				$lines = array_merge( $head, array( sprintf( '... %d lines left out ...', count( $lines ) - 50 - count( $tail ) ) ), $tail );
			}
			$r['lines'] = $lines;
			// Newest first.
			$runs = array( $key => $r ) + $runs;
		}
		self::$pending = array();
		$runs          = array_slice( $runs, 0, self::RUNS, true );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $runs, '', 'no' );
		} else {
			update_option( self::OPTION, $runs, false );
		}
	}

	/**
	 * @return array run key => {kind, label, started, updated, status, lines}, newest first.
	 */
	public static function all() {
		$runs = get_option( self::OPTION, array() );
		return is_array( $runs ) ? $runs : array();
	}

	/**
	 * How a run ended. A run still marked running whose slices stopped
	 * coming an hour ago, with nothing under way, stopped without saying so.
	 */
	public static function status( array $r ) {
		if ( 'running' === $r['status'] && (int) ( $r['updated'] ?? $r['started'] ) < time() - HOUR_IN_SECONDS && ! SafeGrd_Restore::job() && ! SafeGrd_Backup::running() ) {
			return 'stopped';
		}
		return (string) $r['status'];
	}

	/**
	 * One run's log as text, or '' when it is not kept.
	 */
	public static function text( $key ) {
		self::flush();
		$runs = self::all();
		if ( ! isset( $runs[ $key ] ) ) {
			return '';
		}
		$r    = $runs[ $key ];
		$head = sprintf( "%s %s, %s, started %s UTC\n", ucfirst( $r['kind'] ), $r['label'], $r['status'], gmdate( 'Y-m-d H:i:s', (int) $r['started'] ) );
		return $head . implode( "\n", $r['lines'] ) . "\n";
	}
}
