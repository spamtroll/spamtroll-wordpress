<?php

declare(strict_types=1);

/**
 * Stub for static analysis only — never loaded at runtime.
 *
 * The plugin defines these constants at the top of `spamtroll.php`
 * via runtime helpers (`plugin_dir_path()`, `plugin_basename()`),
 * which PHPStan can't constant-fold. Declaring them here gives
 * PHPStan the types it needs without touching the production loader.
 */

if (! defined('SPAMTROLL_VERSION')) {
    define('SPAMTROLL_VERSION', '0.1.0');
}
if (! defined('SPAMTROLL_PLUGIN_DIR')) {
    define('SPAMTROLL_PLUGIN_DIR', '');
}
if (! defined('SPAMTROLL_PLUGIN_URL')) {
    define('SPAMTROLL_PLUGIN_URL', '');
}
if (! defined('SPAMTROLL_PLUGIN_BASENAME')) {
    define('SPAMTROLL_PLUGIN_BASENAME', '');
}
