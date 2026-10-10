<?php

namespace CaneBayComputers\ValorPay;

use RuntimeException;
use Throwable;

/**
 * ValorPay couldn't be reached, refused a request, or isn't configured.
 *
 * $errorNo is ValorPay's own code when it sent one ("D07" bad app key, "E31" no
 * subscription, ...), so callers can tell refusals apart.
 */
class ValorPayException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorNo = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
