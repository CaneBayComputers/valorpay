<?php

namespace CaneBayComputers\ValorPay\Webhooks;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Checks the Valor-Signature and Valor-Timestamp headers on a webhook request.
 *
 * ValorPay documents signing HMAC-SHA256(json_encode(payload) . timestamp, secret), but in practice
 * (checked 2026-09-29: the portal's test event carries no Valor-Signature) it sends no signature.
 * So check one only when it's present, and treat the webhook as a hint either way (WebhookHint).
 *
 * Its docs show PHP's json_encode but don't say which flags, so the raw body and the usual
 * compact encodings of the decoded payload are all accepted. Each is the same payload, so this
 * doesn't weaken the check.
 *
 * @see https://valorapi.readme.io/reference/webhook-user-guide
 */
class WebhookSignature
{
    public const SIGNATURE_HEADER = 'Valor-Signature';

    public const TIMESTAMP_HEADER = 'Valor-Timestamp';

    public function __construct(private string $secret, private int $toleranceSeconds = 300) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('valorpay.webhook_secret'),
            (int) config('valorpay.webhook_tolerance_seconds', 300),
        );
    }

    /**
     * Returns null when the request is authentic, or the reason it isn't.
     */
    public function problem(string $rawBody, ?string $signature, ?string $timestamp): ?string
    {
        if ($this->secret === '') {
            return 'VALORPAY_WEBHOOK_SECRET is not set';
        }

        if (blank($signature) || blank($timestamp)) {
            return 'missing signature headers';
        }

        try {
            $signedAt = CarbonImmutable::parse($timestamp);
        } catch (Throwable) {
            return 'unreadable timestamp';
        }

        if (abs(now()->diffInSeconds($signedAt)) > $this->toleranceSeconds) {
            return 'stale timestamp';
        }

        foreach ($this->signedForms($rawBody) as $body) {
            if (hash_equals(hash_hmac('sha256', $body.$timestamp, $this->secret), strtolower($signature))) {
                return null;
            }
        }

        return 'signature mismatch';
    }

    /**
     * Computes the signature ValorPay would send, for tests and manual replays.
     */
    public function sign(string $body, string $timestamp): string
    {
        return hash_hmac('sha256', $body.$timestamp, $this->secret);
    }

    /**
     * @return list<string>
     */
    private function signedForms(string $rawBody): array
    {
        $forms = [$rawBody];
        $decoded = json_decode($rawBody, true);

        if (is_array($decoded)) {
            foreach ([0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
                $forms[] = json_encode($decoded, $flags | JSON_PRESERVE_ZERO_FRACTION);
                $forms[] = json_encode($decoded, $flags);
            }
        }

        return array_values(array_unique(array_filter($forms, 'is_string')));
    }
}
