<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Events\PaymentCancelled;
use Cable8mm\LaravelPortone\Events\PaymentPartiallyCancelled;
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\Testing\FakePaymentGateway;
use Cable8mm\LaravelPortone\Testing\InteractsWithPortOneWebhooks;
use Cable8mm\PortonePhp\Exception\UnauthorizedException;
use Cable8mm\PortonePhp\Webhook\WebhookTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * T-034: cancellation webhooks and syncByPaymentId bring the record in line with PortOne.
 *
 * Webhook bodies have the shape of the live Transaction.Cancelled recording in
 * tests/E2E/portone-webhook-observation.json and the Transaction.PartialCancelled recording of T-028.
 * PortOne lookups are traced to tests/E2E/portone-payment-cancellation-observation.json (T-010):
 * amount 100, PARTIAL_CANCELLED with cancelled 30, CANCELLED with cancelled 100; and to
 * tests/E2E/portone-payment-command-observation.json (T-030): PAID with cancelled 0.
 * An invalid api secret fails with UNAUTHORIZED (T-003).
 */
class WebhookCancellationSyncTest extends TestCase
{
    use InteractsWithPortOneWebhooks;

    private const SECRET = 'dGVzdC1zZWNyZXQ=';

    /** transactionId and cancellationId of the recorded Transaction.Cancelled webhook. */
    private const CANCELLATION = [
        'transactionId' => '01a13845-34ca-eb3d-e51a-2f3d35e4ec8e',
        'cancellationId' => '01a13845-de5d-3a4e-1fb1-8a29df5ee17b',
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

        Schema::create('sync_users', function (Blueprint $table): void {
            $table->id();
        });

        $this->gateway = FakePaymentGateway::bind();
    }

    public function test_full_cancellation_webhook_syncs_the_record_before_the_events(): void
    {
        $payment = $this->payment('PAID');
        $this->gateway->payment($payment->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);
        $seen = $this->recordStateSeenByListeners([WebhookReceived::class, PaymentCancelled::class]);

        $this->postPortOneWebhook('Transaction.Cancelled', $payment->payment_id, self::CANCELLATION)->assertOk();
        $this->postPortOneWebhook('Transaction.Cancelled', $payment->payment_id, self::CANCELLATION)->assertOk();

        $this->assertSame(['CANCELLED', 100], [$payment->fresh()->status, $payment->fresh()->cancelled_amount]);
        $this->assertSame(array_fill(0, 4, 'CANCELLED:100'), $seen->values);
        // The second delivery finds a CANCELLED record and asks PortOne nothing.
        $this->gateway->assertLookedUp($payment->payment_id, times: 1);
        $this->gateway->assertNothingCancelled();
    }

    public function test_partial_then_full_cancellation_webhooks(): void
    {
        $payment = $this->payment('PAID');
        $this->gateway->payment($payment->payment_id, 'PARTIAL_CANCELLED', totalAmount: 100, cancelledAmount: 30);
        $seen = $this->recordStateSeenByListeners([PaymentPartiallyCancelled::class]);

        $this->postPortOneWebhook('Transaction.PartialCancelled', $payment->payment_id, self::CANCELLATION)->assertOk();
        $this->postPortOneWebhook('Transaction.PartialCancelled', $payment->payment_id, self::CANCELLATION)->assertOk();

        $this->assertSame(['PARTIALLY_CANCELLED:30', 'PARTIALLY_CANCELLED:30'], $seen->values);

        $this->gateway->payment($payment->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);
        $this->postPortOneWebhook('Transaction.Cancelled', $payment->payment_id, self::CANCELLATION)->assertOk();

        $this->assertSame(['CANCELLED', 100], [$payment->fresh()->status, $payment->fresh()->cancelled_amount]);
        $this->assertNotNull($payment->fresh()->paid_at);
    }

