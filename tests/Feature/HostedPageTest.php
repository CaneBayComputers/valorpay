<?php

namespace CaneBayComputers\ValorPay\Tests\Feature;

use CaneBayComputers\ValorPay\Facades\ValorPay;
use CaneBayComputers\ValorPay\HostedPage;
use CaneBayComputers\ValorPay\Tests\TestCase;
use CaneBayComputers\ValorPay\ValorPayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HostedPageTest extends TestCase
{
    public function test_it_creates_a_hosted_page(): void
    {
        $this->travelTo('2026-10-09 12:00:00');
        Http::fake(['valorpay.test:4430/*' => Http::response(['error_no' => 'S00', 'url' => 'https://pay.valorpay.test/abc', 'uid' => 'UID123'])]);

        $page = ValorPay::createHostedPage(
            cents: 2500,
            invoiceNumber: 'INV1001',
            description: 'Web hosting, October',
            successUrl: 'https://shop.example/paid',
            failureUrl: 'https://shop.example/failed',
            customerName: "O'Brien & Sons, LLC",
        );

        $this->assertEquals(new HostedPage('https://pay.valorpay.test/abc', 'UID123'), $page);

        $request = $this->onlyRequest();
        $this->assertSame('POST', $request->method());
        $this->assertSame('https://valorpay.test:4430/?hostedpage', $request->url());
        $this->assertSame([
            'txn_type' => 'sale',
            'amount' => 25,
            'customer_name' => 'O Brien Sons LLC',
            'invoicenumber' => 'INV1001',
            'orderdescription' => 'Web hosting, October',
            'success_url' => 'https://shop.example/paid',
            'failure_url' => 'https://shop.example/failed',
            'epage' => 1,
            'shipping_country' => 'US',
            'notification_status' => '0',
            'active_on' => '2026-10-10',
        ], $this->bodyWithoutCredentials($request));
        $this->assertArrayNotHasKey('email', $request->data());
    }

    public function test_cents_become_dollars_and_valid_days_and_extra_fields_apply(): void
    {
        $this->travelTo('2026-10-09 12:00:00');
        Http::fake(['*' => Http::response(['error_no' => 'S00', 'url' => 'https://pay.valorpay.test/abc', 'uid' => 'UID123'])]);

        ValorPay::createHostedPage(1999, 'INV1', 'Thing', 'https://a.example/ok', 'https://a.example/no', validDays: 7, extra: ['shipping_country' => 'CA', 'phone' => '5555550100']);

        $body = $this->onlyRequest()->data();
        $this->assertSame(19.99, $body['amount']);
        $this->assertSame('2026-10-16', $body['active_on']);
        $this->assertSame('CA', $body['shipping_country']);
        $this->assertSame('5555550100', $body['phone']);
        $this->assertSame('Customer', $body['customer_name']);
    }

    public function test_a_refusal_throws_with_valorpays_code(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'D07', 'mesg' => 'PROCESSING ERROR', 'desc' => 'NOT A VALID APP KEY'], 400)]);

        try {
            ValorPay::createHostedPage(2500, 'INV1', 'Thing', 'https://a.example/ok', 'https://a.example/no');
            $this->fail('No exception');
        } catch (ValorPayException $exception) {
            $this->assertSame('D07', $exception->errorNo);
            $this->assertStringContainsString('NOT A VALID APP KEY', $exception->getMessage());
        }
    }

    public function test_the_private_ip_firewall_403_throws(): void
    {
        Http::fake(['*' => Http::response('Forbidden', 403)]);

        $this->expectException(ValorPayException::class);
        $this->expectExceptionMessage('HTTP 403');

        ValorPay::createHostedPage(2500, 'INV1', 'Thing', 'http://10.0.0.5/ok', 'http://10.0.0.5/no');
    }

    public function test_a_success_code_without_a_url_throws(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'S00'])]);

        $this->expectException(ValorPayException::class);

        ValorPay::createHostedPage(2500, 'INV1', 'Thing', 'https://a.example/ok', 'https://a.example/no');
    }

    public function test_an_unreachable_valorpay_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(ValorPayException::class);
        $this->expectExceptionMessage('ValorPay could not be reached: timed out');

        ValorPay::createHostedPage(2500, 'INV1', 'Thing', 'https://a.example/ok', 'https://a.example/no');
    }

    public function test_missing_credentials_throw_before_anything_is_sent(): void
    {
        config(['valorpay.app_key' => null]);
        $this->app->forgetInstance(\CaneBayComputers\ValorPay\ValorPayClient::class);
        ValorPay::clearResolvedInstances();
        Http::fake();

        try {
            ValorPay::createHostedPage(2500, 'INV1', 'Thing', 'https://a.example/ok', 'https://a.example/no');
            $this->fail('No exception');
        } catch (ValorPayException $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_cancelling_a_page(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'S00'])]);

        $this->assertTrue(ValorPay::cancelHostedPage('UID123'));

        $request = $this->onlyRequest();
        $this->assertSame('https://valorpay.test:4430/?hosted_page_cancel', $request->url());
        $this->assertSame(['txn_type' => 'cancel_einvoice', 'uid' => 'UID123'], $this->bodyWithoutCredentials($request));
    }

    public function test_an_already_cancelled_or_expired_page_counts_as_cancelled(): void
    {
        Http::fakeSequence()
            ->push(['error_no' => 'A38', 'desc' => 'ALREADY CANCELLED'], 400)
            ->push(['error_no' => 'A39', 'desc' => 'ALREADY EXPIRED'], 400)
            ->push(['error_no' => 'D07', 'desc' => 'NOT A VALID APP KEY'], 400);

        $this->assertTrue(ValorPay::cancelHostedPage('UID1'));
        $this->assertTrue(ValorPay::cancelHostedPage('UID2'));
        $this->assertFalse(ValorPay::cancelHostedPage('UID3'));
    }

    public function test_cancelling_never_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->assertFalse(ValorPay::cancelHostedPage('UID123'));
    }
}
