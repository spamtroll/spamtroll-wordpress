<?php

declare(strict_types=1);
/**
 * Plugin Name: Spamtroll Anti-Spam
 * Plugin URI:  https://spamtroll.io
 * Description: Real-time spam detection for comments and registrations powered by the Spamtroll API.
 * Version:     0.1.1
 * Author:      Spamtroll
 * Author URI:  https://spamtroll.io
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: spamtroll
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 *
 * @package Spamtroll
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Plugin version.
 */
define('SPAMTROLL_VERSION', '0.1.1');

/**
 * Plugin directory path (with trailing slash).
 */
define('SPAMTROLL_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * Plugin directory URL (with trailing slash).
 */
define('SPAMTROLL_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Plugin basename (e.g. "spamtroll/spamtroll.php").
 */
define('SPAMTROLL_PLUGIN_BASENAME', plugin_basename(__FILE__));

/*
 * Load the Spamtroll SDK (installed via Composer into the plugin's vendor/).
 */
if (! file_exists(SPAMTROLL_PLUGIN_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Spamtroll is missing its Composer dependencies. Run "composer install --no-dev" in the plugin directory before activating.', 'spamtroll')
            . '</p></div>';
    });
    return;
}
require_once SPAMTROLL_PLUGIN_DIR . 'vendor/autoload.php';

/*
 * Include plugin class files.
 */
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-settings.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-wp-http-client.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-sdk-factory.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-logger.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-scanner.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-admin.php';

/**
 * Run on plugin activation.
 */
function spamtroll_activate(): void
{
    // Create the logs table.
    Spamtroll_Logger::create_table();

    // Set default settings if none exist.
    if (false === get_option('spamtroll_settings')) {
        update_option('spamtroll_settings', [
            'enabled' => 0,
            'api_key' => '',
            'api_url' => 'https://api.spamtroll.io/api/v1',
            'timeout' => 5,
            'check_comments' => 1,
            'check_registrations' => 1,
            'spam_threshold' => 0.70,
            'suspicious_threshold' => 0.40,
            'action_blocked' => 'block',
            'action_suspicious' => 'moderate',
            'bypass_roles' => [ 'administrator', 'editor' ],
            'log_retention_days' => 30,
        ]);
    }

    // Schedule the log cleanup cron job.
    if (! wp_next_scheduled('spamtroll_cleanup_logs')) {
        wp_schedule_event(time(), 'daily', 'spamtroll_cleanup_logs');
    }
}
register_activation_hook(__FILE__, 'spamtroll_activate');

/**
 * Run on plugin deactivation.
 */
function spamtroll_deactivate(): void
{
    wp_clear_scheduled_hook('spamtroll_cleanup_logs');
}
register_deactivation_hook(__FILE__, 'spamtroll_deactivate');

/**
 * Initialize the plugin on `plugins_loaded`.
 */
function spamtroll_init_plugin(): void
{
    $scanner = new Spamtroll_Scanner();
    $scanner->init();

    if (is_admin()) {
        $admin = new Spamtroll_Admin();
        $admin->init();
    }
}
add_action('plugins_loaded', 'spamtroll_init_plugin');

/**
 * Load translations.
 */
function spamtroll_load_textdomain(): void
{
    load_plugin_textdomain('spamtroll', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('init', 'spamtroll_load_textdomain');

/**
 * Handle the daily log cleanup cron event.
 */
function spamtroll_do_cleanup_logs(): void
{
    Spamtroll_Logger::cleanup(Spamtroll_Settings::int('log_retention_days', 30));
}
add_action('spamtroll_cleanup_logs', 'spamtroll_do_cleanup_logs');
