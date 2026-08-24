<?php

declare(strict_types=1);

/*
 * WORDPRESS.md §2.1 — the header has to declare the PHP the code needs.
 *
 * `Requires PHP: 7.4` sat above a body using match expressions, named
 * arguments, str_contains() and trailing commas in parameter lists. WordPress
 * only blocks *activation* below the declared version, so on PHP 7.4 the
 * plugin activated cheerfully and then hit a parse error inside require_once
 * — a white screen on every request, wp-admin included, with no way left to
 * deactivate it through the UI.
 *
 * These are file assertions rather than behaviour because that is where the
 * defect lives: three files stating three different baselines.
 */

$root = SPAMTROLL_TEST_ROOT;

it('declares a plugin header baseline of at least 8.2', function () use ($root): void {
    $header = (string) file_get_contents($root . '/spamtroll.php');

    expect($header)->toMatch('/^\s*\*\s*Requires PHP:\s*8\.[2-9]/m');
});

it('declares the same baseline in composer.json', function () use ($root): void {
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

    expect($composer['require']['php'])->toBe('>=8.2');
    expect($composer['config']['platform']['php'])->toBe('8.2');
});

it('guards the require_once calls behind a runtime version check', function () use ($root): void {
    $bootstrap = (string) file_get_contents($root . '/spamtroll.php');

    $guard = strpos($bootstrap, 'PHP_VERSION_ID < SPAMTROLL_MIN_PHP_ID');
    $first_require = strpos($bootstrap, "require_once SPAMTROLL_PLUGIN_DIR . 'includes/");

    expect($guard)->not->toBeFalse();
    expect($first_require)->not->toBeFalse();
    expect($guard)->toBeLessThan($first_require);
});

it('keeps the entry point itself parsable on the oldest PHP WordPress supports', function () use ($root): void {
    // The guard is worthless if the file carrying it cannot be parsed. Every
    // 8.0+ construct has to live behind the require_once, not in front of it.
    $bootstrap = (string) file_get_contents($root . '/spamtroll.php');
    $head = substr($bootstrap, 0, (int) strpos($bootstrap, "require_once SPAMTROLL_PLUGIN_DIR . 'includes/"));

    foreach ([ 'match (', '?->', 'readonly ', 'enum ', 'str_contains(', 'str_starts_with(' ] as $modern) {
        expect($head)->not->toContain($modern);
    }
});

it('does not promise PHP 7.4 anywhere in the README', function () use ($root): void {
    expect((string) file_get_contents($root . '/README.md'))->not->toContain('7.4');
});
