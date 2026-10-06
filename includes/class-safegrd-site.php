<?php
/**
 * What the plugin needs to know about the site it runs on, and what it
 * refuses to back up.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Site {
	/**
	 * Plugins that move media to object storage, so the files are not on
	 * this host. The plugin file of each.
	 */
	const OFFLOAD_PLUGINS = array(
		'amazon-s3-and-cloudfront/wordpress-s3.php'         => 'WP Offload Media',
		'amazon-s3-and-cloudfront-pro/amazon-s3-and-cloudfront-pro.php' => 'WP Offload Media',
		'wp-stateless/wp-stateless-media.php'                => 'WP-Stateless',
		'ilab-media-tools/ilab-media-tools.php'              => 'Media Cloud',
		'media-cloud/ilab-media-tools.php'                   => 'Media Cloud',
		'wp-offload-media-lite/wordpress-s3.php'             => 'WP Offload Media Lite',
	);

	/**
	 * Why this site cannot be backed up by this version, in words, or ''.
	 */
	public static function refusal() {
		if ( is_multisite() ) {
			return 'This is a multisite network. The plugin backs up single sites only for now, so nothing on this network is backed up.';
		}
		if ( ! function_exists( 'sodium_crypto_scalarmult' ) || ! function_exists( 'sodium_crypto_aead_chacha20poly1305_ietf_encrypt' ) ) {
			return 'PHP on this host has no sodium extension, which the plugin encrypts with. PHP 7.2 and newer include it; ask your host to enable it.';
		}
		if ( ! function_exists( 'deflate_init' ) ) {
			return 'PHP on this host has no zlib extension, which the plugin compresses with. Ask your host to enable it.';
		}
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( self::OFFLOAD_PLUGINS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				return sprintf( '%s is active, so this site\'s media is in object storage rather than on this host. The plugin cannot back up files it cannot read, so it backs up nothing rather than a site without its media.', $name );
			}
		}
		return '';
	}

	/**
	 * A name for the site in the console: its title, or its host.
	 */
	public static function name() {
		$name = trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $name ) {
			return (string) $host;
		}
		return $host ? $name . ' (' . $host . ')' : $name;
	}

	/**
	 * The site root: where WordPress is installed.
	 */
	public static function root() {
		return rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
	}

	/**
	 * wp-config.php, which may sit one directory above the root.
	 */
	public static function config_file() {
		$root = self::root();
		if ( is_file( $root . '/wp-config.php' ) ) {
			return $root . '/wp-config.php';
		}
		$up = dirname( $root ) . '/wp-config.php';
		if ( is_file( $up ) && ! is_file( dirname( $root ) . '/wp-settings.php' ) ) {
			return $up;
		}
		return '';
	}

	/**
	 * A path relative to the root, or '' when it is outside it.
	 */
	public static function relative( $path ) {
		$root = self::root() . '/';
		$path = str_replace( '\\', '/', $path );
		if ( 0 === strpos( $path . '/', $root ) || $path . '/' === $root ) {
			return trim( substr( $path, strlen( $root ) ), '/' );
		}
		return '';
	}

	/**
	 * The uploads directory relative to the root, as attachments are
	 * checked against it.
	 */
	public static function uploads_path() {
		$base    = self::uploads_dir();
		$content = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		if ( 0 === strpos( $base . '/', $content . '/' ) ) {
			return trim( self::content_path() . '/' . ltrim( substr( $base, strlen( $content ) ), '/' ), '/' );
		}
		$rel = self::relative( $base );
		return '' !== $rel ? $rel : 'wp-content/uploads';
	}

	/**
	 * The uploads directory on disk.
	 */
	public static function uploads_dir() {
		$up = wp_upload_dir( null, false );
		return rtrim( str_replace( '\\', '/', $up['basedir'] ), '/' );
	}

	/**
	 * The content directory relative to the root.
	 */
	public static function content_path() {
		$rel = self::relative( rtrim( WP_CONTENT_DIR, '/' ) );
		return '' !== $rel ? $rel : 'wp-content';
	}
}
