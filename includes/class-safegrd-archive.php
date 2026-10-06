<?php
/**
 * The backup's byte path: a tar stream, hashed, gzipped, encrypted with age
 * and handed to the uploader, with nothing written to disk.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plaintext tar to ciphertext. Counts and hashes both sides, as the
 * snapshot record states them: the digest of the tar before compression,
 * and the digest of what was stored.
 */
final class SafeGrd_Sealed_Stream {
	const FLUSH_AT = 1048576;

	/** @var resource|HashContext */
	private $raw_hash;
	/** @var resource|HashContext */
	private $enc_hash;
	private $raw_bytes = 0;
	private $enc_bytes = 0;
	/** @var resource|DeflateContext */
	private $gzip;
	/** @var SafeGrd_Age_Writer */
	private $age;
	/** @var callable */
	private $sink;
	private $pending = '';

	/**
	 * @param string   $recipient age1... recipient.
	 * @param callable $sink      Receives ciphertext.
	 */
	public function __construct( $recipient, callable $sink ) {
		$this->sink     = $sink;
		$this->raw_hash = hash_init( 'sha256' );
		$this->enc_hash = hash_init( 'sha256' );
		$this->gzip     = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 6 ) );
		$enc            = function ( $bytes ) {
			if ( '' === $bytes ) {
				return;
			}
			hash_update( $this->enc_hash, $bytes );
			$this->enc_bytes += strlen( $bytes );
			call_user_func( $this->sink, $bytes );
		};
		$this->age      = new SafeGrd_Age_Writer( $recipient, $enc );
	}

	public function write( $bytes ) {
		hash_update( $this->raw_hash, $bytes );
		$this->raw_bytes += strlen( $bytes );
		$this->pending   .= $bytes;
		if ( strlen( $this->pending ) >= self::FLUSH_AT ) {
			$this->flush_pending();
		}
	}

	private function flush_pending() {
		if ( '' === $this->pending ) {
			return;
		}
		$z             = deflate_add( $this->gzip, $this->pending, ZLIB_NO_FLUSH );
		$this->pending = '';
		if ( false === $z ) {
			throw new RuntimeException( 'gzip failed' );
		}
		if ( '' !== $z ) {
			$this->age->write( $z );
		}
	}

	/**
	 * Ends the gzip member and the age stream.
	 *
	 * @return array{raw_bytes:int,raw_sha256:string,encrypted_bytes:int,encrypted_sha256:string}
	 */
	public function finish() {
		$this->flush_pending();
		$z = deflate_add( $this->gzip, '', ZLIB_FINISH );
		if ( false === $z ) {
			throw new RuntimeException( 'gzip failed to finish' );
		}
		$this->age->write( $z );
		$this->age->finish();
		return array(
			'raw_bytes'        => $this->raw_bytes,
			'raw_sha256'       => hash_final( $this->raw_hash ),
			'encrypted_bytes'  => $this->enc_bytes,
			'encrypted_sha256' => hash_final( $this->enc_hash ),
		);
	}
}

/**
 * A POSIX tar writer. Long names go in a PAX header.
 */
final class SafeGrd_Tar {
	/** @var callable */
	private $out;

	public function __construct( callable $out ) {
		$this->out = $out;
	}

	/**
	 * Writes one whole entry from a string.
	 */
	public function add_bytes( $name, $bytes, $mtime = null ) {
		$this->begin( $name, strlen( $bytes ), 0644, null === $mtime ? time() : $mtime );
		call_user_func( $this->out, $bytes );
		$this->pad( strlen( $bytes ) );
	}

	/**
	 * Starts an entry whose body the caller writes with body(), then end().
	 */
	public function begin( $name, $size, $mode, $mtime ) {
		if ( strlen( $name ) > 100 || $size > 077777777777 || preg_match( '/[^\x20-\x7e]/', $name ) ) {
			$records = $this->pax_record( 'path', $name );
			if ( $size > 077777777777 ) {
				$records .= $this->pax_record( 'size', (string) $size );
			}
			$pax_name = 'PaxHeaders/' . substr( preg_replace( '/[^A-Za-z0-9._-]/', '_', basename( $name ) ), 0, 80 );
			call_user_func( $this->out, $this->header( $pax_name, strlen( $records ), 0644, $mtime, 'x' ) );
			call_user_func( $this->out, $records );
			$this->pad( strlen( $records ) );
			$short = substr( preg_replace( '/[^\x20-\x7e]/', '_', $name ), -100 );
			call_user_func( $this->out, $this->header( $short, min( $size, 077777777777 ), $mode, $mtime, '0' ) );
			return;
		}
		call_user_func( $this->out, $this->header( $name, $size, $mode, $mtime, '0' ) );
	}

