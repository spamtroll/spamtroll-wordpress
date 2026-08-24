<?php

declare(strict_types=1);
/**
 * Retry-After parsing.
 *
 * @package Spamtroll
 *
 * @since   0.2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Parses Retry-After in both forms RFC 7231 §7.1.3 allows.
 *
 * The header arrives as delta-seconds or as an HTTP-date depending on which
 * layer answered: the backend's rate limiter writes seconds derived from a
 * Redis TTL, while an intermediary — a CDN, a WAF, an nginx error page —
 * may well write a date. Handling only one of the two means falling back to
 * a default and carrying on hammering a limiter that has already said no.
 */
final class Spamtroll_Retry_After
{
    public const MIN_SECONDS = 1;
    public const MAX_SECONDS = 3600;

    /**
     * Seconds to wait, clamped to [1, 3600], or null when unusable.
     *
     * The clamp is deliberate: an hour is long enough that a misconfigured
     * proxy cannot switch spam scanning off for a day, and one second stops
     * a zero or negative value from producing an already-expired breaker.
     */
    public static function parse(?string $header, ?int $now = null): ?int
    {
        if (null === $header) {
            return null;
        }

        $value = trim($header);
        if ('' === $value) {
            return null;
        }

        // delta-seconds: a bare non-negative integer.
        if (1 === preg_match('/^\d+$/', $value)) {
            return self::clamp((int) $value);
        }

        // HTTP-date. strtotime() understands all three formats RFC 7231
        // lists, which is more than a hand-rolled format list would — but it
        // is also far too willing: it reads "-30" as a relative offset and
        // "1.5" as a clock time, so a malformed header would come back as a
        // confident, wrong delay. Every legal HTTP-date carries a day or
        // month name, so requiring a letter separates the two cases without
        // narrowing the accepted formats.
        if (1 !== preg_match('/[A-Za-z]/', $value)) {
            return null;
        }

        $timestamp = strtotime($value);
        if (false === $timestamp) {
            return null;
        }

        return self::clamp($timestamp - ($now ?? time()));
    }

    private static function clamp(int $seconds): int
    {
        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds));
    }
}
