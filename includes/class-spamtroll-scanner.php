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

use Spamtroll\Sdk\Exception\AuthenticationException;
use Spamtroll\Sdk\Exception\NotConfiguredException;
use Spamtroll\Sdk\Exception\SpamtrollException;
use Spamtroll\Sdk\Request\CheckSpamRequest;
use Spamtroll\Sdk\Response\CheckSpamResponse;

/**
 * Handles spam scanning for comments and registrations.
 *
 * Every public entry point here is a fail-open boundary: it is called while a
 * visitor waits on a submitted form, and it must return the caller's data
 * untouched whenever the API cannot produce a verdict. That guarantee is
 * carried by {@see Spamtroll_Verdict} rather than by the branches in this
 * class — see its docblock for why.
 */
class Spamtroll_Scanner
{
    /**
     * How long a verdict is reused for an identical submission.
     *
     * Short on purpose. The cache exists to stop a bot replaying the same
     * payload from burning the site's daily quota, not to remember verdicts:
     * the backend re-scores continuously, and a stale `safe` is a hole.
     */
    public const CACHE_TTL = 300;

    public const CACHE_PREFIX = 'spamtroll_scan_';

    /**
     * Verdict from the last scan, carried from preprocess_comment to
     * pre_comment_approved.
     */
    private ?Spamtroll_Verdict $last_verdict = null;

    /**
     * Submission the API assigned to the comment currently being saved.
     *
     * `preprocess_comment` knows the submission but not the comment ID;
     * `comment_post` knows the ID but has never seen the response. Static
     * because those two hooks are served by different objects, and the
     * window between them is a single request.
     */
    private static ?string $last_submission_id = null;

    private Spamtroll_Circuit_Breaker $breaker;

