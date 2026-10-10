<?php
/**
 * Plugin Name:       SafeGrd Backup
 * Plugin URI:        https://safegrd.dev/docs/surfaces/wordpress
 * Description:       Backs up the site's database and files, encrypted on this server, to locked storage, and test-restores the backups on a schedule.
 * Version:           0.1.7
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            SafeGrd
 * Author URI:        https://safegrd.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       safegrd-backup
 *
 * @package SafeGrd
 */

defined( 'ABSPATH' ) || exit;

define( 'SAFEGRD_VERSION', '0.1.7' );
define( 'SAFEGRD_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-safegrd-settings.php';
require_once __DIR__ . '/includes/class-safegrd-log.php';
require_once __DIR__ . '/includes/class-safegrd-client.php';
require_once __DIR__ . '/includes/class-safegrd-age.php';
require_once __DIR__ . '/includes/class-safegrd-exception.php';
require_once __DIR__ . '/includes/class-safegrd-repo.php';
require_once __DIR__ . '/includes/class-safegrd-site.php';
require_once __DIR__ . '/includes/class-safegrd-dumper.php';
require_once __DIR__ . '/includes/class-safegrd-backup.php';
require_once __DIR__ . '/includes/class-safegrd-reader.php';
require_once __DIR__ . '/includes/class-safegrd-restore.php';
require_once __DIR__ . '/includes/class-safegrd-connect.php';
require_once __DIR__ . '/includes/class-safegrd-scheduler.php';

SafeGrd_Scheduler::init();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-safegrd-admin.php';
	SafeGrd_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-safegrd-cli.php';
	WP_CLI::add_command( 'safegrd', 'SafeGrd_CLI' );
}

register_activation_hook(
	__FILE__,
	function () {
		if ( SafeGrd_Settings::connected() ) {
			SafeGrd_Scheduler::schedule();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		SafeGrd_Scheduler::unschedule();
	}
);