	public function body( $bytes ) {
		call_user_func( $this->out, $bytes );
	}

	public function end( $size ) {
		$this->pad( $size );
	}

	/**
	 * The two zero blocks that end an archive.
	 */
	public function close() {
		call_user_func( $this->out, str_repeat( "\0", 1024 ) );
	}

	private function pad( $size ) {
		$rem = $size % 512;
		if ( $rem ) {
			call_user_func( $this->out, str_repeat( "\0", 512 - $rem ) );
		}
	}

	private function pax_record( $key, $value ) {
		$body = ' ' . $key . '=' . $value . "\n";
		$len  = strlen( $body ) + 1;
		while ( strlen( $len . $body ) !== $len ) {
			$len = strlen( $len . $body );
		}
		return $len . $body;
	}

	private function header( $name, $size, $mode, $mtime, $type ) {
		$h  = str_pad( substr( $name, 0, 100 ), 100, "\0" );
		$h .= sprintf( '%07o', $mode & 07777 ) . "\0";
		$h .= sprintf( '%07o', 0 ) . "\0";
		$h .= sprintf( '%07o', 0 ) . "\0";
		$h .= sprintf( '%011o', $size ) . "\0";
		$h .= sprintf( '%011o', max( 0, (int) $mtime ) ) . "\0";
		$h .= '        ';
		$h .= $type;
		$h .= str_repeat( "\0", 100 );
		$h .= "ustar\0" . '00';
		$h .= str_repeat( "\0", 32 + 32 + 8 + 8 + 155 + 12 );
		$sum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $h[ $i ] );
		}
		return substr( $h, 0, 148 ) . sprintf( '%06o', $sum ) . "\0 " . substr( $h, 156 );
	}
}

/**
 * Uploads a stream to SafeGrd's hosted storage as a multipart upload. The
 * server signs one URL per part, for its exact length, and sets the lock.
 */
final class SafeGrd_Uploader {
	const MIN_PART = 5242880;
	const MAX_PART = 8388608;

	/** @var SafeGrd_Client */
	private $client;
	private $node_id;
	private $upload_id;
	private $part_size;
	private $part = 0;
	private $buffer = '';
	private $sent = 0;
	private $retain_until = '';
	private $name;

	/**
	 * Creates the upload. The server builds the object's key and clamps the
	 * lock to the plan.
	 *
	 * @param SafeGrd_Client $client       Node-token client.
	 * @param string         $node_id      This site's node.
	 * @param string         $name         <snapshot>.safegrd or <snapshot>.meta.json.
	 * @param string         $retain_until RFC 3339 date asked for.
	 */
	public function __construct( SafeGrd_Client $client, $node_id, $name, $retain_until ) {
		$this->client  = $client;
		$this->node_id = $node_id;
		$this->name    = $name;
		$up            = $client->call(
			'POST',
			'/api/v1/nodes/' . rawurlencode( $node_id ) . '/hosted/uploads',
			array(
				'node_id'      => $node_id,
				'name'         => $name,
				'retain_until' => $retain_until,
			)
		);
		if ( is_wp_error( $up ) ) {
			throw new SafeGrd_Exception( 'Hosted storage refused the upload: ' . $up->get_error_message(), 'storage' );
		}
		if ( empty( $up['upload_id'] ) || empty( $up['part_size'] ) ) {
			throw new SafeGrd_Exception( 'Hosted storage gave no upload id or part size.', 'storage' );
		}
		$this->upload_id    = $up['upload_id'];
		$this->retain_until = isset( $up['retain_until'] ) ? $up['retain_until'] : '';
		// Smaller than the server's part size keeps memory low on shared
		// hosting; every part but the last must be at least 5 MiB.
		$this->part_size = max( self::MIN_PART, min( (int) $up['part_size'], self::MAX_PART ) );
	}

