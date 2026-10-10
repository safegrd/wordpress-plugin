# SafeGrd Backup for WordPress

Backs up a WordPress site's database and files to [SafeGrd](https://safegrd.dev), encrypted on
your server before they leave it, into storage locked against deletion. SafeGrd test-restores
the newest backup on your plan's schedule and records each result. The free plan covers one
site; [safegrd.dev/pricing](https://safegrd.dev/pricing) lists what each plan includes.

It is written in PHP and needs nothing installed beside WordPress, so it runs on shared and
managed hosting: PHP 7.4 or newer with the sodium, zlib and mysqli extensions, which PHP
includes by default.

## Install

1. Download `safegrd-backup.zip` from [Releases](https://github.com/safegrd/wordpress-plugin/releases),
   or build it with `bin/build-zip.sh`.
2. In wp-admin: Plugins, Add New, Upload Plugin, then Activate.
3. Tools, SafeGrd: choose who keeps the key and press **Connect**. Sign in or create an account
   in the tab that opens, and check the code matches.

The first backup starts right after connecting, then runs once a day.

From WP-CLI:

```sh
wp plugin install safegrd-backup.zip --activate
wp safegrd connect                       # prints a URL and a code to approve on any device
wp safegrd connect --token=env:SAFEGRD_TOKEN   # or a personal access token from the console
wp safegrd backup
wp safegrd status
```

## Who keeps the key

- **SafeGrd-managed key** (default): SafeGrd keeps your key sealed and releases it only to your
  enrolled hosts, so you can restore even after losing this site. SafeGrd's test restores use it.
- **Customer-managed key**: only you can decrypt these backups. The key is shown once, when the
  site connects; keep a copy somewhere safe. The site stores only its public half.

The site never stores a private key or your personal access token. It keeps a node token that
backs up this site only.

## What a backup holds

Each backup is a complete snapshot of the site, stored incrementally: files are cut into 4 MiB
chunks, encrypted and gathered into packs, and a run uploads only the chunks this month's
repository does not hold yet. An unchanged image, theme or table costs nothing. The first
backup of each month uploads everything once.

| Path in the snapshot | Contents |
| :-- | :-- |
| `mysql/dump.sql`, `.1`, `.2`, ... | The database in the form `mysqldump` writes, one part per table, read in one consistent snapshot. Only tables with the site's `$table_prefix` |
| `files/...` | `wp-config.php`, `.htaccess` and `wp-content`, without caches, `upgrade`, `debug.log` and other backup plugins' archives |
| `manifest.json` | Table row counts, site URL, WordPress and PHP versions, table prefix |

WordPress core is not in the snapshot: reinstall the version the backup names. The plugin's own
settings and its record of what is stored are left out: a restore keeps the connection of the
site it runs on, and a restore with the CLI onto a server without the plugin needs the plugin
installed and connected afterwards.

Refused: multisite networks, and sites whose media a plugin keeps in object storage.

## Test restores

SafeGrd decrypts the newest backup on its own machines and checks, in memory: the digest, that
the dump is complete with every table's rows, that every file matches its listed digest, and that
every attachment the database names (`_wp_attached_file`) is in the snapshot.

## Restore

From wp-admin: open Tools, SafeGrd, *Restore & download*. The list shows this site's backups;
the *Site* filter shows another site's, or every site's in the account. *Open* a backup to see
what it holds: each part's size, the WordPress and PHP versions, and its plugins, themes and
tables. Tick what to take, press *Review restore*, and the review lists what will be replaced
before *Restore now*. The same page restores a site over itself.

- Nothing on the site changes until every table and file is loaded and checked against the
  backup. Then one step swaps them in, and the tables and files they replace are kept aside.
- *Put the copy back* undoes the last restore: what it replaced trades places with what it put
  in, so the restored version is then kept aside, and pressing it again restores it again.
  *Delete the copy* frees the space. Copies kept by earlier restores are listed with their size,
  with *Delete them*.
- The site keeps its own `wp-config.php` and domain. The restore runs a search and replace from
  the old URL to the new one, serialized data included, and renames the tables to the new
  table prefix.
- Afterwards the site's users are the backup's: sign in with an administrator account of the
  restored site.
- Tick only some parts to restore those: the database, plugins, themes, uploads, and *others*
  (the rest of wp-content). Without the database the site keeps its content, settings and
  sign-in. This plugin always stays the version running.
- *Some plugins only*, *Some themes only* and *Some tables only* list what the backup holds, with
  the version of each plugin and theme in the backup beside the one installed now. Tick some to
  restore only those: each replaces its own directory or table, and every other one stays.
  Restoring the users table brings the backup's users with it; any other table leaves the
  sign-in as it is.
- *Download as a file* writes the one part ticked, or the plugins, themes or tables chosen in
  it.

```sh
wp safegrd snapshots --site=this                 # or all, or another site's address
wp safegrd restore snap-... --yes
wp safegrd restore snap-... --only=plugins,themes --yes
wp safegrd contents snap-...                     # plugins, themes and tables, with versions
wp safegrd restore snap-... --plugins=akismet --yes
wp safegrd restore snap-... --tables=wp_posts,wp_postmeta --yes
wp safegrd restore --undo                        # put back what the last restore replaced
wp safegrd restore --delete-copy                 # or --delete-older for earlier restores' copies
wp safegrd logs                                  # the last runs; wp safegrd logs <run> prints one
```

## Before updates

The Updates, Plugins and Themes screens show how old the last backup is, with *Back up now*,
while plugin, theme or WordPress updates wait. If an update breaks the site, restore that plugin
or theme alone from the backup taken before it.

## Download

*Restore & download* writes one part of a backup to a file on the server, decrypted: the
database as a gzipped SQL dump, or plugins, themes, uploads or others as a gzipped tar (others
carries `wp-config.php` and `.htaccess` too). The file is fetched from wp-admin by an
administrator and deleted from the server a day later.

```sh
wp safegrd download snap-... database --to=site.sql.gz
wp safegrd download snap-... uploads --to=uploads.tar.gz
wp safegrd download snap-... database --tables=wp_posts --to=posts.sql.gz
wp safegrd download snap-... plugins --plugins=akismet --to=akismet.tar.gz
```

The plugin restores backups taken with a SafeGrd-managed key. For a customer-managed key, use the
[safegrd CLI](https://safegrd.dev/docs/install) on a machine signed in to the same account:

```sh
safegrd restore --snapshot snap-... --target mysql://user:pass@host:3306/wordpress --target-dir ./restored
```

Full steps: [safegrd.dev/docs/surfaces/wordpress](https://safegrd.dev/docs/surfaces/wordpress#restore).

## Large sites on strict hosts

A backup runs in slices of a few seconds, each its own request: a third of the host's
`max_execution_time`, at most 25 seconds. Each slice uploads what it read, saves where it got to,
and starts the next with a request to the site itself; a WP-Cron event a minute out stands behind
it. A host that stops a request stops one slice, and the next resumes from the last saved point.
Nothing secret is saved between slices: each pack is sealed and uploaded before its slice ends.

Two things still have to fit in one slice's request: the database dump, so that it stays one
consistent snapshot, and the largest single file. A slice the host stops starts again from the
last saved point; stopped three times at the same point, the run is recorded as failed, naming
the dump or the file. Run it from the server's cron instead, where PHP has no time limit:

```
17 3 * * * cd /var/www/html && wp safegrd backup --quiet
```

A restore runs the same way. A slice stopped while loading a table loads that table again from
its start. One stopped while swapping the restored site in is finished by the next slice, from
where it got to; the maintenance page it leaves lasts a minute. A restore that fails part way
through the swap can be put back.

## Settings in wp-config.php

| Constant | Use |
| :-- | :-- |
| `SAFEGRD_SERVER_URL` | A self-hosted SafeGrd server |
| `SAFEGRD_CA_FILE` | A CA bundle for a server with a private CA |
| `SAFEGRD_SLICE_SECONDS` | How long one slice runs, in seconds, when the host's limit is not the right guide |

## Development

`.wordpress-org/` holds the wordpress.org listing: the banner (`banner.svg` is the source,
rendered with `rsvg-convert`), the icon (SafeGrd's brand mark) and the screenshots, numbered as the `== Screenshots ==` lines in
`readme.txt`. They are not in the zip; they go to the SVN `assets/` directory beside `trunk`.

`git config core.hooksPath .githooks` once per clone. The end-to-end test lives with the server's
test suite and runs this plugin on the official WordPress image against a real server.

## Licence

GPL-2.0-or-later. See `LICENSE`.
