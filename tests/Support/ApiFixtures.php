<?php

declare(strict_types=1);

namespace Spamtroll\Tests\Support;

/**
 * The API's real response shapes, as data.
 *
 * Every body here is copied from the backend contract (API_CONTRACT.md §6),
 * which distinguishes four incompatible error envelopes:
 *
 *   A. {"success":false,"error":{"code":…,"message":…}}   — most errors
 *   B. 402 with an extra error.usage block                — quota exhausted
 *   C. {"error":true,"message":…}                         — HTTP rate limiter
 *   D. {"error":true,"message":…}                         — Fiber error handler
 *
 * The matrix below is the executable form of the fail-open table the audit
 * measured by hand (WORDPRESS.md §1). All fourteen rows have to end with the
 * visitor's content untouched; the four verdict rows on top of them are the
 * ones the plugin used to decide wrongly, by re-deriving a score instead of
 * reading the backend's own answer.
 */
final class ApiFixtures
{
    /**
     * A wp_remote_request() return value.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public static function wpResponse(int $status, string $body, array $headers = []): array
    {
        return [
            'response' => [ 'code' => $status, 'message' => '' ],
            'body' => $body,
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public static function json(int $status, array $payload, array $headers = []): array
    {
        return self::wpResponse($status, (string) json_encode($payload), $headers);
    }

    /**
     * A successful scan body carrying the given backend verdict.
     *
     * @param list<string> $symbols
     *
     * @return array<string, mixed>
     */
    public static function verdictBody(string $status, float $rawScore, array $symbols = [], ?string $submissionId = 'sub-1234'): array
    {
        $data = [
            'status' => $status,
            'spam_score' => $rawScore,
            'symbols' => $symbols,
            'threat_categories' => [],
        ];
        if (null !== $submissionId) {
            $data['submission_id'] = $submissionId;
        }

        return [ 'success' => true, 'data' => $data ];
    }

    /**
     * Shape A: the standard error envelope.
     *
     * @return array<string, mixed>
     */
    public static function envelope(string $code, string $message): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => 'req-' . strtolower($code),
            ],
        ];
    }

    /**
     * Shape B: the 402 body from API_CONTRACT.md §6B, verbatim.
     *
     * @return array<string, mixed>
     */
    public static function quotaBody(): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'QUOTA_EXCEEDED',
                'message' => 'Daily scan limit reached. Upgrade your plan at /dashboard/billing.',
                'usage' => [
                    'current' => 200,
                    'limit' => 200,
                    'plan' => 'free',
                    'reset_at' => '2099-01-01T00:00:00Z',
                ],
                'request_id' => 'req-quota-1',
            ],
        ];
    }

    /**
     * Shape C: the HTTP rate limiter's body — no `code`, no `success`.
     *
     * @return array<string, mixed>
     */
    public static function limiterBody(): array
    {
        return [ 'error' => true, 'message' => 'Rate limit exceeded. Maximum 100 requests per minute.' ];
    }

    /**
     * Shape D: Fiber's own error handler.
     *
     * @return array<string, mixed>
     */
    public static function fiberBody(): array
    {
        return [ 'error' => true, 'message' => 'Cannot POST /api/v1/scan/chek' ];
    }

    /**
     * Every row of the fail-open matrix from WORDPRESS.md §1.
     *
     * `outcome` is what wp_remote_request() returns; `reason` is the
     * Spamtroll_Verdict reason the scan should settle on; `maxCalls` is how
     * many HTTP attempts the row may cost — the number that used to be 3 for
     * anything retryable, and with it 16.5 seconds of a visitor's time.
     *
     * @return array<string, array{outcome: array<string, mixed>|WpErrorStub, reason: string, maxCalls: int}>
     */
    public static function matrix(): array
    {
        return [
            '402 QUOTA_EXCEEDED (shape B)' => [
                'outcome' => self::json(402, self::quotaBody()),
                'reason' => 'quota',
                'maxCalls' => 1,
            ],
            '429 HTTP limiter (shape C)' => [
                'outcome' => self::json(429, self::limiterBody(), [ 'retry-after' => '120' ]),
                'reason' => 'rate_limited',
                'maxCalls' => 1,
            ],
            '429 RATE_LIMITED (shape A)' => [
                'outcome' => self::json(429, self::envelope('RATE_LIMITED', 'Too many requests')),
                'reason' => 'rate_limited',
                'maxCalls' => 1,
            ],
            '403 FORBIDDEN' => [
                'outcome' => self::json(403, self::envelope('FORBIDDEN', 'Platform is disabled')),
                'reason' => 'forbidden',
                'maxCalls' => 1,
            ],
            '401 UNAUTHORIZED' => [
                'outcome' => self::json(401, self::envelope('UNAUTHORIZED', 'Invalid API key')),
                'reason' => 'auth_error',
                'maxCalls' => 1,
            ],
            '400 BAD_REQUEST' => [
                'outcome' => self::json(400, self::envelope('BAD_REQUEST', 'Invalid request body')),
                'reason' => 'api_error',
                'maxCalls' => 1,
            ],
            '422 VALIDATION_ERROR' => [
                'outcome' => self::json(422, self::envelope('VALIDATION_ERROR', 'Content is required')),
                'reason' => 'api_error',
                'maxCalls' => 1,
            ],
            '404 Fiber handler (shape D)' => [
                'outcome' => self::json(404, self::fiberBody()),
                'reason' => 'api_error',
                'maxCalls' => 1,
            ],
            '500 INTERNAL_ERROR' => [
                'outcome' => self::json(500, self::envelope('INTERNAL_ERROR', 'Scan failed')),
                'reason' => 'transport_error',
                'maxCalls' => 1,
            ],
            '502 HTML instead of JSON' => [
                'outcome' => self::wpResponse(502, '<html><head><title>502 Bad Gateway</title></head><body>nginx</body></html>'),
                'reason' => 'transport_error',
                'maxCalls' => 1,
            ],
            '200 empty body' => [
                'outcome' => self::wpResponse(200, ''),
                'reason' => 'no_verdict',
                'maxCalls' => 1,
            ],
            '200 HTML instead of JSON' => [
                'outcome' => self::wpResponse(200, '<html><body>captive portal</body></html>'),
                'reason' => 'no_verdict',
                'maxCalls' => 1,
            ],
            'timeout' => [
                'outcome' => new WpErrorStub('cURL error 28: Operation timed out after 3001 milliseconds'),
                'reason' => 'transport_error',
                'maxCalls' => 1,
            ],
            'connection refused' => [
                'outcome' => new WpErrorStub('cURL error 7: Failed to connect to api.spamtroll.io port 443: Connection refused'),
                'reason' => 'transport_error',
                'maxCalls' => 1,
            ],
        ];
    }
}
