<?php

namespace CaneBayComputers\ValorPay\Webhooks;

use Illuminate\Support\Arr;

/**
 * What a ValorPay webhook says to look at, and nothing more.
 *
 * ValorPay doesn't sign its webhooks in practice, so anyone who learns your webhook URL can post
 * one. Never mark anything paid, failed or cancelled from a webhook body. Use this to find which
 * invoices and subscriptions it mentions, then ask ValorPay itself:
 *
 *     $hint = ValorPay::webhookHint($request->getContent(), 'INV');
 *     foreach ($hint->invoiceNumbers as $invoice) { ValorPay::transactionsForInvoice($invoice, $since)->isPaid($cents); }
 *     foreach ($hint->subscriptionIds as $id) { ValorPay::transactionsForSubscription($id, $since); }
 */
final readonly class WebhookHint
{
    /**
     * @param  string|null  $event  ValorPay's event name ("RECURRING BILLING SUCCESS", "Subcription Deleted" [sic], ...). Untrusted.
     * @param  list<string>  $invoiceNumbers  Invoice numbers mentioned, limited to the given prefix.
     * @param  list<string>  $subscriptionIds  Subscription IDs mentioned.
     */
    public function __construct(
        public ?string $event,
        public array $invoiceNumbers,
        public array $subscriptionIds,
    ) {}

    /**
     * Reads a raw request body. A body that isn't JSON gives an empty hint.
     */
    public static function fromJson(string $body, string $invoicePrefix = ''): self
    {
        $payload = json_decode($body, true);

        return is_array($payload) ? self::fromPayload($payload, $invoicePrefix) : new self(null, [], []);
    }

    /**
     * Reads a decoded {event, data} payload, whatever the event is called. Data may be one object or a list.
     *
     * @param  array<mixed>  $payload
     * @param  string  $invoicePrefix  Only invoice numbers starting with this (e.g. your app's "INV"), so other apps' payments on the same merchant are ignored.
     */
    public static function fromPayload(array $payload, string $invoicePrefix = ''): self
    {
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? null;
        $items = is_array($data) ? array_filter(array_is_list($data) ? $data : [$data], 'is_array') : [];

        $invoices = [];
        $subscriptions = [];

        foreach ($items as $item) {
            foreach (['invoice_no', 'invoicenumber', 'reference_descriptive_data.invoicenumber'] as $key) {
                $invoices[] = self::text(Arr::get($item, $key));
            }

            // "subscriptionid" on Recurring Failed, "subscription_id" everywhere else.
            foreach (['subscription_id', 'subscriptionid', 'subscriptionId'] as $key) {
                $subscriptions[] = self::text(Arr::get($item, $key));
            }
        }

        return new self(
            is_string($event) ? $event : null,
            self::unique(array_filter($invoices, fn (string $number): bool => $number !== '' && str_starts_with($number, $invoicePrefix))),
            self::unique(array_filter($subscriptions, fn (string $id): bool => $id !== '' && $id !== '0')),
        );
    }

    public function isEmpty(): bool
    {
        return $this->invoiceNumbers === [] && $this->subscriptionIds === [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique($values));
    }
}
