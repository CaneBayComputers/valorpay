<?php

namespace CaneBayComputers\ValorPay;

use CaneBayComputers\ValorPay\Subscriptions\Subscription;
use CaneBayComputers\ValorPay\Subscriptions\SubscriptionRequest;
use CaneBayComputers\ValorPay\Webhooks\WebhookHint;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * ValorPay's hosted payment pages, transaction list, card tokens and subscriptions.
 *
 * Card numbers never pass through here: customers type them on ValorPay's own page, and
 * subscriptions charge the token ValorPay stored for that sale.
 *
 * @see https://valorapi.readme.io/
 */
class ValorPayClient
{
    // Each call is a query-string "endpoint" on the one ValorPay URL. ValorPay's docs disagree on
    // some of these names between versions; ValorPay appears to route on txn_type.
    public const HOSTED_PAGE = '?hostedpage';

    public const HOSTED_PAGE_CANCEL = '?hosted_page_cancel';

    public const TRANSACTION_LIST = '?txn_list';

    public const TOKEN_INFO = '?fetchtoken';

    public const SUBSCRIPTION_ADD = '?addSubs';

    public const SUBSCRIPTION_UPDATE = '?updateSubs';

    public const SUBSCRIPTION_LIST = '?viewSubs';

    public const SUBSCRIPTION_ACTIVATE = '?activateSub';

    public const SUBSCRIPTION_DEACTIVATE = '?de-Activate';

    public const SUBSCRIPTION_DELETE = '?deleteSub';

    /** ValorPay's dates (transaction list ranges, subscription dates) are US Eastern. */
    public const TIMEZONE = 'America/New_York';

    /** The transaction list takes at most a 90-day range per query. */
    private const MAX_RANGE_DAYS = 90;

    private const PAGE_SIZE = 100;

    /** Stops a runaway pagination loop: 50 pages of 100 per 90-day window. */
    private const MAX_PAGES = 50;

    /**
     * @param  array<string, mixed>  $config  url, app_id, app_key, epi, and optionally link_valid_days and timeout.
     */
    public function __construct(private array $config) {}

    /**
     * The EPI (device ID) this client works on.
     */
    public function epi(): string
    {
        return (string) ($this->config['epi'] ?? '');
    }

    /*
    |--------------------------------------------------------------------------
    | One-off payments: hosted pages
    |--------------------------------------------------------------------------
    */

    /**
     * Creates a ValorPay-hosted page where the customer pays one sale. Send them to the returned URL.
     *
     * Reaching $successUrl proves nothing: confirm the payment with transactionsForInvoice().
     *
     * The customer's email is deliberately not sent: even with notification_status 0, ValorPay
     * emails that address an "E-INVOICE … please pay" message, which confuses someone who just
     * paid in the browser. Send your own receipt.
     *
     * ValorPay's firewall answers a bare 403 when the success or failure URL points at a private IP
     * (http://10.x.x.x, http://192.168.x.x), so local development gets a ValorPayException here.
     *
     * @param  int  $cents  Amount in cents (2500 is $25.00).
     * @param  string  $invoiceNumber  Your reference; it's how you find the payment again.
     * @param  string  $description  Shown on the payment page.
     * @param  string  $customerName  Business or person; cleaned to what ValorPay accepts.
     * @param  int|null  $validDays  Days the page can be paid; defaults to config link_valid_days.
     * @param  array<string, mixed>  $extra  Other documented hosted-page fields, sent as given.
     *
     * @see https://valorapi.readme.io/reference/epage
     *
     * @throws ValorPayException
     */
    public function createHostedPage(
        int $cents,
        string $invoiceNumber,
        string $description,
        string $successUrl,
        string $failureUrl,
        string $customerName = '',
        ?int $validDays = null,
        array $extra = [],
    ): HostedPage {
        $response = $this->post(self::HOSTED_PAGE, array_merge([
            'txn_type' => 'sale',
            'amount' => $cents / 100,
            'customer_name' => Fields::customerName($customerName),
            'invoicenumber' => $invoiceNumber,
            'orderdescription' => $description,
            'success_url' => $successUrl,
            'failure_url' => $failureUrl,
            'epage' => 1,
            'shipping_country' => 'US',
            'notification_status' => '0',
            'active_on' => now()->addDays($validDays ?? (int) ($this->config['link_valid_days'] ?? 1))->toDateString(),
        ], $extra));

        $url = $response->json('url');
        $uid = $response->json('uid');

        if (! $response->successful() || $response->json('error_no') !== 'S00' || ! is_string($url) || ! is_string($uid)) {
            throw $this->refused('the hosted page', $response);
        }

        return new HostedPage($url, $uid);
    }

