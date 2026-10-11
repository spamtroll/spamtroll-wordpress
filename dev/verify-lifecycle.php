<?php

declare(strict_types=1);

/**
 * Real WordPress regression checks. Run only on a disposable local database:
 * SPAMTROLL_QA=1 wp eval-file dev/verify-lifecycle.php single|network|deactivated|uninstalled --use-include
 *
 * @package Spamtroll
 */

if ('1' !== getenv('SPAMTROLL_QA') || 'local' !== wp_get_environment_type()) {
    WP_CLI::error('Requires SPAMTROLL_QA=1 and WP_ENVIRONMENT_TYPE=local on a disposable install.');
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$phase = $args[0] ?? 'single';
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        WP_CLI::error($message);
    }
    WP_CLI::log('PASS: ' . $message);
};

global $wpdb;

if ('single' === $phase) {
    $assert(is_plugin_active('spamtroll/spamtroll.php'), 'Packaged plugin activates');
    $assert(0 === Spamtroll_Settings::int('enabled'), 'Fresh install sends no visitor data');
    $assert(false !== wp_next_scheduled('spamtroll_cleanup_logs'), 'Daily cleanup scheduled');

    $settings = Spamtroll_Settings::all();
    $settings['api_key'] = 'synthetic-local-test-key';
    $settings['enabled'] = 1;
    $settings['bypass_roles'] = [];
    update_option(Spamtroll_Settings::OPTION_KEY, $settings, false);
    $table = Spamtroll_Logger::get_table_name();
    $wpdb->query("ALTER TABLE {$table} DROP COLUMN submission_id");
    update_option(Spamtroll_Logger::SCHEMA_OPTION, 1, false);
    Spamtroll_Logger::maybe_upgrade_schema();
    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
    $assert(in_array('submission_id', $columns, true), 'In-place schema upgrade restores added column');
    $assert($settings === Spamtroll_Settings::all(), 'Schema upgrade preserves settings');

    add_filter('pre_http_request', static function ($pre, $http, $url) {
        if (str_starts_with($url, 'https://api.spamtroll.io/')) {
            return [
                'headers' => [],
                'body' => wp_json_encode([
                    'success' => true,
                    'data' => [
                        'status' => 'blocked',
                        'spam_score' => 30,
                        'symbols' => [],
                        'threat_categories' => [],
                        'submission_id' => '00000000-0000-4000-8000-000000000043',
                    ],
                ]),
                'response' => [ 'code' => 200, 'message' => 'OK' ],
            ];
        }
        return new WP_Error('qa_external_http', 'No external HTTP allowed in lifecycle fixture.');
    }, 10, 3);

    $_SERVER['REMOTE_ADDR'] = '198.51.100.43';
    $scanner = new Spamtroll_Scanner();
    $scanner->init();
    $comment = apply_filters('preprocess_comment', [
        'comment_content' => 'Synthetic lifecycle spam example',
        'comment_author' => 'QA',
        'comment_author_email' => 'subject@example.test',
    ]);
    $assert('spam' === apply_filters('pre_comment_approved', 1, $comment), 'WordPress comment hooks enforce API verdict');
    $logs = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $assert(1 === (int) $logs, 'Scan inserts a real database log row');
    $export = (new Spamtroll_Privacy())->export('subject@example.test');
    $assert(1 === count($export['data']), 'WordPress privacy export includes scan');
    $erase = (new Spamtroll_Privacy())->erase('subject@example.test');
    $assert($erase['items_removed'], 'WordPress privacy erasure removes scan');
    WP_CLI::success('Single-site lifecycle verified without API credentials or outbound scans.');
    return;
}

$assert(is_multisite(), 'Fixture is a real WordPress multisite');
foreach (get_sites([ 'fields' => 'ids', 'number' => 0 ]) as $blog_id) {
    switch_to_blog((int) $blog_id);
    $table = $wpdb->prefix . 'spamtroll_logs';
    if ('network' === $phase) {
        $assert(false !== get_option('spamtroll_settings'), 'Subsite settings exist: ' . $blog_id);
        $assert(false !== wp_next_scheduled('spamtroll_cleanup_logs'), 'Subsite cleanup scheduled: ' . $blog_id);
        $assert($table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))), 'Subsite logs table exists: ' . $blog_id);
    } elseif ('deactivated' === $phase) {
        $assert(false === wp_next_scheduled('spamtroll_cleanup_logs'), 'Network deactivation clears subsite cron: ' . $blog_id);
        $assert(false !== get_option('spamtroll_settings'), 'Deactivation preserves settings: ' . $blog_id);
    } elseif ('uninstalled' === $phase) {
        $assert(false === get_option('spamtroll_settings'), 'Uninstall removes subsite settings: ' . $blog_id);
        $assert(null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))), 'Uninstall removes subsite logs: ' . $blog_id);
        $assert(false === wp_next_scheduled('spamtroll_cleanup_logs'), 'Uninstall removes subsite cron: ' . $blog_id);
    } else {
        WP_CLI::error('Unknown phase: ' . $phase);
    }
    restore_current_blog();
}
WP_CLI::success('Multisite phase verified: ' . $phase);
