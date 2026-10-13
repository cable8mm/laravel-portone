<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Illuminate\Support\ServiceProvider;

class PortOneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/portone.php', 'portone');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/portone.php' => $this->app->configPath('portone.php'),
            ], 'portone-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations/2026_10_13_000000_create_portone_customers_table.php' => $this->app->databasePath('migrations/2026_10_13_000000_create_portone_customers_table.php'),
                __DIR__.'/../database/migrations/2026_10_13_000001_create_portone_payments_table.php' => $this->app->databasePath('migrations/2026_10_13_000001_create_portone_payments_table.php'),
            ], 'portone-migrations');

            $this->publishes([
                __DIR__.'/../stubs/resources/views/portone/checkout.blade.php' => $this->app->resourcePath('views/portone/checkout.blade.php'),
                __DIR__.'/../stubs/resources/js/portone-checkout.js' => $this->app->resourcePath('js/portone-checkout.js'),
                __DIR__.'/../stubs/app/Http/Controllers/PortOneCheckoutController.php' => $this->app->path('Http/Controllers/PortOneCheckoutController.php'),
                __DIR__.'/../stubs/routes/portone.php' => $this->app->basePath('routes/portone.php'),
            ], 'portone-stubs');
        }
    }
}
