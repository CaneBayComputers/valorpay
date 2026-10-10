<?php

namespace CaneBayComputers\ValorPay;

/**
 * What ValorPay says about a stored card token: never the card number, only its masked form.
 */
final readonly class CardToken
{
    public function __construct(
        public string $token,
        public string $maskedPan,
        public string $brand,
        public string $cardType,
        public string $cardholderName,
    ) {}
}
