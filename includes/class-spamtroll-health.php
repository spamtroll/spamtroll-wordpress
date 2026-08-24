<?php

declare(strict_types=1);
/**
 * Last known state of the Spamtroll API, and how the admin hears about it.
 *
 * @package Spamtroll
 *
 * @since   0.2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Makes a silently broken anti-spam plugin impossible to miss.
 *
 * The worst failure mode for this plugin is not blocking a comment it should
 * have allowed — it is the one where nothing appears to be wrong. A revoked
 * key (401), a blocked account or a platform switched off in the dashboard
 * (403) used to produce one line in the PHP error log and nothing else: the
 * settings screen still said "Enable Plugin ✓", the logs page filled up with
 * nothing, and every comment sailed through unscanned. Sites ran that way for
 * as long as nobody read `debug.log`, which is to say indefinitely.
 *
 * So every unsuccessful call records what happened, and every successful one
 * clears it. The record is a single non-autoloaded option; the notice is
 * rendered on all admin screens, because someone who has stopped visiting the
 * Spamtroll settings page is exactly the person who needs to be told.
 */
final class Spamtroll_Health
{
    public const OPTION_KEY = 'spamtroll_api_health';

    public const STATE_OK = 'ok';
    public const STATE_AUTH = 'auth';
    public const STATE_FORBIDDEN = 'forbidden';
    public const STATE_RATE_LIMITED = 'rate_limited';
    public const STATE_QUOTA = 'quota';
    public const STATE_API_ERROR = 'api_error';
    public const STATE_TRANSPORT = 'transport';

    /**
     * Verdict reasons that describe the API, mapped to the state they record.
     *
     * Reasons that describe this site rather than the API — a bypassed user,
     * empty content, an open circuit — are absent on purpose: they are not
     * evidence about the API's health and must not overwrite it.
     *
     * @var array<string, string>
     */
    private const STATE_FOR_REASON = [
        Spamtroll_Verdict::REASON_SCANNED => self::STATE_OK,
        Spamtroll_Verdict::REASON_AUTH_ERROR => self::STATE_AUTH,
        Spamtroll_Verdict::REASON_FORBIDDEN => self::STATE_FORBIDDEN,
        Spamtroll_Verdict::REASON_RATE_LIMITED => self::STATE_RATE_LIMITED,
        Spamtroll_Verdict::REASON_QUOTA => self::STATE_QUOTA,
        Spamtroll_Verdict::REASON_API_ERROR => self::STATE_API_ERROR,
        Spamtroll_Verdict::REASON_NO_VERDICT => self::STATE_API_ERROR,
        Spamtroll_Verdict::REASON_TRANSPORT_ERROR => self::STATE_TRANSPORT,
    ];

    /**
     * States serious enough to interrupt an administrator anywhere in wp-admin.
     *
     * Quota has its own richer panel on the settings screen and a rate limit
     * clears itself within the minute, so neither earns a site-wide notice.
     *
     * @var list<string>
     */
    private const LOUD_STATES = [ self::STATE_AUTH, self::STATE_FORBIDDEN, self::STATE_TRANSPORT ];

    public function init(): void
    {
        add_action('admin_notices', [ $this, 'render_notice' ]);
    }

    /**
     * Records what the API just told us about itself.
     *
     * @param string $reason One of the Spamtroll_Verdict::REASON_* constants.
     * @param int $code HTTP status, or 0 when the request never got one.
     * @param string $message Message from the API or the transport, for the notice.
     */
    public static function record(string $reason, int $code = 0, string $message = ''): void
    {
        $state = self::STATE_FOR_REASON[ $reason ] ?? null;
        if (null === $state) {
            return;
        }

        if (self::STATE_OK === $state) {
            self::record_success();
            return;
        }

        $stored = self::read();
        update_option(self::OPTION_KEY, [
            'state' => $state,
            'http_code' => $code,
            'message' => mb_substr($message, 0, 300),
            'at' => time(),
            'last_ok' => isset($stored['last_ok']) && is_int($stored['last_ok']) ? $stored['last_ok'] : 0,
        ], false);
    }