    public function test_record_stays_when_portone_does_not_match_and_events_are_still_dispatched(): void
    {
        $payment = $this->payment('PAID');
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        $before = $payment->fresh()->getAttributes();
        Event::fake([WebhookReceived::class, PaymentCancelled::class]);

        $this->postPortOneWebhook('Transaction.Cancelled', $payment->payment_id, self::CANCELLATION)->assertOk();

        $this->assertSame($before, $payment->fresh()->getAttributes());
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatchedTimes(PaymentCancelled::class, 1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsyncedStatuses(): iterable
    {
        foreach (['PENDING', 'EXPIRED', 'CANCELLED', 'AMOUNT_MISMATCH_CANCELLED'] as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('unsyncedStatuses')]
    public function test_other_statuses_are_not_looked_up(string $status): void
    {
        $payment = $this->payment($status);
        $before = $payment->fresh()->getAttributes();
        Event::fake([WebhookReceived::class, PaymentCancelled::class]);

        $this->postPortOneWebhook('Transaction.Cancelled', $payment->payment_id, self::CANCELLATION)->assertOk();

        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->gateway->assertNothingLookedUp();
        Event::assertDispatchedTimes(PaymentCancelled::class, 1);
    }

    public function test_unknown_payment_skips_the_sync_and_dispatches_the_events(): void
    {
        Event::fake([WebhookReceived::class, PaymentCancelled::class]);

        $this->postPortOneWebhook('Transaction.Cancelled', 'not-made-by-the-package', self::CANCELLATION)->assertOk();

        $this->gateway->assertNothingLookedUp();
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatchedTimes(PaymentCancelled::class, 1);
    }

    public function test_lookup_failure_fails_the_request_without_events(): void
    {
        $payment = $this->payment('PAID');
        $this->gateway->failLookup($payment->payment_id, new UnauthorizedException('Unauthorized', status: 401, errorType: 'UNAUTHORIZED'));
        Event::fake([WebhookReceived::class, PaymentCancelled::class]);

        $this->postPortOneWebhook('Transaction.Cancelled', $payment->payment_id, self::CANCELLATION)->assertStatus(500);

        $this->assertSame('PAID', $payment->fresh()->status);
        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(PaymentCancelled::class);
    }

    public function test_amount_mismatch_record_cancelled_at_portone_becomes_amount_mismatch_cancelled(): void
    {
        $payment = $this->payment('AMOUNT_MISMATCH', amount: 10000, paidAmount: 100);
        $this->gateway->payment($payment->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);

        $this->postPortOneWebhook('Transaction.Cancelled', $payment->payment_id, self::CANCELLATION)->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame(['AMOUNT_MISMATCH_CANCELLED', 100, 100, 10000], [$fresh->status, $fresh->cancelled_amount, $fresh->paid_amount, $fresh->amount]);
        $this->assertNull($fresh->paid_at);
    }

    /**
     * @return iterable<string, array{string, int, string, int|null}>
     */
    public static function inconsistentPortOneStates(): iterable
    {
        yield 'CANCELLED but cancelled amount below amount' => ['PAID', 0, 'CANCELLED', 30];
        yield 'PARTIAL_CANCELLED below the recorded cancellation' => ['PARTIALLY_CANCELLED', 50, 'PARTIAL_CANCELLED', 30];
        yield 'PARTIAL_CANCELLED for the whole amount' => ['PAID', 0, 'PARTIAL_CANCELLED', 100];
        yield 'no cancelled amount' => ['PAID', 0, 'CANCELLED', null];
        yield 'amount mismatch still paid' => ['AMOUNT_MISMATCH', 0, 'PAID', 0];
    }

    #[DataProvider('inconsistentPortOneStates')]
    public function test_sync_leaves_the_record_for_inconsistent_portone_states(string $status, int $cancelled, string $portOneStatus, ?int $portOneCancelled): void
    {
        $payment = $this->payment($status, cancelledAmount: $cancelled, paidAmount: 100);
        $this->gateway->payment($payment->payment_id, $portOneStatus, totalAmount: 100, cancelledAmount: $portOneCancelled);
        $before = $payment->fresh()->getAttributes();

        $returned = $this->app->make(PaymentOperations::class)->syncByPaymentId($payment->payment_id);

        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame($status, $returned->status);
    }

    public function test_sync_by_payment_id_works_for_any_billable_and_rejects_unknown_ids(): void
    {
        $payment = $this->payment('PARTIALLY_CANCELLED', cancelledAmount: 30);
        $this->gateway->payment($payment->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);
        $operations = $this->app->make(PaymentOperations::class);

        $synced = $operations->syncByPaymentId($payment->payment_id);

        $this->assertSame(['CANCELLED', 100], [$synced->status, $synced->cancelled_amount]);

        $this->expectException(PaymentNotFoundException::class);
        try {
            $operations->syncByPaymentId('unknown');
        } finally {
            $this->assertSame(1, $this->gateway->lookupCount());
        }
    }

    private function payment(string $status, int $amount = 100, int $cancelledAmount = 0, ?int $paidAmount = null): Payment
    {
        $payment = SyncUser::query()->create()->preparePayment($amount, 'Order A');
        $paid = in_array($status, ['PAID', 'PARTIALLY_CANCELLED', 'CANCELLED'], true);
        $payment->forceFill([
            'status' => $status,
            'cancelled_amount' => $status === 'CANCELLED' ? $amount : $cancelledAmount,
            'paid_at' => $paid ? now() : null,
            'paid_amount' => $paid ? $amount : $paidAmount,
        ])->save();

        return $payment;
    }

    /**
     * @param  list<class-string>  $events
     */
    private function recordStateSeenByListeners(array $events): object
    {
        $seen = new class
        {
            /** @var list<string> */
            public array $values = [];
        };

        foreach ($events as $event) {
            Event::listen($event, function (object $dispatched) use ($seen): void {
                $paymentId = $dispatched instanceof WebhookReceived && $dispatched->webhook instanceof WebhookTransaction
                    ? $dispatched->webhook->data->paymentId
                    : $dispatched->paymentId;
                $payment = Payment::query()->where('payment_id', $paymentId)->firstOrFail();
                $seen->values[] = $payment->status.':'.$payment->cancelled_amount;
            });
        }

        return $seen;
    }
}

class SyncUser extends Model
{
    use Billable;

    protected $table = 'sync_users';

    public $timestamps = false;

    protected $guarded = [];
}
