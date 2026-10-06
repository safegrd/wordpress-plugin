<?php
/**
 * The plugin's stored state: one option, never autoloaded.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Settings {
	const OPTION = 'safegrd_settings';
	const LAST   = 'safegrd_last_run';

	/**
	 * Every option the plugin writes starts with this. The backup leaves
	 * these rows out of the dump, so a restored site does not come back
	 * holding this site's node token.
	 */
	const PREFIX = 'safegrd_';

	/**
	 * @return array
	 */
	public static function all() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function get( $key, $default = '' ) {
		$s = self::all();
		return isset( $s[ $key ] ) ? $s[ $key ] : $default;
	}

	public static function update( array $values ) {
		$s = array_merge( self::all(), $values );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $s, '', 'no' );
		} else {
			update_option( self::OPTION, $s, false );
		}
	}

	public static function connected() {
		return '' !== self::get( 'node_id' ) && '' !== self::get( 'node_token' );
	}

	public static function forget() {
		delete_option( self::OPTION );
		delete_option( self::LAST );
	}

	/**
	 * The remote server. The SAFEGRD_SERVER_URL constant in wp-config.php
	 * overrides the saved one, for a self-hosted server.
	 */
	public static function server_url() {
		if ( defined( 'SAFEGRD_SERVER_URL' ) && SAFEGRD_SERVER_URL ) {
			return rtrim( SAFEGRD_SERVER_URL, '/' );
		}
		$saved = self::get( 'server_url' );
		return rtrim( $saved ? $saved : 'https://safegrd.dev', '/' );
	}

	/**
	 * The last run, as the status page and `wp safegrd status` show it.
	 *
	 * @return array
	 */
	public static function last_run() {
		$r = get_option( self::LAST, array() );
		return is_array( $r ) ? $r : array();
	}

	public static function record_run( array $run ) {
		if ( false === get_option( self::LAST, false ) ) {
			add_option( self::LAST, $run, '', 'no' );
		} else {
			update_option( self::LAST, $run, false );
		}
	}
}
