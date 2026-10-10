# ValorPay for Laravel

`canebaycomputers/valorpay` is a small Laravel client for [ValorPay](https://valorpaytech.com). It
covers the jobs most apps have:

- **Take a payment** on a ValorPay-hosted page.
- **Confirm the payment** from ValorPay's own transaction records.
- **Bill on a schedule** with subscriptions charged to a stored card token.
- **Handle webhooks safely**, treating them only as hints about what to re-check.

Three rules hold everywhere in this package:

1. **Amounts are in cents.** `2500` means $25.00. This matches what ValorPay's transaction list reports.
2. **Card numbers never touch your app.** Customers type their card on ValorPay's page. Subscriptions
   charge the token ValorPay saved from that sale. The package has no way to send a card number.
3. **Webhooks are hints, not proof.** In practice ValorPay doesn't sign its webhooks, so anyone can
   post one to your URL. Check the transaction list before you act on one.

## Install

```bash
composer require canebaycomputers/valorpay
```

Laravel discovers the service provider and the `ValorPay` facade on its own. Add your credentials to `.env`:

```dotenv
VALORPAY_APP_ID='your-valor-connect-app-id'
VALORPAY_APP_KEY='your-virtual-terminal-app-key'
VALORPAY_EPI=2xxxxxxxxx
# Optional:
# VALORPAY_URL=https://securelink-prod.valorpaytech.com:4430
# VALORPAY_LINK_VALID_DAYS=1   # how long a hosted page can be paid
# VALORPAY_TIMEOUT=20          # seconds per call
```

Put the app ID and key in single quotes, because they can contain `$`, `#` and `%`. To publish the
config file (optional):

```bash
php artisan vendor:publish --tag=valorpay-config
```

The credentials are checked when the first call is made, not when the app boots. An app without them
still runs; a ValorPay call throws `ValorPayException` instead.

## 1. Take a payment

```php
use CaneBayComputers\ValorPay\Facades\ValorPay;

$page = ValorPay::createHostedPage(
    cents: 2500,
    invoiceNumber: 'INV1001',            // your reference: it's how you find the payment again
    description: 'Web hosting, November',
    successUrl: route('checkout.done', $invoice),
    failureUrl: route('checkout.failed', $invoice),
    customerName: $invoice->business_name, // cleaned to what ValorPay accepts
);

$invoice->update(['valorpay_page_uid' => $page->uid]);

return redirect($page->url);
```

To cancel a page so it can no longer be paid, call `ValorPay::cancelHostedPage($uid)`. It returns
`true` when the page is cancelled, already cancelled or expired. It never throws.

Things to know:

- **The success URL proves nothing.** Anyone can open it. Confirm the payment with step 2.
- **The customer's email is not sent.** ValorPay emails that address an "E-INVOICE, please pay" message
  even with notifications off, which confuses someone who just paid. Send your own receipt.
- **Private-IP URLs get a 403.** ValorPay's firewall refuses success and failure URLs that point at a
  private IP (`http://10.x.x.x`, `http://192.168.x.x`). Local development needs a public URL.
- **Other fields:** `validDays:` overrides how long the link lasts. `extra: [...]` passes any other
  documented hosted-page field.

## 2. Confirm the payment

```php
$payments = ValorPay::transactionsForInvoice('INV1001', since: $invoice->created_at);

if ($sale = $payments->paidSale(2500)) {
    $invoice->markPaid(txnId: $sale->id(), approval: $sale->approvalCode());
}
```

`transactionsForInvoice()` returns every record ValorPay has under that invoice number on your EPI:
sales, declines, refunds and voids. `Transactions` gives you the checks:

| Method | Returns |
|---|---|
| `paidSale($cents)` / `isPaid($cents)` | The approved sale for exactly that amount that still stands (not voided, reversed or refunded). |
| `standingSales()` | Every approved sale that still stands. More than one means a double charge. |
| `reversals()` | Refunds, voids and reversals, plus sales that were later undone. |
| `all()`, `first()`, `isEmpty()`, `count()`, `filter()`, `onEpi()` | Ordinary list access. `Transactions` is also iterable. |

Each `ValorPayRecord` provides `id()`, `type()`, `amountInCents()`, `approvalCode()`, `isApproved()`,
`isStandingSale()`, `isReversal()`, `invoiceNumber()`, `payerEmail()` (the email typed on ValorPay's
page), `token()`, `subscriptionId()`, `epi()`, `describe()` (for example "SALE 123 ($25.00)"), and
`get('ANY_FIELD')` for any other field.

How the lookup works, so you don't have to:

- **Exact matches only.** ValorPay matches invoice numbers as substrings: a search for `INV1` also
  returns `INV10`. Only exact matches are kept.
- **Your EPI only.** ValorPay lists every device on the merchant account. Only your EPI's records are kept.
- **US Eastern dates.** ValorPay's dates are US Eastern. The search runs from a day before `since`
  to a day after today.
- **Long ranges and many results.** Ranges longer than ValorPay's 90-day limit are split into several
  queries, and every page of results is fetched.

For other searches there is `ValorPay::transactions($filter, $text, $since, $until = null)`. `$filter`
is one of `VT_INVOICE`, `SUBSCRIPTION_ID`, `TXNID`, `RRN`, `APPROVAL_CODE`, `AMOUNT`, `EPI`, `MID`,
`ADDNL_VAL1`, `ADDNL_VAL2`, or `''` for everything. It returns raw substring matches from every device.

## 3. Bill on a schedule (subscriptions)

ValorPay subscriptions charge a stored card token on a schedule. You get the token from a hosted-page
sale, so the customer types their card exactly once, on ValorPay's own page:

```php
use CaneBayComputers\ValorPay\Subscriptions\Frequency;
use CaneBayComputers\ValorPay\Subscriptions\SubscriptionRequest;

// 1. The first month is an ordinary hosted page (step 1), confirmed with the transaction list (step 2).
$sale = ValorPay::transactionsForInvoice('INV1001', $invoice->created_at)->paidSale(2500);

// 2. The sale carries the card token. Subscribe from next month on.
$subscriptionId = ValorPay::createSubscription(new SubscriptionRequest(
    cents: 2500,
    cardToken: $sale->token(),
    customerName: 'Acme Co',
    zip: '29486',
    startsOn: now()->addMonth(),         // date of the first scheduled charge
    frequency: Frequency::Monthly,       // Weekly, BiWeekly, Monthly, Quarterly, Annually, Daily
    description: 'Web hosting',
    email: 'billing@acme.example',
));
```

`SubscriptionRequest` checks every field against ValorPay's rules before anything is sent. A bad ZIP,
a 13-character invoice number or a 31st-day charge throws `InvalidArgumentException` before any call
goes out. Its arguments:

| Argument | Default | Notes |
|---|---|---|
| `cents`, `cardToken`, `customerName`, `zip`, `startsOn` | required | The name is cut to 25 letters. ZIP+4 is accepted. |
| `frequency` | `Monthly` | |
| `chargeOn` | day of `startsOn` | Day of the month, 1 to 30 (ValorPay has no 31). Weekly and bi-weekly schedules need a weekday, 0 to 6. |
| `payments` | `null` (until cancelled) | Total number of charges. |
| `invoiceNumber` | `''` | Up to 12 letters and digits. Don't reuse the first sale's invoice number. Recurring charges are matched by subscription ID anyway. |
| `description`, `email`, `phone`, `descriptor` | | `descriptor` is the text on the card statement, up to 30 characters. |
| `retries` | ValorPay's default | 0 to 10 retries after a charge fails for insufficient funds. |
| `validateCard` | `true` | Only checks the card when the subscription is created. The first charge happens on `startsOn`. |
| `notifyOnFailure` | `false` | Whether ValorPay emails about failed charges. |
| `taxCents` | `0` | |
| `extra` | `[]` | Any other documented field (for example `billing_street_name`). Card fields are refused. |

Managing subscriptions:

```php
ValorPay::deactivateSubscription($id);   // pause; already paused or finished is fine
ValorPay::activateSubscription($id);     // resume; already active is fine
ValorPay::deleteSubscription($id);       // for good; already deleted is fine
ValorPay::updateSubscription($id, new SubscriptionRequest(...)); // send the whole subscription

ValorPay::subscription($id);             // ?Subscription, active or not
ValorPay::subscriptions(active: true);   // list<Subscription>; an empty list when there are none
```

To change the card, take a new hosted-page sale and call `updateSubscription()` with that sale's token.

A `Subscription` provides `id()`, `isActive()`, `amountInCents()`, `chargeDay()`, `startsFrom()`,
`paymentsMade()`, `failedAttempts()`, `maskedCard()`, `invoiceNumber()`, `description()`, `email()`
and `get('field')`. ValorPay's list doesn't include the frequency or the next charge date, so store
those in your own database.

**What a subscription actually charged** comes from the transaction list, the same as one-off payments:

```php
$charges = ValorPay::transactionsForSubscription($subscriptionId, since: $subscription->started_at);
$lastGood = $charges->standingSales()->first(); // newest first
```

`ValorPay::cardToken($token)` shows what a token points to: masked number, brand and card type.

## 4. Webhooks: a hint to re-check, never proof

ValorPay posts `{event, data}` for sales, refunds and recurring billing. **In practice ValorPay
doesn't sign them** (no `Valor-Signature` header, checked 2026-09-29), and the event names aren't
even consistent (`"Subcription Deleted"` is spelled that way). Use the payload only to decide what
to look up:

```php
use CaneBayComputers\ValorPay\Facades\ValorPay;

class ValorPayWebhookController
{
    public function __invoke(Request $request)
    {
        // Your own prefix ("INV") skips payments other apps make on the same merchant.
        $hint = ValorPay::webhookHint($request->getContent(), invoicePrefix: 'INV');

        foreach ($hint->invoiceNumbers as $number) {
            $invoice = Invoice::firstWhere('number', $number);
            $sale = $invoice ? ValorPay::transactionsForInvoice($number, $invoice->created_at)->paidSale($invoice->cents) : null;
            // Act only on $sale: that's ValorPay's own record.
        }

        foreach ($hint->subscriptionIds as $id) {
            // Re-check with ValorPay::transactionsForSubscription($id, ...) and ValorPay::subscription($id).
        }

        return response()->noContent();
    }
}
```

`WebhookHint` reads `invoice_no`, `invoicenumber` and `reference_descriptive_data.invoicenumber` for
invoices, and both `subscription_id` and `subscriptionid` for subscriptions. It accepts `data` as a
single object or as a list, and ignores anything that isn't JSON.

If ValorPay ever does sign its webhooks, `Webhooks\WebhookSignature` checks the `Valor-Signature` and
`Valor-Timestamp` headers against `VALORPAY_WEBHOOK_SECRET`:
`WebhookSignature::fromConfig()->problem($body, $signature, $timestamp)` returns `null` when the
signature is valid, or the reason it isn't. Even with a valid signature, re-check before you act.

## Errors

Every call throws `CaneBayComputers\ValorPay\ValorPayException` when ValorPay can't be reached,
refuses a call, or isn't configured. The one exception is `cancelHostedPage()`, which never throws.
`$exception->errorNo` holds ValorPay's code when it sent one:

| Code | Meaning |
|---|---|
| `S00` | Success. Never thrown. |
| `D07` | Bad app key. |
| `D27` | Invalid EPI. |
| `E31` | No subscription. `subscriptions()` returns `[]` and `subscription()` returns `null` instead of throwing. |
| `E59` / `E60` / `E62` | Already deactivated / already active / already deleted. Treated as success. |
| `E65` | The subscription is already completed, so it can't be activated. |
| `E77` | Invalid card token. |
| `A38` / `A39` | Hosted page already cancelled / already expired. `cancelHostedPage()` returns `true`. |

## Testing your app

The client uses Laravel's HTTP client, so `Http::fake()` covers it:

```php
Http::fake(['*valorpaytech.com*' => Http::response(['status' => 'SUCCESS', 'data' => [[
    'REF_TXN_ID' => '1', 'EPI' => config('valorpay.epi'), 'INVOICE_NO' => 'INV1001', 'TXN_TYPE' => 'SALE',
    'RESPONSE_CODE' => '00', 'APPROVAL_CODE' => 'TEST01', 'BASE_AMOUNT' => '2500',
]]])]);
```

## What has and hasn't been tried against production

These were checked against production with read-only calls (2026-10-09): the transaction list
(including date-range splitting and pagination), exact invoice matching, card-token lookup,
subscription listing (an empty list comes back as `E31`), hosted pages, and cancelling a page (proven
by zeltro-license, where this code comes from).

These follow ValorPay's documentation but have **not yet been run live**:

- Creating, updating, pausing, resuming and deleting subscriptions.
- Whether a subscription accepts a hosted-page sale's token. The docs say "card token received from
  the transaction".
- Which EPI recurring charges are recorded on. Because of this, `transactionsForSubscription()` doesn't
  filter by EPI.
- The date format: the docs say `YYYYMMDD`, but their examples use `YYYY-MM-DD`, which is what this
  package sends.

Try the first subscription on a small amount.

## Development

```bash
composer install
vendor/bin/phpunit
```

The tests fake every call. Never put real credentials, EPIs or customer data in this repository:
it is public.

## License

MIT
