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
			return 'This is a multisite network. The plugin backs up single sites, so nothing on this network is backed up.';
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
				return sprintf( '%s is active and keeps this site\'s media in object storage, where the plugin cannot read it. A backup without the media would not restore the site, so nothing is backed up.', $name );
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
	/** The parts of a site a download or a restore can take on its own. */
	const COMPONENTS = array( 'database', 'plugins', 'themes', 'uploads', 'others' );

	/**
	 * The part of the site a path in a backup belongs to: plugins, themes
	 * and uploads are those directories of the content directory, others is
	 * the rest of it and wp-config.php and .htaccess. The database's dump is
	 * none of them: ''.
	 *
	 * @param string $path    The path in the backup, files/...
	 * @param string $content The content directory's path in the backup, wp-content normally.
	 * @param string $uploads The uploads directory's path in the backup.
	 */
	public static function component( $path, $content, $uploads ) {
		if ( 0 !== strpos( $path, 'files/' ) ) {
			return '';
		}
		$rel = substr( $path, 6 );
		if ( 0 === strpos( $rel, $uploads . '/' ) ) {
			return 'uploads';
		}
		if ( 0 === strpos( $rel, $content . '/plugins/' ) ) {
			return 'plugins';
		}
		if ( 0 === strpos( $rel, $content . '/themes/' ) ) {
			return 'themes';
		}
		return 'others';
	}

	/**
	 * The exclusions set on the Tools page, checked: one pattern per line.
	 * A pattern with a slash is a path from the site root, wp-content/...,
	 * and leaves out everything under it. One without is a file or directory
	 * name, * and ? allowed, left out anywhere but under uploads.
	 *
	 * @param string $text What was typed.
	 * @return array|WP_Error The patterns, or why one is refused.
	 */
	public static function check_exclusions( $text ) {
		$content = self::content_path();
		$uploads = self::uploads_path();
		$out     = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$p = trim( str_replace( '\\', '/', $line ) );
			if ( '' === $p || '#' === $p[0] ) {
				continue;
			}
			$p = rtrim( $p, '/' );
			if ( false !== strpos( $p, '..' ) || '/' === $p[0] || preg_match( '/[\x00-\x1f]/', $p ) ) {
				return new WP_Error( 'safegrd_exclude', sprintf( '"%s" is not a path inside the site. Write a path from the site root, such as %s/cache-old, or a name such as *.zip.', $p, $content ) );
			}
			if ( false !== strpos( $p, '/' ) ) {
				if ( 0 !== strpos( $p . '/', $content . '/' ) || $p === $content ) {
					return new WP_Error( 'safegrd_exclude', sprintf( '"%s" is outside %s. Only files under %s can be left out.', $p, $content, $content ) );
				}
				if ( 0 === strpos( $p . '/', $uploads . '/' ) || 0 === strpos( $uploads . '/', $p . '/' ) ) {
					return new WP_Error( 'safegrd_exclude', sprintf( '"%s" is in %s. The test restore checks that every attachment the database names is in the backup, so uploads stay in it.', $p, $uploads ) );
				}
			}
			$out[] = $p;
		}
		if ( count( $out ) > 50 ) {
			return new WP_Error( 'safegrd_exclude', 'At most 50 patterns.' );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The exclusion pattern a path matches, or ''.
	 *
	 * @param string $rel      The path from the site root, wp-content/...
	 * @param array  $patterns From check_exclusions().
	 */
	public static function excluded_by( $rel, array $patterns ) {
		$uploads = self::uploads_path() . '/';
		foreach ( $patterns as $p ) {
			if ( false !== strpos( $p, '/' ) ) {
				if ( $rel === $p || fnmatch( $p, $rel, FNM_PATHNAME ) ) {
					return $p;
				}
			} elseif ( 0 !== strpos( $rel, $uploads ) && fnmatch( $p, basename( $rel ) ) ) {
				return $p;
			}
		}
		return '';
	}

	public static function content_path() {
		$rel = self::relative( rtrim( WP_CONTENT_DIR, '/' ) );
		return '' !== $rel ? $rel : 'wp-content';
	}
}
