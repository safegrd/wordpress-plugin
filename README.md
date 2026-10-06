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
wp safegrd connect --token env:SAFEGRD_TOKEN   # or a personal access token from the console
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

One archive per backup, `age(gzip(tar))`:

| Entry | Contents |
| :-- | :-- |
| `mysql/dump.sql`, `.1`, ... | Every table with the site's `$table_prefix`, read in one consistent snapshot, in the form `mysqldump` writes |
| `files/...` | `wp-config.php`, `.htaccess` and `wp-content`, without caches, `upgrade`, `debug.log` and other backup plugins' archives |
| `files.json` | Each file's path, size and SHA-256 |
| `manifest.json` | Table row counts, site URL, WordPress and PHP versions, table prefix |

WordPress core is not in the archive: reinstall the version the backup names. The plugin's own
settings are left out, so a restored site comes back disconnected.

Refused: multisite networks, and sites whose media a plugin keeps in object storage.

## Test restores

SafeGrd decrypts the newest backup on its own machines and checks, in memory: the digest, that
the dump is complete with every table's rows, that every file matches its listed digest, and that
every attachment the database names (`_wp_attached_file`) is in the archive.

## Restore

With the [safegrd CLI](https://safegrd.dev/docs/install), on a machine signed in to the same
account, into an empty MySQL or MariaDB database and an empty directory:

```sh
safegrd list
safegrd restore --snapshot snap-... --target mysql://user:pass@host:3306/wordpress --target-dir ./restored
```

Then install the WordPress version it prints, copy `wp-content`, `wp-config.php` and `.htaccess`
over it, and point the database settings in `wp-config.php` at the restored database. Full
steps: [safegrd.dev/docs/surfaces/wordpress](https://safegrd.dev/docs/surfaces/wordpress#restore).

## Long backups on strict hosts

A backup runs in one PHP request started by WP-Cron and streams to storage in 8 MiB parts,
without writing a copy to disk. A host that stops long requests can stop a large site's backup
part way. The run is then recorded as failed with the reason. Run it from the server's cron
instead:

```
17 3 * * * cd /var/www/html && wp safegrd backup --quiet
```

## Settings in wp-config.php

| Constant | Use |
| :-- | :-- |
| `SAFEGRD_SERVER_URL` | A self-hosted SafeGrd server |
| `SAFEGRD_CA_FILE` | A CA bundle for a server with a private CA |

## Development

`git config core.hooksPath .githooks` once per clone. The end-to-end test lives with the server's
test suite and runs this plugin on the official WordPress image against a real server.

## Licence

GPL-2.0-or-later. See `LICENSE`.
