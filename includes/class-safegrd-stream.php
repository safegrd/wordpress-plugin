<?php
/**
 * A file on this server written as a stream.
 *
 * The plugin writes files through WP_Filesystem_Direct::put_contents()
 * wherever the whole file fits in memory. Two files cannot be written
 * that way, and this class is the one place that opens a file handle for
 * writing:
 *
 * - A restored file larger than the memory a request may use. The backup
 *   holds it as 4 MB blobs, and the file is written one blob at a time
 *   into the staging directory under wp-content, so a 2 GB upload restores
 *   under a 40 MB memory limit.
 * - The download archive, under uploads/safegrd-downloads. It is appended
 *   one gzip member at a time over several requests, and put_contents()
 *   has no append.
 *
 * Every path is local and chosen by the plugin: nothing here opens a URL.
 * HTTP goes through wp_remote_request() in SafeGrd_Client and
 * SafeGrd_Reader.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Stream {

	/** @var resource */
	private $fh;

	/** @var string */
	private $path;

	private function __construct( $fh, $path ) {
		$this->fh   = $fh;
		$this->path = $path;
	}

	/** Creates the file, or empties it, for writing. */
	public static function create( $path ) {
		return self::open( $path, 'wb' );
	}

	/** Opens the file for appending, creating it when it is not there. */
	public static function append( $path ) {
		return self::open( $path, 'ab' );
	}

	private static function open( $path, $mode ) {
		$fh = fopen( $path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a local file the plugin named, larger than memory or appended to across requests: see the file header.
		if ( false === $fh ) {
			throw new SafeGrd_Exception( esc_html( 'Could not write ' . $path . '.' ), 'other' );
		}
		return new self( $fh, $path );
	}

	/**
	 * Cuts the file back to $length bytes: a request the host stopped part
	 * way through a write leaves bytes after the last whole file.
	 */
	public static function cut( $path, $length ) {
		$fh = fopen( $path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the download being written, a local file under uploads; put_contents() would rewrite the whole file.
		if ( false === $fh || ! ftruncate( $fh, $length ) ) {
			throw new SafeGrd_Exception( esc_html( 'Could not cut ' . $path . ' back to where the last request stopped.' ), 'other' );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Writes the whole string, or throws: fwrite() can write part of it, or
	 * nothing, when the disk is full or the host stops the request, and a
	 * file written short must never be swapped in or offered as a download.
	 */
	public function write( $data ) {
		$left = strlen( $data );
		$off  = 0;
		while ( $left > 0 ) {
			$n = @fwrite( $this->fh, $off ? substr( $data, $off ) : $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.PHP.NoSilencedErrors.Discouraged -- PHP raises a notice for a failed write, and the next line throws for it.
			if ( false === $n || 0 === $n ) {
				throw new SafeGrd_Exception( esc_html( 'Could not write ' . $this->path . ': the disk may be full.' ), 'other' );
			}
			$off  += $n;
			$left -= $n;
		}
	}

	/** Flushes what is buffered to disk and returns the file's length. */
	public function written() {
		if ( ! fflush( $this->fh ) ) {
			throw new SafeGrd_Exception( esc_html( 'Could not write ' . $this->path . ': the disk may be full.' ), 'other' );
		}
		return (int) fstat( $this->fh )['size'];
	}

	public function close() {
		fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
