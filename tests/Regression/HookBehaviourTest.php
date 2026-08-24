<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\ApiFixtures;
use Spamtroll\Tests\Support\WpEnv;

/*
 * The smaller defects that all live on the same two hooks.
 *
 * §3.2 Akismet collision, §3.1 the bypass list that could not be emptied,
 * §4.7 pingbacks scored as if a visitor had written them, and §3.7 the
 * `?paged=0` offset. None is dramatic on its own; each is a case where the
 * plugin quietly did the opposite of what it was told.
 */

$comment = static fn (string $body = 'hello there'): array => [
    'comment_content' => $body,
    'comment_author' => 'someone',
    'comment_author_email' => 'someone@example.com',
];

it('does not rescue a comment Akismet has already called spam', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('suspicious', 8.0)), 3);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.50';

    $scanner = new Spamtroll_Scanner();
    $scanner->check_comment($comment());

    // Akismet runs on the same filter and hands us 'spam'. Returning 0 for
    // our own "moderate" would promote that spam into the moderation queue,
    // where a human is invited to approve it.
    expect($scanner->filter_comment_approved('spam', $comment()))->toBe('spam');
});

it('still escalates a comment Akismet was willing to publish', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('blocked', 30.0)), 3);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.51';

    $scanner = new Spamtroll_Scanner();
    $scanner->check_comment($comment());

    expect($scanner->filter_comment_approved(1, $comment()))->toBe('spam');
});

it('scans everybody when the administrator has unticked every bypass role', function () use ($comment): void {
    $env = WpEnv::boot([ 'bypass_roles' => [] ]);
    $env->logged_in = true;
    $env->current_roles = [ 'administrator' ];
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 0.0)), 3);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.52';

    (new Spamtroll_Scanner())->check_comment($comment());

    // An empty stored list used to be indistinguishable from "never
    // configured", so the two defaults were silently put back.
    expect($env->requestCount())->toBe(1);
});

it('still bypasses the default roles on a fresh install', function () use ($comment): void {
    $env = WpEnv::boot();
    $env->logged_in = true;
    $env->current_roles = [ 'editor' ];
    $_SERVER['REMOTE_ADDR'] = '198.51.100.53';

    (new Spamtroll_Scanner())->check_comment($comment());

    expect($env->requestCount())->toBe(0);
});

it('does not spend a scan on a pingback', function () use ($comment): void {
    $env = WpEnv::boot();
    $_SERVER['REMOTE_ADDR'] = '198.51.100.54';

    $data = $comment('An excerpt from somebody else\'s page') + [ 'comment_type' => 'pingback' ];
    (new Spamtroll_Scanner())->check_comment($data);

    expect($env->requestCount())->toBe(0);
});

it('does not build a negative OFFSET from ?paged=0', function (): void {
    WpEnv::boot();

    Spamtroll_Logger::get_recent_logs([ 'page' => 0, 'per_page' => 20 ]);

    foreach ($GLOBALS['wpdb']->queries as $sql) {
        expect($sql)->not->toContain('OFFSET -');
    }
});
