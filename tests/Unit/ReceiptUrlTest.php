<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\DuplicatePaymentException;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\Testing\FakePaymentGateway;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * T-042: every write that applies a PortOne lookup stores its receiptUrl in receipt_url, and a lookup without
 * one leaves receipt_url unchanged (FR-113, FR-114, AC-098).
 *
 * The live lookups recorded in tests/E2E/portone-receipt-url-observation.json carry a non-empty receiptUrl on
 * host testpartner.kcp.co.kr for a PAID and a CANCELLED payment; the URL paths here are placeholders because
 * the recording redacts them. Amounts and statuses are traced as in DuplicatePaymentTest.
 */
class ReceiptUrlTest extends TestCase
{
    private const PAID_RECEIPT = 'https://testpartner.kcp.co.kr/receipt-paid';

    private const CANCELLED_RECEIPT = 'https://testpartner.kcp.co.kr/receipt-cancelled';

    private FakePaymentGateway $fake;

    private ReceiptGateway $gateway;

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

        foreach (['receipt_users', 'receipt_orders'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
            });
        }

        $this->fake = FakePaymentGateway::bind();
        $this->gateway = new ReceiptGateway($this->fake);
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    public function test_lookup_maps_receipt_url_and_treats_missing_or_empty_as_none(): void
    {
        $this->assertSame(self::PAID_RECEIPT, $this->map(['receiptUrl' => self::PAID_RECEIPT])->receiptUrl);
        $this->assertNull($this->map([])->receiptUrl);
        $this->assertNull($this->map(['receiptUrl' => ''])->receiptUrl);
        $this->assertNull($this->map(['receiptUrl' => 42])->receiptUrl);
    }

    public function test_a_new_payment_has_no_receipt_url(): void
    {
        $payment = ReceiptUser::query()->create()->preparePayment(100, 'Order A');

        $this->assertNull($payment->fresh()->receipt_url);
        $this->assertArrayHasKey('receipt_url', $payment->fresh()->getAttributes());
    }

    public function test_completion_stores_the_receipt_url(): void
    {
        $user = ReceiptUser::query()->create();
        $payment = $user->preparePayment(100, 'Order A');
        $this->respond($payment->payment_id, 'PAID', 100, 0, self::PAID_RECEIPT);

        $completed = $user->completePayment($payment->payment_id);

        $this->assertSame(['PAID', self::PAID_RECEIPT], [$completed->status, $completed->fresh()->receipt_url]);
    }

    public function test_completion_without_receipt_url_leaves_it_null(): void
    {
        $user = ReceiptUser::query()->create();
        $payment = $user->preparePayment(100, 'Order A');
        $this->respond($payment->payment_id, 'PAID', 100, 0, null);

        $this->assertSame('PAID', $user->completePayment($payment->payment_id)->status);
        $this->assertNull($payment->fresh()->receipt_url);
    }

    public function test_cancellation_sync_stores_the_receipt_url_of_its_lookup(): void
    {
        $user = ReceiptUser::query()->create();
        $payment = $this->stored($user, 'PAID', receiptUrl: self::PAID_RECEIPT);
        $this->respond($payment->payment_id, 'CANCELLED', 100, 100, self::CANCELLED_RECEIPT);

        $synced = $this->app->make(PaymentOperations::class)->syncByPaymentId($payment->payment_id);

        $this->assertSame(['CANCELLED', self::CANCELLED_RECEIPT], [$synced->status, $synced->fresh()->receipt_url]);
    }

    public function test_cancellation_sync_without_receipt_url_leaves_it_unchanged(): void
    {
        $user = ReceiptUser::query()->create();
        $payment = $this->stored($user, 'PAID', receiptUrl: self::PAID_RECEIPT);
        $this->respond($payment->payment_id, 'PARTIAL_CANCELLED', 100, 30, null);

        $synced = $this->app->make(PaymentOperations::class)->syncByPaymentId($payment->payment_id);

        $this->assertSame(['PARTIALLY_CANCELLED', self::PAID_RECEIPT], [$synced->status, $synced->fresh()->receipt_url]);
    }

    public function test_cancellation_sync_with_no_change_writes_no_receipt_url(): void
    {
        $user = ReceiptUser::query()->create();
        $payment = $this->stored($user, 'PAID', receiptUrl: self::PAID_RECEIPT);
        $this->respond($payment->payment_id, 'PAID', 100, 0, self::CANCELLED_RECEIPT);

        $this->app->make(PaymentOperations::class)->syncByPaymentId($payment->payment_id);

        $this->assertSame(['PAID', self::PAID_RECEIPT], [$payment->fresh()->status, $payment->fresh()->receipt_url]);
    }

    public function test_duplicate_sync_stores_the_receipt_url(): void
    {
        $order = ReceiptOrder::query()->create();
        $payment = $this->stored($order, 'DUPLICATE');
        $this->respond($payment->payment_id, 'CANCELLED', 100, 100, self::CANCELLED_RECEIPT);

        $synced = $this->app->make(PaymentOperations::class)->syncByPaymentId($payment->payment_id);

        $this->assertSame(['DUPLICATE_CANCELLED', self::CANCELLED_RECEIPT], [$synced->status, $synced->fresh()->receipt_url]);
    }

    public function test_refund_leaves_the_receipt_url_unchanged(): void
    {
        $user = ReceiptUser::query()->create();
        $payment = $this->stored($user, 'PAID', receiptUrl: self::PAID_RECEIPT);

        $refunded = $user->refund($payment->payment_id, 'Customer request');

        $this->assertSame(['CANCELLED', self::PAID_RECEIPT], [$refunded->status, $refunded->fresh()->receipt_url]);
    }

    public function test_duplicate_cancellation_stores_the_receipt_url_whether_or_not_the_cancellation_succeeds(): void
    {
        $order = ReceiptOrder::query()->create();
        $this->stored($order, 'PAID');
        $cancelled = $order->preparePayment(100, 'Order A');
        $failed = $order->preparePayment(100, 'Order A');
        $this->respond($cancelled->payment_id, 'PAID', 100, 0, self::PAID_RECEIPT);
        $this->respond($failed->payment_id, 'PAID', 100, 0, self::PAID_RECEIPT);
        $this->fake->failCancel($failed->payment_id, $this->alreadyCancelled());

        foreach ([$cancelled, $failed] as $payment) {
            try {
                $order->completePayment($payment->payment_id);
                $this->fail('Expected DuplicatePaymentException.');
            } catch (DuplicatePaymentException) {
            }
        }

        $this->assertSame(['DUPLICATE_CANCELLED', self::PAID_RECEIPT], [$cancelled->fresh()->status, $cancelled->fresh()->receipt_url]);
        $this->assertSame(['DUPLICATE', self::PAID_RECEIPT], [$failed->fresh()->status, $failed->fresh()->receipt_url]);
    }

    public function test_amount_mismatch_stores_the_receipt_url_whether_or_not_the_cancellation_succeeds(): void
    {
        $user = ReceiptUser::query()->create();
        $cancelled = $user->preparePayment(10000, 'Order A');
        $failed = $user->preparePayment(10000, 'Order A');
        $this->respond($cancelled->payment_id, 'PAID', 100, 0, self::PAID_RECEIPT);
        $this->respond($failed->payment_id, 'PAID', 100, 0, self::PAID_RECEIPT);
        $this->fake->failCancel($failed->payment_id, $this->alreadyCancelled());

        foreach ([$cancelled, $failed] as $payment) {
            try {
                $user->completePayment($payment->payment_id);
                $this->fail('Expected AmountMismatchException.');
            } catch (AmountMismatchException) {
            }
        }

        $this->assertSame(['AMOUNT_MISMATCH_CANCELLED', self::PAID_RECEIPT], [$cancelled->fresh()->status, $cancelled->fresh()->receipt_url]);
        $this->assertSame(['AMOUNT_MISMATCH', self::PAID_RECEIPT], [$failed->fresh()->status, $failed->fresh()->receipt_url]);
    }

    public function test_expiry_stores_the_receipt_url_only_when_it_completes_the_payment(): void
    {
        $user = ReceiptUser::query()->create();
        $paid = $user->preparePayment(100, 'Order A');
        $failed = $user->preparePayment(100, 'Order A');
        $this->respond($paid->payment_id, 'PAID', 100, 0, self::PAID_RECEIPT);
        $this->respond($failed->payment_id, 'FAILED', null, null, self::PAID_RECEIPT);
        $operations = $this->app->make(PaymentOperations::class);

        foreach ([$paid, $failed] as $payment) {
            $operations->expire($payment->billable_type, $payment->billable_id, $payment->payment_id);
        }

        $this->assertSame(['PAID', self::PAID_RECEIPT], [$paid->fresh()->status, $paid->fresh()->receipt_url]);
        $this->assertSame(['EXPIRED', null], [$failed->fresh()->status, $failed->fresh()->receipt_url]);
    }

    private function respond(string $paymentId, string $status, ?int $total, ?int $cancelled, ?string $receiptUrl): void
    {
        $this->fake->payment($paymentId, $status, totalAmount: $total, cancelledAmount: $cancelled);
        $this->gateway->receiptUrls[$paymentId] = $receiptUrl;
    }

    private function stored(ReceiptUser|ReceiptOrder $billable, string $status, ?string $receiptUrl = null): Payment
    {
        $payment = $billable->preparePayment(100, 'Order A');
        $payment->forceFill([
            'status' => $status,
            'paid_at' => now(),
            'paid_amount' => 100,
            'cancelled_amount' => 0,
            'receipt_url' => $receiptUrl,
        ])->save();

        return $payment;
    }

    /**
     * T-010 recorded HTTP 409 PAYMENT_ALREADY_CANCELLED for a repeated cancellation
     * (tests/E2E/portone-payment-refund-observation.json).
     */
    private function alreadyCancelled(): UnknownException
    {
        return new UnknownException('cancelPayment', 'Payment already cancelled', status: 409, errorType: 'PAYMENT_ALREADY_CANCELLED');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function map(array $data): GatewayPayment
    {
        return (new class extends PortOneGateway
        {
            /**
             * @param  array<string, mixed>  $data
             */
            public function map(array $data): GatewayPayment
            {
                return $this->gatewayPayment('PAID', $data);
            }
        })->map($data);
    }
}

