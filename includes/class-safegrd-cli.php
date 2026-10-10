<?php
/**
 * WP-CLI commands: connect, backup, status, disconnect.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backs up this site to SafeGrd.
 */
final class SafeGrd_CLI {
	/**
	 * Connects this site to a SafeGrd account.
	 *
	 * Without --token it prints a URL and a code: open the URL on any device,
	 * sign in or sign up, and check the code matches.
	 *
	 * ## OPTIONS
	 *
	 * [--token=<token>]
	 * : A personal access token (sg_pat_...) from Tokens in the SafeGrd console. Prefix env: to read it from an environment variable, for example env:SAFEGRD_TOKEN.
	 *
	 * [--key-custody=<custody>]
	 * : safegrd for a SafeGrd-managed key, local for a customer-managed key.
	 * ---
	 * default: safegrd
	 * options:
	 *   - safegrd
	 *   - local
	 * ---
	 *
	 * [--org=<id>]
	 * : The organization, when the account has more than one.
	 *
	 * [--server=<url>]
	 * : The SafeGrd server, when it is not https://safegrd.dev.
	 *
	 * @when after_wp_load
	 */
	public function connect( $args, $assoc ) {
		if ( SafeGrd_Settings::connected() ) {
			WP_CLI::error( sprintf( 'This site is already connected as %s. Run wp safegrd disconnect first to connect it again.', SafeGrd_Settings::get( 'node_id' ) ) );
		}
		if ( ! empty( $assoc['server'] ) ) {
			$ok = SafeGrd_Client::check_url( $assoc['server'] );
			if ( is_wp_error( $ok ) ) {
				WP_CLI::error( $ok->get_error_message() );
			}
			SafeGrd_Settings::update( array( 'server_url' => rtrim( $assoc['server'], '/' ) ) );
		}
		$custody = isset( $assoc['key-custody'] ) ? $assoc['key-custody'] : 'safegrd';
		$token   = isset( $assoc['token'] ) ? $assoc['token'] : '';
		if ( 0 === strpos( $token, 'env:' ) ) {
			$token = (string) getenv( substr( $token, 4 ) );
			if ( '' === $token ) {
				WP_CLI::error( sprintf( '%s is empty or not set.', substr( $assoc['token'], 4 ) ) );
			}
		}
		if ( '' !== $token ) {
			$done = SafeGrd_Connect::register( $token, $custody, isset( $assoc['org'] ) ? $assoc['org'] : '' );
		} else {
			$s = SafeGrd_Connect::start( $custody );
			if ( is_wp_error( $s ) ) {
				WP_CLI::error( $s->get_error_message() );
			}
			WP_CLI::line( 'Confirmation code: ' . $s['user_code'] );
			WP_CLI::line( 'Open this URL on any device, sign in or sign up, and check the code matches:' );
			WP_CLI::line( '  ' . $s['approve_url'] );
			WP_CLI::line( 'Waiting for the approval (up to 10 minutes).' );
			$deadline = time() + 600;
			$done     = null;
			while ( time() < $deadline ) {
				sleep( 2 );
				$p = SafeGrd_Connect::poll();
				if ( is_wp_error( $p ) || 'connected' === $p['status'] ) {
					$done = $p;
					break;
				}
			}
			if ( null === $done ) {
				WP_CLI::error( 'The sign-in was not approved within 10 minutes. Run wp safegrd connect again.' );
			}
		}
		if ( is_wp_error( $done ) ) {
			WP_CLI::error( $done->get_error_message() );
		}
		WP_CLI::line( 'Connected this site as node ' . $done['node_id'] . '.' );
		WP_CLI::line( '   Storage:  SafeGrd hosted storage' );
		if ( 'safegrd' === $done['key_custody'] ) {
			WP_CLI::line( '   Custody:  SafeGrd-managed key' );
			WP_CLI::line( '             SafeGrd keeps your key sealed and releases it only to your enrolled hosts,' );
			WP_CLI::line( '             so you can restore even after losing this site.' );
		} else {
			if ( ! empty( $done['escrow_failed'] ) ) {
				WP_CLI::warning( 'The server did not store the key. The key below is the only copy: save it now.' );
			}
			WP_CLI::line( '   Custody:  customer-managed key' );
			WP_CLI::line( '             Only you can decrypt these backups. Keep a copy of this key somewhere safe:' );
			WP_CLI::line( '             ' . $done['identity'] );
		}
		WP_CLI::line( 'Next: the first backup starts on WP-Cron at the next page load, then runs ' . ( 'weekly' === SafeGrd_Scheduler::frequency() ? 'once a week' : 'once a day' ) . '.' );
		WP_CLI::line( '      wp safegrd backup runs it here instead, and wp safegrd status shows how it went.' );
	}

