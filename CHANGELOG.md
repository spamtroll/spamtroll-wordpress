# Changelog

All notable changes to the Spamtroll WordPress plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-08-24

Every change below traces to a finding in the plugin audit
(`docs/audit/plugins/WORDPRESS.md`). The audit's headline was that fail-open
already worked — all fourteen error paths let content through — so the work
here was to keep that true while fixing what sat around it.

### Security

- **The plugin now refuses to load on PHP older than 8.2 instead of taking the
  site down.** The header declared `Requires PHP: 7.4` above a body using
  `match`, named arguments and `str_contains()`. WordPress only blocks
  *activation* below the declared version, so a site on 7.4 activated the
  plugin happily and then hit a parse error inside `require_once` — a white
  screen on every request, wp-admin included, with no way left to deactivate
  it. `spamtroll.php` is kept parsable on 7.4 so the guard can run and bail
  out with an admin notice. The header, `composer.json` and the CI matrix now
  all say 8.2, and a test fails the build if they drift apart again.
- The API key is no longer rendered in full in the settings form; a saved key
  shows as a masked placeholder and is only replaced when a real value is
  typed.
- Forwarded IP headers (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`)
  are read only when the operator has confirmed the site is behind a trusted
  proxy, and every address is validated with `FILTER_VALIDATE_IP`.
- **The API key can no longer leave the host it was issued for.**
  `wp_remote_request()` follows five redirects by default and Requests
  replays the request headers on every hop, `X-API-Key` included — so a
  redirect this plugin never sees, from a hijacked DNS record or a
  compromised intermediary, would have handed the platform's key to whatever
  host answered. Platform keys have no expiry, so that leak is permanent.
  The adapter now sends `redirection => 0`; there is no legitimate redirect
  on this API, and a 3xx is something to fail open on rather than chase. A
  response-size cap goes in alongside, so a misrouted answer cannot stream
  into memory while a visitor waits.

### Added

- `Spamtroll_Verdict` — the outcome of one scan, with a private constructor
  and exactly one factory that can produce an action other than `allow`. Every
  other path reaches `allow()` because there is nothing else to reach, which
  makes fail-open a property of a type rather than a rule to remember.
- `Spamtroll_Action_Map` — backend verdict to local action as a table, with
  the sensitivity preset selecting a column.
- `Spamtroll_Circuit_Breaker` — stops calling an API already known to be down.
  Three consecutive transport failures open it; a 401, 403, 429 or 402 opens
  it on the first occurrence, because repeating the request cannot change the
  answer. Stored in a non-autoloaded option, not a transient, so a cache flush
  during an incident cannot reopen the floodgates.
- `Spamtroll_Health` — records what the API last said about itself and raises
  an admin notice for the faults that silently switch scanning off.
- `Spamtroll_Feedback` — sends a moderator's "Spam" / "Not spam" corrections
  to `POST /scan/feedback` on a scheduled single event, with a claim flag so
  the backend's 100-per-platform-per-day allowance is not spent on repeats.
- `Spamtroll_Privacy` — registers `wp_privacy_personal_data_exporters` and
  `..._erasers`, and contributes suggested privacy-policy wording.
- `Spamtroll_Retry_After` — parses `Retry-After` in both forms RFC 7231
  allows, clamped to [1s, 1h].
- Extension points: the `spamtroll_scan_verdict` action, the
  `spamtroll_action_for_status` filter and the `spamtroll_client_ip` filter.
  The plugin previously had none.
- `readme.txt` with the `External services` disclosure wordpress.org requires,
  plus `dev/build-zip.sh` and `dev/verify-zip.sh`, which build and check the
  installable archive — the GitHub source ZIP has never worked, because the
  plugin refuses to load without a `vendor/` that is gitignored.
- `dev/prove-regression.sh` — runs the regression suite against the base
  revision's classes and insists it fails there, then runs the fail-open gate
  against those same classes and insists it passes.

### Changed

- **Spam verdicts come from the API's `status` field instead of a local
  recomputation.** The plugin used to divide `spam_score` by a fixed 30 and
  compare the quotient against local thresholds, so on the default preset
  content the backend had classified `blocked` at raw 16–20.9 was only held
  for moderation, `suspicious` content was published outright, and the
  per-platform `SpamThreshold` override had no effect at all. Sensitivity now
  decides only what this site does with the verdict.
- **An unreachable API costs one request instead of three.** The SDK client is
  built with `maxRetries: 1` and a configurable timeout, and the transport
  adapter enforces a wall-clock deadline covering the whole scan. A dead API
  used to hold `preprocess_comment` — and a PHP-FPM worker — for roughly 16.5
  seconds per comment.
- **The verdict cache is keyed on the IP address**, and its TTL is five
  minutes rather than an hour. Leaving the address out meant a `safe` earned
  from a clean IP was reused for the same text sent from a blacklisted one,
  and a block earned by a bad IP was applied to anyone who wrote the same
  short reply.
- The cache stores plain scalars rather than a serialized SDK object, which
  could throw on read after an SDK upgrade changed the class shape.
- A 200 response carrying neither `status` nor `spam_score` — a captive
  portal, a WAF, an empty body — is treated as "no verdict" rather than
  "scanned, clean". It is no longer cached or written to the log as `safe`.
- Spamtroll runs on `pre_comment_approved` at priority 20 and never softens an
  existing `spam` verdict, so it can no longer promote a comment Akismet
  caught into the moderation queue.
- Pingbacks and trackbacks are skipped rather than scored as if a visitor had
  written them.
- The registration scan sends the username as `content` instead of
  `"username email"`, and the email address is stored in its own indexed
  column rather than inside the log's content preview — a value that cannot be
  queried cannot be erased.
- `error_log()` calls are gated behind `WP_DEBUG`, and the rate limiter's
  message survives instead of being flattened to the string `"1"`.
- `composer.lock` is committed, so CI resolves the same dependency set every
  run.

### Fixed

- 401 and 403 reach the administrator. A revoked key, a blocked account or a
  platform disabled in the dashboard used to produce one line in the PHP error
  log while the settings screen still read "Enable Plugin ✓".
- Unticking every bypass role now means nobody bypasses the scan. An empty
  saved list was indistinguishable from "never configured", so the two
  defaults were silently put back.
- The latency budget tops out at 10 seconds rather than 30. This is a person
  waiting on a comment form and the scan fails open, so a thirty-second
  budget never buys a verdict a three-second one misses — it buys thirty
  seconds of a pinned PHP-FPM worker.
- `API URL`, `Latency budget` and `Log Retention` are configurable again.
  Their field renderers existed but were never registered, and `sanitize`
  pinned all three to constants on every save — so a self-hosted instance
  could not be reached and a custom URL was wiped on first save.
- "Test Connection" tests the key in the form rather than the one in the
  database, and no longer answers an unexpected error with an empty 500.
- `?paged=0` on the logs screen no longer produces `OFFSET -20`, a MySQL
  syntax error and a raw database message on screen.
- The logs screen counts every status in one `GROUP BY` instead of five
  separate queries per render.
- `uninstall.php` removes the quota log, the circuit and health records, every
  cached verdict, and — on multisite — all of that on every subsite. Network
  activation now creates the logs table on each site, and a site created later
  gets one too.
- Schema changes reach sites that upgrade in place, which never call the
  activation hook.
- `strtotime()` returning `false` no longer reaches `gmdate()`, which would
  have collapsed the pruning window to the epoch and deleted the whole quota
  log.
- The `.pot` file is regenerated: 11 strings were missing and 10 described
  settings the UI removed in 0.1.1.

### Testing

- `tests/Unit/FailOpenMatrixTest.php` — the fail-open gate, covering all
  fourteen error paths from the audit for comments and registrations. It is
  expected to pass against the pre-fix code as well; that is what shows the
  fix did not buy its behaviour by giving fail-open up.
- `tests/Regression/` — 78 tests, none of which pass against `main`.
- `tests/Unit/ArchTest.php` — pins the properties the fail-open guarantee
  rests on: the private constructor, the two factories, no hand-built verdict
  in the scanner, every `error_log()` gated, every include guarded.
- The QA workflow gained a regression-proof job and a packaging job, plus
  `permissions:` and `concurrency:`.

## [0.1.1] - 2026-04-26

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
