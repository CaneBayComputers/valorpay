<?php

namespace CaneBayComputers\ValorPay\Tests\Feature;

use CaneBayComputers\ValorPay\Tests\TestCase;
use CaneBayComputers\ValorPay\ValorPayClient;

class ServiceProviderTest extends TestCase
{
    public function test_the_client_is_a_singleton_built_from_config(): void
    {
        $client = $this->app->make(ValorPayClient::class);

        $this->assertSame($client, $this->app->make(ValorPayClient::class));
        $this->assertSame($client, $this->app->make('valorpay'));
        $this->assertSame(self::EPI, $client->epi());
    }

    public function test_the_config_has_defaults(): void
    {
        $this->assertSame(1, config('valorpay.link_valid_days'));
        $this->assertSame(20, config('valorpay.timeout'));
        $this->assertSame(300, config('valorpay.webhook_tolerance_seconds'));
    }

    public function test_the_production_url_is_the_default(): void
    {
        $config = require __DIR__.'/../../config/valorpay.php';

        $this->assertSame('https://securelink-prod.valorpaytech.com:4430', $config['url']);
    }
}
