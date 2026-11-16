<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Blade and the React stubs publish the same controller class, so each test loads it in its own process.
 */
#[RunTestsInSeparateProcesses]
class ReactCheckoutStubTest extends TestCase
{
    private const PUBLISHED = [
        'resources/js/pages/portone/checkout.tsx',
        'resources/js/pages/portone/completed.tsx',
        'app/Http/Controllers/PortOneCheckoutController.php',
        'routes/portone.php',
    ];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class, InertiaServiceProvider::class];
    }

    protected function tearDown(): void
    {
        $paths = array_map(fn (string $path): string => $this->app->basePath($path), self::PUBLISHED);

        parent::tearDown();

        foreach ($paths as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_react_stubs_are_published_to_the_application_paths(): void
    {
        $this->publishStubs();

        foreach (self::PUBLISHED as $path) {
            $this->assertFileExists($this->app->basePath($path));
        }
        $root = dirname(__DIR__, 2);
        $this->assertFileEquals($root.'/stubs/routes/portone.php', $this->app->basePath('routes/portone.php'));
        $this->assertFileEquals(
            $root.'/stubs/react/app/Http/Controllers/PortOneCheckoutController.php',
            $this->app->basePath('app/Http/Controllers/PortOneCheckoutController.php'),
        );
        $this->assertFileDoesNotExist($this->app->resourcePath('views/portone/checkout.blade.php'));
        $this->assertFileDoesNotExist($this->app->resourcePath('js/portone-checkout.js'));
        $this->assertFalse(Route::has('portone.checkout'));
    }

    public function test_checkout_renders_the_inertia_page_with_the_blade_view_values(): void
    {
        $this->publishAndIncludeRoutes();
        config(['portone.store_id' => 'store-123', 'portone.channel_key' => 'channel-456']);
        $this->actingAs($this->billable(101));

        $this->inertia(route('portone.checkout'))
            ->assertJsonPath('component', 'portone/checkout')
            ->assertJsonPath('props.product', ['id' => 1, 'name' => 'Order A', 'price' => 10000])
            ->assertJsonPath('props.storeId', 'store-123')
            ->assertJsonPath('props.channelKey', 'channel-456')
            ->assertJsonPath('props.prepareUrl', route('portone.checkout.prepare'))
            ->assertJsonPath('props.csrfToken', csrf_token())
            ->assertJsonPath('props.missingConfiguration', false)
            ->assertJsonPath('props.state', 'initial')
            ->assertJsonPath('props.statusText', 'Idle');
    }

    public function test_missing_configuration_renders_the_error_state(): void
    {
        $this->publishAndIncludeRoutes();
        $this->actingAs($this->billable(101));

        foreach ([
            ['store_id' => null, 'channel_key' => 'channel-456'],
            ['store_id' => 'store-123', 'channel_key' => ''],
        ] as $configuration) {
            config([
                'portone.store_id' => $configuration['store_id'],
                'portone.channel_key' => $configuration['channel_key'],
            ]);

            $this->inertia(route('portone.checkout'))
                ->assertJsonPath('props.missingConfiguration', true)
                ->assertJsonPath('props.state', 'error')
                ->assertJsonPath('props.statusText', 'Error');
        }
    }

    public function test_prepare_returns_browser_payment_request_values_as_json(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        config(['portone.store_id' => 'store-123', 'portone.channel_key' => 'channel-456']);
        $billable = $this->billable(101);
        $this->actingAs($billable);

        $response = $this->postJson(route('portone.checkout.prepare'), ['product' => 1, 'amount' => 1]);

        $payment = Payment::query()->sole();
        $response->assertOk()->assertExactJson([
            'storeId' => 'store-123',
            'channelKey' => 'channel-456',
            'paymentId' => $payment->payment_id,
            'orderName' => 'Order A',
            'totalAmount' => 10000,
            'redirectUrl' => route('portone.checkout.completed', ['paymentId' => $payment->payment_id]),
            'customer' => ['customerId' => $billable->portOneId()],
            'forceRedirect' => true,
        ]);
        $this->assertSame('PENDING', $payment->status);
    }

    public function test_completed_completes_a_pending_payment_and_renders_the_paid_page(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->with($payment->payment_id)
            ->willReturn(new GatewayPayment('PAID', 100));
        $this->actingAs($billable);

        $response = $this->inertia(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]));

        $payment->refresh();
        $this->assertSame('PAID', $payment->status);
        $response->assertJsonPath('component', 'portone/completed')
            ->assertJsonPath('props.failed', false)
            ->assertJsonPath('props.payment', [
                'payment_id' => $payment->payment_id,
                'order_name' => 'Order A',
                'amount' => 100,
                'paid_at' => $payment->paid_at?->toDateTimeString(),
                'status' => 'PAID',
            ]);
    }

    public function test_completed_renders_failed_when_portone_reports_not_paid(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())->method('lookup')->willReturn(new GatewayPayment('FAILED', 100));
        $this->actingAs($billable);

        $this->inertia(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]))
            ->assertJsonPath('props.failed', true)
            ->assertJsonPath('props.payment.status', 'PENDING');

        $this->assertSame('PENDING', $payment->fresh()->status);
    }

    public function test_completed_renders_failed_for_a_redirect_error_without_touching_the_record(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('lookup');
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $this->actingAs($billable);

        $this->inertia(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]).'?code=FAILURE_TYPE_PG&message=failed')
            ->assertJsonPath('props.failed', true)
            ->assertJsonPath('props.payment.payment_id', $payment->payment_id);

        $this->assertSame('PENDING', $payment->fresh()->status);
    }

    public function test_completed_is_not_found_for_unknown_or_other_payments(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $others = $this->billable(202)->preparePayment(100, 'Order A');
        $others->forceFill(['status' => 'PAID', 'paid_at' => now()])->save();
        $this->actingAs($this->billable(101));

        foreach ([$others->payment_id, 'unknown-payment'] as $paymentId) {
            $this->inertia(route('portone.checkout.completed', ['paymentId' => $paymentId]))->assertNotFound();
        }
    }

    public function test_unauthenticated_requests_redirect_to_login(): void
    {
        $this->publishAndIncludeRoutes();
        Route::get('/login', static fn () => response('login'))->name('login');

        $this->get(route('portone.checkout'))->assertRedirect(route('login'));
        $this->post(route('portone.checkout.prepare'))->assertRedirect(route('login'));
    }

    /**
     * @return TestResponse<Response>
     */
    private function inertia(string $url): TestResponse
    {
        return $this->get($url, ['X-Inertia' => 'true']);
    }

    private function publishStubs(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'portone-stubs-react',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);
    }

    private function publishAndIncludeRoutes(): void
    {
        $this->publishStubs();

        require_once $this->app->basePath('app/Http/Controllers/PortOneCheckoutController.php');
        require $this->app->basePath('routes/portone.php');
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

    private function gatewayMock(): PaymentGateway&MockObject
    {
        $gateway = $this->createMock(PaymentGateway::class);
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    private function billable(int $key): ReactCheckoutBillableUser
    {
        $billable = new ReactCheckoutBillableUser;
        $billable->setRawAttributes(['id' => $key]);

        return $billable;
    }
}

class ReactCheckoutBillableUser extends Authenticatable
{
    use Billable;
}
