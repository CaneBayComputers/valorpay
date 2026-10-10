<?php

namespace CaneBayComputers\ValorPay\Tests\Unit;

use CaneBayComputers\ValorPay\Tests\TestCase;
use CaneBayComputers\ValorPay\Webhooks\WebhookSignature;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    public function test_a_signed_body_passes(): void
    {
        $signature = new WebhookSignature(self::SECRET);
        $body = '{"event":"sale","data":{"invoice_no":"INV1","url":"https://x.example/a"}}';
        $timestamp = now()->toIso8601String();

        $this->assertNull($signature->problem($body, $signature->sign($body, $timestamp), $timestamp));
    }

    public function test_a_body_signed_with_escaped_slashes_passes(): void
    {
        $signature = new WebhookSignature(self::SECRET);
        $payload = ['event' => 'sale', 'data' => ['url' => 'https://x.example/a']];
        $timestamp = now()->toIso8601String();

        $this->assertNull($signature->problem(json_encode($payload, JSON_UNESCAPED_SLASHES), $signature->sign(json_encode($payload), $timestamp), $timestamp));
    }

    public function test_problems(): void
    {
        $signature = new WebhookSignature(self::SECRET, 300);
        $body = '{"event":"sale"}';
        $now = now()->toIso8601String();
        $old = now()->subMinutes(10)->toIso8601String();

        $this->assertSame('VALORPAY_WEBHOOK_SECRET is not set', (new WebhookSignature(''))->problem($body, 'x', $now));
        $this->assertSame('missing signature headers', $signature->problem($body, null, $now));
        $this->assertSame('unreadable timestamp', $signature->problem($body, 'x', 'not a time'));
        $this->assertSame('stale timestamp', $signature->problem($body, $signature->sign($body, $old), $old));
        $this->assertSame('signature mismatch', $signature->problem($body, (new WebhookSignature('other'))->sign($body, $now), $now));
    }

    public function test_it_reads_the_secret_from_config(): void
    {
        config(['valorpay.webhook_secret' => self::SECRET]);
        $body = '{"event":"sale"}';
        $now = now()->toIso8601String();

        $this->assertNull(WebhookSignature::fromConfig()->problem($body, (new WebhookSignature(self::SECRET))->sign($body, $now), $now));
    }
}
