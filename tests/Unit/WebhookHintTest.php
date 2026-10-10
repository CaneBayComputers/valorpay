<?php

namespace CaneBayComputers\ValorPay\Tests\Unit;

use CaneBayComputers\ValorPay\Facades\ValorPay;
use CaneBayComputers\ValorPay\Tests\TestCase;
use CaneBayComputers\ValorPay\Webhooks\WebhookHint;
use Illuminate\Support\Facades\Http;

class WebhookHintTest extends TestCase
{
    public function test_a_sale_names_its_invoice_in_either_field(): void
    {
        $hint = WebhookHint::fromPayload([
            'event' => 'sale',
            'data' => [
                ['invoice_no' => 'INV1001', 'reference_descriptive_data' => ['invoicenumber' => 'INV1001']],
                ['reference_descriptive_data' => ['invoicenumber' => 'INV1002']],
                ['invoice_no' => 'OTHER1'],
            ],
        ], 'INV');

        $this->assertSame('sale', $hint->event);
        $this->assertSame(['INV1001', 'INV1002'], $hint->invoiceNumbers);
        $this->assertSame([], $hint->subscriptionIds);
        $this->assertFalse($hint->isEmpty());
    }

    public function test_without_a_prefix_every_invoice_counts(): void
    {
        $hint = WebhookHint::fromPayload(['data' => ['invoice_no' => 'OTHER1']]);

        $this->assertSame(['OTHER1'], $hint->invoiceNumbers);
    }

    public function test_recurring_events_name_their_subscription(): void
    {
        // Shapes from ValorPay's webhook guide, trimmed.
        $success = ['event' => 'RECURRING BILLING SUCCESS', 'data' => ['txn_id' => 223074843183, 'invoice_no' => '', 'amount' => 10000, 'response_code' => '00', 'subscription_id' => '114539', 'subscription_status' => 'Active']];
        $failed = ['event' => 'Recurring Failed', 'data' => ['message' => 'Recursive Payment of $708.63 is declined', 'subscriptionid' => '34880']];
        $added = ['event' => 'RECURRING ADDED', 'data' => ['subscription_id' => 21706, 'recurring_type' => 2]];
        $deleted = ['event' => 'Subcription Deleted', 'data' => [['subscription_id' => 31477, 'status' => 'Subcription Deleted']]];

        $this->assertSame(['114539'], WebhookHint::fromPayload($success)->subscriptionIds);
        $this->assertSame([], WebhookHint::fromPayload($success)->invoiceNumbers);
        $this->assertSame(['34880'], WebhookHint::fromPayload($failed)->subscriptionIds);
        $this->assertSame(['21706'], WebhookHint::fromPayload($added)->subscriptionIds);
        $this->assertSame(['31477'], WebhookHint::fromPayload($deleted)->subscriptionIds);
        $this->assertSame('Subcription Deleted', WebhookHint::fromPayload($deleted)->event);
    }

    public function test_junk_gives_an_empty_hint(): void
    {
        $this->assertTrue(WebhookHint::fromJson('not json')->isEmpty());
        $this->assertTrue(WebhookHint::fromJson('"a string"')->isEmpty());
        $this->assertTrue(WebhookHint::fromPayload(['event' => 'x', 'data' => 'nope'])->isEmpty());
        $this->assertTrue(WebhookHint::fromPayload(['data' => [['subscription_id' => 0, 'invoice_no' => ['x']]]])->isEmpty());
        $this->assertNull(WebhookHint::fromPayload(['event' => ['x']])->event);
    }

    public function test_the_client_reads_raw_bodies_and_payloads_without_calling_valorpay(): void
    {
        Http::fake();

        $this->assertSame(['INV1'], ValorPay::webhookHint('{"event":"sale","data":{"invoice_no":"INV1"}}', 'INV')->invoiceNumbers);
        $this->assertSame(['7'], ValorPay::webhookHint(['data' => ['subscriptionid' => 7]])->subscriptionIds);
        Http::assertNothingSent();
    }
}
