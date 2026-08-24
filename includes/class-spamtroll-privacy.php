<?php

declare(strict_types=1);
/**
 * GDPR: export, erasure, and the privacy-policy suggestion.
 *
 * @package Spamtroll
 *
 * @since   0.2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Wires the scan log into WordPress's own privacy tooling.
 *
 * The log holds an IP address, an email address and up to 500 characters of
 * whatever the visitor typed — personal data by any reading of the GDPR, and
 * for a registration attempt the email address is the payload. None of it was
 * reachable from Tools → Export Personal Data or Erase Personal Data, so a
 * site owner honouring a subject-access request would hand over an export
 * that silently omitted this table, and an erasure that silently kept it.
 *
 * The plugin also sends that content, the IP and the email to a third-party
 * API. wordpress.org's guidelines require a plugin doing that to say so, and
 * `wp_add_privacy_policy_content()` is where a site's own policy picks it up.
 */
final class Spamtroll_Privacy
{
    public const EXPORTER_ID = 'spamtroll-scan-log';
    public const ERASER_ID = 'spamtroll-scan-log';
    public const GROUP_ID = 'spamtroll';

    /**
     * Rows handled per export or erasure batch.
     */
    public const BATCH_SIZE = 100;

    public function init(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ]);
        add_filter('wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ]);
        add_action('admin_init', [ $this, 'add_privacy_policy_content' ]);
    }

    /**
     * @param array<string, mixed> $exporters
     *
     * @return array<string, mixed>
     */
    public function register_exporter($exporters): array
    {
        if (! is_array($exporters)) {
            $exporters = [];
        }

        $exporters[ self::EXPORTER_ID ] = [
            'exporter_friendly_name' => __('Spamtroll spam scan log', 'spamtroll'),
            'callback' => [ $this, 'export' ],
        ];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     *
     * @return array<string, mixed>
     */
    public function register_eraser($erasers): array
    {
        if (! is_array($erasers)) {
            $erasers = [];
        }

        $erasers[ self::ERASER_ID ] = [
            'eraser_friendly_name' => __('Spamtroll spam scan log', 'spamtroll'),
            'callback' => [ $this, 'erase' ],
        ];

        return $erasers;
    }

    /**
     * Exports every scan recorded for one email address.
     *
     * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
     */
    public function export(string $email_address, int $page = 1): array
    {
        $rows = Spamtroll_Logger::find_by_email($email_address, self::BATCH_SIZE, max(1, $page));

        $data = [];
        foreach ($rows as $row) {
            $id = isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : '0';

            $data[] = [
                'group_id' => self::GROUP_ID,
                'group_label' => __('Spamtroll spam scan log', 'spamtroll'),
                'item_id' => 'spamtroll-log-' . $id,
                'data' => self::fields($row),
            ];
        }

        return [
            'data' => $data,
            'done' => count($rows) < self::BATCH_SIZE,
        ];
    }

    /**
     * Deletes every scan recorded for one email address.
     *
     * Deletion rather than anonymisation: a scan row that has lost its IP,
     * its email and its content preview retains nothing worth keeping, so
     * there is no case for holding on to the husk.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email_address, int $page = 1): array
    {
        unset($page); // Deletion is not paginated — one statement clears them all.

        $removed = Spamtroll_Logger::delete_by_email($email_address);

        return [
            'items_removed' => $removed > 0,
            'items_retained' => false,
            'messages' => [],
            'done' => true,
        ];
    }

    /**
     * Suggests wording for the site's privacy policy.
     */
    public function add_privacy_policy_content(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p class="privacy-policy-tutorial">'
            . esc_html__('Suggested text for sites using Spamtroll Anti-Spam.', 'spamtroll')
            . '</p><p>'
            . esc_html__('When you leave a comment or register an account, this site sends the text you submitted, your IP address, your email address and your chosen username to Spamtroll (spamtroll.io) so it can be checked for spam. Spamtroll is a third-party service; see https://spamtroll.io/privacy for how it handles that data.', 'spamtroll')
            . '</p><p>'
            . sprintf(
                /* translators: %d: number of days scan results are kept */
                esc_html__('The result of each check — the score, the reason codes, your IP address, your email address and a short excerpt of the submitted text — is stored on this site for %d days and then deleted automatically.', 'spamtroll'),
                Spamtroll_Settings::retention_days(),
            )
            . '</p>';

        wp_add_privacy_policy_content(__('Spamtroll Anti-Spam', 'spamtroll'), $content);
    }

    /**
     * One log row as export field pairs.
     *
     * @param array<string, mixed> $row
     *
     * @return list<array{name: string, value: string}>
     */
    private static function fields(array $row): array
    {
        $labels = [
            'created_at' => __('Checked at', 'spamtroll'),
            'content_type' => __('Submission type', 'spamtroll'),
            'ip_address' => __('IP address', 'spamtroll'),
            'email' => __('Email address', 'spamtroll'),
            'status' => __('Result', 'spamtroll'),
            'spam_score' => __('Score', 'spamtroll'),
            'action_taken' => __('Action taken', 'spamtroll'),
            'content_preview' => __('Submitted text (excerpt)', 'spamtroll'),
        ];

        $fields = [];
        foreach ($labels as $key => $label) {
            if (! isset($row[ $key ]) || ! is_scalar($row[ $key ])) {
                continue;
            }
            $value = (string) $row[ $key ];
            if ('' === $value) {
                continue;
            }
            $fields[] = [ 'name' => $label, 'value' => $value ];
        }

        return $fields;
    }
}
