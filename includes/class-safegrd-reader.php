<?php
/**
 * Reads a site's snapshot back from its repository: age decryption with one
 * X25519 identity, zstd frames of stored blocks, and blobs fetched by ranged
 * GETs on presigned URLs, each checked against its id before it is used.
 *
 * It reads what this plugin writes. The safegrd CLI reads every snapshot.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

/**
 * age v1 decryption with one X25519 identity, of an object held whole.
 */
final class SafeGrd_Age_Reader {
	const CHUNK = 65536;
	const TAG   = 16;

	/**
	 * @param string $ciphertext An age file.
	 * @param string $identity   AGE-SECRET-KEY-1...
	 * @return string The plaintext.
	 */
	public static function decrypt( $ciphertext, $identity ) {
		list( $hrp, $secret ) = SafeGrd_Bech32::decode( trim( $identity ) );
		if ( 'age-secret-key-' !== $hrp || 32 !== strlen( $secret ) ) {
			throw new SafeGrd_Exception( 'The key SafeGrd released is not an age X25519 identity.', 'other' );
		}
		$public = sodium_crypto_scalarmult_base( $secret );

		$intro = "age-encryption.org/v1\n";
		if ( 0 !== strpos( $ciphertext, $intro ) ) {
			throw new SafeGrd_Exception( 'An object of the backup is not an age file.', 'other' );
		}
		$pos      = strlen( $intro );
		$file_key = null;
		while ( true ) {
			$eol = strpos( $ciphertext, "\n", $pos );
			if ( false === $eol ) {
				throw new SafeGrd_Exception( 'An age header ends early.', 'other' );
			}
			$line = substr( $ciphertext, $pos, $eol - $pos );
			if ( 0 === strpos( $line, '---' ) ) {
				$mac_at = $pos + 3;
				$mac    = self::b64( substr( $line, 4 ) );
				$pos    = $eol + 1;
				break;
			}
			if ( 0 !== strpos( $line, '-> ' ) ) {
				throw new SafeGrd_Exception( 'An age header has a malformed stanza.', 'other' );
			}
			$args = explode( ' ', substr( $line, 3 ) );
			$pos  = $eol + 1;
			// The body: lines of 64 columns, the last one shorter.
			$body = '';
			while ( true ) {
				$eol = strpos( $ciphertext, "\n", $pos );
				if ( false === $eol ) {
					throw new SafeGrd_Exception( 'An age stanza ends early.', 'other' );
				}
				$bl    = substr( $ciphertext, $pos, $eol - $pos );
				$body .= $bl;
				$pos   = $eol + 1;
				if ( strlen( $bl ) < 64 ) {
					break;
				}
			}
			if ( null !== $file_key || 'X25519' !== $args[0] || 2 !== count( $args ) ) {
				continue;
			}
			$share  = self::b64( $args[1] );
			$shared = sodium_crypto_scalarmult( $secret, $share );
			$wrap   = hash_hkdf( 'sha256', $shared, 32, 'age-encryption.org/v1/X25519', $share . $public );
			$key    = sodium_crypto_aead_chacha20poly1305_ietf_decrypt( self::b64( $body ), '', str_repeat( "\0", 12 ), $wrap );
			if ( false !== $key ) {
				$file_key = $key;
			}
		}
		if ( null === $file_key ) {
			throw new SafeGrd_Exception( 'This backup is not sealed to the key SafeGrd released for it.', 'other' );
		}
		$expect = hash_hmac( 'sha256', substr( $ciphertext, 0, $mac_at ), hash_hkdf( 'sha256', $file_key, 32, 'header', '' ), true );
		if ( ! hash_equals( $expect, $mac ) ) {
			throw new SafeGrd_Exception( 'An age header does not authenticate: the object was altered.', 'other' );
		}
		$nonce   = substr( $ciphertext, $pos, 16 );
		$payload = substr( $ciphertext, $pos + 16 );
		$key     = hash_hkdf( 'sha256', $file_key, 32, 'payload', $nonce );
		$size    = self::CHUNK + self::TAG;
		$out     = '';
		$n       = strlen( $payload );
		$counter = 0;
		for ( $off = 0; ; $off += $size ) {
			$last  = $off + $size >= $n;
			$chunk = substr( $payload, $off, $size );
			$iv    = "\0\0\0" . pack( 'J', $counter ) . ( $last ? "\x01" : "\x00" );
			$plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt( $chunk, '', $iv, $key );
			if ( false === $plain ) {
				throw new SafeGrd_Exception( 'An age payload does not authenticate: the object was altered or cut short.', 'other' );
			}
			$out .= $plain;
			$counter++;
			if ( $last ) {
				return $out;
			}
		}
	}

