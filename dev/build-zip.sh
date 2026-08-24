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
for path in spamtroll.php uninstall.php readme.txt LICENSE includes assets languages; do
  cp -R "$path" "$STAGE/"
done

composer install --no-dev --optimize-autoloader --no-interaction --quiet
cp -R vendor "$STAGE/"

# Restore the development dependencies for whatever runs next.
composer install --no-interaction --quiet

( cd "$BUILD" && zip -qr "${SLUG}.zip" "$SLUG" )

echo "Built ${BUILD}/${SLUG}.zip ($(du -h "${BUILD}/${SLUG}.zip" | cut -f1))"
