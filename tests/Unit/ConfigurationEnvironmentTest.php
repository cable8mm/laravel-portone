<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Orchestra\Testbench\TestCase;

class ConfigurationEnvironmentTest extends TestCase
{
    /** @var array<string, array{env: string|false, server: string|false, process: string|false}> */
    private array $originalEnvironment = [];

    /** @var array<string, string> */
    private array $testEnvironment = [];

    protected function setUp(): void
    {
        $this->testEnvironment = [
            'PORTONE_API_SECRET' => 'test-'.bin2hex(random_bytes(16)),
            'PORTONE_WEBHOOK_SECRET' => 'test-'.bin2hex(random_bytes(16)),
            'PORTONE_BASE_URL' => 'https://portone.test.invalid/api',
            'PORTONE_WEBHOOK_PATH' => 'test-portone/webhook',
            'PORTONE_STORE_ID' => 'test-store-'.bin2hex(random_bytes(8)),
            'PORTONE_CHANNEL_KEY' => 'test-channel-'.bin2hex(random_bytes(8)),
        ];

        foreach ($this->testEnvironment as $name => $value) {
            $this->originalEnvironment[$name] = [
                'env' => $_ENV[$name] ?? false,
                'server' => $_SERVER[$name] ?? false,
                'process' => getenv($name),
            ];
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->originalEnvironment as $name => $original) {
            if ($original['env'] === false) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $original['env'];
            }

            if ($original['server'] === false) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $original['server'];
            }

            putenv($original['process'] === false ? $name : $name.'='.$original['process']);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    public function test_environment_values_are_merged_under_the_portone_configuration_key(): void
    {
        $this->assertSame($this->testEnvironment['PORTONE_API_SECRET'], config('portone.api_secret'));
        $this->assertSame($this->testEnvironment['PORTONE_WEBHOOK_SECRET'], config('portone.webhook_secret'));
        $this->assertSame($this->testEnvironment['PORTONE_BASE_URL'], config('portone.base_url'));
        $this->assertSame($this->testEnvironment['PORTONE_WEBHOOK_PATH'], config('portone.webhook_path'));
        $this->assertSame($this->testEnvironment['PORTONE_STORE_ID'], config('portone.store_id'));
        $this->assertSame($this->testEnvironment['PORTONE_CHANNEL_KEY'], config('portone.channel_key'));
    }
}
