<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\Testing\FakePaymentGateway;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The gateway test double (T-033, AC-086).
 *
 * The not-found behavior mirrors PortOneGateway as recorded by T-003 and T-021
 * (HTTP 404 PAYMENT_NOT_FOUND); cancelled amounts follow T-010.
 */
class FakePaymentGatewayTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    public function test_bind_replaces_the_container_gateway(): void
    {
        $gateway = FakePaymentGateway::bind();

        $this->assertSame($gateway, $this->app->make(PaymentGateway::class));
    }

    public function test_set_responses_are_returned_with_the_cancelled_amount(): void
    {
        $gateway = (new FakePaymentGateway)->payment('payment-1', 'PARTIAL_CANCELLED', totalAmount: 100, cancelledAmount: 30);

        $result = $gateway->lookup('payment-1');

        $this->assertSame(['PARTIAL_CANCELLED', 100, 30, true], [$result->status, $result->totalAmount, $result->cancelledAmount, $result->found]);
        $this->assertSame('PARTIAL_CANCELLED', $gateway->lookupForExpiry('payment-1')->status);
    }

    public function test_unset_payment_is_unknown_to_portone(): void
    {
        $gateway = new FakePaymentGateway;

        $result = $gateway->lookupForExpiry('never-opened');
        $this->assertFalse($result->found);
        $this->assertSame('PAYMENT_NOT_FOUND', $result->status);

        try {
            $gateway->lookup('never-opened');
            $this->fail('An unset payment must not be found.');
        } catch (UnknownException $exception) {
            $this->assertSame('PAYMENT_NOT_FOUND', $exception->errorType);
            $this->assertSame(404, $exception->status);
        }
    }

    public function test_set_failures_are_thrown_and_cancellations_still_recorded(): void
    {
        $failure = new UnknownException('cancelPayment', 'Already cancelled', status: 409, errorType: 'PAYMENT_ALREADY_CANCELLED');
        $gateway = (new FakePaymentGateway)
            ->failLookup('payment-1', new PortOneException('down'))
            ->failCancel('payment-2', $failure);

        try {
            $gateway->lookup('payment-1');
            $this->fail('The lookup failure must be thrown.');
        } catch (PortOneException $exception) {
            $this->assertSame('down', $exception->getMessage());
        }

        try {
            $gateway->cancel('payment-2', 'reason', null);
            $this->fail('The cancellation failure must be thrown.');
        } catch (UnknownException $exception) {
            $this->assertSame($failure, $exception);
        }

        $gateway->assertCancelled('payment-2');
    }

    public function test_lookup_assertions(): void
    {
        $gateway = (new FakePaymentGateway)->payment('payment-1', 'PAID', 100, 0);
        $gateway->assertNothingLookedUp();

        $gateway->lookup('payment-1');
        $gateway->lookupForExpiry('payment-1');

        $gateway->assertLookedUp('payment-1');
        $gateway->assertLookedUp('payment-1', times: 2);
        $gateway->assertNotLookedUp('payment-2');
        $this->assertSame(2, $gateway->lookupCount());
        $this->assertFails(fn () => $gateway->assertLookedUp('payment-1', times: 1));
        $this->assertFails(fn () => $gateway->assertLookedUp('payment-2'));
        $this->assertFails(fn () => $gateway->assertNothingLookedUp());
    }

    public function test_cancellation_assertions(): void
    {
        $gateway = new FakePaymentGateway;
        $gateway->assertNothingCancelled();

        $gateway->cancel('payment-1', 'customer request', 3000);

        $gateway->assertCancelled('payment-1');
        $gateway->assertCancelled('payment-1', fn (string $reason, ?int $amount): bool => $reason === 'customer request' && $amount === 3000);
        $gateway->assertNotCancelled('payment-2');
        $this->assertSame([['paymentId' => 'payment-1', 'reason' => 'customer request', 'amount' => 3000]], $gateway->cancellations());
        $this->assertFails(fn () => $gateway->assertCancelled('payment-1', fn (string $reason, ?int $amount): bool => $amount === null));
        $this->assertFails(fn () => $gateway->assertNotCancelled('payment-1'));
        $this->assertFails(fn () => $gateway->assertNothingCancelled());
    }

    public function test_package_operations_use_the_bound_fake(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }
        $this->app['db']->connection()->getSchemaBuilder()->create('fake_gateway_users', function ($table): void {
            $table->id();
        });
        $user = FakeGatewayUser::query()->create();
        $payment = $user->preparePayment(100, 'Order A');
        $gateway = FakePaymentGateway::bind()->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);

        $this->assertSame('PAID', $user->completePayment($payment->payment_id)->status);
        $user->refund($payment->payment_id, 'customer request', 30);

        $gateway->assertLookedUp($payment->payment_id, times: 1);
        $gateway->assertCancelled($payment->payment_id, fn (string $reason, ?int $amount): bool => $amount === 30);
    }

    private function assertFails(callable $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The assertion was expected to fail.');
    }
}

class FakeGatewayUser extends Model
{
    use Billable;

    protected $table = 'fake_gateway_users';

    public $timestamps = false;

    protected $guarded = [];
}