	/**
	 * Backs up this site now and waits for it to finish.
	 *
	 * @when after_wp_load
	 */
	public function backup( $args, $assoc ) {
		$say = function ( $line ) {
			if ( 0 === strpos( $line, 'Warning: ' ) ) {
				WP_CLI::warning( substr( $line, 9 ) );
			} elseif ( 0 !== strpos( $line, 'Error: ' ) ) {
				WP_CLI::line( $line );
			}
		};
		// Slice after slice in this process: the same saved points a site
		// whose host stops long requests resumes from.
		$retries = 0;
		do {
			$backup        = new SafeGrd_Backup( $say );
			$backup->chain = false;
			$run           = $backup->run( true );
			if ( 'busy' === $run['status'] ) {
				WP_CLI::error( $run['message'] . ' Run wp safegrd status to follow it.' );
			}
			if ( 'running' === $run['status'] && ! empty( $run['message'] ) ) {
				if ( ++$retries >= SafeGrd_Backup::ATTEMPTS ) {
					break;
				}
				sleep( 30 * $retries );
			}
		} while ( 'running' === $run['status'] );
		if ( 'completed' !== $run['status'] ) {
			WP_CLI::error( isset( $run['message'] ) ? $run['message'] : 'The backup did not complete.' );
		}
		$how = 'opening' === $run['class'] ? 'every file, as the month\'s first' : 'only what changed';
		WP_CLI::line( sprintf( 'Backed up %s (%s uploaded, %s, %ss)', $run['snapshot_id'], size_format( $run['bytes'], 1 ), $how, $run['seconds'] ) );
		WP_CLI::line( sprintf( '   Database:  %d tables, %d rows', $run['tables'], $run['rows'] ) );
		WP_CLI::line( sprintf( '   Files:     %d (%s)', $run['files'], size_format( $run['raw_bytes'], 1 ) ) );
		WP_CLI::line( sprintf( '   Slices:    %d', $run['slices'] ) );
		WP_CLI::line( sprintf( '   Memory:    %s peak', size_format( $run['peak_memory'], 1 ) ) );
		if ( ! empty( $run['retain'] ) ) {
			WP_CLI::line( '   Locked until ' . substr( $run['retain'], 0, 10 ) );
		}
		foreach ( array_slice( (array) $run['skipped'], 0, 20 ) as $s ) {
			WP_CLI::line( '   Left out:  ' . $s );
		}
		if ( ! empty( $run['message'] ) ) {
			WP_CLI::warning( $run['message'] );
		}
	}

	/**
	 * Lists the backups of every WordPress site in this account that this site can restore.
	 *
	 * @when after_wp_load
	 */
	public function snapshots( $args, $assoc ) {
		$list = SafeGrd_Restore::snapshots();
		if ( is_wp_error( $list ) ) {
			WP_CLI::error( $list->get_error_message() );
		}
		if ( ! $list ) {
			WP_CLI::line( 'No WordPress backups in this account yet.' );
			return;
		}
		foreach ( $list as $s ) {
			WP_CLI::line( sprintf( '%s  %s  %s  %d tables, %d files, %s%s', $s['id'], substr( $s['taken'], 0, 16 ), $s['site'], $s['tables'], $s['files'], size_format( $s['size'], 1 ), $s['verified'] ? ', test-restored' : '' ) );
		}
	}

