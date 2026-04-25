<?php

declare(strict_types=1);

/*
 * Tests bootstrap — sets up Brain Monkey + autoload.
 *
 * Brain Monkey lets us redefine WP global functions per-test; Mockery
 * handles class doubles ($wpdb mostly). The plugin's own classes are
 * loaded via Composer's autoload-dev so phpunit/pest can find them
 * without booting WordPress core.
 */

require __DIR__ . '/../vendor/autoload.php';

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (! defined('SPAMTROLL_VERSION')) {
    define('SPAMTROLL_VERSION', '0.1.0-test');
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
if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

require __DIR__ . '/../includes/class-spamtroll-settings.php';
require __DIR__ . '/../includes/class-spamtroll-wp-http-client.php';
require __DIR__ . '/../includes/class-spamtroll-sdk-factory.php';
require __DIR__ . '/../includes/class-spamtroll-logger.php';
require __DIR__ . '/../includes/class-spamtroll-scanner.php';
require __DIR__ . '/../includes/class-spamtroll-admin.php';
