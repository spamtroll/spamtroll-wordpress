#!/bin/bash
#
# Checks that the built ZIP is a plugin and not just a folder of files.
#
# The three ways this has gone wrong before: the archive has no vendor/ so the
# plugin bails on load; the header version and readme.txt's Stable tag drift
# apart so wordpress.org serves the wrong release; a development directory
# rides along into the package.
#
# Usage: bash dev/verify-zip.sh

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

SLUG="spamtroll"
ZIP="build/${SLUG}.zip"
OUT="build/verify"

[ -f "$ZIP" ] || { echo "No $ZIP — run dev/build-zip.sh first" >&2; exit 2; }

rm -rf "$OUT"
mkdir -p "$OUT"
unzip -q "$ZIP" -d "$OUT"

fail() { echo "FAIL: $1" >&2; exit 1; }

[ -f "${OUT}/${SLUG}/${SLUG}.php" ] || fail "no ${SLUG}/${SLUG}.php in the archive"
[ -f "${OUT}/${SLUG}/vendor/autoload.php" ] || fail "no vendor/autoload.php — the plugin would refuse to load"
[ -f "${OUT}/${SLUG}/readme.txt" ] || fail "no readme.txt — wordpress.org requires one"

for unwanted in tests dev .github composer.json phpstan.neon; do
  [ ! -e "${OUT}/${SLUG}/${unwanted}" ] || fail "${unwanted} was packaged"
done

header_version=$(sed -n 's/^ \* Version:[[:space:]]*//p' "${OUT}/${SLUG}/${SLUG}.php" | head -1 | tr -d '[:space:]')
stable_tag=$(sed -n 's/^Stable tag:[[:space:]]*//p' "${OUT}/${SLUG}/readme.txt" | head -1 | tr -d '[:space:]')

[ -n "$header_version" ] || fail "no Version in the plugin header"
[ "$header_version" = "$stable_tag" ] || fail "header says ${header_version}, readme.txt Stable tag says ${stable_tag}"

echo "OK: ${SLUG} ${header_version}, vendor/ present, no development files packaged"
