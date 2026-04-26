<?php

declare(strict_types=1);

/**
 * Spamtroll Scanner.
 *
 * @package Spamtroll
 *
 * @since   0.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Handles spam scanning for comments and registrations.
 */
class Spamtroll_Scanner
{
    /**
     * Cached result from the last scan (used between preprocess_comment and pre_comment_approved).
     *
     * @var array{score:float, status:string, action:string}|null
     */
    private ?array $last_scan_result = null;

    /**
     * Initialize hooks.
     */
    public function init(): void
    {
        if (! Spamtroll_Settings::bool('enabled')) {
            return;
        }

        if (Spamtroll_Settings::bool('check_comments', true)) {
            add_filter('preprocess_comment', [ $this, 'check_comment' ]);
            add_filter('pre_comment_approved', [ $this, 'filter_comment_approved' ], 10, 2);
        }

        if (Spamtroll_Settings::bool('check_registrations', true)) {
            add_filter('registration_errors', [ $this, 'check_registration' ], 10, 3);
        }
    }

    /**
     * Check a comment for spam via the API.
     *
     * Hooked to `preprocess_comment`. Scans the content and stores the result
     * for use in `filter_comment_approved`.
     *
     * @param array<string, mixed> $commentdata Comment data.
     *
     * @return array<string, mixed> Unmodified comment data (fail-open).
     */
    public function check_comment(array $commentdata): array
    {
        $this->last_scan_result = null;

        if ($this->should_bypass()) {
            return $commentdata;
        }

        $content = isset($commentdata['comment_content']) && is_string($commentdata['comment_content'])
            ? $commentdata['comment_content']
            : '';
        if (empty(trim($content))) {
            return $commentdata;
        }

        try {
            $client = Spamtroll_Sdk_Factory::client();
            $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
            $email = isset($commentdata['comment_author_email']) && is_string($commentdata['comment_author_email'])
                ? $commentdata['comment_author_email']
                : '';
            $username = isset($commentdata['comment_author']) && is_string($commentdata['comment_author'])
                ? $commentdata['comment_author']
                : '';

            $response = $this->scan_with_cache($client, $content, \Spamtroll\Sdk\Request\CheckSpamRequest::SOURCE_COMMENT, $ip, $username, $email);

            // Quota exhausted — record locally for the admin dashboard,
            // then fail open. The SDK never throws on 402; we intentionally
            // do NOT block the comment because the user is on a free /
            // capped plan, not because the comment looks like spam.
            if ($this->is_quota_exceeded($response)) {
                self::record_skipped_quota($response);
                return $commentdata;
            }

            if (! $response->success) {
                error_log('Spamtroll: API returned error for comment scan: ' . ($response->error ?? '?'));
                return $commentdata;
            }

            $score = $response->getSpamScore();
            $status = $this->determine_status($score);
            $action = $this->determine_action($score);

            $this->last_scan_result = [
                'score' => $score,
                'status' => $status,
                'action' => $action,
            ];

            $user_id = get_current_user_id();

            Spamtroll_Logger::log([
                'user_id' => $user_id !== 0 ? $user_id : null,
                'content_type' => 'comment',
                'content_id' => null,
                'ip_address' => $ip,
                'status' => $status,
                'spam_score' => $score,
                'raw_score' => $response->getRawSpamScore(),
                'symbols' => $response->getSymbols(),
                'threat_categories' => $response->getThreatCategories(),
                'action_taken' => $action,
                'content_preview' => $content,
            ]);
        } catch (\Spamtroll\Sdk\Exception\SpamtrollException $e) {
            // Fail-open: allow the comment through on any API error.
            error_log('Spamtroll: API exception during comment scan: ' . $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Spamtroll: Unexpected error during comment scan: ' . $e->getMessage());
        }

        return $commentdata;
    }

    /**
     * Filter comment approval status based on scan result.
     *
     * Hooked to `pre_comment_approved`.
     *
     * @param int|string|WP_Error $approved Current approval status.
     * @param array<string, mixed> $commentdata Comment data.
     *
     * @return int|string|WP_Error Modified approval status.
     */
    public function filter_comment_approved($approved, array $commentdata)
    {
        unset($commentdata); // Unused — we store the verdict from check_comment.

        if (null === $this->last_scan_result) {
            return $approved;
        }

        $result = $this->last_scan_result;
        $this->last_scan_result = null;

        return match ($result['action']) {
            'block' => 'spam',
            'moderate' => 0,
            default => $approved,
        };
    }

    /**
     * Check registration for spam.
     *
     * Hooked to `registration_errors`.
     *
     * @param WP_Error $errors Registration errors.
     * @param string $sanitized_user_login Sanitized username.
     * @param string $user_email User email.
     *
     * @return WP_Error Modified errors object.
     */
    public function check_registration(WP_Error $errors, string $sanitized_user_login, string $user_email): WP_Error
    {
        if ($this->should_bypass()) {
            return $errors;
        }

        $content = $sanitized_user_login . ' ' . $user_email;

        try {
            $client = Spamtroll_Sdk_Factory::client();
            $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';

            $response = $this->scan_with_cache($client, $content, \Spamtroll\Sdk\Request\CheckSpamRequest::SOURCE_REGISTRATION, $ip, $sanitized_user_login, $user_email);

            if ($this->is_quota_exceeded($response)) {
                self::record_skipped_quota($response);
                return $errors;
            }

            if (! $response->success) {
                error_log('Spamtroll: API returned error for registration scan: ' . ($response->error ?? '?'));
                return $errors;
            }

            $score = $response->getSpamScore();
            $status = $this->determine_status($score);
            $action = $this->determine_action($score);

            Spamtroll_Logger::log([
                'user_id' => null,
                'content_type' => 'registration',
                'content_id' => null,
                'ip_address' => $ip,
                'status' => $status,
                'spam_score' => $score,
                'raw_score' => $response->getRawSpamScore(),
                'symbols' => $response->getSymbols(),
                'threat_categories' => $response->getThreatCategories(),
                'action_taken' => $action,
                'content_preview' => $content,
            ]);

            if ('block' === $action) {
                $errors->add('spamtroll_blocked', __('Registration blocked by spam filter. Please contact the site administrator if you believe this is an error.', 'spamtroll'));
            }
        } catch (\Spamtroll\Sdk\Exception\SpamtrollException $e) {
            // Fail-open: allow registration on any API error.
            error_log('Spamtroll: API exception during registration scan: ' . $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Spamtroll: Unexpected error during registration scan: ' . $e->getMessage());
        }

        return $errors;
    }

    /**
     * Call the API with a 1-hour transient cache keyed on the
     * content + source + email. Identical repeated submissions (e.g. a
     * bot hammering the same comment form with the same payload from
     * different IPs) reuse the first verdict instead of burning through
     * the user's API quota.
     *
     * Only caches successful API calls — errors fall straight through so
     * we don't lock in a bad verdict.
     */
    private function scan_with_cache(
        \Spamtroll\Sdk\Client $client,
        string $content,
        string $source,
        string $ip,
        string $username,
        string $email,
    ): \Spamtroll\Sdk\Response\CheckSpamResponse {
        $cache_key = 'spamtroll_scan_' . md5($source . '|' . $email . '|' . trim($content));
        $cached = get_transient($cache_key);
        if ($cached instanceof \Spamtroll\Sdk\Response\CheckSpamResponse && $cached->success) {
            return $cached;
        }
        $response = $client->checkSpam(new \Spamtroll\Sdk\Request\CheckSpamRequest(
            $content,
            $source,
            $ip !== '' ? $ip : null,
            $username !== '' ? $username : null,
            $email !== '' ? $email : null,
        ));
        if ($response->success) {
            set_transient($cache_key, $response, HOUR_IN_SECONDS);
        }
        return $response;
    }

    /**
     * True when the API returned 402 — the account ran out of daily
     * scans and is on a free / capped plan. Detected purely by status
     * code so this works with both the current SDK release (0.9.2)
     * and the unreleased one that adds isQuotaExceeded(). When the
     * SDK ships 0.9.3 the wasSkipped() method will be the cleaner
     * call; the httpCode check stays as a belt-and-braces fallback.
     */
    private function is_quota_exceeded(\Spamtroll\Sdk\Response\CheckSpamResponse $response): bool
    {
        if (method_exists($response, 'isQuotaExceeded')) {
            /** @phpstan-ignore-next-line older SDK installs don't have this method */
            if ($response->isQuotaExceeded() === true) {
                return true;
            }
        }
        return $response->httpCode === 402;
    }

    /**
     * Record a quota-exhausted scan in the rolling 30-day local log
     * so the plugin's admin dashboard can show "X messages were left
     * unscanned because you hit your daily limit — upgrade your plan".
     * Storage: a single wp_options entry keyed `spamtroll_quota_skipped_log`,
     * shape `[ "YYYY-MM-DD" => count, … ]` plus a `last_usage` block.
     * Pruned to 30 days on each write so the option never grows
     * unbounded.
     */
    public static function record_skipped_quota(\Spamtroll\Sdk\Response\CheckSpamResponse $response): void
    {
        $today = gmdate('Y-m-d');
        $stored = get_option('spamtroll_quota_skipped_log', []);
        if (! is_array($stored)) {
            $stored = [];
        }

        $byDay = isset($stored['days']) && is_array($stored['days']) ? $stored['days'] : [];
        $byDay[ $today ] = (isset($byDay[ $today ]) && is_int($byDay[ $today ])) ? $byDay[ $today ] + 1 : 1;

        // Prune to last 30 days.
        $cutoff = gmdate('Y-m-d', strtotime('-30 days'));
        foreach (array_keys($byDay) as $day) {
            if (! is_string($day) || $day < $cutoff) {
                unset($byDay[ $day ]);
            }
        }

        $usage = [];
        if (method_exists($response, 'getQuotaUsage')) {
            /** @phpstan-ignore-next-line older SDK installs don't have this method */
            $usage = $response->getQuotaUsage();
        }

        update_option('spamtroll_quota_skipped_log', [
            'days' => $byDay,
            'last_at' => time(),
            'last_usage' => is_array($usage) ? $usage : [],
        ], false);
    }

    /**
     * Stats for the admin dashboard panel. Returns the day-by-day
     * skipped count for the last $days days plus the most recent
     * usage block reported by the API. Always returns a populated
     * shape — empty arrays when nothing has been recorded yet.
     *
     * @return array{total: int, today: int, days: array<string,int>, last_usage: array<string,mixed>, last_at: int}
     */
    public static function get_skipped_quota_stats(int $days = 7): array
    {
        $stored = get_option('spamtroll_quota_skipped_log', []);
        if (! is_array($stored)) {
            $stored = [];
        }
        $byDay = isset($stored['days']) && is_array($stored['days']) ? $stored['days'] : [];

        $cutoff = gmdate('Y-m-d', strtotime('-' . max(1, $days) . ' days'));
        $window = [];
        $total = 0;
        foreach ($byDay as $day => $count) {
            if (! is_string($day) || ! is_int($count)) {
                continue;
            }
            if ($day < $cutoff) {
                continue;
            }
            $window[ $day ] = $count;
            $total += $count;
        }

        $today = gmdate('Y-m-d');
        $todayCount = isset($byDay[ $today ]) && is_int($byDay[ $today ]) ? $byDay[ $today ] : 0;

        return [
            'total' => $total,
            'today' => $todayCount,
            'days' => $window,
            'last_usage' => isset($stored['last_usage']) && is_array($stored['last_usage']) ? $stored['last_usage'] : [],
            'last_at' => isset($stored['last_at']) && is_int($stored['last_at']) ? $stored['last_at'] : 0,
        ];
    }

    /**
     * Check if the current user should bypass spam checking.
     *
     * @return bool True if the user should bypass.
     */
    private function should_bypass(): bool
    {
        if (! is_user_logged_in()) {
            return false;
        }

        $user = wp_get_current_user();
        $bypass = Spamtroll_Settings::stringList('bypass_roles');
        if ($bypass === []) {
            $bypass = [ 'administrator', 'editor' ];
        }

        $roles = is_array($user->roles) ? $user->roles : [];
        foreach ($bypass as $role) {
            if (in_array($role, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine action based on normalized spam score.
     *
     * @param float $score Normalized spam score (0-1).
     *
     * @return string Action to take (block, moderate, allow).
     */
    private function determine_action(float $score): string
    {
        $spam_threshold = Spamtroll_Settings::float('spam_threshold', 0.70);
        $suspicious_threshold = Spamtroll_Settings::float('suspicious_threshold', 0.40);

        if ($score >= $spam_threshold) {
            return Spamtroll_Settings::string('action_blocked', 'block');
        }

        if ($score >= $suspicious_threshold) {
            return Spamtroll_Settings::string('action_suspicious', 'moderate');
        }

        return 'allow';
    }

    /**
     * Determine status label based on normalized spam score.
     *
     * @param float $score Normalized spam score (0-1).
     *
     * @return string Status label (blocked, suspicious, safe).
     */
    private function determine_status(float $score): string
    {
        $spam_threshold = Spamtroll_Settings::float('spam_threshold', 0.70);
        $suspicious_threshold = Spamtroll_Settings::float('suspicious_threshold', 0.40);

        if ($score >= $spam_threshold) {
            return 'blocked';
        }

        if ($score >= $suspicious_threshold) {
            return 'suspicious';
        }

        return 'safe';
    }
}
