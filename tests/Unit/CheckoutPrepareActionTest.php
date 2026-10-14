<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class CheckoutPrepareActionTest extends TestCase
{
    /** @var list<string> */
    private array $publishedFiles = [];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function tearDown(): void
    {
        Payment::flushEventListeners();
        parent::tearDown();

        foreach ($this->publishedFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_unauthenticated_prepare_request_does_not_create_a_payment(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        Route::get('/login', static fn () => response('login'))->name('login');

        $this->post(route('portone.checkout.prepare'), [
            'amount' => 10000,
            'orderName' => 'Order A',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_prepare_uses_the_looked_up_product_and_returns_checkout_parameters(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        config(['portone.api_secret' => null]);
        $billable = new CheckoutBillableUser;
        $billable->setRawAttributes(['id' => 101]);
        $this->actingAs($billable);

        $response = $this->post(route('portone.checkout.prepare'), [
            'product' => 1,
            'amount' => 1,
            'orderName' => 'Untrusted request value',
        ]);

        $response->assertOk();
        $response->assertExactJson([
            'paymentId' => Payment::query()->sole()->payment_id,
            'orderName' => 'Order A',
            'totalAmount' => 10000,
            'customerId' => $billable->portOneId(),
        ]);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('PENDING', Payment::query()->sole()->status);
        $this->assertSame('Order A', Payment::query()->sole()->order_name);
        $this->assertSame(10000, Payment::query()->sole()->amount);
        $this->assertSame($billable->getMorphClass(), Payment::query()->sole()->billable_type);
        $this->assertSame('101', Payment::query()->sole()->billable_id);
    }

    public function test_prepare_failure_propagates_without_wrapping(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $billable = new CheckoutBillableUser;
        $billable->setRawAttributes(['id' => 101]);
        $this->actingAs($billable);
        Payment::creating(static function (): never {
            throw new \RuntimeException('Simulated preparation failure.');
        });
        $this->withoutExceptionHandling();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Simulated preparation failure.');

        $this->post(route('portone.checkout.prepare'), [
            'amount' => 10000,
            'orderName' => 'Order A',
        ]);
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

    private function publishAndIncludeRoutes(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'portone-stubs',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        foreach ([
            'resources/views/portone/checkout.blade.php',
            'resources/views/portone/completed.blade.php',
            'resources/js/portone-checkout.js',
            'app/Http/Controllers/PortOneCheckoutController.php',
            'routes/portone.php',
        ] as $path) {
            $file = $this->app->basePath($path);
            $this->publishedFiles[] = $file;
        }

        require_once $this->app->basePath('app/Http/Controllers/PortOneCheckoutController.php');
        require $this->app->basePath('routes/portone.php');
    }
}

class CheckoutBillableUser extends Authenticatable
{
    use Billable;
}