    /**
     * Cancels a hosted page so it can't be paid any more. Best effort: returns whether ValorPay
     * confirmed it (already cancelled or expired counts), and never throws.
     *
     * @see https://valorapi.readme.io/reference/hosted-page-sale-cancel
     */
    public function cancelHostedPage(string $uid): bool
    {
        try {
            $response = $this->post(self::HOSTED_PAGE_CANCEL, ['txn_type' => 'cancel_einvoice', 'uid' => $uid], timeout: 10);
        } catch (ValorPayException $exception) {
            report($exception);

            return false;
        }

        // S00 cancelled; A38 already cancelled; A39 already expired (checked against production 2026-09-30).
        return in_array($response->json('error_no'), ['S00', 'A38', 'A39'], true);
    }

    /*
    |--------------------------------------------------------------------------
    | What ValorPay actually recorded: the transaction list
    |--------------------------------------------------------------------------
    */

    /**
     * Every transaction under an invoice number on this EPI: sales, declines, refunds, voids.
     * This is how to tell whether an invoice was paid: ->isPaid($cents).
     *
     * The list API matches the invoice as a substring, so only exact INVOICE_NO matches are kept. It
     * also lists every device on the merchant, so only records on this client's EPI are kept.
     *
     * @param  CarbonInterface  $since  When the invoice was created (or earlier); the search starts a day before.
     *
     * @see https://valorapi.readme.io/reference/transaction-list-with-date-range-api-copy
     *
     * @throws ValorPayException when ValorPay can't be reached or refuses the query
     */
    public function transactionsForInvoice(string $invoiceNumber, CarbonInterface $since): Transactions
    {
        return $this->transactions('VT_INVOICE', $invoiceNumber, $since)
            ->filter(fn (ValorPayRecord $record): bool => $record->invoiceNumber() === $invoiceNumber)
            ->onEpi($this->epi());
    }

    /**
     * Every charge a subscription has made (approved and declined), newest first as ValorPay lists them.
     * Recurring charges often have an empty invoice number, so they're found by SUBSCRIPTION_ID.
     *
     * @param  CarbonInterface  $since  How far back to look (e.g. the subscription's start).
     *
     * @throws ValorPayException
     */
    public function transactionsForSubscription(string|int $subscriptionId, CarbonInterface $since): Transactions
    {
        $subscriptionId = (string) $subscriptionId;

        return $this->transactions('SUBSCRIPTION_ID', $subscriptionId, $since)
            ->filter(fn (ValorPayRecord $record): bool => $record->subscriptionId() === $subscriptionId);
    }

    /**
     * A raw search of the transaction list, across every device on the merchant.
     *
     * Dates are US Eastern; the range runs from a day before $since to a day after $until (default now).
     * Ranges longer than ValorPay's 90-day limit are split, and every page is fetched.
     *
     * @param  string  $filter  VT_INVOICE, SUBSCRIPTION_ID, TXNID, RRN, APPROVAL_CODE, AMOUNT, EPI, MID, ADDNL_VAL1, ADDNL_VAL2, or '' for all.
     * @param  string  $text  What to search for. ValorPay matches substrings.
     *
     * @throws ValorPayException
     */
    public function transactions(string $filter, string $text, CarbonInterface $since, ?CarbonInterface $until = null): Transactions
    {
        $start = CarbonImmutable::instance($since)->setTimezone(self::TIMEZONE)->subDay()->startOfDay();
        $end = ($until === null ? CarbonImmutable::now(self::TIMEZONE) : CarbonImmutable::instance($until)->setTimezone(self::TIMEZONE))->addDay()->startOfDay();
        $records = [];

        // Newest window first, so the result reads newest first like a single query does.
        for ($windowEnd = $end; $windowEnd->greaterThanOrEqualTo($start); $windowEnd = $windowStart->subDay()) {
            $windowStart = $windowEnd->subDays(self::MAX_RANGE_DAYS - 1)->max($start);
            array_push($records, ...$this->transactionWindow($filter, $text, $windowStart, $windowEnd));
        }

        return new Transactions($records);
    }

