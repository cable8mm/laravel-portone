<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Exception\UnauthorizedException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class CheckoutCompleteActionTest extends TestCase
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

    public function test_unauthenticated_completion_does_not_create_a_payment(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        Route::get('/login', static fn () => response('login'))->name('login');

        $this->post(route('portone.checkout.complete'), ['paymentId' => 'payment-1'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_completion_returns_status_and_ignores_request_amount(): void
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

        $this->post(route('portone.checkout.complete'), [
            'paymentId' => $payment->payment_id,
            'amount' => 1,
        ])->assertOk()->assertExactJson([
            'status' => 'PAID',
            'redirectUrl' => route('portone.checkout.completed', ['paymentId' => $payment->payment_id]),
        ]);

        $this->assertSame('PAID', $payment->fresh()->status);
    }

    public function test_completion_page_shows_a_paid_payment_of_the_signed_in_user(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $payment->forceFill(['status' => 'PAID', 'paid_at' => now()])->save();
        $this->actingAs($billable);

        $this->get(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]))
            ->assertOk()
            ->assertSee('data-status="PAID"', false)
            ->assertDontSee('data-failed', false)
            ->assertSee('Order A')
            ->assertSee('100 KRW')
            ->assertSee($payment->payment_id)
            ->assertSee($payment->paid_at->toDateTimeString());

        // A later partial refund keeps the page and shows the current status.
        $payment->forceFill(['status' => 'PARTIALLY_CANCELLED', 'cancelled_amount' => 30])->save();
        $this->get(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]))
            ->assertOk()
            ->assertSee('PARTIALLY_CANCELLED');
    }

    public function test_completion_page_completes_a_pending_payment_on_the_redirect_return(): void
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

        $this->get(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]))
            ->assertOk()
            ->assertSee('data-status="PAID"', false)
            ->assertDontSee('data-failed', false);

        $this->assertSame('PAID', $payment->fresh()->status);
    }

    public function test_completion_page_completes_an_expired_payment_paid_later(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $payment->forceFill(['status' => 'EXPIRED'])->save();
        $gateway->expects($this->once())
            ->method('lookup')
            ->willReturn(new GatewayPayment('PAID', 100));
        $this->actingAs($billable);

        $this->get(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]))
            ->assertOk()
            ->assertSee('data-status="PAID"', false);

        $this->assertSame('PAID', $payment->fresh()->status);
    }

    public function test_completion_page_shows_failed_when_portone_reports_not_paid(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->willReturn(new GatewayPayment('FAILED', 100));
        $this->actingAs($billable);

        $this->get(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]))
            ->assertOk()
            ->assertSee('data-failed', false)
            ->assertSee('Payment failed');

        $this->assertSame('PENDING', $payment->fresh()->status);
    }

    public function test_completion_page_shows_failed_for_a_redirect_error_without_touching_the_record(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('lookup');
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $this->actingAs($billable);

        $this->get(route('portone.checkout.completed', ['paymentId' => $payment->payment_id]).'?code=FAILURE_TYPE_PG&message=failed')
            ->assertOk()
            ->assertSee('data-failed', false)
            ->assertSee('Payment failed');

        $this->assertSame('PENDING', $payment->fresh()->status);
    }

    public function test_completion_page_is_not_found_for_unknown_or_other_payments(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $billable = $this->billable(101);
        $others = $this->billable(202)->preparePayment(100, 'Order A');
        $others->forceFill(['status' => 'PAID', 'paid_at' => now()])->save();
        $this->actingAs($billable);

        foreach ([$others->payment_id, 'unknown-payment'] as $paymentId) {
            $this->get(route('portone.checkout.completed', ['paymentId' => $paymentId]))->assertNotFound();
        }
        $this->get(route('portone.checkout.completed', ['paymentId' => 'unknown-payment']).'?code=FAILURE_TYPE_PG')->assertNotFound();
    }

    public function test_unauthenticated_completion_page_redirects_to_login(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        Route::get('/login', static fn () => response('login'))->name('login');

        $this->get(route('portone.checkout.completed', ['paymentId' => 'payment-1']))->assertRedirect(route('login'));
    }

    public function test_not_found_not_paid_and_amount_mismatch_return_http_422(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $otherBillable = $this->billable(202);
        $otherPayment = $otherBillable->preparePayment(100, 'Other order');
        $notPaidPayment = $billable->preparePayment(100, 'Not paid');
        $mismatchedPayment = $billable->preparePayment(100, 'Mismatched');
        $gateway->expects($this->exactly(2))
            ->method('lookup')
            ->willReturnOnConsecutiveCalls(
                new GatewayPayment('FAILED', 100),
                new GatewayPayment('PAID', 99),
            );
        $this->actingAs($billable);

        $this->post(route('portone.checkout.complete'), ['paymentId' => 'unknown-payment'])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
        $this->post(route('portone.checkout.complete'), ['paymentId' => $otherPayment->payment_id])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
        $this->post(route('portone.checkout.complete'), ['paymentId' => $notPaidPayment->payment_id])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
        $this->post(route('portone.checkout.complete'), ['paymentId' => $mismatchedPayment->payment_id])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);

        $this->assertSame('PENDING', $notPaidPayment->fresh()->status);
        $this->assertSame('AMOUNT_MISMATCH_CANCELLED', $mismatchedPayment->fresh()->status);
    }

    public function test_missing_api_secret_and_portone_failures_return_http_500(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $this->actingAs($billable);

        config(['portone.api_secret' => null]);
        $this->post(route('portone.checkout.complete'), ['paymentId' => $payment->payment_id])
            ->assertStatus(500)
            ->assertJsonStructure(['error']);

        $gateway = $this->gatewayMock();
        $gateway->expects($this->once())
            ->method('lookup')
            ->willThrowException(new UnauthorizedException('PortOne request failed.', 401, null, null, 'UNAUTHORIZED'));
        config(['portone.api_secret' => 'configured-for-test']);

        $this->post(route('portone.checkout.complete'), ['paymentId' => $payment->payment_id])
            ->assertStatus(500)
            ->assertJsonStructure(['error']);
        $this->assertSame('PENDING', $payment->fresh()->status);
    }

    public function test_unexpected_completion_failures_propagate_unmodified(): void
    {
        $this->migratePackageTables();
        $this->publishAndIncludeRoutes();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->willReturn(new GatewayPayment('PAID', 100));
        Payment::updating(static function (): never {
            throw new \RuntimeException('Local persistence failed.');
        });
        $this->actingAs($billable);
        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Local persistence failed.');

        $this->post(route('portone.checkout.complete'), ['paymentId' => $payment->payment_id]);
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
            $this->publishedFiles[] = $this->app->basePath($path);
        }

        require_once $this->app->basePath('app/Http/Controllers/PortOneCheckoutController.php');
        require $this->app->basePath('routes/portone.php');
    }

    private function gatewayMock(): PaymentGateway&MockObject
    {
        $gateway = $this->createMock(PaymentGateway::class);
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    private function billable(int $key): CheckoutCompleteBillableUser
    {
        $billable = new CheckoutCompleteBillableUser;
        $billable->setRawAttributes(['id' => $key]);

        return $billable;
    }
}

class CheckoutCompleteBillableUser extends Authenticatable
{
    use Billable;
}
