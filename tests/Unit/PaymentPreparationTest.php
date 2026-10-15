<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Models\Customer;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class PaymentPreparationTest extends TestCase
{
    /** @var list<string> */
    private array $publishedMigrations = [];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function tearDown(): void
    {
        Payment::flushEventListeners();
        parent::tearDown();

        foreach ($this->publishedMigrations as $migration) {
            if (is_file($migration)) {
                unlink($migration);
            }
        }
    }

    public function test_prepare_creates_pending_payment_with_checkout_parameters_and_customer(): void
    {
        $this->migratePackageTables();
        config(['portone.api_secret' => null]);
        $billable = $this->billable(TestPaymentBillableModel::class, 101);

        $payment = $billable->preparePayment(10000, 'Order A');

        $this->assertSame('PENDING', $payment->status);
        $this->assertSame(10000, $payment->amount);
        $this->assertSame('Order A', $payment->order_name);
        $this->assertSame(0, $payment->cancelled_amount);
        $this->assertNull($payment->paid_at);
        $this->assertNotSame('', $payment->payment_id);
        $this->assertSame($billable->portOneId(), $payment->customer_id);
        $this->assertTrue($billable->hasPortOneId());
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $persistedPayment = Payment::query()->findOrFail($payment->getKey());
        $this->assertSame($payment->payment_id, $persistedPayment->payment_id);
        $this->assertSame($payment->customer_id, $persistedPayment->customer_id);
        $this->assertSame([
            'paymentId' => $payment->payment_id,
            'orderName' => 'Order A',
            'totalAmount' => 10000,
            'customerId' => $payment->customer_id,
        ], $payment->checkoutParameters());
    }

    public function test_prepare_generates_a_unique_payment_id_for_every_call(): void
    {
        $this->migratePackageTables();
        $billable = $this->billable(TestPaymentBillableModel::class, 101);

        $first = $billable->preparePayment(10000, 'Order A');
        $second = $billable->preparePayment(10000, 'Order A');

        $this->assertNotSame($first->payment_id, $second->payment_id);
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_invalid_amount_or_order_name_creates_no_records(): void
    {
        $this->migratePackageTables();
        $billable = $this->billable(TestPaymentBillableModel::class, 101);

        try {
            $billable->preparePayment(0, 'Order A');
            $this->fail('Expected an invalid amount to be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, Customer::query()->count());
            $this->assertSame(0, Payment::query()->count());
        }

        try {
            $billable->preparePayment(-1, 'Order A');
            $this->fail('Expected a negative amount to be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, Customer::query()->count());
            $this->assertSame(0, Payment::query()->count());
        }

        try {
            $billable->preparePayment(10000, '');
            $this->fail('Expected an empty order name to be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, Customer::query()->count());
            $this->assertSame(0, Payment::query()->count());
        }
    }

    public function test_payment_creation_failure_rolls_back_the_customer_created_by_preparation(): void
    {
        $this->migratePackageTables();
        $billable = $this->billable(TestPaymentBillableModel::class, 101);
        Payment::creating(static function (): never {
            throw new \RuntimeException('Simulated local payment persistence failure.');
        });

        try {
            $billable->preparePayment(10000, 'Order A');
            $this->fail('Expected payment persistence to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated local payment persistence failure.', $exception->getMessage());
            $this->assertSame(0, Customer::query()->count());
            $this->assertSame(0, Payment::query()->count());
        }
    }

    public function test_published_package_migrations_create_working_customer_and_payment_tables(): void
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

        foreach ($paths as $migration) {
            $this->artisan('migrate', [
                '--path' => $migration,
                '--realpath' => true,
                '--force' => true,
            ])->assertExitCode(0);
        }

        $this->assertTrue(Schema::hasTable('portone_customers'));
        $this->assertTrue(Schema::hasTable('portone_payments'));
        $this->assertTrue(Schema::hasColumns('portone_payments', [
            'payment_id',
            'billable_type',
            'billable_id',
            'customer_id',
            'order_name',
            'amount',
            'status',
            'cancelled_amount',
            'paid_amount',
            'paid_at',
            'created_at',
            'updated_at',
        ]));
    }

    private function migratePackageTables(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', [
                '--path' => $migration,
                '--realpath' => true,
                '--force' => true,
            ])->assertExitCode(0);
        }
    }

    /**
     * @param  class-string<TestPaymentBillableModel>  $model
     */
    private function billable(string $model, int $key): TestPaymentBillableModel
    {
        $billable = new $model;
        $billable->setRawAttributes(['id' => $key]);

        return $billable;
    }
}

class TestPaymentBillableModel extends Model
{
    use Billable;
}