	public function retain_until() {
		return $this->retain_until;
	}

	public function write( $bytes ) {
		$this->buffer .= $bytes;
		while ( strlen( $this->buffer ) >= $this->part_size ) {
			$this->put( substr( $this->buffer, 0, $this->part_size ) );
			$this->buffer = (string) substr( $this->buffer, $this->part_size );
		}
	}

	/**
	 * Sends the last part and completes the upload.
	 *
	 * @return array{storage_uri:string,bytes:int}
	 */
	public function finish() {
		if ( '' !== $this->buffer || 0 === $this->part ) {
			$this->put( $this->buffer );
			$this->buffer = '';
		}
		$done = $this->client->call( 'POST', $this->base() . '/complete', null, 120 );
		if ( is_wp_error( $done ) ) {
			throw new SafeGrd_Exception( 'Hosted storage could not complete the upload of ' . $this->name . ': ' . $done->get_error_message(), 'storage' );
		}
		$bytes = isset( $done['bytes'] ) ? (int) $done['bytes'] : -1;
		if ( $bytes !== $this->sent ) {
			throw new SafeGrd_Exception( sprintf( 'Hosted storage holds %d bytes of %s; %d were sent.', $bytes, $this->name, $this->sent ), 'storage' );
		}
		return array(
			'storage_uri' => isset( $done['storage_uri'] ) ? $done['storage_uri'] : '',
			'bytes'       => $bytes,
		);
	}

	/**
	 * Abandons the upload, so nothing half-written is kept or billed.
	 */
	public function abort() {
		$r = $this->client->call( 'POST', $this->base() . '/abort', null, 30 );
		return is_wp_error( $r ) ? $r->get_error_message() : '';
	}

	private function base() {
		return '/api/v1/nodes/' . rawurlencode( $this->node_id ) . '/hosted/uploads/' . rawurlencode( $this->upload_id );
	}

	private function put( $data ) {
		if ( '' === $data ) {
			throw new SafeGrd_Exception( 'Hosted storage: ' . $this->name . ' is empty.', 'storage' );
		}
		$this->part++;
		$last = null;
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( $attempt * $attempt );
			}
			$grant = $this->client->call(
				'POST',
				$this->base() . '/parts',
				array(
					'parts' => array(
						array(
							'part_number' => $this->part,
							'size'        => strlen( $data ),
						),
					),
				)
			);
			if ( is_wp_error( $grant ) ) {
				$status = (int) ( $grant->get_error_data()['status'] ?? 0 );
				if ( ( $status >= 400 && $status < 500 ) || 507 === $status ) {
					throw new SafeGrd_Exception( 'Hosted storage refused part ' . $this->part . ': ' . $grant->get_error_message(), 'storage' );
				}
				$last = $grant->get_error_message();
				continue;
			}
			if ( empty( $grant['parts'][0]['url'] ) ) {
				throw new SafeGrd_Exception( 'Hosted storage granted no URL for part ' . $this->part . '.', 'storage' );
			}
			$ok = SafeGrd_Client::put( $grant['parts'][0]['url'], $data );
			if ( true === $ok ) {
				$this->sent += strlen( $data );
				return;
			}
			$last   = $ok->get_error_message();
			$status = (int) ( $ok->get_error_data()['status'] ?? 0 );
			if ( $status >= 400 && $status < 500 && 403 !== $status && 408 !== $status ) {
				break; // a 403 may be an expired URL, worth a fresh one; other 4xx will not change
			}
		}
		throw new SafeGrd_Exception( sprintf( 'Hosted storage: part %d of %s failed: %s', $this->part, $this->name, $last ), 'storage' );
	}
}

/**
 * A backup failure, with the reason code the server records.
 */
final class SafeGrd_Exception extends RuntimeException {
	/** @var string */
	public $reason;

	public function __construct( $message, $reason = '' ) {
		parent::__construct( $message );
		$this->reason = $reason;
	}
}
