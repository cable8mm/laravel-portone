<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Events\PaymentCompleted;
use Cable8mm\LaravelPortone\Events\PaymentDuplicated;
use Cable8mm\LaravelPortone\Events\PaymentEventSource;
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\DuplicatePaymentException;
use Cable8mm\LaravelPortone\Exceptions\InvalidPaymentStatusException;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\Testing\FakePaymentGateway;
use Cable8mm\LaravelPortone\Testing\InteractsWithPortOneWebhooks;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * T-041: a second paid payment of a single-payment Billable model is cancelled automatically (EL-069, EL-055,
 * FR-083, FR-107, FR-109 to FR-112, ERR-020, AC-095, AC-096).
 *
 * PortOne lookups are traced to tests/E2E/portone-payment-command-observation.json (PAID 100, cancelled 0),
 * tests/E2E/portone-payment-cancellation-observation.json (CANCELLED 100 of 100) and
 * tests/E2E/portone-amount-mismatch-observation.json (paid 100 for a prepared 10000); the failed cancellation to
 * tests/E2E/portone-payment-refund-observation.json (HTTP 409 PAYMENT_ALREADY_CANCELLED).
 */
class DuplicatePaymentTest extends TestCase
{
    use InteractsWithPortOneWebhooks;

    private const SECRET = 'dGVzdC1zZWNyZXQ=';

