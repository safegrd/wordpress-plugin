<?php
/**
 * The incremental repository a site backs up into: one epoch a month, packs
 * of encrypted blobs, trees, and a snapshot per run. Each run uploads only
 * the blobs the epoch does not hold yet, so an unchanged file or table costs
 * nothing.
 *
 * Layout of a run (paths from the snapshot's root):
 *
 *   mysql/dump.sql, mysql/dump.sql.1, ...  the dump: a header, one part per table, a footer
 *   files/<path>                           wp-config.php, .htaccess and the content directory
 *   manifest.json                          the snapshot's metadata
 *
 * Files are cut into chunks of 4 MiB, the last one shorter. The epoch says so,
 * and readers never re-cut a file.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

/**
 * A zstd frame of stored (uncompressed) blocks. The repository seals its
 * JSON objects as age(zstd(JSON)), and PHP has no zstd; a frame of stored
 * blocks is valid zstd that every decoder reads.
 */
final class SafeGrd_Zstd {
	const BLOCK = 131072;

	public static function frame( $data ) {
		$len = strlen( $data );
		// Single segment, an 8-byte content size, no checksum.
		$out = "\x28\xb5\x2f\xfd" . chr( 0xE0 ) . pack( 'P', $len );
		if ( 0 === $len ) {
			return $out . self::block_header( 0, true );
		}
		for ( $off = 0; $off < $len; $off += self::BLOCK ) {
			$chunk = substr( $data, $off, self::BLOCK );
			$out  .= self::block_header( strlen( $chunk ), $off + self::BLOCK >= $len ) . $chunk;
		}
		return $out;
	}

	private static function block_header( $size, $last ) {
		$h = ( $size << 3 ) | ( $last ? 1 : 0 ); // block type 0: raw
		return chr( $h & 0xff ) . chr( ( $h >> 8 ) & 0xff ) . chr( ( $h >> 16 ) & 0xff );
	}
}

/**
 * Encodings of the repository format.
 */
final class SafeGrd_Repo_Format {
	const CHUNK      = 4194304;
	const PACK       = 8388608;
	const DATA       = 0;
	const TREE       = 1;
	const DIR_MODE   = 493; // 0755
	const FIXED_TIME = '1970-01-01T00:00:00Z';

	/** The chunker the epoch declares: fixed 4 MiB chunks. */
	public static function chunker() {
		return array(
			'algorithm' => 'fixed',
			'min'       => 1,
			'avg'       => self::CHUNK,
			'max'       => self::CHUNK,
		);
	}

	/**
	 * JSON as the format writes it: no escaped slashes or Unicode, no
	 * whitespace. Only trees depend on the exact bytes, which this keeps
	 * stable from run to run.
	 */
	public static function json( $v ) {
		$s = wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $s ) {
			throw new SafeGrd_Exception( 'Could not encode the repository JSON: ' . json_last_error_msg(), 'other' );
		}
		return $s;
	}

	/**
	 * age(zstd(JSON)), the form of an index, a catalog and a snapshot.
	 */
	public static function seal_object( $v, $recipient ) {
		$out = '';
		$age = new SafeGrd_Age_Writer(
			$recipient,
			function ( $b ) use ( &$out ) {
				$out .= $b;
			}
		);
		$age->write( SafeGrd_Zstd::frame( self::json( $v ) ) );
		$age->finish();
		return $out;
	}

	public static function rfc3339( $unix ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) $unix );
	}

	/**
	 * A name or path as a JSON string, or null when its bytes are not UTF-8
	 * (then it is written base64 under a _b64 key).
	 */
	public static function utf8( $s ) {
		return ( '' === $s || preg_match( '//u', $s ) ) ? $s : null;
	}

	/**
	 * One tree blob's plaintext. Entries are sorted by the raw bytes of
	 * their names, keys in the format's order.
	 *
	 * @param array $entries name => entry: ['type' => 'dir', 'subtree' => id] or
	 *                       ['type' => 'file', 'mode', 'mtime', 'size', 'sha256', 'content' => ids].
	 */
	public static function tree( array $entries ) {
		$names = array_map( 'strval', array_keys( $entries ) );
		usort( $names, 'strcmp' );
		$out = array();
		foreach ( $names as $name ) {
			$e = $entries[ $name ];
			$n = array();
			$u = self::utf8( $name );
			if ( null === $u ) {
				$n['name_b64'] = base64_encode( $name );
			} else {
				$n['name'] = $name;
			}
			if ( 'dir' === $e['type'] ) {
				$n['type']    = 'dir';
				$n['mode']    = self::DIR_MODE;
				$n['mtime']   = self::FIXED_TIME;
				$n['subtree'] = $e['subtree'];
			} else {
				$n['type']    = 'file';
				$n['mode']    = (int) $e['mode'];
				$n['mtime']   = $e['mtime'];
				$n['size']    = (int) $e['size'];
				$n['sha256']  = $e['sha256'];
				$n['content'] = array_values( $e['content'] );
			}
			$out[] = $n;
		}
		return self::json( array( 'entries' => $out ) );
	}

	/**
	 * The content root of a set of entries: the SHA-256 over one line per
	 * entry, "<type> <value> <path>", sorted by the raw bytes of the path.
	 *
	 * @param array $lines path => array( type 'd'|'f', value ).
	 */
	public static function content_root( array $lines ) {
		$paths = array_map( 'strval', array_keys( $lines ) );
		usort( $paths, 'strcmp' );
		$h = hash_init( 'sha256' );
		foreach ( $paths as $p ) {
			list( $type, $value ) = $lines[ $p ];
			hash_update( $h, $type . ' ' . $value . ' ' . self::escape_path( $p ) . "\n" );
		}
		return hash_final( $h );
	}

	private static function escape_path( $p ) {
		$out = '';
		$len = strlen( $p );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = ord( $p[ $i ] );
			$out .= ( $c < 0x21 || $c > 0x7e || 0x25 === $c ) ? sprintf( '%%%02X', $c ) : $p[ $i ];
		}
		return $out;
	}
}

