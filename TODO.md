# TODO

Known gaps in how backups and restores recover from a slice the host stops. None of them
loses a backup; each says what breaks and when.

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
