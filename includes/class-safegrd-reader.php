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
			throw new SafeGrd_Exception( esc_html( 'The key SafeGrd released is not an age X25519 identity.' ), 'other' );
		}
		$public = sodium_crypto_scalarmult_base( $secret );

		$intro = "age-encryption.org/v1\n";
		if ( 0 !== strpos( $ciphertext, $intro ) ) {
			throw new SafeGrd_Exception( esc_html( 'An object of the backup is not an age file.' ), 'other' );
		}
		$pos      = strlen( $intro );
		$file_key = null;
		while ( true ) {
			$eol = strpos( $ciphertext, "\n", $pos );
			if ( false === $eol ) {
				throw new SafeGrd_Exception( esc_html( 'An age header ends early.' ), 'other' );
			}
			$line = substr( $ciphertext, $pos, $eol - $pos );
			if ( 0 === strpos( $line, '---' ) ) {
				$mac_at = $pos + 3;
				$mac    = self::b64( substr( $line, 4 ) );
				$pos    = $eol + 1;
				break;
			}
			if ( 0 !== strpos( $line, '-> ' ) ) {
				throw new SafeGrd_Exception( esc_html( 'An age header has a malformed stanza.' ), 'other' );
			}
			$args = explode( ' ', substr( $line, 3 ) );
			$pos  = $eol + 1;
			// The body: lines of 64 columns, the last one shorter.
			$body = '';
			while ( true ) {
				$eol = strpos( $ciphertext, "\n", $pos );
				if ( false === $eol ) {
					throw new SafeGrd_Exception( esc_html( 'An age stanza ends early.' ), 'other' );
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
			throw new SafeGrd_Exception( esc_html( 'This backup is not sealed to the key SafeGrd released for it.' ), 'other' );
		}
		$expect = hash_hmac( 'sha256', substr( $ciphertext, 0, $mac_at ), hash_hkdf( 'sha256', $file_key, 32, 'header', '' ), true );
		if ( ! hash_equals( $expect, $mac ) ) {
			throw new SafeGrd_Exception( esc_html( 'An age header does not authenticate: the object was altered.' ), 'other' );
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
				throw new SafeGrd_Exception( esc_html( 'An age payload does not authenticate: the object was altered or cut short.' ), 'other' );
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
			throw new SafeGrd_Exception( esc_html( 'An age header holds malformed base64.' ), 'other' );
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
			throw new SafeGrd_Exception( esc_html( 'An object of the backup is not a zstd frame.' ), 'other' );
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
				throw new SafeGrd_Exception( esc_html( 'This backup was written by a newer client with compression the plugin cannot read. Restore it with the safegrd CLI.' ), 'other' );
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
	/** @var array blob id => its sealed record, fetched ahead by prefetch() */
	private $records = array();

	/** Neighbouring blobs closer than this are fetched in one request. */
	const PREFETCH_GAP = 262144;
	/** Bytes from a pack's start read in one request with its header, for a record that ends within them. */
	const HEAD_SPAN = 1048576;
	/** No one prefetch request reads more than this. */
	const PREFETCH_SPAN = 16777216;
	/** Bytes of blobs read ahead of being asked for, at most. */
	const SPARE_BYTES = 33554432;


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
				throw new SafeGrd_Exception( esc_html( 'Storage: ' . $r->get_error_message() ), 'storage' );
			}
			foreach ( (array) ( $r['objects'] ?? array() ) as $o ) {
				$this->urls[ $o['key'] ] = array( $o['url'], strtotime( $o['expires_at'] ?? '' ) ?: time() + 600 );
			}
		}
	}

	private function get( $key, $from = null, $length = null ) {
		$this->urls( array( $key ) );
		if ( empty( $this->urls[ $key ] ) ) {
			throw new SafeGrd_Exception( esc_html( 'Hosted storage does not hold ' . $key . '.' ), 'storage' );
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
			if ( 403 === $code ) {
				// The link expired while the slice waited, or the clock here
				// runs ahead of storage's: sign it again before the next try.
				unset( $this->urls[ $key ] );
				$this->urls( array( $key ) );
				if ( empty( $this->urls[ $key ] ) ) {
					break;
				}
				continue;
			}
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
		throw new SafeGrd_Exception( esc_html( 'Hosted storage: could not read ' . $key . '.' ), 'storage' );
	}

	/** An object sealed as age(zstd(JSON)), decoded. */
	public function object( $kind, $name ) {
		$key = $this->prefix . ( 'snapshot' === $kind ? '/snapshots/' . $name . '.age' : '/' . $kind . '/' . $name . '.age' );
		$v   = json_decode( SafeGrd_Zstd_Reader::decode( SafeGrd_Age_Reader::decrypt( $this->get( $key ), $this->identity ) ), true );
		if ( ! is_array( $v ) ) {
			throw new SafeGrd_Exception( esc_html( 'The backup\'s ' . $kind . ' ' . $name . ' does not parse.' ), 'other' );
		}
		return $v;
	}

	/**
	 * Loads the index of every run a snapshot names, or of some of them.
	 * Their URLs are signed in one request first.
	 *
	 * @param array      $snapshot The snapshot object.
	 * @param array|null $runs     Only these runs; null for all the snapshot names.
	 */
	public function load_index( array $snapshot, $runs = null ) {
		$runs = null === $runs ? $snapshot['runs'] : array_values( array_intersect( $snapshot['runs'], $runs ) );
		$this->urls(
			array_map(
				function ( $run ) {
					return $this->prefix . '/index/' . $run . '.age';
				},
				$runs
			)
		);
		foreach ( $runs as $run ) {
			$x = $this->object( 'index', $run );
			foreach ( $x['packs'] as $p ) {
				foreach ( $p['blobs'] as $b ) {
					$this->index[ $b['id'] ] = array( $p['pack_id'], (int) $b['offset'], (int) $b['length'], (int) $b['raw_length'], (int) $b['type'] );
				}
			}
		}
		$this->heads      = null;
		$this->packs      = null;
		$this->all_signed = false;
	}

	/** @var array|null Where each pack's first record begins: the length of its header. */
	private $heads = null;

	/** @var bool Whether every pack of the index was signed once already. */
	private $all_signed = false;

	/** @var array|null Each pack's blobs, as [id, offset, length], in the order they sit in it. */
	private $packs = null;

	/** @var array Blobs read ahead of being asked for, by id, with their length. */
	private $spare = array();

	/** @var int The bytes of $spare. */
	private $spare_bytes = 0;

	/** The blobs of a pack, in their order in it. */
	private function blobs_of( $pack ) {
		if ( null === $this->packs ) {
			$this->packs = array();
			foreach ( $this->index as $id => $loc ) {
				$this->packs[ $loc[0] ][] = array( (string) $id, $loc[1], $loc[2] );
			}
			foreach ( $this->packs as &$list ) {
				usort(
					$list,
					function ( $a, $b ) {
						return $a[1] - $b[1];
					}
				);
			}
			unset( $list );
		}
		return $this->packs[ $pack ] ?? array();
	}

	public function set_index( array $index ) {
		$this->index      = $index;
		$this->heads      = null;
		$this->packs      = null;
		$this->all_signed = false;
	}

	public function index() {
		return $this->index;
	}

	/**
	 * A pack's key, unwrapped with the identity.
	 *
	 * @param string      $pack The pack's id.
	 * @param string|null $head The pack's first bytes, when they were read already.
	 */
	private function pack_key( $pack, $head = null ) {
		if ( isset( $this->pack_keys[ $pack ] ) ) {
			return $this->pack_keys[ $pack ];
		}
		$key = $this->prefix . '/packs/' . $pack;
		if ( null === $head ) {
			// The header and the wrapped key in one read: a wrapped key is a
			// few hundred bytes.
			$head = $this->get_head( $pack, $key );
		}
		if ( 'SGPK' !== substr( $head, 0, 4 ) || 1 !== ord( $head[4] ) ) {
			throw new SafeGrd_Exception( esc_html( 'Pack ' . $pack . ' is not a pack this plugin reads.' ), 'other' );
		}
		$w       = unpack( 'V', substr( $head, 8, 4 ) )[1];
		$wrapped = strlen( $head ) >= 12 + $w ? substr( $head, 12, $w ) : $this->get( $key, 12, $w );
		$k       = SafeGrd_Age_Reader::decrypt( $wrapped, $this->identity );
		if ( 32 !== strlen( $k ) ) {
			throw new SafeGrd_Exception( esc_html( 'Pack ' . $pack . ' has a malformed key.' ), 'other' );
		}
		$this->pack_keys[ $pack ] = $k;
		return $k;
	}

	/**
	 * Fetches the sealed records of these blobs ahead of blob(), with one
	 * ranged GET per run of neighbours in a pack instead of one per blob:
	 * a site's small files sit side by side in its packs, and each GET is a
	 * round trip to storage. blob() still checks every record against its
	 * id; a blob prefetch did not reach is fetched on its own.
	 *
	 * The runs are fetched in the order $ids first needs them, and none is
	 * started after $deadline (a microtime, 0 for none): where the next
	 * files are spread over many packs, fetching all of them would take the
	 * whole slice and leave it no time to write them.
	 */
	public function prefetch( array $ids, $deadline = 0 ) {
		$by_pack = array();
		$order   = array();
		foreach ( array_unique( $ids ) as $i => $id ) {
			if ( isset( $this->index[ $id ] ) && ! isset( $this->records[ $id ] ) ) {
				$by_pack[ $this->index[ $id ][0] ][] = $id;
				$order[ $id ]                       = $i;
			}
		}
		$spans         = array();
		$pending       = array();
		$pending_bytes = 0;
		foreach ( $by_pack as $pack => $blobs ) {
			usort(
				$blobs,
				function ( $a, $b ) {
					return $this->index[ $a ][1] - $this->index[ $b ][1];
				}
			);
			// The rest of the pack after the last blob asked for comes in
			// the same read: files are restored in the order they were
			// stored, so the next files' blobs follow these, and reading
			// them now saves reading the pack again. Kept until used, up
			// to SPARE_BYTES.
			$last = $this->index[ end( $blobs ) ][1];
			foreach ( $this->blobs_of( $pack ) as $b ) {
				if ( $b[1] <= $last || isset( $this->records[ $b[0] ] ) || isset( $order[ $b[0] ] ) ) {
					continue;
				}
				if ( $this->spare_bytes + $pending_bytes + $b[2] > self::SPARE_BYTES ) {
					break;
				}
				$blobs[]          = $b[0];
				$order[ $b[0] ]   = PHP_INT_MAX;
				$pending[ $b[0] ] = $b[2];
				$pending_bytes   += $b[2];
			}
			$span = array();
			foreach ( $blobs as $id ) {
				$off = $this->index[ $id ][1];
				$end = $off + $this->index[ $id ][2];
				if ( $span && ( $off - $span['end'] > self::PREFETCH_GAP || $end - $span['from'] > self::PREFETCH_SPAN ) ) {
					$spans[] = $span;
					$span    = array();
				}
				if ( ! $span ) {
					$span = array(
						'pack'  => $pack,
						'from'  => $off,
						'end'   => $end,
						'ids'   => array(),
						'first' => $order[ $id ],
					);
				}
				$span['end']   = max( $span['end'], $end );
				$span['ids'][] = $id;
				$span['first'] = min( $span['first'], $order[ $id ] );
			}
			if ( $span ) {
				$spans[] = $span;
			}
		}
		usort(
			$spans,
			function ( $a, $b ) {
				return $a['first'] <=> $b['first'];
			}
		);
		// A pack whose key is not known yet is read from its start, so its
		// header comes with the blobs, when they begin near it. And every
		// pack's link is signed in one request rather than one each.
		$started = array();
		$keys    = array();
		foreach ( $spans as &$span ) {
			if ( ! isset( $started[ $span['pack'] ] ) && ! isset( $this->pack_keys[ $span['pack'] ] ) && $span['from'] <= self::PREFETCH_GAP ) {
				$span['from'] = 0;
			}
			$started[ $span['pack'] ] = true;
			$keys[]                   = $this->prefix . '/packs/' . $span['pack'];
		}
		unset( $span );
		// With them, every other pack of the index not signed yet: links
		// are signed 200 to a request, so a slice asks once or twice.
		if ( ! $this->all_signed ) {
			$all = array();
			foreach ( $this->index as $loc ) {
				$all[ $loc[0] ] = true;
			}
			foreach ( array_keys( $all ) as $pack ) {
				$keys[] = $this->prefix . '/packs/' . $pack;
			}
			$this->all_signed = true;
		}
		$this->urls( array_values( array_unique( $keys ) ) );
		foreach ( $spans as $span ) {
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				return;
			}
			$this->fetch_span( $span['pack'], $span );
			foreach ( $span['ids'] as $id ) {
				if ( isset( $pending[ $id ] ) ) {
					$this->spare[ $id ] = $pending[ $id ];
					$this->spare_bytes += $pending[ $id ];
				}
			}
		}
	}

	/**
	 * Fetches every tree blob the index lists, ahead of walk(): a backup
	 * writes its trees together, so they come in a few requests instead of
	 * one per directory.
	 */
	public function prefetch_trees() {
		$ids = array();
		foreach ( $this->index as $id => $loc ) {
			if ( SafeGrd_Repo_Format::TREE === ( $loc[4] & 0x7f ) ) {
				$ids[] = $id;
			}
		}
		$this->prefetch( $ids );
	}

	/**
	 * A pack's header and wrapped key, which end where its first record
	 * begins: one read, rather than the header and then the key.
	 */
	private function get_head( $pack, $key ) {
		if ( null === $this->heads ) {
			$this->heads = array();
			foreach ( $this->index as $loc ) {
				if ( ! isset( $this->heads[ $loc[0] ] ) || $loc[1] < $this->heads[ $loc[0] ] ) {
					$this->heads[ $loc[0] ] = $loc[1];
				}
			}
		}
		return $this->get( $key, 0, isset( $this->heads[ $pack ] ) && $this->heads[ $pack ] > 12 ? $this->heads[ $pack ] : 12 );
	}

	private function fetch_span( $pack, array $span ) {
		$bytes = $this->get( $this->prefix . '/packs/' . $pack, $span['from'], $span['end'] - $span['from'] );
		if ( 0 === $span['from'] && ! isset( $this->pack_keys[ $pack ] ) ) {
			$this->pack_key( $pack, $bytes );
		}
		foreach ( $span['ids'] as $id ) {
			$this->records[ $id ] = substr( $bytes, $this->index[ $id ][1] - $span['from'], $this->index[ $id ][2] );
		}
	}

	/**
	 * One blob's plaintext, checked against its id.
	 */
	public function blob( $id ) {
		if ( ! isset( $this->index[ $id ] ) ) {
			throw new SafeGrd_Exception( esc_html( 'Blob ' . $id . ' is in no index the snapshot names.' ), 'other' );
		}
		list( $pack, $offset, $length, $raw, $type ) = $this->index[ $id ];
		if ( $type & 0x80 ) {
			throw new SafeGrd_Exception( esc_html( 'This backup was written by a newer client with compression the plugin cannot read. Restore it with the safegrd CLI.' ), 'other' );
		}
		if ( isset( $this->records[ $id ] ) ) {
			$record = $this->records[ $id ];
			unset( $this->records[ $id ] );
			if ( isset( $this->spare[ $id ] ) ) {
				$this->spare_bytes -= $this->spare[ $id ];
				unset( $this->spare[ $id ] );
			}
		} elseif ( ! isset( $this->pack_keys[ $pack ] ) && $offset + $length <= self::HEAD_SPAN ) {
			// The pack's key is in its header, before every record: a record
			// near the start comes with it in one read instead of three.
			$bytes  = $this->get( $this->prefix . '/packs/' . $pack, 0, $offset + $length );
			$record = substr( $bytes, $offset, $length );
			$this->pack_key( $pack, $bytes );
		} else {
			$record = $this->get( $this->prefix . '/packs/' . $pack, $offset, $length );
		}
		$plain  = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $record, 24 ), hex2bin( $id ) . chr( $type ), substr( $record, 0, 24 ), $this->pack_key( $pack ) );
		if ( false === $plain || strlen( $plain ) !== $raw || hash( 'sha256', $plain ) !== $id ) {
			throw new SafeGrd_Exception( esc_html( 'Blob ' . $id . ' does not authenticate or does not match its id: the backup was altered.' ), 'other' );
		}
		return $plain;
	}

	/** A tree blob, decoded. */
	public function tree( $id ) {
		$t = json_decode( $this->blob( $id ), true );
		if ( ! is_array( $t ) || ! isset( $t['entries'] ) ) {
			throw new SafeGrd_Exception( esc_html( 'Tree ' . $id . ' does not parse.' ), 'other' );
		}
		return $t['entries'];
	}

	/** Whether a name from a tree is one entry of a directory, nothing more. */
	public static function safe_name( $name ) {
		return '' !== $name && '.' !== $name && '..' !== $name && false === strpos( $name, '/' ) && false === strpos( $name, "\0" );
	}

	/**
	 * Every file under a tree, depth first, as [path, entry].
	 */
	public function walk( $root, $prefix = '' ) {
		$out = array();
		foreach ( $this->tree( $root ) as $e ) {
			$name = isset( $e['name'] ) ? (string) $e['name'] : (string) base64_decode( (string) ( $e['name_b64'] ?? '' ), true );
			if ( ! self::safe_name( $name ) ) {
				// A restore writes each path under its staging directory and a
				// download names it in a tar: "..", a slash or a NUL in a name
				// would reach outside either.
				throw new SafeGrd_Exception( esc_html( sprintf( 'The backup names a file "%s" under %s, which is not a file name. Nothing was restored.', substr( $name, 0, 80 ), '' === $prefix ? 'its root' : $prefix ) ), 'other' );
			}
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
