<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\ApiFixtures;
use Spamtroll\Tests\Support\WpEnv;
use Spamtroll\Tests\Support\WpErrorStub;

/*
 * WORDPRESS.md §2.5 — 401 and 403 have to reach a human.
 *
 * A revoked key, a blocked account or a platform switched off in the
 * dashboard used to produce one line in error_log() and nothing else. The
 * settings screen still said "Enable Plugin ✓", the logs page filled up with
 * nothing, and every comment sailed through unscanned — for as long as nobody
 * read debug.log, which is to say indefinitely. That is the worst available
 * failure mode for an anti-spam plugin: not a false positive, but the quiet
 * absence of any protection at all.
 */

$scan = static function (WpEnv $env, string $body = 'hello'): void {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.30';
    (new Spamtroll_Scanner())->check_comment([
        'comment_content' => $body,
        'comment_author_email' => 'a@example.com',
    ]);
};

it('records a rejected API key where the admin screens can find it', function () use ($scan): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(401, ApiFixtures::envelope('UNAUTHORIZED', 'Invalid API key')), 3);

    $scan($env);

    expect($env->options)->toHaveKey(Spamtroll_Health::OPTION_KEY);
    expect(Spamtroll_Health::status()['state'])->toBe(Spamtroll_Health::STATE_AUTH);
    expect(Spamtroll_Health::describe()['severity'])->toBe('error');
});

it('distinguishes a blocked account from a bad key', function () use ($scan): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(403, ApiFixtures::envelope('FORBIDDEN', 'Platform is disabled')), 3);

    $scan($env);

    expect(Spamtroll_Health::status()['state'])->toBe(Spamtroll_Health::STATE_FORBIDDEN);
    expect(Spamtroll_Health::status()['http_code'])->toBe(403);
});

it('keeps the rate limiter message instead of the bare "1" the envelope casts to', function () use ($scan): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(429, ApiFixtures::limiterBody(), [ 'retry-after' => '120' ]), 3);

    $scan($env);

    // Shape C has `"error": true`, and the SDK stringifies that bool to "1",
    // so the actual message used to be discarded exactly when it mattered.
    expect(Spamtroll_Health::status()['message'])->toContain('Rate limit exceeded');
});

it('shows the site-wide notice for a fault that silently disables scanning', function () use ($scan): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(401, ApiFixtures::envelope('UNAUTHORIZED', 'Invalid API key')), 3);
    Brain\Monkey\Functions\when('current_user_can')->justReturn(true);
    Brain\Monkey\Functions\when('admin_url')->returnArg();

    $scan($env);

    ob_start();
    (new Spamtroll_Health())->render_notice();
    $html = (string) ob_get_clean();

    expect($html)->toContain('notice-error');
    expect($html)->toContain('API key was rejected');
});

it('clears the fault once a scan succeeds again', function () use ($scan): void {
    $env = WpEnv::boot();
    $env->willRespond(new WpErrorStub('cURL error 7: Connection refused'), 1);
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 0.0)), 3);

    $scan($env, 'first');
    expect(Spamtroll_Health::status()['state'])->toBe(Spamtroll_Health::STATE_TRANSPORT);

    $scan($env, 'second');
    expect(Spamtroll_Health::status()['state'])->toBe(Spamtroll_Health::STATE_OK);
    expect(Spamtroll_Health::describe())->toBeNull();
});
