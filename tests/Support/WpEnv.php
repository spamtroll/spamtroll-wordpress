<?php

declare(strict_types=1);

namespace Spamtroll\Tests\Support;

use Brain\Monkey\Functions;

/**
 * Just enough WordPress for the scanner to run.
 *
 * Every stub here is a function the plugin genuinely calls, wired to an
 * in-memory store so a test can assert on what was written. Nothing is
 * mocked that the plugin's own code owns: the SDK, the transport adapter,
 * the scanner and the verdict are all the real thing, and the seam is
 * `wp_remote_request()` — the last line before the socket.
 *
 * Putting the seam there is what lets dev/prove-regression.sh point the same
 * tests at the pre-fix classes: both revisions reach WordPress through the
 * same function, so a test that passes against one and fails against the
 * other is measuring the fix rather than the harness.
 */
final class WpEnv
{
    /** @var array<string, mixed> */
    public array $options = [];

    /** @var array<string, mixed> */
    public array $transients = [];

    /** @var list<array{url: string, args: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $inserts = [];

    /** @var list<string> */
    public array $scheduled = [];

    /** @var list<array{hook: string, args: list<mixed>}> */
    public array $actions = [];

    /** @var list<mixed> */
    private array $responses = [];

    public bool $logged_in = false;

    /** @var list<string> */
    public array $current_roles = [];

    /**
     * Boots the stubs and returns the state they read and write.
     *
     * @param array<string, mixed> $settings Plugin settings option value.
     */
    public static function boot(array $settings = []): self
    {
        $env = new self();

        $env->options[ \Spamtroll_Settings::OPTION_KEY ] = $settings + [
            'enabled' => 1,
            'api_key' => 'test-key',
            'sensitivity' => 'balanced',
        ];

        Functions\when('get_option')->alias(
            static fn (string $key, $default = false) => $env->options[ $key ] ?? $default,
        );
        Functions\when('update_option')->alias(
            static function (string $key, $value, $autoload = null) use ($env): bool {
                unset($autoload);
                $env->options[ $key ] = $value;
                return true;
            },
        );
        Functions\when('delete_option')->alias(
            static function (string $key) use ($env): bool {
                unset($env->options[ $key ]);
                return true;
            },
        );

        Functions\when('get_transient')->alias(
            static fn (string $key) => $env->transients[ $key ] ?? false,
        );
        Functions\when('set_transient')->alias(
            static function (string $key, $value, $ttl = 0) use ($env): bool {
                unset($ttl);
                $env->transients[ $key ] = $value;
                return true;
            },
        );
        Functions\when('delete_transient')->alias(
            static function (string $key) use ($env): bool {
                unset($env->transients[ $key ]);
                return true;
            },
        );

        Functions\when('wp_remote_request')->alias(
            static function (string $url, array $args) use ($env) {
                $env->requests[] = [ 'url' => $url, 'args' => $args ];
                $next = array_shift($env->responses);
                return $next ?? ApiFixtures::wpResponse(200, (string) json_encode(ApiFixtures::verdictBody('safe', 0.0)));
            },
        );
        Functions\when('is_wp_error')->alias(
            static fn ($thing): bool => $thing instanceof WpErrorStub,
        );
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn ($response) => is_array($response) ? ($response['response']['code'] ?? 0) : 0,
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn ($response) => is_array($response) ? ($response['body'] ?? '') : '',
        );
        Functions\when('wp_remote_retrieve_headers')->alias(
            static fn ($response) => is_array($response) ? ($response['headers'] ?? []) : [],
        );

        Functions\when('sanitize_text_field')->alias(
            static fn ($value): string => is_scalar($value) ? trim(strip_tags((string) $value)) : '',
        );
        Functions\when('wp_unslash')->returnArg();
        Functions\when('absint')->alias(static fn ($v): int => abs((int) $v));
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr__')->returnArg();
        Functions\when('__')->returnArg();
        Functions\when('_e')->returnArg();
        Functions\when('esc_html_e')->returnArg();
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('current_time')->justReturn('2026-08-24 12:00:00');
        Functions\when('human_time_diff')->justReturn('5 mins');
        Functions\when('wp_parse_args')->alias(
            static fn ($args, $defaults = []) => array_merge((array) $defaults, (array) $args),
        );

        Functions\when('is_user_logged_in')->alias(static fn (): bool => $env->logged_in);
        Functions\when('wp_get_current_user')->alias(
            static function () use ($env) {
                $user = new \stdClass();
                $user->roles = $env->current_roles;
                return $user;
            },
        );
        Functions\when('get_current_user_id')->justReturn(0);

        Functions\when('apply_filters')->alias(
            static function (string $hook, $value = null) {
                unset($hook);
                return $value;
            },
        );
        Functions\when('do_action')->alias(
            static function (string $hook, ...$args) use ($env): void {
                $env->actions[] = [ 'hook' => $hook, 'args' => $args ];
            },
        );
        Functions\when('add_filter')->justReturn(true);
        Functions\when('add_action')->justReturn(true);

        Functions\when('wp_schedule_single_event')->alias(
            static function ($timestamp, string $hook, array $args = []) use ($env): bool {
                unset($timestamp);
                $env->scheduled[] = $hook . ':' . implode(',', array_map('strval', $args));
                return true;
            },
        );

        $GLOBALS['wpdb'] = new FakeWpdb($env);

        return $env;
    }

    /**
     * Queues what the next call to wp_remote_request() will return.
     *
     * @param array<string, mixed>|WpErrorStub $response
     */
    public function willRespond($response, int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->responses[] = $response;
        }
        return $this;
    }

    /**
     * The last verdict the scanner announced through `spamtroll_scan_verdict`.
     */
    public function lastVerdict(): ?\Spamtroll_Verdict
    {
        for ($i = count($this->actions) - 1; $i >= 0; $i--) {
            if ('spamtroll_scan_verdict' === $this->actions[ $i ]['hook']) {
                $verdict = $this->actions[ $i ]['args'][0] ?? null;
                return $verdict instanceof \Spamtroll_Verdict ? $verdict : null;
            }
        }
        return null;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * The scan rows the logger tried to insert.
     *
     * @return list<array<string, mixed>>
     */
    public function loggedRows(): array
    {
        return $this->inserts;
    }
}