    private FakePaymentGateway $gateway;

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('portone.webhook_secret', self::SECRET);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }

        foreach (['duplicate_orders', 'duplicate_users'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
            });
        }

        $this->gateway = FakePaymentGateway::bind();
    }

    public function test_a_billable_model_allows_many_paid_payments_by_default(): void
    {
        $user = DuplicateUser::query()->create();
        $first = $this->payment($user);
        $second = $this->payment($user);
        $this->gateway->payment($first->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        $this->gateway->payment($second->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);

        $this->assertFalse($user->allowsOnlyOnePaidPayment());
        $this->assertSame('PAID', $user->completePayment($first->payment_id)->status);
        $this->assertSame('PAID', $user->completePayment($second->payment_id)->status);
        $this->gateway->assertNothingCancelled();
    }

    public function test_second_paid_payment_is_cancelled_and_completion_throws(): void
    {
        config(['portone.duplicate_cancel_reason' => 'Paid twice']);
        $order = DuplicateOrder::query()->create();
        $this->payment($order, 'PAID');
        $second = $this->payment($order);
        $this->gateway->payment($second->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        Event::fake([PaymentCompleted::class, PaymentDuplicated::class]);

        try {
            $order->completePayment($second->payment_id);
            $this->fail('Expected DuplicatePaymentException.');
        } catch (DuplicatePaymentException $exception) {
            $this->assertTrue($exception->wasCancelled());
            $this->assertSame(100, $exception->paidAmount());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame([['paymentId' => $second->payment_id, 'reason' => 'Paid twice', 'amount' => null]], $this->gateway->cancellations());
        $fresh = $second->fresh();
        $this->assertSame(['DUPLICATE_CANCELLED', 100, 100, 100], [$fresh->status, $fresh->amount, $fresh->paid_amount, $fresh->cancelled_amount]);
        $this->assertNotNull($fresh->paid_at);
        Event::assertNotDispatched(PaymentCompleted::class);
        Event::assertDispatchedTimes(PaymentDuplicated::class, 1);
        Event::assertDispatched(PaymentDuplicated::class, fn (PaymentDuplicated $event): bool => $event->wasCancelled
            && $event->source === PaymentEventSource::Complete
            && $event->paymentId === $second->payment_id);

        // A repeated completion of the stored duplicate sends nothing to PortOne and writes nothing.
        $before = $fresh->getAttributes();
        $this->assertSame($before, $order->completePayment($second->payment_id)->getAttributes());
        $this->gateway->assertLookedUp($second->payment_id, times: 1);
        Event::assertDispatchedTimes(PaymentDuplicated::class, 1);
    }

    public function test_failed_cancellation_stores_duplicate_and_carries_the_portone_exception(): void
    {
        $order = DuplicateOrder::query()->create();
        $this->payment($order, 'PAID');
        $second = $this->payment($order);
        $this->gateway->payment($second->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        $portOneException = new UnknownException('cancelPayment', 'PortOne rejected the cancellation.', 409, null, null, 'PAYMENT_ALREADY_CANCELLED');
        $this->gateway->failCancel($second->payment_id, $portOneException);
        Event::fake([PaymentDuplicated::class]);

        try {
            $order->completePayment($second->payment_id);
            $this->fail('Expected DuplicatePaymentException.');
        } catch (DuplicatePaymentException $exception) {
            $this->assertFalse($exception->wasCancelled());
            $this->assertSame(100, $exception->paidAmount());
            $this->assertSame($portOneException, $exception->getPrevious());
        }

        $this->gateway->assertCancelled($second->payment_id, fn (string $reason, ?int $amount): bool => $reason === '중복 결제로 자동 취소' && $amount === null);
        $fresh = $second->fresh();
        $this->assertSame(['DUPLICATE', 100, 0], [$fresh->status, $fresh->paid_amount, $fresh->cancelled_amount]);
        $this->assertNotNull($fresh->paid_at);
        Event::assertDispatched(PaymentDuplicated::class, fn (PaymentDuplicated $event): bool => ! $event->wasCancelled);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paidStatuses(): iterable
    {
        yield 'PAID' => ['PAID'];
        yield 'PARTIALLY_CANCELLED' => ['PARTIALLY_CANCELLED'];
        yield 'CANCELLED' => ['CANCELLED'];
    }

    #[DataProvider('paidStatuses')]
    public function test_every_paid_status_of_another_record_makes_the_payment_a_duplicate(string $status): void
    {
        $order = DuplicateOrder::query()->create();
        $this->payment($order, $status);
        $second = $this->payment($order);
        $this->gateway->payment($second->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);

        $this->expectException(DuplicatePaymentException::class);

        try {
            $order->completePayment($second->payment_id);
        } finally {
            $this->assertSame('DUPLICATE_CANCELLED', $second->fresh()->status);
        }
    }

    public function test_unpaid_records_and_records_of_other_billable_models_do_not_count(): void
    {
        $order = DuplicateOrder::query()->create();
        $other = DuplicateOrder::query()->create();
        $this->payment($other, 'PAID');

        foreach (['PENDING', 'EXPIRED', 'AMOUNT_MISMATCH_CANCELLED', 'AMOUNT_MISMATCH', 'DUPLICATE_CANCELLED', 'DUPLICATE'] as $status) {
            $this->payment($order, $status);
        }

        $payment = $this->payment($order);
        $this->gateway->payment($payment->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);

        $this->assertSame('PAID', $order->completePayment($payment->payment_id)->status);
        $this->gateway->assertNothingCancelled();
    }

    public function test_amount_mismatch_is_decided_before_the_duplicate_check(): void
    {
        $order = DuplicateOrder::query()->create();
        $this->payment($order, 'PAID');
        $second = $this->payment($order, amount: 10000);
        $this->gateway->payment($second->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);

        $this->expectException(AmountMismatchException::class);

        try {
            $order->completePayment($second->payment_id);
        } finally {
            $this->assertSame('AMOUNT_MISMATCH_CANCELLED', $second->fresh()->status);
        }
    }

    public function test_paid_webhook_of_a_second_payment_cancels_it_and_succeeds(): void
    {
        $order = DuplicateOrder::query()->create();
        $this->payment($order, 'PAID');
        $second = $this->payment($order);
        $this->gateway->payment($second->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        Event::fake([PaymentCompleted::class, PaymentDuplicated::class]);

        $this->postPortOneWebhook('Transaction.Paid', $second->payment_id)->assertOk();

        $this->assertSame('DUPLICATE_CANCELLED', $second->fresh()->status);
        $this->gateway->assertCancelled($second->payment_id);
        Event::assertNotDispatched(PaymentCompleted::class);
        Event::assertDispatched(PaymentDuplicated::class, fn (PaymentDuplicated $event): bool => $event->wasCancelled
            && $event->source === PaymentEventSource::Webhook);
    }

    public function test_expiry_of_a_second_paid_payment_cancels_it_without_an_exception(): void
    {
        $order = DuplicateOrder::query()->create();
        $this->payment($order, 'PAID');
        $second = $this->payment($order);
        $this->gateway->payment($second->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        Event::fake([PaymentDuplicated::class]);

        $this->app->make(PaymentOperations::class)->expire($second->billable_type, $second->billable_id, $second->payment_id);

        $this->assertSame('DUPLICATE_CANCELLED', $second->fresh()->status);
        Event::assertDispatched(PaymentDuplicated::class, fn (PaymentDuplicated $event): bool => $event->wasCancelled
            && $event->source === PaymentEventSource::Expiry);
    }

    public function test_expiry_with_a_failed_cancellation_stores_duplicate_and_throws_the_portone_exception(): void
    {
        $order = DuplicateOrder::query()->create();
        $this->payment($order, 'PAID');
        $second = $this->payment($order);
        $this->gateway->payment($second->payment_id, 'PAID', totalAmount: 100, cancelledAmount: 0);
        $portOneException = new UnknownException('cancelPayment', 'PortOne rejected the cancellation.', 409, null, null, 'PAYMENT_ALREADY_CANCELLED');
        $this->gateway->failCancel($second->payment_id, $portOneException);

        try {
            $this->app->make(PaymentOperations::class)->expire($second->billable_type, $second->billable_id, $second->payment_id);
            $this->fail('Expected the PortOne exception.');
        } catch (UnknownException $exception) {
            $this->assertSame($portOneException, $exception);
        }

        $this->assertSame('DUPLICATE', $second->fresh()->status);
    }

    public function test_sync_records_a_later_cancellation_of_a_duplicate(): void
    {
        $order = DuplicateOrder::query()->create();
        $duplicate = $this->payment($order, 'DUPLICATE');
        $this->gateway->payment($duplicate->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);
        Event::fake([PaymentDuplicated::class]);

        $operations = $this->app->make(PaymentOperations::class);
        $operations->syncByPaymentId($duplicate->payment_id);
        $operations->syncByPaymentId($duplicate->payment_id);

        $fresh = $duplicate->fresh();
        $this->assertSame(['DUPLICATE_CANCELLED', 100, 100], [$fresh->status, $fresh->paid_amount, $fresh->cancelled_amount]);
        $this->gateway->assertLookedUp($duplicate->payment_id, times: 1);
        Event::assertDispatchedTimes(PaymentDuplicated::class, 1);
        Event::assertDispatched(PaymentDuplicated::class, fn (PaymentDuplicated $event): bool => $event->wasCancelled
            && $event->source === PaymentEventSource::Sync);
    }

    #[DataProvider('duplicateStatuses')]
    public function test_duplicate_records_cannot_be_refunded(string $status): void
    {
        $order = DuplicateOrder::query()->create();
        $payment = $this->payment($order, $status);

        $this->expectException(InvalidPaymentStatusException::class);

        try {
            $order->refund($payment->payment_id, 'Customer request');
        } finally {
            $this->gateway->assertNothingCancelled();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicateStatuses(): iterable
    {
        yield 'DUPLICATE_CANCELLED' => ['DUPLICATE_CANCELLED'];
        yield 'DUPLICATE' => ['DUPLICATE'];
    }

    private function payment(DuplicateOrder|DuplicateUser $billable, string $status = 'PENDING', int $amount = 100): Payment
    {
        $payment = $billable->preparePayment($amount, 'Order A');
        $paid = in_array($status, ['PAID', 'PARTIALLY_CANCELLED', 'CANCELLED', 'DUPLICATE_CANCELLED', 'DUPLICATE'], true);
        $payment->forceFill([
            'status' => $status,
            'paid_at' => $paid ? now() : null,
            'paid_amount' => $paid || str_starts_with($status, 'AMOUNT_MISMATCH') ? $amount : null,
            'cancelled_amount' => match ($status) {
                'PARTIALLY_CANCELLED' => 30,
                'CANCELLED', 'DUPLICATE_CANCELLED' => $amount,
                default => 0,
            },
        ])->save();

        return $payment;
    }
}

class DuplicateOrder extends Model
{
    use Billable;

    protected $table = 'duplicate_orders';

    public $timestamps = false;

    protected $guarded = [];

    public function allowsOnlyOnePaidPayment(): bool
    {
        return true;
    }
}

class DuplicateUser extends Model
{
    use Billable;

    protected $table = 'duplicate_users';

    public $timestamps = false;

    protected $guarded = [];
}
