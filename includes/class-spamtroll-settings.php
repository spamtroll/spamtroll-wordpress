<?php

declare(strict_types=1);

/**
 * Typed wrapper around the plugin settings option.
 *
 * `get_option()` returns `mixed` — every call site that reads
 * spamtroll settings used to need its own `is_array()` narrowing
 * dance. This helper does it once and exposes typed accessors so
 * the rest of the plugin reads like normal code.
 *
 * @package Spamtroll
 */

if (! defined('ABSPATH')) {
    exit;
}

class Spamtroll_Settings
{
    public const OPTION_KEY = 'spamtroll_settings';

    /**
     * Seconds allowed for one whole scan, retries included.
     *
     * Three, not the SDK's five: this runs while a visitor waits on a
     * submitted comment form, and the scan fails open, so the choice is
     * between a fast maybe and a slow maybe.
     */
    public const DEFAULT_TIMEOUT = 3;

    public const MIN_TIMEOUT = 1;
    public const MAX_TIMEOUT = 30;

    public const DEFAULT_RETENTION_DAYS = 30;
    public const MIN_RETENTION_DAYS = 1;
    public const MAX_RETENTION_DAYS = 365;

    /**
     * Roles that skip scanning when the setting has never been saved.
     *
     * @var list<string>
     */
    public const DEFAULT_BYPASS_ROLES = [ 'administrator', 'editor' ];

    /**
     * Return the full settings array, narrowed from `get_option()`'s
     * `mixed` return type.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $value = get_option(self::OPTION_KEY, []);
        return is_array($value) ? $value : [];
    }

    public static function string(string $key, string $default = ''): string
    {
        $settings = self::all();
        if (isset($settings[$key]) && is_scalar($settings[$key])) {
            return (string) $settings[$key];
        }
        return $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $settings = self::all();
        if (isset($settings[$key]) && is_numeric($settings[$key])) {
            return (int) $settings[$key];
        }
        return $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $settings = self::all();
        if (isset($settings[$key]) && is_numeric($settings[$key])) {
            return (float) $settings[$key];
        }
        return $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $settings = self::all();
        if (! isset($settings[$key])) {
            return $default;
        }
        $value = $settings[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        return $default;
    }

    /**
     * @return list<string>
     */
    public static function stringList(string $key): array
    {
        $settings = self::all();
        if (! isset($settings[$key]) || ! is_array($settings[$key])) {
            return [];
        }
        $out = [];
        foreach ($settings[$key] as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }
        return $out;
    }

    /**
     * Roles whose submissions skip the scan.
     *
     * `stringList()` cannot answer this, because it returns `[]` both for
     * "never configured" and for "configured to bypass nobody" — and the
     * caller used to substitute the defaults for both. An administrator who
     * deliberately unticked every role got the two defaults back silently,
     * which is the opposite of what they asked for. The key's presence is
     * what separates the two cases.
     *
     * @return list<string>
     */
    public static function bypass_roles(): array
    {
        $settings = self::all();
        if (! array_key_exists('bypass_roles', $settings)) {
            return self::DEFAULT_BYPASS_ROLES;
        }
        return self::stringList('bypass_roles');
    }

    /**
     * Seconds allowed for one whole scan, clamped to something sane.
     */
    public static function timeout(): int
    {
        return max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, self::int('timeout', self::DEFAULT_TIMEOUT)));
    }

    /**
     * Days of scan history to keep.
     */
    public static function retention_days(): int
    {
        return max(
            self::MIN_RETENTION_DAYS,
            min(self::MAX_RETENTION_DAYS, self::int('log_retention_days', self::DEFAULT_RETENTION_DAYS)),
        );
    }

    /**
     * API base URL. Falls back to the SDK's default for anything that is not
     * a usable http(s) URL, so a half-typed value cannot silently switch the
     * plugin off.
     */
    public static function api_url(): string
    {
        $url = trim(self::string('api_url', \Spamtroll\Sdk\ClientConfig::DEFAULT_BASE_URL));
        if ('' === $url || ! preg_match('#^https?://#i', $url)) {
            return \Spamtroll\Sdk\ClientConfig::DEFAULT_BASE_URL;
        }
        return rtrim($url, '/');
    }
}
