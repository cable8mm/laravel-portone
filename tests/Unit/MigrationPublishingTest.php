<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * T-026: an Application that published earlier migrations receives only the missing ones.
 */
class MigrationPublishingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function tearDown(): void
    {
        foreach ($this->publishedPortOneMigrations() as $path) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function test_republishing_copies_only_missing_migrations(): void
    {
        // Laravel renamed these with the publish date when the Application published v0.2.
        file_put_contents($this->app->databasePath('migrations/2026_01_02_030405_create_portone_customers_table.php'), '<?php');
        file_put_contents($this->app->databasePath('migrations/2026_01_02_030406_create_portone_payments_table.php'), '<?php');

        $this->publishMigrations();

        $names = $this->publishedNames();
        $this->assertSame([
            'add_paid_amount_to_portone_payments_table.php',
            'backfill_paid_amount_on_portone_payments_table.php',
            'create_portone_customers_table.php',
            'create_portone_payments_table.php',
        ], $names);
        $this->assertFileExists($this->app->databasePath('migrations/2026_01_02_030405_create_portone_customers_table.php'));

        $this->publishMigrations();

        $this->assertSame($names, $this->publishedNames());
    }

    public function test_a_fresh_application_receives_every_migration_once(): void
    {
        $this->publishMigrations();
        $this->publishMigrations();

        $this->assertCount(4, $this->publishedPortOneMigrations());
    }

    /**
     * Each artisan process boots the provider again; reset the static publish lists to do the same here.
     */
    private function publishMigrations(): void
    {
        \Closure::bind(static function (): void {
            ServiceProvider::$publishes = [];
            ServiceProvider::$publishGroups = [];
            ServiceProvider::$publishableMigrationPaths = [];
        }, null, ServiceProvider::class)();
        $this->app->register(PortOneServiceProvider::class, true);

        $this->artisan('vendor:publish', ['--tag' => 'portone-migrations', '--no-interaction' => true])->assertExitCode(0);
    }

    /**
     * @return list<string>
     */
    private function publishedNames(): array
    {
        $names = array_map(
            static fn (string $path): string => substr(basename($path), strlen('2026_01_02_030405_')),
            $this->publishedPortOneMigrations(),
        );
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function publishedPortOneMigrations(): array
    {
        return glob($this->app->databasePath('migrations/*_portone_*_table.php')) ?: [];
    }
}