	/**
	 * Restores a backup onto this site, replacing its database and content directory.
	 *
	 * The tables and files it replaces are kept aside until you delete them with --delete-copy.
	 * Afterwards, sign in with an administrator account of the restored site.
	 *
	 * ## OPTIONS
	 *
	 * [<snapshot>]
	 * : The backup to restore, from wp safegrd snapshots.
	 *
	 * [--only=<parts>]
	 * : Restore only these parts, comma-separated: database, plugins, themes, uploads, others. Others is the rest of wp-content. Plugin settings are in the database.
	 *
	 * [--plugins=<names>]
	 * : Restore only these plugins, comma-separated, by directory (akismet) or file (hello.php). Every other plugin stays as it is.
	 *
	 * [--themes=<names>]
	 * : Restore only these themes, comma-separated, by directory.
	 *
	 * [--tables=<names>]
	 * : Restore only these tables, comma-separated, with or without the table prefix (wp_posts or posts). The users table brings the backup's users.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--delete-copy]
	 * : Delete the tables and files the last restore kept aside, and restore nothing.
	 *
	 * [--slice-seconds=<seconds>]
	 * : How long each slice runs. Default: the same as a backup's.
	 *
	 * @when after_wp_load
	 */
	public function restore( $args, $assoc ) {
		if ( ! empty( $assoc['delete-copy'] ) ) {
			WP_CLI::line( SafeGrd_Restore::delete_copy() );
			return;
		}
		if ( empty( $args[0] ) && ! SafeGrd_Restore::job() ) {
			WP_CLI::error( 'Name the backup to restore. wp safegrd snapshots lists them.' );
		}
		if ( ! SafeGrd_Restore::job() ) {
			$only = null;
			if ( ! empty( $assoc['only'] ) ) {
				$only    = array_filter( array_map( 'trim', explode( ',', strtolower( $assoc['only'] ) ) ) );
				$unknown = array_diff( $only, SafeGrd_Site::COMPONENTS );
				if ( $unknown ) {
					WP_CLI::error( sprintf( '%s is not a part of the site. Choose from: %s.', implode( ', ', $unknown ), implode( ', ', SafeGrd_Site::COMPONENTS ) ) );
				}
			}
			$items = self::items( $assoc, true );
			$parts = SafeGrd_Restore::describe_parts( null === $only ? ( $items ? array_keys( $items ) : SafeGrd_Site::COMPONENTS ) : array_values( array_unique( array_merge( $only, array_keys( $items ) ) ) ), $items );
			$what  = '' === $parts ? 'This site\'s database and content directory are replaced.' : sprintf( 'This site\'s %s are replaced.', $parts );
			WP_CLI::confirm( sprintf( 'Restore %s onto %s? %s The current ones are kept aside.', $args[0], home_url(), $what ), $assoc );
			$ok = SafeGrd_Restore::begin( $args[0], $only, 'restore', $items );
			if ( is_wp_error( $ok ) ) {
				WP_CLI::error( $ok->get_error_message() );
			}
		} else {
			WP_CLI::line( 'Continuing the restore under way.' );
		}
		$say = function ( $line ) {
			if ( 0 !== strpos( $line, 'Error: ' ) ) {
				WP_CLI::line( $line );
			}
		};
		$budget = isset( $assoc['slice-seconds'] ) ? (float) $assoc['slice-seconds'] : null;
		do {
			$restore        = new SafeGrd_Restore( $say, $budget );
			$restore->chain = false;
			$run            = $restore->run();
			if ( 'busy' === $run['status'] ) {
				WP_CLI::error( $run['message'] );
			}
		} while ( 'running' === $run['status'] );
		if ( 'restored' !== $run['status'] ) {
			WP_CLI::error( isset( $run['message'] ) ? $run['message'] : 'The restore did not finish.' );
		}
		$parts = SafeGrd_Restore::describe_parts( (array) ( $run['components'] ?? SafeGrd_Site::COMPONENTS ), (array) ( $run['items'] ?? array() ) );
		WP_CLI::line( sprintf( 'Restored %s of %s (taken %s): %d tables, %d rows, %d files', $run['snapshot_id'], $run['source_url'], $run['taken_at'], $run['tables'], $run['rows'], $run['files'] ) );
		if ( '' !== $parts ) {
			WP_CLI::line( '   Only: ' . $parts );
		}
		foreach ( (array) $run['notes'] as $note ) {
			WP_CLI::line( '   ' . $note );
		}
		WP_CLI::line( '   The replaced tables and files are kept aside. Delete them with: wp safegrd restore --delete-copy' );
		if ( ! empty( $run['users'] ) ) {
			WP_CLI::line( '   Sign in with an administrator account of the restored site.' );
		}
	}

