=== SafeGrd Backup ===
Contributors: safegrd
Tags: backup, database backup, restore, migration, cloud backup
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backs up the database and files, encrypted on your server, to storage locked against deletion, and test-restores them on a schedule.

== Description ==

SafeGrd Backup backs up a WordPress site, its database, wp-config.php, .htaccess and wp-content, to SafeGrd's hosted storage. Each backup is encrypted on your server before it leaves, and the storage refuses to delete it before its date, whoever asks. SafeGrd then restores the newest backup on a schedule and checks what came back, so a backup that would not restore is found before you need it.

The free plan covers one site. What each plan includes is at https://safegrd.dev/pricing.

= On Tools, SafeGrd =

* Backups: one line saying whether the site is backed up, then the last backup, the next one and the last test restore, with how often your plan test-restores and when the next is due. Hosted storage used against what your plan includes, with SafeGrd's warning from 80%. Recent backups, each marked as the month's full upload or an incremental one, beside the site's size.
* Restore & download: restore any WordPress backup in the account onto this site, all of it or only some parts (database, plugins, themes, uploads, the rest of wp-content), or migrate a site to a new host or domain. Download one part of a backup: the database as a gzipped SQL dump, the others as a gzipped tar.
* Settings: back up daily or weekly, from an hour you choose, and leave out paths or file names, such as *.zip.
* Logs: what the last 20 backups, restores and downloads printed.
* Help: the WordPress guide, support, alerts, and diagnostics to copy into a request, with no token or key in them.

= What is in a backup =

* Every table with the site's table prefix, read in one consistent snapshot.
* wp-config.php, .htaccess and wp-content: uploads, themes and plugins. Caches, the upgrade directory, debug.log and other backup plugins' archives are left out.
* A manifest with the row count of every table, the site URL, and the WordPress and PHP versions.

Each backup is a complete snapshot, stored incrementally: a run uploads only what changed since the last, so an unchanged image, theme or table is not uploaded again. The first backup of each month uploads everything once.

= The test restore =

On your plan's schedule SafeGrd decrypts the newest backup on its own machines and checks that the archive opens with the key, that the database dump is complete with every table's rows, that every file matches the digest recorded beside it, and that every attachment the database names is in the archive. Tools, SafeGrd shows the result, and a failed check is reported to you.

= Who keeps the key =

The backup is encrypted with age on your server. Choose who keeps the key when you connect:

* SafeGrd-managed key (the default): SafeGrd keeps your key sealed and releases it only to your enrolled hosts, so you can restore even after losing this site. SafeGrd's test restores use it.
* Customer-managed key: only you can decrypt these backups. The key is shown once, when the site connects. Keep a copy somewhere safe, such as a password manager. The site stores only its public half.

= Restore and migrate =

From Tools, SafeGrd, on this site or on a fresh WordPress install connected to the same account. The site keeps running on its own database and files until every table and file is loaded and checked against the backup. Then one step swaps them in, and what they replaced is kept until you delete it. A different site URL gets a search and replace in the database, serialized data included, and the tables are renamed to the new table prefix. The same works from WP-CLI with wp safegrd restore.

= Hosts that stop long requests =

A backup runs in slices of a few seconds, each its own request, so shared and managed hosting that stops long requests does not stop the backup. Each slice starts the next with a request to the site's own address. Where a firewall or the host blocks that, Tools, SafeGrd says so, with what to change, and runs the backup itself while the page is open. The plugin is PHP only and needs nothing installed beside WordPress: PHP 7.4 or newer with the sodium, zlib and mysqli extensions, which PHP includes.

= WP-CLI =

wp safegrd connect, backup, status, snapshots, schedule, restore (with --only=plugins,themes) and download do the same as Tools, SafeGrd, for a host with a shell and for a cron job where PHP has no time limit. WP-CLI is not needed to set up or run the plugin.

== External service ==

