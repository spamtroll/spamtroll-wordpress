<?php

declare(strict_types=1);

/*
 * Tests bootstrap — sets up Brain Monkey + autoload.
 *
 * Brain Monkey lets us redefine WP global functions per-test; Mockery
 * handles class doubles ($wpdb mostly). The plugin's own classes are
 * loaded from disk, without booting WordPress core.
 *
 * SPAMTROLL_INCLUDES_DIR exists so dev/prove-regression.sh can point the
 * same suite at the pre-fix classes and prove the tests actually fail
 * against them. A test that passes on the broken code is decoration, not a
 * regression test.
 */

require __DIR__ . '/../vendor/autoload.php';

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (! defined('SPAMTROLL_VERSION')) {
    define('SPAMTROLL_VERSION', '0.2.0-test');
}
if (! defined('SPAMTROLL_PLUGIN_DIR')) {
    define('SPAMTROLL_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (! defined('SPAMTROLL_PLUGIN_URL')) {
    define('SPAMTROLL_PLUGIN_URL', 'http://example.test/wp-content/plugins/spamtroll/');
}
if (! defined('SPAMTROLL_PLUGIN_BASENAME')) {
    define('SPAMTROLL_PLUGIN_BASENAME', 'spamtroll/spamtroll.php');
}
if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$includes = getenv('SPAMTROLL_INCLUDES_DIR');
$includes = is_string($includes) && '' !== $includes
    ? rtrim($includes, '/')
    : dirname(__DIR__) . '/includes';

if (! defined('SPAMTROLL_TEST_INCLUDES_DIR')) {
    define('SPAMTROLL_TEST_INCLUDES_DIR', $includes);
}

// The plugin root that goes with those classes. dev/prove-regression.sh lays
// out the base revision's entry point and metadata next to its includes/, so
// the file-level assertions (the PHP baseline, chiefly) are pointed at the
// same revision as everything else.
if (! defined('SPAMTROLL_TEST_ROOT')) {
    define('SPAMTROLL_TEST_ROOT', dirname($includes));
}

/*
 * Load order matters: Spamtroll_Action_Map's constant table references
 * Spamtroll_Verdict at class-definition time, and the scanner references
 * both. Everything else is order-independent, so the named files go first
 * and the glob picks up whatever a given revision happens to have.
 */
foreach (
    [
        'class-spamtroll-settings.php',
        'class-spamtroll-retry-after.php',
        'class-spamtroll-verdict.php',
        'class-spamtroll-action-map.php',
    ] as $first
) {
    if (file_exists($includes . '/' . $first)) {
        require_once $includes . '/' . $first;
    }
}

foreach ((array) glob($includes . '/class-spamtroll-*.php') as $file) {
    if (is_string($file)) {
        require_once $file;
    }
}

/*
 * Core classes the plugin type-hints against. Only the surface the plugin
 * touches is here; anything more would be inventing WordPress.
 */
if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, list<string>> */
        public array $errors = [];

        public function add(string $code, string $message = '', $data = null): void
        {
            unset($data);
            $this->errors[ $code ][] = $message;
        }

        /**
         * @return list<string>
         */
        public function get_error_codes(): array
        {
            return array_keys($this->errors);
        }

        public function get_error_message(string $code = ''): string
        {
            $code = '' === $code ? (array_key_first($this->errors) ?? '') : $code;
            return $this->errors[ $code ][0] ?? '';
        }
    }
}

if (! class_exists('WP_Comment')) {
    class WP_Comment
    {
        public string $comment_ID = '0';

        public string $comment_content = '';
    }
}