	private static function b64( $s ) {
		$raw = base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		if ( false === $raw ) {
			throw new SafeGrd_Exception( 'An age header holds malformed base64.', 'other' );
		}
		return $raw;
	}
}

/**
 * Reads a zstd frame of stored and repeated blocks: the frames this plugin
 * writes. A compressed block is refused in words.
 */
final class SafeGrd_Zstd_Reader {
	public static function decode( $data ) {
		if ( "\x28\xb5\x2f\xfd" !== substr( $data, 0, 4 ) ) {
			throw new SafeGrd_Exception( 'An object of the backup is not a zstd frame.', 'other' );
		}
		$fhd    = ord( $data[4] );
		$pos    = 5;
		$single = ( $fhd >> 5 ) & 1;
		if ( ! $single ) {
			$pos++; // window descriptor
		}
		$pos     += array( 0, 1, 2, 4 )[ $fhd & 3 ];
		$fcs      = $fhd >> 6;
		$pos     += 0 === $fcs ? ( $single ? 1 : 0 ) : array( 0, 2, 4, 8 )[ $fcs ];
		$checksum = ( $fhd >> 2 ) & 1;
		$out      = '';
		while ( true ) {
			$h    = ord( $data[ $pos ] ) | ( ord( $data[ $pos + 1 ] ) << 8 ) | ( ord( $data[ $pos + 2 ] ) << 16 );
			$pos += 3;
			$last = $h & 1;
			$type = ( $h >> 1 ) & 3;
			$size = $h >> 3;
			if ( 0 === $type ) {
				$out .= substr( $data, $pos, $size );
				$pos += $size;
			} elseif ( 1 === $type ) {
				$out .= str_repeat( $data[ $pos ], $size );
				$pos++;
			} else {
				throw new SafeGrd_Exception( 'This backup was written by a newer client with compression the plugin cannot read. Restore it with the safegrd CLI.', 'other' );
			}
			if ( $last ) {
				break;
			}
		}
		unset( $checksum );
		return $out;
	}
}

/**
 * One epoch of a repository, read with one identity.
 */
final class SafeGrd_Repo_Reader {
	/** @var SafeGrd_Client */
	private $client;
	private $base;
	private $prefix;
	private $identity;
	/** @var array key => [url, expires] */
	private $urls = array();
	/** @var array pack id => key */
	private $pack_keys = array();
	/** @var array blob id => location */
	private $index = array();

	/**
	 * @param SafeGrd_Client $client   Node-token client.
	 * @param string         $node_id  This site's node.
	 * @param string         $prefix   The epoch's key prefix, as the server lists it.
	 * @param string         $identity The key the server released for the snapshot.
	 */
	public function __construct( SafeGrd_Client $client, $node_id, $prefix, $identity ) {
		$this->client   = $client;
		$this->base     = '/api/v1/nodes/' . rawurlencode( $node_id ) . '/hosted/repo';
		$this->prefix   = rtrim( $prefix, '/' );
		$this->identity = $identity;
	}

	/** Presigned GET URLs for keys, signed in batches and kept until they near expiry. */
	private function urls( array $keys ) {
		$want = array();
		foreach ( $keys as $k ) {
			if ( ! isset( $this->urls[ $k ] ) || $this->urls[ $k ][1] < time() + 60 ) {
				$want[] = $k;
			}
		}
		foreach ( array_chunk( $want, 200 ) as $batch ) {
			$r = $this->client->call( 'POST', $this->base . '/download', array( 'keys' => $batch ), 60 );
			if ( is_wp_error( $r ) ) {
				throw new SafeGrd_Exception( 'Hosted storage: ' . $r->get_error_message(), 'storage' );
			}
			foreach ( (array) ( $r['objects'] ?? array() ) as $o ) {
				$this->urls[ $o['key'] ] = array( $o['url'], strtotime( $o['expires_at'] ?? '' ) ?: time() + 600 );
			}
		}
	}