    public function __construct(?Spamtroll_Circuit_Breaker $breaker = null)
    {
        $this->breaker = $breaker ?? new Spamtroll_Circuit_Breaker();
    }

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
            // Priority 20, after Akismet's 10: see filter_comment_approved().
            add_filter('pre_comment_approved', [ $this, 'filter_comment_approved' ], 20, 2);
        }

        if (Spamtroll_Settings::bool('check_registrations', true)) {
            add_filter('registration_errors', [ $this, 'check_registration' ], 10, 3);
        }
    }

    /**
     * Check a comment for spam via the API.
     *
     * Hooked to `preprocess_comment`. Scans the content and stores the
     * verdict for use in `filter_comment_approved`.
     *
     * @param array<string, mixed> $commentdata Comment data.
     *
     * @return array<string, mixed> Unmodified comment data (fail-open).
     */
    public function check_comment(array $commentdata): array
    {
        $this->last_verdict = null;
        self::$last_submission_id = null;

        try {
            $type = self::string_field($commentdata, 'comment_type');

            // Pingbacks and trackbacks carry an excerpt of somebody else's
            // page, not something a visitor typed here. Scoring that as a
            // comment means judging a third-party site's prose, and a block
            // rejects a legitimate inbound link. WordPress has its own
            // moderation path for them.
            if ('pingback' === $type || 'trackback' === $type) {
                return $commentdata;
            }

            if ($this->should_bypass()) {
                return $commentdata;
            }

            $content = self::string_field($commentdata, 'comment_content');
            $email = self::string_field($commentdata, 'comment_author_email');
            $username = self::string_field($commentdata, 'comment_author');
            $ip = self::client_ip();

            $verdict = $this->scan($content, CheckSpamRequest::SOURCE_COMMENT, $ip, $username, $email);
            $this->last_verdict = $verdict;
            self::$last_submission_id = $verdict->submission_id;

            if (Spamtroll_Verdict::REASON_SCANNED === $verdict->reason) {
                $user_id = get_current_user_id();
                Spamtroll_Logger::log([
                    'user_id' => 0 !== $user_id ? $user_id : null,
                    'content_type' => 'comment',
                    'content_id' => null,
                    'submission_id' => $verdict->submission_id,
                    'ip_address' => $ip,
                    'email' => $email,
                    'status' => $verdict->status,
                    'spam_score' => $verdict->score,
                    'raw_score' => $verdict->raw_score,
                    'symbols' => $verdict->symbols,
                    'threat_categories' => $verdict->categories,
                    'action_taken' => $verdict->action,
                    'content_preview' => $content,
                ]);
            }
        } catch (\Throwable $e) {
            // Belt and braces. scan() already contains every throwable it can
            // reach, but this method also touches the logger and the option
            // store, and losing a visitor's comment to a database hiccup
            // would be a worse outcome than not scanning it.
            $this->last_verdict = null;
            self::debug('Unexpected error during comment scan: ' . $e->getMessage());
        }

        return $commentdata;
    }

    /**
     * Submission the API assigned to the comment being saved in this request.
     */
    public static function last_submission_id(): ?string
    {
        return self::$last_submission_id;
    }

    /**
     * Filter comment approval status based on the scan verdict.
     *
     * Hooked to `pre_comment_approved` at priority 20.
     *
     * @param int|string|WP_Error $approved Current approval status.
     * @param array<string, mixed> $commentdata Comment data.
     *
     * @return int|string|WP_Error Modified approval status.
     */
    public function filter_comment_approved($approved, array $commentdata)
    {
        unset($commentdata); // Unused — we store the verdict from check_comment.

        $verdict = $this->last_verdict;
        $this->last_verdict = null;

        if (null === $verdict) {
            return $approved;
        }

        // Never soften somebody else's verdict. Akismet runs on this same
        // filter and marks spam as 'spam'; returning 0 for our own
        // "moderate" would promote that spam into the moderation queue,
        // where a human is invited to approve it. Escalation is fine, and
        // a WP_Error means core has already refused the comment outright.
        if (is_wp_error($approved) || 'spam' === $approved || 'trash' === $approved) {
            return $approved;
        }

        if ($verdict->is_blocked()) {
            return 'spam';
        }

        if ($verdict->is_moderated()) {
            return 0;
        }

        return $approved;
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
        try {
            if ($this->should_bypass()) {
                return $errors;
            }

            $ip = self::client_ip();

            // `source=registration` makes the backend skip content analysis,
            // RETVec and Bayes entirely and score `username`/`email` through
            // registration_check instead. `content` still has to be non-empty
            // (an empty one is a 422), so the username goes in — the email
            // does not, because it would then be echoed back into the local
            // log's content preview for no gain.
            $verdict = $this->scan(
                $sanitized_user_login,
                CheckSpamRequest::SOURCE_REGISTRATION,
                $ip,
                $sanitized_user_login,
                $user_email,
            );

            if (Spamtroll_Verdict::REASON_SCANNED === $verdict->reason) {
                Spamtroll_Logger::log([
                    'user_id' => null,
                    'content_type' => 'registration',
                    'content_id' => null,
                    'submission_id' => $verdict->submission_id,
                    'ip_address' => $ip,
                    'email' => $user_email,
                    'status' => $verdict->status,
                    'spam_score' => $verdict->score,
                    'raw_score' => $verdict->raw_score,
                    'symbols' => $verdict->symbols,
                    'threat_categories' => $verdict->categories,
                    'action_taken' => $verdict->action,
                    'content_preview' => $sanitized_user_login,
                ]);
            }

            if ($verdict->is_blocked()) {
                $errors->add('spamtroll_blocked', __('Registration blocked by spam filter. Please contact the site administrator if you believe this is an error.', 'spamtroll'));
            }
        } catch (\Throwable $e) {
            self::debug('Unexpected error during registration scan: ' . $e->getMessage());
        }

        return $errors;
    }

    /**
     * Performs the scan and turns the API's verdict into a local action.
     *
     * This method never throws and never returns an action other than
     * `allow` unless the API answered with one. Both properties are enforced
     * by {@see Spamtroll_Verdict}, whose constructor is private.
     */
    private function scan(string $content, string $source, string $ip, string $username, string $email): Spamtroll_Verdict
    {
        $verdict = $this->decide($content, $source, $ip, $username, $email);

        /**
         * Every verdict this plugin reaches, however it reached it.
         *
         * The one extension point that matters: it is how a site logs to
         * somewhere other than the plugin's own table, feeds a dashboard, or
         * notices that scans have quietly stopped happening.
         *
         * @param Spamtroll_Verdict $verdict Action, backend status, score, symbols and reason.
         * @param string $source `comment` or `registration`.
         */
        do_action('spamtroll_scan_verdict', $verdict, $source);

        return $verdict;
    }

    /**
     * The scan itself. Split from scan() only so the action above fires on
     * every path out, including the early returns.
     */
    private function decide(string $content, string $source, string $ip, string $username, string $email): Spamtroll_Verdict
    {
        if ('' === trim($content)) {
            return Spamtroll_Verdict::allow(Spamtroll_Verdict::REASON_EMPTY_CONTENT);
        }

        if ('' === Spamtroll_Settings::string('api_key')) {
            return Spamtroll_Verdict::allow(Spamtroll_Verdict::REASON_NOT_CONFIGURED);
        }

        // A short circuit costs nothing and saves the visitor the whole
        // budget. While the key is revoked, the quota is spent or the
        // limiter is saying no, every request gets the same answer.
        if ($this->breaker->is_open()) {
            self::debug('circuit open (' . $this->breaker->reason() . ') — ' . $source . ' allowed through unscanned');
            return Spamtroll_Verdict::allow(Spamtroll_Verdict::REASON_BREAKER_OPEN);
        }

        $map = new Spamtroll_Action_Map();
        $cache_key = self::cache_key($source, $ip, $email, $content);

        $cached = self::read_cache($cache_key);
        if (null !== $cached) {
            return Spamtroll_Verdict::from_response($cached, $map);
        }

        $http = new Spamtroll_Wp_Http_Client();

        try {
            // The budget covers the whole scan, retries included — the SDK's
            // own timeout bounds one attempt only.
            $http->set_deadline(hrtime(true) + (Spamtroll_Settings::timeout() * 1_000_000_000));

            $response = Spamtroll_Sdk_Factory::client(null, $http)->checkSpam(new CheckSpamRequest(
                $content,
                $source,
                '' !== $ip ? $ip : null,
                '' !== $username ? $username : null,
                '' !== $email ? $email : null,
            ));
        } catch (AuthenticationException $e) {
            // 401. The SDK throws rather than returning, and no number of
            // retries will make a rejected key acceptable.
            return $this->fail(Spamtroll_Verdict::REASON_AUTH_ERROR, Spamtroll_Circuit_Breaker::KIND_AUTH, 401, $e->getMessage());
        } catch (NotConfiguredException) {
            return Spamtroll_Verdict::allow(Spamtroll_Verdict::REASON_NOT_CONFIGURED);
        } catch (SpamtrollException $e) {
            // Timeouts, DNS failures, connection refused, 5xx after retries.
            return $this->fail(Spamtroll_Verdict::REASON_TRANSPORT_ERROR, Spamtroll_Circuit_Breaker::KIND_TRANSPORT, 0, $e->getMessage());
        } catch (\Throwable $e) {
            // An \Error from a future SDK release must not escape into
            // wp_new_comment() and lose the submission.
            return $this->fail(Spamtroll_Verdict::REASON_TRANSPORT_ERROR, Spamtroll_Circuit_Breaker::KIND_TRANSPORT, 0, $e->getMessage());
        }

        $retry_after = Spamtroll_Retry_After::parse($http->get_last_headers()['retry-after'] ?? null);

        if (self::is_quota_exceeded($response)) {
            // Not spam and not an error: a billing condition. Record it for
            // the settings-screen panel and let the message through.
            self::record_skipped_quota($response);
            $this->breaker->record_failure(
                Spamtroll_Circuit_Breaker::KIND_QUOTA,
                $retry_after ?? self::seconds_until_quota_reset($response),
            );
            Spamtroll_Health::record(Spamtroll_Verdict::REASON_QUOTA, 402, self::error_text($response));
            return Spamtroll_Verdict::allow(Spamtroll_Verdict::REASON_QUOTA);
        }

        if (429 === $response->httpCode) {
            return $this->fail(Spamtroll_Verdict::REASON_RATE_LIMITED, Spamtroll_Circuit_Breaker::KIND_RATE_LIMITED, 429, self::error_text($response), $retry_after);
        }

        if (403 === $response->httpCode) {
            return $this->fail(Spamtroll_Verdict::REASON_FORBIDDEN, Spamtroll_Circuit_Breaker::KIND_AUTH, 403, self::error_text($response));
        }

        if (401 === $response->httpCode) {
            return $this->fail(Spamtroll_Verdict::REASON_AUTH_ERROR, Spamtroll_Circuit_Breaker::KIND_AUTH, 401, self::error_text($response));
        }

        if (! $response->success) {
            return $this->fail(Spamtroll_Verdict::REASON_API_ERROR, Spamtroll_Circuit_Breaker::KIND_TRANSPORT, $response->httpCode, self::error_text($response));
        }

        // A 200 is not by itself a verdict. A captive portal, a WAF or a
        // misrouted proxy will happily answer 200 with HTML, and the SDK
        // decodes that into an empty payload — which reads as `safe`, score
        // 0. Recording that as "scanned, clean" would be a lie, and caching
        // it would keep the lie for the whole TTL.
        if (! self::carries_verdict($response)) {
            $this->breaker->record_failure(Spamtroll_Circuit_Breaker::KIND_TRANSPORT);
            Spamtroll_Health::record(Spamtroll_Verdict::REASON_NO_VERDICT, $response->httpCode, 'response carried no status or spam_score');
            self::debug('API returned ' . $response->httpCode . ' with no verdict for ' . $source . ' scan');
            return Spamtroll_Verdict::allow(Spamtroll_Verdict::REASON_NO_VERDICT);
        }

        $this->breaker->record_success();
        Spamtroll_Health::record_success();
        self::write_cache($cache_key, $response);

        return Spamtroll_Verdict::from_response($response, $map);
    }

    /**
     * Records a failure everywhere it needs recording, then allows.
     */
    private function fail(string $reason, string $kind, int $code, string $message, ?int $retry_after = null): Spamtroll_Verdict
    {
        $this->breaker->record_failure($kind, $retry_after);
        Spamtroll_Health::record($reason, $code, $message);
        self::debug('scan failed (' . $reason . ', HTTP ' . $code . '): ' . $message);

        return Spamtroll_Verdict::allow($reason);
    }

    // -------------------------------------------------------------------------
    // Client IP
    // -------------------------------------------------------------------------

    /**
     * The visitor's IP address, or an empty string when there isn't a usable one.
     *
     * `REMOTE_ADDR` is the proxy's address behind Cloudflare, an nginx
     * reverse proxy, a load balancer or Varnish — so every scan on such a
     * site reports the same IP, and `ip_check`, `geo_check` and
     * `hosting_check` end up scoring the CDN instead of the spammer. If that
     * address ever lands on a reputation list, the site starts blocking its
     * own readers.
     *
     * Forwarded headers are only read when the operator has said the site
     * actually sits behind a trusted proxy, because anyone can send them.
     */
    public static function client_ip(): string
    {
        $ip = self::server_string('REMOTE_ADDR');

        if (Spamtroll_Settings::bool('trust_proxy')) {
            foreach ([ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ] as $header) {
                $candidate = self::server_string($header);
                if ('' === $candidate) {
                    continue;
                }
                // X-Forwarded-For is a chain; the client is the first entry.
                $first = trim(explode(',', $candidate)[0]);
                if (false !== filter_var($first, FILTER_VALIDATE_IP)) {
                    $ip = $first;
                    break;
                }
            }
        }

        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '';
        }

        /**
         * The address sent to the API and stored in the local log.
         *
         * The last word for sites whose proxy layer this plugin cannot guess.
         *
         * @param string $ip Address resolved so far, or an empty string.
         */
        $filtered = apply_filters('spamtroll_client_ip', $ip);

        if (! is_string($filtered) || false === filter_var($filtered, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return $filtered;
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    /**
     * Transient key for one submission.
     *
     * The IP belongs in the key. It used to be left out deliberately — the
     * comment described a bot replaying one payload from many addresses —
     * but the backend scores the address as heavily as the text, through
     * `ip_check`, `geo_check` and `hosting_check`. Keying without it makes
     * the cache wrong in both directions: a `safe` earned from a clean
     * address gets reused for the same text sent from a blacklisted one, and
     * a block earned by a bad address is applied to an innocent visitor who
     * happened to write "Thanks, great post!" — which is exactly the sort of
     * text that collides.
     */
    public static function cache_key(string $source, string $ip, string $email, string $content): string
    {
        return self::CACHE_PREFIX . md5($source . '|' . $ip . '|' . $email . '|' . trim($content));
    }

    /**
     * Reads a cached verdict, or null when there isn't a usable one.
     *
     * Stored as plain scalars rather than a serialized SDK object: an object
     * survives an SDK upgrade in the database but not necessarily in PHP, and
     * an unserialized instance of a class whose shape has changed throws on
     * first property access. Data has no such problem.
     */
    private static function read_cache(string $key): ?CheckSpamResponse
    {
        $cached = get_transient($key);
        if (! is_array($cached) || ! isset($cached['status'])) {
            return null;
        }

        return new CheckSpamResponse(true, 200, [ 'success' => true, 'data' => $cached ]);
    }

    private static function write_cache(string $key, CheckSpamResponse $response): void
    {
        set_transient($key, [
            'status' => $response->getStatus(),
            'spam_score' => $response->getRawSpamScore(),
            'symbols' => $response->getSymbols(),
            'threat_categories' => $response->getThreatCategories(),
            'submission_id' => $response->getSubmissionId(),
        ], self::CACHE_TTL);
    }

    // -------------------------------------------------------------------------
    // Response inspection
    // -------------------------------------------------------------------------

    /**
     * True when the body actually contains a scan result.
     */
    private static function carries_verdict(CheckSpamResponse $response): bool
    {
        $payload = isset($response->data['data']) && is_array($response->data['data'])
            ? $response->data['data']
            : $response->data;

        return isset($payload['status']) || isset($payload['spam_score']);
    }

    /**
     * True when the API returned 402 — the account ran out of daily scans.
     */
    private static function is_quota_exceeded(CheckSpamResponse $response): bool
    {
        return $response->isQuotaExceeded() || 402 === $response->httpCode;
    }

    /**
     * The most informative error string the response can offer.
     *
     * `$response->error` is the SDK's `extractError()`, which reads
     * `data.error` first. That is a string for the standard envelope but a
     * bare `true` for the HTTP limiter's shape and for Fiber's 404 handler,
     * and casting `true` to string yields `"1"` — so the actual message
     * ("Rate limit exceeded. Maximum 100 requests per minute.") used to be
     * thrown away exactly when it was most wanted.
     */
    private static function error_text(CheckSpamResponse $response): string
    {
        $error = $response->error;
        if (is_string($error) && '' !== $error && '1' !== $error) {
            return $error;
        }

        $envelope = $response->data['error'] ?? null;
        if (is_array($envelope) && isset($envelope['message']) && is_string($envelope['message'])) {
            return $envelope['message'];
        }

        $message = $response->getMessage();
        if (is_string($message) && '' !== $message) {
            return $message;
        }

        return is_string($error) ? $error : 'API error';
    }

    /**
     * Seconds until the quota window rolls over, from the 402 body.
     */
    private static function seconds_until_quota_reset(CheckSpamResponse $response): ?int
    {
        $usage = $response->getQuotaUsage();
        $reset = $usage['reset_at'] ?? null;
        if (! is_string($reset) || '' === $reset) {
            return null;
        }

        $timestamp = strtotime($reset);
        if (false === $timestamp) {
            return null;
        }

        return Spamtroll_Retry_After::parse((string) max(0, $timestamp - time()));
    }

    // -------------------------------------------------------------------------
    // Quota bookkeeping
    // -------------------------------------------------------------------------

    /**
     * Record a quota-exhausted scan in the rolling 30-day local log so the
     * settings screen can show "X messages were left unscanned because you
     * hit your daily limit — upgrade your plan".
     *
     * Storage: a single wp_options entry keyed `spamtroll_quota_skipped_log`,
     * shape `[ "YYYY-MM-DD" => count, … ]` plus a `last_usage` block. Pruned
     * to 30 days on each write so the option never grows unbounded.
     */
    public static function record_skipped_quota(CheckSpamResponse $response): void
    {
        $today = gmdate('Y-m-d');
        $stored = get_option('spamtroll_quota_skipped_log', []);
        if (! is_array($stored)) {
            $stored = [];
        }

        $byDay = isset($stored['days']) && is_array($stored['days']) ? $stored['days'] : [];
        $byDay[ $today ] = (isset($byDay[ $today ]) && is_int($byDay[ $today ])) ? $byDay[ $today ] + 1 : 1;

        // Prune to last 30 days.
        $cutoff = self::days_ago(30);
        foreach (array_keys($byDay) as $day) {
            if (! is_string($day) || $day < $cutoff) {
                unset($byDay[ $day ]);
            }
        }

        update_option('spamtroll_quota_skipped_log', [
            'days' => $byDay,
            'last_at' => time(),
            'last_usage' => $response->getQuotaUsage(),
        ], false);
    }

    /**
     * Stats for the settings-screen panel: the day-by-day skipped count for
     * the last $days days plus the most recent usage block reported by the
     * API. Always returns a populated shape — empty arrays when nothing has
     * been recorded yet.
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

        $cutoff = self::days_ago(max(1, $days));
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
     * `Y-m-d` for N days ago. `strtotime()` returns int|false and gmdate()
     * will not take a false, so the fallback keeps the pruning window from
     * collapsing to the epoch and deleting the whole log.
     */
    private static function days_ago(int $days): string
    {
        $timestamp = strtotime('-' . $days . ' days');
        return gmdate('Y-m-d', false === $timestamp ? time() - ($days * DAY_IN_SECONDS) : $timestamp);
    }

    // -------------------------------------------------------------------------
    // Bypass
    // -------------------------------------------------------------------------

    /**
     * Check if the current user should bypass spam checking.
     */
    private function should_bypass(): bool
    {
        if (! is_user_logged_in()) {
            return false;
        }

        $user = wp_get_current_user();
        $roles = is_array($user->roles) ? $user->roles : [];

        foreach (Spamtroll_Settings::bypass_roles() as $role) {
            if (in_array($role, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Small helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private static function string_field(array $data, string $key): string
    {
        return isset($data[ $key ]) && is_scalar($data[ $key ]) ? (string) $data[ $key ] : '';
    }

    private static function server_string(string $key): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        return isset($_SERVER[ $key ]) && is_scalar($_SERVER[ $key ])
            ? sanitize_text_field(wp_unslash((string) $_SERVER[ $key ]))
            : '';
    }

    /**
     * One line per failed scan is one line per comment during an outage, and
     * a busy blog fills debug.log with it. wordpress.org's guidelines want
     * this gated; so does anybody who has had to read such a file.
     */
    private static function debug(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Spamtroll: ' . $message);
        }
    }
}
