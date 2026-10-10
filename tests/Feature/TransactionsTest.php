<?php

namespace CaneBayComputers\ValorPay\Tests\Feature;

use CaneBayComputers\ValorPay\Facades\ValorPay;
use CaneBayComputers\ValorPay\Tests\TestCase;
use CaneBayComputers\ValorPay\ValorPayException;
use CaneBayComputers\ValorPay\ValorPayRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class TransactionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 2026-10-09 02:00 UTC is still 2026-10-08 in New York.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 02:00:00', 'UTC'));
    }

    public function test_it_lists_an_invoices_transactions_with_exact_matches_on_this_epi_only(): void
    {
        $this->fakeList([
            $this->sale(['REF_TXN_ID' => '1001']),
            $this->sale(['REF_TXN_ID' => '1002', 'INVOICE_NO' => 'INV10011']),
            $this->sale(['REF_TXN_ID' => '1003', 'EPI' => '2000000099']),
            $this->sale(['REF_TXN_ID' => '1004', 'TXN_TYPE' => 'REFUND']),
        ]);

        $transactions = ValorPay::transactionsForInvoice('INV1001', CarbonImmutable::parse('2026-10-07 15:00:00', 'UTC'));

        $this->assertSame(['1001', '1004'], array_map(fn (ValorPayRecord $record): string => $record->id(), $transactions->all()));

        $request = $this->onlyRequest();
        $this->assertSame('https://valorpay.test:4430/?txn_list', $request->url());
        $this->assertSame([
            'txn_type' => 'txnfetch',
            'date_filter' => 7,
            'start_date_range' => '2026-10-06',
            'end_date_range' => '2026-10-09',
            'filter' => 'VT_INVOICE',
            'filter_text' => 'INV1001',
            'transaction_status' => 'ALL',
            'limit' => 100,
            'offset' => 0,
            'version' => 2,
        ], $this->bodyWithoutCredentials($request));
    }

    public function test_is_paid_needs_a_standing_sale_for_exactly_the_amount(): void
    {
        $this->fakeList([
            $this->sale(['REF_TXN_ID' => '1', 'RESPONSE_CODE' => '05', 'APPROVAL_CODE' => '']),
            $this->sale(['REF_TXN_ID' => '2', 'BASE_AMOUNT' => '2400']),
            $this->sale(['REF_TXN_ID' => '3', 'IS_VOID' => '1']),
        ]);

        $transactions = ValorPay::transactionsForInvoice('INV1001', now());

        $this->assertFalse($transactions->isPaid(2500));
        $this->assertNull($transactions->paidSale(2500));
        $this->assertSame('2', $transactions->paidSale(2400)?->id());
        $this->assertSame(['2'], array_map(fn ($r) => $r->id(), $transactions->standingSales()->all()));
        $this->assertSame(['3'], array_map(fn ($r) => $r->id(), $transactions->reversals()->all()));
    }

    public function test_an_approved_standing_sale_for_the_amount_is_paid(): void
    {
        $this->fakeList([$this->sale()]);

        $transactions = ValorPay::transactionsForInvoice('INV1001', now());

        $this->assertTrue($transactions->isPaid(2500));
        $this->assertSame('5F8E3B0A6C1D4E7F9A2B5C8D1E4F7A0B3C6D9E2F', $transactions->paidSale(2500)->token());
        $this->assertCount(1, $transactions);
    }

    public function test_no_data_is_an_empty_list(): void
    {
        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'data' => null])]);

        $this->assertTrue(ValorPay::transactionsForInvoice('INV1001', now())->isEmpty());
    }

    public function test_every_page_is_fetched(): void
    {
        $full = array_map(fn (int $i): array => $this->sale(['REF_TXN_ID' => (string) $i]), range(1, 100));
        Http::fakeSequence()
            ->push(['status' => 'SUCCESS', 'data' => $full])
            ->push(['status' => 'SUCCESS', 'data' => [$this->sale(['REF_TXN_ID' => '101'])]]);

        $this->assertCount(101, ValorPay::transactionsForInvoice('INV1001', now()));

        $offsets = array_map(fn (array $pair): int => $pair[0]->data()['offset'], Http::recorded()->all());
        $this->assertSame([0, 100], $offsets);
    }

    public function test_ranges_over_90_days_are_split_newest_first(): void
    {
        $this->fakeList([]);

        ValorPay::transactions('VT_INVOICE', 'INV', CarbonImmutable::parse('2026-05-01', 'America/New_York'));

        $ranges = array_map(fn (array $pair): string => $pair[0]->data()['start_date_range'].'..'.$pair[0]->data()['end_date_range'], Http::recorded()->all());
        $this->assertSame(['2026-07-12..2026-10-09', '2026-04-30..2026-07-11'], $ranges);
    }

    public function test_it_lists_a_subscriptions_charges_by_subscription_id(): void
    {
        $this->fakeList([
            $this->sale(['REF_TXN_ID' => '1', 'INVOICE_NO' => '', 'SUBSCRIPTION_ID' => '54670']),
            $this->sale(['REF_TXN_ID' => '2', 'INVOICE_NO' => '', 'SUBSCRIPTION_ID' => '546701']),
        ]);

        $transactions = ValorPay::transactionsForSubscription(54670, now()->subMonth());

        $this->assertSame(['1'], array_map(fn ($r) => $r->id(), $transactions->all()));
        $this->assertSame('54670', $transactions->first()->subscriptionId());
        Http::assertSent(fn (Request $request): bool => $request['filter'] === 'SUBSCRIPTION_ID' && $request['filter_text'] === '54670');
    }

    public function test_a_refused_lookup_throws(): void
    {
        Http::fake(['*' => Http::response(['status' => 'FAILED', 'error_no' => 'D07', 'desc' => 'NOT A VALID APP KEY'], 400)]);

        $this->expectException(ValorPayException::class);
        $this->expectExceptionMessage('ValorPay refused the transaction lookup (HTTP 400)');

        ValorPay::transactionsForInvoice('INV1001', now());
    }

    public function test_an_unreachable_lookup_throws(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(ValorPayException::class);

        ValorPay::transactionsForSubscription('54670', now());
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function fakeList(array $records): void
    {
        Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'data' => $records, 'total_results' => count($records)])]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sale(array $overrides = []): array
    {
        return array_merge([
            'REF_TXN_ID' => '1001',
            'EPI' => self::EPI,
            'INVOICE_NO' => 'INV1001',
            'TXN_TYPE' => 'SALE',
            'RESPONSE_CODE' => '00',
            'APPROVAL_CODE' => 'TEST01',
            'BASE_AMOUNT' => '2500',
            'IS_VOID' => '0',
            'IS_REVERSAL' => '0',
            'REFUND_AMOUNT' => '0',
            'EMAIL' => 'payer@example.com',
            'TOKEN' => '5F8E3B0A6C1D4E7F9A2B5C8D1E4F7A0B3C6D9E2F',
            'SUBSCRIPTION_ID' => '0',
        ], $overrides);
    }
}
