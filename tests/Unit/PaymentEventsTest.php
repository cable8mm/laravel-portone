<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Events\PaymentAmountMismatched;
use Cable8mm\LaravelPortone\Events\PaymentCompleted;
use Cable8mm\LaravelPortone\Events\PaymentDuplicated;
use Cable8mm\LaravelPortone\Events\PaymentEventSource;
use Cable8mm\LaravelPortone\Events\PaymentExpired;
use Cable8mm\LaravelPortone\Events\PaymentPartiallyRefunded;
use Cable8mm\LaravelPortone\Events\PaymentRefunded;
use Cable8mm\LaravelPortone\Events\WebhookFailed;
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Jobs\ExpirePendingPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\Testing\FakePaymentGateway;
use Cable8mm\LaravelPortone\Testing\InteractsWithPortOneWebhooks;
use Cable8mm\PortonePhp\Exception\UnauthorizedException;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * T-040: one payment event per committed status change, whatever its source (SCR-011, FR-101, FR-103 to FR-108).
 *
 * Webhook bodies have the shape of the live recordings in tests/E2E/portone-webhook-observation.json
 * (Transaction.Paid, Transaction.Cancelled) and tests/E2E/portone-webhook-events-observation.json
 * (Transaction.PartialCancelled, Transaction.Failed). PortOne lookups are traced to
 * tests/E2E/portone-payment-command-observation.json (PAID, cancelled 0), tools/probes/portone-abandoned-payment-observation.json
 * (READY), tests/E2E/portone-payment-cancellation-observation.json (PARTIAL_CANCELLED 30 and CANCELLED 100 of 100),
 * tests/E2E/portone-amount-mismatch-observation.json (paid 100 for a prepared 10000); the failed cancellation to
 * tests/E2E/portone-payment-refund-observation.json (HTTP 409 PAYMENT_ALREADY_CANCELLED); an invalid api secret to T-003.
 */
class PaymentEventsTest extends TestCase
{
    use InteractsWithPortOneWebhooks;

    private const SECRET = 'dGVzdC1zZWNyZXQ=';

    private const PAYMENT_EVENTS = [
        PaymentCompleted::class,
        PaymentPartiallyRefunded::class,
        PaymentRefunded::class,
        PaymentAmountMismatched::class,
        PaymentDuplicated::class,
        PaymentExpired::class,
    ];

