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
		add_action( 'wp_ajax_safegrd_connect_token', array( __CLASS__, 'ajax_connect_token' ) );
		add_action( 'wp_ajax_safegrd_connect_org', array( __CLASS__, 'ajax_connect_org' ) );
		add_action( 'wp_ajax_safegrd_backup_now', array( __CLASS__, 'ajax_backup_now' ) );
		add_action( 'wp_ajax_safegrd_save_settings', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_safegrd_download_start', array( __CLASS__, 'ajax_download_start' ) );
		add_action( 'wp_ajax_safegrd_download_delete', array( __CLASS__, 'ajax_download_delete' ) );
		add_action( 'wp_ajax_safegrd_logs', array( __CLASS__, 'ajax_logs' ) );
		add_action( 'admin_post_safegrd_download', array( __CLASS__, 'serve_download' ) );
		add_action( 'wp_ajax_safegrd_tick', array( __CLASS__, 'ajax_tick' ) );
		add_action( 'wp_ajax_safegrd_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_safegrd_disconnect', array( __CLASS__, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_safegrd_restore_list', array( __CLASS__, 'ajax_restore_list' ) );
		add_action( 'wp_ajax_safegrd_restore_items', array( __CLASS__, 'ajax_restore_items' ) );
		add_action( 'wp_ajax_safegrd_restore_start', array( __CLASS__, 'ajax_restore_start' ) );
		add_action( 'wp_ajax_safegrd_restore_delete_copy', array( __CLASS__, 'ajax_restore_delete_copy' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SAFEGRD_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'update_notice' ) );
	}

	/** The screens a site is updated from, where the last backup is shown. */
	const UPDATE_SCREENS = array( 'update-core', 'plugins', 'themes' );

	/**
	 * On the Updates, Plugins and Themes screens: how old the last backup
	 * is, with Back up now, so an update starts from a backup of the
	 * versions it replaces. On Plugins and Themes only while updates wait.
	 */
	public static function update_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, self::UPDATE_SCREENS, true ) || ! current_user_can( 'manage_options' ) || ! SafeGrd_Settings::connected() || '' !== SafeGrd_Site::refusal() ) {
			return;
		}
		if ( 'update-core' !== $screen->id && ! self::updates_waiting() ) {
			return;
		}
		$run = SafeGrd_Settings::last_run();
		$old = empty( $run['finished_at'] ) || 'completed' !== ( $run['status'] ?? '' ) || time() - strtotime( $run['finished_at'] ) > DAY_IN_SECONDS;
		printf(
			'<div class="notice notice-%s" id="safegrd-update-notice"><p><strong>SafeGrd:</strong> <span id="safegrd-update-last">%s</span> <button type="button" class="button button-small" id="safegrd-update-backup"%s>Back up now</button></p><p class="description">If an update breaks the site, restore that plugin or theme from the backup under <a href="%s">Tools, SafeGrd, Restore</a>.</p></div>',
			esc_attr( $old ? 'warning' : 'info' ),
			esc_html( self::describe_last_short() ),
			disabled( SafeGrd_Backup::running(), true, false ),
			esc_url( self::url() . '#restore' )
		);
	}

	/** Whether WordPress has plugin or theme updates waiting. */
	private static function updates_waiting() {
		foreach ( array( 'update_plugins', 'update_themes' ) as $t ) {
			$u = get_site_transient( $t );
			if ( is_object( $u ) && ! empty( $u->response ) ) {
				return true;
			}
		}
		return false;
	}

	/** The last backup in one sentence of plain text. */
	public static function describe_last_short() {
		$run = SafeGrd_Settings::last_run();
		if ( ! $run ) {
			return 'This site has no backup yet.';
		}
		switch ( $run['status'] ) {
			case 'running':
				return sprintf( 'Backing up now: %d files read, %s uploaded.', (int) ( $run['files'] ?? 0 ), size_format( (int) ( $run['bytes'] ?? 0 ), 1 ) );
			case 'failed':
				return 'The last backup failed: ' . rtrim( (string) $run['message'], '.' ) . '.';
		}
		$t = strtotime( (string) ( $run['finished_at'] ?? '' ) );
		return $t ? sprintf( 'Last backup %s ago, %s.', human_time_diff( $t ), wp_date( 'Y-m-d H:i', $t ) ) : 'This site has no backup yet.';
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
		if ( in_array( $hook, array( 'update-core.php', 'plugins.php', 'themes.php' ), true ) ) {
			if ( ! SafeGrd_Settings::connected() || ! current_user_can( 'manage_options' ) || ( 'update-core.php' !== $hook && ! self::updates_waiting() ) ) {
				return;
			}
			// The last loopback check, without making one on these screens:
			// unknown is taken as working, and WP-Cron stands behind it.
			$loopback = get_transient( SafeGrd_Scheduler::LOOPBACK );
			wp_enqueue_script( 'safegrd-updates', plugins_url( 'assets/updates.js', SAFEGRD_FILE ), array(), SAFEGRD_VERSION, true );
			wp_localize_script(
				'safegrd-updates',
				'SafeGrdAdmin',
				array(
					'ajax'     => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( self::NONCE ),
					'loopback' => false === $loopback || '' === $loopback,
				)
			);
			return;
		}
		if ( 'tools_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'safegrd-admin', plugins_url( 'assets/admin.css', SAFEGRD_FILE ), array(), SAFEGRD_VERSION );
		wp_enqueue_script( 'safegrd-admin', plugins_url( 'assets/admin.js', SAFEGRD_FILE ), array(), SAFEGRD_VERSION, true );
		wp_localize_script(
			'safegrd-admin',
			'SafeGrdAdmin',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'server'   => SafeGrd_Settings::server_url(),
				'nonce'    => wp_create_nonce( self::NONCE ),
				// The page runs slices itself where the site cannot reach itself.
				'loopback' => SafeGrd_Settings::connected() && '' === SafeGrd_Scheduler::loopback_problem(),
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
		if ( SafeGrd_Settings::connected() ) {
			$loopback = SafeGrd_Scheduler::loopback_problem();
			if ( '' !== $loopback ) {
				echo '<div id="safegrd-loopback" class="notice notice-warning inline"><p><strong>' . esc_html( $loopback ) . '</strong></p><p>' . esc_html( SafeGrd_Scheduler::loopback_remedy( true ) ) . '</p></div>';
			}
		}
		if ( SafeGrd_Settings::connected() ) {
			$health = self::describe_health();
			if ( $health ) {
				echo '<div id="safegrd-health" class="notice notice-' . esc_attr( $health[0] ) . ' inline safegrd-health"><p><strong>' . esc_html( $health[1] ) . '</strong> ' . esc_html( $health[2] ) . '</p></div>';
			}
		}
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
			<details class="safegrd-token" id="safegrd-token">
				<summary>Connect with a token instead</summary>
				<p class="description">Create a personal access token under Tokens in the SafeGrd console and paste it here. The site uses it once to register and does not keep it.</p>
				<p>
					<label for="safegrd-token-text">Personal access token</label><br>
					<input type="password" id="safegrd-token-text" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="sg_pat_...">
				</p>
				<p><button type="button" class="button" id="safegrd-token-btn">Connect with token</button></p>
			</details>
			<div id="safegrd-orgs" hidden>
				<p>This account belongs to several organizations. Pick the one this site belongs to.</p>
				<p>
					<label for="safegrd-org">Organization</label><br>
					<select id="safegrd-org"></select>
				</p>
				<p><button type="button" class="button button-primary" id="safegrd-org-btn">Connect</button></p>
			</div>
			<div id="safegrd-waiting" hidden>
				<p>Approve the sign-in in the tab that opened. Check that it shows this code:</p>
				<p class="safegrd-code" id="safegrd-code"></p>
				<p>No tab opened? <a href="#" id="safegrd-approve-link" target="_blank" rel="noopener">Open the sign-in page</a>.</p>
				<p class="description">This page refreshes periodically and finishes connecting once you approve.</p>
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
		$hour    = SafeGrd_Scheduler::hour();
		$tabs    = array(
			'backups'  => 'Backups',
			'restore'  => 'Restore & download',
			'settings' => 'Settings',
			'logs'     => 'Logs',
			'help'     => 'Help',
		);
		?>
		<nav class="nav-tab-wrapper safegrd-tabs" id="safegrd-tabs">
			<?php foreach ( $tabs as $id => $label ) : ?>
				<a href="#<?php echo esc_attr( $id ); ?>" class="nav-tab<?php echo 'backups' === $id ? ' nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="safegrd-panel" data-panel="backups" id="safegrd-status">
			<div class="safegrd-grid">
				<div class="safegrd-card">
					<h2>Backups</h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row">Last backup</th><td id="safegrd-last"><?php echo wp_kses_post( self::describe_run( $run ) ); ?></td></tr>
						<tr><th scope="row">Next backup</th><td>
							<span id="safegrd-next"><?php echo esc_html( $next ? wp_date( 'Y-m-d H:i', $next ) : 'Not scheduled' ); ?></span>
							<span class="description" id="safegrd-cadence"><?php echo esc_html( self::describe_cadence() ); ?></span>
							<a href="#settings" data-tab-link="settings">Change</a>
						</td></tr>
						<tr><th scope="row">Last test restore</th><td id="safegrd-drill">Asking SafeGrd...</td></tr>
					</table>
					<p>
						<button type="button" class="button button-primary" id="safegrd-backup-btn" <?php disabled( SafeGrd_Backup::running() ); ?>>Back up now</button>
						<a class="button" href="<?php echo esc_url( SafeGrd_Settings::server_url() . '/dashboard' ); ?>" target="_blank" rel="noopener">Open SafeGrd</a>
					</p>
				</div>
				<div class="safegrd-card">
					<h2>Storage</h2>
					<div class="safegrd-meter" id="safegrd-meter" hidden><span></span></div>
					<p id="safegrd-storage">Asking SafeGrd...</p>
					<p class="description">SafeGrd hosted storage, locked against deletion: Object Lock in compliance mode keeps each backup until its date, and no one can delete it sooner.</p>
					<p>
						<?php if ( 'safegrd' === $custody ) : ?>
							<strong>SafeGrd-managed key.</strong> SafeGrd keeps your key sealed and releases it only to your enrolled hosts, so you can restore even after losing this site.
						<?php else : ?>
							<strong>Customer-managed key.</strong> Only you can decrypt these backups.
						<?php endif; ?>
					</p>
				</div>
			</div>
			<div class="safegrd-card">
				<h2>Recent backups</h2>
				<p class="description">The first backup of each month uploads every file. Later ones upload only what changed since, so they are small. Every backup is still a full restore point.</p>
				<div id="safegrd-snapshots"><p class="description">Asking SafeGrd...</p></div>
			</div>
		</div>

		<div class="safegrd-panel" data-panel="restore" hidden>
			<div class="safegrd-card">
				<h2>Restore, migrate or download</h2>
				<p>Restore any WordPress backup in this account onto this site, all of it or only some parts: the database, plugins, themes, uploads, and the rest of wp-content. Under <em>Choose plugins, themes or tables</em>, restore single ones, such as a plugin from before an update. What a restore replaces is kept aside until you delete it. <code>wp-config.php</code> stays this site's own.</p>
				<p class="description">To migrate a site to a new host or domain, install WordPress and this plugin there, connect it to the same SafeGrd account, and restore the old site's backup with its database. The restore runs a search and replace from the old URL to the new one, serialized data included, and renames the tables to the new site's table prefix.</p>
				<p class="description">Plugin and theme settings are in the database: restoring plugins alone brings back their files, not their settings.</p>
				<div id="safegrd-restore-state"><?php echo wp_kses_post( self::describe_restore() ); ?></div>
				<div id="safegrd-restore-list"><p class="description">Asking SafeGrd...</p></div>
				<p class="description">A backup taken with a customer-managed key restores and downloads with the safegrd command line tool and that key file. <a href="<?php echo esc_url( SafeGrd_Settings::server_url() . '/docs/surfaces/wordpress#restore' ); ?>" target="_blank" rel="noopener">How to restore</a>.</p>
			</div>
			<div class="safegrd-card">
				<h2>Downloads</h2>
				<p class="description">A download is written on this server, decrypted, then fetched from this page. It is deleted from the server a day after it is written. The database is a gzipped SQL dump; files are a gzipped tar.</p>
				<div id="safegrd-downloads"><?php echo wp_kses_post( self::describe_downloads() ); ?></div>
			</div>
		</div>

		<div class="safegrd-panel" data-panel="settings" hidden>
			<div class="safegrd-card">
				<h2>Schedule</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="safegrd-frequency">How often</label></th><td>
						<select id="safegrd-frequency">
							<?php foreach ( array( 'daily' => 'Daily', 'weekly' => 'Weekly' ) as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( SafeGrd_Scheduler::frequency(), $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
					<tr><th scope="row"><label for="safegrd-hour">Start at</label></th><td>
						<select id="safegrd-hour">
							<option value="-1" <?php selected( $hour, -1 ); ?>>Any time</option>
							<?php for ( $h = 0; $h < 24; $h++ ) : ?>
								<option value="<?php echo esc_attr( (string) $h ); ?>" <?php selected( $hour, $h ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
							<?php endfor; ?>
						</select>
						<p class="description">In the site's timezone, <?php echo esc_html( wp_timezone_string() ); ?>. WP-Cron starts the backup at the first visit to the site after this time, so a quiet site may start later.</p>
					</td></tr>
				</table>
			</div>
			<div class="safegrd-card">
				<h2>Leave out of backups</h2>
				<p class="description">One per line. A path from the site root leaves out everything under it, such as <code><?php echo esc_html( SafeGrd_Site::content_path() ); ?>/ewww</code>. A name leaves out files and directories of that name anywhere but uploads, such as <code>*.zip</code> or <code>node_modules</code>. Uploads stay in every backup: the test restore checks that every attachment the database names is there.</p>
				<p class="description">Already left out: caches, the upgrade directory, debug.log, and other backup plugins' archives.</p>
				<textarea id="safegrd-exclude" rows="5" class="large-text code" spellcheck="false"><?php echo esc_textarea( implode( "\n", (array) SafeGrd_Settings::get( 'leave_out', array() ) ) ); ?></textarea>
			</div>
			<p><button type="button" class="button button-primary" id="safegrd-save-settings">Save settings</button></p>
		</div>

		<div class="safegrd-panel" data-panel="logs" hidden>
			<div class="safegrd-card">
				<h2>Logs</h2>
				<p class="description">What the last <?php echo esc_html( (string) SafeGrd_Log::RUNS ); ?> backups, restores and downloads on this site printed. Support asks for these.</p>
				<div id="safegrd-logs"></div>
				<pre class="safegrd-log" id="safegrd-log-text" hidden></pre>
				<p id="safegrd-log-actions" hidden><button type="button" class="button" id="safegrd-copy-log">Copy log</button></p>
			</div>
		</div>

		<div class="safegrd-panel" data-panel="help" hidden>
			<div class="safegrd-card">
				<h2>Help</h2>
				<p>
					<a href="<?php echo esc_url( SafeGrd_Settings::server_url() . '/docs/surfaces/wordpress' ); ?>" target="_blank" rel="noopener">WordPress guide</a>
					&middot; <a href="<?php echo esc_url( SafeGrd_Settings::server_url() . '/contact' ); ?>" target="_blank" rel="noopener">Contact support</a>
					&middot; <a href="mailto:support@safegrd.dev">support@safegrd.dev</a>
				</p>
				<p>Email and Slack alerts for a failed backup or test restore are set in the SafeGrd console, under <a href="<?php echo esc_url( SafeGrd_Settings::server_url() . '/dashboard#settings' ); ?>" target="_blank" rel="noopener">Settings, Alerts</a>.</p>
				<p class="description">Support asks for these first. They hold no token or key.</p>
				<textarea readonly rows="6" class="large-text code" id="safegrd-diagnostics"><?php echo esc_textarea( self::diagnostics() ); ?></textarea>
				<p><button type="button" class="button" id="safegrd-copy-diagnostics">Copy diagnostics</button></p>
			</div>
			<p class="description">Node <?php echo esc_html( SafeGrd_Settings::get( 'node_id' ) ); ?> on <?php echo esc_html( SafeGrd_Settings::server_url() ); ?>.
				<a href="#" id="safegrd-disconnect">Disconnect this site</a>. Backups already taken stay in SafeGrd.</p>
		</div>
		<?php
	}

	/** How often and when backups run, in words. */
	public static function describe_cadence() {
		$h = SafeGrd_Scheduler::hour();
		$f = 'weekly' === SafeGrd_Scheduler::frequency() ? 'Weekly' : 'Daily';
		return $h >= 0 ? sprintf( '%s, from %02d:00', $f, $h ) : $f;
	}

	/** The downloads still on the server, in HTML, each with its link. */
	public static function describe_downloads() {
		$list   = SafeGrd_Restore::downloads();
		$failed = get_option( 'safegrd_download_failed', array() );
		$s      = '';
		if ( ! empty( $failed['message'] ) && (int) $failed['at'] > time() - DAY_IN_SECONDS ) {
			$s .= '<p class="safegrd-warn"><strong>The download of the ' . esc_html( $failed['component'] ) . ' of ' . esc_html( $failed['snapshot_id'] ) . ' failed:</strong> ' . esc_html( $failed['message'] ) . '</p>';
		}
		if ( ! $list ) {
			return $s . '<p class="description">None. Choose a part of a backup under Restore, migrate or download.</p>';
		}
		$s .= '<table class="widefat striped"><thead><tr><th>File</th><th>Of</th><th>Size</th><th>Deleted</th><th></th></tr></thead><tbody>';
		foreach ( $list as $id => $d ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=safegrd_download&id=' . $id ), 'safegrd_download_' . $id );
			$s  .= '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( $d['name'] ) . '</a></td>'
				. '<td>' . esc_html( $d['source_url'] . ', ' . self::when( $d['taken_at'] ) ) . '</td>'
				. '<td>' . esc_html( size_format( (int) $d['bytes'], 1 ) ) . '</td>'
				. '<td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $d['expires'] ) ) . '</td>'
				. '<td><a class="button" href="' . esc_url( $url ) . '">Download</a> <button type="button" class="button-link safegrd-delete-download" data-id="' . esc_attr( $id ) . '">Delete</button></td></tr>';
		}
		return $s . '</tbody></table>';
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
		$started  = (int) ( $job['started'] ?? 0 );
		$taken    = empty( $job['taken_at'] ) ? '' : ' (taken ' . self::when( $job['taken_at'] ) . ')';
		$download = 'download' === ( $job['mode'] ?? 'restore' );
		if ( $download ) {
			$stages['database'] = sprintf( 'Writing the database dump: part %d of %d.', min( (int) ( $job['seq'] ?? 0 ) + 1, max( 1, $parts ) ), max( 1, $parts ) );
			$stages['files']    = sprintf( 'Writing the files: %d of %d, %s so far.', (int) $job['files'], (int) ( $job['total_files'] ?? 0 ), size_format( (int) ( $job['bytes'] ?? 0 ), 1 ) );
		}
		$parts_of = SafeGrd_Restore::describe_parts( (array) ( $job['components'] ?? SafeGrd_Site::COMPONENTS ), (array) ( $job['items'] ?? array() ) );
		$what     = '' === $parts_of ? '' : ' (' . $parts_of . ')';
		$s        = '<p><strong>' . ( $download ? 'Writing a download of ' : 'Restoring ' ) . esc_html( $job['snapshot_id'] . $what . $taken ) . '.</strong> ' . esc_html( $stages[ $job['stage'] ] ?? $job['stage'] ) . '</p>';
		$s       .= '<p>' . esc_html(
			sprintf(
				$download ? 'Started %s, %s ago, in %d slices so far. This page refreshes periodically.' : 'Started %s, %s ago, in %d slices so far. The site runs as it is until the swap. This page refreshes periodically.',
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
			return '<p>' . ( 'download' === ( SafeGrd_Restore::job()['mode'] ?? '' ) ? 'A download is being written.' : 'A restore is under way.' ) . ' Its progress is at the top of this page.</p>';
		}
		$last = get_option( SafeGrd_Restore::LAST, array() );
		if ( ! $last ) {
			return '';
		}
		if ( ! empty( $last['failed'] ) ) {
			return '<p class="safegrd-warn"><strong>The restore of ' . esc_html( $last['snapshot_id'] ) . ' failed:</strong> ' . esc_html( $last['failed'] ) . '</p>';
		}
		$only = SafeGrd_Restore::describe_parts( (array) ( $last['components'] ?? SafeGrd_Site::COMPONENTS ), (array) ( $last['items'] ?? array() ) );
		$s    = sprintf(
			'<p><strong>Restored %s%s</strong> of %s on %s: %d tables, %d rows, %d files.</p>',
			esc_html( $last['snapshot_id'] ),
			esc_html( '' === $only ? '' : ' (' . $only . ')' ),
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
	 * What support needs to know about this site, as plain text: versions,
	 * limits, the loopback check and the last run. Never a token or a key.
	 */
	private static function diagnostics() {
		global $wpdb;
		$run   = SafeGrd_Settings::last_run();
		$lines = array(
			'SafeGrd Backup ' . SAFEGRD_VERSION,
			'WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ', ' . ( method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : 'database unknown' ),
			'Site ' . home_url() . ( is_multisite() ? ' (multisite)' : '' ),
			'Server ' . SafeGrd_Settings::server_url() . ', node ' . SafeGrd_Settings::get( 'node_id' ) . ', organization ' . SafeGrd_Settings::get( 'org_id' ),
			'Key ' . ( 'safegrd' === SafeGrd_Settings::get( 'key_custody' ) ? 'SafeGrd-managed' : 'customer-managed' ) . ', schedule ' . SafeGrd_Scheduler::frequency(),
			'max_execution_time ' . (int) ini_get( 'max_execution_time' ) . ', memory_limit ' . ini_get( 'memory_limit' ) . ', slice ' . SafeGrd_Backup::default_budget() . ' s',
			'WP-Cron ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled (DISABLE_WP_CRON)' : 'on' ) . ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ? ', ALTERNATE_WP_CRON' : '' ),
			'Loopback ' . ( '' === SafeGrd_Scheduler::loopback_problem() ? 'works' : SafeGrd_Scheduler::loopback_problem() ),
		);
		if ( $run ) {
			$lines[] = 'Last run ' . ( $run['status'] ?? '' ) . ' ' . ( $run['snapshot_id'] ?? '' ) . ' ' . ( $run['finished_at'] ?? ( $run['started_at'] ?? '' ) ) . ( empty( $run['message'] ) ? '' : ': ' . $run['message'] );
		}
		return implode( "\n", $lines );
	}

	/**
	 * How often the plan test-restores and when the next is due, in the
	 * server's terms, or why the next cannot run. Empty when the server did
	 * not say.
	 *
	 * @param array|WP_Error $node GET /api/v1/nodes/{id}.
	 */
	private static function describe_next_drill( $node ) {
		if ( is_wp_error( $node ) || empty( $node['next'] ) ) {
			return '';
		}
		$n = $node['next'];
		if ( ! empty( $n['drill_blocked'] ) ) {
			return 'The next cannot run: ' . rtrim( $n['drill_blocked'], '.' ) . '.';
		}
		$s = empty( $n['drill_cadence'] ) ? '' : sprintf( '%s on your plan.', $n['drill_cadence'] );
		if ( ! empty( $n['drill_at'] ) ) {
			$at = strtotime( $n['drill_at'] );
			$s .= ' ' . ( $at <= time() + 60 ? 'The next is due now.' : sprintf( 'The next is due %s.', wp_date( 'Y-m-d H:i', $at ) ) );
		}
		return trim( $s );
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
					esc_html( self::when_ago( $run['finished_at'] ) ),
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
				return '<strong class="safegrd-warn">Failed</strong> ' . esc_html( self::when_ago( $run['finished_at'] ) ) . ': ' . esc_html( $run['message'] );
		}
		return esc_html( $run['status'] );
	}

	/** A time as a date and how long ago: "2026-10-08 18:57, 3 mins ago". */
	private static function when_ago( $iso ) {
		$t = strtotime( (string) $iso );
		return $t ? wp_date( 'Y-m-d H:i', $t ) . ', ' . human_time_diff( $t ) . ' ago' : '';
	}

	/**
	 * The one line at the top of the page: whether this site is backed up,
	 * and what to look at when it is not.
	 */
	private static function describe_health() {
		$run = SafeGrd_Settings::last_run();
		if ( SafeGrd_Restore::job() ) {
			return '';
		}
		if ( ! $run ) {
			return array( 'warning', 'Not backed up yet.', 'The first backup starts on the next page load, or press Back up now.' );
		}
		if ( 'running' === $run['status'] ) {
			return array( 'info', 'Backing up now.', 'Started ' . self::when_ago( $run['started_at'] ?? '' ) . '.' );
		}
		if ( 'failed' === $run['status'] ) {
			return array( 'error', 'The last backup failed.', (string) $run['message'] );
		}
		$t = strtotime( (string) ( $run['finished_at'] ?? '' ) );
		if ( $t && time() - $t > 2 * ( 'weekly' === SafeGrd_Scheduler::frequency() ? WEEK_IN_SECONDS : DAY_IN_SECONDS ) ) {
			return array( 'warning', 'No backup for ' . human_time_diff( $t ) . '.', 'This site backs up ' . SafeGrd_Scheduler::frequency() . '. Check the loopback notice above, if there is one, or press Back up now.' );
		}
		return array( 'success', 'Protected.', 'Backed up ' . human_time_diff( $t ) . ' ago, encrypted and locked against deletion.' );
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
		$custody = isset( $_POST['custody'] ) ? sanitize_key( wp_unslash( $_POST['custody'] ) ) : 'safegrd'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::guard() checked the nonce.
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

	public static function ajax_connect_token() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() checked the nonce.
		$token   = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		$custody = isset( $_POST['custody'] ) ? sanitize_key( wp_unslash( $_POST['custody'] ) ) : 'safegrd';
		$org     = isset( $_POST['org'] ) ? sanitize_text_field( wp_unslash( $_POST['org'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => 'Paste a personal access token from Tokens in the SafeGrd console.' ) );
		}
		$done = SafeGrd_Connect::register( $token, $custody, $org );
		if ( is_wp_error( $done ) && 'safegrd_many_orgs' === $done->get_error_code() ) {
			wp_send_json_success(
				array(
					'status' => 'pick_org',
					'orgs'   => SafeGrd_Connect::org_choices( $done ),
				)
			);
		}
		if ( is_wp_error( $done ) ) {
			wp_send_json_error( array( 'message' => $done->get_error_message() ) );
		}
		$done['status'] = 'connected';
		wp_send_json_success( $done );
	}

	/**
	 * Finishes a browser sign-in once the person picked an organization.
	 */
	public static function ajax_connect_org() {
		self::guard();
		$org = isset( $_POST['org'] ) ? sanitize_text_field( wp_unslash( $_POST['org'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::guard() checked the nonce.
		$p   = SafeGrd_Connect::pick_org( $org );
		if ( is_wp_error( $p ) ) {
			wp_send_json_error( array( 'message' => $p->get_error_message() ) );
		}
		wp_send_json_success( $p );
	}

	/**
	 * One slice of the backup or restore under way, run by the Tools page
	 * where the site cannot reach itself to run it.
	 */
	public static function ajax_tick() {
		self::guard();
		if ( SafeGrd_Restore::job() ) {
			$job        = new SafeGrd_Restore();
			$job->chain = false;
			$run        = $job->run();
		} else {
			$job        = new SafeGrd_Backup();
			$job->chain = false;
			$run        = $job->run( false );
		}
		wp_send_json_success( array( 'status' => $run['status'] ?? '' ) );
	}

	public static function ajax_save_settings() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() checked the nonce.
		$f    = isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : '';
		$hour = isset( $_POST['hour'] ) ? (int) $_POST['hour'] : -1;
		$text = isset( $_POST['leave_out'] ) ? sanitize_textarea_field( wp_unslash( $_POST['leave_out'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$exclude = SafeGrd_Site::check_exclusions( $text );
		if ( is_wp_error( $exclude ) ) {
			wp_send_json_error( array( 'message' => $exclude->get_error_message() ) );
		}
		if ( ! SafeGrd_Scheduler::set_frequency( $f, $hour ) ) {
			wp_send_json_error( array( 'message' => 'Choose daily or weekly, and an hour from 00:00 to 23:00.' ) );
		}
		SafeGrd_Settings::update( array( 'leave_out' => $exclude ) );
		SafeGrd_Backup::report_schedule();
		$next = SafeGrd_Scheduler::next_run();
		wp_send_json_success(
			array(
				'next'    => wp_date( 'Y-m-d H:i', $next ),
				'cadence' => self::describe_cadence(),
				'leave_out' => implode( "\n", $exclude ),
				'message' => sprintf( 'Saved. Backs up %s. The next is due %s.', strtolower( self::describe_cadence() ), wp_date( 'Y-m-d H:i', $next ) ),
			)
		);
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
			'running'    => SafeGrd_Backup::running(),
			'restoring'  => (bool) SafeGrd_Restore::job(),
			'last'       => self::describe_run( SafeGrd_Settings::last_run() ),
			'restore'    => self::describe_restore(),
			'progress'   => self::describe_restore_progress(),
			'downloads'  => self::describe_downloads(),
			'cadence'    => self::describe_cadence(),
			'last_short' => self::describe_last_short(),
		);
		if ( SafeGrd_Settings::connected() && empty( $_POST['local'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::guard() checked the nonce.
			$client = SafeGrd_Client::for_site();
			$node   = rawurlencode( SafeGrd_Settings::get( 'node_id' ) );
			$snaps  = $client->call( 'GET', '/api/v1/snapshots?node_id=' . $node . '&limit=5', null, 15 );
			if ( ! is_wp_error( $snaps ) ) {
				$logs             = SafeGrd_Log::all();
				$out['snapshots'] = array();
				foreach ( ( isset( $snaps['items'] ) ? $snaps['items'] : $snaps ) as $s ) {
					$out['snapshots'][] = array(
						'id'      => $s['snapshot_id'] ?? '',
						'status'  => $s['status'] ?? '',
						'created' => self::when( $s['created_at'] ?? '' ),
						'size'    => size_format( (int) ( $s['encrypted_size_bytes'] ?? 0 ), 1 ),
						'site'    => size_format( (int) ( $s['raw_size_bytes'] ?? 0 ), 1 ),
						'kind'    => 'opening' === ( $s['object_class'] ?? '' ) ? 'Full, the month\'s first' : ( 'later' === ( $s['object_class'] ?? '' ) ? 'Changes only' : '' ),
						'locked'  => substr( (string) ( $s['worm_retention_until'] ?? '' ), 0, 10 ),
						'log'     => isset( $logs[ $s['snapshot_id'] ?? '' ] ),
					);
				}
			} else {
				$out['snapshots_error'] = $snaps->get_error_message();
			}
			$storage = SafeGrd_Backup::storage_usage();
			if ( is_wp_error( $storage ) ) {
				$out['storage'] = 'SafeGrd did not answer: ' . $storage->get_error_message();
			} else {
				$out['storage']         = $storage['line'];
				$out['storage_warning'] = $storage['warning'];
				$out['storage_used']    = $storage['used'];
				$out['storage_quota']   = $storage['quota'];
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
					$out['drill'] = 'None yet.';
				}
			}
			$out['drill_next'] = self::describe_next_drill( $client->call( 'GET', '/api/v1/nodes/' . $node, null, 15 ) );
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

	/**
	 * What a backup holds, for the picker: its plugins and themes with their
	 * versions beside this site's, and its tables.
	 */
	public static function ajax_restore_items() {
		self::guard();
		$id  = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::guard() checked the nonce.
		$out = SafeGrd_Restore::contents( $id );
		if ( is_wp_error( $out ) ) {
			wp_send_json_error( array( 'message' => $out->get_error_message() ) );
		}
		foreach ( $out['tables'] as &$t ) {
			$t['size'] = size_format( $t['bytes'], 1 );
		}
		unset( $t );
		wp_send_json_success( $out );
	}

	public static function ajax_restore_start() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() checked the nonce.
		$id    = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
		$parts = isset( $_POST['parts'] ) ? array_map( 'sanitize_key', explode( ',', sanitize_text_field( wp_unslash( $_POST['parts'] ) ) ) ) : null;
		// Some plugins, themes or tables: {"plugins":["akismet"],"database":["wp_posts"]}. SafeGrd_Restore::check_items() checks every name.
		$items = isset( $_POST['items'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['items'] ) ), true ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$ok = SafeGrd_Restore::begin( $id, $parts, 'restore', is_array( $items ) ? $items : array() );
		if ( is_wp_error( $ok ) ) {
			wp_send_json_error( array( 'message' => $ok->get_error_message() ) );
		}
		SafeGrd_Scheduler::continue_now();
		wp_send_json_success( array( 'message' => 'Restore started. The site runs as it is until the restored one is swapped in.' ) );
	}

	public static function ajax_download_start() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- self::guard() checked the nonce.
		$id   = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
		$part = isset( $_POST['part'] ) ? sanitize_key( wp_unslash( $_POST['part'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$ok = SafeGrd_Restore::begin( $id, array( $part ), 'download' );
		if ( is_wp_error( $ok ) ) {
			wp_send_json_error( array( 'message' => $ok->get_error_message() ) );
		}
		delete_option( 'safegrd_download_failed' );
		SafeGrd_Scheduler::continue_now();
		wp_send_json_success( array( 'message' => 'Writing the download. It is listed under Downloads when it is ready.' ) );
	}

	public static function ajax_download_delete() {
		self::guard();
		SafeGrd_Restore::delete_download( isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::guard() checked the nonce.
		wp_send_json_success( array( 'downloads' => self::describe_downloads() ) );
	}

	/** The runs whose logs are kept, or one run's log as text. */
	public static function ajax_logs() {
		self::guard();
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- self::guard() checked the nonce.
		if ( '' !== $key ) {
			$text = SafeGrd_Log::text( $key );
			if ( '' === $text ) {
				wp_send_json_error( array( 'message' => 'That log is no longer kept.' ) );
			}
			wp_send_json_success( array( 'text' => $text ) );
		}
		$out = array();
		foreach ( SafeGrd_Log::all() as $k => $r ) {
			$out[] = array(
				'key'     => (string) $k,
				'kind'    => ucfirst( $r['kind'] ),
				'label'   => $r['label'],
				'status'  => $r['status'],
				'started' => wp_date( 'Y-m-d H:i', (int) $r['started'] ),
				'lines'   => count( $r['lines'] ),
			);
		}
		wp_send_json_success( array( 'runs' => $out ) );
	}

	/**
	 * Sends a download's file, to an administrator with the link's nonce.
	 * The id names the download; its path comes from the plugin's record.
	 */
	public static function serve_download() {
		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'safegrd_download_' . $id ) ) {
			wp_die( esc_html( 'Only an administrator can download backups, from Tools, SafeGrd.' ), '', array( 'response' => 403 ) );
		}
		$file = SafeGrd_Restore::download_file( $id );
		if ( null === $file ) {
			wp_die( esc_html( 'This download is no longer on the server. Write it again from Tools, SafeGrd.' ), '', array( 'response' => 404 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- a large file takes as long as the connection needs; some hosts disable the function.
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $file['name'] ) . '"' );
		header( 'Content-Length: ' . filesize( $file['path'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams the plugin's own file to an administrator.
		exit;
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
