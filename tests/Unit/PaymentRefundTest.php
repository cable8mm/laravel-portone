<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\InvalidPaymentStatusException;
use Cable8mm\LaravelPortone\Exceptions\InvalidRefundAmountException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PortOneGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class PaymentRefundTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function tearDown(): void
    {
        Payment::flushEventListeners();
        parent::tearDown();
    }

    public function test_full_refund_omits_amount_and_updates_payment_state(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $this->paidPayment($billable, 10000);
        $gateway->expects($this->once())
            ->method('cancel')
            ->with($payment->payment_id, 'Customer request', null);

        $refunded = $billable->refund($payment->payment_id, 'Customer request');

        $this->assertSame('CANCELLED', $refunded->status);
        $this->assertSame(10000, $refunded->cancelled_amount);
        $this->assertNotNull($refunded->paid_at);
        $this->assertRefundInvariant($refunded);
    }

    public function test_partial_refund_and_remainder_refund_update_absolute_amounts(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $this->paidPayment($billable, 10000);
        $anotherPayment = $this->paidPayment($billable, 10000);
        $anotherPayment->forceFill([
            'status' => 'PARTIALLY_CANCELLED',
            'cancelled_amount' => 3000,
        ])->save();
        $expectedCalls = [
            [$payment->payment_id, 'Customer request', 3000],
            [$payment->payment_id, 'Customer request', 7000],
            [$anotherPayment->payment_id, 'Customer request', null],
        ];
        $callIndex = 0;
        $gateway->expects($this->exactly(3))
            ->method('cancel')
            ->willReturnCallback(function (string $paymentId, string $reason, ?int $amount) use ($expectedCalls, &$callIndex): void {
                $this->assertSame($expectedCalls[$callIndex][0], $paymentId);
                $this->assertSame($expectedCalls[$callIndex][1], $reason);
                $this->assertSame($expectedCalls[$callIndex][2], $amount);
                $callIndex++;
            });

        $partial = $billable->refund($payment->payment_id, 'Customer request', 3000);
        $this->assertSame('PARTIALLY_CANCELLED', $partial->status);
        $this->assertSame(3000, $partial->cancelled_amount);
        $this->assertRefundInvariant($partial);

        $completed = $billable->refund($payment->payment_id, 'Customer request', 7000);
        $this->assertSame('CANCELLED', $completed->status);
        $this->assertSame(10000, $completed->cancelled_amount);
        $this->assertRefundInvariant($completed);

        $remaining = $billable->refund($anotherPayment->payment_id, 'Customer request');
        $this->assertSame('CANCELLED', $remaining->status);
        $this->assertSame(10000, $remaining->cancelled_amount);
        $this->assertRefundInvariant($remaining);
    }

    public function test_pending_or_cancelled_payments_are_rejected_without_a_gateway_request(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('cancel');
        $billable = $this->billable(101);
        $pending = $billable->preparePayment(10000, 'Order A');
        $cancelled = $this->paidPayment($billable, 10000);
        $cancelled->forceFill([
            'status' => 'CANCELLED',
            'cancelled_amount' => 10000,
        ])->save();

        foreach ([$pending, $cancelled] as $payment) {
            try {
                $billable->refund($payment->payment_id, 'Customer request');
                $this->fail('Expected an invalid payment status to be rejected.');
            } catch (InvalidPaymentStatusException) {
                $this->assertRefundInvariant($payment->fresh());
            }
        }
    }

    public function test_unknown_and_other_billable_payment_fail_before_reason_validation(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('cancel');
        $owner = $this->billable(101);
        $other = $this->billable(202);
        $payment = $this->paidPayment($owner, 10000);

        foreach ([
            fn () => $other->refund($payment->payment_id, ''),
            fn () => $owner->refund('unknown-payment', ''),
        ] as $refund) {
            try {
                $refund();
                $this->fail('Expected an unknown scoped payment to be rejected first.');
            } catch (PaymentNotFoundException) {
                $this->assertRefundInvariant($payment->fresh());
            }
        }
    }

    public function test_empty_reason_is_rejected_before_status_validation(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('cancel');
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(10000, 'Order A');

        try {
            $billable->refund($payment->payment_id, '');
            $this->fail('Expected an empty reason to be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('PENDING', $payment->fresh()->status);
        }
    }

    public function test_invalid_refund_amounts_are_rejected_without_state_changes(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('cancel');
        $billable = $this->billable(101);
        $payment = $this->paidPayment($billable, 10000);

        foreach ([0, -1, 10001] as $amount) {
            try {
                $billable->refund($payment->payment_id, 'Customer request', $amount);
                $this->fail('Expected an invalid refund amount to be rejected.');
            } catch (InvalidRefundAmountException) {
                $this->assertRefundInvariant($payment->fresh());
                $this->assertSame(0, $payment->fresh()->cancelled_amount);
            }
        }

        $payment->forceFill([
            'status' => 'PARTIALLY_CANCELLED',
            'cancelled_amount' => 3000,
        ])->save();
        try {
            $billable->refund($payment->payment_id, 'Customer request', 7001);
            $this->fail('Expected an amount beyond the remaining balance to be rejected.');
        } catch (InvalidRefundAmountException) {
            $this->assertRefundInvariant($payment->fresh());
            $this->assertSame(3000, $payment->fresh()->cancelled_amount);
        }
    }

    public function test_missing_api_secret_and_portone_failure_leave_record_unchanged(): void
    {
        $this->migratePackageTables();
        $billable = $this->billable(101);
        $payment = $this->paidPayment($billable, 10000);
        $this->app->instance(PaymentGateway::class, new PortOneGateway);
        config(['portone.api_secret' => null]);

        try {
            $billable->refund($payment->payment_id, 'Customer request');
            $this->fail('Expected a missing API secret to be rejected.');
        } catch (ConfigurationException) {
            $this->assertSame('PAID', $payment->fresh()->status);
            $this->assertRefundInvariant($payment->fresh());
        }

        $gateway = $this->gatewayMock();
        $portOneException = new UnknownException(
            'cancelPayment',
            'PortOne rejected the cancellation.',
            409,
            null,
            null,
            'PAYMENT_ALREADY_CANCELLED',
        );
        $gateway->expects($this->once())
            ->method('cancel')
            ->willThrowException($portOneException);

        try {
            $billable->refund($payment->payment_id, 'Customer request');
            $this->fail('Expected the PortOne cancellation failure to propagate.');
        } catch (UnknownException $exception) {
            $this->assertSame($portOneException, $exception);
            $this->assertSame('PAID', $payment->fresh()->status);
            $this->assertSame(0, $payment->fresh()->cancelled_amount);
            $this->assertRefundInvariant($payment->fresh());
        }
    }

    public function test_local_write_retries_without_repeating_cancellation(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $this->paidPayment($billable, 10000);
        $gateway->expects($this->once())
            ->method('cancel')
            ->with($payment->payment_id, 'Customer request', 3000);
        $attempts = 0;
        Payment::updating(static function () use (&$attempts): void {
            if (++$attempts < 3) {
                throw new \RuntimeException('Temporary local write failure.');
            }
        });

        $refunded = $billable->refund($payment->payment_id, 'Customer request', 3000);

        $this->assertSame(3, $attempts);
        $this->assertSame('PARTIALLY_CANCELLED', $refunded->fresh()->status);
        $this->assertSame(3000, $refunded->fresh()->cancelled_amount);
        $this->assertRefundInvariant($refunded->fresh());
    }

    public function test_persistence_failure_after_three_attempts_propagates_and_keeps_payment_paid(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $this->paidPayment($billable, 10000);
        $gateway->expects($this->once())
            ->method('cancel')
            ->with($payment->payment_id, 'Customer request', 3000);
        $attempts = 0;
        Payment::updating(static function () use (&$attempts): never {
            $attempts++;
            throw new \RuntimeException('Persistent local write failure.');
        });

        try {
            $billable->refund($payment->payment_id, 'Customer request', 3000);
            $this->fail('Expected persistence failure to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Persistent local write failure.', $exception->getMessage());
            $this->assertSame(3, $attempts);
            $this->assertSame('PAID', $payment->fresh()->status);
            $this->assertSame(0, $payment->fresh()->cancelled_amount);
            $this->assertRefundInvariant($payment->fresh());
        }
    }

    public function test_concurrent_full_refunds_send_only_one_cancellation_request(): void
    {
        if (config('database.default') !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This concurrency test requires MySQL and pcntl.');
        }

        $this->migratePackageTables();
        $billable = $this->billable(303);
        $payment = $this->paidPayment($billable, 10000);
        $paymentId = $payment->payment_id;
        $directory = sys_get_temp_dir().'/portone-refund-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        DB::disconnect();
        $childIds = [];

        try {
            $firstChild = pcntl_fork();
            if ($firstChild === -1) {
                $this->fail('Could not start the first refund process.');
            }
            if ($firstChild === 0) {
                $this->refundInChild(303, $paymentId, $directory, true);
            }
            $childIds[] = $firstChild;
            $this->waitForFile($directory.'/cancel-started');

            $secondChild = pcntl_fork();
            if ($secondChild === -1) {
                $this->fail('Could not start the second refund process.');
            }
            if ($secondChild === 0) {
                file_put_contents($directory.'/second-started', 'started', LOCK_EX);
                $this->refundInChild(303, $paymentId, $directory, false);
            }
            $childIds[] = $secondChild;
            $this->waitForFile($directory.'/second-started');
            usleep(250_000);
            file_put_contents($directory.'/release-cancel', 'release', LOCK_EX);

            foreach ($childIds as $childId) {
                pcntl_waitpid($childId, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $this->assertSame('1', file_get_contents($directory.'/cancel-count'));
            $this->assertSame('CANCELLED', file_get_contents($directory.'/result-1'));
            $this->assertSame(InvalidPaymentStatusException::class, file_get_contents($directory.'/result-2'));
            DB::reconnect();
            $this->assertSame('CANCELLED', $payment->fresh()->status);
            $this->assertRefundInvariant($payment->fresh());
        } finally {
            file_put_contents($directory.'/release-cancel', 'release', LOCK_EX);
            foreach ($childIds as $childId) {
                pcntl_waitpid($childId, $status, WNOHANG);
            }
            DB::purge();

            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
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

    private function billable(int $key): RefundTestBillableModel
    {
        $billable = new RefundTestBillableModel;
        $billable->setRawAttributes(['id' => $key]);

        return $billable;
    }

    private function paidPayment(RefundTestBillableModel $billable, int $amount): Payment
    {
        $payment = $billable->preparePayment($amount, 'Order A');
        $payment->forceFill(['status' => 'PAID', 'paid_at' => now()])->save();

        return $payment->fresh();
    }

    private function gatewayMock(): PaymentGateway&MockObject
    {
        $gateway = $this->createMock(PaymentGateway::class);
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    private function assertRefundInvariant(Payment $payment): void
    {
        $this->assertGreaterThanOrEqual(0, $payment->cancelled_amount);
        $this->assertLessThanOrEqual($payment->amount, $payment->cancelled_amount);

        if ($payment->status === 'PAID') {
            $this->assertSame(0, $payment->cancelled_amount);
            $this->assertNotNull($payment->paid_at);

            return;
        }

        if ($payment->status === 'PARTIALLY_CANCELLED') {
            $this->assertGreaterThan(0, $payment->cancelled_amount);
            $this->assertLessThan($payment->amount, $payment->cancelled_amount);
            $this->assertNotNull($payment->paid_at);

            return;
        }

        if ($payment->status === 'CANCELLED') {
            $this->assertSame($payment->amount, $payment->cancelled_amount);
            $this->assertNotNull($payment->paid_at);
        }
    }

    private function waitForFile(string $path): void
    {
        $deadline = time() + 10;
        while (! is_file($path) && time() < $deadline) {
            usleep(10_000);
        }

        $this->assertFileExists($path, "Expected process coordination file at {$path}.");
    }

    private function refundInChild(int $billableId, string $paymentId, string $directory, bool $blockCancel): never
    {
        DB::purge();
        $this->app->instance(PaymentGateway::class, new RefundConcurrencyGateway($directory, $blockCancel));
        $billable = $this->billable($billableId);

        try {
            $payment = $billable->refund($paymentId, 'Concurrent refund');
            file_put_contents($directory.'/result-'.($blockCancel ? '1' : '2'), $payment->status, LOCK_EX);
            exit(0);
        } catch (InvalidPaymentStatusException $exception) {
            file_put_contents($directory.'/result-2', $exception::class, LOCK_EX);
            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($directory.'/failure-'.($blockCancel ? '1' : '2'), $exception::class, LOCK_EX);
            exit(1);
        }
    }
}

class RefundTestBillableModel extends Model
{
    use Billable;
}

class RefundConcurrencyGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $directory,
        private readonly bool $blockCancel,
    ) {}

    public function lookup(string $paymentId): GatewayPayment
    {
        throw new \LogicException('Refund concurrency must not perform a lookup.');
    }

    public function cancel(string $paymentId, string $reason, ?int $amount): void
    {
        $counter = fopen($this->directory.'/cancel-count', 'c+');
        flock($counter, LOCK_EX);
        $count = (int) stream_get_contents($counter);
        ftruncate($counter, 0);
        rewind($counter);
        fwrite($counter, (string) ($count + 1));
        fflush($counter);
        flock($counter, LOCK_UN);
        fclose($counter);

        if ($this->blockCancel) {
            file_put_contents($this->directory.'/cancel-started', 'started', LOCK_EX);
            $deadline = time() + 10;
            while (! is_file($this->directory.'/release-cancel') && time() < $deadline) {
                usleep(10_000);
            }
        }
    }
}
