<?php

namespace CaneBayComputers\ValorPay;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class ValorPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/valorpay.php', 'valorpay');

        $this->app->singleton(ValorPayClient::class, fn (Application $app): ValorPayClient => new ValorPayClient((array) $app['config']->get('valorpay', [])));
        $this->app->alias(ValorPayClient::class, 'valorpay');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/valorpay.php' => config_path('valorpay.php')], 'valorpay-config');
        }
    }
}
