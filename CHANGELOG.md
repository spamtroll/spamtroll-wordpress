# Changelog

All notable changes to the Spamtroll WordPress plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
