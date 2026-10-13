<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Orchestra\Testbench\TestCase;

class FoundationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    public function test_default_webhook_path_is_available_from_merged_configuration(): void
    {
        $this->assertSame('portone/webhook', config('portone.webhook_path'));
    }
}
