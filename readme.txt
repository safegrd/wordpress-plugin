=== SafeGrd Backup ===
Contributors: safegrd
Tags: backup, database backup, restore, migration, cloud backup
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Encrypted WordPress backups in storage locked against deletion, test-restored on a schedule so you know they work.

== Description ==

SafeGrd Backup backs up your WordPress site, then restores the backup on a schedule and checks that it works. You see the result on Tools > SafeGrd, before you need the backup.

Install the plugin, press Connect on Tools > SafeGrd, and sign in. The first backup starts right after.

https://www.youtube.com/watch?v=Q4q2PGo16w0

There is a free plan. What each plan includes is on [safegrd.dev/pricing](https://safegrd.dev/pricing).

= Back up =

* Back up the database, `wp-config.php`, `.htaccess` and `wp-content` daily or weekly, at the hour you choose.
* Back up before a plugin, theme or core update, from wp-admin, WP-CLI or automatic updates. You turn it on under Settings.
* See how old your last backup is on the Updates, Plugins and Themes screens, with Back up now.
* WooCommerce orders and customers are included, read in one consistent snapshot with the rest of the database.
* After the first backup, only what changed is uploaded.
* Leave out large files by path or by name, such as `*.zip`.
* Each backup is encrypted on your server before it leaves, and stored where it cannot be deleted before its lock date.
* Works on shared hosting that stops long requests. The plugin is PHP only and needs nothing installed beside WordPress.
* Keep your current backup plugin while you try it. SafeGrd leaves other backup plugins' archives out of its backups.

= Test restore =

On your plan's schedule, SafeGrd restores your newest backup on its own machines and checks that:

* the archive opens with your key,
* every table came back with all its rows,
* every file matches the digest recorded when it was backed up,
* every attachment the database names is in the backup.

Tools > SafeGrd shows the date and result of the last test restore. To get an alert when a backup or a test restore fails, set one in the SafeGrd console under Settings, Alerts.

= Restore and migrate =

* Restore the whole site, some parts (database, plugins, themes, uploads), or single plugins, themes or tables.
* Roll back one plugin after a bad update. A backup taken before an update is labeled with the plugin and both versions.
* Review what will be replaced before anything starts.
* Your site keeps running while the backup is loaded and checked beside it. One step swaps it in at the end.
* Undo a restore with Put the copy back.
* Move a site to a new host or domain by restoring it there. The old URL is replaced in the database, serialized data included.
* Restore any WordPress site in your account onto this one.
* Download the database as a SQL dump, or plugins, themes or uploads as an archive.

= Storage and keys =

Backups go to SafeGrd-hosted storage, or to your own S3 bucket with Object Lock on. You choose how your key is managed when you connect:

* **SafeGrd-managed key** (the default): SafeGrd keeps your key sealed and releases it only to your enrolled hosts, so you can restore even after losing this site.
* **Customer-managed key**: only you can decrypt these backups. Keep a copy of the key somewhere safe, such as a password manager.

= WP-CLI =

Everything on Tools > SafeGrd also works from WP-CLI: `wp safegrd connect`, `wp safegrd backup`, `wp safegrd restore`, `wp safegrd download`, `wp safegrd status` and more. You do not need WP-CLI to use the plugin.

= Support =

Ask in the [support forum](https://wordpress.org/support/plugin/safegrd-backup/). Tools > SafeGrd, Help, has diagnostics to copy into your request, with no token or key in them. The full guide is at [safegrd.dev/docs/surfaces/wordpress](https://safegrd.dev/docs/surfaces/wordpress).

== External service ==

This plugin uses the SafeGrd backup service ([safegrd.dev](https://safegrd.dev)). Nothing is sent until you connect the site on Tools > SafeGrd, or with `wp safegrd connect`. Once connected, the site sends its name, URL and backup details to SafeGrd, and uploads its encrypted backups to SafeGrd's storage or your own bucket. [Terms](https://safegrd.dev/terms), [privacy policy](https://safegrd.dev/privacy), [sub-processors](https://safegrd.dev/subprocessors).

== Installation ==

1. In wp-admin, open Plugins, Add New, search for SafeGrd Backup, press Install Now, then Activate.
2. Open Tools > SafeGrd, choose how your key is managed, and press Connect.
3. Sign in or create an account in the tab that opens, and check the code wp-admin shows.

== Frequently Asked Questions ==

= How do I restore? =

From Tools > SafeGrd, Restore & download, on this site or a new one connected to the same account. See the [WordPress guide](https://safegrd.dev/docs/surfaces/wordpress#restore).

= Can I move the site to a new host or domain? =

Yes. Install WordPress and this plugin on the new host, connect to the same account, and restore the backup there. The site URL is replaced in the database.

= Where are the backups stored? =

In SafeGrd-hosted storage, or in your own S3 bucket with Object Lock on. Either way they are encrypted on your server first.

= Does it back up multisite? =

Not yet. The plugin backs up single sites.

= How do I roll back one plugin after a bad update? =

On Tools > SafeGrd, Restore & download, open a backup from before the update, choose Some plugins only, and tick that plugin. Only its files are replaced. Its settings stay as they are, since they live in the database.

== Screenshots ==

1. Tools > SafeGrd once connected: whether the site is protected, the last backup with its tables, rows, files and lock date, the next backup, the last test restore, storage used against the plan, and the recent backups, each with Open and its log.
2. Restore & download: a backup opened, with each part's size and the WordPress and PHP versions. Some plugins only lists each plugin with the version in the backup beside the one installed, and the review says what will be replaced before anything starts.
3. The Updates screen: how old the last backup is, with Back up now, and where to restore a plugin or theme if an update breaks the site.
4. Connecting: choose how your key is managed, then press Connect, or connect with a personal access token instead.
5. The code to check in the sign-in tab that opens. The page finishes connecting once you approve.

== Changelog ==

= 0.1.13 =
* Backups and restores work on MariaDB 10.6 and newer. Before, the first backup failed with "The snapshot would name … blobs this site has no record of storing", because a table the plugin keeps used a column name MariaDB reserves. MySQL sites were not affected.
* If the plugin cannot write to its own tables, the backup stops at once and names the database error.
* The first backup after this update uploads every file again, once, because the plugin rebuilds its record of what it uploaded.

= 0.1.12 =
* Back up to your own S3 bucket. When the site's project in SafeGrd stores in your own bucket with Object Lock on, and SafeGrd holds the bucket's key, the next backup goes there and SafeGrd test-restores it there. Tools > SafeGrd and `wp safegrd status` name the bucket, or say why the site is still on hosted storage.
* The Connect card asks how your key is managed, and links to the plans on safegrd.dev instead of quoting one.

= 0.1.11 =
* A sign-in started with wp safegrd connect that expires says to run wp safegrd connect again, not to press Connect.

= 0.1.10 =
* If another WordPress install shares your database and its table prefix starts with yours (wp_shop_ beside wp_), its tables are no longer in your backup, where restoring your site would have replaced them. The backup lists them as left out.
* Restoring over your own site works when its tables have foreign keys, such as WooCommerce's download log. The restored tables reference each other, and each key keeps its name. Over your own site the name returns when you delete the copy kept from before the restore, which holds it until then.
* Replacing the site address after a restore to a new address is safe to resume on MyISAM tables. A slice the host stopped part way through a batch replaced some rows twice when the new address held the old one (a.com to a.com/blog).
* A restore runs only the statements the plugin's dump writes, and refuses a backup that names a file outside the site.
* Triggers on your tables are listed as left out. The backup does not hold them.
* A backup SafeGrd could not record, because it could not be reached, is recorded with the next backup.
* A slice that runs past its time limit no longer frees the lock of the slice that took over, so two slices never run at once. A restore cannot begin while a backup slice starts, and Disconnect waits up to 30 seconds for a running slice.
* The copy a restore keeps aside is not served by Apache or LiteSpeed.
* A storage link that expires during a restore is signed again.
* Delete the copy says so when the database refuses, instead of reporting it deleted.

= 0.1.9 =
* Back up before a plugin, theme or core update: a setting, off until you turn it on, under Tools, SafeGrd, Settings, or wp safegrd schedule --before-updates=on. The update waits up to 20 seconds for the backup in wp-admin and for the whole backup from WP-CLI, and goes ahead if the backup fails. Recent backups, the restore list and wp safegrd snapshots label such a backup "Before update: <plugin> <old> to <new>".

= 0.1.8 =
* Restored files are written through the WordPress filesystem API. Only a file larger than the memory a request may use, and the download archive, are written as a stream.

= 0.1.7 =
* Downloads are written under uploads/safegrd-downloads, not in wp-content, and the backup leaves that directory out.
* A download or a restored file the disk could not take in full now fails with "the disk may be full", instead of being offered for download or swapped into the site short.
* The Connect card asks SafeGrd nothing until the site is connected.

= 0.1.6 =
* Restore single plugins, themes or tables from a backup. Every other plugin, theme and table stays as it is. The picker lists each with the version in the backup and the one installed now.
* Download single tables as a SQL dump, or single plugins or themes as a tar, with wp safegrd download --tables, --plugins or --themes.
* wp safegrd contents lists the plugins, themes and tables a backup holds.
* The Updates, Plugins and Themes screens show how old the last backup is, with Back up now, while updates wait.
* Restore & download, redone: this site's backups first, with a filter for the others. Open one to see each part's size, the WordPress and PHP versions, and its plugins, themes and tables. A review lists what will be replaced before anything starts.
* Put the copy back undoes the last restore, and pressing it again restores it again. wp safegrd restore --undo does the same.
* Copies kept by earlier restores are listed with their size, with Delete them, or wp safegrd restore --delete-older.
* A restore of some plugins, themes or tables no longer moves the next backup a week out.
* A failed restore no longer hides the copy of the last one that worked.
* Recent backups show each backup's id and Open, and say Test-restored or Backed up.
* Logs mark a run that stopped without finishing as stopped.
* wp safegrd snapshots --site and --format=json, wp safegrd logs, and wp safegrd status shows the last test restore.
* Reading what a backup holds takes a third of the requests it did.
* A backup or restore the host stops three times at the same point fails, saying what it was doing, instead of starting again for good.
* A restore stopped while swapping the restored site in is finished by the next slice, and one that fails there can be put back. Before, the site stayed half swapped and backups were refused.
* A restore stopped while loading a table loads it again from its start, instead of failing with "Duplicate entry".
* A whole-plugins restore keeps this plugin in the plugins directory throughout.
* The maintenance page a stopped swap leaves lasts a minute, not ten.
* A file that shrinks while it is read is kept as read, not padded with zeros.
* A restore checks the disk has room for the files it writes beside the site before it starts, as a full disk would take the running site down.
* Restores read and write their list of files hundreds of rows at a time, and backups look up a directory's files in one query.

= 0.1.5 =
* A restore or a download of a site with thousands of files waits when SafeGrd asks it to slow down, instead of stopping with "Rate limit exceeded".

= 0.1.4 =
* A restore checks it can move every directory it replaces before it changes anything. Where PHP runs as another user than the one who owns wp-content, it used to swap the tables, stop at the first directory it could not move, and leave the site in maintenance mode. It now stops first and says which directory and who owns it.
* The site never stays in maintenance mode after a restore that failed part way.
* The Tools page keeps following a restore through a moment the site does not answer, as while the plugins are swapped in, and says the session ended only when the database was restored.

= 0.1.3 =
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
* Restores read neighboring files and folders in one request: the first stage went from minutes to seconds on a site of 3,500 files.
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