	private function get( $key, $from = null, $length = null ) {
		$this->urls( array( $key ) );
		if ( empty( $this->urls[ $key ] ) ) {
			throw new SafeGrd_Exception( 'Hosted storage does not hold ' . $key . '.', 'storage' );
		}
		$headers = array();
		if ( null !== $from ) {
			$headers['Range'] = 'bytes=' . $from . '-' . ( $from + $length - 1 );
		}
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( $attempt * 2 );
			}
			$resp = wp_remote_get(
				$this->urls[ $key ][0],
				SafeGrd_Client::request_args(
					array(
						'timeout' => 120,
						'headers' => $headers,
					)
				)
			);
			if ( is_wp_error( $resp ) ) {
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = wp_remote_retrieve_body( $resp );
			if ( ( 200 === $code && null === $from ) || ( 206 === $code && strlen( $body ) === $length ) ) {
				return $body;
			}
			if ( 200 === $code && null !== $from && strlen( $body ) >= $from + $length ) {
				return substr( $body, $from, $length ); // a store that ignores Range
			}
			if ( $code >= 400 && $code < 500 && 403 !== $code ) {
				break;
			}
		}
		throw new SafeGrd_Exception( 'Hosted storage: reading ' . $key . ' failed.', 'storage' );
	}

	/** An object sealed as age(zstd(JSON)), decoded. */
	public function object( $kind, $name ) {
		$key = $this->prefix . ( 'snapshot' === $kind ? '/snapshots/' . $name . '.age' : '/' . $kind . '/' . $name . '.age' );
		$v   = json_decode( SafeGrd_Zstd_Reader::decode( SafeGrd_Age_Reader::decrypt( $this->get( $key ), $this->identity ) ), true );
		if ( ! is_array( $v ) ) {
			throw new SafeGrd_Exception( 'The backup\'s ' . $kind . ' ' . $name . ' does not parse.', 'other' );
		}
		return $v;
	}

	/** Loads the index of every run a snapshot names. */
	public function load_index( array $snapshot ) {
		foreach ( $snapshot['runs'] as $run ) {
			$x = $this->object( 'index', $run );
			foreach ( $x['packs'] as $p ) {
				foreach ( $p['blobs'] as $b ) {
					$this->index[ $b['id'] ] = array( $p['pack_id'], (int) $b['offset'], (int) $b['length'], (int) $b['raw_length'], (int) $b['type'] );
				}
			}
		}
	}

	public function set_index( array $index ) {
		$this->index = $index;
	}

	public function index() {
		return $this->index;
	}

	private function pack_key( $pack ) {
		if ( isset( $this->pack_keys[ $pack ] ) ) {
			return $this->pack_keys[ $pack ];
		}
		$key  = $this->prefix . '/packs/' . $pack;
		$head = $this->get( $key, 0, 12 );
		if ( 'SGPK' !== substr( $head, 0, 4 ) || 1 !== ord( $head[4] ) ) {
			throw new SafeGrd_Exception( 'Pack ' . $pack . ' is not a pack this plugin reads.', 'other' );
		}
		$w       = unpack( 'V', substr( $head, 8, 4 ) )[1];
		$wrapped = $this->get( $key, 12, $w );
		$k       = SafeGrd_Age_Reader::decrypt( $wrapped, $this->identity );
		if ( 32 !== strlen( $k ) ) {
			throw new SafeGrd_Exception( 'Pack ' . $pack . ' has a malformed key.', 'other' );
		}
		$this->pack_keys[ $pack ] = $k;
		return $k;
	}

	/**
	 * One blob's plaintext, checked against its id.
	 */
	public function blob( $id ) {
		if ( ! isset( $this->index[ $id ] ) ) {
			throw new SafeGrd_Exception( 'Blob ' . $id . ' is in no index the snapshot names.', 'other' );
		}
		list( $pack, $offset, $length, $raw, $type ) = $this->index[ $id ];
		if ( $type & 0x80 ) {
			throw new SafeGrd_Exception( 'This backup was written by a newer client with compression the plugin cannot read. Restore it with the safegrd CLI.', 'other' );
		}
		$record = $this->get( $this->prefix . '/packs/' . $pack, $offset, $length );
		$plain  = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $record, 24 ), hex2bin( $id ) . chr( $type ), substr( $record, 0, 24 ), $this->pack_key( $pack ) );
		if ( false === $plain || strlen( $plain ) !== $raw || hash( 'sha256', $plain ) !== $id ) {
			throw new SafeGrd_Exception( 'Blob ' . $id . ' does not authenticate or does not match its id: the backup was altered.', 'other' );
		}
		return $plain;
	}

	/** A tree blob, decoded. */
	public function tree( $id ) {
		$t = json_decode( $this->blob( $id ), true );
		if ( ! is_array( $t ) || ! isset( $t['entries'] ) ) {
			throw new SafeGrd_Exception( 'Tree ' . $id . ' does not parse.', 'other' );
		}
		return $t['entries'];
	}

	/**
	 * Every file under a tree, depth first, as [path, entry].
	 */
	public function walk( $root, $prefix = '' ) {
		$out = array();
		foreach ( $this->tree( $root ) as $e ) {
			$name = isset( $e['name'] ) ? $e['name'] : base64_decode( $e['name_b64'] );
			$path = '' === $prefix ? $name : $prefix . '/' . $name;
			if ( 'dir' === $e['type'] ) {
				$out[] = array( $path, $e );
				foreach ( $this->walk( $e['subtree'], $path ) as $c ) {
					$out[] = $c;
				}
			} elseif ( 'file' === $e['type'] ) {
				$out[] = array( $path, $e );
			}
		}
		return $out;
	}
}
