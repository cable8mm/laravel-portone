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
        }
    }
}
