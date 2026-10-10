<?php

namespace CaneBayComputers\ValorPay\Tests\Unit;

use CaneBayComputers\ValorPay\Fields;
use CaneBayComputers\ValorPay\ValorPayRecord;
use PHPUnit\Framework\TestCase;

class ValorPayRecordTest extends TestCase
{
    public function test_an_approved_sale(): void
    {
        $record = new ValorPayRecord([
            'REF_TXN_ID' => ' 1001 ', 'EPI' => '2000000001', 'INVOICE_NO' => 'INV1', 'TXN_TYPE' => 'sale',
            'RESPONSE_CODE' => '00', 'AUTH_CODE' => 'TEST01', 'BASE_AMOUNT' => '10000', 'EMAIL' => 'payer@example.com',
            'TOKEN' => 'ABC', 'SUBSCRIPTION_ID' => '0', 'MASKED_CARD_NO' => 'XXXX1111',
        ]);

        $this->assertSame('1001', $record->id());
        $this->assertSame('SALE', $record->type());
        $this->assertSame('TEST01', $record->approvalCode());
        $this->assertTrue($record->isApproved());
        $this->assertTrue($record->isStandingSale());
        $this->assertFalse($record->isReversal());
        $this->assertSame(10000, $record->amountInCents());
        $this->assertSame('SALE 1001 ($100.00)', $record->describe());
        $this->assertSame('payer@example.com', $record->payerEmail());
        $this->assertSame('ABC', $record->token());
        $this->assertNull($record->subscriptionId());
        $this->assertSame('XXXX1111', $record->get('MASKED_CARD_NO'));
    }

    public function test_a_decline_is_not_approved(): void
    {
        $record = new ValorPayRecord(['TXN_TYPE' => 'SALE', 'RESPONSE_CODE' => '05', 'APPROVAL_CODE' => '', 'EMAIL' => 'not an email', 'TOKEN' => '']);

        $this->assertFalse($record->isApproved());
        $this->assertFalse($record->isStandingSale());
        $this->assertNull($record->payerEmail());
        $this->assertNull($record->token());
    }

    public function test_undone_sales_and_refunds_are_reversals(): void
    {
        $approved = ['TXN_TYPE' => 'SALE', 'RESPONSE_CODE' => '00', 'APPROVAL_CODE' => 'X'];

        foreach ([['IS_VOID' => '1'], ['IS_REVERSAL' => '1'], ['REFUND_AMOUNT' => '500']] as $undo) {
            $record = new ValorPayRecord($approved + $undo);
            $this->assertFalse($record->isStandingSale());
            $this->assertTrue($record->isReversal());
        }

        $this->assertTrue((new ValorPayRecord(['TXN_TYPE' => 'REFUND'] + $approved))->isReversal());
        $this->assertFalse((new ValorPayRecord(['TXN_TYPE' => 'REFUND', 'RESPONSE_CODE' => '05']))->isReversal());
    }

    public function test_an_unreadable_amount(): void
    {
        $record = new ValorPayRecord(['BASE_AMOUNT' => '12.50']);

        $this->assertNull($record->amountInCents());
        $this->assertSame('an unreadable amount', $record->amountForDisplay());
    }

    public function test_hosted_page_customer_names(): void
    {
        $this->assertSame('Acme Co #2 @ Main', Fields::customerName("Acme   Co. #2 @ Main!"));
        $this->assertSame('Customer', Fields::customerName('!!!'));
        $this->assertSame(100, strlen(Fields::customerName(str_repeat('a', 150))));
    }

    public function test_dollars(): void
    {
        $this->assertSame('0.05', Fields::dollars(5));
        $this->assertSame('25.00', Fields::dollars(2500));
        $this->assertSame('99999.99', Fields::dollars(9_999_999));
    }
}