This plugin sends backups to SafeGrd (https://safegrd.dev), a backup service. Nothing is sent until you connect the site on Tools, SafeGrd, or with wp safegrd connect.

* safegrd.dev receives the site's name and URL, the database name, the WordPress and PHP versions, table row counts, file counts, and the time and size of each backup. It holds the site's key sealed when you choose a SafeGrd-managed key. Terms: https://safegrd.dev/terms. Privacy: https://safegrd.dev/privacy.
* The encrypted backups are uploaded from this server straight to SafeGrd's storage bucket at Backblaze B2 (s3.us-east-005.backblazeb2.com), through URLs safegrd.dev signs for each upload. A restore downloads them the same way. Backblaze terms: https://www.backblaze.com/company/policy/terms-of-service. Privacy: https://www.backblaze.com/company/policy/privacy. SafeGrd's sub-processors are listed at https://safegrd.dev/subprocessors.

== Installation ==

1. Upload the zip under Plugins, Add New, Upload Plugin, and activate it.
2. Open Tools, SafeGrd, choose who keeps the key, and press Connect.
3. Sign in or create an account in the tab that opens, and check the code matches the one in wp-admin.

To connect without the sign-in tab, open "Connect with a token instead" and paste a personal access token from Tokens in the SafeGrd console. The site uses it once to register and does not keep it.

The first backup starts right after connecting, then runs once a day. Switch to weekly on Tools, SafeGrd.

== Frequently Asked Questions ==

= How do I restore? =

From Tools, SafeGrd, Restore, on this site or a new one connected to the same account. The site runs as it is until the restored database and files are loaded and checked, and what they replace is kept aside until you delete it. Details: https://safegrd.dev/docs/surfaces/wordpress#restore

= Can I move the site to a new host or domain with it? =

Yes. Install WordPress and this plugin on the new host, connect to the same account, and restore the backup from Tools, SafeGrd. The new site keeps its own wp-config.php and domain. The restore runs a search and replace from the old URL to the new one, serialized data included.

= Can I connect without signing in from wp-admin? =

Yes. Create a personal access token under Tokens in the SafeGrd console, open "Connect with a token instead" on Tools, SafeGrd, and paste it. The site uses it once to register and does not keep it. With WP-CLI: wp safegrd connect --token=env:SAFEGRD_TOKEN.

= Can I download a backup? =

Yes, one part at a time from Tools, SafeGrd, Restore & download: the database as a gzipped SQL dump, or plugins, themes, uploads or the rest of wp-content as a gzipped tar. The file is written on your server, decrypted, and deleted a day later. With WP-CLI: wp safegrd download <snapshot> uploads --to=uploads.tar.gz.

= Can I restore only the plugins, or only the database? =

Yes. Tick the parts to restore. Plugin and theme settings live in the database, so restoring plugins alone brings back their files and keeps this site's settings, content and sign-in. This plugin always stays the version running.

= Can I leave large files out of backups? =

Yes, under Settings: a path from the site root, such as wp-content/ewww, or a name, such as *.zip. Uploads always stay in the backup, because the test restore checks that every attachment the database names is there.

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

1. Tools, SafeGrd once connected: the last backup with its tables, rows, files and lock date, the next backup and how often, the last test restore, storage used against the plan, and the recent backups.
2. Restore: every WordPress backup in the account, with what each holds, and the copy kept aside from the last restore until you delete it.
3. Connecting: choose who keeps the encryption key, then press Connect, or connect with a token instead.
4. Approving the sign-in in the tab that opens, with the code to check. The page refreshes periodically and finishes connecting once you approve.

== Changelog ==

= 0.2.0 =
* Download one part of any backup: the database as a gzipped SQL dump, or plugins, themes, uploads or the rest of wp-content as a gzipped tar. Kept on the server for a day.
* Restore only some parts of a backup. Without the database, the site keeps its content, settings and sign-in. This plugin stays the version running.
* Tools, SafeGrd has tabs: Backups, Restore & download, Settings, Logs and Help.
* Settings: the hour backups start at, and paths or names to leave out of backups.
* Logs of the last 20 backups, restores and downloads, linked from each recent backup.
* The backup's manifest lists each part's files and size, and the plugins and themes installed with their versions.
* wp safegrd download, wp safegrd restore --only, wp safegrd schedule --at.

= 0.1.2 =
* Tools, SafeGrd opens with one line saying whether the site is backed up, and sections for backups, storage, recent backups, restore and help.
* Hosted storage held against the plan, with SafeGrd's warning from 80%, on the Tools page and in wp safegrd status.
* Back up daily or weekly: on the Tools page, or wp safegrd schedule weekly.
* Recent backups say which uploaded every file and which only what changed, beside the site's size.
* "Locked until" is the date Object Lock holds the backup to, as SafeGrd records it.
* The Tools page says how often your plan test-restores and when the next is due.
* A site that cannot reach itself is told so, with what to do, and the Tools page runs the backup or restore itself while it is open.
* A restore no longer starts a backup at once: the next is one interval after it.
* Restores read neighbouring files and folders in one request: the first stage went from minutes to seconds on a site of 3,500 files.
* Help: the WordPress guide, support, and diagnostics to copy into a request.
* Connect with a personal access token on the Tools page, without WP-CLI.
* An account in several organizations picks one on the Tools page instead of needing WP-CLI.

= 0.1.1 =
* A restore's progress shows at the top of Tools, SafeGrd, and survives a reload.
* A file the restore cannot move into place stops the restore and says which.
* Deleting the plugin removes the restore's settings and tables too.
* Requires WordPress 6.2 or newer. Tested up to 7.1.

= 0.1.0 =
* First release.