    /**
     * Records a call the API answered normally, clearing any stored fault.
     */
    public static function record_success(): void
    {
        $stored = self::read();
        if (($stored['state'] ?? self::STATE_OK) === self::STATE_OK && isset($stored['last_ok'])) {
            return;
        }
        update_option(self::OPTION_KEY, [
            'state' => self::STATE_OK,
            'http_code' => 200,
            'message' => '',
            'at' => time(),
            'last_ok' => time(),
        ], false);
    }

    /**
     * @return array{state: string, http_code: int, message: string, at: int, last_ok: int}
     */
    public static function status(): array
    {
        $stored = self::read();

        return [
            'state' => isset($stored['state']) && is_string($stored['state']) ? $stored['state'] : self::STATE_OK,
            'http_code' => isset($stored['http_code']) && is_int($stored['http_code']) ? $stored['http_code'] : 0,
            'message' => isset($stored['message']) && is_string($stored['message']) ? $stored['message'] : '',
            'at' => isset($stored['at']) && is_int($stored['at']) ? $stored['at'] : 0,
            'last_ok' => isset($stored['last_ok']) && is_int($stored['last_ok']) ? $stored['last_ok'] : 0,
        ];
    }

    /**
     * Headline and body for the current fault, or null when there is none.
     *
     * Split out from rendering so the wording is testable without a DOM and
     * reusable on the settings screen.
     *
     * @return array{severity: string, title: string, body: string}|null
     */
    public static function describe(): ?array
    {
        $status = self::status();

        switch ($status['state']) {
            case self::STATE_AUTH:
                return [
                    'severity' => 'error',
                    'title' => __('Spamtroll is not scanning: the API key was rejected', 'spamtroll'),
                    'body' => __('The API returned 401 Unauthorized. Comments and registrations are being allowed through without any spam check. Paste a valid key on the Spamtroll settings screen and use Test Connection to confirm it.', 'spamtroll'),
                ];
            case self::STATE_FORBIDDEN:
                return [
                    'severity' => 'error',
                    'title' => __('Spamtroll is not scanning: access to this platform was refused', 'spamtroll'),
                    'body' => __('The API returned 403 Forbidden — the account is blocked, or this platform has been switched off in the Spamtroll dashboard. Comments and registrations are being allowed through without any spam check.', 'spamtroll'),
                ];
            case self::STATE_RATE_LIMITED:
                return [
                    'severity' => 'warning',
                    'title' => __('Spamtroll hit its rate limit', 'spamtroll'),
                    'body' => __('The API returned 429 Too Many Requests. Scans are paused briefly and content is being allowed through unchecked in the meantime.', 'spamtroll'),
                ];
            case self::STATE_QUOTA:
                return [
                    'severity' => 'warning',
                    'title' => __('Spamtroll has used up today\'s scan quota', 'spamtroll'),
                    'body' => __('Messages are being allowed through without a spam check until the quota resets.', 'spamtroll'),
                ];
            case self::STATE_TRANSPORT:
                return [
                    'severity' => 'warning',
                    'title' => __('Spamtroll cannot reach the API', 'spamtroll'),
                    'body' => __('The last scan failed to connect or timed out. Content is being allowed through unchecked until the connection recovers.', 'spamtroll'),
                ];
            case self::STATE_API_ERROR:
                return [
                    'severity' => 'warning',
                    'title' => __('Spamtroll got an unexpected answer from the API', 'spamtroll'),
                    'body' => __('Content is being allowed through unchecked. If this persists, check the Spamtroll status page or contact support.', 'spamtroll'),
                ];
        }

        return null;
    }

    /**
     * Renders the site-wide notice for the loud faults.
     */
    public function render_notice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $status = self::status();
        if (! in_array($status['state'], self::LOUD_STATES, true)) {
            return;
        }

        $described = self::describe();
        if (null === $described) {
            return;
        }

        printf(
            '<div class="notice notice-%1$s"><p><strong>%2$s</strong></p><p>%3$s</p><p>%4$s</p></div>',
            esc_attr($described['severity']),
            esc_html($described['title']),
            esc_html($described['body']),
            sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url(admin_url('admin.php?page=spamtroll')),
                esc_html__('Open Spamtroll settings', 'spamtroll'),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        return is_array($stored) ? $stored : [];
    }
}
