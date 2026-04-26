# Changelog

All notable changes to the Spamtroll WordPress plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Quota-aware fail-open** — when the Spamtroll API returns HTTP 402 / `QUOTA_EXCEEDED`, the comment / registration check no longer blocks the content. The message is allowed through unscanned (the user's account ran out of daily scans, not because the content looks like spam) and the event is recorded in a rolling 30-day local log stored in `wp_options['spamtroll_quota_skipped_log']`. Detection is by HTTP status code so this works with both the current SDK release (0.9.2) and the unreleased `isQuotaExceeded()` API.
- **Admin notice on the Spamtroll settings page** that summarises quota-skipped messages from the last 7 days, the most recent usage block returned by the API (`current / limit / plan`), an "Upgrade your plan" CTA, and an expandable per-day breakdown. Only shown when there's at least one skipped scan in the window so a healthy account sees nothing.
- `Spamtroll_Scanner::record_skipped_quota()` and `get_skipped_quota_stats($days)` — public helpers used by both the scanner hook and the admin renderer.

### Fixed

- Settings page now displays the "Settings saved." admin notice after
  the form is submitted. Custom top-level admin menus don't get the
  notice rendered automatically (only `options-*.php` pages do), so we
  call `settings_errors()` explicitly and seed it from the
  `settings-updated` query flag.

### Added

- PHPStan level 9 with `szepeviktor/phpstan-wordpress` stubs. Source
  is fully clean (0 baseline entries). Memory bumped to 1G because
  the WP stub set is large.
- php-cs-fixer config (`.php-cs-fixer.php`) — PSR-12 hybrid that
  preserves WordPress's snake_case method names while enforcing
  4-space indent, ordered imports, and `declare(strict_types=1)`.
- Pest 2 test suite under `tests/Unit/` with Brain Monkey mocking WP
  globals and Mockery for class doubles. 15 tests covering
  `Spamtroll_Settings` (typed accessors), `Spamtroll_Sdk_Factory`,
  and `Spamtroll_Wp_Http_Client` (timeout vs connection mapping).
- peck spell-check with `peck.json` dictionary covering WP terms
  (wpdb, nonce, transient, kses, …) and SDK domain words.
- New `Spamtroll_Settings` typed wrapper around `get_option()`. Every
  read now goes through `Spamtroll_Settings::string|int|float|bool|stringList`
  — `get_option()` return type (`mixed`) is narrowed once instead of
  re-narrowing at each call site.
- Composer scripts: `test`, `test:coverage`, `lint`, `lint:fix`,
  `stan`, `peck`, `qa` (composite).
- New `.github/workflows/qa.yml` — test matrix (PHP 8.2/8.3/8.4) + qa
  job (PHPStan + cs-fixer dry-run + peck on PHP 8.3). Repo previously
  had no CI.
- Documentation under `docs/CONTRIBUTING.md`.

### Changed

- All five `includes/*.php` classes received explicit type hints on
  public methods. Previously ~2% of methods had types; now all do.
  Includes generic-typed arrays in PHPDoc (`array<string, mixed>`).
- `Spamtroll_Scanner` now uses `match` for the action → approval
  status mapping in `filter_comment_approved`, and pulls every
  setting through the typed `Spamtroll_Settings` helper.
- `Spamtroll_Logger::log` parameter changed from untyped to
  `array<string, mixed>`. `cleanup` and `get_log` typed too.
- `Spamtroll_Admin::sanitize_settings` accepts `array|mixed` (because
  `register_setting` may pass non-array values) and narrows
  internally.
- `composer.json` requires PHP 8.0+ in production but pins
  `config.platform.php = 8.3` for development tooling. Pest 2 needs
  8.2+ transitively, peck needs 8.3+; production runtime unchanged.

### Changed
- API client extracted to the shared `spamtroll/php-sdk` Composer package.
  `Spamtroll_Api_Client`, `Spamtroll_Api_Response`, and
  `Spamtroll_Api_Exception` were removed; callers now use
  `\Spamtroll\Sdk\Client`, `\Spamtroll\Sdk\Response\CheckSpamResponse`, and
  the `\Spamtroll\Sdk\Exception\*` hierarchy.
  - New `includes/class-spamtroll-wp-http-client.php` adapter routes SDK
    requests through `wp_remote_request()` so WordPress HTTP filters
    (`http_request_args`, `pre_http_request`, proxy/SSL overrides) still
    apply.
  - New `includes/class-spamtroll-sdk-factory.php` builds a ready-to-use
    SDK client from the saved plugin settings.
  - The SDK now retries on 5xx and connection failures (3 attempts with
    exponential-ish backoff). Previously this plugin made a single attempt
    and failed open. Fail-open behavior is preserved once the SDK gives
    up, so the end-state on hard API outages is the same — just more
    resilient to transient blips.
- **Score normalization changed from `raw / 15` to `raw / 30`** to match the
  IPS plugin's mapping. Raw score `15` (the "definitely spam" threshold)
  now normalizes to `0.5` instead of `1.0`, preserving signal between
  borderline and high-confidence spam. Admins who had manually tuned
  `spam_threshold` / `suspicious_threshold` should revisit those values
  after the upgrade — the default sensitivity presets are unchanged.

### Install

- Run `composer install --no-dev` in the plugin directory before
  activation. The plugin shows an admin notice and bails out of
  initialization if `vendor/autoload.php` is missing.

## [0.1.0] - 2026-02-14

### Added
- Initial release of the Spamtroll WordPress plugin
- API client (`Spamtroll_Api_Client`) using WordPress HTTP API (`wp_remote_post`/`wp_remote_get`)
- API response wrapper (`Spamtroll_Api_Response`) with score normalization (0-15 raw → 0-1 normalized)
- API exception class (`Spamtroll_Api_Exception`) with static factory methods for common error types
- Comment spam scanning via `preprocess_comment` and `pre_comment_approved` hooks
- Registration spam scanning via `registration_errors` hook
- Fail-open behavior: all API errors allow content through (never blocks on failure)
- Role-based bypass for administrators and editors (configurable)
- Database logging with `{prefix}spamtroll_logs` table (status, scores, symbols, threat categories)
- Daily cron job for log cleanup with configurable retention period
- Admin settings page with sections: API Configuration, Detection Settings, Actions, Bypass, Maintenance
- AJAX-powered "Test Connection" button with nonce verification
- Logs viewer page with status filters (All/Blocked/Suspicious/Safe) and pagination
- Colored status badges for blocked (red), suspicious (orange), and safe (green)
- Settings link on the plugins list page
- Full i18n support with `.pot` translation template
- Clean uninstall: removes settings, drops logs table, clears cron
