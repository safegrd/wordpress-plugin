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
	/** Downloads ready to fetch: id => {path, name, snapshot_id, component, bytes, created, expires}. */
	const DOWNLOADS = 'safegrd_downloads';
	/** How long a download stays on the server. */
	const DOWNLOAD_TTL = DAY_IN_SECONDS;
	/** Bytes of tar gathered before they are compressed and written. */
	const OUT_BUFFER = 8388608;
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

	/** The parts a restore or a download can take item by item. */
	const ITEM_PARTS = array( 'plugins', 'themes', 'database' );

	/**
	 * Starts a restore of a snapshot, or of some of its parts.
	 *
	 * @param string     $snapshot_id The snapshot.
	 * @param array|null $components  Some of SafeGrd_Site::COMPONENTS; null for all of them, or
	 *                                for the parts $items names when it names any.
	 * @param string     $mode        restore or download.
	 * @param array      $items       Some plugins, themes or tables only: part => names. A
	 *                                plugin or theme is its directory (or file) under plugins
	 *                                or themes; a table is its name in the backup, with or
	 *                                without the backup's table prefix.
	 * @return true|WP_Error
	 */
	public static function begin( $snapshot_id, $components = null, $mode = 'restore', array $items = array() ) {
		$items = self::check_items( $items, 'download' !== $mode );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		if ( null === $components ) {
			$components = $items ? array_keys( $items ) : SafeGrd_Site::COMPONENTS;
		}
		$components = array_values( array_intersect( SafeGrd_Site::COMPONENTS, array_merge( (array) $components, array_keys( $items ) ) ) );
		if ( ! $components ) {
			return new WP_Error( 'safegrd_restore', 'Choose at least one part of the site to restore.' );
		}
		if ( 'download' === $mode && 1 !== count( $components ) ) {
			return new WP_Error( 'safegrd_restore', 'A download is of one part of the site: database, plugins, themes, uploads or others.' );
		}
		if ( ! SafeGrd_Settings::connected() ) {
			return new WP_Error( 'safegrd_restore', 'Connect this site to SafeGrd first.' );
		}
		if ( self::job() ) {
			return new WP_Error( 'safegrd_restore', 'A restore or a download is already under way on this site. Start this one when it finishes.' );
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
				'components'  => $components,
				'items'       => $items,
				'mode'        => 'download' === $mode ? 'download' : 'restore',
				'out_dir'     => 'safegrd-download-' . bin2hex( random_bytes( 8 ) ),
				'out_bytes'   => 0,
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

	/**
	 * The plugins, themes and tables asked for, checked: part => unique
	 * names, with parts that name none left out.
	 *
	 * @param array $items   part => names, or a comma-separated string of names.
	 * @param bool  $restore Whether they are restored here, which this plugin never is.
	 * @return array|WP_Error
	 */
	public static function check_items( array $items, $restore = true ) {
		$out = array();
		foreach ( $items as $part => $names ) {
			if ( ! in_array( $part, self::ITEM_PARTS, true ) ) {
				return new WP_Error( 'safegrd_restore', sprintf( '%s cannot be restored item by item. Choose plugins, themes or tables.', $part ) );
			}
			$names = is_array( $names ) ? array_filter( $names, 'is_string' ) : explode( ',', (string) $names );
			$names = array_values( array_unique( array_filter( array_map( 'trim', $names ), 'strlen' ) ) );
			if ( ! $names ) {
				continue;
			}
			foreach ( $names as $n ) {
				$ok = 'database' === $part
					? preg_match( '/^[A-Za-z0-9_$-]{1,64}$/', $n )
					: preg_match( '/^[A-Za-z0-9_][A-Za-z0-9._ -]{0,199}$/', $n ) && false === strpos( $n, '..' );
				if ( ! $ok ) {
					return new WP_Error( 'safegrd_restore', sprintf( '"%s" is not the name of a %s.', $n, 'database' === $part ? 'table' : substr( $part, 0, -1 ) ) );
				}
				if ( $restore && 'plugins' === $part && basename( dirname( SAFEGRD_FILE ) ) === $n ) {
					return new WP_Error( 'safegrd_restore', 'SafeGrd Backup stays the version running, so it is never restored from a backup.' );
				}
			}
			$out[ $part ] = $names;
		}
		return $out;
	}

	/**
	 * What a restore or download takes, in words, or '' for all of a backup:
	 * "plugins (akismet), database (wp_posts, wp_postmeta), uploads".
	 */
	public static function describe_parts( array $components, array $items = array() ) {
		if ( count( $components ) >= count( SafeGrd_Site::COMPONENTS ) && ! $items ) {
			return '';
		}
		$out = array();
		foreach ( $components as $c ) {
			$out[] = empty( $items[ $c ] ) ? $c : sprintf( '%s (%s)', 'database' === $c ? 'tables' : $c, implode( ', ', $items[ $c ] ) );
		}
		return implode( ', ', $out );
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
			return $this->progress( $this->downloading() ? 'ready' : 'restored' );
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
			// Without the database there are no tables to load or check.
			$this->job['stage'] = $this->restores( 'database' ) ? 'database' : 'files';
			$this->save();
			if ( $this->out_of_time() ) {
				return false;
			}
		}
		$this->open_reader();
		if ( $this->downloading() ) {
			if ( ! $this->write_archive() ) {
				return false;
			}
			$this->ready();
			return true;
		}
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
		if ( is_array( $this->job ) && ! empty( $this->job['id'] ) ) {
			SafeGrd_Log::add( 'restore-' . $this->job['id'], $this->downloading() ? 'download' : 'restore', $this->job['snapshot_id'], $line );
		}
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
		list( $rec, $snap ) = $this->open_snapshot();
		$this->reader->prefetch_trees();
		$entries = $this->reader->walk( $snap['root_tree'] );

		// The content root of the trees, as SafeGrd recorded it.
		$lines = array();
		foreach ( $entries as list( $path, $e ) ) {
			$lines[ $path ] = 'dir' === $e['type'] ? array( 'd', '-' ) : array( 'f', $e['sha256'] );
		}
		if ( SafeGrd_Repo_Format::content_root( $lines ) !== ( $rec['sha256_checksum'] ?? '' ) ) {
			throw new SafeGrd_Exception( esc_html( 'The backup in storage does not match what SafeGrd recorded when it was taken. Nothing was restored.' ), 'other' );
		}
		$this->plan_entries( $rec, $entries );
	}

	/**
	 * SafeGrd's record of the snapshot and the snapshot object itself, with
	 * the reader open on its month and every index it names loaded.
	 *
	 * @return array{0:array,1:array}
	 */
	private function open_snapshot() {
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
		return array( $rec, $snap );
	}

	/**
	 * Writes down every file of the snapshot this job takes, in the order it
	 * writes them: the dump's parts, then the files.
	 *
	 * @param array $rec     SafeGrd's record of the snapshot.
	 * @param array $entries Every path of the snapshot's trees, as walk() lists them.
	 */
	private function plan_entries( array $rec, array $entries ) {
		global $wpdb;
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
		$this->job['uploads_path'] = $manifest['wordpress']['uploads_path'] ?? 'wp-content/uploads';
		$this->job['components_size'] = $manifest['components'] ?? array();
		$content                      = $this->source_content_path();
		// A download is the backup's own copy of everything it names.
		$own   = $this->downloading() ? "\0" : 'files/' . $content . '/plugins/' . basename( dirname( SAFEGRD_FILE ) ) . '/';
		$items = (array) ( $this->job['items'] ?? array() );
		$found = array();
		$files = array_values(
			array_filter(
				$files,
				function ( $f ) use ( $content, $own, $items, &$found ) {
					// This plugin stays the version running now: the backup's
					// copy could be older than the code doing the restore.
					$c = SafeGrd_Site::component( $f[0], $content, $this->job['uploads_path'] );
					if ( 0 === strpos( $f[0], $own ) || ! $this->restores( $c ) ) {
						return false;
					}
					if ( empty( $items[ $c ] ) ) {
						return true;
					}
					// One plugin or theme: its directory, or a plugin's single file.
					$item = strtok( substr( $f[0], strlen( 'files/' . $content . '/' . $c . '/' ) ), '/' );
					if ( ! in_array( $item, $items[ $c ], true ) ) {
						return false;
					}
					$found[ $c ][ $item ] = true;
					return true;
				}
			)
		);
		foreach ( array( 'plugins', 'themes' ) as $c ) {
			foreach ( $items[ $c ] ?? array() as $item ) {
				if ( empty( $found[ $c ][ $item ] ) ) {
					throw new SafeGrd_Exception( esc_html( sprintf( 'This backup holds no %s named %s. wp safegrd contents %s lists the plugins and themes it holds.', substr( $c, 0, -1 ), $item, $this->job['snapshot_id'] ) ), 'other' );
				}
			}
		}
		ksort( $parts );
		if ( array_keys( $parts ) !== range( 0, count( $parts ) - 1 ) ) {
			throw new SafeGrd_Exception( esc_html( 'A part of the backup\'s dump is missing.' ), 'other' );
		}

		if ( ! $this->restores( 'database' ) ) {
			$parts = array();
		} elseif ( ! empty( $items['database'] ) ) {
			list( $parts, $manifest['table_stats'] ) = $this->choose_tables( array_values( $parts ), (array) $manifest['table_stats'], $manifest['wordpress']['table_prefix'] ?? '', $items['database'] );
			$manifest['total_rows'] = array_sum( array_map( 'intval', array_column( $manifest['table_stats'], 'row_count' ) ) );
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
		$this->job['source_node']   = $rec['node_id'];
		$this->job['taken_at']      = $rec['created_at'] ?? '';
		$this->job['target_url']    = home_url();
		$this->job['target_prefix'] = $wpdb->prefix;

		if ( $this->downloading() ) {
			$this->open_out_dir();
		} else {
			$this->drop_leftovers();
			$staging = $this->content_dir() . '/' . $this->job['staging'];
			if ( ! wp_mkdir_p( $staging ) ) {
				throw new SafeGrd_Exception( esc_html( 'Could not create ' . $staging . ' to stage the files: the content directory is not writable.' ), 'other' );
			}
		}
		$this->say( sprintf( 'Snapshot of %s, taken %s: %d tables, %d files', $wp['site_url'], $this->job['taken_at'], count( $manifest['table_stats'] ), count( $files ) ) );
		$only = self::describe_parts( $this->job['components'], (array) ( $this->job['items'] ?? array() ) );
		if ( '' !== $only ) {
			$this->say( ( $this->downloading() ? 'Writing only: ' : 'Restoring only: ' ) . $only );
		}
	}

	/** Whether this restore takes the part of the site named. */
	private function restores( $component ) {
		return in_array( $component, $this->job['components'] ?? SafeGrd_Site::COMPONENTS, true );
	}

	/** Whether this restore takes only some plugins or some themes: plugins or themes. */
	private function by_item( $component ) {
		return in_array( $component, array( 'plugins', 'themes' ), true ) && ! empty( $this->job['items'][ $component ] );
	}

	/**
	 * Whether this restore takes the backup's table with this suffix, the
	 * name after the table prefix: every table, unless some were chosen.
	 */
	private function has_table( $suffix ) {
		if ( ! $this->restores( 'database' ) ) {
			return false;
		}
		$only = $this->job['tables_only'] ?? null;
		return null === $only || in_array( $this->job['source_prefix'] . $suffix, $only, true );
	}

	/**
	 * The dump's parts and the manifest's tables, cut down to the tables
	 * chosen. The dump writes a header part, one part per table in the
	 * manifest's order, and a footer part, so a table's part is found by its
	 * place; load_database() checks each chosen part names its table.
	 *
	 * @param array  $parts  The dump's parts in order, as [path, entry].
	 * @param array  $stats  The manifest's table_stats.
	 * @param string $prefix The backup's table prefix.
	 * @param array  $want   Table names, with or without the prefix.
	 * @return array{0:array,1:array} The parts and the table_stats to load.
	 */
	private function choose_tables( array $parts, array $stats, $prefix, array $want ) {
		if ( count( $parts ) !== count( $stats ) + 2 ) {
			throw new SafeGrd_Exception( esc_html( 'This backup\'s dump is not written one table at a time, so its tables cannot be restored on their own. Restore the whole database.' ), 'other' );
		}
		$names = array_column( $stats, 'table_name' );
		$chose = array();
		foreach ( $want as $w ) {
			if ( in_array( $w, $names, true ) ) {
				$chose[ $w ] = true;
			} elseif ( in_array( $prefix . $w, $names, true ) ) {
				$chose[ $prefix . $w ] = true;
			} else {
				throw new SafeGrd_Exception( esc_html( sprintf( 'This backup holds no table named %s. wp safegrd contents %s lists its tables.', $w, $this->job['snapshot_id'] ) ), 'other' );
			}
		}
		$keep_parts = array( $parts[0] );
		$keep_stats = array();
		foreach ( $stats as $i => $t ) {
			if ( isset( $chose[ $t['table_name'] ] ) ) {
				$keep_parts[] = $parts[ $i + 1 ];
				$keep_stats[] = $t;
			}
		}
		$keep_parts[]             = $parts[ count( $parts ) - 1 ];
		$this->job['tables_only'] = array_keys( $chose );
		return array( $keep_parts, $keep_stats );
	}

	/** Whether this job writes a download instead of restoring. */
	private function downloading() {
		return 'download' === ( $this->job['mode'] ?? 'restore' );
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
		$only = $this->job['tables_only'] ?? null;
		$sql  = preg_replace_callback(
			'/^((?:--[^\n]*\n|\s)*)(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `((?:[^`]|``)+)`/',
			function ( $m ) use ( $from, $tmp, $only ) {
				$name = str_replace( '``', '`', $m[3] );
				if ( 0 !== strpos( $name, $from ) ) {
					throw new SafeGrd_Exception( esc_html( 'The dump names a table outside the site\'s prefix: ' . $name ), 'other' );
				}
				if ( null !== $only && ! in_array( $name, $only, true ) ) {
					throw new SafeGrd_Exception( esc_html( sprintf( 'A part of the dump chosen for %s holds %s. Restore the whole database instead.', implode( ', ', $only ), $name ) ), 'other' );
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
					? sprintf( 'Tables renamed from no prefix to %s.', $dst )
					: sprintf( 'Tables renamed from the prefix %s to %s.', $src, $dst );
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
			$this->job['notes'][] = sprintf( 'Search and replace: %s to %s, serialized data included.', $from, $to );
		}
		foreach ( $this->has_table( 'options' ) ? array( 'siteurl' => site_url(), 'home' => home_url() ) : array() as $name => $value ) {
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
		// Only the tables this restore loads: some may have been chosen.
		$options  = $this->has_table( 'options' );
		$usermeta = $this->has_table( 'usermeta' );
		if ( '' === $src ) {
			if ( $options ) {
				$db->query( "UPDATE `{$tmp}options` SET option_name = CONCAT('$to', option_name) WHERE option_name = 'user_roles'" );
			}
			if ( $usermeta ) {
				$db->query( "UPDATE `{$tmp}usermeta` SET meta_key = CONCAT('$to', meta_key) WHERE meta_key IN ('capabilities', 'user_level', 'user-settings', 'user-settings-time', 'dashboard_quick_press_last_post_id')" );
			}
			return;
		}
		$like = $db->real_escape_string( addcslashes( $src, '_%\\' ) ) . '%';
		$from = strlen( $src ) + 1;
		$sqls = array();
		if ( $options ) {
			$sqls[] = "UPDATE `{$tmp}options` SET option_name = CONCAT('$to', SUBSTRING(option_name, $from)) WHERE option_name LIKE '$like'";
		}
		if ( $usermeta ) {
			$sqls[] = "UPDATE `{$tmp}usermeta` SET meta_key = CONCAT('$to', SUBSTRING(meta_key, $from)) WHERE meta_key LIKE '$like'";
		}
		foreach ( $sqls as $sql ) {
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
		$last    = $this->job['parts'] + $this->job['total_files'];
		$fetched = $this->job['seq'];
		$first   = $this->job['seq'];
		for ( ; $this->job['seq'] < $last; $this->job['seq']++ ) {
			// At least one file a slice, so a slice whose setup took its
			// whole time still moves the restore on.
			if ( $this->job['seq'] > $first && $this->out_of_time() ) {
				return false;
			}
			if ( $this->job['seq'] >= $fetched ) {
				$fetched = $this->prefetch_from( $this->job['seq'], $last );
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

	/**
	 * Fetches the blobs of the files from $seq on, up to 24 MB or 300
	 * files, in as few requests as their places in the packs allow.
	 *
	 * @return int The first seq not fetched ahead.
	 */
	private function prefetch_from( $seq, $last ) {
		$ids   = array();
		$bytes = 0;
		$end   = $seq;
		for ( ; $end < $last && $end - $seq < 300 && $bytes < 25165824; $end++ ) {
			$row = $this->row( $end );
			if ( '' !== $row['content'] ) {
				$ids = array_merge( $ids, explode( ',', $row['content'] ) );
			}
			$bytes += (int) $row['size'];
		}
		// Half of what is left of the slice at most, so it still writes files.
		$deadline = $this->deadline > 0 ? microtime( true ) + ( $this->deadline - microtime( true ) ) / 2 : 0;
		$this->reader->prefetch( $ids, $deadline );
		return $end;
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

		// Before anything changes: every directory the swap moves must be one
		// PHP can move. One it cannot would stop the swap half way.
		$this->check_movable( $content );

		// This plugin's own rows go into the restored options table, so the
		// site stays connected and the restore can record that it finished.
		$this->job['stage'] = 'done';
		$this->save();
		$tables = $this->restores( 'database' );
		$own    = ! $this->has_table( 'options' ) ? false : $db->query( "SELECT option_name, option_value, autoload FROM `{$live}options` WHERE option_name LIKE 'safegrd\\_%' OR option_name LIKE '\\_transient\\_safegrd\\_%' OR option_name LIKE '\\_transient\\_timeout\\_safegrd\\_%'" );
		while ( $own && ( $o = $own->fetch_row() ) ) {
			$stmt = $db->prepare( "REPLACE INTO `{$tmp}options` (option_name, option_value, autoload) VALUES (?, ?, ?)" );
			$stmt->bind_param( 'sss', $o[0], $o[1], $o[2] );
			$stmt->execute();
			$stmt->close();
		}

		$maintenance = ABSPATH . '.maintenance';
		$maint       = @file_put_contents( $maintenance, '<?php $upgrading = ' . time() . ';' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- WordPress reads .maintenance from ABSPATH, as core updates write it; best effort, the swap is quick either way.

		try {
			$this->swap_in( $db, $tables, $tmp, $aside, $live, $src, $content );
		} finally {
			// Whatever happened, the site does not stay in maintenance mode.
			if ( $maint && file_exists( $maintenance ) ) {
				wp_delete_file( $maintenance );
			}
		}
		$this->finish_swap( $tables, $aside );
	}

	/**
	 * Refuses a swap PHP could not finish: a directory of the content
	 * directory it would move but may not, as on a host where the files
	 * belong to another user than the one PHP runs as.
	 */
	private function check_movable( $content ) {
		$stage = $content . '/' . $this->job['staging'];
		$check = array( $content );
		foreach ( (array) scandir( $stage ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( $this->by_item( $name ) ) {
				// Only the chosen plugins or themes move, inside the live directory.
				$check[] = $content . '/' . $name;
				foreach ( self::children( $stage . '/' . $name ) as $item ) {
					$check[] = $stage . '/' . $name . '/' . $item;
					if ( file_exists( $content . '/' . $name . '/' . $item ) ) {
						$check[] = $content . '/' . $name . '/' . $item;
					}
				}
				continue;
			}
			$check[] = $stage . '/' . $name;
			if ( 'mu-plugins' === $name ) {
				$check[] = $content . '/mu-plugins';
			} elseif ( file_exists( $content . '/' . $name ) ) {
				$check[] = $content . '/' . $name;
			}
			if ( 'plugins' === $name && is_dir( $content . '/plugins/' . basename( dirname( SAFEGRD_FILE ) ) ) ) {
				$check[] = $content . '/plugins/' . basename( dirname( SAFEGRD_FILE ) );
			}
		}
		$who = function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ? ( posix_getpwuid( posix_geteuid() )['name'] ?? '' ) : '';
		foreach ( $check as $path ) {
			if ( ! wp_is_writable( $path ) ) {
				$owner = function_exists( 'posix_getpwuid' ) ? ( posix_getpwuid( (int) fileowner( $path ) )['name'] ?? '' ) : '';
				throw new SafeGrd_Exception(
					esc_html(
						sprintf(
							'PHP%s cannot move %s%s, so the restore stopped before changing anything. Make the content directory and what is in it writable by the web server, then restore again.',
							'' !== $who ? ' (running as ' . $who . ')' : '',
							substr( $path, strlen( dirname( $content ) ) + 1 ),
							'' !== $owner ? ', which belongs to ' . $owner : ''
						)
					),
					'other'
				);
			}
		}
	}

	/** The tables and the files, swapped in while the site is in maintenance. */
	private function swap_in( $db, $tables, $tmp, $aside, $live, $src, $content ) {
		$renames = array();
		foreach ( $tables ? $this->job['manifest']['tables'] : array() as $t ) {
			$suffix = substr( $t['table_name'], strlen( $src ) );
			$r      = $db->query( "SHOW TABLES LIKE '" . $db->real_escape_string( addcslashes( $live . $suffix, '_%\\' ) ) . "'" );
			if ( $r && $r->num_rows ) {
				$renames[] = "`{$live}{$suffix}` TO `{$aside}{$suffix}`";
			}
			$renames[] = "`{$tmp}{$suffix}` TO `{$live}{$suffix}`";
		}
		if ( $renames && false === $db->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
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
		// This plugin was left out of the staged plugins: the running copy
		// goes into them, so it stays in place when they are swapped in.
		$mine = basename( dirname( SAFEGRD_FILE ) );
		if ( ! $this->by_item( 'plugins' ) && is_dir( $stage . '/plugins' ) && is_dir( $content . '/plugins/' . $mine ) && ! file_exists( $stage . '/plugins/' . $mine ) ) {
			self::move( $content . '/plugins/' . $mine, $stage . '/plugins/' . $mine );
		}
		foreach ( (array) scandir( $stage ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( $this->by_item( $name ) ) {
				// Some plugins or themes: each replaces its own directory, and
				// every other one stays as it is.
				wp_mkdir_p( $keep . '/' . $name );
				wp_mkdir_p( $content . '/' . $name );
				foreach ( self::children( $stage . '/' . $name ) as $item ) {
					if ( file_exists( $content . '/' . $name . '/' . $item ) ) {
						self::move( $content . '/' . $name . '/' . $item, $keep . '/' . $name . '/' . $item );
					}
					self::move( $stage . '/' . $name . '/' . $item, $content . '/' . $name . '/' . $item );
				}
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
	}

	/** What a swapped-in restore leaves: caches cleared, the record, the log. */
	private function finish_swap( $tables, $aside ) {
		wp_cache_flush();
		delete_option( 'rewrite_rules' );
		SafeGrd_Scheduler::reschedule_after_restore();
		$items = (array) ( $this->job['items'] ?? array() );
		if ( ! empty( $items['plugins'] ) && ! $this->has_table( 'options' ) ) {
			$this->job['notes'][] = 'Plugins keep this site\'s settings and whether each is active. Activate a restored plugin under Plugins if it is not.';
		}
		// The site's users, and so the sign-in, are the backup's only when
		// its users table came with it.
		$users = $this->has_table( 'users' );
		$last  = array(
			'snapshot_id' => $this->job['snapshot_id'],
			'source_url'  => $this->job['source_url'],
			'taken_at'    => $this->job['taken_at'],
			'restored_at' => gmdate( 'c' ),
			'components'  => $this->job['components'] ?? SafeGrd_Site::COMPONENTS,
			'items'       => $items,
			'users'       => $users,
			'tables'      => $tables ? count( $this->job['manifest']['tables'] ) : 0,
			'rows'        => $tables ? $this->job['manifest']['rows'] : 0,
			'files'       => $this->job['files'],
			'aside'       => $aside,
			'aside_dir'   => $this->job['aside_dir'],
			'notes'       => $this->job['notes'],
			'wordpress'   => $this->job['source_wp'],
		);
		delete_option( self::JOB );
		update_option( self::LAST, $last, false );
		self::drop_tables();
		$what = self::describe_parts( $last['components'], $items );
		if ( $users ) {
			$this->say( 'Restored' . ( '' === $what ? '' : ' ' . $what ) . '. Sign in with an administrator account of the restored site.' );
		} else {
			$this->say( 'Restored ' . $what . '. ' . ( $tables ? 'The other tables and the sign-in are as they were.' : 'The database and the sign-in are as they were.' ) );
		}
		SafeGrd_Log::finish( 'restore-' . $this->job['id'], 'restore', $this->job['snapshot_id'], 'restored' );
	}

	// --- download -----------------------------------------------------------

	/**
	 * Makes the directory the download is written to: in the content
	 * directory, named so nobody can guess it, with nothing listed and,
	 * where Apache reads it, nothing served. The file is fetched through
	 * wp-admin only. Refused when the disk lacks room for the part.
	 */
	private function open_out_dir() {
		$dir = $this->content_dir() . '/' . $this->job['out_dir'];
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new SafeGrd_Exception( esc_html( 'Could not create ' . $dir . ' to write the download: the content directory is not writable.' ), 'other' );
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- files of the plugin's own directory, written in a background request where WP_Filesystem may need credentials.
		file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" );
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$c     = $this->job['components'][0];
		// A part's size is known; some of its items are smaller, and not checked.
		$need  = empty( $this->job['items'][ $c ] ) ? (int) ( $this->job['components_size'][ $c ]['bytes'] ?? 0 ) : 0;
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts disable it; the check is then skipped.
		if ( false !== $free && $need > 0 && $free < $need + 104857600 ) {
			throw new SafeGrd_Exception( esc_html( sprintf( 'This server has %s free and the %s of this backup are %s. Free some space, or download it with the safegrd command line tool on another machine.', size_format( $free ), $c, size_format( $need ) ) ), 'other' );
		}
		$host = preg_replace( '/[^A-Za-z0-9.-]+/', '-', (string) wp_parse_url( $this->job['source_url'], PHP_URL_HOST ) );
		$port = wp_parse_url( $this->job['source_url'], PHP_URL_PORT );
		$only = (array) ( $this->job['items'][ $c ] ?? array() );
		$what = ! $only ? $c : ( 'database' === $c ? 'tables' : $c ) . '-' . ( 1 === count( $only ) ? preg_replace( '/[^A-Za-z0-9._-]+/', '-', $only[0] ) : count( $only ) );
		$name = $host . ( $port ? '-' . $port : '' ) . '-' . gmdate( 'Ymd-Hi', strtotime( $this->job['taken_at'] ) ) . '-' . $what . ( 'database' === $c ? '.sql.gz' : '.tar.gz' );
		$this->job['out_name'] = $name;
		$this->say( sprintf( 'Writing %s', $name ) );
	}

	private function out_path() {
		return $this->content_dir() . '/' . $this->job['out_dir'] . '/' . $this->job['out_name'];
	}

	/**
	 * Writes the download from where the last slice stopped. The database
	 * is its dump, gzipped; files are a tar, gzipped. Each write appends a
	 * gzip member, which gunzip and tar read as one stream, and the job
	 * records the length after each whole file: a slice the host stopped
	 * part way is cut back to it.
	 *
	 * @return bool Whether the whole download is written.
	 */
	private function write_archive() {
		$path = $this->out_path();
		clearstatcache( true, $path );
		if ( is_file( $path ) && filesize( $path ) > $this->job['out_bytes'] ) {
			$fh = fopen( $path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			ftruncate( $fh, $this->job['out_bytes'] );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$out = fopen( $path, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			throw new SafeGrd_Exception( esc_html( 'Could not write ' . $path . '.' ), 'other' );
		}
		$tar     = 'database' !== $this->job['components'][0];
		$buf     = '';
		$flush   = function () use ( &$buf, $out ) {
			if ( '' !== $buf ) {
				fwrite( $out, gzencode( $buf, 6 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				$buf = '';
			}
		};
		$last    = $this->job['parts'] + $this->job['total_files'];
		$fetched = $this->job['seq'];
		$taken   = (int) strtotime( $this->job['taken_at'] );
		$first   = $this->job['seq'];
		try {
			for ( ; $this->job['seq'] < $last; $this->job['seq']++ ) {
				// At least one file a slice, so a slice whose setup took its
				// whole time still moves the download on.
				if ( $this->job['seq'] > $first && $this->out_of_time() ) {
					// Every file before this one is written: that is where the next slice starts.
					$flush();
					$this->mark_written( $out );
					return false;
				}
				if ( $this->job['seq'] >= $fetched ) {
					$fetched = $this->prefetch_from( $this->job['seq'], $last );
				}
				$row = $this->row( $this->job['seq'] );
				if ( $tar ) {
					$buf .= self::tar_header( substr( $row['path'], 6 ), (int) $row['size'], (int) $row['mode'] & 0777, $taken );
				}
				$h = hash_init( 'sha256' );
				foreach ( '' === $row['content'] ? array() : explode( ',', $row['content'] ) as $id ) {
					$chunk = $this->reader->blob( $id );
					hash_update( $h, $chunk );
					$buf .= $chunk;
					if ( strlen( $buf ) >= self::OUT_BUFFER ) {
						$flush();
					}
				}
				if ( hash_final( $h ) !== $row['sha256'] ) {
					throw new SafeGrd_Exception( esc_html( $row['path'] . ' does not match its SHA-256. The download was not written.' ), 'other' );
				}
				if ( $tar ) {
					$buf .= str_repeat( "\0", ( 512 - (int) $row['size'] % 512 ) % 512 );
					$this->job['files']++;
				}
				$this->job['bytes'] += (int) $row['size'];
				if ( strlen( $buf ) >= self::OUT_BUFFER ) {
					// A whole file more is written: record it, as the file after it.
					$flush();
					$this->job['seq']++;
					$this->mark_written( $out );
					$this->job['seq']--;
				}
			}
			if ( $tar ) {
				$buf .= str_repeat( "\0", 1024 ); // the end of the archive
			}
			$flush();
			$this->mark_written( $out );
			return true;
		} finally {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/** Records where the download stands: the next file, and its length so far. */
	private function mark_written( $out ) {
		fflush( $out );
		$this->job['out_bytes'] = (int) fstat( $out )['size'];
		$this->save();
	}

	/**
	 * A tar header for one regular file: ustar, with a pax record before it
	 * when the name or the size does not fit ustar's fields.
	 */
	public static function tar_header( $name, $size, $mode, $mtime ) {
		$pax = '';
		if ( strlen( $name ) > 100 ) {
			$pax .= self::pax_record( 'path', $name );
		}
		if ( $size > 077777777777 ) {
			$pax .= self::pax_record( 'size', (string) $size );
		}
		$out = '';
		if ( '' !== $pax ) {
			$out .= self::ustar( 'PaxHeader/' . substr( basename( $name ), 0, 80 ), strlen( $pax ), 0644, $mtime, 'x' );
			$out .= $pax . str_repeat( "\0", ( 512 - strlen( $pax ) % 512 ) % 512 );
		}
		return $out . self::ustar( substr( $name, 0, 100 ), min( $size, 077777777777 ), $mode ? $mode : 0644, $mtime, '0' );
	}

	private static function pax_record( $key, $value ) {
		$body = ' ' . $key . '=' . $value . "\n";
		$len  = strlen( $body ) + 1;
		while ( strlen( $len . $body ) !== $len ) {
			$len = strlen( $len . $body );
		}
		return $len . $body;
	}

	private static function ustar( $name, $size, $mode, $mtime, $type ) {
		$h  = str_pad( $name, 100, "\0" );
		$h .= sprintf( '%07o', $mode ) . "\0";
		$h .= sprintf( '%07o', 0 ) . "\0";
		$h .= sprintf( '%07o', 0 ) . "\0";
		$h .= sprintf( '%011o', $size ) . "\0";
		$h .= sprintf( '%011o', max( 0, $mtime ) ) . "\0";
		$h .= '        '; // the checksum, as spaces while it is summed
		$h .= $type;
		$h .= str_repeat( "\0", 100 );
		$h .= "ustar\0" . '00';
		$h .= str_pad( 'www-data', 32, "\0" ) . str_pad( 'www-data', 32, "\0" );
		$h .= str_repeat( "\0", 8 ) . str_repeat( "\0", 8 );
		$h .= str_repeat( "\0", 155 ) . str_repeat( "\0", 12 );
		$sum = 0;
		for ( $i = 0; $i < 512; $i++ ) {
			$sum += ord( $h[ $i ] );
		}
		return substr_replace( $h, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}

	/** The download is written: listed for fetching, and the job ends. */
	private function ready() {
		$path      = $this->out_path();
		$id        = substr( $this->job['out_dir'], strlen( 'safegrd-download-' ) );
		$downloads = self::downloads();
		$downloads = array(
			$id => array(
				'dir'         => $this->job['out_dir'],
				'name'        => $this->job['out_name'],
				'snapshot_id' => $this->job['snapshot_id'],
				'source_url'  => $this->job['source_url'],
				'taken_at'    => $this->job['taken_at'],
				'component'   => $this->job['components'][0],
				'bytes'       => (int) filesize( $path ),
				'created'     => time(),
				'expires'     => time() + self::DOWNLOAD_TTL,
			),
		) + $downloads;
		update_option( self::DOWNLOADS, $downloads, false );
		wp_schedule_single_event( time() + self::DOWNLOAD_TTL + 60, 'safegrd_expire_downloads' );
		delete_option( self::JOB );
		self::drop_tables();
		$this->say( sprintf( 'Ready: %s, %s. It is deleted from this server %s.', $this->job['out_name'], size_format( (int) filesize( $path ), 1 ), wp_date( 'Y-m-d H:i', time() + self::DOWNLOAD_TTL ) ) );
		SafeGrd_Log::finish( 'restore-' . $this->job['id'], 'download', $this->job['snapshot_id'], 'ready' );
	}

	/**
	 * Downloads still on the server, newest first. Expired ones are deleted
	 * on the way.
	 *
	 * @return array id => {dir, name, snapshot_id, source_url, taken_at, component, bytes, created, expires}
	 */
	public static function downloads() {
		$all = get_option( self::DOWNLOADS, array() );
		$all = is_array( $all ) ? $all : array();
		$now = time();
		$out = array();
		foreach ( $all as $id => $d ) {
			if ( (int) $d['expires'] <= $now ) {
				self::rmdir( rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/' . $d['dir'] );
				continue;
			}
			$out[ $id ] = $d;
		}
		if ( count( $out ) !== count( $all ) ) {
			update_option( self::DOWNLOADS, $out, false );
		}
		return $out;
	}

	/** Deletes one download now. */
	public static function delete_download( $id ) {
		$all = self::downloads();
		if ( isset( $all[ $id ] ) ) {
			self::rmdir( rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/' . $all[ $id ]['dir'] );
			unset( $all[ $id ] );
			update_option( self::DOWNLOADS, $all, false );
		}
	}

	/**
	 * The file of a download, by its id: the path on disk and the name to
	 * save it as, or null. The id is all the request names; the path comes
	 * from the plugin's own record.
	 *
	 * @return array{path:string,name:string}|null
	 */
	public static function download_file( $id ) {
		$all = self::downloads();
		if ( ! preg_match( '/^[0-9a-f]{16}$/', (string) $id ) || ! isset( $all[ $id ] ) ) {
			return null;
		}
		$path = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/' . $all[ $id ]['dir'] . '/' . $all[ $id ]['name'];
		return is_file( $path ) ? array(
			'path' => $path,
			'name' => $all[ $id ]['name'],
		) : null;
	}

	// --- outcomes -----------------------------------------------------------

	private function progress( $status ) {
		if ( 'ready' === $status ) {
			$d = self::downloads();
			$k = substr( $this->job['out_dir'], strlen( 'safegrd-download-' ) );
			return array_merge(
				array(
					'status' => 'ready',
					'id'     => $k,
				),
				isset( $d[ $k ] ) ? $d[ $k ] : array()
			);
		}
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
		$download = 'download' === ( $job['mode'] ?? 'restore' );
		if ( ! empty( $job['id'] ) ) {
			SafeGrd_Log::finish( 'restore-' . $job['id'], $download ? 'download' : 'restore', $job['snapshot_id'], 'failed' );
		}
		delete_option( self::JOB );
		if ( $download ) {
			SafeGrd_Scheduler::continue_cancel();
			self::rmdir( $this->content_dir() . '/' . $job['out_dir'] );
			self::drop_tables();
			update_option( 'safegrd_download_failed', array( 'snapshot_id' => $job['snapshot_id'], 'component' => $job['components'][0] ?? '', 'message' => $message, 'at' => time() ), false );
			return array(
				'status'  => 'failed',
				'message' => $message,
			);
		}
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

	/** The names in a directory, without . and .. */
	private static function children( $dir ) {
		return array_values( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) );
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
	 * What a backup holds, from its manifest: the plugins and themes with
	 * their versions beside the ones installed on this site, and the tables
	 * with their rows. The manifest is read from storage once and kept for a
	 * day, as a backup does not change.
	 *
	 * @param string $snapshot_id The snapshot.
	 * @return array|WP_Error {plugins, themes: [{slug, name, version, active, installed}], tables: [{name, rows, bytes}], prefix}
	 */
	public static function contents( $snapshot_id ) {
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', (string) $snapshot_id ) ) {
			return new WP_Error( 'safegrd_restore', 'That is not a snapshot id.' );
		}
		$key      = 'safegrd_contents_' . md5( $snapshot_id );
		$manifest = get_transient( $key );
		if ( ! is_array( $manifest ) ) {
			try {
				$job         = new self();
				$job->client = SafeGrd_Client::for_site();
				$job->job    = array( 'snapshot_id' => $snapshot_id );
				$snap        = $job->open_snapshot()[1];
				$man         = null;
				foreach ( $job->reader->tree( $snap['root_tree'] ) as $e ) {
					if ( 'file' === ( $e['type'] ?? '' ) && 'manifest.json' === ( $e['name'] ?? '' ) ) {
						$man = $e;
					}
				}
				$read = $man ? json_decode( $job->read_file( $man ), true ) : null;
				if ( ! is_array( $read ) ) {
					throw new SafeGrd_Exception( esc_html( 'This backup holds no manifest that parses.' ), 'other' );
				}
			} catch ( Throwable $e ) {
				return new WP_Error( 'safegrd_restore', SafeGrd_Exception::text( $e ) );
			}
			$manifest = array(
				'components' => (array) ( $read['components'] ?? array() ),
				'tables'     => (array) ( $read['table_stats'] ?? array() ),
				'prefix'     => (string) ( $read['wordpress']['table_prefix'] ?? '' ),
			);
			set_transient( $key, $manifest, DAY_IN_SECONDS );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array();
		foreach ( get_plugins() as $file => $p ) {
			$installed['plugins'][ strtok( $file, '/' ) ] = (string) $p['Version'];
		}
		foreach ( wp_get_themes() as $slug => $t ) {
			$installed['themes'][ (string) $slug ] = (string) $t->get( 'Version' );
		}
		$out = array(
			'plugins' => array(),
			'themes'  => array(),
			'tables'  => array(),
			'prefix'  => $manifest['prefix'],
		);
		foreach ( array( 'plugins', 'themes' ) as $c ) {
			foreach ( (array) ( $manifest['components'][ $c ]['items'] ?? array() ) as $it ) {
				$slug = 'plugins' === $c ? strtok( (string) $it['file'], '/' ) : (string) $it['slug'];
				if ( 'plugins' === $c && basename( dirname( SAFEGRD_FILE ) ) === $slug ) {
					continue; // never restored from a backup
				}
				$out[ $c ][] = array(
					'slug'      => $slug,
					'name'      => (string) $it['name'],
					'version'   => (string) $it['version'],
					'active'    => ! empty( $it['active'] ),
					'installed' => $installed[ $c ][ $slug ] ?? null,
				);
			}
		}
		foreach ( $manifest['tables'] as $t ) {
			$out['tables'][] = array(
				'name'  => (string) $t['table_name'],
				'rows'  => (int) $t['row_count'],
				'bytes' => (int) ( $t['size_bytes'] ?? 0 ),
			);
		}
		return $out;
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
