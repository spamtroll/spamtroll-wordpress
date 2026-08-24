<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\ApiFixtures;
use Spamtroll\Tests\Support\WpEnv;
use Spamtroll\Tests\Support\WpErrorStub;

/*
 * WORDPRESS.md §2.4 — a dead API must not hold the comment form hostage.
 *
 * The SDK retries connection failures and every 5xx three times, at five
 * seconds each, with 0.5s and 1s of backoff in between: 16.5 seconds of
 * `preprocess_comment` with a PHP-FPM worker pinned and a visitor watching a
 * frozen form. The factory constructed ClientConfig with nothing but a user
 * agent, so all three defaults stood.
 *
 * Attempts are the honest unit here. Asserting on elapsed seconds would only
 * measure the test's own stubs; asserting that one unreachable API costs one
 * request measures the thing that made it sixteen seconds.
 */

$comment = static fn (string $body): array => [
    'comment_content' => $body,
    'comment_author' => 'someone',
    'comment_author_email' => 'someone@example.com',
];

it('spends one HTTP attempt on a timeout, not three', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->willRespond(new WpErrorStub('cURL error 28: Operation timed out after 3001 milliseconds'), 5);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';

    (new Spamtroll_Scanner())->check_comment($comment('timeout please'));

    expect($env->requestCount())->toBe(1);
});

it('spends one HTTP attempt on a 500, not three', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(500, ApiFixtures::envelope('INTERNAL_ERROR', 'Scan failed')), 5);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.11';

    (new Spamtroll_Scanner())->check_comment($comment('server error please'));

    expect($env->requestCount())->toBe(1);
});

it('asks the SDK for a budget rather than the default five seconds and three tries', function (): void {
    WpEnv::boot([ 'timeout' => 2 ]);

    $config = Spamtroll_Sdk_Factory::client()->getConfig();

    expect($config->maxRetries)->toBe(1);
    expect($config->timeout)->toBe(2);
});

it('refuses to open a socket once the budget is already spent', function (): void {
    WpEnv::boot();

    $http = new Spamtroll_Wp_Http_Client();
    $http->set_deadline(hrtime(true) - 1_000_000);

    expect(static fn () => $http->send('POST', 'https://api.spamtroll.io/api/v1/scan/check', [], '{}', 5))
        ->toThrow(\Spamtroll\Sdk\Exception\ConnectionException::class);
});

it('stops calling a dead API after three consecutive failures', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->willRespond(new WpErrorStub('cURL error 7: Connection refused'), 20);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.12';

    $scanner = new Spamtroll_Scanner();
    foreach ([ 'one', 'two', 'three' ] as $body) {
        $scanner->check_comment($comment($body));
    }
    expect($env->requestCount())->toBe(3);

    // Fourth submission: the circuit is open, so the visitor pays nothing.
    $scanner->check_comment($comment('four'));
    expect($env->requestCount())->toBe(3);
});

it('opens the circuit on the first 401, because a rejected key stays rejected', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(401, ApiFixtures::envelope('UNAUTHORIZED', 'Invalid API key')), 5);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.13';

    $scanner = new Spamtroll_Scanner();
    $scanner->check_comment($comment('first'));
    $scanner->check_comment($comment('second'));

    expect($env->requestCount())->toBe(1);
});

it('closes the circuit again as soon as a scan succeeds', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->willRespond(new WpErrorStub('cURL error 7: Connection refused'), 2);
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 0.0)), 1);
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 0.0)), 5);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.14';

    $scanner = new Spamtroll_Scanner();
    foreach ([ 'a', 'b', 'c', 'd' ] as $body) {
        $scanner->check_comment($comment($body));
    }

    expect($env->requestCount())->toBe(4);
    expect($env->options)->not->toHaveKey('spamtroll_circuit');
});
