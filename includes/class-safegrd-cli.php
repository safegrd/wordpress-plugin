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
		WP_CLI::line( 'Next: wp safegrd backup takes the first backup now. After that it runs once a day.' );
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
			WP_CLI::confirm( sprintf( 'Restore %s onto %s? This site\'s database and content directory are replaced; the current ones are kept aside.', $args[0], home_url() ), $assoc );
			$ok = SafeGrd_Restore::begin( $args[0] );
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
		WP_CLI::line( sprintf( 'Restored %s of %s (taken %s): %d tables, %d rows, %d files', $run['snapshot_id'], $run['source_url'], $run['taken_at'], $run['tables'], $run['rows'], $run['files'] ) );
		foreach ( (array) $run['notes'] as $note ) {
			WP_CLI::line( '   ' . $note );
		}
		WP_CLI::line( '   The replaced tables and files are kept aside. Delete them with: wp safegrd restore --delete-copy' );
		WP_CLI::line( '   Sign in with an administrator account of the restored site.' );
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
		$next = SafeGrd_Scheduler::next_run();
		WP_CLI::line( 'Next:     ' . ( $next ? gmdate( 'Y-m-d H:i', $next ) . ' UTC' : 'not scheduled' ) );
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
