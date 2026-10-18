#!/bin/sh
# Publishes the plugin to the WordPress.org plugin directory, which serves
# https://wordpress.org/plugins/safegrd-backup from the Subversion repository
# https://plugins.svn.wordpress.org/safegrd-backup.
#
#   bin/release-wporg.sh             commit trunk and assets, then tag the version
#   bin/release-wporg.sh --dry-run   build and show what would change; commit nothing
#
# The directory serves the version readme.txt's Stable tag names, from
# tags/<version>. trunk holds the same files, and assets/ beside them holds the
# banner, icon and screenshots the listing shows (.wordpress-org/ here).
#
# It commits only a version that is already released on GitHub, from a clean
# checkout that origin has, so the directory never serves code the repository
# does not. Run it after `gh release create v<version>`.
#
# The SVN username is safegrd. The password is the SVN password set on
# profiles.wordpress.org (not the account password): put it in
# WPORG_SVN_PASSWORD, or leave it unset and svn asks for it and caches it in
# ~/.subversion as it does for any repository.
set -eu

url=https://plugins.svn.wordpress.org/safegrd-backup
wc=${WPORG_SVN_DIR:-dist/wporg}
dry=0
[ "${1:-}" = "--dry-run" ] && dry=1

cd "$(dirname "$0")/.."

fail=0
check() { # check <ok> <message>: a failed check stops a release, and is listed on a dry run
	[ "$1" = 0 ] && return 0
	if [ "$dry" = 1 ]; then
		echo "Warning: $2" >&2
		fail=1
	else
		echo "Error: $2" >&2
		exit 1
	fi
}

ver=$(sed -nE 's/^ \* Version: *([0-9][0-9.]*).*/\1/p' safegrd-backup.php)
[ -n "$ver" ] || { echo "Error: safegrd-backup.php names no Version" >&2; exit 1; }
ok=0; grep -q "define( 'SAFEGRD_VERSION', '$ver' );" safegrd-backup.php || ok=1
check $ok "SAFEGRD_VERSION in safegrd-backup.php is not $ver"
ok=0; grep -qx "Stable tag: $ver" readme.txt || ok=1
check $ok "readme.txt's Stable tag is not $ver: the directory serves tags/<Stable tag>"
ok=0; grep -qx "= $ver =" readme.txt || ok=1
check $ok "readme.txt has no changelog section for $ver"

ok=0; [ -z "$(git status --porcelain)" ] || ok=1
check $ok "the checkout has uncommitted changes"
git fetch -q origin
ok=0; git merge-base --is-ancestor HEAD origin/main 2>/dev/null || ok=1
check $ok "HEAD is not on origin/main: push first"
ok=0
git rev-parse -q --verify "refs/tags/v$ver" >/dev/null 2>&1 ||
	curl -fsS -o /dev/null "https://api.github.com/repos/safegrd/wordpress-plugin/releases/tags/v$ver" 2>/dev/null || ok=1
check $ok "v$ver is not released on GitHub yet: release it there first (the zip and the notes), then here"

ok=0; svn ls "$url/tags/" --non-interactive | grep -qx "$ver/" && ok=1
check $ok "tags/$ver is already in the directory: a published version is never replaced, raise the version"

sh bin/build-zip.sh >/dev/null

if [ -d "$wc/.svn" ]; then
	svn update -q "$wc"
else
	rm -rf "$wc"
	# trunk and assets in full; tags listed by name only, so old versions are not downloaded.
	svn checkout -q --depth immediates "$url" "$wc"
	svn update -q --set-depth infinity "$wc/trunk" "$wc/assets"
fi

rsync -a --delete --exclude .svn dist/safegrd-backup/ "$wc/trunk/"

mkdir -p "$wc/assets"
for f in banner-772x250.png banner-1544x500.png icon-128x128.png icon-256x256.png icon.svg; do
	cp ".wordpress-org/$f" "$wc/assets/$f"
done
# Screenshots are numbered as the == Screenshots == lines in readme.txt; a
# number no longer listed goes away.
rm -f "$wc"/assets/screenshot-*.png
cp .wordpress-org/screenshot-*.png "$wc/assets/"

svn add -q --force "$wc/trunk" "$wc/assets"
svn status "$wc" | awk '$1 == "!" {print $2}' | while read -r gone; do svn rm -q "$gone"; done
# Without a type the assets are served as text and the listing shows no image.
for f in "$wc"/assets/*.png; do svn propset -q svn:mime-type image/png "$f"; done
svn propset -q --force svn:mime-type image/svg+xml "$wc/assets/icon.svg"

echo "Changes against $url:"
svn status "$wc" | sed 's/^/  /'
if [ "$dry" = 1 ]; then
	[ "$fail" = 0 ] && echo "Dry run: nothing committed. Run without --dry-run to publish $ver." ||
		echo "Dry run: nothing committed. Fix the warnings above before publishing $ver."
	exit 0
fi

if [ -n "${WPORG_SVN_PASSWORD:-}" ]; then
	auth() { printf '%s' "$WPORG_SVN_PASSWORD" | svn "$@" --username safegrd --password-from-stdin --no-auth-cache --non-interactive; }
else
	auth() { svn "$@" --username safegrd; }
fi

auth commit -q "$wc" -m "Release $ver"
auth copy -q "$url/trunk" "$url/tags/$ver" -m "Tag $ver"
echo "Published $ver: https://wordpress.org/plugins/safegrd-backup/ serves it once the directory rebuilds the page, within minutes."
echo "Search results and the author profile update within 72 hours."
