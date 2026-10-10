<?php

namespace CaneBayComputers\ValorPay\Facades;

use CaneBayComputers\ValorPay\CardToken;
use CaneBayComputers\ValorPay\HostedPage;
use CaneBayComputers\ValorPay\Subscriptions\Subscription;
use CaneBayComputers\ValorPay\Subscriptions\SubscriptionRequest;
use CaneBayComputers\ValorPay\Transactions;
use CaneBayComputers\ValorPay\ValorPayClient;
use CaneBayComputers\ValorPay\Webhooks\WebhookHint;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string epi()
 * @method static HostedPage createHostedPage(int $cents, string $invoiceNumber, string $description, string $successUrl, string $failureUrl, string $customerName = '', ?int $validDays = null, array $extra = [])
 * @method static bool cancelHostedPage(string $uid)
 * @method static Transactions transactionsForInvoice(string $invoiceNumber, CarbonInterface $since)
 * @method static Transactions transactionsForSubscription(string|int $subscriptionId, CarbonInterface $since)
 * @method static Transactions transactions(string $filter, string $text, CarbonInterface $since, ?CarbonInterface $until = null)
 * @method static CardToken cardToken(string $token)
 * @method static string createSubscription(SubscriptionRequest $request)
 * @method static void updateSubscription(string|int $subscriptionId, SubscriptionRequest $request)
 * @method static void deactivateSubscription(string|int $subscriptionId)
 * @method static void activateSubscription(string|int $subscriptionId)
 * @method static void deleteSubscription(string|int $subscriptionId)
 * @method static list<Subscription> subscriptions(bool $active = true, ?CarbonInterface $startedSince = null, string|int|null $subscriptionId = null)
 * @method static Subscription|null subscription(string|int $subscriptionId)
 * @method static WebhookHint webhookHint(array|string $payload, string $invoicePrefix = '')
 *
 * @see ValorPayClient
 */
class ValorPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ValorPayClient::class;
    }
}
