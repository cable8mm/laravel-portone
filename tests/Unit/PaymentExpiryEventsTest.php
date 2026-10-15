<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Events\PaymentAmountMismatchedOnExpiry;
use Cable8mm\LaravelPortone\Events\PaymentExpired;
use Cable8mm\LaravelPortone\Events\PaymentExpiryEvent;
use Cable8mm\LaravelPortone\Events\PaymentPaidOnExpiry;
use Cable8mm\LaravelPortone\Jobs\ExpirePendingPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\Testing\FakePaymentGateway;
use Cable8mm\PortonePhp\Exception\UnauthorizedException;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

/**
 * T-035: the expiry job reports each written outcome as an event (AC-085).
 *
 * Lookup results are traced to tools/probes/portone-abandoned-payment-observation.json (T-021):
 * PAID 100, FAILED 100, READY 100, PAYMENT_NOT_FOUND; the cancellation failure to
 * tests/E2E/portone-payment-refund-observation.json (T-010): HTTP 409 PAYMENT_ALREADY_CANCELLED;
 * an invalid api secret to T-003: HTTP 401 UNAUTHORIZED.
 */
class PaymentExpiryEventsTest extends TestCase
{
    private const EVENTS = [PaymentExpired::class, PaymentPaidOnExpiry::class, PaymentAmountMismatchedOnExpiry::class];

    private FakePaymentGateway $gateway;

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }

        $this->gateway = FakePaymentGateway::bind();
        Event::fake(self::EVENTS);
    }

    public function test_expired_payment_dispatches_payment_expired(): void
    {
        $payment = $this->pending(100);
        $this->gateway->payment($payment->payment_id, 'FAILED', totalAmount: 100);

        $this->runJob($payment->payment_id);

        $this->assertOnly(PaymentExpired::class, $payment, 'EXPIRED');
    }

    public function test_never_opened_payment_dispatches_payment_expired(): void
    {
        $payment = $this->pending(100);

        $this->runJob($payment->payment_id);

        $this->assertOnly(PaymentExpired::class, $payment, 'EXPIRED');
    }

    public function test_late_payment_dispatches_payment_paid_on_expiry(): void
    {
        $payment = $this->pending(100);
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);

        $this->runJob($payment->payment_id);

        $this->assertOnly(PaymentPaidOnExpiry::class, $payment, 'PAID');
        Event::assertDispatched(PaymentPaidOnExpiry::class, fn (PaymentPaidOnExpiry $event): bool => $event->payment->paid_amount === 100 && $event->payment->paid_at !== null);
    }

    public function test_cancelled_amount_mismatch_dispatches_was_cancelled_true(): void
    {
        $payment = $this->pending(10000);
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);

        $this->runJob($payment->payment_id);

        $this->assertOnly(PaymentAmountMismatchedOnExpiry::class, $payment, 'AMOUNT_MISMATCH_CANCELLED');
        Event::assertDispatched(PaymentAmountMismatchedOnExpiry::class, fn (PaymentAmountMismatchedOnExpiry $event): bool => $event->wasCancelled);
        $this->gateway->assertCancelled($payment->payment_id, fn (string $reason, ?int $amount): bool => $amount === null);
    }

    public function test_failed_mismatch_cancellation_dispatches_was_cancelled_false_before_the_job_fails(): void
    {
        $payment = $this->pending(10000);
        $failure = new UnknownException('cancelPayment', 'PortOne rejected the cancellation.', 409, null, null, 'PAYMENT_ALREADY_CANCELLED');
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0)->failCancel($payment->payment_id, $failure);

        try {
            $this->runJob($payment->payment_id);
            $this->fail('The cancellation failure must fail the job.');
        } catch (UnknownException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertOnly(PaymentAmountMismatchedOnExpiry::class, $payment, 'AMOUNT_MISMATCH');
        Event::assertDispatched(PaymentAmountMismatchedOnExpiry::class, fn (PaymentAmountMismatchedOnExpiry $event): bool => ! $event->wasCancelled);
    }

    public function test_no_event_when_nothing_is_written(): void
    {
        $undecided = $this->pending(100);
        $this->gateway->payment($undecided->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);
        $notPending = $this->pending(100);
        $notPending->forceFill(['status' => 'PAID', 'paid_at' => now(), 'paid_amount' => 100])->save();
        $failing = $this->pending(100);
        $this->gateway->failLookup($failing->payment_id, new UnauthorizedException('Unauthorized', status: 401, errorType: 'UNAUTHORIZED'));

        $this->runJob($undecided->payment_id);
        $this->runJob($notPending->payment_id);
        try {
            $this->runJob($failing->payment_id);
            $this->fail('The lookup failure must fail the job.');
        } catch (UnauthorizedException) {
        }

        foreach (self::EVENTS as $event) {
            Event::assertNotDispatched($event);
        }
    }

    public function test_event_is_dispatched_after_the_write_is_committed(): void
    {
        Event::swap(new Dispatcher($this->app));
        $payment = $this->pending(100);
        $this->gateway->payment($payment->payment_id, 'READY', totalAmount: 100);
        $seen = null;
        Event::listen(PaymentExpired::class, function () use (&$seen, $payment): void {
            $seen = [$this->app['db']->transactionLevel(), Payment::query()->whereKey($payment->getKey())->value('status')];
        });

        $this->runJob($payment->payment_id);

        $this->assertSame([0, 'EXPIRED'], $seen);
    }

    /**
     * @param  class-string<PaymentExpiryEvent>  $expected
     */
    private function assertOnly(string $expected, Payment $payment, string $status): void
    {
        $this->assertSame($status, $payment->fresh()->status);
        Event::assertDispatchedTimes($expected, 1);
        Event::assertDispatched($expected, fn (PaymentExpiryEvent $event): bool => $event->paymentId === $payment->payment_id
            && $event->payment->is($payment)
            && $event->payment->status === $status
            && $event->billableType === $payment->billable_type
            && $event->billableId === '101');

        foreach (array_diff(self::EVENTS, [$expected]) as $other) {
            Event::assertNotDispatched($other);
        }
    }

    private function runJob(string $paymentId): void
    {
        (new ExpirePendingPayment((new ExpiryEventsBillableModel)->getMorphClass(), '101', $paymentId))
            ->handle($this->app->make(PaymentOperations::class));
    }

    private function pending(int $amount): Payment
    {
        $billable = new ExpiryEventsBillableModel;
        $billable->setRawAttributes(['id' => 101]);

        return $billable->preparePayment($amount, 'Order A');
    }
}

class ExpiryEventsBillableModel extends Model
{
    use Billable;
}
