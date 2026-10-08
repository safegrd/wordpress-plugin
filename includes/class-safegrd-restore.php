<?php
/**
 * Restores a WordPress snapshot onto this site: a new install, or this site
 * itself. Runs in slices like a backup. Nothing live changes until every
 * table and file is loaded and checked; then one slice swaps them in and
 * keeps what it replaced aside.
 *
 * Stages:
 *   plan      the snapshot, its index and trees; the content root checked
 *   database  the dump, into tables under a temporary prefix
 *   tables    row counts against the manifest; prefix rows; the site URL
 *   files     the content directory, into a staging directory
 *   swap      tables and files into place, the old ones kept aside
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Restore {
	const JOB  = 'safegrd_restore';
	const LAST = 'safegrd_last_restore';
	/** Rows of a table rewritten per query when the site URL changes. */
	const URL_BATCH = 200;

	/** @var callable|null */
	private $say;
	private $budget;
	private $deadline = 0;
	/** @var array */
	private $job;
	/** @var SafeGrd_Client */
	private $client;
	/** @var SafeGrd_Repo_Reader */
	private $reader;
	/** @var mysqli|null */
	private $db;

	/**
	 * Whether a slice that leaves work starts the next in a request of its
	 * own. WP-CLI runs its slices one after another itself.
	 *
	 * @var bool
	 */
	public $chain = true;

	public function __construct( $say = null, $budget = null ) {
		$this->say    = $say;
		$this->budget = null === $budget ? (float) SafeGrd_Backup::default_budget() : (float) $budget;
	}

	public static function job() {
		$j = get_option( self::JOB, null );
		return is_array( $j ) ? $j : null;
	}

	// --- the tables of this restore's plan ----------------------------------

	private static function files_table() {
		global $wpdb;
		return $wpdb->prefix . 'safegrd_restore_files';
	}

	private static function index_table() {
		global $wpdb;
		return $wpdb->prefix . 'safegrd_restore_index';
	}

	private static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::files_table() . " (
  seq int NOT NULL,
  path longblob NOT NULL,
  size bigint NOT NULL,
  sha256 char(64) NOT NULL,
  content longtext NOT NULL,
  mode int NOT NULL,
  done tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY  (seq)
) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::index_table() . " (
  id char(64) NOT NULL,
  pack char(32) NOT NULL,
  offset bigint NOT NULL,
  length bigint NOT NULL,
  raw_length bigint NOT NULL,
  type tinyint NOT NULL,
  PRIMARY KEY  (id)
) $charset;"
		);
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::files_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::index_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	}

	private static function drop_tables() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::files_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::index_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
	}

	// --- starting -----------------------------------------------------------

	/**
	 * Starts a restore of a snapshot, after the person confirmed it.
	 *
	 * @return true|WP_Error
	 */
	public static function begin( $snapshot_id ) {
		if ( ! SafeGrd_Settings::connected() ) {
			return new WP_Error( 'safegrd_restore', 'Connect this site to SafeGrd first.' );
		}
		if ( self::job() ) {
			return new WP_Error( 'safegrd_restore', 'A restore is already under way on this site.' );
		}
		if ( is_array( get_option( SafeGrd_Backup::JOB, null ) ) ) {
			return new WP_Error( 'safegrd_restore', 'A backup of this site is under way. Restore when it finishes.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $snapshot_id ) ) {
			return new WP_Error( 'safegrd_restore', 'That is not a snapshot id.' );
		}
		$id = substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		update_option(
			self::JOB,
			array(
				'snapshot_id' => $snapshot_id,
				'id'          => $id,
				'stage'       => 'plan',
				'started'     => time(),
				'tmp'         => 'sgr' . $id . '_',
				'aside'       => 'sgb' . $id . '_',
				'staging'     => 'safegrd-restore-' . $id,
				'aside_dir'   => 'safegrd-before-restore-' . $id,
				'part'        => 0,
				'offset'      => 0,
				'table'       => 0,
				'pk'          => null,
				'seq'         => 0,
				'files'       => 0,
				'bytes'       => 0,
				'slices'      => 0,
				'notes'       => array(),
			),
			false
		);
		return true;
	}

	// --- one slice ----------------------------------------------------------

	/**
	 * Runs one slice of the restore under way.
	 *
	 * @return array status running, restored, failed or busy, with the job's progress.
	 */
	public function run() {
		if ( ! self::job() ) {
			return array( 'status' => 'idle' );
		}
		if ( ! SafeGrd_Backup::take_lock( $this->budget ) ) {
			SafeGrd_Scheduler::continue_later( SafeGrd_Backup::stale_seconds( $this->budget ) + 5 );
			return array(
				'status'  => 'busy',
				'message' => 'A slice of this site\'s restore is running now.',
			);
		}
		// Read again under the lock: the last slice may have run in another
		// request and moved the job on.
		$this->job = self::job();
		if ( ! $this->job ) {
			SafeGrd_Backup::release_lock();
			return array( 'status' => 'idle' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0.0 === $this->budget ? 0 : (int) ceil( $this->budget * 3 + 60 ) ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- each slice sets its own limit; some hosts disable the function, and the slice budget still holds.
		}
		ignore_user_abort( true );
		wp_raise_memory_limit( 'admin' );
		$this->deadline = 0.0 === $this->budget ? 0 : microtime( true ) + $this->budget;
		$this->client   = SafeGrd_Client::for_site();
		$next           = false;
		try {
			SafeGrd_Scheduler::continue_later( 0.0 === $this->budget ? HOUR_IN_SECONDS : SafeGrd_Backup::stale_seconds( $this->budget ) );
			$this->job['slices']++;
			$done = $this->work();
			if ( ! $done ) {
				$this->save();
				$next = true;
				return $this->progress( 'running' );
			}
			SafeGrd_Scheduler::continue_cancel();
			return $this->progress( 'restored' );
		} catch ( Throwable $e ) {
			return $this->failed( SafeGrd_Exception::text( $e ) );
		} finally {
			if ( $this->db ) {
				$this->db->close();
			}
			SafeGrd_Backup::release_lock();
			if ( $next && $this->chain ) {
				SafeGrd_Scheduler::continue_now();
			}
		}
	}

	private function work() {
		$stage = $this->job['stage'];
		if ( 'plan' === $stage ) {
			$this->plan();
			$this->job['stage'] = 'database';
			$this->save();
			if ( $this->out_of_time() ) {
				return false;
			}
		}
		$this->open_reader();
		if ( 'database' === $this->job['stage'] ) {
			if ( ! $this->load_database() ) {
				return false;
			}
			$this->job['stage'] = 'tables';
			$this->save();
		}
		if ( 'tables' === $this->job['stage'] ) {
			if ( ! $this->fix_tables() ) {
				return false;
			}
			$this->job['stage'] = 'files';
			$this->save();
		}
		if ( 'files' === $this->job['stage'] ) {
			if ( ! $this->write_files() ) {
				return false;
			}
			$this->job['stage'] = 'swap';
			$this->save();
		}
		if ( 'swap' === $this->job['stage'] ) {
			$this->swap();
		}
		return true;
	}

	private function out_of_time() {
		return $this->deadline > 0 && microtime( true ) >= $this->deadline;
	}

	private function save() {
		update_option( self::JOB, $this->job, false );
	}

	private function say( $line ) {
		if ( $this->say ) {
			call_user_func( $this->say, $line );
		}
	}

	// --- plan ---------------------------------------------------------------

	/** The key SafeGrd releases for the snapshot, to this site's token. Never stored. */
	private function identity() {
		$r = $this->client->call( 'GET', '/api/v1/nodes/' . rawurlencode( SafeGrd_Settings::get( 'node_id' ) ) . '/identity?snapshot=' . rawurlencode( $this->job['snapshot_id'] ), null, 30 );
		if ( is_wp_error( $r ) ) {
			$status = (int) ( $r->get_error_data()['status'] ?? 0 );
			if ( 404 === $status ) {
				throw new SafeGrd_Exception( esc_html( 'SafeGrd does not hold the key of this backup: it was taken with a customer-managed key. Restore it with the safegrd command line tool and that key file.' ), 'other' );
			}
			throw new SafeGrd_Exception( esc_html( 'SafeGrd did not release the key of this backup: ' . $r->get_error_message() ), 'storage' );
		}
		if ( empty( $r['identities'][0]['identity'] ) ) {
			throw new SafeGrd_Exception( esc_html( 'SafeGrd released no key for this backup.' ), 'storage' );
		}
		return $r['identities'][0]['identity'];
	}

	private function open_reader() {
		if ( $this->reader ) {
			return;
		}
		$this->reader = new SafeGrd_Repo_Reader( $this->client, SafeGrd_Settings::get( 'node_id' ), $this->job['prefix'], $this->identity() );
		global $wpdb;
		$index = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id,pack,offset,length,raw_length,type FROM %i', self::index_table() ), ARRAY_N ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table, which only this plugin reads and writes.
			$index[ $r[0] ] = array( $r[1], (int) $r[2], (int) $r[3], (int) $r[4], (int) $r[5] );
		}
		$this->reader->set_index( $index );
	}

	/**
	 * Reads the snapshot, its index and its trees, checks the content root
	 * against the one SafeGrd recorded, and writes down every file.
	 */
	private function plan() {
		global $wpdb;
		$this->say( 'Reading snapshot ' . $this->job['snapshot_id'] );
		$rec = $this->client->call( 'GET', '/api/v1/snapshots/' . rawurlencode( $this->job['snapshot_id'] ), null, 30 );
		if ( is_wp_error( $rec ) ) {
			throw new SafeGrd_Exception( esc_html( 'SafeGrd has no record of ' . $this->job['snapshot_id'] . ': ' . $rec->get_error_message() ), 'other' );
		}
		$rec = isset( $rec['snapshot'] ) ? $rec['snapshot'] : $rec;
		if ( 'wordpress' !== ( $rec['surface_type'] ?? '' ) || 'repo-v1' !== ( $rec['format'] ?? '' ) || empty( $rec['epoch_id'] ) ) {
			throw new SafeGrd_Exception( esc_html( $this->job['snapshot_id'] . ' is not a WordPress backup this plugin restores.' ), 'other' );
		}
		if ( ! in_array( $rec['status'] ?? '', array( 'completed', 'verified' ), true ) ) {
			throw new SafeGrd_Exception( esc_html( $this->job['snapshot_id'] . ' did not complete, so there is nothing to restore.' ), 'other' );
		}
		$epochs = $this->client->call( 'GET', '/api/v1/nodes/' . rawurlencode( SafeGrd_Settings::get( 'node_id' ) ) . '/hosted/repo/epochs?' . http_build_query( array( 'node_id' => $rec['node_id'], 'surface_id' => SafeGrd_Backup::SURFACE_ID ) ), null, 30 );
		if ( is_wp_error( $epochs ) ) {
			throw new SafeGrd_Exception( esc_html( 'Hosted storage: ' . $epochs->get_error_message() ), 'storage' );
		}
		$prefix = '';
		foreach ( (array) ( $epochs['epochs'] ?? array() ) as $e ) {
			if ( ( $e['epoch']['epoch_id'] ?? '' ) === $rec['epoch_id'] ) {
				$prefix = $e['prefix'];
			}
		}
		if ( '' === $prefix ) {
			throw new SafeGrd_Exception( esc_html( 'Hosted storage no longer holds the month this backup is in. It has expired.' ), 'other' );
		}
		$this->job['prefix'] = $prefix;
		$this->reader        = new SafeGrd_Repo_Reader( $this->client, SafeGrd_Settings::get( 'node_id' ), $prefix, $this->identity() );
		$snap                = $this->reader->object( 'snapshot', $this->job['snapshot_id'] );
		if ( ( $snap['snapshot_id'] ?? '' ) !== $this->job['snapshot_id'] ) {
			throw new SafeGrd_Exception( esc_html( 'The snapshot object names another snapshot.' ), 'other' );
		}
		$this->reader->load_index( $snap );
		$entries = $this->reader->walk( $snap['root_tree'] );

		// The content root of the trees, as SafeGrd recorded it.
		$lines = array();
		foreach ( $entries as list( $path, $e ) ) {
			$lines[ $path ] = 'dir' === $e['type'] ? array( 'd', '-' ) : array( 'f', $e['sha256'] );
		}
		if ( SafeGrd_Repo_Format::content_root( $lines ) !== ( $rec['sha256_checksum'] ?? '' ) ) {
			throw new SafeGrd_Exception( esc_html( 'The backup in storage does not match what SafeGrd recorded when it was taken. Nothing was restored.' ), 'other' );
		}

		// The manifest, then every file in the order the restore writes them.
		$parts = array();
		$files = array();
		$man   = null;
		foreach ( $entries as list( $path, $e ) ) {
			if ( 'file' !== $e['type'] ) {
				continue;
			}
			if ( 'manifest.json' === $path ) {
				$man = $e;
			} elseif ( 'mysql/dump.sql' === $path ) {
				$parts[0] = array( $path, $e );
			} elseif ( 0 === strpos( $path, 'mysql/dump.sql.' ) ) {
				$parts[ (int) substr( $path, 15 ) ] = array( $path, $e );
			} elseif ( 0 === strpos( $path, 'files/' ) ) {
				$files[] = array( $path, $e );
			}
		}
		if ( ! $man || ! isset( $parts[0] ) ) {
			throw new SafeGrd_Exception( esc_html( 'This backup holds no manifest or no database dump.' ), 'other' );
		}
		$manifest = json_decode( $this->read_file( $man ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['wordpress'] ) ) {
			throw new SafeGrd_Exception( esc_html( 'The backup\'s manifest does not parse.' ), 'other' );
		}
		ksort( $parts );
		if ( array_keys( $parts ) !== range( 0, count( $parts ) - 1 ) ) {
			throw new SafeGrd_Exception( esc_html( 'A part of the backup\'s dump is missing.' ), 'other' );
		}

		self::install_tables();
		$seq = 0;
		foreach ( array_merge( array_values( $parts ), $files ) as list( $path, $e ) ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table, which only this plugin reads and writes.
				self::files_table(),
				array(
					'seq'     => $seq++,
					'path'    => $path,
					'size'    => (int) $e['size'],
					'sha256'  => $e['sha256'],
					'content' => implode( ',', $e['content'] ),
					'mode'    => (int) $e['mode'],
				)
			);
		}
		foreach ( array_chunk( $this->reader->index(), 200, true ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $id => $loc ) {
				$values[] = '(%s,%s,%d,%d,%d,%d)';
				array_push( $args, $id, $loc[0], $loc[1], $loc[2], $loc[3], $loc[4] );
			}
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (id,pack,offset,length,raw_length,type) VALUES ' . implode( ',', $values ), array_merge( array( self::index_table() ), $args ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the plugin's own table, which only this plugin reads and writes; the VALUES list is placeholders only, one group per row, filled by prepare.
		}

		$wp                         = $manifest['wordpress'];
		$this->job['parts']         = count( $parts );
		$this->job['total_files']   = count( $files );
		$this->job['manifest']      = array(
			'tables' => $manifest['table_stats'],
			'rows'   => (int) $manifest['total_rows'],
		);
		$this->job['source_prefix'] = $wp['table_prefix'];
		$this->job['source_url']    = $wp['site_url'];
		$this->job['source_wp']     = $wp['wordpress_version'] ?? '';
		$this->job['uploads_path']  = $wp['uploads_path'] ?? 'wp-content/uploads';
		$this->job['source_node']   = $rec['node_id'];
		$this->job['taken_at']      = $rec['created_at'] ?? '';
		$this->job['target_url']    = home_url();
		$this->job['target_prefix'] = $wpdb->prefix;

		$this->drop_leftovers();
		$staging = $this->content_dir() . '/' . $this->job['staging'];
		if ( ! wp_mkdir_p( $staging ) ) {
			throw new SafeGrd_Exception( esc_html( 'Could not create ' . $staging . ' to stage the files: the content directory is not writable.' ), 'other' );
		}
		$this->say( sprintf( 'Snapshot of %s, taken %s: %d tables, %d files', $wp['site_url'], $this->job['taken_at'], count( $manifest['table_stats'] ), count( $files ) ) );
	}

	/**
	 * Drops what an earlier restore that never swapped left behind: tables
	 * under its temporary prefix and its staging directory.
	 */
	private function drop_leftovers() {
		$db = $this->db();
		$r  = $db->query( "SHOW TABLES LIKE 'sgr%'" );
		$drop = array();
		while ( $r && ( $row = $r->fetch_row() ) ) {
			if ( preg_match( '/^sgr[0-9a-f]{6}_/', $row[0] ) && 0 !== strpos( $row[0], $this->job['tmp'] ) ) {
				$drop[] = '`' . $row[0] . '`';
			}
		}
		if ( $drop ) {
			$db->query( 'DROP TABLE IF EXISTS ' . implode( ',', $drop ) );
		}
		foreach ( (array) glob( $this->content_dir() . '/safegrd-restore-*', GLOB_ONLYDIR ) as $dir ) {
			if ( basename( $dir ) !== $this->job['staging'] ) {
				self::rmdir( $dir );
			}
		}
	}

	private function content_dir() {
		return rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
	}

	private function db() {
		if ( ! $this->db ) {
			$this->db = SafeGrd_Dumper::connect();
		}
		return $this->db;
	}

	/** A small file of the snapshot, whole and checked. */
	private function read_file( array $e ) {
		$out = '';
		foreach ( $e['content'] as $id ) {
			$out .= $this->reader->blob( $id );
		}
		if ( hash( 'sha256', $out ) !== $e['sha256'] ) {
			throw new SafeGrd_Exception( esc_html( 'A file of the backup does not match its SHA-256.' ), 'other' );
		}
		return $out;
	}

	private function row( $seq ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT seq,path,size,sha256,content,mode FROM %i WHERE seq = %d', self::files_table(), $seq ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table, which only this plugin reads and writes.
	}

	// --- database -----------------------------------------------------------

	/**
	 * Loads the dump into tables under the temporary prefix, statement by
	 * statement, from where the last slice stopped.
	 *
	 * @return bool Whether the whole dump is loaded.
	 */
	private function load_database() {
		$db = $this->db();
		// What the dump's header sets, again in every slice: a slice that
		// resumes mid-dump has its own connection.
		foreach ( array( "SET SESSION FOREIGN_KEY_CHECKS=0", "SET SESSION UNIQUE_CHECKS=0", "SET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO'", "SET SESSION time_zone='+00:00'" ) as $set ) {
			$db->query( $set );
		}
		if ( 0 === $this->job['part'] && 0 === $this->job['offset'] ) {
			$this->say( 'Loading the database into new tables' );
		}
		while ( $this->job['part'] < $this->job['parts'] ) {
			$row     = $this->row( $this->job['part'] );
			$content = '' === $row['content'] ? array() : explode( ',', $row['content'] );
			$skip    = (int) $this->job['offset'];
			$pos     = 0; // bytes of the part before the buffer
			$buf     = '';
			$hash    = 0 === $skip ? hash_init( 'sha256' ) : null;
			foreach ( $content as $id ) {
				$len = $this->reader->index()[ $id ][3];
				if ( $pos + $len <= $skip ) {
					$pos += $len; // loaded by an earlier slice
					continue;
				}
				$chunk = $this->reader->blob( $id );
				if ( $hash ) {
					hash_update( $hash, $chunk );
				}
				if ( $pos < $skip ) {
					$chunk = substr( $chunk, $skip - $pos );
					$pos   = $skip;
				}
				$buf .= $chunk;
				while ( false !== ( $end = strpos( $buf, ";\n" ) ) ) {
					$this->execute( substr( $buf, 0, $end ) );
					$buf                 = (string) substr( $buf, $end + 2 );
					$pos                += $end + 2;
					$this->job['offset'] = $pos;
					if ( $this->out_of_time() ) {
						return false;
					}
				}
			}
			if ( '' !== trim( preg_replace( '/^--[^\n]*$/m', '', $buf ) ) ) {
				throw new SafeGrd_Exception( esc_html( 'The dump ends in the middle of a statement.' ), 'other' );
			}
			if ( $hash && hash_final( $hash ) !== $row['sha256'] ) {
				throw new SafeGrd_Exception( esc_html( 'A part of the dump does not match its SHA-256.' ), 'other' );
			}
			$this->job['part']++;
			$this->job['offset'] = 0;
			$this->save();
		}
		return true;
	}

	/**
	 * Runs one statement of the dump, with its table renamed to the
	 * temporary prefix.
	 */
	private function execute( $sql ) {
		// The header saves session settings into @OLD_ variables and the
		// footer puts them back; a slice that resumes has a new connection
		// where they are empty. Every slice sets these itself instead.
		if ( false !== strpos( $sql, '@OLD_' ) ) {
			return;
		}
		$from = $this->job['source_prefix'];
		$tmp  = $this->job['tmp'];
		$sql  = preg_replace_callback(
			'/^((?:--[^\n]*\n|\s)*)(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `((?:[^`]|``)+)`/',
			function ( $m ) use ( $from, $tmp ) {
				$name = str_replace( '``', '`', $m[3] );
				if ( 0 !== strpos( $name, $from ) ) {
					throw new SafeGrd_Exception( esc_html( 'The dump names a table outside the site\'s prefix: ' . $name ), 'other' );
				}
				return $m[1] . $m[2] . ' `' . str_replace( '`', '``', $tmp . substr( $name, strlen( $from ) ) ) . '`';
			},
			$sql,
			1
		);
		if ( false === $this->db()->query( $sql ) ) {
			throw new SafeGrd_Exception( esc_html( 'The database refused a statement of the dump: ' . $this->db()->error . ' (' . substr( $sql, 0, 120 ) . ')' ), 'other' );
		}
	}

	// --- tables -------------------------------------------------------------

	/**
	 * Counts every table against the manifest, renames the rows that carry
	 * the table prefix, and moves the site URL to this site's.
	 *
	 * @return bool Whether all of it is done.
	 */
	private function fix_tables() {
		$db  = $this->db();
		$tmp = $this->job['tmp'];
		$src = $this->job['source_prefix'];
		$dst = $this->job['target_prefix'];
		if ( empty( $this->job['counted'] ) ) {
			foreach ( $this->job['manifest']['tables'] as $t ) {
				$name = $tmp . substr( $t['table_name'], strlen( $src ) );
				$r    = $db->query( 'SELECT COUNT(*) FROM `' . $name . '`' );
				$got  = $r ? (int) $r->fetch_row()[0] : -1;
				if ( $got !== (int) $t['row_count'] ) {
					throw new SafeGrd_Exception( esc_html( sprintf( '%s loaded %d rows; the backup recorded %d. Nothing was restored.', $t['table_name'], $got, $t['row_count'] ) ), 'other' );
				}
			}
			$this->say( sprintf( 'Loaded %d tables, %d rows, each matching the backup', count( $this->job['manifest']['tables'] ), $this->job['manifest']['rows'] ) );
			if ( $src !== $dst ) {
				$this->rename_prefixed_keys( $src, $dst );
				$this->job['notes'][] = '' === $src
					? sprintf( 'Tables moved from no prefix to %s.', $dst )
					: sprintf( 'Tables moved from the prefix %s to %s.', $src, $dst );
			}
			$this->job['counted'] = true;
			$this->save();
		}
		$from = untrailingslashit( $this->job['source_url'] );
		$to   = untrailingslashit( $this->job['target_url'] );
		if ( $from !== $to ) {
			if ( ! $this->replace_url( $from, $to ) ) {
				return false;
			}
			$this->job['notes'][] = sprintf( 'Every %s was replaced with %s, serialized values included.', $from, $to );
		}
		foreach ( array( 'siteurl' => site_url(), 'home' => home_url() ) as $name => $value ) {
			$stmt = $db->prepare( "UPDATE `{$tmp}options` SET option_value = ? WHERE option_name = ?" );
			$stmt->bind_param( 'ss', $value, $name );
			$stmt->execute();
			$stmt->close();
		}
		return true;
	}

	/**
	 * Renames the option and user meta keys that carry the table prefix
	 * (<prefix>user_roles, <prefix>capabilities and the rest). A site with no
	 * prefix gives no way to tell those keys apart, so then only the ones
	 * WordPress itself writes are renamed.
	 */
	private function rename_prefixed_keys( $src, $dst ) {
		$db  = $this->db();
		$tmp = $this->job['tmp'];
		$to  = $db->real_escape_string( $dst );
		if ( '' === $src ) {
			$db->query( "UPDATE `{$tmp}options` SET option_name = CONCAT('$to', option_name) WHERE option_name = 'user_roles'" );
			$db->query( "UPDATE `{$tmp}usermeta` SET meta_key = CONCAT('$to', meta_key) WHERE meta_key IN ('capabilities', 'user_level', 'user-settings', 'user-settings-time', 'dashboard_quick_press_last_post_id')" );
			return;
		}
		$like = $db->real_escape_string( addcslashes( $src, '_%\\' ) ) . '%';
		$from = strlen( $src ) + 1;
		foreach ( array( "UPDATE `{$tmp}options` SET option_name = CONCAT('$to', SUBSTRING(option_name, $from)) WHERE option_name LIKE '$like'", "UPDATE `{$tmp}usermeta` SET meta_key = CONCAT('$to', SUBSTRING(meta_key, $from)) WHERE meta_key LIKE '$like'" ) as $sql ) {
			if ( false === $db->query( $sql ) ) {
				throw new SafeGrd_Exception( esc_html( 'Renaming the prefixed rows failed: ' . $db->error ), 'other' );
			}
		}
	}

	/**
	 * Replaces the old site URL with this one in every text column of the
	 * loaded tables, except posts' guid. A serialized value is rewritten as
	 * serialized text, its string lengths fixed, so objects of any class
	 * come through.
	 *
	 * @return bool Whether every table is done.
	 */
	private function replace_url( $from, $to ) {
		$db     = $this->db();
		$tmp    = $this->job['tmp'];
		$src    = $this->job['source_prefix'];
		$tables = $this->job['manifest']['tables'];
		$pairs  = array(
			$from                       => $to,
			str_replace( '/', '\\/', $from ) => str_replace( '/', '\\/', $to ),
		);
		if ( 0 === $this->job['table'] && null === $this->job['pk'] ) {
			$this->say( sprintf( 'Replacing %s with %s', $from, $to ) );
		}
		for ( ; $this->job['table'] < count( $tables ); $this->job['table']++, $this->job['pk'] = null ) {
			$suffix = substr( $tables[ $this->job['table'] ]['table_name'], strlen( $src ) );
			$name   = $tmp . $suffix;
			$cols   = array();
			$pk     = array();
			$r      = $db->query( 'SHOW COLUMNS FROM `' . $name . '`' );
			while ( $r && ( $c = $r->fetch_assoc() ) ) {
				if ( 'PRI' === $c['Key'] ) {
					$pk[] = $c['Field'];
				}
				if ( preg_match( '/char|text|json|enum|set/i', $c['Type'] ) && ! ( 'posts' === $suffix && 'guid' === $c['Field'] ) ) {
					$cols[] = $c['Field'];
				}
			}
			if ( ! $cols ) {
				continue;
			}
			if ( 1 !== count( $pk ) ) {
				$this->job['notes'][] = sprintf( '%s has no single-column primary key, so its rows were not checked for the old site URL.', $suffix );
				continue;
			}
			$key  = $pk[0];
			$like = "'%" . $db->real_escape_string( addcslashes( $from, '_%\\' ) ) . "%'";
			$where = implode( ' OR ', array_map( function ( $c ) use ( $like ) {
				return '`' . $c . '` LIKE ' . $like;
			}, $cols ) );
			while ( true ) {
				$after = null === $this->job['pk'] ? '' : " AND `$key` > '" . $db->real_escape_string( (string) $this->job['pk'] ) . "'";
				$res   = $db->query( 'SELECT `' . $key . '`,`' . implode( '`,`', $cols ) . '` FROM `' . $name . "` WHERE ($where)$after ORDER BY `$key` LIMIT " . self::URL_BATCH );
				if ( ! $res ) {
					throw new SafeGrd_Exception( esc_html( 'Reading ' . $suffix . ' to replace the site URL failed: ' . $db->error ), 'other' );
				}
				$n = 0;
				while ( $row = $res->fetch_assoc() ) {
					$n++;
					$set = array();
					foreach ( $cols as $c ) {
						if ( null === $row[ $c ] ) {
							continue;
						}
						$new = self::replace_value( $row[ $c ], $pairs );
						if ( $new !== $row[ $c ] ) {
							$set[] = '`' . $c . "` = '" . $db->real_escape_string( $new ) . "'";
						}
					}
					if ( $set && false === $db->query( 'UPDATE `' . $name . '` SET ' . implode( ',', $set ) . " WHERE `$key` = '" . $db->real_escape_string( (string) $row[ $key ] ) . "'" ) ) {
						throw new SafeGrd_Exception( esc_html( 'Replacing the site URL in ' . $suffix . ' failed: ' . $db->error ), 'other' );
					}
					$this->job['pk'] = $row[ $key ];
				}
				if ( $n < self::URL_BATCH ) {
					break;
				}
				if ( $this->out_of_time() ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * A value with the old URL replaced: serialized text rewritten string by
	 * string, anything else replaced as text.
	 */
	public static function replace_value( $value, array $pairs ) {
		if ( is_serialized( $value ) ) {
			$out = self::replace_serialized( $value, $pairs );
			if ( null !== $out ) {
				return $out;
			}
		}
		return strtr( $value, $pairs );
	}

	/**
	 * Rewrites every string of a serialized value, fixing its length; a
	 * string that is itself serialized is rewritten the same way. Null when
	 * the text does not parse, so the caller does not guess.
	 */
	public static function replace_serialized( $s, array $pairs ) {
		$out = '';
		$i   = 0;
		$n   = strlen( $s );
		while ( $i < $n ) {
			$c = $s[ $i ];
			if ( 's' === $c && ':' === ( $s[ $i + 1 ] ?? '' ) ) {
				$colon = strpos( $s, ':', $i + 2 );
				if ( false === $colon ) {
					return null;
				}
				$len = (int) substr( $s, $i + 2, $colon - $i - 2 );
				if ( '"' !== ( $s[ $colon + 1 ] ?? '' ) || '"' !== ( $s[ $colon + 2 + $len ] ?? '' ) ) {
					return null;
				}
				$str = substr( $s, $colon + 2, $len );
				$new = is_serialized( $str ) ? self::replace_serialized( $str, $pairs ) : null;
				$new = null === $new ? strtr( $str, $pairs ) : $new;
				$out .= 's:' . strlen( $new ) . ':"' . $new . '"';
				$i    = $colon + 3 + $len;
				continue;
			}
			if ( ( 'O' === $c || 'C' === $c ) && ':' === ( $s[ $i + 1 ] ?? '' ) ) {
				// A class name is copied as it is: it is not a URL.
				$colon = strpos( $s, ':', $i + 2 );
				if ( false === $colon ) {
					return null;
				}
				$len  = (int) substr( $s, $i + 2, $colon - $i - 2 );
				$end  = $colon + 3 + $len;
				$out .= substr( $s, $i, $end - $i );
				$i    = $end;
				continue;
			}
			$out .= $c;
			$i++;
		}
		return $out;
	}

	// --- files --------------------------------------------------------------

	/**
	 * Writes the content directory into the staging directory, every file
	 * against its SHA-256, from where the last slice stopped.
	 *
	 * @return bool Whether every file is written.
	 */
	private function write_files() {
		$prefix = 'files/' . $this->source_content_path() . '/';
		$stage  = $this->content_dir() . '/' . $this->job['staging'];
		if ( 0 === $this->job['seq'] ) {
			$this->job['seq'] = $this->job['parts'];
			$this->say( 'Writing the files' );
		}
		$last = $this->job['parts'] + $this->job['total_files'];
		for ( ; $this->job['seq'] < $last; $this->job['seq']++ ) {
			if ( $this->out_of_time() ) {
				return false;
			}
			$row = $this->row( $this->job['seq'] );
			if ( 0 !== strpos( $row['path'], $prefix ) ) {
				$rel = substr( $row['path'], 6 );
				if ( in_array( $rel, array( 'wp-config.php', '.htaccess' ), true ) ) {
					continue; // this site's own: its database, its server rules
				}
				$this->job['notes'][] = $rel . ' is outside the content directory and was not restored.';
				continue;
			}
			$dst = $stage . '/' . substr( $row['path'], strlen( $prefix ) );
			if ( ! wp_mkdir_p( dirname( $dst ) ) ) {
				throw new SafeGrd_Exception( esc_html( 'Could not create ' . dirname( $dst ) . '.' ), 'other' );
			}
			$fh = fopen( $dst, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $fh ) {
				throw new SafeGrd_Exception( esc_html( 'Could not write ' . $dst . '.' ), 'other' );
			}
			$h = hash_init( 'sha256' );
			foreach ( '' === $row['content'] ? array() : explode( ',', $row['content'] ) as $id ) {
				$chunk = $this->reader->blob( $id );
				hash_update( $h, $chunk );
				fwrite( $fh, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( hash_final( $h ) !== $row['sha256'] ) {
				throw new SafeGrd_Exception( esc_html( $row['path'] . ' does not match its SHA-256. Nothing was restored.' ), 'other' );
			}
			$mode = (int) $row['mode'] & 0777;
			if ( $mode ) {
				@chmod( $dst, $mode | 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- a mode the host refuses is not worth failing for.
			}
			$this->job['files']++;
			$this->job['bytes'] += (int) $row['size'];
		}
		$this->say( sprintf( 'Wrote %d files, %s, each matching the backup', $this->job['files'], size_format( $this->job['bytes'] ) ) );
		return true;
	}

	/** The content directory's path in the snapshot: wp-content, normally. */
	private function source_content_path() {
		$up = trim( $this->job['uploads_path'], '/' );
		if ( '/uploads' === substr( '/' . $up, -8 ) ) {
			$c = substr( $up, 0, -8 );
			return '' === $c ? 'wp-content' : $c;
		}
		return 'wp-content';
	}

	// --- swap ---------------------------------------------------------------

	/**
	 * Puts the loaded tables and staged files in place, keeping what they
	 * replace aside.
	 */
	private function swap() {
		global $wpdb;
		$this->say( 'Swapping the restored site in' );
		$db      = $this->db();
		$tmp     = $this->job['tmp'];
		$aside   = $this->job['aside'];
		$live    = $this->job['target_prefix'];
		$src     = $this->job['source_prefix'];
		$content = $this->content_dir();

		// This plugin's own rows go into the restored options table, so the
		// site stays connected and the restore can record that it finished.
		$this->job['stage'] = 'done';
		$this->save();
		$own = $db->query( "SELECT option_name, option_value, autoload FROM `{$live}options` WHERE option_name LIKE 'safegrd\\_%' OR option_name LIKE '\\_transient\\_safegrd\\_%' OR option_name LIKE '\\_transient\\_timeout\\_safegrd\\_%'" );
		while ( $own && ( $o = $own->fetch_row() ) ) {
			$stmt = $db->prepare( "REPLACE INTO `{$tmp}options` (option_name, option_value, autoload) VALUES (?, ?, ?)" );
			$stmt->bind_param( 'sss', $o[0], $o[1], $o[2] );
			$stmt->execute();
			$stmt->close();
		}

		$maintenance = ABSPATH . '.maintenance';
		$maint       = @file_put_contents( $maintenance, '<?php $upgrading = ' . time() . ';' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- WordPress reads .maintenance from ABSPATH, as core updates write it; best effort, the swap is quick either way.

		$renames = array();
		foreach ( $this->job['manifest']['tables'] as $t ) {
			$suffix = substr( $t['table_name'], strlen( $src ) );
			$r      = $db->query( "SHOW TABLES LIKE '" . $db->real_escape_string( addcslashes( $live . $suffix, '_%\\' ) ) . "'" );
			if ( $r && $r->num_rows ) {
				$renames[] = "`{$live}{$suffix}` TO `{$aside}{$suffix}`";
			}
			$renames[] = "`{$tmp}{$suffix}` TO `{$live}{$suffix}`";
		}
		if ( false === $db->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
			if ( $maint ) {
				wp_delete_file( $maintenance );
			}
			$this->job['stage'] = 'swap';
			$this->save();
			throw new SafeGrd_Exception( esc_html( 'The database refused to swap the restored tables in: ' . $db->error . '. The site is as it was.' ), 'other' );
		}

		// Files: each top-level entry of the content directory, kept aside
		// and replaced. mu-plugins is merged: a host's own must-use plugins
		// stay.
		$stage = $content . '/' . $this->job['staging'];
		$keep  = $content . '/' . $this->job['aside_dir'];
		wp_mkdir_p( $keep );
		foreach ( (array) scandir( $stage ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( 'mu-plugins' === $name && is_dir( $content . '/mu-plugins' ) ) {
				foreach ( (array) scandir( $stage . '/mu-plugins' ) as $mu ) {
					if ( '.' !== $mu && '..' !== $mu && ! file_exists( $content . '/mu-plugins/' . $mu ) ) {
						self::move( $stage . '/mu-plugins/' . $mu, $content . '/mu-plugins/' . $mu );
					}
				}
				continue;
			}
			if ( file_exists( $content . '/' . $name ) ) {
				self::move( $content . '/' . $name, $keep . '/' . $name );
			}
			self::move( $stage . '/' . $name, $content . '/' . $name );
		}
		self::rmdir( $stage );
		if ( $maint ) {
			wp_delete_file( $maintenance );
		}

		wp_cache_flush();
		delete_option( 'rewrite_rules' );
		SafeGrd_Scheduler::reschedule_after_restore();
		$last = array(
			'snapshot_id' => $this->job['snapshot_id'],
			'source_url'  => $this->job['source_url'],
			'taken_at'    => $this->job['taken_at'],
			'restored_at' => gmdate( 'c' ),
			'tables'      => count( $this->job['manifest']['tables'] ),
			'rows'        => $this->job['manifest']['rows'],
			'files'       => $this->job['files'],
			'aside'       => $aside,
			'aside_dir'   => $this->job['aside_dir'],
			'notes'       => $this->job['notes'],
			'wordpress'   => $this->job['source_wp'],
		);
		delete_option( self::JOB );
		update_option( self::LAST, $last, false );
		self::drop_tables();
		$this->say( 'Restored. Sign in with an administrator account of the restored site.' );
	}

	// --- outcomes -----------------------------------------------------------

	private function progress( $status ) {
		if ( 'restored' === $status ) {
			$last = get_option( self::LAST, array() );
			return array_merge( array( 'status' => 'restored' ), (array) $last );
		}
		return array(
			'status'      => $status,
			'snapshot_id' => $this->job['snapshot_id'],
			'stage'       => $this->job['stage'],
			'files'       => $this->job['files'],
			'total_files' => $this->job['total_files'] ?? 0,
			'slices'      => $this->job['slices'],
		);
	}

	/**
	 * A restore that failed before its swap: the site is as it was. The
	 * temporary tables and staged files are removed.
	 */
	private function failed( $message ) {
		$this->say( 'Error: ' . $message );
		$job = $this->job;
		delete_option( self::JOB );
		SafeGrd_Scheduler::continue_cancel();
		if ( 'done' !== ( $job['stage'] ?? '' ) ) {
			$drop = array();
			$db   = $this->db();
			foreach ( (array) ( $job['manifest']['tables'] ?? array() ) as $t ) {
				$drop[] = '`' . $job['tmp'] . substr( $t['table_name'], strlen( $job['source_prefix'] ) ) . '`';
			}
			if ( $drop ) {
				$db->query( 'DROP TABLE IF EXISTS ' . implode( ',', $drop ) );
			}
			self::rmdir( $this->content_dir() . '/' . $job['staging'] );
			self::drop_tables();
			$message .= ' This site is as it was.';
		}
		update_option(
			self::LAST,
			array(
				'snapshot_id' => $job['snapshot_id'],
				'failed'      => $message,
				'restored_at' => gmdate( 'c' ),
			),
			false
		);
		return array(
			'status'  => 'failed',
			'message' => $message,
		);
	}

	/**
	 * Deletes the tables and files a finished restore kept aside.
	 *
	 * @return string What was deleted, in words.
	 */
	public static function delete_copy() {
		$last = get_option( self::LAST, array() );
		if ( empty( $last['aside'] ) ) {
			return 'There is no copy from before a restore to delete.';
		}
		$db = SafeGrd_Dumper::connect();
		$r  = $db->query( "SHOW TABLES LIKE '" . $db->real_escape_string( addcslashes( $last['aside'], '_%\\' ) ) . "%'" );
		$drop = array();
		while ( $r && ( $row = $r->fetch_row() ) ) {
			$drop[] = '`' . $row[0] . '`';
		}
		if ( $drop ) {
			$db->query( 'DROP TABLE IF EXISTS ' . implode( ',', $drop ) );
		}
		$db->close();
		self::rmdir( rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/' . $last['aside_dir'] );
		unset( $last['aside'], $last['aside_dir'] );
		update_option( self::LAST, $last, false );
		return sprintf( 'Deleted the copy from before the restore: %d tables and its files.', count( $drop ) );
	}

	/**
	 * Moves a file or directory within the content directory, through
	 * WordPress's direct filesystem: the swap runs in a background request,
	 * where no FTP credentials can be asked for. A move that fails stops the
	 * restore and says which.
	 */
	private static function move( $from, $to ) {
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}
		$fs = new WP_Filesystem_Direct( null );
		if ( ! $fs->move( $from, $to, false ) ) {
			throw new SafeGrd_Exception( esc_html( 'Could not move ' . $from . ' to ' . $to . '. The files may be part swapped: the ones kept aside are in the content directory under safegrd-before-restore-*.' ), 'other' );
		}
	}

	private static function rmdir( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$p = $dir . '/' . $name;
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				self::rmdir( $p );
			} else {
				wp_delete_file( $p );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * The organization's WordPress snapshots this site can restore, newest
	 * first.
	 *
	 * @return array|WP_Error
	 */
	public static function snapshots() {
		$client = SafeGrd_Client::for_site();
		$org    = SafeGrd_Settings::get( 'org_id' );
		$r      = $client->call( 'GET', '/api/v1/snapshots?' . http_build_query( array( 'org_id' => $org, 'limit' => 100 ) ), null, 30 );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = array();
		foreach ( (array) ( $r['items'] ?? $r ) as $s ) {
			if ( 'wordpress' !== ( $s['surface_type'] ?? '' ) || 'repo-v1' !== ( $s['format'] ?? '' ) || ! in_array( $s['status'] ?? '', array( 'completed', 'verified' ), true ) ) {
				continue;
			}
			$out[] = array(
				'id'       => $s['snapshot_id'],
				'site'     => $s['wordpress']['site_url'] ?? '',
				'taken'    => $s['created_at'] ?? '',
				'tables'   => (int) ( $s['total_tables'] ?? 0 ),
				'files'    => (int) ( $s['wordpress']['total_files'] ?? 0 ),
				'size'     => (int) ( $s['raw_size_bytes'] ?? 0 ),
				'verified' => 'verified' === ( $s['status'] ?? '' ),
			);
		}
		return $out;
	}
}
