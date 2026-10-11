#!/bin/bash
# Disposable WordPress + MariaDB integration fixture. Never points at an existing site.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
: "${WP_TEST_DB_HOST:?Provide the isolated MariaDB host:port}"
: "${WP_TEST_DB_NAME:?Provide a disposable database name}"
: "${WP_TEST_DB_USER:?Provide the isolated database user}"

WP_QA_DIR=$(mktemp -d "${TMPDIR:-/tmp}/spamtroll-wordpress-qa.XXXXXX")
trap 'rm -rf "$WP_QA_DIR"' EXIT
export SPAMTROLL_QA=1
wpqa() { wp --path="$WP_QA_DIR" "$@"; }

wpqa core download --version="${WP_VERSION:-7.1.3}"
wpqa config create --dbname="$WP_TEST_DB_NAME" --dbuser="$WP_TEST_DB_USER" \
    --dbpass="${WP_TEST_DB_PASSWORD:-}" --dbhost="$WP_TEST_DB_HOST"
wpqa config set WP_ENVIRONMENT_TYPE local
wpqa core install --url=http://spamtroll-qa.test --title='Spamtroll isolated QA' \
    --admin_user=qa --admin_password=local-test-only --admin_email=qa@example.test --skip-email
wpqa plugin install "$PWD/build/spamtroll.zip" --activate
wpqa eval-file "$PWD/dev/verify-lifecycle.php" single --use-include
if [ "${WP_VERSION:-7.1.3}" = 7.1.3 ]; then
    wpqa plugin install plugin-check --version=2.1.0 --activate
    wpqa plugin check spamtroll --format=json > build/plugin-check.json
    cat build/plugin-check.json
    php -r 'foreach (file($argv[1]) as $line) { if (str_starts_with($line, "[")) { foreach (json_decode($line, true, 512, JSON_THROW_ON_ERROR) as $finding) { if ($finding["type"] === "ERROR") { exit(1); } } } }' build/plugin-check.json
    wpqa plugin deactivate plugin-check
fi
wpqa core multisite-convert --title='Spamtroll network QA' --base=/
wpqa site create --slug=before --email=qa@example.test
wpqa plugin deactivate spamtroll
wpqa plugin activate spamtroll --network
wpqa site create --slug=after --email=qa@example.test
wpqa eval-file "$PWD/dev/verify-lifecycle.php" network --use-include
wpqa plugin deactivate spamtroll --network
wpqa eval-file "$PWD/dev/verify-lifecycle.php" deactivated --use-include
wpqa plugin uninstall spamtroll
wpqa eval-file "$PWD/dev/verify-lifecycle.php" uninstalled --use-include
