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
     * Bumped whenever the table's columns change.
     *
     * WordPress updates a plugin by unpacking the new files over the old
     * ones; `register_activation_hook` does not fire. Without a stored
     * version to compare against, a column added in a release only ever
     * exists on installs created after it — and every write that mentions
     * the column fails on the rest.
     */
    public const SCHEMA_VERSION = 2;

    public const SCHEMA_OPTION = 'spamtroll_db_version';

    /**
     * Get the log table name.
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
			submission_id VARCHAR(36) DEFAULT NULL,
			ip_address VARCHAR(46) DEFAULT NULL,
			email VARCHAR(191) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'safe',
			spam_score DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
			raw_score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
			symbols TEXT DEFAULT NULL,
			threat_categories TEXT DEFAULT NULL,
			action_taken VARCHAR(20) NOT NULL DEFAULT 'allow',
			content_preview TEXT DEFAULT NULL,
			feedback_sent TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY content_type (content_type),
			KEY created_at (created_at),
			KEY content_id (content_id),
			KEY email (email)
		) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    /**
     * Bring an existing install's table up to the current schema.
     *
     * `dbDelta()` is additive and idempotent, so replaying the CREATE TABLE
     * is the whole upgrade; the stored version only exists to keep it off
     * the hot path.
     */
    public static function maybe_upgrade_schema(): void
    {
        $stored = get_option(self::SCHEMA_OPTION, 0);
        if (is_numeric($stored) && (int) $stored >= self::SCHEMA_VERSION) {
            return;
        }

        self::create_table();
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
                'content_type' => self::text($entry, 'content_type', ''),
                'content_id' => isset($entry['content_id']) && is_scalar($entry['content_id']) ? absint($entry['content_id']) : null,
                'submission_id' => self::text($entry, 'submission_id', null),
                'ip_address' => self::text($entry, 'ip_address', null),
                'email' => self::text($entry, 'email', null),
                'status' => self::text($entry, 'status', 'safe'),
                'spam_score' => isset($entry['spam_score']) && is_numeric($entry['spam_score']) ? (float) $entry['spam_score'] : 0.0,
                'raw_score' => isset($entry['raw_score']) && is_numeric($entry['raw_score']) ? (float) $entry['raw_score'] : 0.0,
                'symbols' => isset($entry['symbols']) ? (wp_json_encode($entry['symbols']) ?: null) : null,
                'threat_categories' => isset($entry['threat_categories']) ? (wp_json_encode($entry['threat_categories']) ?: null) : null,
                'action_taken' => self::text($entry, 'action_taken', 'allow'),
                'content_preview' => isset($entry['content_preview']) && is_scalar($entry['content_preview']) ? mb_substr(sanitize_text_field((string) $entry['content_preview']), 0, 500) : null,
                'created_at' => current_time('mysql'),
            ];

            $formats = [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s' ];

            return (bool) $wpdb->insert(self::get_table_name(), $data, $formats);
        } catch (\Throwable $e) {
            // Never block on logging errors.
            self::debug('Logger error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Attach a comment ID to the most recent matching scan row.
     *
     * `preprocess_comment` runs before the comment exists, so the ID cannot
     * be written at scan time. Without it there is no way back from a
     * moderator's "Spam" click to the submission the API scored, which is
     * what /scan/feedback needs.
     */
    public static function attach_comment(int $comment_id, ?string $submission_id): bool
    {
        global $wpdb;

        if (null === $submission_id || '' === $submission_id) {
            return false;
        }

        return (bool) $wpdb->update(
            self::get_table_name(),
            [ 'content_id' => $comment_id ],
            [ 'submission_id' => $submission_id ],
            [ '%d' ],
            [ '%s' ],
        );
    }

    /**
     * The submission the API assigned to a comment, or null.
     */
    public static function submission_id_for_comment(int $comment_id): ?string
    {
        global $wpdb;
        $table_name = self::get_table_name();

        $value = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT submission_id FROM {$table_name} WHERE content_id = %d AND content_type = 'comment' AND submission_id IS NOT NULL ORDER BY id DESC LIMIT 1",
                $comment_id,
            ),
        );

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Mark a submission as having had moderator feedback sent, and report
     * whether this call is the one that marked it.
     *
     * The backend caps feedback at 100 per platform per day, so re-sending
     * the same verdict every time a moderator toggles a comment would spend
     * the allowance on nothing.
     */
    public static function mark_feedback_sent(string $submission_id): bool
    {
        global $wpdb;

        return 0 < (int) $wpdb->update(
            self::get_table_name(),
            [ 'feedback_sent' => 1 ],
            [ 'submission_id' => $submission_id, 'feedback_sent' => 0 ],
            [ '%d' ],
            [ '%s', '%d' ],
        );
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
        $days = max(1, absint($retention_days));

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

        $per_page = max(1, absint($args['per_page']));
        // `?paged=0` used to reach this as page 0, giving OFFSET -20 — which
        // is a MySQL syntax error, an empty table, and a raw database message
        // on screen whenever WP_DEBUG_DISPLAY is on.
        $offset = (max(1, absint($args['page'])) - 1) * $per_page;

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
     * Row counts per status, in one query.
     *
     * The logs screen used to run five separate COUNT(*) queries — one per
     * filter tab plus the listing's own — on every render.
     *
     * @return array<string, int> Status to count, plus a `_all` total.
     */
    public static function count_by_status(): array
    {
        global $wpdb;
        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table_name} GROUP BY status", ARRAY_A);

        $counts = [ '_all' => 0 ];
        if (! is_array($rows)) {
            return $counts;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['status'], $row['total'])) {
                continue;
            }
            $status = is_scalar($row['status']) ? (string) $row['status'] : '';
            $total = is_numeric($row['total']) ? (int) $row['total'] : 0;
            $counts[ $status ] = $total;
            $counts['_all'] += $total;
        }

        return $counts;
    }

    /**
     * Every row that concerns one person, for an export or erasure request.
     *
     * Matching is on the email address because that is the only identifier
     * WordPress's privacy tools hand to a callback, and it is why the address
     * has a column of its own rather than being buried in the content
     * preview: a value that cannot be queried cannot be erased.
     *
     * @return list<array<string, mixed>>
     */
    public static function find_by_email(string $email, int $per_page, int $page): array
    {
        global $wpdb;
        $table_name = self::get_table_name();

        $per_page = max(1, $per_page);
        $offset = (max(1, $page) - 1) * $per_page;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$table_name} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
                $email,
                $per_page,
                $offset,
            ),
            ARRAY_A,
        );

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Delete every row belonging to one person.
     *
     * @return int Rows removed.
     */
    public static function delete_by_email(string $email): int
    {
        global $wpdb;

        return (int) $wpdb->delete(self::get_table_name(), [ 'email' => $email ], [ '%s' ]);
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
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

    /**
     * @param array<string, mixed> $entry
     */
    private static function text(array $entry, string $key, ?string $default): ?string
    {
        if (! isset($entry[ $key ]) || ! is_scalar($entry[ $key ])) {
            return $default;
        }
        $value = sanitize_text_field((string) $entry[ $key ]);
        return '' === $value ? $default : $value;
    }

    private static function debug(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Spamtroll: ' . $message);
        }
    }
}
