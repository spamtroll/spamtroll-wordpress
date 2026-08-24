<?php

declare(strict_types=1);

use Spamtroll\Tests\Support\ApiFixtures;
use Spamtroll\Tests\Support\WpEnv;

/*
 * WORDPRESS.md §2.10 — the moderator's verdict is the training signal.
 *
 * A person clicking "Spam" or "Not spam" produces a labelled example, from a
 * real site, on content the classifier has already scored. The plugin read
 * none of it: `submission_id` never left the response object, the logs table
 * had no column for it, and /scan/feedback was never called. Every correction
 * a moderator made was thrown away.
 */

it('stores the submission id the API assigned', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 1.0, [], 'sub-abc-123')), 3);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.40';

    (new Spamtroll_Scanner())->check_comment([
        'comment_content' => 'a normal comment',
        'comment_author_email' => 'reader@example.com',
    ]);

    $rows = $env->loggedRows();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['submission_id'])->toBe('sub-abc-123');
});

it('ties the stored submission to the comment WordPress just created', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, ApiFixtures::verdictBody('safe', 1.0, [], 'sub-abc-123')), 3);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.41';

    (new Spamtroll_Scanner())->check_comment([
        'comment_content' => 'a normal comment',
        'comment_author_email' => 'reader@example.com',
    ]);
    (new Spamtroll_Feedback())->remember_comment(4242);

    $update = $GLOBALS['wpdb']->updates[0] ?? null;
    expect($update)->not->toBeNull();
    expect($update['data']['content_id'])->toBe(4242);
    expect($update['where']['submission_id'])->toBe('sub-abc-123');
});

it('queues feedback when a moderator marks a comment as spam', function (): void {
    $env = WpEnv::boot();
    $GLOBALS['wpdb']->var = 'sub-abc-123';

    $comment = new WP_Comment();
    $comment->comment_ID = '99';

    (new Spamtroll_Feedback())->on_status_change('spam', 'approved', $comment);

    expect($env->scheduled)->toBe([ 'spamtroll_send_feedback:sub-abc-123,spam' ]);
});

it('queues the opposite label when a moderator restores a comment', function (): void {
    $env = WpEnv::boot();
    $GLOBALS['wpdb']->var = 'sub-abc-123';

    $comment = new WP_Comment();
    $comment->comment_ID = '99';

    (new Spamtroll_Feedback())->on_status_change('approved', 'spam', $comment);

    expect($env->scheduled)->toBe([ 'spamtroll_send_feedback:sub-abc-123,ham' ]);
});

it('sends nothing twice, because the backend allows a hundred a day', function (): void {
    $env = WpEnv::boot();
    $GLOBALS['wpdb']->var = 'sub-abc-123';
    $GLOBALS['wpdb']->update_result = 0; // Already claimed.

    $comment = new WP_Comment();
    $comment->comment_ID = '99';

    (new Spamtroll_Feedback())->on_status_change('spam', 'approved', $comment);

    expect($env->scheduled)->toBe([]);
});

it('ignores a comment that was never scanned', function (): void {
    $env = WpEnv::boot();
    $GLOBALS['wpdb']->var = null;

    $comment = new WP_Comment();
    $comment->comment_ID = '99';

    (new Spamtroll_Feedback())->on_status_change('spam', 'approved', $comment);

    expect($env->scheduled)->toBe([]);
});

it('posts the moderator verdict to /scan/feedback', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(200, [ 'success' => true, 'data' => [ 'message' => 'Feedback processed successfully' ] ]), 2);

    (new Spamtroll_Feedback())->send('sub-abc-123', 'spam');

    expect($env->requestCount())->toBe(1);
    expect($env->requests[0]['url'])->toEndWith('/scan/feedback');

    $body = json_decode((string) $env->requests[0]['args']['body'], true);
    expect($body['submission_id'])->toBe('sub-abc-123');
    expect($body['correct_label'])->toBe('spam');
});

it('releases the claim when the API is down, so a later correction can retry', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(500, ApiFixtures::envelope('INTERNAL_ERROR', 'Failed to process feedback')), 2);

    (new Spamtroll_Feedback())->send('sub-abc-123', 'spam');

    $update = end($GLOBALS['wpdb']->updates);
    expect($update['data']['feedback_sent'])->toBe(0);
});

it('keeps the claim on a 404, because that submission will never be accepted', function (): void {
    $env = WpEnv::boot();
    $env->willRespond(ApiFixtures::json(404, ApiFixtures::envelope('NOT_FOUND', 'submission not found or not owned by this platform')), 2);

    (new Spamtroll_Feedback())->send('sub-abc-123', 'spam');

    expect($GLOBALS['wpdb']->updates)->toBe([]);
});
