<?php

namespace CaneBayComputers\ValorPay;

/**
 * A ValorPay-hosted payment page. Send the customer to $url; keep $uid to cancel the page later.
 */
final readonly class HostedPage
{
    public function __construct(public string $url, public string $uid) {}
}
