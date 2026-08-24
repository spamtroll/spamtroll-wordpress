<?php

declare(strict_types=1);
/**
 * Circuit breaker for the Spamtroll API.
 *
 * @package Spamtroll
 *
 * @since   0.2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Stops the site from paying for an outage it already knows about.
 *
 * Without this, every failure mode costs the full latency budget on every
 * single submission. The SDK retries connection failures and 5xx three times
 * with backoff, so an unreachable API used to hold `preprocess_comment` — and
 * with it a PHP-FPM worker, and with it the visitor staring at a frozen form
 * — for roughly 16.5 seconds per comment. On a blog with any traffic at all
 * that exhausts the worker pool, which turns an outage of ours into an outage
 * of theirs.
 *
 * The round trips buy nothing even when they succeed at failing: a revoked
 * key means every scan waits for a 401 it will get again, a 429 means the
 * site keeps feeding the very limiter that is rejecting it, and an exhausted
 * daily quota means hours of pointless requests. The scan fails open in all
 * three cases, so the only thing repeating it produces is delay.
 *
 * Stored in a non-autoloaded option rather than a transient on purpose: a
 * transient lives in the object cache on sites that have one, and a cache
 * flush in the middle of an incident would reopen the floodgates.
 */
final class Spamtroll_Circuit_Breaker
{
    public const OPTION_KEY = 'spamtroll_circuit';

    /**
     * Consecutive transport failures before the circuit opens.
     */
    public const FAILURE_THRESHOLD = 3;

    public const KIND_RATE_LIMITED = 'rate_limited';
    public const KIND_AUTH = 'auth';
    public const KIND_QUOTA = 'quota';
    public const KIND_TRANSPORT = 'transport';

    /**
     * How long each failure kind keeps the circuit open, in seconds.
     *
     * @var array<string, int>
     */
    private const DEFAULT_COOLDOWN = [
        self::KIND_RATE_LIMITED => 60,
        self::KIND_AUTH => 300,
        self::KIND_QUOTA => 900,
        self::KIND_TRANSPORT => 300,
    ];

    /**
     * @var callable():int|null
     */
    private $clock;

    /**
     * @param callable():int|null $clock Overridable so tests need not sleep.
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * True while the circuit is open and no request should be attempted.
     */
    public function is_open(): bool
    {
        return $this->open_until() > $this->now();
    }

    /**
     * Timestamp the circuit stays shut until, or 0 when it is closed.
     */
    public function open_until(): int
    {
        $stored = $this->read();
        $until = isset($stored['open_until']) && is_int($stored['open_until']) ? $stored['open_until'] : 0;
        return $until > $this->now() ? $until : 0;
    }

    /**
     * Why the circuit is open, or an empty string when it is closed.
     */
    public function reason(): string
    {
        if (! $this->is_open()) {
            return '';
        }
        $stored = $this->read();
        return isset($stored['kind']) && is_string($stored['kind']) ? $stored['kind'] : self::KIND_TRANSPORT;
    }

    /**
     * Records a successful call, closing the circuit and clearing the counter.
     */
    public function record_success(): void
    {
        if ([] === $this->read()) {
            return;
        }
        delete_option(self::OPTION_KEY);
    }

    /**
     * Records a failure and opens the circuit when the kind warrants it.
     *
     * Transport failures are counted rather than acted on immediately: one
     * timeout is ordinary internet weather, three in a row is an outage.
     * Everything else is the server making a statement about its own state,
     * and repeating the request cannot change the answer, so those open the
     * circuit on the first occurrence.
     *
     * @param string $kind One of the KIND_* constants.
     * @param int|null $seconds Cooldown the server asked for, if any.
     */
    public function record_failure(string $kind, ?int $seconds = null): void
    {
        $stored = $this->read();
        $now = $this->now();

        if (self::KIND_TRANSPORT === $kind) {
            $failures = (isset($stored['failures']) && is_int($stored['failures']) ? $stored['failures'] : 0) + 1;
            if ($failures < self::FAILURE_THRESHOLD) {
                $this->write([ 'failures' => $failures ]);
                return;
            }
            $this->write([
                'failures' => $failures,
                'kind' => $kind,
                'open_until' => $now + ($seconds ?? self::DEFAULT_COOLDOWN[ $kind ]),
            ]);
            return;
        }

        $cooldown = $seconds ?? (self::DEFAULT_COOLDOWN[ $kind ] ?? self::DEFAULT_COOLDOWN[ self::KIND_TRANSPORT ]);
        $this->write([
            'failures' => 0,
            'kind' => $kind,
            'open_until' => $now + max(Spamtroll_Retry_After::MIN_SECONDS, min(Spamtroll_Retry_After::MAX_SECONDS, $cooldown)),
        ]);
    }

    /**
     * Forgets everything — for uninstall, and for the "Test Connection"
     * button, where an operator getting an answer is the clearest possible
     * signal that the outage is over.
     */
    public function reset(): void
    {
        delete_option(self::OPTION_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function write(array $record): void
    {
        update_option(self::OPTION_KEY, $record, false);
    }

    private function now(): int
    {
        return null === $this->clock ? time() : (int) ($this->clock)();
    }
}
