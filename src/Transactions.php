<?php

namespace CaneBayComputers\ValorPay;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The transactions ValorPay lists for an invoice, a subscription or a search, with the checks
 * that decide whether something was paid.
 *
 * @implements IteratorAggregate<int, ValorPayRecord>
 */
final class Transactions implements Countable, IteratorAggregate
{
    /**
     * @param  list<ValorPayRecord>  $records
     */
    public function __construct(private array $records) {}

    /**
     * @return list<ValorPayRecord>
     */
    public function all(): array
    {
        return $this->records;
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function first(): ?ValorPayRecord
    {
        return $this->records[0] ?? null;
    }

    /**
     * Approved sales that still stand (not voided, reversed or refunded).
     */
    public function standingSales(): self
    {
        return $this->filter(fn (ValorPayRecord $record): bool => $record->isStandingSale());
    }

    /**
     * Refunds, voids and reversals, and sales since undone.
     */
    public function reversals(): self
    {
        return $this->filter(fn (ValorPayRecord $record): bool => $record->isReversal());
    }

    /**
     * The standing sale for exactly this amount, if there is one. This is the "was it paid?" check:
     * a sale for any other amount is not a payment of this invoice, so look at it by hand.
     */
    public function paidSale(int $cents): ?ValorPayRecord
    {
        return $this->standingSales()->filter(fn (ValorPayRecord $record): bool => $record->amountInCents() === $cents)->first();
    }

    public function isPaid(int $cents): bool
    {
        return $this->paidSale($cents) !== null;
    }

    /**
     * Only the records made on one EPI (device). The transaction list covers every device on the merchant.
     */
    public function onEpi(string $epi): self
    {
        return $this->filter(fn (ValorPayRecord $record): bool => $record->epi() === $epi);
    }

    /**
     * @param  callable(ValorPayRecord): bool  $keep
     */
    public function filter(callable $keep): self
    {
        return new self(array_values(array_filter($this->records, $keep)));
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->records);
    }
}
