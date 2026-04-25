<?php

declare(strict_types=1);
/**
 * Spamtroll Logger
 *
 * @package Spamtroll
 *
 * @since   0.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Database logger for spam scan results.
 */
class Spamtroll_Logger
{
    /**
     * Get the log table name.
     *
     * @return string
     */
    public static function get_table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'spamtroll_logs';
    }

    /**
     * Create the log table.
     */
    public static function create_table(): void
    {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			content_type VARCHAR(50) NOT NULL DEFAULT '',
			content_id BIGINT(20) UNSIGNED DEFAULT NULL,
			ip_address VARCHAR(46) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'safe',
			spam_score DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
			raw_score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
			symbols TEXT DEFAULT NULL,
			threat_categories TEXT DEFAULT NULL,
			action_taken VARCHAR(20) NOT NULL DEFAULT 'allow',
			content_preview TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY content_type (content_type),
			KEY created_at (created_at)
		) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Log a scan result.
     *
     * @param array<string, mixed> $entry Log entry data.
     *
     * @return bool True on success, false on failure.
     */
    public static function log(array $entry): bool
    {
        global $wpdb;

        try {
            $data = [
                'user_id' => isset($entry['user_id']) && is_scalar($entry['user_id']) ? absint($entry['user_id']) : null,
                'content_type' => isset($entry['content_type']) && is_scalar($entry['content_type']) ? sanitize_text_field((string) $entry['content_type']) : '',
                'content_id' => isset($entry['content_id']) && is_scalar($entry['content_id']) ? absint($entry['content_id']) : null,
                'ip_address' => isset($entry['ip_address']) && is_scalar($entry['ip_address']) ? sanitize_text_field((string) $entry['ip_address']) : null,
                'status' => isset($entry['status']) && is_scalar($entry['status']) ? sanitize_text_field((string) $entry['status']) : 'safe',
                'spam_score' => isset($entry['spam_score']) && is_numeric($entry['spam_score']) ? (float) $entry['spam_score'] : 0.0,
                'raw_score' => isset($entry['raw_score']) && is_numeric($entry['raw_score']) ? (float) $entry['raw_score'] : 0.0,
                'symbols' => isset($entry['symbols']) ? (wp_json_encode($entry['symbols']) ?: null) : null,
                'threat_categories' => isset($entry['threat_categories']) ? (wp_json_encode($entry['threat_categories']) ?: null) : null,
                'action_taken' => isset($entry['action_taken']) && is_scalar($entry['action_taken']) ? sanitize_text_field((string) $entry['action_taken']) : 'allow',
                'content_preview' => isset($entry['content_preview']) && is_scalar($entry['content_preview']) ? mb_substr(sanitize_text_field((string) $entry['content_preview']), 0, 500) : null,
                'created_at' => current_time('mysql'),
            ];

            $formats = [ '%d', '%s', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s' ];

            return (bool) $wpdb->insert(self::get_table_name(), $data, $formats);
        } catch (\Throwable $e) {
            // Never block on logging errors.
            error_log('Spamtroll Logger error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up old log entries.
     *
     * @param int $retention_days Number of days to retain logs.
     *
     * @return int Number of rows deleted.
     */
    public static function cleanup(int $retention_days = 30): int
    {
        global $wpdb;

        $table_name = self::get_table_name();
        $days = absint($retention_days);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days,
            ),
        );
    }

    /**
     * Get recent log entries.
     *
     * @param array<string, mixed> $args Query arguments (status, per_page, page).
     *
     * @return array{logs: list<array<string, mixed>>, total: int}
     */
    public static function get_recent_logs(array $args = []): array
    {
        global $wpdb;

        $defaults = [
            'status' => '',
            'per_page' => 20,
            'page' => 1,
        ];
        $args = wp_parse_args($args, $defaults);

        $table_name = self::get_table_name();
        $where = '1=1';
        $params = [];

        if (! empty($args['status'])) {
            $where .= ' AND status = %s';
            $params[] = sanitize_text_field($args['status']);
        }

        $per_page = absint($args['per_page']);
        $offset = (absint($args['page']) - 1) * $per_page;

        if (! empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE {$where}", $params));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $logs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    array_merge($params, [ $per_page, $offset ]),
                ),
                ARRAY_A,
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE {$where}");
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $logs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $per_page,
                    $offset,
                ),
                ARRAY_A,
            );
        }

        return [
            'logs' => is_array($logs) ? array_values(array_filter($logs, 'is_array')) : [],
            'total' => $total,
        ];
    }

    /**
     * Get a single log entry.
     *
     * @return array<string, mixed>|null Log entry or null.
     */
    public static function get_log(int $id): ?array
    {
        global $wpdb;
        $table_name = self::get_table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", absint($id)),
            ARRAY_A,
        );
        return is_array($row) ? $row : null;
    }

    public static function delete_log(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete(self::get_table_name(), [ 'id' => absint($id) ], [ '%d' ]);
    }
}