/**
 * Adds a receiptUrl per Payment ID to the results of FakePaymentGateway, which sets status and amounts only.
 */
class ReceiptGateway implements PaymentGateway
{
    /** @var array<string, string|null> */
    public array $receiptUrls = [];

    public function __construct(private readonly FakePaymentGateway $fake) {}

    public function lookup(string $paymentId): GatewayPayment
    {
        return $this->withReceipt($paymentId, $this->fake->lookup($paymentId));
    }

    public function lookupForExpiry(string $paymentId): GatewayPayment
    {
        return $this->withReceipt($paymentId, $this->fake->lookupForExpiry($paymentId));
    }

    public function cancel(string $paymentId, string $reason, ?int $amount): void
    {
        $this->fake->cancel($paymentId, $reason, $amount);
    }

    private function withReceipt(string $paymentId, GatewayPayment $result): GatewayPayment
    {
        return new GatewayPayment($result->status, $result->totalAmount, $result->found, $result->cancelledAmount, $this->receiptUrls[$paymentId] ?? null);
    }
}

class ReceiptUser extends Model
{
    use Billable;

    protected $table = 'receipt_users';

    public $timestamps = false;

    protected $guarded = [];
}

class ReceiptOrder extends Model
{
    use Billable;

    protected $table = 'receipt_orders';

    public $timestamps = false;

    protected $guarded = [];

    public function allowsOnlyOnePaidPayment(): bool
    {
        return true;
    }
}
