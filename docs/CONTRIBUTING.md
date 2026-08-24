# Contributing

This page documents the development setup for the Spamtroll WordPress
plugin. End-users do not need any of this — they install the release
ZIP through `Plugins → Add New → Upload`.

## Local setup

The plugin runs on PHP 8.0+ in production but the **dev tooling
requires PHP 8.3+** (Pest, peck, php-cs-fixer). CI runs the test
matrix on 8.2/8.3/8.4 (Pest 2 needs 8.2+).

```bash
git clone https://github.com/spamtroll/spamtroll-wordpress.git
cd spamtroll-wordpress
composer install
```

You also need `aspell` + `aspell-en` for the spell-check:

```bash
sudo apt install aspell aspell-en       # Debian / Ubuntu
brew install aspell                     # macOS
```

If you skip aspell, `composer peck` will fail locally — CI runs it for
every PR, so this is optional locally.

## Quality gate

Before opening a PR:

```bash
composer qa
```

Runs in order:

1. `composer lint` — php-cs-fixer dry-run. Failure → run `composer lint:fix`.
2. `composer stan` — PHPStan level 9 with the WordPress stubs from
   `szepeviktor/phpstan-wordpress`. Source code is fully clean (0
   baseline entries). Memory limit bumped to 1G because the WP stub
   set is large.
3. `composer peck` — aspell-based spell-check. Add domain words to
   `peck.json` if a real word is flagged as a typo.
4. `composer test` — Pest suite with Brain Monkey + Mockery for WP
   function doubles.

CI runs the same set on every push / PR. We won't merge red CI.

## Coding standards

- **PSR-12 hybrid** enforced by php-cs-fixer (`@PSR12 +
  @PSR12:risky + @PHP80Migration:risky`). PSR-12 doesn't enforce
  camelCase, so the plugin keeps WordPress's snake_case method naming
  (`check_comment`, `sanitize_settings`, `render_field_*`) — hooks
  registered via `add_action`/`add_filter` are part of the WordPress
  public surface and break if renamed.
- All files declare `strict_types=1`.
- **PHPStan level 9** clean, with `phpstan-wordpress` providing the WP
  function/class stubs. Custom plugin constants (`SPAMTROLL_VERSION`,
  etc.) are stubbed in `phpstan-bootstrap.php`.
- Settings reads go through `Spamtroll_Settings::string|int|float|bool|stringList`
  — never call `get_option('spamtroll_settings')` directly. The
  helper does the `is_array()` narrowing once so the rest of the
  plugin reads like normal code.

## Tests

Pest, split in two on purpose.

`tests/Unit/` holds ordinary unit tests plus the **fail-open gate**
(`FailOpenMatrixTest`), which runs every error path from the API
contract and asserts the visitor's content survives it. That file is
expected to pass against old revisions too — it guards a promise, not
a change.

`tests/Regression/` holds tests that must **fail** against the revision
before the fix. A test that passes on the broken code is not a
regression test.

`tests/Support/` boots a fake WordPress (`WpEnv`) whose only seam is
`wp_remote_request()` — the last line before the socket. Everything
between the hook and that call is the real scanner, the real transport
adapter and the real SDK. `ApiFixtures` holds the API's four error-body
shapes as data.

```bash
composer test                  # full Pest suite
composer test:coverage         # with coverage report
bash dev/prove-regression.sh   # regression suite red on main, gate green
```

`dev/prove-regression.sh` points the harness at another revision through
`SPAMTROLL_INCLUDES_DIR` and checks both halves. Run it before opening a
PR; CI runs it too.

Cover both happy-path and failure modes. The plugin's core promise is
"never block content on an SDK error" — every scanner test should
also assert the comment/registration goes through when the API
fails.

## Upgrading the Spamtroll SDK

The constraint is `spamtroll/php-sdk: ^0.9.3`. Caret on a `0.x` version
pins the minor, so 0.10.0 will not arrive on its own — the bump is a
deliberate edit, and these are the things to do while making it.

**Blocking:** 0.10.0 requires `php >=8.2`. The plugin already declares
that, so Composer is satisfied.

**Delete on the way in.** Each of these exists only to work around
something 0.9.3 does not do:

| Plugin code | Replaced by |
|---|---|
| `Spamtroll_Wp_Http_Client::set_deadline()` and its check in `send()` | `ClientConfig::$totalBudgetMs` |
| `Spamtroll_Sdk_Factory::MAX_RETRIES` | the SDK's new defaults (timeout 3, 2 retries, 250 ms base) |
| `Spamtroll_Scanner::error_text()` | `Response::getErrorCode()` / a `$error` that is no longer `"1"` |
| `Spamtroll_Scanner::carries_verdict()` | `CheckSpamResponse::hasVerdict()` |
| the `try`/`catch` around `checkSpam()` | `Client::checkSpamOrHam()`, which never throws |
| `Spamtroll_Feedback`'s hand-rolled POST | `Client::submitFeedback()` / `trySubmitFeedback()` with `FeedbackRequest` |

**Keep.** `Spamtroll_Circuit_Breaker` is entirely outside the SDK.
`get_last_headers()` stays too: `Client::dispatch()` still collapses a
response into `[success, code, decoded, error]`, so `Retry-After` and the
`X-RateLimit-*` family remain unreachable through `checkSpam()`. Keys
must stay lowercased.

**Watch.** `ClientConfig` validates `baseUrl` and throws
`InvalidConfigurationException` on anything that is not http(s);
`Spamtroll_Settings::api_url()` already refuses to return such a value,
so keep that guard rather than trusting the stored option. And keep
`MAX_TIMEOUT` at or below the SDK's `totalBudgetMs`, or a budget saved in
the settings screen is silently overridden by the SDK's own.

## Release checklist

1. Bump `SPAMTROLL_VERSION` in `spamtroll.php`.
2. Move `[Unreleased]` in `CHANGELOG.md` under a dated version.
3. `composer qa` — must be green.
4. Commit, tag `v<version>`, push tag.
5. Update `Stable tag` in `readme.txt` to match the header version.
6. Build and check the release ZIP:

   ```bash
   bash dev/build-zip.sh && bash dev/verify-zip.sh
   ```

   It lists what goes into the archive rather than what stays out, so a
   new development directory cannot reach a live site by being
   forgotten, and it vendors production dependencies — the plugin
   refuses to load without them, which is why a ZIP taken from GitHub's
   "Download source" button has never worked.
7. Upload the ZIP via WordPress.org SVN or GitHub Releases.
