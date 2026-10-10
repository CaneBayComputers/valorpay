<?php

namespace CaneBayComputers\ValorPay\Subscriptions;

use CaneBayComputers\ValorPay\Fields;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Everything a subscription needs, checked against ValorPay's field rules before anything is sent.
 *
 * The card is always a token from an earlier ValorPay sale (ValorPayRecord::token()), never a card
 * number: this package has no way to send one.
 *
 * With the default validateCard: true, creating the subscription only checks the card. The first
 * charge happens on $startsOn, so if the customer already paid for the first period on a hosted
 * page, start one period later.
 */
final readonly class SubscriptionRequest
{
    public CarbonImmutable $startsOn;

    public int $chargeOn;

    /**
     * @param  int  $cents  What each charge takes, in cents (2500 is $25.00).
     * @param  string  $cardToken  The TOKEN of an earlier ValorPay sale on this card.
     * @param  string  $customerName  Billing name; trimmed to ValorPay's 25 letters.
     * @param  string  $zip  Billing ZIP code (5 digits, or ZIP+4).
     * @param  CarbonInterface  $startsOn  Date of the first charge.
     * @param  int|null  $chargeOn  Day of the month (1-30), or weekday (0-6) for weekly schedules. Defaults to $startsOn's day, capped at 30.
     * @param  int|null  $payments  How many charges in all; null charges until the subscription is cancelled.
     * @param  string  $invoiceNumber  Up to 12 letters and digits.
     * @param  string  $description  Shown in ValorPay as the product description (50 characters).
     * @param  string|null  $email  Customer email ValorPay stores on the subscription.
     * @param  string|null  $phone  10-digit phone number.
     * @param  string|null  $descriptor  Statement descriptor (30 characters).
     * @param  int|null  $retries  Retries (0-10) after a charge fails for insufficient funds; null leaves ValorPay's default.
     * @param  bool  $notifyOnFailure  Whether ValorPay emails about failed charges.
     * @param  bool  $validateCard  Check the card when the subscription is created instead of charging it.
     * @param  int  $taxCents  Tax added to each charge, in cents.
     * @param  array<string, mixed>  $extra  Any other documented field (e.g. billing_street_name). Can't carry card data or credentials.
     */
    public function __construct(
        public int $cents,
        public string $cardToken,
        public string $customerName,
        public string $zip,
        CarbonInterface $startsOn,
        public Frequency $frequency = Frequency::Monthly,
        ?int $chargeOn = null,
        public ?int $payments = null,
        public string $invoiceNumber = '',
        public string $description = '',
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $descriptor = null,
        public ?int $retries = null,
        public bool $notifyOnFailure = false,
        public bool $validateCard = true,
        public int $taxCents = 0,
        public array $extra = [],
    ) {
        $this->startsOn = CarbonImmutable::instance($startsOn)->startOfDay();
        $this->chargeOn = $chargeOn ?? $this->defaultChargeOn();

        $this->validate();
    }

    /**
     * The request body, without credentials or txn_type.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $name = Fields::personName($this->customerName);
        $zip = Fields::zip($this->zip);

        $payload = [
            'amount' => Fields::dollars($this->cents),
            'surchargeAmount' => '0',
            'surchargeIndicator' => '0',
            'tax' => Fields::dollars($this->taxCents),
            'payment_info' => ['token' => $this->cardToken],
            'recurring_type' => (string) $this->frequency->value,
            'charge_on' => (string) $this->chargeOn,
            'subscription_starts_from' => $this->startsOn->toDateString(),
            'charge_until' => $this->payments === null ? 'never_expired' : (string) $this->payments,
            'is_validate_card' => $this->validateCard ? '1' : '0',
            'invoice_no' => Fields::invoiceNumber($this->invoiceNumber),
            'product_description' => Str::limit($this->description, 50, ''),
            'billing_customer_name' => $name,
            'billing_zip' => $zip,
            'shipping_customer_name' => $name,
            'shipping_zip' => $zip,
            'failure_notification' => $this->notifyOnFailure ? '1' : '0',
        ];

        if ($this->email !== null && $this->email !== '') {
            $payload['email'] = $this->email;
        }

        if ($this->phone !== null && $this->phone !== '') {
            $payload['phone'] = preg_replace('/\D/', '', $this->phone);
        }

        if ($this->descriptor !== null && $this->descriptor !== '') {
            $payload['descriptor'] = Str::limit($this->descriptor, 30, '');
        }

        if ($this->retries !== null) {
            $payload['retry_count'] = (string) $this->retries;
        }

        return array_merge($payload, $this->extra);
    }

    private function defaultChargeOn(): int
    {
        if ($this->frequency->isWeekBased()) {
            throw new InvalidArgumentException('Weekly and bi-weekly subscriptions need chargeOn: the weekday, 0-6, in ValorPay\'s numbering.');
        }

        // ValorPay takes 1-30, so a subscription starting on the 31st charges on the 30th.
        return min($this->startsOn->day, 30);
    }

    private function validate(): void
    {
        if ($this->cents <= 0 || $this->cents > 9_999_999) {
            throw new InvalidArgumentException('A subscription charge must be between $0.01 and $99,999.99.');
        }

        if (trim($this->cardToken) === '') {
            throw new InvalidArgumentException('A subscription needs the card token of an earlier ValorPay sale.');
        }

        if ($this->taxCents < 0) {
            throw new InvalidArgumentException('Tax can\'t be negative.');
        }

        [$min, $max] = $this->frequency->isWeekBased() ? [0, 6] : [1, 30];

        if ($this->chargeOn < $min || $this->chargeOn > $max) {
            throw new InvalidArgumentException("chargeOn must be {$min}-{$max} for a {$this->frequency->name} subscription.");
        }

        if ($this->payments !== null && $this->payments < 1) {
            throw new InvalidArgumentException('payments must be at least 1, or null for no end.');
        }

        if ($this->retries !== null && ($this->retries < 0 || $this->retries > 10)) {
            throw new InvalidArgumentException('ValorPay allows 0-10 retries.');
        }

        $blocked = ['appid', 'appkey', 'epi', 'txn_type', 'subscription_id', 'payment_info', 'card_number', 'expiry_date', 'cvv', 'pan_num', 'expiry'];

        if (($keys = array_intersect(array_map('strtolower', array_keys($this->extra)), $blocked)) !== []) {
            throw new InvalidArgumentException('extra can\'t set '.implode(', ', $keys).'. Pay with a card token only.');
        }

        Fields::zip($this->zip);
        Fields::invoiceNumber($this->invoiceNumber);
    }
}
