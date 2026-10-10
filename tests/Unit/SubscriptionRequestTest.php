<?php

namespace CaneBayComputers\ValorPay\Tests\Unit;

use CaneBayComputers\ValorPay\Subscriptions\Frequency;
use CaneBayComputers\ValorPay\Subscriptions\SubscriptionRequest;
use CaneBayComputers\ValorPay\Tests\TestCase;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class SubscriptionRequestTest extends TestCase
{
    public function test_the_optional_fields_are_sent_when_given(): void
    {
        $payload = $this->make(
            frequency: Frequency::Annually,
            chargeOn: 3,
            payments: 12,
            phone: '(843) 555-0100',
            descriptor: 'CANE BAY HOSTING AND MORE STUFF THAN FITS',
            retries: 2,
            notifyOnFailure: true,
            validateCard: false,
            taxCents: 175,
            extra: ['billing_street_name' => 'Main St'],
        )->toPayload();

        $this->assertSame('4', $payload['recurring_type']);
        $this->assertSame('3', $payload['charge_on']);
        $this->assertSame('12', $payload['charge_until']);
        $this->assertSame('8435550100', $payload['phone']);
        $this->assertSame('CANE BAY HOSTING AND MORE STUF', $payload['descriptor']);
        $this->assertSame('2', $payload['retry_count']);
        $this->assertSame('1', $payload['failure_notification']);
        $this->assertSame('0', $payload['is_validate_card']);
        $this->assertSame('1.75', $payload['tax']);
        $this->assertSame('Main St', $payload['billing_street_name']);
        $this->assertArrayNotHasKey('email', $this->make()->toPayload());
    }

    public function test_charge_on_defaults_to_the_start_day_capped_at_30(): void
    {
        $this->assertSame(9, $this->make(startsOn: CarbonImmutable::parse('2026-11-09'))->chargeOn);
        $this->assertSame(30, $this->make(startsOn: CarbonImmutable::parse('2026-12-31'))->chargeOn);
    }

    public function test_names_are_cleaned_to_25_letters(): void
    {
        $payload = $this->make(customerName: 'José\'s Café & Bakery #2, Incorporated')->toPayload();

        $this->assertSame('Jose s Cafe Bakery Incorp', $payload['billing_customer_name']);
        $this->assertSame($payload['billing_customer_name'], $payload['shipping_customer_name']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'zero amount' => [['cents' => 0], 'between'],
            'huge amount' => [['cents' => 10_000_000], 'between'],
            'no token' => [['cardToken' => ' '], 'card token'],
            'bad zip' => [['zip' => '2948'], 'ZIP'],
            'long invoice' => [['invoiceNumber' => 'INV-1234567890'], 'invoice'],
            'day 31' => [['chargeOn' => 31], '1-30'],
            'weekly without a weekday' => [['frequency' => Frequency::Weekly], 'weekday'],
            'weekday 7' => [['frequency' => Frequency::BiWeekly, 'chargeOn' => 7], '0-6'],
            'no payments' => [['payments' => 0], 'payments'],
            '11 retries' => [['retries' => 11], 'retries'],
            'negative tax' => [['taxCents' => -1], 'Tax'],
            'card number in extra' => [['extra' => ['card_number' => '4111111111111111']], 'card token only'],
            'payment info in extra' => [['extra' => ['PAYMENT_INFO' => []]], 'card token only'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('invalid')]
    public function test_invalid_requests_are_refused_before_sending(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->make(...$arguments);
    }

    private function make(mixed ...$arguments): SubscriptionRequest
    {
        return new SubscriptionRequest(...array_merge([
            'cents' => 2500,
            'cardToken' => '5F8E3B0A6C1D4E7F9A2B5C8D1E4F7A0B3C6D9E2F',
            'customerName' => 'Acme Test Co',
            'zip' => '29486',
            'startsOn' => CarbonImmutable::parse('2026-11-15'),
        ], $arguments));
    }
}