    /**
     * What ValorPay stored for a card token: masked number, brand, type. Read-only.
     *
     * @see https://valorapi.readme.io/reference/fetch-token-info
     *
     * @throws ValorPayException
     */
    public function cardToken(string $token): CardToken
    {
        $response = $this->post(self::TOKEN_INFO, ['txn_type' => 'fetchtoken-info', 'token' => $token]);

        if (! $response->successful() || $response->json('error_no') !== 'S00') {
            throw $this->refused('the card token lookup', $response);
        }

        return new CardToken(
            token: (string) ($response->json('token') ?: $token),
            maskedPan: (string) $response->json('masked_pan'),
            brand: (string) $response->json('card_brand'),
            cardType: (string) $response->json('card_type'),
            cardholderName: (string) $response->json('cardholder_name'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Subscriptions (recurring card charges)
    |--------------------------------------------------------------------------
    */

    /**
     * Creates a subscription that charges a stored card token on a schedule, and returns its ID.
     *
     * @see https://valorapi.readme.io/reference/add-subscriptions
     *
     * @throws ValorPayException
     */
    public function createSubscription(SubscriptionRequest $request): string
    {
        $response = $this->post(self::SUBSCRIPTION_ADD, ['txn_type' => 'add_subscription'] + $request->toPayload());
        $id = $response->json('subscription_id');

        if (! $response->successful() || $response->json('error_no') !== 'S00' || ! (is_int($id) || (is_string($id) && $id !== ''))) {
            throw $this->refused('the subscription', $response);
        }

        return (string) $id;
    }

    /**
     * Replaces a subscription's details: amount, schedule, or card (a new sale's token). ValorPay
     * requires the whole subscription on every update, so pass a complete request.
     *
     * @see https://valorapi.readme.io/reference/update-subscription
     *
     * @throws ValorPayException
     */
    public function updateSubscription(string|int $subscriptionId, SubscriptionRequest $request): void
    {
        $response = $this->post(self::SUBSCRIPTION_UPDATE, [
            'txn_type' => 'updateSubscription',
            'subscription_id' => (string) $subscriptionId,
        ] + $request->toPayload());

        if (! $response->successful() || $response->json('error_no') !== 'S00') {
            throw $this->refused('the subscription update', $response);
        }
    }

    /**
     * Pauses a subscription: no charges until it's activated again. Pausing one that is already
     * paused or finished is not an error.
     *
     * @throws ValorPayException
     */
    public function deactivateSubscription(string|int $subscriptionId): void
    {
        // E59: already deactivated or completed.
        $this->subscriptionAction(self::SUBSCRIPTION_DEACTIVATE, 'deactivateSubscription', $subscriptionId, ['E59']);
    }

    /**
     * Resumes a paused subscription. Activating one that is already active is not an error;
     * a finished one (E65) is.
     *
     * @throws ValorPayException
     */
    public function activateSubscription(string|int $subscriptionId): void
    {
        // E60: already activated or pending.
        $this->subscriptionAction(self::SUBSCRIPTION_ACTIVATE, 'activateSubscription', $subscriptionId, ['E60']);
    }

    /**
     * Deletes a subscription for good. Deleting one already deleted is not an error.
     *
     * @throws ValorPayException
     */
    public function deleteSubscription(string|int $subscriptionId): void
    {
        // E62: already deleted.
        $this->subscriptionAction(self::SUBSCRIPTION_DELETE, 'deleteSubscription', $subscriptionId, ['E62']);
    }

    /**
     * The merchant's subscriptions, active or inactive.
     *
     * @param  CarbonInterface|null  $startedSince  ValorPay's start_date filter (required by the API); defaults to 2000-01-01.
     * @return list<Subscription>
     *
     * @see https://valorapi.readme.io/reference/view-subscription
     *
     * @throws ValorPayException
     */
    public function subscriptions(bool $active = true, ?CarbonInterface $startedSince = null, string|int|null $subscriptionId = null): array
    {
        $query = [
            'txn_type' => 'listsubscription',
            'active' => $active ? 'true' : 'false',
            'start_date' => ($startedSince === null ? CarbonImmutable::create(2000) : CarbonImmutable::instance($startedSince))->format('m/d/Y'),
        ];

        if ($subscriptionId !== null) {
            $query['subscription_id'] = (string) $subscriptionId;
        }

        // The list is a GET: ValorPay answers a POST with "Invalid Method".
        $response = $this->send('get', self::SUBSCRIPTION_LIST, $query);

        // E31: NO SUBSCRIPTION AVAILABLE, i.e. an empty list.
        if ($response->json('error_no') === 'E31') {
            return [];
        }

        $records = $response->json('records');

        if (! $response->successful() || $response->json('error_no') !== 'S00' || ! is_array($records)) {
            throw $this->refused('the subscription list', $response);
        }

        return array_values(array_map(
            fn (array $record): Subscription => new Subscription($record),
            array_filter($records, 'is_array'),
        ));
    }

    /**
     * One subscription, active or not, or null when ValorPay has no such subscription.
     *
     * @throws ValorPayException
     */
    public function subscription(string|int $subscriptionId): ?Subscription
    {
        foreach ([true, false] as $active) {
            foreach ($this->subscriptions($active, null, $subscriptionId) as $subscription) {
                if ($subscription->id() === (string) $subscriptionId) {
                    return $subscription;
                }
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */

    /**
     * What an (unsigned, untrusted) webhook says to re-check. See WebhookHint.
     *
     * @param  array<mixed>|string  $payload  The decoded payload or the raw request body.
     */
    public function webhookHint(array|string $payload, string $invoicePrefix = ''): WebhookHint
    {
        return is_string($payload) ? WebhookHint::fromJson($payload, $invoicePrefix) : WebhookHint::fromPayload($payload, $invoicePrefix);
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<ValorPayRecord>
     *
     * @throws ValorPayException
     */
    private function transactionWindow(string $filter, string $text, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $records = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->post(self::TRANSACTION_LIST, [
                'txn_type' => 'txnfetch',
                'date_filter' => 7,
                'start_date_range' => $start->toDateString(),
                'end_date_range' => $end->toDateString(),
                'filter' => $filter,
                'filter_text' => $text,
                'transaction_status' => 'ALL',
                'limit' => self::PAGE_SIZE,
                'offset' => $page * self::PAGE_SIZE,
                'version' => 2,
            ]);

            $data = $response->json('data');

            if (! $response->successful() || $response->json('status') !== 'SUCCESS' || ! (is_array($data) || $data === null)) {
                throw $this->refused('the transaction lookup', $response);
            }

            foreach ($data ?? [] as $record) {
                if (is_array($record)) {
                    $records[] = new ValorPayRecord($record);
                }
            }

            if (count($data ?? []) < self::PAGE_SIZE) {
                break;
            }
        }

        return $records;
    }

    /**
     * @param  list<string>  $alreadyDone  Error codes that mean the subscription is already in the wanted state.
     *
     * @throws ValorPayException
     */
    private function subscriptionAction(string $endpoint, string $txnType, string|int $subscriptionId, array $alreadyDone): void
    {
        $response = $this->post($endpoint, ['txn_type' => $txnType, 'subscription_id' => (string) $subscriptionId]);
        $code = $response->json('error_no');

        if ($code === 'S00' || in_array($code, $alreadyDone, true)) {
            return;
        }

        throw $this->refused("{$txnType} for subscription {$subscriptionId}", $response);
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ValorPayException
     */
    private function post(string $endpoint, array $body, ?int $timeout = null): Response
    {
        return $this->send('post', $endpoint, $body, $timeout);
    }

    /**
     * Sends a call with the credentials added. ValorPay answers refusals with HTTP 200 or 400 and an
     * error_no, so callers check the body; only a failed connection throws here.
     *
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $data
     *
     * @throws ValorPayException
     */
    private function send(string $method, string $endpoint, array $data, ?int $timeout = null): Response
    {
        $data = ['appid' => $this->credential('app_id'), 'appkey' => $this->credential('app_key'), 'epi' => $this->credential('epi')] + $data;
        $request = Http::acceptJson()->timeout($timeout ?? (int) ($this->config['timeout'] ?? 20));
        $url = rtrim((string) ($this->config['url'] ?? ''), '/').'/'.$endpoint;

        try {
            return $method === 'get'
                ? $request->get($url.'&'.http_build_query($data, '', '&', PHP_QUERY_RFC3986))
                : $request->asJson()->post($url, $data);
        } catch (ConnectionException $exception) {
            throw new ValorPayException('ValorPay could not be reached: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @throws ValorPayException
     */
    private function credential(string $key): string
    {
        $value = (string) ($this->config[$key] ?? '');

        if ($value === '') {
            throw new ValorPayException('ValorPay credentials are not configured (VALORPAY_APP_ID, VALORPAY_APP_KEY, VALORPAY_EPI).');
        }

        return $value;
    }

    private function refused(string $what, Response $response): ValorPayException
    {
        $code = $response->json('error_no');

        return new ValorPayException(
            "ValorPay refused {$what} (HTTP {$response->status()}): ".Str::limit($response->body(), 300),
            is_string($code) ? $code : null,
        );
    }
}
