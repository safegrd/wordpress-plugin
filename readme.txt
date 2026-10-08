=== SafeGrd Backup: Encrypted WordPress Backups, Test-Restored ===
Contributors: safegrd
Tags: backup, wordpress backup, database backup, restore, cloud backup
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backs up the database and files, encrypted on your server, to storage locked against deletion, and test-restores them on a schedule.

== Description ==

SafeGrd Backup backs up a WordPress site, its database, wp-config.php, .htaccess and wp-content, to SafeGrd's hosted storage. Each backup is encrypted on your server before it leaves, and the storage refuses to delete it before its date, whoever asks. SafeGrd then restores the newest backup on a schedule and checks what came back, so a backup that would not restore is found before you need it.

The free plan covers one site. What each plan includes is at https://safegrd.dev/pricing.

= What is in a backup =

* Every table with the site's table prefix, read in one consistent snapshot.
* wp-config.php, .htaccess and wp-content: uploads, themes and plugins. Caches, the upgrade directory, debug.log and other backup plugins' archives are left out.
* A manifest with the row count of every table, the site URL, and the WordPress and PHP versions.

Each backup is a complete snapshot, stored incrementally: a run uploads only what changed since the last, so an unchanged image, theme or table costs nothing. The first backup of each month uploads everything once.

= The test restore =

On your plan's schedule SafeGrd decrypts the newest backup on its own machines and checks that the archive opens with the key, that the database dump is complete with every table's rows, that every file matches the digest recorded beside it, and that every attachment the database names is in the archive. Tools, SafeGrd shows the result; a failed check is reported to you.

= Who keeps the key =

The backup is encrypted with age on your server. Choose who keeps the key when you connect:

* SafeGrd-managed key (the default): SafeGrd keeps your key sealed and releases it only to your enrolled hosts, so you can restore even after losing this site. SafeGrd's test restores use it.
* Customer-managed key: only you can decrypt these backups. The key is shown once, when the site connects. Keep a copy somewhere safe, such as a password manager. The site stores only its public half.

= Restore =

From Tools, SafeGrd, on this site or on a fresh WordPress install connected to the same account. The site keeps running on its own database and files until every table and file is loaded and checked against the backup; then one step swaps them in, and what they replaced is kept until you delete it. A different site address is replaced in the database, serialized values included. The same works from WP-CLI with wp safegrd restore.

= Hosts that stop long requests =

A backup runs in slices of a few seconds, each its own request, so shared and managed hosting that stops long requests does not stop the backup. The plugin is PHP only and needs nothing installed beside WordPress: PHP 7.4 or newer with the sodium, zlib and mysqli extensions, which PHP includes.

= WP-CLI =

wp safegrd connect, backup, status, snapshots and restore do the same as Tools, SafeGrd, for a host with a shell and for a cron job where PHP has no time limit.

== External service ==

This plugin sends backups to SafeGrd (https://safegrd.dev), a backup service. Nothing is sent until you connect the site on Tools, SafeGrd, or with wp safegrd connect.

* safegrd.dev receives the site's name and URL, the database name, the WordPress and PHP versions, table row counts, file counts, and the time and size of each backup. It holds the site's key sealed when you choose a SafeGrd-managed key. Terms: https://safegrd.dev/terms. Privacy: https://safegrd.dev/privacy.
* The encrypted backups are uploaded from this server straight to SafeGrd's storage bucket at Backblaze B2 (s3.us-east-005.backblazeb2.com), through URLs safegrd.dev signs for each upload. A restore downloads them the same way. Backblaze terms: https://www.backblaze.com/company/policy/terms-of-service. Privacy: https://www.backblaze.com/company/policy/privacy. SafeGrd's sub-processors are listed at https://safegrd.dev/subprocessors.

== Installation ==

1. Upload the zip under Plugins, Add New, Upload Plugin, and activate it.
2. Open Tools, SafeGrd, choose who keeps the key, and press Connect.
3. Sign in or create an account in the tab that opens, and check the code matches the one in wp-admin.

The first backup starts right after connecting, then runs once a day.

== Frequently Asked Questions ==

= How do I restore? =

From Tools, SafeGrd, Restore, on this site or a new one connected to the same account. The site runs as it is until the restored database and files are loaded and checked, and what they replace is kept aside until you delete it. Details: https://safegrd.dev/docs/surfaces/wordpress#restore

= Can I move the site to a new host or domain with it? =

Yes. Install WordPress and this plugin on the new host, connect to the same account, and restore the backup from Tools, SafeGrd. The new site keeps its own wp-config.php and address; the old address is replaced in the database.

= Does it slow the site down? =

A backup runs in slices of a few seconds and uploads only what changed since the last one. The site serves pages between slices as usual.

= Where are the backups stored? =

In SafeGrd's hosted storage, encrypted on your server first, and locked against deletion until each backup's date. Nobody can read them without the key, and nobody can delete them early.

= Is WooCommerce backed up? =

Every table that starts with the site's table prefix is in the backup, orders and customers included, read in one consistent snapshot.

= Is WordPress core in the backup? =

No. The backup records the version. Install WordPress at that version, then restore: the backed-up database and files go over it.

= Does it back up multisite? =

The plugin backs up single sites. On a multisite network it backs up nothing and says so on Tools, SafeGrd.

= What does the free plan include? =

One site, with scheduled backups and test restores. https://safegrd.dev/pricing has what each plan adds.

== Screenshots ==

1. Tools, SafeGrd after the first backups: the last backup with its tables, rows, files and lock date, the next backup, the last test restore, the storage, the key, and the recent backups.

== Changelog ==

= 0.1.1 =
* A restore's progress shows at the top of Tools, SafeGrd, and survives a reload.
* A file the restore cannot move into place stops the restore and says which.
* Deleting the plugin removes the restore's settings and tables too.
* Requires WordPress 6.2 or newer. Tested up to 7.1.

= 0.1.0 =
* First release.