/**
 * A pack being filled: blobs of one type, each sealed with XChaCha20-Poly1305
 * under the pack's own key, which is wrapped to the recipient in the header
 * and then forgotten.
 */
final class SafeGrd_Pack {
	public $id;
	public $type;
	/** @var array blob entries: id, type, offset, length, raw_length */
	public $blobs = array();
	private $key;
	private $body;

	public function __construct( $type, $recipient ) {
		$this->type = $type;
		$this->id   = bin2hex( random_bytes( 16 ) );
		$this->key  = random_bytes( 32 );
		$wrapped    = '';
		$age        = new SafeGrd_Age_Writer(
			$recipient,
			function ( $b ) use ( &$wrapped ) {
				$wrapped .= $b;
			}
		);
		$age->write( $this->key );
		$age->finish();
		$this->body = 'SGPK' . chr( 1 ) . "\0\0\0" . pack( 'V', strlen( $wrapped ) ) . $wrapped;
	}

	public function size() {
		return strlen( $this->body );
	}

	public function add( $id_hex, $plaintext ) {
		$nonce  = random_bytes( 24 );
		$ad     = hex2bin( $id_hex ) . chr( $this->type );
		$record = $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $ad, $nonce, $this->key );
		$this->blobs[] = array(
			'id'         => $id_hex,
			'type'       => $this->type,
			'offset'     => strlen( $this->body ),
			'length'     => strlen( $record ),
			'raw_length' => strlen( $plaintext ),
		);
		$this->body .= $record;
	}

	/**
	 * The finished pack: records, the sealed trailer listing them, and the
	 * footer. The pack key is wiped.
	 */
	public function finish() {
		$trailer = SafeGrd_Repo_Format::json( array( 'blobs' => $this->blobs ) );
		$nonce   = random_bytes( 24 );
		$ad      = 'SGPK-trailer' . hex2bin( $this->id );
		$record  = $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $trailer, $ad, $nonce, $this->key );
		$this->body .= $record . pack( 'V', strlen( $record ) ) . 'SGPK';
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $this->key );
		}
		$this->key = null;
		$body       = $this->body;
		$this->body = '';
		return $body;
	}
}

/**
 * What this site knows of its repository, in two tables of its own. Both are
 * left out of the dump. Nothing in them is secret: blob ids are hashes of
 * plaintext, and the files table holds paths, sizes and times.
 */
final class SafeGrd_Repo_Cache {
	const VERSION = 1;

	public static function files_table() {
		global $wpdb;
		return $wpdb->prefix . 'safegrd_files';
	}

	public static function blobs_table() {
		global $wpdb;
		return $wpdb->prefix . 'safegrd_blobs';
	}

