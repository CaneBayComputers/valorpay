<?php

namespace CaneBayComputers\ValorPay;

use Illuminate\Support\Arr;

/**
 * One transaction as ValorPay's own transaction list reports it. This, not a webhook
 * payload, is what decides whether something was paid.
 *
 * Amounts are in cents ("BASE_AMOUNT": "10000" is $100.00). A declined attempt is a SALE
 * with a non-00 response code and a blank approval code; a refund is its own REFUND record
 * under the same invoice number.
 */
class ValorPayRecord
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(private array $record) {}

    /**
     * ValorPay's transaction ID.
     */
    public function id(): string
    {
        return $this->string('REF_TXN_ID');
    }

    public function epi(): string
    {
        return $this->string('EPI');
    }

    public function invoiceNumber(): string
    {
        return $this->string('INVOICE_NO');
    }

    /**
     * The subscription a recurring charge belongs to, or null for a one-off sale.
     */
    public function subscriptionId(): ?string
    {
        $id = $this->string('SUBSCRIPTION_ID');

        return $id === '' || $id === '0' ? null : $id;
    }

    /**
     * The reusable card token ValorPay stored for this sale. Hosted-page and virtual terminal sales
     * have one (40 hex characters); card-present terminal sales don't. Pass it to a subscription.
     */
    public function token(): ?string
    {
        $token = $this->string('TOKEN');

        return $token === '' ? null : $token;
    }

    /**
     * The email the payer typed on ValorPay's payment page, when it is a valid address.
     */
    public function payerEmail(): ?string
    {
        $email = $this->string('EMAIL');

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    /**
     * SALE, REFUND, VOID, ... in capitals.
     */
    public function type(): string
    {
        return strtoupper($this->string('TXN_TYPE'));
    }

    public function approvalCode(): ?string
    {
        $code = trim((string) (Arr::get($this->record, 'APPROVAL_CODE') ?: Arr::get($this->record, 'AUTH_CODE')));

        return $code === '' ? null : $code;
    }

    public function isApproved(): bool
    {
        return $this->string('RESPONSE_CODE') === '00' && $this->approvalCode() !== null;
    }

    /**
     * An approved sale that still stands: not voided, reversed or refunded.
     */
    public function isStandingSale(): bool
    {
        return $this->type() === 'SALE' && $this->isApproved() && ! $this->isUndone();
    }

    /**
     * Money that went back: an approved refund, return or void record, or an approved sale since voided,
     * reversed or (partly) refunded.
     */
    public function isReversal(): bool
    {
        if (in_array($this->type(), ['REFUND', 'RETURN', 'VOID', 'REVERSAL'], true)) {
            return $this->isApproved();
        }

        return $this->type() === 'SALE' && $this->isApproved() && $this->isUndone();
    }

    public function amountInCents(): ?int
    {
        $amount = $this->string('BASE_AMOUNT');

        return ctype_digit($amount) ? (int) $amount : null;
    }

    public function amountForDisplay(): string
    {
        $cents = $this->amountInCents();

        return $cents === null ? 'an unreadable amount' : '$'.number_format($cents / 100, 2);
    }

    /**
     * "SALE 11012417297 ($100.00)", for logs and admin notes.
     */
    public function describe(): string
    {
        return "{$this->type()} {$this->id()} ({$this->amountForDisplay()})";
    }

    /**
     * Any field of the raw record, e.g. get('MASKED_CARD_NO').
     */
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

    private function isUndone(): bool
    {
        return (string) Arr::get($this->record, 'IS_VOID') === '1'
            || (string) Arr::get($this->record, 'IS_REVERSAL') === '1'
            || (int) Arr::get($this->record, 'REFUND_AMOUNT', 0) > 0;
    }

    private function string(string $key): string
    {
        return trim((string) Arr::get($this->record, $key));
    }
}
