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
        $this->assertNull(config('portone.base_url'));
        $this->assertNull(config('portone.store_id'));
        $this->assertNull(config('portone.channel_key'));
        $this->assertSame(1440, config('portone.pending_expire_after'));
        $this->assertSame(90, config('portone.expired_retention_days'));
    }
}
