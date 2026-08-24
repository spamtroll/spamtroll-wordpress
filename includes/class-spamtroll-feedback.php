<?php

declare(strict_types=1);
/**
 * Moderator feedback loop.
 *
 * @package Spamtroll
 *
 * @since   0.2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

use Spamtroll\Sdk\Exception\SpamtrollException;

/**
 * Sends the moderator's own verdict back to the API.
 *
 * A person clicking "Spam" or "Not spam" in the comments screen produces the
 * single most valuable signal this integration can generate: a labelled
 * example, from a real site, on content the classifier has already seen and
 * scored. The plugin used to throw all of it away — it never read
 * `submission_id` out of the scan response, never stored it, and never
 * called `/scan/feedback`. Every correction a moderator made was a lesson
 * the backend was not taught.
 *
 * Sending happens on a scheduled single event rather than inline, because
 * bulk-marking forty comments as spam must not turn into forty synchronous
 * HTTP calls inside one wp-admin request.
 */
final class Spamtroll_Feedback
{
    public const CRON_HOOK = 'spamtroll_send_feedback';

    public const LABEL_SPAM = 'spam';
    public const LABEL_HAM = 'ham';

    /**
     * Comment statuses that carry a moderator's judgement, and what it means.
     *
     * `trash` and `delete` are absent: a comment can be binned for being
     * off-topic, duplicated or simply unwanted, and none of those is a
     * statement about spam.
     *
     * @var array<string, string>
     */
    private const LABEL_FOR_STATUS = [
        'spam' => self::LABEL_SPAM,
        'approved' => self::LABEL_HAM,
    ];

    public function init(): void
    {
        add_action('comment_post', [ $this, 'remember_comment' ], 10, 1);
        add_action('transition_comment_status', [ $this, 'on_status_change' ], 10, 3);
        add_action(self::CRON_HOOK, [ $this, 'send' ], 10, 2);
    }

    /**
     * Ties the freshly inserted comment to the submission that was scored.
     *
     * @param int|string $comment_id Comment ID.
     */
    public function remember_comment($comment_id): void
    {
        $submission_id = Spamtroll_Scanner::last_submission_id();
        if (null === $submission_id) {
            return;
        }

        Spamtroll_Logger::attach_comment((int) $comment_id, $submission_id);
    }

    /**
     * Queues feedback when a moderator changes a comment's status.
     *
     * @param string $new_status New comment status.
     * @param string $old_status Previous comment status.
     * @param WP_Comment|null $comment The comment.
     */
    public function on_status_change($new_status, $old_status, $comment): void
    {
        if ($new_status === $old_status) {
            return;
        }

        if (! Spamtroll_Settings::bool('send_feedback', true)) {
            return;
        }

        $label = self::LABEL_FOR_STATUS[ (string) $new_status ] ?? null;
        if (null === $label) {
            return;
        }

        // A comment going straight from 0 to `approved` because the site
        // auto-approves known authors is WordPress talking, not a moderator.
        // Only a status a human could have set counts as a correction.
        if ('' === (string) $old_status) {
            return;
        }

        if (! $comment instanceof WP_Comment) {
            return;
        }

        $submission_id = Spamtroll_Logger::submission_id_for_comment((int) $comment->comment_ID);
        if (null === $submission_id) {
            return;
        }

        // Claim it here rather than in the cron job: the moderator can toggle
        // a comment several times in a row, and the backend allows only 100
        // feedbacks per platform per day.
        if (! Spamtroll_Logger::mark_feedback_sent($submission_id)) {
            return;
        }

        wp_schedule_single_event(time(), self::CRON_HOOK, [ $submission_id, $label ]);
    }

    /**
     * Posts one feedback item to the API. Runs from cron.
     *
     * Failures are never fatal and never surface to the moderator — feedback
     * is a gift to the classifier, not part of moderating a comment.
     *
     * @param string $submission_id Submission the API scored.
     * @param string $label `spam` or `ham`.
     */
    public function send($submission_id, $label): void
    {
        $submission_id = (string) $submission_id;
        $label = (string) $label;

        if ('' === $submission_id || ! in_array($label, [ self::LABEL_SPAM, self::LABEL_HAM ], true)) {
            return;
        }

        $api_key = Spamtroll_Settings::string('api_key');
        if ('' === $api_key) {
            return;
        }

        $http = new Spamtroll_Wp_Http_Client();

        try {
            $body = wp_json_encode([
                'submission_id' => $submission_id,
                'correct_label' => $label,
                'reviewer_notes' => 'WordPress moderator action',
            ]);

            $response = $http->send(
                'POST',
                Spamtroll_Settings::api_url() . '/scan/feedback',
                [
                    'X-API-Key' => $api_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'Spamtroll-WordPress/' . SPAMTROLL_VERSION,
                ],
                false === $body ? '{}' : $body,
                Spamtroll_Settings::timeout(),
            );
        } catch (SpamtrollException | \Throwable $e) {
            self::release($submission_id);
            self::debug('feedback transport failure: ' . $e->getMessage());
            return;
        }

        if ($response->statusCode >= 200 && $response->statusCode < 300) {
            return;
        }

        // 404 means the submission is unknown or belongs to another platform,
        // and 422 means the payload will never be accepted. Retrying either
        // spends the daily allowance on a request that cannot succeed.
        if (429 === $response->statusCode || $response->statusCode >= 500) {
            self::release($submission_id);
        }

        self::debug('feedback rejected with HTTP ' . $response->statusCode . ': ' . $response->body);
    }

    /**
     * Puts a claimed submission back so a later correction can retry it.
     *
     * Without this, a moderator working through a spam queue during an API
     * outage would burn one claim per comment and send nothing at all.
     */
    private static function release(string $submission_id): void
    {
        global $wpdb;

        $wpdb->update(
            Spamtroll_Logger::get_table_name(),
            [ 'feedback_sent' => 0 ],
            [ 'submission_id' => $submission_id ],
            [ '%d' ],
            [ '%s' ],
        );
    }

    private static function debug(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Spamtroll: ' . $message);
        }
    }
}
