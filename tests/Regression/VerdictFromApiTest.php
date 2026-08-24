<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\ApiFixtures;
use Spamtroll\Tests\Support\WpEnv;

/*
 * WORDPRESS.md §2.2 — the backend's `status` decides, not a local recompute.
 *
 * The plugin used to ignore `status` entirely, divide `spam_score` by the
 * SDK's fixed denominator of 30 and compare the quotient against two local
 * thresholds. The rows below are the measured consequences, straight out of
 * the audit's harness: on the default preset a `blocked` verdict at raw 16
 * became 0.53 and was merely held for moderation, `suspicious` at raw 8
 * became 0.27 and was published outright, and a `safe` verdict at raw 20 was
 * moderated anyway because 0.67 cleared the local suspicious threshold.
 *
 * The same defect made the per-platform SpamThreshold override inert: the
 * dashboard could lower the threshold all it liked, and WordPress would go on
 * comparing a number the threshold had no part in producing.
 */

$scan = function (string $status, float $raw, string $preset = 'balanced'): array {
    $env = WpEnv::boot([ 'sensitivity' => $preset ]);
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody($status, $raw, [ 'BAYES_99' ])), 3);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

    $commentdata = [
        'comment_content' => 'Verdict fixture ' . $status . ' ' . $raw . ' ' . $preset,
        'comment_author' => 'someone',
        'comment_author_email' => 'someone@example.com',
    ];

    $scanner = new Spamtroll_Scanner();
    $scanner->check_comment($commentdata);

    return [ 'approved' => $scanner->filter_comment_approved(1, $commentdata), 'env' => $env ];
};

it('marks a blocked verdict as spam even at raw 16, where the old maths said 0.53', function () use ($scan): void {
    expect($scan('blocked', 16.0)['approved'])->toBe('spam');
});

it('marks a blocked verdict as spam at raw 20.9, the top of the window the old code moderated', function () use ($scan): void {
    expect($scan('blocked', 20.9)['approved'])->toBe('spam');
});

it('holds a suspicious verdict for moderation at raw 8, where the old maths published it', function () use ($scan): void {
    expect($scan('suspicious', 8.0)['approved'])->toBe(0);
});

it('publishes a safe verdict even at raw 20, where the old maths moderated it', function () use ($scan): void {
    expect($scan('safe', 20.0)['approved'])->toBe(1);
});

it('blocks on the lenient preset only by moderating, and never by score', function () use ($scan): void {
    // Lenient means "hold spam for review", not "let raw 25 through" — which
    // is what the old thresholds did: nothing below 25.5 was ever blocked.
    expect($scan('blocked', 25.0, 'lenient')['approved'])->toBe(0);
    expect($scan('suspicious', 14.9, 'lenient')['approved'])->toBe(1);
});

it('treats borderline content as spam on the strict preset', function () use ($scan): void {
    expect($scan('suspicious', 5.0, 'strict')['approved'])->toBe('spam');
});

it('allows a status this version has never heard of', function () use ($scan): void {
    // A future backend adding a fourth verdict must not make an old site
    // start rejecting comments over a word it cannot read.
    expect($scan('quarantined', 99.0)['approved'])->toBe(1);
});

it('records the backend status in the log rather than a locally derived label', function () use ($scan): void {
    $rows = $scan('blocked', 16.0)['env']->loggedRows();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['status'])->toBe('blocked');
    expect($rows[0]['action_taken'])->toBe('block');
});
