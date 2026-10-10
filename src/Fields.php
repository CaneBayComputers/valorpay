<?php

namespace CaneBayComputers\ValorPay;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Formats values the way ValorPay's field rules want them.
 */
final class Fields
{
    /**
     * ValorPay accepts only letters, digits, spaces, # and @ in a hosted page's customer_name, up to 100 characters.
     */
    public static function customerName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 #@]/', ' ', $name)));

        return Str::limit($name, 100, '') ?: 'Customer';
    }

    /**
     * Subscription billing and shipping names: letters and spaces only, up to 25 characters.
     */
    public static function personName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z ]/', ' ', Str::ascii($name))));

        return rtrim(Str::limit($name, 25, '')) ?: 'Customer';
    }

    /**
     * Dollars with two decimals, as ValorPay's subscription calls take them ("25.00").
     */
    public static function dollars(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * A five-digit US ZIP code, from "29486" or "29486-1234".
     *
     * @throws InvalidArgumentException
     */
    public static function zip(string $zip): string
    {
        if (preg_match('/^\s*(\d{5})(?:-?\d{4})?\s*$/', $zip, $match) !== 1) {
            throw new InvalidArgumentException("ValorPay needs a 5-digit US ZIP code, got \"{$zip}\".");
        }

        return $match[1];
    }

    /**
     * Invoice numbers on subscriptions: letters and digits only, up to 12 characters. They are what
     * ties a charge back to your records, so a bad one is an error rather than silently changed.
     *
     * @throws InvalidArgumentException
     */
    public static function invoiceNumber(string $invoice): string
    {
        if ($invoice !== '' && preg_match('/^[A-Za-z0-9]{1,12}$/', $invoice) !== 1) {
            throw new InvalidArgumentException("ValorPay invoice numbers are up to 12 letters and digits, got \"{$invoice}\".");
        }

        return $invoice;
    }
}