	/**
	 * Writes one part of a backup to a file: the database as a gzipped SQL dump, or plugins, themes, uploads or others as a gzipped tar.
	 *
	 * ## OPTIONS
	 *
	 * <snapshot>
	 * : The backup, from wp safegrd snapshots.
	 *
	 * <part>
	 * : database, plugins, themes, uploads or others. Others is the rest of wp-content, with wp-config.php and .htaccess.
	 *
	 * [--plugins=<names>]
	 * : With plugins: only these plugins, comma-separated, by directory or file.
	 *
	 * [--themes=<names>]
	 * : With themes: only these themes, comma-separated.
	 *
	 * [--tables=<names>]
	 * : With database: only these tables, comma-separated, with or without the table prefix.
	 *
	 * [--to=<file>]
	 * : Copy the download here. Without it, the file stays on the server for a day and its path is printed.
	 *
	 * [--slice-seconds=<seconds>]
	 * : How long each slice runs. Default: the same as a backup's.
	 *
	 * @when after_wp_load
	 */
	public function download( $args, $assoc ) {
		if ( ! SafeGrd_Restore::job() ) {
			$ok = SafeGrd_Restore::begin( $args[0], array( $args[1] ), 'download', self::items( $assoc, false ) );
			if ( is_wp_error( $ok ) ) {
				WP_CLI::error( $ok->get_error_message() );
			}
		} elseif ( 'download' !== ( SafeGrd_Restore::job()['mode'] ?? '' ) ) {
			WP_CLI::error( 'A restore is under way on this site. Download when it finishes.' );
		} else {
			WP_CLI::line( 'Continuing the download under way.' );
		}
		$say    = function ( $line ) {
			if ( 0 !== strpos( $line, 'Error: ' ) ) {
				WP_CLI::line( $line );
			}
		};
		$budget = isset( $assoc['slice-seconds'] ) ? (float) $assoc['slice-seconds'] : null;
		do {
			$job        = new SafeGrd_Restore( $say, $budget );
			$job->chain = false;
			$run        = $job->run();
			if ( 'busy' === $run['status'] ) {
				WP_CLI::error( $run['message'] );
			}
		} while ( 'running' === $run['status'] );
		if ( 'ready' !== $run['status'] ) {
			WP_CLI::error( isset( $run['message'] ) ? $run['message'] : 'The download did not finish.' );
		}
		$file = SafeGrd_Restore::download_file( $run['id'] );
		if ( ! empty( $assoc['to'] ) ) {
			if ( ! copy( $file['path'], $assoc['to'] ) ) {
				WP_CLI::error( 'Could not write ' . $assoc['to'] . '.' );
			}
			SafeGrd_Restore::delete_download( $run['id'] );
			WP_CLI::success( sprintf( 'Wrote %s (%s).', $assoc['to'], size_format( $run['bytes'], 1 ) ) );
			return;
		}
		WP_CLI::success( sprintf( '%s (%s), kept on this server until %s.', $file['path'], size_format( $run['bytes'], 1 ), wp_date( 'Y-m-d H:i', $run['expires'] ) ) );
	}

	/**
	 * Lists the plugins, themes and tables a backup holds, with the version of each plugin and theme installed here.
	 *
	 * ## OPTIONS
	 *
	 * <snapshot>
	 * : The backup, from wp safegrd snapshots.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @when after_wp_load
	 */
	public function contents( $args, $assoc ) {
		$c = SafeGrd_Restore::contents( $args[0] );
		if ( is_wp_error( $c ) ) {
			WP_CLI::error( $c->get_error_message() );
		}
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		foreach ( array( 'plugins' => 'Plugins', 'themes' => 'Themes' ) as $part => $label ) {
			WP_CLI::line( $label . ':' );
			if ( ! $c[ $part ] ) {
				WP_CLI::line( '   none listed' );
			}
			foreach ( $c[ $part ] as $it ) {
				$here = null === $it['installed'] ? 'not installed here' : ( $it['installed'] === $it['version'] ? 'same as installed' : $it['installed'] . ' installed' );
				WP_CLI::line( sprintf( '   %-32s %-12s %s%s', $it['slug'], $it['version'], $here, $it['active'] ? ', was active' : '' ) );
			}
		}
		WP_CLI::line( 'Tables:' );
		foreach ( $c['tables'] as $t ) {
			WP_CLI::line( sprintf( '   %-40s %10d rows  %s', $t['name'], $t['rows'], size_format( $t['bytes'], 1 ) ) );
		}
		WP_CLI::line( sprintf( 'Restore one with: wp safegrd restore %s --plugins=<name> (or --themes, --tables)', $args[0] ) );
	}

	/**
	 * The plugins, themes and tables named with --plugins, --themes and
	 * --tables, checked.
	 *
	 * @param array $assoc   The command's options.
	 * @param bool  $restore Whether they are restored here.
	 * @return array part => names.
	 */
	private static function items( array $assoc, $restore ) {
		$items = array();
		foreach ( array( 'plugins' => 'plugins', 'themes' => 'themes', 'tables' => 'database' ) as $flag => $part ) {
			if ( isset( $assoc[ $flag ] ) ) {
				$items[ $part ] = (string) $assoc[ $flag ];
			}
		}
		$items = SafeGrd_Restore::check_items( $items, $restore );
		if ( is_wp_error( $items ) ) {
			WP_CLI::error( $items->get_error_message() );
		}
		return $items;
	}

