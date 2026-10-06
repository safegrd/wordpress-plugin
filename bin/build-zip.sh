#!/bin/sh
# Builds dist/safegrd-backup.zip, the file WordPress installs from
# Plugins, Add New, Upload Plugin.
set -eu
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/safegrd-backup
cp -R safegrd-backup.php uninstall.php readme.txt LICENSE includes assets dist/safegrd-backup/
(cd dist && zip -qr safegrd-backup.zip safegrd-backup)
echo "dist/safegrd-backup.zip"
