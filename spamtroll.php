<?php

declare(strict_types=1);
/**
 * Plugin Name: Spamtroll Anti-Spam
 * Plugin URI:  https://spamtroll.io
 * Description: Real-time spam detection for comments and registrations powered by the Spamtroll API.
 * Version:     0.2.0
 * Author:      Spamtroll
 * Author URI:  https://spamtroll.io
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: spamtroll
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 8.2
 *
 * @package Spamtroll
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Plugin version.
 */
define('SPAMTROLL_VERSION', '0.2.0');

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

/**
 * Lowest PHP this plugin's code can be parsed on, as PHP_VERSION_ID.
 *
 * The plugin body uses match expressions, named arguments, readonly
 * promotion and enums; on anything older than 8.2 the `require_once`
 * calls below do not fail politely, they emit a parse error, and a parse
 * error in a plugin that is already active is a white screen on every
 * request including wp-admin — with no way left to deactivate it.
 *
 * `Requires PHP` in the header only stops *activation* on older PHP.
 * It does nothing for a site that upgraded the plugin, or downgraded
 * PHP, while it was active. This file is therefore kept parsable on
 * PHP 7.4 — the version the plugin used to claim, and so the version a
 * site is most likely to still be on — so the guard can run and bail out
 * before anything else is loaded. Do not use 8.0+ syntax in this file.
 */
define('SPAMTROLL_MIN_PHP_ID', 80200);
define('SPAMTROLL_MIN_PHP', '8.2');

/**
 * Tell the administrator why the plugin is inert instead of dying silently.
 *
 * Declared before the guard rather than after it, so it does not depend on
 * PHP hoisting a declaration that sits past a top-level `return`.
 */
function spamtroll_render_php_version_notice(): void
{
    echo '<div class="notice notice-error"><p>'
        . esc_html(
            sprintf(
                /* translators: %1$s: required PHP version, %2$s: running PHP version */
                __('Spamtroll Anti-Spam needs PHP %1$s or newer. This site runs PHP %2$s, so spam scanning is switched off. Ask your host to upgrade PHP, or deactivate the plugin.', 'spamtroll'),
                SPAMTROLL_MIN_PHP,
                PHP_VERSION,
            ),
        )
        . '</p></div>';
}

if (PHP_VERSION_ID < SPAMTROLL_MIN_PHP_ID) {
    add_action('admin_notices', 'spamtroll_render_php_version_notice');
    add_action('network_admin_notices', 'spamtroll_render_php_version_notice');
    return;
}

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
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-retry-after.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-circuit-breaker.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-health.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-wp-http-client.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-sdk-factory.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-logger.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-action-map.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-verdict.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-scanner.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-feedback.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-privacy.php';
require_once SPAMTROLL_PLUGIN_DIR . 'includes/class-spamtroll-admin.php';

/**
 * Run on plugin activation.
 */
function spamtroll_activate(bool $network_wide = false): void
{
    if (is_multisite() && $network_wide) {
        // delete_option() and CREATE TABLE both act on whichever blog is
        // current, so a network activation that only touches the primary
        // site leaves every other subsite without a logs table — and the
        // first comment there fails its insert. Walk the network instead.
        foreach (get_sites([ 'fields' => 'ids', 'number' => 0 ]) as $blog_id) {
            switch_to_blog((int) $blog_id);
            spamtroll_activate_single_site();
            restore_current_blog();
        }
        return;
    }

    spamtroll_activate_single_site();
}
register_activation_hook(__FILE__, 'spamtroll_activate');

/**
 * Per-site half of activation. Also runs for a subsite created after a
 * network activation, via `wp_initialize_site`.
 */
function spamtroll_activate_single_site(): void
{
    // Create the logs table.
    Spamtroll_Logger::create_table();

    // Set default settings if none exist.
    if (false === get_option(Spamtroll_Settings::OPTION_KEY)) {
        update_option(Spamtroll_Settings::OPTION_KEY, [
            'enabled' => 0,
            'api_key' => '',
            'api_url' => \Spamtroll\Sdk\ClientConfig::DEFAULT_BASE_URL,
            'timeout' => Spamtroll_Settings::DEFAULT_TIMEOUT,
            'check_comments' => 1,
            'check_registrations' => 1,
            'sensitivity' => Spamtroll_Action_Map::PRESET_BALANCED,
            'bypass_roles' => [ 'administrator', 'editor' ],
            'log_retention_days' => Spamtroll_Settings::DEFAULT_RETENTION_DAYS,
            'send_feedback' => 1,
            'trust_proxy' => 0,
        ], false);
    }

    // Schedule the log cleanup cron job.
    if (! wp_next_scheduled('spamtroll_cleanup_logs')) {
        wp_schedule_event(time(), 'daily', 'spamtroll_cleanup_logs');
    }
}

/**
 * Give a subsite created after a network activation its own logs table.
 */
function spamtroll_initialize_new_site(WP_Site $site): void
{
    if (! is_plugin_active_for_network(SPAMTROLL_PLUGIN_BASENAME)) {
        return;
    }
    switch_to_blog((int) $site->blog_id);
    spamtroll_activate_single_site();
    restore_current_blog();
}
add_action('wp_initialize_site', 'spamtroll_initialize_new_site', 20);

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
    // Schema upgrades run for every request, not only on activation:
    // WordPress updates a plugin by unpacking files over the old ones and
    // never calls the activation hook, so a column added in a release
    // would otherwise never reach a site that upgraded in place.
    Spamtroll_Logger::maybe_upgrade_schema();

    $scanner = new Spamtroll_Scanner();
    $scanner->init();

    // Privacy and feedback hooks are registered unconditionally. An export
    // or erasure request must be honoured even when scanning is switched
    // off — the rows are already in the table either way.
    (new Spamtroll_Privacy())->init();
    (new Spamtroll_Feedback())->init();

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
    Spamtroll_Logger::cleanup(Spamtroll_Settings::retention_days());
}
add_action('spamtroll_cleanup_logs', 'spamtroll_do_cleanup_logs');
