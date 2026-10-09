<?php
/**
 * One backup of the site into its incremental repository, run in slices: each
 * request does a few seconds of work, saves where it got to, and asks WP-Cron
 * for the next. A host that stops long requests stops a slice, not the
 * backup; the next slice picks up from the last saved point.
 *
 * Stages:
 *   database  the whole dump, in one consistent snapshot, in one slice
 *   files     wp-config.php, .htaccess and the content directory, resumable
 *   finish    trees, the manifest, the index, catalog and snapshot, commit
 *
 * Nothing secret is saved between slices: each pack is closed and uploaded
 * before a slice ends, and its key is gone with it. What carries over is
 * which files are done and the hashes of what the epoch holds.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Backup {
	const LOCK       = 'safegrd_backup_lock';
	const JOB        = 'safegrd_job';
	/** Set by Back up now: the next slice may start a backup. */
	const REQUESTED  = 'safegrd_backup_requested';
	const REPO       = 'safegrd_repo';
	const SURFACE_ID = 'wordpress';
	const READ_BYTES = 1048576;
	/** A slice that fails on storage is tried this many times before the run fails. */
	const ATTEMPTS = 3;

	/**
	 * Directories under the content directory that are not the site:
	 * caches, upgrade scratch space, and other backup plugins' archives.
	 */
	const EXCLUDE_DIRS = array(
		'cache',
		'upgrade',
		'upgrade-temp-backup',
		'updraft',
		'ai1wm-backups',
		'backups-dup-lite',
		'backups-dup-pro',
		'wpvivid_backups',
		'wpvividbackups',
		'backup-db',
		'backupwordpress',
		'wflogs',
		'et-cache',
	);

	/** @var callable|null */
	private $say;
	/** @var float Seconds this slice may run; 0 for no limit. */
	private $budget;
	private $deadline = 0;
	/** @var array|null */
	private $job;
	/** @var SafeGrd_Client|null */
	private $client;
	/** @var SafeGrd_Repo_Client */
	private $repo;
	/** @var SafeGrd_Pack|null */
	private $pack;
	/** @var array Rows waiting for their blobs' pack to be stored. */
	private $rows = array();
	/** @var array Blob ids in the open pack. */
	private $in_pack = array();
	/** @var array|null The file being written. */
	private $file;

	/**
	 * @param callable|null $say    Receives one line of progress at a time.
	 * @param int|null      $budget Seconds a slice may run; null decides from the host's limit.
	 */
	/**
	 * Whether a slice that leaves work starts the next in a request of its
	 * own. WP-CLI runs its slices one after another itself, and a loopback
	 * would only compete with it for the lock.
	 *
	 * @var bool
	 */
	public $chain = true;

	public function __construct( $say = null, $budget = null ) {
		$this->say    = $say;
		$this->budget = null === $budget ? self::default_budget() : (float) $budget;
	}

	/**
	 * Seconds one slice runs: SAFEGRD_SLICE_SECONDS when set, else a third
	 * of the host's time limit, between 5 and 25 seconds. No limit from the
	 * command line, where PHP has none.
	 */
	public static function default_budget() {
		if ( defined( 'SAFEGRD_SLICE_SECONDS' ) ) {
			return max( 0.0, (float) SAFEGRD_SLICE_SECONDS );
		}
		$limit = (int) ini_get( 'max_execution_time' );
		if ( 'cli' === PHP_SAPI && 0 === $limit ) {
			return 0;
		}
		if ( $limit <= 0 ) {
			return 25;
		}
		return max( 5, min( 25, (int) floor( $limit / 3 ) ) );
	}

	/**
	 * Runs one slice: continues the backup under way, or starts one when none
	 * is and starting is allowed (the daily run, or Back up now).
	 *
	 * @param bool $may_start Whether this slice may start a new backup.
	 * @return array The run as recorded: status running, completed, failed, busy or idle.
	 */
	public function run( $may_start = true ) {
		if ( ! $may_start && ! is_array( get_option( self::JOB, null ) ) && ! get_option( self::REQUESTED, false ) ) {
			return array( 'status' => 'idle' );
		}
		if ( SafeGrd_Restore::job() ) {
			return array(
				'status'  => 'busy',
				'message' => 'A restore of this site is under way. Backups start again when it finishes.',
			);
		}
		if ( ! SafeGrd_Settings::connected() ) {
			return $this->record_failure( 'This site is not connected to SafeGrd. Connect it under Tools, SafeGrd.', 'config', '' );
		}
		$refusal = SafeGrd_Site::refusal();
		if ( '' !== $refusal ) {
			return $this->record_failure( $refusal, 'config', '' );
		}
		if ( ! $this->lock() ) {
			// The slice holding the lock schedules the next when it ends. If
			// the host stopped it, nothing will: ask again once its lock is
			// stale, so the run does not wait for tomorrow's backup.
			if ( is_array( get_option( self::JOB, null ) ) ) {
				SafeGrd_Scheduler::continue_later( $this->stale_after() + 5 );
			}
			return array(
				'status'  => 'busy',
				'message' => 'A slice of this site\'s backup is running now.',
			);
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0.0 === $this->budget ? 0 : (int) ceil( $this->budget * 3 + 60 ) ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- each slice sets its own limit; some hosts disable the function, and the slice budget still holds.
		}
		ignore_user_abort( true );
		wp_raise_memory_limit( 'admin' );
		$this->deadline = 0.0 === $this->budget ? 0 : microtime( true ) + $this->budget;
		SafeGrd_Repo_Cache::install();
		$this->client = SafeGrd_Client::for_site();
		$this->repo   = new SafeGrd_Repo_Client( $this->client, SafeGrd_Settings::get( 'node_id' ) );

		$next = false;
		try {
			$job = get_option( self::JOB, null );
			if ( is_array( $job ) ) {
				$this->job = $job;
				$doing = array(
					'database' => 'dumping the database',
					'files'    => 'reading the files',
					'finish'   => 'writing the snapshot',
				);
				$this->say( sprintf( 'Continuing %s, %s', $this->job['snapshot_id'], $doing[ $this->job['stage'] ] ?? $this->job['stage'] ) );
			} else {
				$this->start();
			}
			// A watchdog: if the host stops this slice, the next starts anyway.
			SafeGrd_Scheduler::continue_later( 0.0 === $this->budget ? HOUR_IN_SECONDS : (int) ceil( $this->budget * 3 + 60 ) );
			if ( ! $this->work() ) {
				$this->checkpoint();
				$this->job['attempts'] = 0;
				$this->save_job();
				$next = true;
				$run  = $this->progress( 'running' );
				SafeGrd_Settings::record_run( $run );
				return $run;
			}
			$run = $this->finish();
			SafeGrd_Log::finish( $run['snapshot_id'], 'backup', $run['snapshot_id'], 'completed' );
			delete_option( self::JOB );
			SafeGrd_Scheduler::continue_cancel();
			SafeGrd_Settings::record_run( $run );
			return $run;
		} catch ( Throwable $e ) {
			return $this->slice_failed( $e );
		} finally {
			$this->unlock();
			if ( $next && $this->chain ) {
				// After the unlock, so the next slice finds the lock free.
				SafeGrd_Scheduler::continue_now();
			}
		}
	}

	/**
	 * Opens the epoch and the job of a new backup.
	 */
	private function start() {
		delete_option( self::REQUESTED );
		$node_id = SafeGrd_Settings::get( 'node_id' );
		$info    = $this->client->call( 'GET', '/api/v1/nodes/' . rawurlencode( $node_id ) . '/hosted' );
		if ( is_wp_error( $info ) ) {
			throw new SafeGrd_Exception( esc_html( 'Hosted storage: ' . $info->get_error_message() ), 'storage' );
		}
		if ( ! empty( $info['warning'] ) ) {
			$this->say( 'Warning: ' . $info['warning'] );
		}
		$view  = $this->open_epoch( $node_id, $info );
		$epoch = $view['epoch'];
		$now   = time();
		$days  = max( 1, (int) ( $info['retention_days'] ?? 1 ) );
		$class = empty( $view['opening_done'] ) ? 'opening' : 'later';
		$lock  = strtotime( 'opening' === $class ? $epoch['opening_retain_until'] : $epoch['later_retain_until'] );
		$this->job = array(
			'snapshot_id' => 'snap-' . gmdate( 'Ymd-His', $now ) . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 ),
			'run_id'      => bin2hex( random_bytes( 16 ) ),
			'epoch_id'    => $epoch['epoch_id'],
			'class'       => $class,
			'recipient'   => $epoch['recipient'],
			'storage_uri' => $view['storage_uri'] ?? '',
			'worm_mode'   => $info['worm_mode'] ?? '',
			'retain'      => min( $now + $days * DAY_IN_SECONDS, $lock ),
			// What Object Lock holds the run's objects to: its epoch's lock
			// for this class, which outlasts the plan's retention. This is
			// the date to show, as the server records it; retain is when the
			// snapshot itself expires.
			'locked'      => $lock,
			'started'     => $now,
			'stage'       => 'database',
			'cursor'      => null,
			'attempts'    => 0,
			'files'       => 0,
			'file_bytes'  => 0,
			'logical'     => 0,
			'new_bytes'   => 0,
			'new_packs'   => 0,
			'skipped'     => array(),
			'db'          => null,
			'slices'      => 0,
		);
		// Packs an earlier run uploaded and never committed are this run's
		// now: its index lists them, so a snapshot can name them.
		SafeGrd_Repo_Cache::adopt( $this->job['epoch_id'], $this->job['run_id'] );
		$this->say(
			! empty( $view['new'] )
				? sprintf( 'Epoch %s opened: every file is uploaded once this month.', $this->job['epoch_id'] )
				: sprintf( 'Incremental, in epoch %s: only what changed is uploaded.', $this->job['epoch_id'] )
		);
		$this->save_job();
		SafeGrd_Settings::record_run( $this->progress( 'running' ) );
	}

	/**
	 * Asks the server for this month's epoch: the one this site's cache holds
	 * when the server still writes into it, else a new one.
	 */
	private function open_epoch( $node_id, array $info ) {
		$state = get_option( self::REPO, array() );
		$req   = array(
			'node_id'      => $node_id,
			'surface_id'   => self::SURFACE_ID,
			'open'         => false,
			'reason'       => '',
			'opening_tier' => 'base',
			'recipient'    => SafeGrd_Settings::get( 'recipient' ),
			'format'       => 1,
			'chunker'      => SafeGrd_Repo_Format::chunker(),
			'retention'    => array(
				'days'         => (int) ( $info['retention_days'] ?? 0 ),
				'keep_daily'   => (int) ( $info['keep_daily'] ?? 0 ),
				'keep_weekly'  => (int) ( $info['keep_weekly'] ?? 0 ),
				'keep_monthly' => (int) ( $info['keep_monthly'] ?? 0 ),
			),
		);
		if ( ! empty( $state['epoch_id'] ) ) {
			$req['current_epoch_id'] = $state['epoch_id'];
		} else {
			$req['open']   = true;
			$req['reason'] = $this->repo->epochs( $node_id, self::SURFACE_ID ) ? 'cache-lost' : 'first';
		}
		$view = $this->repo->open_epoch( $req );
		if ( empty( $view['epoch']['epoch_id'] ) ) {
			throw new SafeGrd_Exception( esc_html( 'Hosted storage opened no epoch.' ), 'storage' );
		}
		if ( empty( $view['new'] ) && ( $state['epoch_id'] ?? '' ) !== $view['epoch']['epoch_id'] ) {
			// The server continues an epoch this site has no cache for: start
			// a new one rather than miss blobs it already holds.
			$req['open']   = true;
			$req['reason'] = 'cache-lost';
			$view          = $this->repo->open_epoch( $req );
		}
		if ( 'fixed' !== ( $view['epoch']['chunker']['algorithm'] ?? '' ) || SafeGrd_Settings::get( 'recipient' ) !== $view['epoch']['recipient'] ) {
			throw new SafeGrd_Exception( esc_html( 'Hosted storage opened an epoch this plugin cannot write into: another chunker or another key.' ), 'storage' );
		}
		SafeGrd_Repo_Cache::keep_only( $view['epoch']['epoch_id'] );
		update_option(
			self::REPO,
			array(
				'epoch_id'    => $view['epoch']['epoch_id'],
				'storage_uri' => $view['storage_uri'] ?? '',
			),
			false
		);
		return $view;
	}

	/**
	 * Does the job's stages until they are done or the slice's time is up.
	 *
	 * @return bool Whether every stage before finishing is done.
	 */
	private function work() {
		$this->job['slices']++;
		if ( 'database' === $this->job['stage'] ) {
			$this->say( 'Dumping the database' );
			// A dump a stopped slice began is done again from the start.
			SafeGrd_Repo_Cache::forget_run( $this->job['epoch_id'], $this->job['run_id'] );
			$this->job['db'] = ( new SafeGrd_Dumper( $this ) )->run();
			foreach ( $this->job['db']['skipped'] as $s ) {
				$this->job['skipped'][] = $s;
			}
			$rows = 0;
			foreach ( $this->job['db']['tables'] as $t ) {
				$rows += $t['rows'];
			}
			$this->say( sprintf( 'Dumped %d tables, %d rows', count( $this->job['db']['tables'] ), $rows ) );
			$this->job['stage'] = 'files';
			$this->checkpoint();
			$this->save_job();
			if ( $this->out_of_time() ) {
				return false;
			}
		}
		if ( 'files' === $this->job['stage'] ) {
			$this->say( null === $this->job['cursor'] ? 'Reading the files' : 'Reading the files after ' . substr( $this->job['cursor'], 6 ) );
			if ( ! $this->add_files() ) {
				return false;
			}
			$this->say( sprintf( 'Read %d files, %s', $this->job['files'], size_format( $this->job['file_bytes'] ) ) );
			$this->job['stage'] = 'finish';
			$this->checkpoint();
			$this->save_job();
		}
		return true;
	}

	private function out_of_time() {
		return $this->deadline > 0 && microtime( true ) >= $this->deadline;
	}

	// --- storing ----------------------------------------------------------

	/**
	 * Starts a file of the run. Its bytes come through write(), and
	 * end_file() records it.
	 */
	public function begin_file( $path, $mode = 0600, $mtime = 0, $inode = 0 ) {
		$this->file = array(
			'path'    => $path,
			'mode'    => $mode,
			'mtime'   => $mtime,
			'inode'   => $inode,
			'hash'    => hash_init( 'sha256' ),
			'size'    => 0,
			'content' => array(),
			'buffer'  => '',
		);
	}

	public function write( $bytes ) {
		hash_update( $this->file['hash'], $bytes );
		$this->file['size']   += strlen( $bytes );
		$this->file['buffer'] .= $bytes;
		while ( strlen( $this->file['buffer'] ) >= SafeGrd_Repo_Format::CHUNK ) {
			$this->store_chunk( substr( $this->file['buffer'], 0, SafeGrd_Repo_Format::CHUNK ) );
			$this->file['buffer'] = (string) substr( $this->file['buffer'], SafeGrd_Repo_Format::CHUNK );
		}
	}

	public function end_file() {
		if ( '' !== $this->file['buffer'] ) {
			$this->store_chunk( $this->file['buffer'] );
		}
		$this->rows[]          = array(
			'path'    => $this->file['path'],
			'size'    => $this->file['size'],
			'mtime'   => $this->file['mtime'],
			'inode'   => $this->file['inode'],
			'mode'    => $this->file['mode'],
			'sha256'  => hash_final( $this->file['hash'] ),
			'content' => $this->file['content'],
		);
		$this->job['logical'] += $this->file['size'];
		$this->file            = null;
	}

	/** Adds a whole file from a string. */
	public function put_file( $path, $bytes, $mode = 0600, $mtime = 0 ) {
		$this->begin_file( $path, $mode, $mtime );
		$this->write( $bytes );
		$this->end_file();
	}

	private function store_chunk( $chunk ) {
		$id                      = hash( 'sha256', $chunk );
		$this->file['content'][] = $id;
		$this->store_blob( $id, $chunk, SafeGrd_Repo_Format::DATA );
	}

	/**
	 * Puts a blob in the open pack, unless the epoch or the pack holds it.
	 */
	private function store_blob( $id, $plaintext, $type ) {
		if ( isset( $this->in_pack[ $id ] ) || SafeGrd_Repo_Cache::known( $this->job['epoch_id'], array( $id ) ) ) {
			return;
		}
		if ( $this->pack && $this->pack->type !== $type ) {
			$this->flush_pack();
		}
		if ( ! $this->pack ) {
			$this->pack = new SafeGrd_Pack( $type, $this->job['recipient'] );
		}
		$this->pack->add( $id, $plaintext );
		$this->in_pack[ $id ] = true;
		if ( $this->pack->size() >= SafeGrd_Repo_Format::PACK ) {
			$this->flush_pack();
		}
	}

	/**
	 * Seals and uploads the open pack, records its blobs as held, and saves
	 * the rows whose blobs are now all stored.
	 */
	private function flush_pack() {
		if ( ! $this->pack || ! $this->pack->blobs ) {
			$this->pack = null;
			return;
		}
		$pack          = $this->pack;
		$blobs         = $pack->blobs;
		$body          = $pack->finish();
		$this->pack    = null;
		$this->in_pack = array();
		$this->repo->put( $this->job['epoch_id'], 'pack', $pack->id, $body );
		SafeGrd_Repo_Cache::add_pack( $this->job['epoch_id'], $this->job['run_id'], $pack->id, strlen( $body ), $blobs );
		$this->job['new_bytes'] += strlen( $body );
		$this->job['new_packs']++;
		$this->save_rows();
		$this->save_job();
	}

	private function save_rows() {
		if ( $this->rows ) {
			SafeGrd_Repo_Cache::put_files( $this->job['epoch_id'], $this->job['run_id'], $this->rows );
			$this->rows = array();
		}
	}

	/**
	 * Closes the open pack, then saves the rows, in that order: a saved point
	 * never names a file whose blobs are not stored.
	 */
	private function checkpoint() {
		$this->flush_pack();
		$this->save_rows();
	}

	private function save_job() {
		update_option( self::JOB, $this->job, false );
	}

	// --- files ------------------------------------------------------------

	/**
	 * Adds the site's files after the saved cursor, in path order, until the
	 * slice's time is up.
	 *
	 * @return bool Whether every file is done.
	 */
	private function add_files() {
		$roots  = array();
		$config = SafeGrd_Site::config_file();
		if ( '' !== $config ) {
			$roots[] = array( $config, 'files/wp-config.php', false );
		}
		$htaccess = SafeGrd_Site::root() . '/.htaccess';
		if ( is_file( $htaccess ) ) {
			$roots[] = array( $htaccess, 'files/.htaccess', false );
		}
		$content = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		$roots[] = array( $content, 'files/' . SafeGrd_Site::content_path(), true );
		$uploads = SafeGrd_Site::uploads_dir();
		if ( is_dir( $uploads ) && 0 !== strpos( $uploads . '/', $content . '/' ) ) {
			if ( '' !== SafeGrd_Site::relative( $uploads ) ) {
				$roots[] = array( $uploads, 'files/' . SafeGrd_Site::uploads_path(), true );
			} elseif ( null === $this->job['cursor'] ) {
				$this->job['skipped'][] = $uploads . ' (the uploads directory is outside the site root)';
			}
		}
		usort(
			$roots,
			function ( $a, $b ) {
				return self::cmp_path( $a[1], $b[1] );
			}
		);
		foreach ( $roots as list( $disk, $rel, $is_dir ) ) {
			$cursor = $this->job['cursor'];
			if ( ! $is_dir ) {
				if ( null !== $cursor && self::cmp_path( $rel, $cursor ) <= 0 ) {
					continue;
				}
				if ( ! $this->add_file( $disk, $rel ) ) {
					return false;
				}
				continue;
			}
			if ( null !== $cursor && self::cmp_path( $rel, $cursor ) < 0 && ! self::under( $cursor, $rel ) ) {
				continue;
			}
			if ( ! $this->add_tree( $disk, $rel, $disk ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Paths compared segment by segment: the order a walk that sorts the
	 * names of each directory visits them in.
	 */
	public static function cmp_path( $a, $b ) {
		$x = explode( '/', $a );
		$y = explode( '/', $b );
		$n = min( count( $x ), count( $y ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$c = strcmp( $x[ $i ], $y[ $i ] );
			if ( 0 !== $c ) {
				return $c < 0 ? -1 : 1;
			}
		}
		return count( $x ) <=> count( $y );
	}

	private static function under( $path, $dir ) {
		return 0 === strpos( $path, $dir . '/' );
	}

	/**
	 * @return bool False when the slice's time ran out.
	 */
	private function add_tree( $dir, $rel, $content_root ) {
		$names = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory is listed as skipped.
		if ( false === $names ) {
			if ( null === $this->job['cursor'] || self::cmp_path( $rel, $this->job['cursor'] ) > 0 ) {
				$this->job['skipped'][] = substr( $rel, 6 ) . '/ (could not be read)';
			}
			return true;
		}
		sort( $names, SORT_STRING );
		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$full   = $dir . '/' . $name;
			$child  = $rel . '/' . $name;
			$cursor = $this->job['cursor'];
			if ( null !== $cursor && self::cmp_path( $child, $cursor ) <= 0 && ! self::under( $cursor, $child ) ) {
				continue;
			}
			if ( is_link( $full ) ) {
				$this->job['skipped'][] = substr( $child, 6 ) . ' (a symlink)';
				continue;
			}
			if ( is_dir( $full ) ) {
				if ( $dir === $content_root && ( in_array( $name, self::EXCLUDE_DIRS, true ) || 0 === strpos( $name, 'safegrd-' ) ) ) {
					continue; // caches, other plugins' backups, a restore's staging and kept copy
				}
				if ( $dir === $content_root . '/uploads' && 0 === strpos( $name, 'backwpup' ) ) {
					continue;
				}
				if ( ! $this->add_tree( $full, $child, $content_root ) ) {
					return false;
				}
				continue;
			}
			if ( ! is_file( $full ) ) {
				$this->job['skipped'][] = substr( $child, 6 ) . ' (not a regular file)';
				continue;
			}
			if ( $dir === $content_root && 'debug.log' === $name ) {
				continue;
			}
			if ( ! $this->add_file( $full, $child ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Adds one file: from the cache when its size, time and inode are what
	 * the epoch last saw, else read and stored.
	 *
	 * @return bool False when the slice's time ran out before it.
	 */
	private function add_file( $full, $rel ) {
		if ( $this->out_of_time() ) {
			return false;
		}
		$stat = @stat( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable file is listed as skipped.
		if ( false === $stat ) {
			$this->job['skipped'][] = substr( $rel, 6 ) . ' (could not be read)';
			$this->job['cursor']    = $rel;
			return true;
		}
		$size  = (int) $stat['size'];
		$mtime = (int) $stat['mtime'];
		$inode = (int) $stat['ino'];
		$mode  = (int) $stat['mode'] & 07777;
		$row   = SafeGrd_Repo_Cache::file( $this->job['epoch_id'], $rel );
		if ( $row && (int) $row['size'] === $size && (int) $row['mtime'] === $mtime && (int) $row['inode'] === $inode ) {
			$this->rows[]          = array(
				'path'    => $rel,
				'size'    => $size,
				'mtime'   => $mtime,
				'inode'   => $inode,
				'mode'    => $mode,
				'sha256'  => $row['sha256'],
				'content' => '' === $row['blobs'] ? array() : explode( ',', $row['blobs'] ),
			);
			$this->job['logical'] += $size;
		} else {
			$fh = @fopen( $full, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed; an unreadable file is listed as skipped.
			if ( false === $fh ) {
				$this->job['skipped'][] = substr( $rel, 6 ) . ' (could not be read)';
				$this->job['cursor']    = $rel;
				return true;
			}
			$this->begin_file( $rel, $mode, $mtime, $inode );
			$left = $size;
			while ( $left > 0 ) {
				$buf = fread( $fh, min( self::READ_BYTES, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $buf || '' === $buf ) {
					$buf                    = str_repeat( "\0", $left );
					$this->job['skipped'][] = substr( $rel, 6 ) . ' (changed while it was read)';
				}
				$this->write( $buf );
				$left -= strlen( $buf );
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->end_file();
		}
		$this->job['files']++;
		$this->job['file_bytes'] += $size;
		$this->job['cursor']      = $rel;
		/**
		 * Fires after a file is added to the backup under way, before it is
		 * saved: its blobs may still be in the open pack.
		 *
		 * @param string $rel The file's path in the snapshot.
		 */
		do_action( 'safegrd_file_stored', $rel );
		return true;
	}

	// --- finishing --------------------------------------------------------

	/**
	 * Writes the manifest, the trees, the index, catalog and snapshot, the
	 * sidecar, and commits the run; then records it with the server.
	 */
	private function finish() {
		if ( ! empty( $this->job['finishing'] ) ) {
			// An earlier finish stopped part way, and may have written some of
			// this run's objects, which are written once. Finish as a new run:
			// its index adopts every blob the stopped one uploaded.
			$old                      = $this->job['run_id'];
			$this->job['run_id']      = bin2hex( random_bytes( 16 ) );
			$this->job['snapshot_id'] = 'snap-' . gmdate( 'Ymd-His' ) . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
			SafeGrd_Repo_Cache::adopt( $this->job['epoch_id'], $this->job['run_id'] );
			SafeGrd_Repo_Cache::retag_run( $this->job['epoch_id'], $old, $this->job['run_id'] );
			$this->say( sprintf( 'Finishing again as %s', $this->job['snapshot_id'] ) );
		}
		$this->job['finishing'] = true;
		$this->save_job();
		$job = $this->job;
		$this->say( 'Writing the snapshot' );
		$db          = $job['db'];
		$rows_total  = 0;
		$table_stats = array();
		foreach ( $db['tables'] as $name => $t ) {
			$rows_total   += $t['rows'];
			$table_stats[] = array(
				'schema'     => $db['database'],
				'table_name' => (string) $name,
				'row_count'  => $t['rows'],
				'size_bytes' => $t['size'],
			);
		}
		$wordpress = array(
			'site_url'          => home_url(),
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'plugin_version'    => SAFEGRD_VERSION,
			'table_prefix'      => $GLOBALS['wpdb']->prefix,
			'uploads_path'      => SafeGrd_Site::uploads_path(),
			'total_files'       => $job['files'],
			'file_bytes'        => $job['file_bytes'],
			'attachments'       => $db['attachments'],
		);
		if ( $job['skipped'] ) {
			$wordpress['skipped'] = array_slice( $job['skipped'], 0, 100 );
		}
		$meta = array(
			'snapshot_id'      => $job['snapshot_id'],
			'node_id'          => SafeGrd_Settings::get( 'node_id' ),
			'surface_type'     => 'wordpress',
			'database_name'    => $db['database'],
			'created_at'       => SafeGrd_Repo_Format::rfc3339( $job['started'] ),
			'status'           => 'completed',
			'server_version'   => $db['server_version'],
			'schema_source'    => 'safegrd-wordpress ' . SAFEGRD_VERSION,
			'table_stats'      => $table_stats,
			'total_tables'     => count( $table_stats ),
			'total_rows'       => $rows_total,
			'total_items'      => $rows_total,
			'total_containers' => count( $table_stats ),
			'wordpress'        => $wordpress,
		);
		// The trees' files, first, so the manifest can say what each part holds.
		$rows = SafeGrd_Repo_Cache::run_files( $job['epoch_id'], $job['run_id'] );

		// The archive's manifest says what each part of the site holds, so a
		// download or a restore of one part can be offered with its size.
		// The server is sent $meta without it.
		$manifest               = $meta;
		$manifest['components'] = self::components( $rows, $db );
		$this->put_file( 'manifest.json', SafeGrd_Repo_Format::json( $manifest ) );
		$this->checkpoint();
		$rows = SafeGrd_Repo_Cache::run_files( $job['epoch_id'], $job['run_id'] );

		// The trees, from every file this run saw.
		$root = array();
		$data = array();
		foreach ( $rows as $r ) {
			$node = &$root;
			$segs = explode( '/', $r['path'] );
			$leaf = array_pop( $segs );
			foreach ( $segs as $s ) {
				if ( ! isset( $node[ $s ] ) ) {
					$node[ $s ] = array();
				}
				$node = &$node[ $s ];
			}
			$content       = '' === $r['blobs'] ? array() : explode( ',', $r['blobs'] );
			$node[ $leaf ] = array(
				'__file'  => true,
				'type'    => 'file',
				'mode'    => (int) $r['mode'],
				'mtime'   => SafeGrd_Repo_Format::rfc3339( (int) $r['mtime'] ),
				'size'    => (int) $r['size'],
				'sha256'  => $r['sha256'],
				'content' => $content,
			);
			unset( $node );
			foreach ( $content as $id ) {
				$data[ $id ] = true;
			}
		}
		$lines   = array();
		$trees   = array();
		$dirs    = 0;
		$files   = 0;
		$root_id = $this->store_tree( $root, '', $lines, $trees, $dirs, $files );
		$this->checkpoint();
		$where = SafeGrd_Repo_Cache::locate( $job['epoch_id'], array_merge( array_keys( $data ), array_keys( $trees ) ) );
		if ( $where['missing'] ) {
			throw new SafeGrd_Exception( esc_html( sprintf( 'The snapshot would name %d blobs this site has no record of storing.', count( $where['missing'] ) ) ), 'other' );
		}
		$content_root = SafeGrd_Repo_Format::content_root( $lines );

		$index   = array(
			'version'  => 1,
			'epoch_id' => $job['epoch_id'],
			'run_id'   => $job['run_id'],
			'packs'    => SafeGrd_Repo_Cache::run_index( $job['epoch_id'], $job['run_id'] ),
		);
		$entries = array();
		foreach ( $rows as $r ) {
			$e = array();
			if ( null === SafeGrd_Repo_Format::utf8( $r['path'] ) ) {
				$e['path_b64'] = base64_encode( $r['path'] );
			} else {
				$e['path'] = $r['path'];
			}
			$e['event']  = 'present';
			$e['type']   = 'f';
			$e['sha256'] = $r['sha256'];
			if ( (int) $r['size'] > 0 ) {
				$e['size'] = (int) $r['size'];
			}
			$e['mtime'] = SafeGrd_Repo_Format::rfc3339( (int) $r['mtime'] );
			if ( (int) $r['mode'] > 0 ) {
				$e['mode'] = (int) $r['mode'];
			}
			$entries[] = $e;
		}
		// Directories are entries of the catalog too: it names every path
		// the trees hold.
		foreach ( $lines as $path => $line ) {
			if ( 'd' !== $line[0] ) {
				continue;
			}
			$path = (string) $path;
			$e    = array();
			if ( null === SafeGrd_Repo_Format::utf8( $path ) ) {
				$e['path_b64'] = base64_encode( $path );
			} else {
				$e['path'] = $path;
			}
			$e['event'] = 'present';
			$e['type']  = 'd';
			$e['mtime'] = SafeGrd_Repo_Format::FIXED_TIME;
			$e['mode']  = SafeGrd_Repo_Format::DIR_MODE;
			$entries[]  = $e;
		}
		$catalog = array(
			'version'     => 1,
			'epoch_id'    => $job['epoch_id'],
			'run_id'      => $job['run_id'],
			'snapshot_id' => $job['snapshot_id'],
			'complete'    => true,
			'entries'     => $entries,
		);
		$keys   = array();
		$keys[] = $this->repo->put( $job['epoch_id'], 'index', $job['run_id'], SafeGrd_Repo_Format::seal_object( $index, $job['recipient'] ) );
		$keys[] = $this->repo->put( $job['epoch_id'], 'catalog', $job['run_id'], SafeGrd_Repo_Format::seal_object( $catalog, $job['recipient'] ) );

		$skipped = array();
		foreach ( $job['skipped'] as $s ) {
			$skipped[] = array(
				'path'   => $s,
				'reason' => 'skipped',
			);
		}
		$now      = time();
		$job      = $this->job; // the counters after the last packs
		$snapshot = array(
			'version'      => 1,
			'snapshot_id'  => $job['snapshot_id'],
			'epoch_id'     => $job['epoch_id'],
			'run_id'       => $job['run_id'],
			'class'        => $job['class'],
			'created_at'   => SafeGrd_Repo_Format::rfc3339( $job['started'] ),
			'completed_at' => SafeGrd_Repo_Format::rfc3339( $now ),
			'host'         => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'roots'        => array( '/' ),
			'root_tree'    => $root_id,
			'content_root' => $content_root,
			'runs'         => $where['runs'],
			'packs'        => $where['packs'],
			'stats'        => array(
				'files'         => $files,
				'dirs'          => $dirs,
				'logical_bytes' => $job['logical'],
				'new_bytes'     => $job['new_bytes'],
				'new_packs'     => $job['new_packs'],
			),
			'skipped'      => $skipped,
			'inconsistent' => array(),
			'retain_until' => SafeGrd_Repo_Format::rfc3339( $job['retain'] ),
		);
		$keys[]   = $this->repo->put( $job['epoch_id'], 'snapshot', $job['snapshot_id'], SafeGrd_Repo_Format::seal_object( $snapshot, $job['recipient'] ) );

		$meta   = array_merge(
			$meta,
			array(
				'completed_at'         => SafeGrd_Repo_Format::rfc3339( $now ),
				'raw_size_bytes'       => $job['logical'],
				'encrypted_size_bytes' => $job['new_bytes'],
				'sha256_checksum'      => $content_root,
				'encrypted_sha256'     => '',
				'storage_uri'          => $job['storage_uri'],
				'worm_mode'            => $job['worm_mode'],
				'worm_retention_until' => SafeGrd_Repo_Format::rfc3339( $job['locked'] ?? $job['retain'] ),
				'duration_ms'          => ( $now - $job['started'] ) * 1000,
				'format'               => 'repo-v1',
				'epoch_id'             => $job['epoch_id'],
				'object_class'         => $job['class'],
			)
		);
		$keys[] = $this->repo->put( $job['epoch_id'], 'meta', $job['snapshot_id'], wp_json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
		$this->repo->commit(
			$job['epoch_id'],
			array(
				'snapshot_id'  => $job['snapshot_id'],
				'run_id'       => $job['run_id'],
				'class'        => $job['class'],
				'keys'         => $keys,
				'retain_until' => SafeGrd_Repo_Format::rfc3339( $job['retain'] ),
			)
		);
		SafeGrd_Repo_Cache::mark_committed( $job['epoch_id'], $job['run_id'] );

		$message = '';
		$rec     = $this->client->call( 'POST', '/api/v1/snapshots', $meta );
		if ( is_wp_error( $rec ) ) {
			// The backup is stored and restorable; the console just does not
			// know about it. Said on every surface the run is shown.
			$message = 'Stored, but the server did not record it: ' . $rec->get_error_message();
			$this->say( 'Warning: ' . $message );
		}
		$this->heartbeat( $job['snapshot_id'], '' );
		return array(
			'status'      => 'completed',
			'snapshot_id' => $job['snapshot_id'],
			'started_at'  => gmdate( 'c', $job['started'] ),
			'finished_at' => gmdate( 'c', $now ),
			'seconds'     => $now - $job['started'],
			'slices'      => $job['slices'],
			'bytes'       => $job['new_bytes'],
			'raw_bytes'   => $job['logical'],
			'tables'      => count( $table_stats ),
			'rows'        => $rows_total,
			'files'       => $job['files'],
			'retain'      => SafeGrd_Repo_Format::rfc3339( $job['locked'] ?? $job['retain'] ),
			'class'       => $job['class'],
			'skipped'     => $job['skipped'],
			'message'     => $message,
			'peak_memory' => memory_get_peak_usage( true ),
		);
	}

	/**
	 * What each part of the site holds: files and bytes per component, the
	 * database's tables, and the plugins and themes installed, with their
	 * versions.
	 *
	 * @param array $rows Every file of the run, as {path, size}.
	 * @param array $db   The dump's record: tables with rows and size.
	 */
	private static function components( array $rows, array $db ) {
		$out = array();
		foreach ( SafeGrd_Site::COMPONENTS as $c ) {
			$out[ $c ] = array(
				'files' => 0,
				'bytes' => 0,
			);
		}
		$content = SafeGrd_Site::content_path();
		$uploads = SafeGrd_Site::uploads_path();
		foreach ( $rows as $r ) {
			$c = SafeGrd_Site::component( $r['path'], $content, $uploads );
			if ( '' === $c ) {
				continue;
			}
			++$out[ $c ]['files'];
			$out[ $c ]['bytes'] += (int) $r['size'];
		}
		$rows_total = 0;
		$bytes      = 0;
		foreach ( $db['tables'] as $t ) {
			$rows_total += (int) $t['rows'];
			$bytes      += (int) $t['size'];
		}
		$out['database'] = array(
			'tables' => count( $db['tables'] ),
			'rows'   => $rows_total,
			'bytes'  => $bytes,
		);
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out['plugins']['items'] = array();
		foreach ( get_plugins() as $file => $p ) {
			$out['plugins']['items'][] = array(
				'file'    => (string) $file,
				'name'    => (string) $p['Name'],
				'version' => (string) $p['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}
		$out['themes']['items'] = array();
		foreach ( wp_get_themes() as $slug => $t ) {
			$out['themes']['items'][] = array(
				'slug'    => (string) $slug,
				'name'    => (string) $t->get( 'Name' ),
				'version' => (string) $t->get( 'Version' ),
				'active'  => get_stylesheet() === $slug,
			);
		}
		return $out;
	}

	/**
	 * Stores the trees under node bottom up and returns this tree's id,
	 * collecting every entry's content-root line on the way.
	 */
	private function store_tree( array $node, $prefix, array &$lines, array &$trees, &$dirs, &$files ) {
		$entries = array();
		foreach ( $node as $name => $child ) {
			$name = (string) $name;
			$path = '' === $prefix ? $name : $prefix . '/' . $name;
			if ( isset( $child['__file'] ) ) {
				unset( $child['__file'] );
				$entries[ $name ] = $child;
				$lines[ $path ]   = array( 'f', $child['sha256'] );
				$files++;
				continue;
			}
			$sub              = $this->store_tree( $child, $path, $lines, $trees, $dirs, $files );
			$entries[ $name ] = array(
				'type'    => 'dir',
				'subtree' => $sub,
			);
			$lines[ $path ]   = array( 'd', '-' );
			$dirs++;
		}
		$plain        = SafeGrd_Repo_Format::tree( $entries );
		$id           = hash( 'sha256', $plain );
		$trees[ $id ] = true;
		$this->store_blob( $id, $plain, SafeGrd_Repo_Format::TREE );
		return $id;
	}

	// --- outcomes ---------------------------------------------------------

	/**
	 * A slice that threw. A storage failure is tried again in a later slice;
	 * anything else, or the last attempt, fails the run.
	 */
	private function slice_failed( Throwable $e ) {
		$reason = $e instanceof SafeGrd_Exception && $e->reason ? $e->reason : 'other';
		if ( 'storage' === $reason && is_array( $this->job ) && $this->job['attempts'] + 1 < self::ATTEMPTS ) {
			// What the slice had not stored is dropped; the cursor saved at
			// the last pack is where the next slice resumes.
			$saved                 = get_option( self::JOB, null );
			$this->job             = is_array( $saved ) ? $saved : $this->job;
			$this->job['attempts'] = $this->job['attempts'] + 1;
			$this->pack            = null;
			$this->in_pack         = array();
			$this->rows            = array();
			$this->save_job();
			SafeGrd_Scheduler::continue_later( 60 * $this->job['attempts'] );
			$this->say( 'Warning: ' . SafeGrd_Exception::text( $e ) . ' Trying again in a minute.' );
			$run            = $this->progress( 'running' );
			$run['message'] = SafeGrd_Exception::text( $e ) . ' Trying again.';
			SafeGrd_Settings::record_run( $run );
			return $run;
		}
		$snapshot_id = is_array( $this->job ) ? $this->job['snapshot_id'] : '';
		delete_option( self::JOB );
		SafeGrd_Scheduler::continue_cancel();
		return $this->record_failure( SafeGrd_Exception::text( $e ), $reason, $snapshot_id );
	}

	/**
	 * Records a failed run here and with the server, so the console shows the
	 * failure rather than silence. Packs already uploaded stay in the epoch,
	 * and the next run adopts them.
	 */
	private function record_failure( $message, $reason, $snapshot_id ) {
		$started = is_array( $this->job ) ? $this->job['started'] : time();
		$run     = array(
			'status'      => 'failed',
			'started_at'  => gmdate( 'c', $started ),
			'finished_at' => gmdate( 'c' ),
			'seconds'     => time() - $started,
			'message'     => $message,
			'reason'      => $reason,
			'skipped'     => is_array( $this->job ) ? $this->job['skipped'] : array(),
		);
		$this->say( 'Error: ' . $message );
		$key = '' !== $snapshot_id ? $snapshot_id : 'backup-' . gmdate( 'Ymd-His' );
		if ( '' === $snapshot_id ) {
			SafeGrd_Log::add( $key, 'backup', 'Backup', 'Error: ' . $message );
		}
		SafeGrd_Log::finish( $key, 'backup', '' !== $snapshot_id ? $snapshot_id : 'Backup', 'failed' );
		if ( SafeGrd_Settings::connected() ) {
			$this->client = $this->client ? $this->client : SafeGrd_Client::for_site();
			$now          = gmdate( 'Y-m-d\TH:i:s\Z' );
			$rec          = $this->client->call(
				'POST',
				'/api/v1/snapshots',
				array(
					'snapshot_id'    => $snapshot_id ? $snapshot_id : 'snap-' . gmdate( 'Ymd-His' ) . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 ),
					'node_id'        => SafeGrd_Settings::get( 'node_id' ),
					'surface_type'   => 'wordpress',
					'status'         => 'failed',
					'error_message'  => $message,
					'failure_reason' => $reason,
					'created_at'     => $now,
					'completed_at'   => $now,
				)
			);
			if ( is_wp_error( $rec ) ) {
				$run['message'] .= ' The server was not told either: ' . $rec->get_error_message();
				$this->say( 'Warning: the server was not told of this failure: ' . $rec->get_error_message() );
			}
			$this->heartbeat( '', $message );
		}
		SafeGrd_Settings::record_run( $run );
		return $run;
	}

	private function progress( $status ) {
		return array(
			'status'      => $status,
			'snapshot_id' => $this->job['snapshot_id'],
			'started_at'  => gmdate( 'c', $this->job['started'] ),
			'stage'       => $this->job['stage'],
			'files'       => $this->job['files'],
			'file_bytes'  => $this->job['file_bytes'],
			'bytes'       => $this->job['new_bytes'],
			'slices'      => $this->job['slices'],
		);
	}

	/**
	 * Tells the server the schedule now, so the console measures overdue
	 * against it before the next backup reports it.
	 */
	public static function report_schedule() {
		$last = SafeGrd_Settings::last_run();
		( new self() )->heartbeat( 'completed' === ( $last['status'] ?? '' ) ? (string) $last['snapshot_id'] : '', '' );
	}

	private function heartbeat( $snapshot_id, $error ) {
		if ( ! $this->client ) {
			$this->client = SafeGrd_Client::for_site();
		}
		$body = array(
			'node_id'       => SafeGrd_Settings::get( 'node_id' ),
			'cli_version'   => 'wordpress-plugin ' . SAFEGRD_VERSION,
			'os'            => 'wordpress',
			'arch'          => 'php-' . PHP_VERSION,
			'postgres_up'   => '' === $error,
			'storage_up'    => '' === $error,
			'last_snapshot' => $snapshot_id,
			'schedule'      => SafeGrd_Scheduler::schedule_expr(),
		);
		if ( '' !== $error ) {
			$body['last_error'] = $error;
		}
		$r = $this->client->call( 'POST', '/api/v1/nodes/heartbeat', $body, 20 );
		if ( is_wp_error( $r ) ) {
			$this->say( 'Warning: the server did not take this site\'s heartbeat: ' . $r->get_error_message() );
		}
	}

	private function say( $line ) {
		if ( null === $line ) {
			return;
		}
		if ( is_array( $this->job ) && ! empty( $this->job['snapshot_id'] ) ) {
			SafeGrd_Log::add( $this->job['snapshot_id'], 'backup', $this->job['snapshot_id'], $line );
		}
		if ( $this->say ) {
			call_user_func( $this->say, $line );
		}
	}

	// --- hosted storage -------------------------------------------------------

	/**
	 * Hosted storage held against the plan's included amount, as SafeGrd
	 * counts it for the whole organization, and the server's warning when it
	 * gave one (from 80%, in the plan's own terms: refused, billed or in
	 * grace). The plugin knows no quota of its own.
	 *
	 * @return array{line:string,warning:string,used:int,quota:int}|WP_Error
	 */
	public static function storage_usage() {
		$info = SafeGrd_Client::for_site()->call( 'GET', '/api/v1/nodes/' . rawurlencode( SafeGrd_Settings::get( 'node_id' ) ) . '/hosted', null, 15 );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$used  = (int) ( $info['used_bytes'] ?? 0 );
		$quota = (int) ( $info['quota_bytes'] ?? 0 );
		$line  = $quota > 0
			? sprintf( '%s of the %s your plan includes, %d%%, across every surface in the account', size_format( $used, 1 ), size_format( $quota, 0 ), (int) floor( $used * 100 / $quota ) )
			: sprintf( '%s held, across every surface in the account', size_format( $used, 1 ) );
		return array(
			'line'    => $line,
			'warning' => (string) ( $info['warning'] ?? '' ),
			'used'    => $used,
			'quota'   => $quota,
		);
	}

	// --- the lock -----------------------------------------------------------

	/**
	 * Takes the slice lock, shared by backups and restores, so one slice
	 * runs at a time. The lock row holds when it goes stale: a holder with
	 * no time limit (WP-CLI) is not taken over by a slice that would give up
	 * sooner. add_option() is no lock: it upserts, and it reads through this
	 * request's option cache, so two slices could both take it. Here a free
	 * lock is taken by INSERT IGNORE and a stale one by compare-and-swap on
	 * the value read, and only the request whose statement changed the row
	 * holds it.
	 */
	public static function take_lock( $budget ) {
		global $wpdb;
		$now   = time();
		$value = (string) ( $now + self::stale_seconds( $budget ) );
		$took  = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $wpdb->options, self::LOCK, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a lock needs one atomic statement; the options API has none.
		if ( 1 !== $took ) {
			$held = self::lock_value();
			if ( null !== $held && (int) $held > $now ) {
				return false;
			}
			$took = null === $held
				? $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $wpdb->options, self::LOCK, $value ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as above.
				: $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, self::LOCK, $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- as above.
		}
		if ( 1 !== $took ) {
			return false;
		}
		// The slice reads the job the last slice saved, in whichever request
		// that ran, not this request's cached copy.
		wp_cache_delete( self::JOB, 'options' );
		wp_cache_delete( SafeGrd_Restore::JOB, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return true;
	}

	public static function release_lock() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the lock row, read only by take_lock.
		wp_cache_delete( self::LOCK, 'options' );
	}

	/** When the held lock goes stale, as stored; null when none is held. */
	private static function lock_value() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read past the option cache, which another request's lock does not reach.
	}

	/** Seconds after which a held lock belongs to a slice the host stopped. */
	public static function stale_seconds( $budget ) {
		return 0.0 === (float) $budget ? 6 * HOUR_IN_SECONDS : (int) ceil( $budget * 3 + 60 );
	}

	private function lock() {
		return self::take_lock( $this->budget );
	}

	private function unlock() {
		self::release_lock();
	}

	private function stale_after() {
		return self::stale_seconds( $this->budget );
	}

	/** Whether a backup is under way: a slice running, or one still to come. */
	public static function running() {
		return is_array( get_option( self::JOB, null ) ) || (int) self::lock_value() > time();
	}
}
