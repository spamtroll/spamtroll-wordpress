#!/bin/bash
#
# Runs the regression suite against the classes as they were before the fix,
# and insists that it fails — then runs the fail-open gate against those same
# pre-fix classes and insists that it passes.
#
# A test that passes on the broken code is not a regression test, it is
# decoration. The first half of this script is what separates the two.
#
# The second half exists because the audit's headline finding was that
# fail-open already worked (WORDPRESS.md §1): all fourteen error paths let the
# visitor's content through. Showing the gate green on both revisions is how
# the fix demonstrates it did not buy its new behaviour by giving that up.
#
# The harness reads the plugin's classes from $SPAMTROLL_INCLUDES_DIR, so the
# same tests can be pointed at either revision without touching the working
# tree.
#
# Usage: bash dev/prove-regression.sh [baseRef]     (default: main)

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

BASE_REF="${1:-main}"
ROOT="build/regression-root"
OUT="${ROOT}/includes"

rm -rf "$ROOT"
mkdir -p "$OUT"

files=$(git ls-tree --name-only "${BASE_REF}:includes" 2>/dev/null)
if [ -z "$files" ]; then
  echo "Cannot read includes/ at ${BASE_REF}" >&2
  exit 2
fi

for file in $files; do
  git show "${BASE_REF}:includes/${file}" > "${OUT}/${file}" || exit 2
done

# The entry point and the metadata that has to agree with it. PhpBaselineTest
# reads these, and reading the working tree's copies would make it assert
# about the fix while everything around it asserts about the defect.
for file in spamtroll.php composer.json README.md uninstall.php; do
  git show "${BASE_REF}:${file}" > "${ROOT}/${file}" || exit 2
done

echo "Revision ${BASE_REF} laid out in ${ROOT}."
echo
echo "=== 1/2  Regression suite against ${BASE_REF} — expected to FAIL ==="
echo

SPAMTROLL_INCLUDES_DIR="$OUT" vendor/bin/pest tests/Regression --colors=never
regression_status=$?

echo
if [ "$regression_status" -eq 0 ]; then
  echo "REGRESSION PROOF FAILED: tests/Regression passes against ${BASE_REF}."
  echo "Those tests do not distinguish the fix from the defect."
  exit 1
fi
echo "Regression proof holds: tests/Regression is red against ${BASE_REF}."

echo
echo "=== 2/2  Fail-open gate against ${BASE_REF} — expected to PASS ==="
echo

SPAMTROLL_INCLUDES_DIR="$OUT" vendor/bin/pest tests/Unit/FailOpenMatrixTest.php --colors=never
failopen_status=$?

echo
if [ "$failopen_status" -ne 0 ]; then
  echo "FAIL-OPEN GATE FAILED against ${BASE_REF}."
  echo "The gate is supposed to hold on both revisions; if it cannot pass on"
  echo "the pre-fix code, it is testing the fix rather than the guarantee."
  exit 1
fi

echo "Fail-open held before the fix and holds after it."
