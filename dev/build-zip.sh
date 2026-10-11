#!/bin/bash
#
# Builds the ZIP a user actually installs.
#
# The plugin refuses to load without vendor/ (spamtroll.php bails with a
# notice), and vendor/ is gitignored — so the archive GitHub's "Download
# source" button produces has never been installable. This script produces the
# other thing: a directory named after the plugin slug, containing exactly the
# files that belong on a live site, with production dependencies vendored in.
#
# Usage: bash dev/build-zip.sh

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

SLUG="spamtroll"
BUILD="build"
STAGE="${BUILD}/${SLUG}"

rm -rf "$STAGE" "${BUILD}/${SLUG}.zip"
mkdir -p "$STAGE"

# Everything a running plugin needs, and nothing else. Listing what goes in
# rather than what stays out means a new development directory cannot end up
# on somebody's server by being forgotten.
for path in spamtroll.php uninstall.php readme.txt LICENSE composer.json composer.lock includes assets languages; do
  cp -R "$path" "$STAGE/"
done

# Resolve locked runtime dependencies in staging, keeping checkout QA tools intact.
composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --no-interaction --quiet

# Stable timestamps and entry ordering make independently rebuilt release ZIPs
# identical. SOURCE_DATE_EPOCH can pin a release even from an exported checkout.
BUILD_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"
php -r '$root = $argv[1]; $epoch = (int) $argv[2]; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) { touch($file->getPathname(), $epoch); } touch($root, $epoch);' "$STAGE" "$BUILD_EPOCH"
( cd "$BUILD" && find "$SLUG" -print | LC_ALL=C sort | zip -q -X "${SLUG}.zip" -@ )

echo "Built ${BUILD}/${SLUG}.zip ($(du -h "${BUILD}/${SLUG}.zip" | cut -f1))"
