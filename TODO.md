# TODO

Below the P0 items, known gaps in how backups and restores recover from a slice the host stops. None of them
loses a backup. Each says what breaks and when.

P0 items come first: features the UpdraftPlus reviews show customers rely on.

## P0: Email the site owner when a backup or test restore fails

Alerts exist on the server: email and Slack for a failed backup or test restore, set in the
console under Settings, Alerts (Help tab links to it). The plugin sends nothing itself
(`grep wp_mail` finds none), so an owner who never opened the console gets no word, and nothing
fires when the site stops running backups at all (WP-Cron disabled, host blocking loopback,
plugin deactivated by a conflict). UpdraftPlus's reviews say the same: a success email on a
failed run, and years of backups that never worked.

Do: send an email from the site, with plain subjects that say the result.

- Failure: when a backup or restore ends in an error (`SafeGrd_Backup::run` ends, the place that
  sets `last_error`, and the stalled-three-times path). Subject "SafeGrd: backup of <site> failed",
  body the one-line reason the Logs tab shows, the last good backup's date, and the Tools, SafeGrd
  link. One email per failed run, and at most one per 24 hours for a repeating failure.
- Missed: a daily check (a WP-Cron event, plus a check on any wp-admin load) that sends
  "no backup of <site> in <n> days" when the last completed backup is older than twice the
  schedule interval. This is the case the server cannot see for a site that never calls it.
- Test restore failed: the plugin already reads the last test restore for Tools; send when it
  turns failed. Do not email on success by default.
- Recipient: the site's `admin_email` by default, overridable on Tools, Settings (comma list).
  Settings: "Email me when a backup fails" (on by default), "Also email when a backup succeeds"
  (off).
- Use `wp_mail()`. When it returns false, show it on Tools, SafeGrd (many hosts block mail) and
  say what to do (set up an SMTP plugin, or the console alerts), never fail the backup over it.
  No secret, token or key in the body, same rule as the Help diagnostics.
- Do not duplicate the server's alert when the server already emailed the same owner: have the
  plugin ask the server's alert setting if the API exposes it, or say in Settings that console
  alerts are separate and may send both.
- Tests: failure sends one mail, a repeat within 24 hours sends none, a missed backup sends one,
  `wp_mail` failure is reported not thrown. Use the `wp_mail` filter / `pre_wp_mail` in tests.
- Update the readme and changelog.

## Put the copy back has no resume

`SafeGrd_Restore::undo()` swaps each folder with its copy in three renames: live to a
temporary name, copy to live, temporary name to copy. It runs in one request and records
no progress. A request killed between the first and second rename leaves that folder
missing from the live site (`wp-content/plugins`, for example), and nothing finishes the
move. The window is a few microseconds per folder, so it needs a kill at exactly that
moment.

Fix: write the list of renames to an option before the first one, mark each as it
completes, and finish an unfinished list on the next request, as the restore's swap does
with `placed`.

## A pack uploaded but not confirmed is orphaned

A backup slice stopped after a pack's PUT and before its `/uploaded` confirmation leaves the
pack in storage. The site's cache does not know about it, so the next slice uploads its
blobs again in a new pack. The orphan is locked against deletion like every object and is
paid for until its lock expires. Its size is at most one pack, 8 MiB.

Fix: the server could list the epoch's unconfirmed keys when it opens the epoch, and the
plugin could adopt the ones it wrote, the way it adopts uncommitted packs today.

## Search and replace is only atomic on InnoDB

`replace_url()` commits each batch of rows and saves its place right after, so a slice
stopped between the two runs the batch again. The transaction does nothing on MyISAM
tables: a slice stopped in the middle of a batch there replaces some rows twice. That only
corrupts rows when the new address contains the old one (`http://a.com` to
`http://a.com/blog`).

Fix: skip a row whose value already holds the new address and not the old one, or keep the
batch's primary keys in the job before updating them.

## Ctrl-C counts as the host stopping a slice

A slice that ends releases its lock, so a lock left behind is read as a slice the host
stopped. Pressing Ctrl-C on `wp safegrd backup` leaves one too. Three at the same point fail
the run with "The host stopped the backup 3 times".

Fix: release the lock from a SIGINT handler when `pcntl` is there, or say "stopped" rather
than "the host stopped" in the message.

## The stall message names only large files

When a backup fails after three stops at one point, it names the file it was reading only if
that file is 16 MiB or larger (`SafeGrd_Backup::LARGE_FILE`). Otherwise it says "reading the
files after <the last saved file>", which is true but leaves the operator to find which file
it was.

Fix: name the file being read in `safegrd_backup_doing` for every file that takes more than a
second to read, not only by size.

## Disconnecting while a slice runs

`SafeGrd_Connect::disconnect()` drops the cache tables while a backup slice may be using them.
A slice writing its snapshot then reads an empty file list and fails with "The snapshot would
name 1 blobs this site has no record of storing": the check refuses the snapshot, so nothing
broken is stored, but the run fails for no reason the operator can see.

Fix: take the slice lock in `disconnect()`, waiting a few seconds for the running slice, or
refuse while a backup or restore is under way.