	/**
	 * Creates the tables when they are missing or older than this plugin.
	 */
	public static function install() {
		if ( (int) get_option( 'safegrd_cache_version', 0 ) === self::VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::files_table() . " (
  epoch_id varchar(32) NOT NULL,
  path_hash char(64) NOT NULL,
  path longblob NOT NULL,
  size bigint NOT NULL,
  mtime bigint NOT NULL,
  inode bigint NOT NULL,
  mode int NOT NULL,
  sha256 char(64) NOT NULL,
  blobs longtext NOT NULL,
  run_id char(32) NOT NULL,
  PRIMARY KEY  (epoch_id,path_hash),
  KEY run (epoch_id,run_id)
) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::blobs_table() . " (
  epoch_id varchar(32) NOT NULL,
  id char(64) NOT NULL,
  type tinyint NOT NULL,
  pack_id char(32) NOT NULL,
  pack_bytes bigint NOT NULL,
  offset bigint NOT NULL,
  length bigint NOT NULL,
  raw_length bigint NOT NULL,
  run_id char(32) NOT NULL,
  committed tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY  (epoch_id,id),
  KEY run (epoch_id,run_id)
) $charset;"
		);
		update_option( 'safegrd_cache_version', self::VERSION, false );
	}

	/** Forgets every epoch but this one. */
	public static function keep_only( $epoch_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::files_table() . ' WHERE epoch_id <> %s', $epoch_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::blobs_table() . ' WHERE epoch_id <> %s', $epoch_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function forget_all() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::files_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::blobs_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( 'safegrd_cache_version' );
	}

	/**
	 * Which of these blob ids the epoch already holds.
	 *
	 * @return array id => true
	 */
	public static function known( $epoch_id, array $ids ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( $ids ) ), 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::blobs_table() . " WHERE epoch_id = %s AND id IN ($in)", array_merge( array( $epoch_id ), $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $rows as $id ) {
				$out[ $id ] = true;
			}
		}
		return $out;
	}

	/** Records the blobs of an uploaded pack as held by the epoch. */
	public static function add_pack( $epoch_id, $run_id, $pack_id, $pack_bytes, array $blobs ) {
		global $wpdb;
		foreach ( array_chunk( $blobs, 200 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $b ) {
				$values[] = '(%s,%s,%d,%s,%d,%d,%d,%d,%s,0)';
				array_push( $args, $epoch_id, $b['id'], $b['type'], $pack_id, $pack_bytes, $b['offset'], $b['length'], $b['raw_length'], $run_id );
			}
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::blobs_table() . ' (epoch_id,id,type,pack_id,pack_bytes,offset,length,raw_length,run_id,committed) VALUES ' . implode( ',', $values ), $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * Adopts the blobs of runs that never committed: their packs are in
	 * storage, and this run's index lists them so a snapshot can name them.
	 */
	public static function adopt( $epoch_id, $run_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::blobs_table() . ' SET run_id = %s WHERE epoch_id = %s AND committed = 0', $run_id, $epoch_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function mark_committed( $epoch_id, $run_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::blobs_table() . ' SET committed = 1 WHERE epoch_id = %s AND run_id = %s', $epoch_id, $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** The blobs this run stored first, by pack: the run's index. */
	public static function run_index( $epoch_id, $run_id ) {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id,type,pack_id,pack_bytes,offset,length,raw_length FROM ' . self::blobs_table() . ' WHERE epoch_id = %s AND run_id = %s ORDER BY pack_id, offset', $epoch_id, $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$packs = array();
		foreach ( $rows as $r ) {
			if ( ! isset( $packs[ $r['pack_id'] ] ) ) {
				$packs[ $r['pack_id'] ] = array(
					'pack_id' => $r['pack_id'],
					'bytes'   => (int) $r['pack_bytes'],
					'blobs'   => array(),
				);
			}
			$packs[ $r['pack_id'] ]['blobs'][] = array(
				'id'         => $r['id'],
				'type'       => (int) $r['type'],
				'offset'     => (int) $r['offset'],
				'length'     => (int) $r['length'],
				'raw_length' => (int) $r['raw_length'],
			);
		}
		return array_values( $packs );
	}

	/**
	 * The packs and runs a set of blobs lives in.
	 *
	 * @return array{packs:array,runs:array,missing:array}
	 */
	public static function locate( $epoch_id, array $ids ) {
		global $wpdb;
		$packs = array();
		$runs  = array();
		$found = array();
		foreach ( array_chunk( array_values( array_unique( $ids ) ), 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,pack_id,run_id FROM ' . self::blobs_table() . " WHERE epoch_id = %s AND id IN ($in)", array_merge( array( $epoch_id ), $chunk ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $rows as $r ) {
				$found[ $r['id'] ]       = true;
				$packs[ $r['pack_id'] ] = true;
				$runs[ $r['run_id'] ]    = true;
			}
		}
		$missing = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $found[ $id ] ) ) {
				$missing[] = $id;
			}
		}
		return array(
			'packs'   => array_keys( $packs ),
			'runs'    => array_keys( $runs ),
			'missing' => $missing,
		);
	}

	/** A file's row from an earlier run of this epoch, or null. */
	public static function file( $epoch_id, $path ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT size,mtime,inode,mode,sha256,blobs FROM ' . self::files_table() . ' WHERE epoch_id = %s AND path_hash = %s', $epoch_id, hash( 'sha256', $path ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/** Records the files this run saw, many at a time. */
	public static function put_files( $epoch_id, $run_id, array $rows ) {
		global $wpdb;
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $r ) {
				$values[] = '(%s,%s,%s,%d,%d,%d,%d,%s,%s,%s)';
				array_push( $args, $epoch_id, hash( 'sha256', $r['path'] ), $r['path'], $r['size'], $r['mtime'], $r['inode'], $r['mode'], $r['sha256'], implode( ',', $r['content'] ), $run_id );
			}
			$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::files_table() . ' (epoch_id,path_hash,path,size,mtime,inode,mode,sha256,blobs,run_id) VALUES ' . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE size=VALUES(size), mtime=VALUES(mtime), inode=VALUES(inode), mode=VALUES(mode), sha256=VALUES(sha256), blobs=VALUES(blobs), run_id=VALUES(run_id)', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/** Every file this run saw. */
	public static function run_files( $epoch_id, $run_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT path,size,mtime,mode,sha256,blobs FROM ' . self::files_table() . ' WHERE epoch_id = %s AND run_id = %s', $epoch_id, $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Drops this run's rows of paths it has not finished, for a fresh start. */
	public static function forget_run( $epoch_id, $run_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::files_table() . " SET run_id = '' WHERE epoch_id = %s AND run_id = %s", $epoch_id, $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

/**
 * The server's repository routes, under /api/v1/nodes/<node>/hosted/repo.
 */
final class SafeGrd_Repo_Client {
	/** @var SafeGrd_Client */
	private $client;
	private $base;

	public function __construct( SafeGrd_Client $client, $node_id ) {
		$this->client = $client;
		$this->base   = '/api/v1/nodes/' . rawurlencode( $node_id ) . '/hosted/repo';
	}

	private function call( $method, $path, $body = null ) {
		for ( $attempt = 0; ; $attempt++ ) {
			$r = $this->client->call( $method, $this->base . $path, $body, 120 );
			if ( ! is_wp_error( $r ) ) {
				return $r;
			}
			$status = (int) ( $r->get_error_data()['status'] ?? 0 );
			$busy   = 429 === $status && false === strpos( $r->get_error_message(), 'backups to hosted storage' );
			if ( ( $busy || $status >= 500 || 0 === $status ) && $attempt < 4 ) {
				sleep( 2 * ( $attempt + 1 ) );
				continue;
			}
			$reason = ( 402 === $status || 507 === $status ) ? 'quota' : 'storage';
			throw new SafeGrd_Exception( 'Hosted storage: ' . $r->get_error_message(), $reason );
		}
	}

	public function epochs( $node_id, $surface_id ) {
		$r = $this->call( 'GET', '/epochs?' . http_build_query( array( 'node_id' => $node_id, 'surface_id' => $surface_id ) ) );
		return isset( $r['epochs'] ) ? $r['epochs'] : array();
	}

	public function open_epoch( array $req ) {
		return $this->call( 'POST', '/epochs', $req );
	}

	/**
	 * Signs, uploads and confirms one object.
	 *
	 * @return string The object's key.
	 */
	public function put( $epoch_id, $kind, $name, $body ) {
		$slots = $this->call(
			'POST',
			'/epochs/' . rawurlencode( $epoch_id ) . '/sign',
			array(
				'objects' => array(
					array(
						'kind' => $kind,
						'name' => $name,
						'size' => strlen( $body ),
						'md5'  => base64_encode( md5( $body, true ) ),
					),
				),
			)
		);
		if ( empty( $slots['objects'][0]['url'] ) ) {
			throw new SafeGrd_Exception( 'Hosted storage signed no URL for ' . $kind . ' ' . $name . '.', 'storage' );
		}
		$slot    = $slots['objects'][0];
		$headers = array();
		foreach ( (array) ( $slot['headers'] ?? array() ) as $k => $v ) {
			if ( 0 !== strcasecmp( $k, 'Content-Length' ) ) {
				$headers[ $k ] = $v;
			}
		}
		$last = '';
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			if ( $attempt > 0 ) {
				sleep( $attempt * 2 );
			}
			$ok = SafeGrd_Client::put( $slot['url'], $body, $headers );
			if ( true === $ok ) {
				$last = '';
				break;
			}
			$last   = $ok->get_error_message();
			$status = (int) ( $ok->get_error_data()['status'] ?? 0 );
			if ( $status >= 400 && $status < 500 && 408 !== $status ) {
				break;
			}
		}
		if ( '' !== $last ) {
			throw new SafeGrd_Exception( sprintf( 'Hosted storage: uploading %s %s failed: %s', $kind, $name, $last ), 'storage' );
		}
		$this->call( 'POST', '/epochs/' . rawurlencode( $epoch_id ) . '/uploaded', array( 'keys' => array( $slot['key'] ) ) );
		return $slot['key'];
	}

	public function commit( $epoch_id, array $req ) {
		return $this->call( 'POST', '/epochs/' . rawurlencode( $epoch_id ) . '/commit', $req );
	}
}
