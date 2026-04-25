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

Pest, in `tests/Unit/`. Brain Monkey (`Brain\Monkey\Functions\when()`)
mocks WP functions. Mockery handles class doubles (`$wpdb`, `WP_Error`).

```bash
composer test               # full Pest suite
composer test:coverage      # with coverage report
```

Cover both happy-path and failure modes. The plugin's core promise is
"never block content on an SDK error" — every scanner test should
also assert the comment/registration goes through when the API
fails.

## Release checklist

1. Bump `SPAMTROLL_VERSION` in `spamtroll.php`.
2. Move `[Unreleased]` in `CHANGELOG.md` under a dated version.
3. `composer qa` — must be green.
4. Commit, tag `v<version>`, push tag.
5. Build the release ZIP: `composer install --no-dev
   --optimize-autoloader && zip -r spamtroll.zip . -x ".git/*"
   "tests/*" "phpstan*" ".php-cs-fixer*" "peck.json" "*.md"`.
6. Upload the ZIP via WordPress.org SVN or GitHub Releases.