    private FakePaymentGateway $gateway;

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('portone.webhook_secret', self::SECRET);
        $app['config']->set('portone.store_id', 'store-720c15ca-0a45-4811-9e40-c8321ca05e8e');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }

        Schema::create('payment_event_users', function (Blueprint $table): void {
            $table->id();
        });

        $this->gateway = FakePaymentGateway::bind();
    }

    public function test_paid_webhook_completes_the_record_and_dispatches_payment_completed_before_webhook_received(): void
    {
        $payment = $this->payment(100);
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        $seen = [];
        Event::listen(PaymentCompleted::class, function (PaymentCompleted $event) use (&$seen): void {
            $seen[] = ['PaymentCompleted', $event->source, $event->payment->status, DB::transactionLevel(), Payment::query()->whereKey($event->payment->getKey())->value('status')];
        });
        Event::listen(WebhookReceived::class, function () use (&$seen): void {
            $seen[] = ['WebhookReceived'];
        });

        $this->postPortOneWebhook('Transaction.Paid', $payment->payment_id, ['transactionId' => '01a13845-34ca-eb3d-e51a-2f3d35e4ec8e'])->assertOk();

        $this->assertSame([['PaymentCompleted', PaymentEventSource::Webhook, 'PAID', 0, 'PAID'], ['WebhookReceived']], $seen);
        $fresh = $payment->fresh();
        $this->assertSame(['PAID', 100], [$fresh->status, $fresh->paid_amount]);
        $this->assertNotNull($fresh->paid_at);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unchangedPaidWebhooks(): iterable
    {
        yield 'PortOne reports the payment not paid' => ['READY'];
        yield 'record already PAID' => ['already-paid'];
        yield 'unknown payment' => ['unknown'];
    }

    #[DataProvider('unchangedPaidWebhooks')]
    public function test_paid_webhook_that_changes_nothing_succeeds_without_payment_events(string $case): void
    {
        $payment = $this->payment(100, $case === 'already-paid' ? 'PAID' : 'PENDING');
        $paymentId = $case === 'unknown' ? 'not-made-by-the-package' : $payment->payment_id;
        $this->gateway->payment($paymentId, $case === 'READY' ? 'READY' : 'PAID', totalAmount: 100, cancelledAmount: 0);
        $before = $payment->fresh()->getAttributes();
        Event::fake([WebhookReceived::class, WebhookFailed::class, ...self::PAYMENT_EVENTS]);

        $this->postPortOneWebhook('Transaction.Paid', $paymentId)->assertOk();

        $this->assertSame($before, $payment->fresh()->getAttributes());
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        $this->assertNoPaymentEvent();
        Event::assertNotDispatched(WebhookFailed::class);

        if ($case === 'READY') {
            $this->gateway->assertLookedUp($paymentId, times: 1);
        } else {
            $this->gateway->assertNothingLookedUp();
        }
    }

    public function test_paid_webhook_with_a_different_amount_records_the_mismatch_and_succeeds(): void
    {
        $cancelled = $this->payment(10000);
        $this->gateway->payment($cancelled->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        $failed = $this->payment(10000);
        $this->gateway->payment($failed->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0)
            ->failCancel($failed->payment_id, new UnknownException('cancelPayment', 'PortOne rejected the cancellation.', 409, null, null, 'PAYMENT_ALREADY_CANCELLED'));
        Event::fake([WebhookReceived::class, WebhookFailed::class, ...self::PAYMENT_EVENTS]);

        $this->postPortOneWebhook('Transaction.Paid', $cancelled->payment_id)->assertOk();
        $this->postPortOneWebhook('Transaction.Paid', $failed->payment_id)->assertOk();

        $this->assertSame('AMOUNT_MISMATCH_CANCELLED', $cancelled->fresh()->status);
        $this->assertSame('AMOUNT_MISMATCH', $failed->fresh()->status);
        Event::assertDispatchedTimes(PaymentAmountMismatched::class, 2);
        Event::assertDispatched(PaymentAmountMismatched::class, fn (PaymentAmountMismatched $event): bool => $event->paymentId === $cancelled->payment_id
            && $event->wasCancelled && $event->source === PaymentEventSource::Webhook);
        Event::assertDispatched(PaymentAmountMismatched::class, fn (PaymentAmountMismatched $event): bool => $event->paymentId === $failed->payment_id
            && ! $event->wasCancelled && $event->source === PaymentEventSource::Webhook);
        Event::assertDispatchedTimes(WebhookReceived::class, 2);
        Event::assertNotDispatched(WebhookFailed::class);
    }

    public function test_paid_webhook_lookup_failure_dispatches_only_webhook_failed(): void
    {
        $payment = $this->payment(100);
        $this->gateway->failLookup($payment->payment_id, new UnauthorizedException('Unauthorized', status: 401, errorType: 'UNAUTHORIZED'));
        Event::fake([WebhookReceived::class, WebhookFailed::class, ...self::PAYMENT_EVENTS]);

        $this->postPortOneWebhook('Transaction.Paid', $payment->payment_id)->assertStatus(500);

        $this->assertSame('PENDING', $payment->fresh()->status);
        Event::assertDispatchedTimes(WebhookFailed::class, 1);
        Event::assertNotDispatched(WebhookReceived::class);
        $this->assertNoPaymentEvent();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function webhooksWithoutEffect(): iterable
    {
        foreach (['Transaction.Failed', 'Transaction.Ready', 'Transaction.VirtualAccountIssued', 'Transaction.PayPending', 'Transaction.CancelPending', 'Transaction.DisputeCreated', 'Future.UnknownEvent'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('webhooksWithoutEffect')]
    public function test_other_webhook_types_change_nothing_and_dispatch_only_webhook_received(string $type): void
    {
        $payment = $this->payment(100);
        $before = $payment->fresh()->getAttributes();
        Event::fake([WebhookReceived::class, ...self::PAYMENT_EVENTS]);

        $this->postPortOneWebhook($type, $payment->payment_id)->assertOk();

        $this->assertSame($before, $payment->fresh()->getAttributes());
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        $this->assertNoPaymentEvent();
        $this->gateway->assertNothingLookedUp();
    }

    public function test_completion_by_browser_return_webhook_and_expiry_dispatches_payment_completed_once(): void
    {
        $payment = $this->payment(100);
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        Event::fake(self::PAYMENT_EVENTS);

        $this->owner($payment)->completePayment($payment->payment_id);
        $this->postPortOneWebhook('Transaction.Paid', $payment->payment_id)->assertOk();
        (new ExpirePendingPayment($payment->billable_type, $payment->billable_id, $payment->payment_id))
            ->handle($this->app->make(PaymentOperations::class));
        $this->owner($payment)->completePayment($payment->payment_id);

        Event::assertDispatchedTimes(PaymentCompleted::class, 1);
        Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event): bool => $event->source === PaymentEventSource::Complete
            && $event->paymentId === $payment->payment_id
            && $event->billableType === $payment->billable_type
            && $event->billableId === $payment->billable_id
            && $event->payment->status === 'PAID');
        $this->assertSame(1, count(array_filter(self::PAYMENT_EVENTS, static fn (string $event): bool => Event::dispatched($event)->isNotEmpty())));
    }

    public function test_completion_by_payment_id_uses_source_complete(): void
    {
        $payment = $this->payment(100);
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        Event::fake(self::PAYMENT_EVENTS);

        $this->app->make(PaymentOperations::class)->completeByPaymentId($payment->payment_id);

        Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event): bool => $event->source === PaymentEventSource::Complete);
    }

    public function test_rolled_back_write_dispatches_no_payment_event(): void
    {
        $payment = $this->payment(100);
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        Event::fake(self::PAYMENT_EVENTS);

        try {
            DB::transaction(function () use ($payment): void {
                $this->owner($payment)->completePayment($payment->payment_id);

                throw new RuntimeException('The Application rolls its own unit of work back.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('PENDING', $payment->fresh()->status);
        $this->assertNoPaymentEvent();
    }

    public function test_events_inside_an_outer_transaction_wait_for_its_commit(): void
    {
        $payment = $this->payment(100);
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        $levels = [];
        Event::listen(PaymentCompleted::class, function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });

        DB::transaction(function () use ($payment, &$levels): void {
            $this->owner($payment)->completePayment($payment->payment_id);
            $this->assertSame([], $levels);
        });

        $this->assertSame([0], $levels);
    }

    public function test_refund_dispatches_partially_refunded_and_the_later_webhook_dispatches_no_payment_event(): void
    {
        $payment = $this->payment(10000, 'PAID');
        Event::fake([WebhookReceived::class, ...self::PAYMENT_EVENTS]);

        $this->owner($payment)->refund($payment->payment_id, '고객 요청', 3000);
        $this->gateway->payment($payment->payment_id, 'PARTIAL_CANCELLED', totalAmount: 10000, cancelledAmount: 3000);
        $this->postPortOneWebhook('Transaction.PartialCancelled', $payment->payment_id, ['cancellationId' => '01a13845-de5d-3a4e-1fb1-8a29df5ee17b'])->assertOk();

        Event::assertDispatchedTimes(PaymentPartiallyRefunded::class, 1);
        Event::assertDispatched(PaymentPartiallyRefunded::class, fn (PaymentPartiallyRefunded $event): bool => $event->refundedAmount === 3000
            && $event->source === PaymentEventSource::Refund
            && $event->payment->cancelled_amount === 3000);
        Event::assertNotDispatched(PaymentRefunded::class);
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        $this->assertSame(['PARTIALLY_CANCELLED', 3000], [$payment->fresh()->status, $payment->fresh()->cancelled_amount]);
    }

    public function test_full_refund_dispatches_refunded_with_the_remaining_amount(): void
    {
        $payment = $this->payment(10000, 'PAID');
        Event::fake(self::PAYMENT_EVENTS);

        $this->owner($payment)->refund($payment->payment_id, '고객 요청', 3000);
        $this->owner($payment)->refund($payment->payment_id, '고객 요청');

        Event::assertDispatched(PaymentRefunded::class, fn (PaymentRefunded $event): bool => $event->refundedAmount === 7000
            && $event->source === PaymentEventSource::Refund);
        Event::assertDispatchedTimes(PaymentPartiallyRefunded::class, 1);
    }

    public function test_cancellation_webhooks_dispatch_with_source_webhook(): void
    {
        $payment = $this->payment(100, 'PAID');
        Event::fake(self::PAYMENT_EVENTS);

        $this->gateway->payment($payment->payment_id, 'PARTIAL_CANCELLED', totalAmount: 100, cancelledAmount: 30);
        $this->postPortOneWebhook('Transaction.PartialCancelled', $payment->payment_id)->assertOk();
        $this->postPortOneWebhook('Transaction.PartialCancelled', $payment->payment_id)->assertOk();
        $this->gateway->payment($payment->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);
        $this->postPortOneWebhook('Transaction.Cancelled', $payment->payment_id)->assertOk();

        Event::assertDispatchedTimes(PaymentPartiallyRefunded::class, 1);
        Event::assertDispatched(PaymentPartiallyRefunded::class, fn (PaymentPartiallyRefunded $event): bool => $event->refundedAmount === 30
            && $event->source === PaymentEventSource::Webhook);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        Event::assertDispatched(PaymentRefunded::class, fn (PaymentRefunded $event): bool => $event->refundedAmount === 70
            && $event->source === PaymentEventSource::Webhook);
    }

    public function test_sync_dispatches_with_source_sync_and_amount_mismatch_cancellation_was_cancelled_true(): void
    {
        $paid = $this->payment(100, 'PAID');
        $this->gateway->payment($paid->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);
        $mismatch = $this->payment(10000, 'AMOUNT_MISMATCH', paidAmount: 100);
        $this->gateway->payment($mismatch->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);
        $operations = $this->app->make(PaymentOperations::class);
        Event::fake(self::PAYMENT_EVENTS);

        $operations->syncByPaymentId($paid->payment_id);
        $operations->syncByPaymentId($paid->payment_id);
        $operations->syncByPaymentId($mismatch->payment_id);

        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        Event::assertDispatched(PaymentRefunded::class, fn (PaymentRefunded $event): bool => $event->refundedAmount === 100
            && $event->source === PaymentEventSource::Sync);
        Event::assertDispatchedTimes(PaymentAmountMismatched::class, 1);
        Event::assertDispatched(PaymentAmountMismatched::class, fn (PaymentAmountMismatched $event): bool => $event->wasCancelled
            && $event->source === PaymentEventSource::Sync
            && $event->payment->status === 'AMOUNT_MISMATCH_CANCELLED');
    }

    public function test_completion_amount_mismatch_dispatches_before_the_exception(): void
    {
        $payment = $this->payment(10000);
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        Event::fake(self::PAYMENT_EVENTS);

        try {
            $this->owner($payment)->completePayment($payment->payment_id);
            $this->fail('Expected AmountMismatchException.');
        } catch (AmountMismatchException) {
        }

        Event::assertDispatched(PaymentAmountMismatched::class, fn (PaymentAmountMismatched $event): bool => $event->wasCancelled
            && $event->source === PaymentEventSource::Complete);

        // A repeated completion of the stored mismatch writes nothing and dispatches nothing more.
        try {
            $this->owner($payment)->completePayment($payment->payment_id);
        } catch (AmountMismatchException) {
        }

        Event::assertDispatchedTimes(PaymentAmountMismatched::class, 1);
    }

    private function owner(Payment $payment): PaymentEventUser
    {
        return PaymentEventUser::query()->findOrFail($payment->billable_id);
    }

    private function assertNoPaymentEvent(): void
    {
        foreach (self::PAYMENT_EVENTS as $event) {
            Event::assertNotDispatched($event);
        }
    }

    private function payment(int $amount, string $status = 'PENDING', ?int $paidAmount = null): Payment
    {
        $payment = PaymentEventUser::query()->create()->preparePayment($amount, 'Order A');
        $paid = in_array($status, ['PAID', 'PARTIALLY_CANCELLED', 'CANCELLED'], true);
        $payment->forceFill([
            'status' => $status,
            'paid_at' => $paid ? now() : null,
            'paid_amount' => $paid ? $amount : $paidAmount,
        ])->save();

        return $payment;
    }
}

class PaymentEventUser extends Model
{
    use Billable;

    protected $table = 'payment_event_users';

    public $timestamps = false;

    protected $guarded = [];
}
