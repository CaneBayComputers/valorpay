<?php

namespace CaneBayComputers\ValorPay\Tests\Feature;

use CaneBayComputers\ValorPay\Facades\ValorPay;
use CaneBayComputers\ValorPay\Subscriptions\Frequency;
use CaneBayComputers\ValorPay\Subscriptions\SubscriptionRequest;
use CaneBayComputers\ValorPay\Tests\TestCase;
use CaneBayComputers\ValorPay\ValorPayException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class SubscriptionsTest extends TestCase
{
    private const TOKEN = '5F8E3B0A6C1D4E7F9A2B5C8D1E4F7A0B3C6D9E2F';

    public function test_it_creates_a_subscription_on_a_card_token(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'S00', 'error_code' => '00', 'subscription_id' => 54670, 'msg' => 'SUBSRIPTION_CREATED'])]);

        $id = ValorPay::createSubscription(new SubscriptionRequest(
            cents: 2500,
            cardToken: self::TOKEN,
            customerName: 'Acme Test Co.',
            zip: '29486-1234',
            startsOn: CarbonImmutable::parse('2026-11-15'),
            invoiceNumber: 'HOST1001',
            description: 'Web hosting',
            email: 'billing@example.com',
        ));

        $this->assertSame('54670', $id);

        $request = $this->onlyRequest();
        $this->assertSame('POST', $request->method());
        $this->assertSame('https://valorpay.test:4430/?addSubs', $request->url());
        $this->assertSame([
            'txn_type' => 'add_subscription',
            'amount' => '25.00',
            'surchargeAmount' => '0',
            'surchargeIndicator' => '0',
            'tax' => '0.00',
            'payment_info' => ['token' => self::TOKEN],
            'recurring_type' => '2',
            'charge_on' => '15',
            'subscription_starts_from' => '2026-11-15',
            'charge_until' => 'never_expired',
            'is_validate_card' => '1',
            'invoice_no' => 'HOST1001',
            'product_description' => 'Web hosting',
            'billing_customer_name' => 'Acme Test Co',
            'billing_zip' => '29486',
            'shipping_customer_name' => 'Acme Test Co',
            'shipping_zip' => '29486',
            'failure_notification' => '0',
            'email' => 'billing@example.com',
        ], $this->bodyWithoutCredentials($request));
    }

    public function test_a_refused_subscription_throws_with_valorpays_code(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'D27', 'mesg' => 'PROCESSING ERROR', 'desc' => 'INVALID EPI ID'], 400)]);

        try {
            ValorPay::createSubscription($this->request());
            $this->fail('No exception');
        } catch (ValorPayException $exception) {
            $this->assertSame('D27', $exception->errorNo);
        }
    }

    public function test_a_success_without_an_id_throws(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'S00'])]);

        $this->expectException(ValorPayException::class);

        ValorPay::createSubscription($this->request());
    }

    public function test_it_updates_a_subscription(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'S00', 'error_code' => '00', 'msg' => 'SUBSRIPTION_EDITED'])]);

        ValorPay::updateSubscription(54670, $this->request(cents: 3000));

        $request = $this->onlyRequest();
        $this->assertSame('https://valorpay.test:4430/?updateSubs', $request->url());
        $body = $this->bodyWithoutCredentials($request);
        $this->assertSame('updateSubscription', $body['txn_type']);
        $this->assertSame('54670', $body['subscription_id']);
        $this->assertSame('30.00', $body['amount']);
        $this->assertSame(['token' => self::TOKEN], $body['payment_info']);
    }

    public function test_a_refused_update_throws(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'E31', 'desc' => 'NO SUBSCRIPTION AVAILABLE'], 400)]);

        $this->expectException(ValorPayException::class);

        ValorPay::updateSubscription(54670, $this->request());
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function actions(): array
    {
        return [
            'deactivate' => ['deactivateSubscription', '?de-Activate', 'deactivateSubscription', 'E59'],
            'activate' => ['activateSubscription', '?activateSub', 'activateSubscription', 'E60'],
            'delete' => ['deleteSubscription', '?deleteSub', 'deleteSubscription', 'E62'],
        ];
    }

    #[DataProvider('actions')]
    public function test_subscription_actions(string $method, string $endpoint, string $txnType, string $alreadyCode): void
    {
        Http::fakeSequence()
            ->push(['error_no' => 'S00', 'status' => 'OK', 'msg' => 'SUCCESS'])
            ->push(['error_no' => $alreadyCode, 'msg' => 'PROCESSING ERROR'], 400)
            ->push(['error_no' => 'E99', 'msg' => 'PROCESSING ERROR', 'desc' => 'SOMETHING ELSE'], 400);

        ValorPay::{$method}(54670);
        ValorPay::{$method}('54670');

        try {
            ValorPay::{$method}(54670);
            $this->fail('No exception');
        } catch (ValorPayException $exception) {
            $this->assertSame('E99', $exception->errorNo);
        }

        $first = Http::recorded()[0][0];
        $this->assertSame('https://valorpay.test:4430/'.$endpoint, $first->url());
        $this->assertSame(['txn_type' => $txnType, 'subscription_id' => '54670'], $this->bodyWithoutCredentials($first));
    }

    public function test_activating_a_completed_subscription_throws(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'E65', 'desc' => 'SUBSCRIPTION IS ALREADY COMPLETED'], 400)]);

        $this->expectException(ValorPayException::class);

        ValorPay::activateSubscription(54670);
    }

    public function test_it_lists_subscriptions_with_a_get(): void
    {
        Http::fake(['*' => Http::response(['records' => [$this->listed()], 'error_no' => 'S00', 'error_code' => '00'])]);

        $subscriptions = ValorPay::subscriptions(startedSince: CarbonImmutable::parse('2026-01-05'));

        $this->assertCount(1, $subscriptions);
        $subscription = $subscriptions[0];
        $this->assertSame('54670', $subscription->id());
        $this->assertTrue($subscription->isActive());
        $this->assertSame(2500, $subscription->amountInCents());
        $this->assertSame('HOST1001', $subscription->invoiceNumber());
        $this->assertSame('Web hosting', $subscription->description());
        $this->assertSame('XXXX1111', $subscription->maskedCard());
        $this->assertSame('billing@example.com', $subscription->email());
        $this->assertSame('2026-11-15', $subscription->startsFrom()->toDateString());
        $this->assertSame(15, $subscription->chargeDay());
        $this->assertSame(2, $subscription->paymentsMade());
        $this->assertSame(0, $subscription->failedAttempts());

        $request = $this->onlyRequest();
        $this->assertSame('GET', $request->method());
        $this->assertStringStartsWith('https://valorpay.test:4430/?viewSubs&', $request->url());
        $this->assertSame([
            'viewSubs' => '',
            'appid' => self::APP_ID,
            'appkey' => self::APP_KEY,
            'epi' => self::EPI,
            'txn_type' => 'listsubscription',
            'active' => 'true',
            'start_date' => '01/05/2026',
        ], $request->data());
        $this->assertStringContainsString('appkey=test-app-key%24%23%25', $request->url());
    }

    public function test_no_subscriptions_is_an_empty_list(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'E31', 'msg' => 'PROCESSING ERROR', 'desc' => 'NO SUBSCRIPTION AVAILABLE'], 400)]);

        $this->assertSame([], ValorPay::subscriptions(active: false));
        $this->assertSame('false', $this->onlyRequest()->data()['active']);
    }

    public function test_a_refused_list_throws(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'D07', 'desc' => 'NOT A VALID APP KEY'], 400)]);

        $this->expectException(ValorPayException::class);

        ValorPay::subscriptions();
    }

    public function test_finding_one_subscription_checks_active_then_inactive(): void
    {
        Http::fakeSequence()
            ->push(['error_no' => 'E31', 'desc' => 'NO SUBSCRIPTION AVAILABLE'], 400)
            ->push(['records' => [$this->listed(['status' => '0'])], 'error_no' => 'S00']);

        $subscription = ValorPay::subscription(54670);

        $this->assertFalse($subscription->isActive());
        $queries = array_map(fn (array $pair): array => [$pair[0]->data()['active'], $pair[0]->data()['subscription_id']], Http::recorded()->all());
        $this->assertSame([['true', '54670'], ['false', '54670']], $queries);
    }

    public function test_an_unknown_subscription_is_null(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'E31', 'desc' => 'NO SUBSCRIPTION AVAILABLE'], 400)]);

        $this->assertNull(ValorPay::subscription(1));
    }

    public function test_it_reads_a_card_token(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'S00', 'error_code' => '00', 'masked_pan' => '4111 XXXX XXXX 1111', 'token' => self::TOKEN, 'card_type' => 'DEBIT', 'card_brand' => 'Visa', 'cardholder_name' => ''])]);

        $card = ValorPay::cardToken(self::TOKEN);

        $this->assertSame('4111 XXXX XXXX 1111', $card->maskedPan);
        $this->assertSame('Visa', $card->brand);
        $this->assertSame('DEBIT', $card->cardType);
        $request = $this->onlyRequest();
        $this->assertSame('https://valorpay.test:4430/?fetchtoken', $request->url());
        $this->assertSame(['txn_type' => 'fetchtoken-info', 'token' => self::TOKEN], $this->bodyWithoutCredentials($request));
    }

    public function test_an_invalid_card_token_throws(): void
    {
        Http::fake(['*' => Http::response(['error_no' => 'E77', 'desc' => 'INVALID TOKEN'], 400)]);

        $this->expectException(ValorPayException::class);

        ValorPay::cardToken('nope');
    }

    private function request(int $cents = 2500): SubscriptionRequest
    {
        return new SubscriptionRequest(
            cents: $cents,
            cardToken: self::TOKEN,
            customerName: 'Acme Test Co',
            zip: '29486',
            startsOn: CarbonImmutable::parse('2026-11-15'),
            frequency: Frequency::Monthly,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function listed(array $overrides = []): array
    {
        return array_merge([
            'subscription_id' => '54670',
            'amount' => '25.00',
            'custom_fee' => '0.0000',
            'tax' => '0.0000',
            'invoice_number' => 'HOST1001',
            'product_description' => 'Web hosting',
            'billing_customerName' => 'Acme Test Co',
            'billing_zipcode' => '29486',
            'subscription_day_of_the_month' => '15',
            'subscription_starts_from' => '2026-11-15',
            'subscription_valid_for' => 'never_expired',
            'phone' => '',
            'email' => 'billing@example.com',
            'no_of_failed_attempts' => '0',
            'status' => '1',
            'card_number' => 'XXXX1111',
            'total_no_of_payments' => '2',
            'forfeit' => '0',
            'forfeit_date' => null,
        ], $overrides);
    }
}
