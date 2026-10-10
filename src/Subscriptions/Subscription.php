<?php

namespace CaneBayComputers\ValorPay\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Throwable;

/**
 * A subscription as ValorPay's subscription list reports it.
 *
 * The list doesn't say how often it charges or when it next runs; keep that on your side, and
 * check what was actually charged with ValorPay::transactionsForSubscription().
 */
class Subscription
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(private array $record) {}

    public function id(): string
    {
        return $this->string('subscription_id');
    }

    public function isActive(): bool
    {
        return $this->string('status') === '1';
    }

    /**
     * Each charge, in cents. ValorPay lists it in dollars ("25" or "25.00").
     */
    public function amountInCents(): ?int
    {
        $amount = $this->string('amount');

        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', $amount, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 100 + (int) str_pad(substr($match[2] ?? '', 0, 2), 2, '0');
    }

    public function invoiceNumber(): string
    {
        return $this->string('invoice_number');
    }

    public function description(): string
    {
        return $this->string('product_description');
    }

    /**
     * The card's last digits, like "XXXX1111".
     */
    public function maskedCard(): string
    {
        return $this->string('card_number');
    }

    public function email(): ?string
    {
        $email = $this->string('email');

        return $email === '' ? null : $email;
    }

    public function startsFrom(): ?CarbonImmutable
    {
        try {
            return $this->string('subscription_starts_from') === '' ? null : CarbonImmutable::parse($this->string('subscription_starts_from'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The charge_on value: day of the month, or weekday for weekly schedules.
     */
    public function chargeDay(): ?int
    {
        $day = $this->string('subscription_day_of_the_month');

        return ctype_digit($day) ? (int) $day : null;
    }

    public function paymentsMade(): int
    {
        return (int) $this->string('total_no_of_payments');
    }

    public function failedAttempts(): int
    {
        return (int) $this->string('no_of_failed_attempts');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->record, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->record;
    }

    private function string(string $key): string
    {
        return trim((string) Arr::get($this->record, $key));
    }
}
