<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\ApiFixtures;
use Spamtroll\Tests\Support\WpEnv;

/*
 * WORDPRESS.md §2.3 — the IP belongs in the cache key.
 *
 * Leaving it out was deliberate and documented: the comment above the method
 * described a bot replaying one payload from many addresses. But the backend
 * scores the address as heavily as the text — ip_check, geo_check and
 * hosting_check are three of its thirteen stages — so a key without it makes
 * the cache wrong in both directions. A `safe` earned from a clean address
 * gets reused for the same text sent from a blacklisted one, and a block
 * earned by a bad address is applied for the next hour to anyone who happens
 * to write "Thanks, great post!" — exactly the text that collides.
 */

$comment = static fn (): array => [
    'comment_content' => 'Thanks, great post!',
    'comment_author' => 'reader',
    'comment_author_email' => 'reader@example.com',
];

it('scans again when the same text arrives from a different address', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('blocked', 30.0)), 1);
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 0.0)), 3);

    $scanner = new Spamtroll_Scanner();

    $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
    $scanner->check_comment($comment());
    expect($scanner->filter_comment_approved(1, $comment()))->toBe('spam');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.21';
    $scanner->check_comment($comment());

    expect($env->requestCount())->toBe(2);
    expect($scanner->filter_comment_approved(1, $comment()))->toBe(1);
});

it('still reuses a verdict for an identical replay from the same address', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 0.0)), 5);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.22';

    $scanner = new Spamtroll_Scanner();
    $scanner->check_comment($comment());
    $scanner->check_comment($comment());

    // The quota protection the cache exists for is intact.
    expect($env->requestCount())->toBe(1);
});

it('caches plain data, so an SDK upgrade cannot poison the cache', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 0.0)), 2);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.23';

    (new Spamtroll_Scanner())->check_comment($comment());

    expect($env->transients)->toHaveCount(1);
    foreach ($env->transients as $value) {
        // A serialized SDK object survives an upgrade in the database but not
        // in PHP: reading a typed property that the new class no longer
        // initialises throws, and every cached scan breaks until it expires.
        expect($value)->toBeArray();
        expect($value)->toHaveKey('status');
    }
});

it('does not cache a 200 that carries no verdict', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::wpResponse(200, '<html><body>captive portal</body></html>'), 3);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.24';

    (new Spamtroll_Scanner())->check_comment([
        'comment_content' => 'anything',
        'comment_author_email' => 'a@example.com',
    ]);

    // A WAF or captive portal answering 200 with HTML used to be recorded as
    // "scanned, clean" and kept for an hour.
    expect($env->transients)->toBe([]);
    expect($env->loggedRows())->toBe([]);
});
