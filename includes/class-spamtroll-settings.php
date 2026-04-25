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
}
