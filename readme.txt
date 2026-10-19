=== SafeGrd Backup ===
Contributors: safegrd
Tags: backup, database backup, restore, migration, cloud backup
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Encrypted WordPress backups in storage locked against deletion, test-restored on a schedule so you know they work.

== Description ==

SafeGrd Backup backs up your WordPress site, then restores the backup on a schedule and checks what came back. A backup that would not restore shows as failed on Tools > SafeGrd, before you need it.

Install the plugin, press Connect on Tools > SafeGrd, and sign in. The first backup starts right after.

Each backup holds the database, `wp-config.php`, `.htaccess` and `wp-content`. It is encrypted on your server before it leaves, and SafeGrd-hosted storage refuses to delete it before its lock date, whoever asks.

There is a free plan. What each plan includes is on [safegrd.dev/pricing](https://safegrd.dev/pricing).

= What you can do on Tools > SafeGrd =

* **Backups**: whether the site is backed up, then the last backup, the next one and the last test restore, with when the next test restore is due. Hosted storage used against your plan. Recent backups, each marked as the month's full upload, changes only, or a full copy.
* **Restore & download**: open any backup of this site, or of any WordPress site in your account, and see what it holds. Restore all of it, some parts (database, plugins, themes, uploads, the rest of `wp-content`), or single plugins, themes or tables, each listed with the version in the backup and the one installed now. A review lists what will be replaced before anything starts, and Put the copy back undoes the restore. Download a part, or single items, as a file.
* **Updates, Plugins and Themes screens**: how old the last backup is, with Back up now, while updates wait.
* **Settings**: back up daily or weekly from an hour you choose, and before each plugin, theme or core update if you turn it on. Leave out paths or file names, such as `*.zip`.
* **Logs**: what the last 20 backups, restores and downloads printed.
* **Help**: the WordPress guide, support, alerts, and diagnostics to copy into a support request, with no token or key in them.

= What is in a backup =

* Every table with the site's table prefix, read in one consistent snapshot.
* `wp-config.php`, `.htaccess` and `wp-content`: uploads, themes and plugins. Caches, the upgrade directory, `debug.log` and other backup plugins' archives are left out.
* A manifest with the row count of every table, the site URL, and the WordPress and PHP versions.

Each backup is a complete snapshot, stored incrementally: a run uploads only what changed since the last one, so an unchanged image, theme or table is not uploaded again. The first backup of each month uploads everything once. On the free plan each backup is a full copy instead, kept for the days [the free plan](https://safegrd.dev/pricing) allows.

= The test restore =

On your plan's schedule, SafeGrd decrypts the newest backup on its own machines and checks four things:

* the archive opens with the key,
* the database dump is complete, with every table's rows,
* every file matches the digest recorded beside it,
* every attachment the database names is in the archive.

Tools > SafeGrd shows the result, and a failed check is reported to you.

= How your key is managed =

The backup is encrypted with [age](https://age-encryption.org) on your server. You choose how the key is managed when you connect:

* **SafeGrd-managed key** (the default): SafeGrd keeps your key sealed and releases it only to your enrolled hosts, so you can restore even after losing this site. SafeGrd's test restores use it.
* **Customer-managed key**: only you can decrypt these backups. The key is shown once, when the site connects. Keep a copy somewhere safe, such as a password manager. The site stores only its public half.

= Restore and migrate =

Restore from Tools > SafeGrd on this site, or on a fresh WordPress install connected to the same account. The site keeps running on its own database and files until every table and file is loaded and checked against the backup. Then one step swaps them in, and what they replaced is kept until you delete it.

To move a site to a new host or domain, restore it there. A different site URL gets a search and replace in the database, serialized data included, and the tables are renamed to the new table prefix.

= Hosts that stop long requests =

A backup runs in slices of a few seconds, each its own request, so shared and managed hosting that stops long requests does not stop the backup. Each slice starts the next with a request to the site's own address. Where a firewall or the host blocks that, Tools > SafeGrd says so and what to change, and runs the backup itself while the page is open.

The plugin is PHP only and needs nothing installed beside WordPress: PHP 7.4 or newer with the sodium, zlib and mysqli extensions, which PHP includes.

= WP-CLI =

Everything on Tools > SafeGrd also works from WP-CLI, for a host with a shell or a cron job where PHP has no time limit. You do not need WP-CLI to set up or run the plugin.

* `wp safegrd connect`
* `wp safegrd backup` and `wp safegrd status`
* `wp safegrd snapshots` and `wp safegrd contents <snapshot>`
* `wp safegrd schedule daily|weekly`, with `--at` and `--before-updates=on|off`
* `wp safegrd restore <snapshot>`, with `--only=plugins,themes`, `--plugins`, `--themes`, `--tables` and `--undo`
* `wp safegrd download <snapshot>`
* `wp safegrd logs`

== External service ==

This plugin sends backups to SafeGrd ([safegrd.dev](https://safegrd.dev)), a backup service. Nothing is sent until you connect the site on Tools > SafeGrd, or with `wp safegrd connect`.

* safegrd.dev receives the site's name and URL, the database name, the WordPress and PHP versions, table row counts, file counts, and the time and size of each backup. It holds the site's key sealed when you choose a SafeGrd-managed key. [Terms](https://safegrd.dev/terms), [privacy policy](https://safegrd.dev/privacy).
* The encrypted backups are uploaded from this server straight to SafeGrd's storage bucket at Backblaze B2 (`s3.us-east-005.backblazeb2.com`), through URLs safegrd.dev signs for each upload. A restore downloads them the same way. When the site's project stores in your own bucket, they go to that bucket instead, through URLs safegrd.dev signs with the key it holds for it. Backblaze [terms](https://www.backblaze.com/company/policy/terms-of-service), [privacy policy](https://www.backblaze.com/company/policy/privacy). SafeGrd's sub-processors are listed at [safegrd.dev/subprocessors](https://safegrd.dev/subprocessors).

== Installation ==

1. In wp-admin, open Plugins, Add New, search for SafeGrd Backup, press Install Now, then Activate.
2. Open Tools > SafeGrd, choose how your key is managed, and press Connect.
3. Sign in or create an account in the tab that opens, and check the code wp-admin shows.

The first backup starts right after connecting, then runs once a day. You can switch to weekly under Settings.

To connect without the sign-in tab, open "Connect with a token instead" and paste a personal access token from Tokens in the [SafeGrd console](https://safegrd.dev/dashboard). The site uses it once to register and does not keep it.

== Frequently Asked Questions ==

= How do I restore? =

From Tools > SafeGrd, Restore & download, on this site or a new one connected to the same account. The site runs as it is until the restored database and files are loaded and checked, and what they replace is kept aside until you delete it. Details are in the [WordPress guide](https://safegrd.dev/docs/surfaces/wordpress#restore).

= Can I move the site to a new host or domain with it? =

Yes. Install WordPress and this plugin on the new host, connect to the same account, and restore the backup from Tools > SafeGrd. The new site keeps its own `wp-config.php` and domain. The restore runs a search and replace from the old URL to the new one, serialized data included.

= Can I connect without signing in from wp-admin? =

Yes. Create a personal access token under Tokens in the SafeGrd console, open "Connect with a token instead" on Tools > SafeGrd, and paste it. The site uses it once to register and does not keep it. With WP-CLI: `wp safegrd connect --token=env:SAFEGRD_TOKEN`.

= Can I download a backup? =

Yes, one part at a time from Tools > SafeGrd, Restore & download: the database as a gzipped SQL dump, or plugins, themes, uploads or the rest of `wp-content` as a gzipped tar. The file is written on your server, decrypted, and deleted a day later. With WP-CLI: `wp safegrd download <snapshot> uploads --to=uploads.tar.gz`.

= Can I restore only the plugins, or only the database? =

Yes. Tick the parts to restore. Plugin and theme settings live in the database, so restoring plugins alone brings back their files and keeps this site's settings, content and sign-in. This plugin always stays the version running.

= Can I undo a restore? =

Yes. A restore keeps what it replaced. Put the copy back, on Tools > SafeGrd, Restore & download, swaps it back and keeps the restored version aside, so pressing it again restores it again. With WP-CLI: `wp safegrd restore --undo`.

= Can it back up before an update? =

Yes, once you turn on Back up before a plugin, theme or core update under Tools > SafeGrd, Settings. It is off until you do. It covers updates from wp-admin, WP-CLI and automatic updates, and a bulk update takes one backup.

In wp-admin and for automatic updates, the update waits up to 20 seconds for the backup, then goes ahead while the backup finishes. Files the backup had not read by then are backed up as updated. From WP-CLI the update waits for the whole backup. No new backup is taken when one finished in the last hour, and a failed backup does not stop the update.

A bulk update that includes an active plugin or theme puts the site in maintenance mode before the backup starts, so visitors see the maintenance page while it runs.

= Can I roll back one plugin after a bad update? =

Yes. On Tools > SafeGrd, Restore & download, open a backup from before the update (one taken by Back up before updates is labeled with the plugin and its versions), choose Some plugins only, and tick that plugin. Only that plugin's files are replaced. Its settings stay as they are, since they live in the database. With WP-CLI: `wp safegrd restore <snapshot> --plugins=akismet`.

= Can I restore one table? =

Yes, the same way, or with `wp safegrd restore <snapshot> --tables=wp_posts`. The other tables stay as they are, and so does your sign-in, unless you restore the users table. `wp safegrd contents <snapshot>` lists the tables a backup holds, with their rows.

= How do I find out when a backup fails? =

Tools > SafeGrd shows the last backup and the last test restore. To be told without opening wp-admin, set alerts in the SafeGrd console under Settings, Alerts. Which channels your plan includes is on [safegrd.dev/pricing](https://safegrd.dev/pricing).

= Can I keep my current backup plugin while I try it? =

Yes. SafeGrd leaves other backup plugins' archives out of its backups, so the two do not back up each other.

= Can I leave large files out of backups? =

Yes, under Settings: a path from the site root, such as `wp-content/ewww`, or a name, such as `*.zip`. Uploads always stay in the backup, because the test restore checks that every attachment the database names is there.

= Does it slow the site down? =

A backup runs in slices of a few seconds and uploads only what changed since the last one. The site serves pages between slices as usual.

= Where are the backups stored? =

In SafeGrd-hosted storage, or in your own S3 bucket. Either way they are encrypted on your server first and locked against deletion until each backup's lock date. Nobody can read them without the key, and nobody can delete them early.

= Can I back up to my own S3 bucket? =

Yes, on a bucket with Object Lock on. In the SafeGrd console, set the site's project to store in your own bucket, save the bucket's key so SafeGrd holds it, and test the bucket. The site's next backup goes there, and SafeGrd test-restores it there.

SafeGrd holds the bucket's key, encrypted, and uses it only to sign this site's uploads and reads, so the site holds no storage key. Tools > SafeGrd and `wp safegrd status` name the bucket. The backups are filed the way the SafeGrd CLI files them, so a host with the bucket's key can verify and restore them without SafeGrd.

= Is WooCommerce backed up? =

Yes. Every table that starts with the site's table prefix is in the backup, orders and customers included, read in one consistent snapshot.

= Is WordPress core in the backup? =

No. The backup records the version. Install WordPress at that version, then restore: the backed-up database and files go over it.

= Does it back up multisite? =

Not yet. The plugin backs up single sites. On a multisite network it backs up nothing and says so on Tools > SafeGrd.

= What does the free plan include? =

Scheduled backups and test restores. [safegrd.dev/pricing](https://safegrd.dev/pricing) lists what each plan includes.

== Screenshots ==

1. Tools > SafeGrd once connected: whether the site is protected, the last backup with its tables, rows, files and lock date, the next backup, the last test restore, storage used against the plan, and the recent backups, each with Open and its log.
2. Restore & download: a backup opened, with each part's size and the WordPress and PHP versions. Some plugins only lists each plugin with the version in the backup beside the one installed, and the review says what will be replaced before anything starts.
3. The Updates screen: how old the last backup is, with Back up now, and where to restore a plugin or theme if an update breaks the site.
4. Connecting: choose how your key is managed, then press Connect, or connect with a personal access token instead.
5. The code to check in the sign-in tab that opens. The page finishes connecting once you approve.

== Changelog ==

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
