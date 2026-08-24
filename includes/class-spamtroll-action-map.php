<?php

declare(strict_types=1);
/**
 * Backend verdict to local action, as a table.
 *
 * @package Spamtroll
 *
 * @since   0.2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

use Spamtroll\Sdk\Response\CheckSpamResponse;

/**
 * The single place where the API's verdict becomes something WordPress does.
 *
 * There used to be no such place. `Spamtroll_Scanner` took `spam_score`,
 * divided it by the SDK's fixed denominator of 30, and compared the result
 * against two local thresholds — so the backend's own `status` field never
 * reached a decision at all. That is not a rounding difference. On the
 * default preset a comment the backend had classified `blocked` at raw 16
 * came out as 16/30 = 0.53, below the 0.70 block threshold, and was merely
 * held for moderation; on the lenient preset nothing below raw 25 was ever
 * blocked. It also silently disabled the per-platform `SpamThreshold`
 * override: a customer could lower the threshold in the Spamtroll dashboard
 * and watch WordPress ignore it, because WordPress was never reading the
 * verdict that the threshold produced.
 *
 * A table cannot have that shape. `status` selects a row; the preset selects
 * a column; the cell is the action. A status this version has never heard of
 * — whatever a future backend adds next to `blocked`/`suspicious`/`safe` —
 * has no row, and an unknown row cannot mean "block": an older site must not
 * start rejecting comments over a word it cannot read.
 */
final class Spamtroll_Action_Map
{
    public const PRESET_LENIENT = 'lenient';
    public const PRESET_BALANCED = 'balanced';
    public const PRESET_STRICT = 'strict';

    /**
     * Verdict → preset → action.
     *
     * The presets differ only in how much benefit of the doubt borderline
     * content gets, which is the only thing an operator can usefully tune
     * from here: the scoring itself belongs to the backend, where it can
     * see the whole platform rather than one site.
     *
     * @var array<string, array<string, string>>
     */
    private const TABLE = [
        CheckSpamResponse::STATUS_BLOCKED => [
            self::PRESET_LENIENT => Spamtroll_Verdict::ACTION_MODERATE,
            self::PRESET_BALANCED => Spamtroll_Verdict::ACTION_BLOCK,
            self::PRESET_STRICT => Spamtroll_Verdict::ACTION_BLOCK,
        ],
        CheckSpamResponse::STATUS_SUSPICIOUS => [
            self::PRESET_LENIENT => Spamtroll_Verdict::ACTION_ALLOW,
            self::PRESET_BALANCED => Spamtroll_Verdict::ACTION_MODERATE,
            self::PRESET_STRICT => Spamtroll_Verdict::ACTION_BLOCK,
        ],
        CheckSpamResponse::STATUS_SAFE => [
            self::PRESET_LENIENT => Spamtroll_Verdict::ACTION_ALLOW,
            self::PRESET_BALANCED => Spamtroll_Verdict::ACTION_ALLOW,
            self::PRESET_STRICT => Spamtroll_Verdict::ACTION_ALLOW,
        ],
    ];

    private string $preset;

    public function __construct(?string $preset = null)
    {
        $preset ??= Spamtroll_Settings::string('sensitivity', self::PRESET_BALANCED);
        $this->preset = in_array($preset, self::presets(), true) ? $preset : self::PRESET_BALANCED;
    }

    /**
     * The action configured for a backend verdict.
     *
     * @param string $status One of `blocked`, `suspicious`, `safe`.
     *
     * @return string One of the Spamtroll_Verdict::ACTION_* constants.
     */
    public function for_status(string $status): string
    {
        $row = self::TABLE[$status] ?? null;
        if (null === $row) {
            return Spamtroll_Verdict::ACTION_ALLOW;
        }

        /**
         * Last word on what a verdict does to this site.
         *
         * Fires only for a verdict the backend actually returned, so it can
         * escalate as well as relax. Returning something that is not an
         * ACTION_* constant leaves the table's own answer in place.
         *
         * @param string $action One of the Spamtroll_Verdict::ACTION_* constants.
         * @param string $status Backend verdict: blocked, suspicious or safe.
         * @param string $preset Configured sensitivity preset.
         */
        $action = apply_filters('spamtroll_action_for_status', $row[$this->preset], $status, $this->preset);

        return is_string($action) && in_array($action, Spamtroll_Verdict::actions(), true)
            ? $action
            : $row[$this->preset];
    }

    public function preset(): string
    {
        return $this->preset;
    }

    /**
     * @return list<string>
     */
    public static function presets(): array
    {
        return [ self::PRESET_LENIENT, self::PRESET_BALANCED, self::PRESET_STRICT ];
    }

    /**
     * What each preset does, for the settings screen.
     *
     * @return array<string, string>
     */
    public static function describe(): array
    {
        return [
            self::PRESET_LENIENT => __('Lenient — hold spam for moderation, publish borderline content', 'spamtroll'),
            self::PRESET_BALANCED => __('Balanced (recommended) — spam to the spam folder, borderline content to moderation', 'spamtroll'),
            self::PRESET_STRICT => __('Strict — spam and borderline content both to the spam folder', 'spamtroll'),
        ];
    }
}
