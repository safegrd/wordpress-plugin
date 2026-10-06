=== SafeGrd Backup ===
Contributors: safegrd
Tags: backup, restore, database backup, encryption, immutable
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backs up the database and files, encrypted on your server, to locked storage, and test-restores them on a schedule.

== Description ==

SafeGrd Backup backs up the site (its database, wp-config.php, .htaccess and wp-content) to SafeGrd's hosted storage, encrypted on this server and locked against deletion. Each backup is a complete snapshot, and uploads only what changed since the last: an unchanged image, theme or table costs nothing. SafeGrd then test-restores the newest backup on a schedule: it checks the archive decrypts, every table has its rows, every file matches its digest, and every attachment the database names is in the archive.

The free plan covers one site. What each plan includes is at https://safegrd.dev/pricing.

The backup is encrypted with age on this server before it is uploaded. Choose who keeps the key:

* SafeGrd-managed key: SafeGrd keeps your key sealed and releases it only to your enrolled hosts, so you can restore even after losing this site.
* Customer-managed key: only you can decrypt these backups. Keep a copy of the key somewhere safe.

The plugin is written in PHP and needs nothing else installed. A backup runs in slices of a few seconds, so a host that stops long requests does not stop it.

== External service ==

This plugin sends backups to SafeGrd (https://safegrd.dev), a backup service, once you connect the site. It sends the encrypted backups to storage SafeGrd operates, and to safegrd.dev: the site's name and URL, the database name, table row counts, file counts and the time and size of each backup. Terms: https://safegrd.dev/terms. Privacy: https://safegrd.dev/privacy.

== Installation ==

1. Upload the zip under Plugins, Add New, Upload Plugin, and activate it.
2. Open Tools, SafeGrd, and press Connect.
3. Sign in or create an account in the tab that opens.

== Frequently Asked Questions ==

= How do I restore? =

With the safegrd command line tool: https://safegrd.dev/docs/surfaces/wordpress#restore

= Does it back up multisite? =

The plugin backs up single sites. On a multisite network it backs up nothing and says so on Tools, SafeGrd.

== Changelog ==

= 0.1.0 =
* First release.
