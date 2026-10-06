<?php
/**
 * One backup of the site: the database, then the files, into one encrypted
 * archive in SafeGrd's hosted storage, recorded with the server.
 *
 * Archive layout (read by the safegrd CLI's verify and restore):
 *
 *   mysql/dump.sql[.N]  the database
 *   files/<path>        wp-config.php, .htaccess and the content directory
 *   files.json          every files/ entry: path, size, SHA-256
 *   manifest.json       the snapshot's metadata
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Backup {
	const LOCK = 'safegrd_backup_lock';
	/** A lock older than this belongs to a run the host killed. */
	const LOCK_TTL = 21600;
	const READ_BYTES = 1048576;

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
	private $skipped = array();

	/**
	 * @param callable|null $say Receives one line of progress at a time.
	 */
	public function __construct( $say = null ) {
		$this->say = $say;
	}

	/**
	 * Runs a backup and records the outcome, locally and with the server.
	 *
	 * @return array The run, as recorded.
	 */
	public function run() {
		$started = microtime( true );
		$run     = array(
			'status'     => 'running',
			'started_at' => gmdate( 'c' ),
		);
		if ( ! SafeGrd_Settings::connected() ) {
			return $this->finish_failed( $run, $started, 'This site is not connected to SafeGrd. Connect it under Tools, SafeGrd.', 'config', '' );
		}
		$refusal = SafeGrd_Site::refusal();
		if ( '' !== $refusal ) {
			return $this->finish_failed( $run, $started, $refusal, 'config', '' );
		}
		if ( ! $this->lock() ) {
			return array(
				'status'  => 'busy',
				'message' => 'A backup of this site is already running.',
			);
		}
		SafeGrd_Settings::record_run( $run );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts disable it; the run goes on with their limit.
			@set_time_limit( 0 );
		}
		ignore_user_abort( true );
		wp_raise_memory_limit( 'admin' );

		$snapshot_id = 'snap-' . gmdate( 'Ymd-His' ) . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		$uploader    = null;
		try {
			$result = $this->backup( $snapshot_id, $uploader );
			$run    = array_merge(
				$run,
				array(
					'status'      => 'completed',
					'snapshot_id' => $snapshot_id,
					'finished_at' => gmdate( 'c' ),
					'seconds'     => round( microtime( true ) - $started, 1 ),
					'bytes'       => $result['encrypted_bytes'],
					'raw_bytes'   => $result['raw_bytes'],
					'tables'      => $result['tables'],
					'rows'        => $result['rows'],
					'files'       => $result['files'],
					'retain'      => $result['retain_until'],
					'skipped'     => $this->skipped,
					'message'     => $result['message'],
					'peak_memory' => memory_get_peak_usage( true ),
				)
			);
			SafeGrd_Settings::record_run( $run );
			return $run;
		} catch ( Throwable $e ) {
			if ( $uploader ) {
				$why = $uploader->abort();
				if ( '' !== $why ) {
					$this->say( 'Warning: could not abort the unfinished upload (' . $why . '); the server removes it later.' );
				}
			}
			$reason = $e instanceof SafeGrd_Exception && $e->reason ? $e->reason : 'other';
			return $this->finish_failed( $run, $started, $e->getMessage(), $reason, $snapshot_id );
		} finally {
			$this->unlock();
		}
	}

	private function backup( $snapshot_id, &$uploader ) {
		$client  = SafeGrd_Client::for_site();
		$node_id = SafeGrd_Settings::get( 'node_id' );

		$info = $client->call( 'GET', '/api/v1/nodes/' . rawurlencode( $node_id ) . '/hosted' );
		if ( is_wp_error( $info ) ) {
			throw new SafeGrd_Exception( 'Hosted storage: ' . $info->get_error_message(), 'storage' );
		}
		if ( ! empty( $info['warning'] ) ) {
			$this->say( 'Warning: ' . $info['warning'] );
		}
		$days   = max( 1, (int) ( $info['retention_days'] ?? 1 ) );
		$retain = gmdate( 'Y-m-d\TH:i:s\Z', time() + $days * DAY_IN_SECONDS );

		$created  = gmdate( 'Y-m-d\TH:i:s\Z' );
		$uploader = new SafeGrd_Uploader( $client, $node_id, $snapshot_id . '.safegrd', $retain );
		$sealed   = new SafeGrd_Sealed_Stream(
			SafeGrd_Settings::get( 'recipient' ),
			function ( $bytes ) use ( $uploader ) {
				$uploader->write( $bytes );
			}
		);
		$tar      = new SafeGrd_Tar(
			function ( $bytes ) use ( $sealed ) {
				$sealed->write( $bytes );
			}
		);

		$this->say( 'Dumping the database' );
		$dumper = new SafeGrd_Dumper( $tar );
		$db     = $dumper->run();
		foreach ( $db['skipped'] as $s ) {
			$this->skipped[] = $s;
		}
		$rows = 0;
		foreach ( $db['tables'] as $t ) {
			$rows += $t['rows'];
		}
		$this->say( sprintf( 'Dumped %d tables, %d rows', count( $db['tables'] ), $rows ) );

		$this->say( 'Reading the files' );
		$list = $this->add_files( $tar );
		$file_bytes = 0;
		foreach ( $list as $f ) {
			$file_bytes += $f['size'];
		}
		$tar->add_bytes( 'files.json', wp_json_encode( $list, JSON_UNESCAPED_SLASHES ) );
		$this->say( sprintf( 'Read %d files, %s', count( $list ), size_format( $file_bytes ) ) );

		$wordpress = array(
			'site_url'          => home_url(),
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'plugin_version'    => SAFEGRD_VERSION,
			'table_prefix'      => $GLOBALS['wpdb']->prefix,
			'uploads_path'      => SafeGrd_Site::uploads_path(),
			'total_files'       => count( $list ),
			'file_bytes'        => $file_bytes,
			'attachments'       => $db['attachments'],
		);
		if ( $this->skipped ) {
			$wordpress['skipped'] = array_slice( $this->skipped, 0, 100 );
		}
		$meta = array(
			'snapshot_id'    => $snapshot_id,
			'node_id'        => $node_id,
			'surface_type'   => 'wordpress',
			'database_name'  => $db['database'],
			'created_at'     => $created,
			'status'         => 'completed',
			'server_version' => $db['server_version'],
			'schema_source'  => 'safegrd-wordpress ' . SAFEGRD_VERSION,
			'table_stats'    => array(),
			'total_tables'   => count( $db['tables'] ),
			'total_rows'     => $rows,
			'total_items'    => $rows,
			'total_containers' => count( $db['tables'] ),
			'wordpress'      => $wordpress,
		);
		foreach ( $db['tables'] as $name => $t ) {
			$meta['table_stats'][] = array(
				'schema'     => $db['database'],
				'table_name' => $name,
				'row_count'  => $t['rows'],
				'size_bytes' => $t['size'],
			);
		}
		$tar->add_bytes( 'manifest.json', wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ) );
		$tar->close();
		$digests = $sealed->finish();
		$this->say( 'Finishing the upload' );
		$stored   = $uploader->finish();
		$kept     = $uploader->retain_until();
		$uploader = null;

		$meta['raw_size_bytes']       = $digests['raw_bytes'];
		$meta['encrypted_size_bytes'] = $digests['encrypted_bytes'];
		$meta['sha256_checksum']      = $digests['raw_sha256'];
		$meta['encrypted_sha256']     = $digests['encrypted_sha256'];
		$meta['storage_uri']          = $stored['storage_uri'];
		$meta['worm_mode']            = isset( $info['worm_mode'] ) ? $info['worm_mode'] : '';
		$meta['worm_retention_until'] = $kept ? $kept : $retain;
		$meta['completed_at']         = gmdate( 'Y-m-d\TH:i:s\Z' );
		$meta['duration_ms']          = (int) round( ( microtime( true ) - strtotime( $created ) ) * 1000 );

		// The sidecar beside the archive: what a restore reads first, from
		// hosted storage, with or without this server's record.
		$side = new SafeGrd_Uploader( $client, $node_id, $snapshot_id . '.meta.json', $meta['worm_retention_until'] );
		try {
			$side->write( wp_json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
			$side->finish();
		} catch ( Throwable $e ) {
			$side->abort();
			throw $e;
		}

		$message = '';
		$rec     = $client->call( 'POST', '/api/v1/snapshots', $meta );
		if ( is_wp_error( $rec ) ) {
			// The backup is stored and restorable; the console just does not
			// know about it. Say so on every surface the run is shown.
			$message = 'Stored, but the server did not record it: ' . $rec->get_error_message();
			$this->say( 'Warning: ' . $message );
		}
		$this->heartbeat( $client, $node_id, $snapshot_id, '' );

		return array(
			'raw_bytes'       => $digests['raw_bytes'],
			'encrypted_bytes' => $digests['encrypted_bytes'],
			'tables'          => count( $db['tables'] ),
			'rows'            => $rows,
			'files'           => count( $list ),
			'retain_until'    => $meta['worm_retention_until'],
			'message'         => $message,
		);
	}

	/**
	 * Adds wp-config.php, .htaccess and the content directory to the
	 * archive, and returns the list of what was added.
	 */
	private function add_files( SafeGrd_Tar $tar ) {
		$list   = array();
		$config = SafeGrd_Site::config_file();
		if ( '' !== $config ) {
			$this->add_file( $tar, $config, 'wp-config.php', $list );
		}
		$htaccess = SafeGrd_Site::root() . '/.htaccess';
		if ( is_file( $htaccess ) ) {
			$this->add_file( $tar, $htaccess, '.htaccess', $list );
		}
		$content = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		$this->add_tree( $tar, $content, SafeGrd_Site::content_path(), $list, true );
		$uploads = SafeGrd_Site::uploads_dir();
		if ( is_dir( $uploads ) && 0 !== strpos( $uploads . '/', $content . '/' ) ) {
			$this->add_tree( $tar, $uploads, SafeGrd_Site::uploads_path(), $list, false );
		}
		return $list;
	}

	private function add_tree( SafeGrd_Tar $tar, $dir, $as, array &$list, $is_content ) {
		$stack = array( array( $dir, $as ) );
		while ( $stack ) {
			list( $path, $rel ) = array_pop( $stack );
			$names = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable directory is listed as skipped.
			if ( false === $names ) {
				$this->skipped[] = $rel . '/ (could not be read)';
				continue;
			}
			sort( $names, SORT_STRING );
			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$full  = $path . '/' . $name;
				$child = $rel . '/' . $name;
				if ( is_link( $full ) ) {
					$this->skipped[] = $child . ' (a symlink)';
					continue;
				}
				if ( is_dir( $full ) ) {
					if ( $is_content && $path === $dir && in_array( $name, self::EXCLUDE_DIRS, true ) ) {
						continue;
					}
					if ( $is_content && $path === $dir . '/uploads' && 0 === strpos( $name, 'backwpup' ) ) {
						continue;
					}
					$stack[] = array( $full, $child );
					continue;
				}
				if ( ! is_file( $full ) ) {
					$this->skipped[] = $child . ' (not a regular file)';
					continue;
				}
				if ( $is_content && $path === $dir && 'debug.log' === $name ) {
					continue;
				}
				$this->add_file( $tar, $full, $child, $list );
			}
		}
	}

	/**
	 * Streams one file into the archive and lists it with the digest of the
	 * bytes written. A file that shrinks while it is read is padded to the
	 * size its header gave, and named in skipped.
	 */
	private function add_file( SafeGrd_Tar $tar, $full, $rel, array &$list ) {
		$fh = @fopen( $full, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed; an unreadable file is listed as skipped.
		if ( false === $fh ) {
			$this->skipped[] = $rel . ' (could not be read)';
			return;
		}
		$stat = fstat( $fh );
		$size = (int) $stat['size'];
		$mode = (int) $stat['mode'] & 0777;
		$tar->begin( 'files/' . $rel, $size, $mode ? $mode : 0644, (int) $stat['mtime'] );
		$hash = hash_init( 'sha256' );
		$left = $size;
		while ( $left > 0 ) {
			$buf = fread( $fh, min( self::READ_BYTES, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $buf || '' === $buf ) {
				$buf             = str_repeat( "\0", $left );
				$this->skipped[] = $rel . ' (changed while it was read)';
			}
			hash_update( $hash, $buf );
			$tar->body( $buf );
			$left -= strlen( $buf );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$tar->end( $size );
		$list[] = array(
			'path'   => $rel,
			'size'   => $size,
			'sha256' => hash_final( $hash ),
		);
	}

	/**
	 * Records a failed run here and with the server, so the console shows
	 * the failure rather than silence.
	 */
	private function finish_failed( array $run, $started, $message, $reason, $snapshot_id ) {
		$run = array_merge(
			$run,
			array(
				'status'      => 'failed',
				'finished_at' => gmdate( 'c' ),
				'seconds'     => round( microtime( true ) - $started, 1 ),
				'message'     => $message,
				'reason'      => $reason,
				'skipped'     => $this->skipped,
			)
		);
		$this->say( 'Error: ' . $message );
		if ( SafeGrd_Settings::connected() ) {
			$client  = SafeGrd_Client::for_site();
			$node_id = SafeGrd_Settings::get( 'node_id' );
			$now     = gmdate( 'Y-m-d\TH:i:s\Z' );
			$rec     = $client->call(
				'POST',
				'/api/v1/snapshots',
				array(
					'snapshot_id'    => $snapshot_id ? $snapshot_id : 'snap-' . gmdate( 'Ymd-His' ) . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 6 ),
					'node_id'        => $node_id,
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
			$this->heartbeat( $client, $node_id, '', $message );
		}
		SafeGrd_Settings::record_run( $run );
		return $run;
	}

	private function heartbeat( SafeGrd_Client $client, $node_id, $snapshot_id, $error ) {
		$body = array(
			'node_id'       => $node_id,
			'cli_version'   => 'wordpress-plugin ' . SAFEGRD_VERSION,
			'os'            => 'wordpress',
			'arch'          => 'php-' . PHP_VERSION,
			'postgres_up'   => '' === $error,
			'storage_up'    => '' === $error,
			'last_snapshot' => $snapshot_id,
			'schedule'      => SafeGrd_Scheduler::SCHEDULE,
		);
		if ( '' !== $error ) {
			$body['last_error'] = $error;
		}
		$r = $client->call( 'POST', '/api/v1/nodes/heartbeat', $body, 20 );
		if ( is_wp_error( $r ) ) {
			$this->say( 'Warning: the server did not take this site\'s heartbeat: ' . $r->get_error_message() );
		}
	}

	private function say( $line ) {
		if ( $this->say ) {
			call_user_func( $this->say, $line );
		}
	}

	/**
	 * Takes the run lock. add_option is a single INSERT, so two runs that
	 * race for it cannot both win.
	 */
	private function lock() {
		$now = time();
		if ( add_option( self::LOCK, $now, '', 'no' ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK, 0 );
		if ( $held && $now - $held < self::LOCK_TTL ) {
			return false;
		}
		delete_option( self::LOCK );
		return add_option( self::LOCK, $now, '', 'no' );
	}

	private function unlock() {
		delete_option( self::LOCK );
	}

	public static function running() {
		$held = (int) get_option( self::LOCK, 0 );
		return $held && time() - $held < self::LOCK_TTL;
	}
}
