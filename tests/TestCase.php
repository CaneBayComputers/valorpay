<?php

namespace CaneBayComputers\ValorPay\Tests;

use CaneBayComputers\ValorPay\ValorPayServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    // Obvious fakes. Real credentials never belong in this repo.
    public const APP_ID = 'test-app-id';

    public const APP_KEY = 'test-app-key$#%';

    public const EPI = '2000000001';

    protected function getPackageProviders($app): array
    {
        return [ValorPayServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['ValorPay' => \CaneBayComputers\ValorPay\Facades\ValorPay::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('valorpay.url', 'https://valorpay.test:4430');
        $app['config']->set('valorpay.app_id', self::APP_ID);
        $app['config']->set('valorpay.app_key', self::APP_KEY);
        $app['config']->set('valorpay.epi', self::EPI);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Asserts that exactly one request was sent and returns it.
     */
    protected function onlyRequest(): Request
    {
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);

        return $recorded[0][0];
    }

    /**
     * Asserts the credentials were sent, and returns the body without them.
     *
     * @return array<string, mixed>
     */
    protected function bodyWithoutCredentials(Request $request): array
    {
        $body = $request->data();
        $this->assertSame(self::APP_ID, $body['appid']);
        $this->assertSame(self::APP_KEY, $body['appkey']);
        $this->assertSame(self::EPI, $body['epi']);
        unset($body['appid'], $body['appkey'], $body['epi']);

        return $body;
    }
}
