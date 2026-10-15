<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Models\Customer;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class CustomerRegistryTest extends TestCase
{
    /** @var list<string> */
    private array $publishedMigrations = [];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->publishedMigrations as $migration) {
            if (is_file($migration)) {
                unlink($migration);
            }
        }
    }

    public function test_a_billable_model_can_look_up_and_create_its_customer_id(): void
    {
        $this->migrateCustomers();
        $billable = $this->billable(TestBillableModel::class, 101);

        $this->assertFalse($billable->hasPortOneId());
        $this->assertNull($billable->portOneId());

        $customerId = $billable->createOrGetPortOneCustomerId();

        $this->assertNotSame('', $customerId);
        $this->assertTrue($billable->hasPortOneId());
        $this->assertSame($customerId, $billable->portOneId());
        $this->assertSame($customerId, $billable->createOrGetPortOneCustomerId());
        $this->assertSame($customerId, $this->billable(TestBillableModel::class, 101)->portOneId());
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_customer_ids_are_unique_and_billable_model_kind_is_part_of_the_reference(): void
    {
        $this->migrateCustomers();
        $userId = $this->billable(TestBillableModel::class, 101)->createOrGetPortOneCustomerId();
        $otherModelId = $this->billable(OtherBillableModel::class, 101)->createOrGetPortOneCustomerId();

        $this->assertNotSame($userId, $otherModelId);
        $this->assertSame(2, Customer::query()->count());
        $this->assertSame(2, Customer::query()->distinct()->count('customer_id'));
    }

    public function test_concurrent_ensure_calls_converge_on_the_winning_customer_record(): void
    {
        $billable = $this->billable(TestBillableModel::class, 101);
        $winnerId = 'winner-'.bin2hex(random_bytes(8));
        $interleaved = false;
        $databasePath = sys_get_temp_dir().'/portone-customer-race-'.bin2hex(random_bytes(8)).'.sqlite';
        config(['database.connections.testing.database' => $databasePath]);
        config(['database.connections.customer_race' => [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge();
        DB::purge('customer_race');
        $this->migrateCustomers();

        Customer::creating(function (Customer $customer) use (&$interleaved, $winnerId): void {
            if ($interleaved) {
                return;
            }

            $interleaved = true;

            DB::connection('customer_race')->table('portone_customers')->insert([
                'billable_type' => $customer->getAttribute('billable_type'),
                'billable_id' => $customer->getAttribute('billable_id'),
                'customer_id' => $winnerId,
            ]);
        });

        try {
            $this->assertSame($winnerId, $billable->createOrGetPortOneCustomerId());
            $this->assertSame(1, Customer::query()->count());
        } finally {
            Customer::flushEventListeners();
            DB::purge('customer_race');
            DB::purge();
            unlink($databasePath);
        }
    }

    public function test_published_customer_migration_can_be_applied_to_the_application(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'portone-migrations',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $paths = glob($this->app->databasePath('migrations/*_portone_*_table.php'));
        $this->assertIsArray($paths);
        $this->assertCount(3, $paths);
        array_push($this->publishedMigrations, ...$paths);
        $customerMigrations = glob($this->app->databasePath('migrations/*_create_portone_customers_table.php'));
        $this->assertIsArray($customerMigrations);
        $this->assertCount(1, $customerMigrations);
        $migration = $customerMigrations[0];

        $this->artisan('migrate', [
            '--path' => $migration,
            '--realpath' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('portone_customers'));
        $this->assertTrue(Schema::hasColumns('portone_customers', [
            'billable_type',
            'billable_id',
            'customer_id',
        ]));
    }

    private function migrateCustomers(): void
    {
        $this->artisan('migrate', [
            '--path' => dirname(__DIR__, 2).'/database/migrations/2026_10_13_000000_create_portone_customers_table.php',
            '--realpath' => true,
            '--force' => true,
        ])->assertExitCode(0);
    }

    /**
     * @param  class-string<TestBillableModel|OtherBillableModel>  $model
     */
    private function billable(string $model, int $key): TestBillableModel|OtherBillableModel
    {
        $billable = new $model;
        $billable->setRawAttributes(['id' => $key]);

        return $billable;
    }
}

class OtherBillableModel extends Model
{
    use Billable;
}

class TestBillableModel extends Model
{
    use Billable;
}