	/**
	 * Sets how often this site backs up.
	 *
	 * ## OPTIONS
	 *
	 * <frequency>
	 * : daily or weekly.
	 *
	 * [--at=<hour>]
	 * : The hour to start at, 0 to 23 in the site's timezone, or any. WP-Cron starts it at the first visit to the site after that hour.
	 *
	 * @when after_wp_load
	 */
	public function schedule( $args, $assoc ) {
		if ( ! SafeGrd_Settings::connected() ) {
			WP_CLI::error( 'Not connected. Run wp safegrd connect first: connecting schedules the first backup at once.' );
		}
		$hour = null;
		if ( isset( $assoc['at'] ) ) {
			if ( 'any' === $assoc['at'] ) {
				$hour = -1;
			} elseif ( ctype_digit( (string) $assoc['at'] ) && (int) $assoc['at'] <= 23 ) {
				$hour = (int) $assoc['at'];
			} else {
				WP_CLI::error( sprintf( '--at=%s is not an hour. Use 0 to 23, or any.', $assoc['at'] ) );
			}
		}
		if ( ! SafeGrd_Scheduler::set_frequency( $args[0], $hour ) ) {
			WP_CLI::error( sprintf( '%s is not a schedule the plugin runs. Use daily or weekly.', $args[0] ) );
		}
		SafeGrd_Backup::report_schedule();
		$next = SafeGrd_Scheduler::next_run();
		WP_CLI::line( sprintf( 'Backs up %s%s. Next: %s (%s)', $args[0], SafeGrd_Scheduler::hour() >= 0 ? sprintf( ' from %02d:00', SafeGrd_Scheduler::hour() ) : '', wp_date( 'Y-m-d H:i', $next ), wp_timezone_string() ) );
	}

	/**
	 * Shows the connection and the last backup.
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc ) {
		if ( ! SafeGrd_Settings::connected() ) {
			WP_CLI::line( 'Not connected. Run wp safegrd connect.' );
			return;
		}
		WP_CLI::line( 'Server:   ' . SafeGrd_Settings::server_url() );
		WP_CLI::line( 'Node:     ' . SafeGrd_Settings::get( 'node_id' ) );
		WP_CLI::line( 'Custody:  ' . ( 'safegrd' === SafeGrd_Settings::get( 'key_custody' ) ? 'SafeGrd-managed key' : 'customer-managed key' ) );
		$loopback = SafeGrd_Scheduler::loopback_problem();
		if ( '' !== $loopback ) {
			WP_CLI::warning( $loopback . ' ' . SafeGrd_Scheduler::loopback_remedy() );
		}
		$storage = SafeGrd_Backup::storage_usage();
		if ( is_wp_error( $storage ) ) {
			WP_CLI::warning( 'SafeGrd did not say how much hosted storage is held: ' . $storage->get_error_message() );
		} else {
			WP_CLI::line( 'Storage:  ' . $storage['line'] );
			if ( '' !== $storage['warning'] ) {
				WP_CLI::warning( $storage['warning'] );
			}
		}
		$next = SafeGrd_Scheduler::next_run();
		WP_CLI::line( 'Next:     ' . ( $next ? gmdate( 'Y-m-d H:i', $next ) . ' UTC (' . SafeGrd_Scheduler::frequency() . ')' : 'not scheduled' ) );
		$run = SafeGrd_Settings::last_run();
		if ( ! $run ) {
			WP_CLI::line( 'Last:     none yet' );
			return;
		}
		$line = 'Last:     ' . $run['status'];
		if ( ! empty( $run['snapshot_id'] ) && 'completed' === $run['status'] ) {
			$line .= ' ' . $run['snapshot_id'];
		}
		if ( ! empty( $run['finished_at'] ) ) {
			$line .= ' at ' . $run['finished_at'];
		}
		WP_CLI::line( $line );
		if ( ! empty( $run['message'] ) ) {
			WP_CLI::line( '          ' . $run['message'] );
		}
	}

	/**
	 * Forgets the connection on this site. The node and its backups stay in the SafeGrd console.
	 *
	 * @when after_wp_load
	 */
	public function disconnect( $args, $assoc ) {
		SafeGrd_Connect::disconnect();
		WP_CLI::line( 'Disconnected. The backups already taken stay in SafeGrd, with this site\'s node.' );
	}
}
