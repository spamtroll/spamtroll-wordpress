<?php

declare(strict_types=1);
/**
 * The outcome of one scan, and the plugin's fail-open guarantee.
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
 * Fail-open expressed as a property of a type rather than a rule to remember.
 *
 * The constructor is private and there is exactly one way to produce an
 * action other than `allow`: `from_response()`, which needs a
 * `CheckSpamResponse` the API answered successfully. Every other path — a
 * timeout, an open circuit, a revoked key, an exhausted quota, a captive
 * portal returning HTML, an unreadable body — reaches `allow()`, because
 * there is nothing else in this class for it to reach.
 *
 * That matters because of where the verdict lands. `preprocess_comment`
 * runs while a visitor waits on a submitted form, and `registration_errors`
 * decides whether a person gets an account at all. "Block" is not a mild
 * outcome there. The failure mode this type rules out is the one where an
 * outage on our side turns into a rejection on theirs.
 */
final class Spamtroll_Verdict
{
    public const ACTION_ALLOW = 'allow';
    public const ACTION_MODERATE = 'moderate';
    public const ACTION_BLOCK = 'block';

    public const STATUS_SAFE = CheckSpamResponse::STATUS_SAFE;
    public const STATUS_SUSPICIOUS = CheckSpamResponse::STATUS_SUSPICIOUS;
    public const STATUS_BLOCKED = CheckSpamResponse::STATUS_BLOCKED;

    /*
     * Why the verdict is what it is. Telemetry and admin notices read this;
     * control flow does not.
     */
    public const REASON_SCANNED = 'scanned';
    public const REASON_BYPASSED = 'bypassed';
    public const REASON_EMPTY_CONTENT = 'empty_content';
    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_BREAKER_OPEN = 'breaker_open';
    public const REASON_QUOTA = 'quota';
    public const REASON_RATE_LIMITED = 'rate_limited';
    public const REASON_AUTH_ERROR = 'auth_error';
    public const REASON_FORBIDDEN = 'forbidden';
    public const REASON_API_ERROR = 'api_error';
    public const REASON_TRANSPORT_ERROR = 'transport_error';
    public const REASON_NO_VERDICT = 'no_verdict';

    /**
     * Reachable only through allow() and from_response().
     *
     * @param string $action One of the ACTION_* constants.
     * @param string $status Verdict the API returned, or `safe` when there was none.
     * @param float $raw_score Raw additive score on the backend's open-ended scale.
     * @param float $score Same score normalized to 0-1, for display only.
     * @param list<string> $symbols Symbols the API reported.
     * @param list<string> $categories Threat categories the API reported.
     * @param string $reason One of the REASON_* constants.
     * @param string|null $submission_id Identifier the API assigned, when it assigned one.
     */
    private function __construct(
        public readonly string $action,
        public readonly string $status,
        public readonly float $raw_score,
        public readonly float $score,
        public readonly array $symbols,
        public readonly array $categories,
        public readonly string $reason,
        public readonly ?string $submission_id,
    ) {
    }

    /**
     * The only constructor that can produce an action other than `allow`.
     */
    public static function from_response(CheckSpamResponse $response, Spamtroll_Action_Map $map): self
    {
        $status = $response->getStatus();

        return new self(
            $map->for_status($status),
            $status,
            $response->getRawSpamScore(),
            $response->getSpamScore(),
            $response->getSymbols(),
            $response->getThreatCategories(),
            self::REASON_SCANNED,
            $response->getSubmissionId(),
        );
    }

    /**
     * Every path other than a successful scan ends here.
     *
     * @param string $reason One of the REASON_* constants.
     */
    public static function allow(string $reason): self
    {
        return new self(self::ACTION_ALLOW, self::STATUS_SAFE, 0.0, 0.0, [], [], $reason, null);
    }

    public function is_blocked(): bool
    {
        return self::ACTION_BLOCK === $this->action;
    }

    public function is_moderated(): bool
    {
        return self::ACTION_MODERATE === $this->action;
    }

    public function is_allowed(): bool
    {
        return self::ACTION_ALLOW === $this->action;
    }

    /**
     * True when no scan actually produced this verdict.
     */
    public function was_skipped(): bool
    {
        return self::REASON_SCANNED !== $this->reason;
    }

    /**
     * @return list<string>
     */
    public static function actions(): array
    {
        return [ self::ACTION_ALLOW, self::ACTION_MODERATE, self::ACTION_BLOCK ];
    }
}
