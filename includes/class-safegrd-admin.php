<?php
/**
 * The page under Tools, SafeGrd: connect, see the last backup and drill,
 * back up now.
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

final class SafeGrd_Admin {
	const PAGE  = 'safegrd';
	const NONCE = 'safegrd_admin';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_safegrd_connect_start', array( __CLASS__, 'ajax_connect_start' ) );
		add_action( 'wp_ajax_safegrd_connect_poll', array( __CLASS__, 'ajax_connect_poll' ) );
		add_action( 'wp_ajax_safegrd_backup_now', array( __CLASS__, 'ajax_backup_now' ) );
		add_action( 'wp_ajax_safegrd_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_safegrd_disconnect', array( __CLASS__, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_safegrd_restore_list', array( __CLASS__, 'ajax_restore_list' ) );
		add_action( 'wp_ajax_safegrd_restore_start', array( __CLASS__, 'ajax_restore_start' ) );
		add_action( 'wp_ajax_safegrd_restore_delete_copy', array( __CLASS__, 'ajax_restore_delete_copy' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SAFEGRD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_management_page( 'SafeGrd Backup', 'SafeGrd', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html( SafeGrd_Settings::connected() ? 'Backups' : 'Connect' ) . '</a>' );
		return $links;
	}

	public static function url() {
		return admin_url( 'tools.php?page=' . self::PAGE );
	}

	public static function assets( $hook ) {
		if ( 'tools_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'safegrd-admin', plugins_url( 'assets/admin.css', SAFEGRD_FILE ), array(), SAFEGRD_VERSION );
		wp_enqueue_script( 'safegrd-admin', plugins_url( 'assets/admin.js', SAFEGRD_FILE ), array(), SAFEGRD_VERSION, true );
		wp_localize_script(
			'safegrd-admin',
			'SafeGrdAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE ),
			)
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$refusal = SafeGrd_Site::refusal();
		echo '<div class="wrap safegrd">';
		echo '<h1>SafeGrd Backup</h1>';
		echo '<p class="safegrd-lede">Backs up this site\'s database and files, encrypted on this server before they leave it, to storage locked against deletion. SafeGrd test-restores the newest backup on your plan\'s schedule and records the result.</p>';
		if ( '' !== $refusal ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $refusal ) . '</p></div></div>';
			return;
		}
		echo '<div id="safegrd-notice" class="notice inline" hidden><p></p></div>';
		$progress = self::describe_restore_progress();
		echo '<div id="safegrd-restore-progress" class="notice notice-info inline"' . ( '' === $progress ? ' hidden' : '' ) . '>' . wp_kses_post( $progress ) . '</div>';
		if ( ! SafeGrd_Settings::connected() ) {
			self::render_connect();
		} else {
			self::render_status();
		}
		echo '</div>';
	}

	private static function render_connect() {
		?>
		<div class="safegrd-card" id="safegrd-connect">
			<h2>Connect this site</h2>
			<p>Sign in to SafeGrd, or create a free account, in the tab that opens.</p>
			<?php echo wp_kses_post( self::free_plan() ); ?>
			<fieldset class="safegrd-custody">
				<legend>Who keeps the encryption key</legend>
				<label>
					<input type="radio" name="safegrd_custody" value="safegrd" checked>
					<strong>SafeGrd-managed key</strong>
					<span class="description">SafeGrd keeps your key sealed and releases it only to your enrolled hosts, so you can restore even after losing this site.</span>
				</label>
				<label>
					<input type="radio" name="safegrd_custody" value="local">
					<strong>Customer-managed key</strong>
					<span class="description">Only you can decrypt these backups. The key is shown once, after connecting: keep a copy somewhere safe.</span>
				</label>
			</fieldset>
			<p><button type="button" class="button button-primary" id="safegrd-connect-btn">Connect</button></p>
			<div id="safegrd-waiting" hidden>
				<p>Approve the sign-in in the tab that opened. Check that it shows this code:</p>
				<p class="safegrd-code" id="safegrd-code"></p>
				<p>No tab opened? <a href="#" id="safegrd-approve-link" target="_blank" rel="noopener">Open the sign-in page</a>.</p>
				<p class="description">This page finishes connecting on its own once you approve.</p>
			</div>
			<div id="safegrd-identity" hidden>
				<h3>Your encryption key</h3>
				<p>Only you can decrypt these backups. Keep a copy of this key somewhere safe, such as a password manager. This site keeps only its public half, and this page does not show the key again.</p>
				<textarea readonly rows="2" class="large-text code" id="safegrd-identity-text"></textarea>
				<p><a href="<?php echo esc_url( self::url() ); ?>" class="button button-primary">I saved the key</a></p>
			</div>
			<p class="description">Server: <?php echo esc_html( SafeGrd_Settings::server_url() ); ?></p>
		</div>
		<?php
	}

	private static function render_status() {
		$run     = SafeGrd_Settings::last_run();
		$custody = SafeGrd_Settings::get( 'key_custody' );
		$next    = SafeGrd_Scheduler::next_run();
		?>
		<div class="safegrd-card" id="safegrd-status">
			<h2>Backups</h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Last backup</th><td id="safegrd-last"><?php echo wp_kses_post( self::describe_run( $run ) ); ?></td></tr>
				<tr><th scope="row">Next backup</th><td><?php echo esc_html( $next ? wp_date( 'Y-m-d H:i', $next ) . ' (daily)' : 'Not scheduled' ); ?></td></tr>
				<tr><th scope="row">Last test restore</th><td id="safegrd-drill">Asking SafeGrd...</td></tr>
				<tr><th scope="row">Storage</th><td>SafeGrd hosted storage, locked against deletion</td></tr>
				<tr><th scope="row">Key</th><td>
					<?php if ( 'safegrd' === $custody ) : ?>
						<strong>SafeGrd-managed key.</strong> SafeGrd keeps your key sealed and releases it only to your enrolled hosts, so you can restore even after losing this site.
					<?php else : ?>
						<strong>Customer-managed key.</strong> Only you can decrypt these backups.
					<?php endif; ?>
				</td></tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="safegrd-backup-btn" <?php disabled( SafeGrd_Backup::running() ); ?>>Back up now</button>
				<a class="button" href="<?php echo esc_url( SafeGrd_Settings::server_url() . '/dashboard' ); ?>" target="_blank" rel="noopener">Open SafeGrd</a>
			</p>
			<h3>Recent backups</h3>
			<div id="safegrd-snapshots"><p class="description">Asking SafeGrd...</p></div>
			<h3>Restore</h3>
			<p>Restore any WordPress site's backup in this account onto this site: a new install, or this site as it was. This site's database and content directory are replaced, and the ones it had are kept aside until you delete them. <code>wp-config.php</code> stays this site's own.</p>
			<div id="safegrd-restore-state"><?php echo wp_kses_post( self::describe_restore() ); ?></div>
			<div id="safegrd-restore-list"><p class="description">Asking SafeGrd...</p></div>
			<p class="description">A backup taken with a customer-managed key restores with the safegrd command line tool and that key file. <a href="<?php echo esc_url( SafeGrd_Settings::server_url() . '/docs/surfaces/wordpress#restore' ); ?>" target="_blank" rel="noopener">How to restore</a>.</p>
			<hr>
			<p class="description">Node <?php echo esc_html( SafeGrd_Settings::get( 'node_id' ) ); ?> on <?php echo esc_html( SafeGrd_Settings::server_url() ); ?>.
				<a href="#" id="safegrd-disconnect">Disconnect this site</a>. Backups already taken stay in SafeGrd.</p>
		</div>
		<?php
	}

	/**
	 * What the free plan includes, in the server's own words: the catalogue
	 * is the server's, so this page never disagrees with what it enforces.
	 * Nothing when the server cannot be reached.
	 */
	private static function free_plan() {
		$plans = get_transient( 'safegrd_plans' );
		if ( ! is_array( $plans ) ) {
			$r = ( new SafeGrd_Client( SafeGrd_Settings::server_url() ) )->call( 'GET', '/api/v1/plans', null, 10 );
			if ( is_wp_error( $r ) || empty( $r['plans'] ) ) {
				return '';
			}
			$plans = $r['plans'];
			set_transient( 'safegrd_plans', $plans, DAY_IN_SECONDS );
		}
		foreach ( $plans as $p ) {
			if ( isset( $p['id'] ) && 'free' === $p['id'] ) {
				return sprintf(
					'<p>On the free plan: %s %s. Test restores: %s.</p>',
					esc_html( $p['tagline'] ?? '' ),
					esc_html( $p['backup_cadence'] ?? '' ),
					esc_html( strtolower( $p['drill_cadence'] ?? '' ) )
				);
			}
		}
		return '';
	}

	/**
	 * The restore under way, in HTML for the notice at the top of the page:
	 * which backup, the stage with its counts, and how long it has run.
	 * Empty when no restore is running.
	 */
	public static function describe_restore_progress() {
		$job = SafeGrd_Restore::job();
		if ( ! $job ) {
			return '';
		}
		$parts  = (int) ( $job['parts'] ?? 0 );
		$stages = array(
			'plan'     => 'Reading the backup.',
			'database' => $parts > 0
				? sprintf( 'Loading the database into new tables: part %d of %d.', min( (int) ( $job['part'] ?? 0 ) + 1, $parts ), $parts )
				: 'Loading the database into new tables.',
			'tables'   => 'Checking the tables against the backup.',
			'files'    => sprintf(
				'Writing the files: %d of %d, %s so far.',
				(int) $job['files'],
				(int) ( $job['total_files'] ?? 0 ),
				size_format( (int) ( $job['bytes'] ?? 0 ), 1 )
			),
			'swap'     => 'Swapping the restored site in.',
			'done'     => 'Finishing.',
		);
		$started = (int) ( $job['started'] ?? 0 );
		$taken   = empty( $job['taken_at'] ) ? '' : ' (taken ' . self::when( $job['taken_at'] ) . ')';
		$s       = '<p><strong>Restoring ' . esc_html( $job['snapshot_id'] . $taken ) . '.</strong> ' . esc_html( $stages[ $job['stage'] ] ?? $job['stage'] ) . '</p>';
		$s      .= '<p>' . esc_html(
			sprintf(
				'Started %s, %s ago, in %d slices so far. The site runs as it is until the swap. This page updates on its own.',
				wp_date( 'H:i', $started ),
				human_time_diff( $started ),
				(int) ( $job['slices'] ?? 0 )
			)
		) . '</p>';
		return $s;
	}

	/**
	 * The last restore, in HTML. A restore under way is described at the top
	 * of the page instead.
	 */
	public static function describe_restore() {
		if ( SafeGrd_Restore::job() ) {
			return '<p>A restore is under way. Its progress is at the top of this page.</p>';
		}
		$last = get_option( SafeGrd_Restore::LAST, array() );
		if ( ! $last ) {
			return '';
		}
		if ( ! empty( $last['failed'] ) ) {
			return '<p class="safegrd-warn"><strong>The restore of ' . esc_html( $last['snapshot_id'] ) . ' failed:</strong> ' . esc_html( $last['failed'] ) . '</p>';
		}
		$s = sprintf(
			'<p><strong>Restored %s</strong> of %s on %s: %d tables, %d rows, %d files.</p>',
			esc_html( $last['snapshot_id'] ),
			esc_html( $last['source_url'] ),
			esc_html( self::when( $last['restored_at'] ) ),
			(int) $last['tables'],
			(int) $last['rows'],
			(int) $last['files']
		);
		foreach ( (array) $last['notes'] as $note ) {
			$s .= '<p class="description">' . esc_html( $note ) . '</p>';
		}
		if ( ! empty( $last['aside'] ) ) {
			$s .= '<p>The tables and files from before the restore are kept aside. <button type="button" class="button" id="safegrd-delete-copy">Delete the copy</button></p>';
		}
		return $s;
	}

	/**
	 * The last run in one line of HTML.
	 */
	public static function describe_run( array $run ) {
		if ( ! $run ) {
			return 'None yet. The first starts on the next page load after connecting, or press Back up now.';
		}
		switch ( $run['status'] ) {
			case 'running':
				$s = sprintf(
					'Running since %s: %s, %d files read so far, %s uploaded.',
					esc_html( self::when( $run['started_at'] ) ),
					'database' === ( $run['stage'] ?? '' ) ? 'dumping the database' : ( 'finish' === ( $run['stage'] ?? '' ) ? 'writing the snapshot' : 'reading the files' ),
					(int) ( $run['files'] ?? 0 ),
					esc_html( size_format( (int) ( $run['bytes'] ?? 0 ), 1 ) )
				);
				if ( ! empty( $run['message'] ) ) {
					$s .= '<br><span class="safegrd-warn">' . esc_html( $run['message'] ) . '</span>';
				}
				return $s;
			case 'completed':
				$s = sprintf(
					'<strong>Completed</strong> %s: %d tables, %d rows, %d files, %s uploaded in %ss.',
					esc_html( self::when( $run['finished_at'] ) ),
					(int) $run['tables'],
					(int) $run['rows'],
					(int) $run['files'],
					esc_html( size_format( (int) $run['bytes'], 1 ) ),
					esc_html( (string) $run['seconds'] )
				);
				if ( ! empty( $run['retain'] ) ) {
					$s .= ' Locked until ' . esc_html( substr( $run['retain'], 0, 10 ) ) . '.';
				}
				if ( ! empty( $run['message'] ) ) {
					$s .= '<br><span class="safegrd-warn">' . esc_html( $run['message'] ) . '</span>';
				}
				if ( ! empty( $run['skipped'] ) ) {
					$s .= '<br><span class="description">Left out: ' . esc_html( implode( '; ', array_slice( $run['skipped'], 0, 5 ) ) ) . ( count( $run['skipped'] ) > 5 ? '; and ' . ( count( $run['skipped'] ) - 5 ) . ' more' : '' ) . '.</span>';
				}
				return $s;
			case 'failed':
				return '<strong class="safegrd-warn">Failed</strong> ' . esc_html( self::when( $run['finished_at'] ) ) . ': ' . esc_html( $run['message'] );
		}
		return esc_html( $run['status'] );
	}

	private static function when( $iso ) {
		$t = strtotime( (string) $iso );
		return $t ? wp_date( 'Y-m-d H:i', $t ) : '';
	}

	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Only an administrator can do this.' ), 403 );
		}
	}

	public static function ajax_connect_start() {
		self::guard();
		$custody = isset( $_POST['custody'] ) ? sanitize_key( wp_unslash( $_POST['custody'] ) ) : 'safegrd';
		$s       = SafeGrd_Connect::start( $custody );
		if ( is_wp_error( $s ) ) {
			wp_send_json_error( array( 'message' => $s->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'user_code'   => $s['user_code'],
				'approve_url' => $s['approve_url'],
			)
		);
	}

	public static function ajax_connect_poll() {
		self::guard();
		$p = SafeGrd_Connect::poll();
		if ( is_wp_error( $p ) ) {
			wp_send_json_error( array( 'message' => $p->get_error_message() ) );
		}
		wp_send_json_success( $p );
	}

	public static function ajax_backup_now() {
		self::guard();
		if ( ! SafeGrd_Scheduler::backup_now() ) {
			wp_send_json_error( array( 'message' => 'A backup is already queued or running.' ) );
		}
		wp_send_json_success( array( 'message' => 'Backup started. This page updates when it finishes.' ) );
	}

	/**
	 * The last run, and what SafeGrd has recorded: recent snapshots and the
	 * newest drill.
	 */
	public static function ajax_status() {
		self::guard();
		$out = array(
			'running'   => SafeGrd_Backup::running(),
			'restoring' => (bool) SafeGrd_Restore::job(),
			'last'      => self::describe_run( SafeGrd_Settings::last_run() ),
			'restore'   => self::describe_restore(),
			'progress'  => self::describe_restore_progress(),
		);
		if ( SafeGrd_Settings::connected() && empty( $_POST['local'] ) ) {
			$client = SafeGrd_Client::for_site();
			$node   = rawurlencode( SafeGrd_Settings::get( 'node_id' ) );
			$snaps  = $client->call( 'GET', '/api/v1/snapshots?node_id=' . $node . '&limit=5', null, 15 );
			if ( ! is_wp_error( $snaps ) ) {
				$out['snapshots'] = array();
				foreach ( ( isset( $snaps['items'] ) ? $snaps['items'] : $snaps ) as $s ) {
					$out['snapshots'][] = array(
						'id'      => $s['snapshot_id'] ?? '',
						'status'  => $s['status'] ?? '',
						'created' => self::when( $s['created_at'] ?? '' ),
						'size'    => size_format( (int) ( $s['encrypted_size_bytes'] ?? 0 ), 1 ),
						'locked'  => substr( (string) ( $s['worm_retention_until'] ?? '' ), 0, 10 ),
					);
				}
			} else {
				$out['snapshots_error'] = $snaps->get_error_message();
			}
			$drills = $client->call( 'GET', '/api/v1/verifications?node_id=' . $node . '&limit=1', null, 15 );
			if ( ! is_wp_error( $drills ) ) {
				$items = isset( $drills['items'] ) ? $drills['items'] : $drills;
				if ( $items ) {
					$v            = $items[0];
					$out['drill'] = sprintf(
						'%s %s: %s',
						'passed' === ( $v['status'] ?? '' ) ? 'Passed' : 'Failed',
						self::when( $v['completed_at'] ?? ( $v['started_at'] ?? '' ) ),
						'passed' === ( $v['status'] ?? '' ) ? 'every table, file and attachment checked' : ( $v['error_message'] ?? '' )
					);
				} else {
					$out['drill'] = 'None yet. SafeGrd test-restores the newest backup on your plan\'s schedule.';
				}
			}
		}
		wp_send_json_success( $out );
	}

	public static function ajax_restore_list() {
		self::guard();
		$list = SafeGrd_Restore::snapshots();
		if ( is_wp_error( $list ) ) {
			wp_send_json_error( array( 'message' => $list->get_error_message() ) );
		}
		foreach ( $list as &$s ) {
			$s['taken'] = self::when( $s['taken'] );
			$s['size']  = size_format( $s['size'], 1 );
		}
		unset( $s );
		wp_send_json_success( array( 'snapshots' => $list ) );
	}

	public static function ajax_restore_start() {
		self::guard();
		$id = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
		$ok = SafeGrd_Restore::begin( $id );
		if ( is_wp_error( $ok ) ) {
			wp_send_json_error( array( 'message' => $ok->get_error_message() ) );
		}
		SafeGrd_Scheduler::continue_now();
		wp_send_json_success( array( 'message' => 'Restore started. The site runs as it is until the restored one is swapped in.' ) );
	}

	public static function ajax_restore_delete_copy() {
		self::guard();
		wp_send_json_success( array( 'message' => SafeGrd_Restore::delete_copy() ) );
	}

	public static function ajax_disconnect() {
		self::guard();
		SafeGrd_Connect::disconnect();
		wp_send_json_success( array( 'message' => 'Disconnected.' ) );
	}
}
