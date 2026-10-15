<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\InvalidPaymentStatusException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class PaymentCompletionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function tearDown(): void
    {
        Payment::flushEventListeners();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paid_payment_is_completed_with_paid_at(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->with($payment->payment_id)
            ->willReturn(new GatewayPayment('PAID', 100));

        $completed = $billable->completePayment($payment->payment_id);

        $this->assertSame('PAID', $completed->status);
        $this->assertNotNull($completed->paid_at);
        $this->assertSame(100, $completed->paid_amount);
        $this->assertCompletionInvariant($completed);
        $this->assertSame('PAID', $payment->fresh()->status);
        $this->assertSame(100, $payment->fresh()->paid_amount);
    }

    /**
     * AC-076: a webhook handler knows only the Payment ID.
     */
    public function test_payment_is_completed_by_payment_id_alone(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $payment = $this->billable(101)->preparePayment(100, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->with($payment->payment_id)
            ->willReturn(new GatewayPayment('PAID', 100));
        $operations = $this->app->make(PaymentOperations::class);

        $completed = $operations->completeByPaymentId($payment->payment_id);
        $again = $operations->completeByPaymentId($payment->payment_id);

        $this->assertSame('PAID', $completed->status);
        $this->assertSame(100, $completed->paid_amount);
        $this->assertSame($completed->paid_at?->toISOString(), $again->paid_at?->toISOString());
        $this->assertCompletionInvariant($payment->fresh());
    }

    public function test_completion_by_payment_id_applies_the_amount_mismatch_rules(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $payment = $this->billable(101)->preparePayment(10000, 'Order A');
        $gateway->expects($this->once())->method('lookup')->willReturn(new GatewayPayment('PAID', 100));
        $gateway->expects($this->once())->method('cancel')->with($payment->payment_id, '결제 금액 불일치로 자동 취소', null);
        $operations = $this->app->make(PaymentOperations::class);

        for ($call = 1; $call <= 2; $call++) {
            try {
                $operations->completeByPaymentId($payment->payment_id);
                $this->fail('Expected a mismatched amount to be rejected.');
            } catch (AmountMismatchException $exception) {
                $this->assertTrue($exception->wasCancelled());
            }
        }

        $this->assertSame('AMOUNT_MISMATCH_CANCELLED', $payment->fresh()->status);
    }

    public function test_completion_by_unknown_payment_id_is_rejected_without_lookup(): void
    {
        $this->migratePackageTables();
        $this->gatewayMock()->expects($this->never())->method('lookup');

        $this->expectException(PaymentNotFoundException::class);
        $this->app->make(PaymentOperations::class)->completeByPaymentId('unknown-payment-id');
    }

    public function test_non_pending_payment_is_returned_without_a_gateway_request(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('lookup');
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $paidAt = now()->subMinute();
        $payment->forceFill(['status' => 'PAID', 'paid_at' => $paidAt, 'paid_amount' => 100])->save();
        $payment->refresh();
        $this->assertNotNull($payment->paid_at);
        $paidAt = $payment->paid_at;
        $updatedAt = $payment->updated_at->toISOString();
        Carbon::setTestNow($payment->updated_at->copy()->addSecond());

        try {
            $result = $billable->completePayment($payment->payment_id);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('PAID', $result->status);
        $this->assertSame($paidAt->toISOString(), $result->paid_at->toISOString());
        $this->assertSame($updatedAt, $result->updated_at->toISOString());
        $this->assertCompletionInvariant($result);
    }

    public function test_unknown_or_other_billable_payment_is_rejected_before_lookup(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('lookup');
        $owner = $this->billable(101);
        $other = $this->billable(202);
        $payment = $owner->preparePayment(100, 'Order A');

        try {
            $other->completePayment($payment->payment_id);
            $this->fail('Expected a payment outside the Billable scope to be rejected.');
        } catch (PaymentNotFoundException) {
            $this->assertDatabaseHas('portone_payments', [
                'payment_id' => $payment->payment_id,
                'status' => 'PENDING',
            ]);
            $this->assertCompletionInvariant($payment->fresh());
        }

        try {
            $owner->completePayment('unknown-payment-id');
            $this->fail('Expected an unknown local payment to be rejected.');
        } catch (PaymentNotFoundException) {
            $this->assertSame('PENDING', $payment->fresh()->status);
            $this->assertCompletionInvariant($payment->fresh());
        }
    }

    public function test_not_paid_payment_is_rejected_without_changing_the_record(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->willReturn(new GatewayPayment('FAILED', 100));

        try {
            $billable->completePayment($payment->payment_id);
            $this->fail('Expected an unpaid payment to be rejected.');
        } catch (PaymentNotPaidException) {
            $this->assertSame('PENDING', $payment->fresh()->status);
            $this->assertNull($payment->fresh()->paid_at);
            $this->assertCompletionInvariant($payment->fresh());
        }
    }

    /**
     * AC-016: the lookup values are traced to T-003 (PAID with the paid total); the prepared amount differs.
     */
    public function test_amount_mismatch_cancels_the_full_paid_amount_and_records_it(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(10000, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->willReturn(new GatewayPayment('PAID', 100));
        $gateway->expects($this->once())
            ->method('cancel')
            ->with($payment->payment_id, '결제 금액 불일치로 자동 취소', null);

        try {
            $billable->completePayment($payment->payment_id);
            $this->fail('Expected a mismatched amount to be rejected.');
        } catch (AmountMismatchException $exception) {
            $this->assertSame(100, $exception->paidAmount());
            $this->assertTrue($exception->wasCancelled());
            $this->assertNull($exception->getPrevious());
        }

        $stored = $payment->fresh();
        $this->assertSame('AMOUNT_MISMATCH_CANCELLED', $stored->status);
        $this->assertSame(10000, $stored->amount);
        $this->assertSame(100, $stored->paid_amount);
        $this->assertSame(100, $stored->cancelled_amount);
        $this->assertCompletionInvariant($stored);
    }

    public function test_amount_mismatch_uses_the_configured_cancel_reason_and_recovers_an_expired_payment(): void
    {
        $this->migratePackageTables();
        config(['portone.amount_mismatch_cancel_reason' => 'Amount tampered']);
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(10000, 'Order A');
        $payment->forceFill(['status' => 'EXPIRED'])->save();
        $gateway->expects($this->once())->method('lookup')->willReturn(new GatewayPayment('PAID', 100));
        $gateway->expects($this->once())->method('cancel')->with($payment->payment_id, 'Amount tampered', null);

        $this->expectException(AmountMismatchException::class);

        try {
            $billable->completePayment($payment->payment_id);
        } finally {
            $this->assertSame('AMOUNT_MISMATCH_CANCELLED', $payment->fresh()->status);
        }
    }

    /**
     * AC-069: the cancellation failure is the PAYMENT_ALREADY_CANCELLED error recorded by T-010.
     */
    public function test_failed_automatic_cancellation_is_recorded_for_the_operator(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(10000, 'Order A');
        $portOneException = new UnknownException(
            'cancelPayment',
            'PortOne rejected the cancellation.',
            409,
            null,
            null,
            'PAYMENT_ALREADY_CANCELLED',
        );
        $gateway->expects($this->once())->method('lookup')->willReturn(new GatewayPayment('PAID', 100));
        $gateway->expects($this->once())->method('cancel')->willThrowException($portOneException);

        try {
            $billable->completePayment($payment->payment_id);
            $this->fail('Expected a mismatched amount to be rejected.');
        } catch (AmountMismatchException $exception) {
            $this->assertSame(100, $exception->paidAmount());
            $this->assertFalse($exception->wasCancelled());
            $this->assertSame($portOneException, $exception->getPrevious());
        }

        $stored = $payment->fresh();
        $this->assertSame('AMOUNT_MISMATCH', $stored->status);
        $this->assertSame(10000, $stored->amount);
        $this->assertSame(100, $stored->paid_amount);
        $this->assertSame(0, $stored->cancelled_amount);
        $this->assertCompletionInvariant($stored);
    }

    /**
     * AC-070: browser and webhook completing the same payment must not cancel twice.
     */
    public function test_repeated_completion_of_a_mismatched_payment_sends_no_portone_request(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $cancelled = $billable->preparePayment(10000, 'Order A');
        $failed = $billable->preparePayment(10000, 'Order B');
        $gateway->expects($this->exactly(2))->method('lookup')->willReturn(new GatewayPayment('PAID', 100));
        $gateway->expects($this->exactly(2))->method('cancel')->willReturnCallback(
            static function (string $paymentId) use ($failed): void {
                if ($paymentId === $failed->payment_id) {
                    throw new UnknownException('cancelPayment', 'PortOne rejected the cancellation.', 409, null, null, 'PAYMENT_ALREADY_CANCELLED');
                }
            },
        );

        foreach ([[$cancelled, true], [$failed, false]] as [$payment, $wasCancelled]) {
            for ($call = 1; $call <= 2; $call++) {
                try {
                    $billable->completePayment($payment->payment_id);
                    $this->fail('Expected a mismatched amount to be rejected.');
                } catch (AmountMismatchException $exception) {
                    $this->assertSame(100, $exception->paidAmount());
                    $this->assertSame($wasCancelled, $exception->wasCancelled());
                }
            }
        }

        $this->assertSame('AMOUNT_MISMATCH_CANCELLED', $cancelled->fresh()->status);
        $this->assertSame('AMOUNT_MISMATCH', $failed->fresh()->status);
    }

    public function test_refund_rejects_mismatched_payments_without_contacting_portone(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('cancel');
        $billable = $this->billable(101);

        foreach (['AMOUNT_MISMATCH_CANCELLED', 'AMOUNT_MISMATCH'] as $status) {
            $payment = $billable->preparePayment(10000, 'Order A');
            $payment->forceFill(['status' => $status, 'paid_amount' => 100])->save();

            try {
                $billable->refund($payment->payment_id, 'Customer request');
                $this->fail('Expected a mismatched payment to be rejected.');
            } catch (InvalidPaymentStatusException) {
                $this->assertSame($status, $payment->fresh()->status);
            }
        }
    }

    public function test_unconfigured_api_secret_is_checked_only_for_a_pending_owned_payment(): void
    {
        $this->migratePackageTables();
        config(['portone.api_secret' => null]);
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');

        try {
            $billable->completePayment($payment->payment_id);
            $this->fail('Expected missing API credentials to be rejected.');
        } catch (ConfigurationException) {
            $this->assertDatabaseHas('portone_payments', [
                'payment_id' => $payment->payment_id,
                'status' => 'PENDING',
            ]);
            $this->assertCompletionInvariant($payment->fresh());
        }

        $other = $this->billable(202);
        try {
            $other->completePayment($payment->payment_id);
            $this->fail('Expected an unknown local payment to be rejected first.');
        } catch (PaymentNotFoundException) {
            $this->assertSame('PENDING', $payment->fresh()->status);
            $this->assertCompletionInvariant($payment->fresh());
        }

        $payment->forceFill(['status' => 'PAID', 'paid_at' => now()])->save();
        $this->assertSame('PAID', $billable->completePayment($payment->payment_id)->status);
    }

    public function test_local_write_retries_without_repeating_the_gateway_request(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->willReturn(new GatewayPayment('PAID', 100));
        $attempts = 0;
        Payment::updating(static function () use (&$attempts): void {
            if (++$attempts < 3) {
                throw new \RuntimeException('Temporary local write failure.');
            }
        });

        $completed = $billable->completePayment($payment->payment_id);

        $this->assertSame(3, $attempts);
        $this->assertSame('PAID', $completed->fresh()->status);
        $this->assertNotNull($completed->paid_at);
        $this->assertCompletionInvariant($completed->fresh());
    }

    public function test_persistence_failure_after_three_attempts_propagates_and_keeps_payment_pending(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->willReturn(new GatewayPayment('PAID', 100));
        $attempts = 0;
        Payment::updating(static function () use (&$attempts): never {
            $attempts++;
            throw new \RuntimeException('Persistent local write failure.');
        });

        try {
            $billable->completePayment($payment->payment_id);
            $this->fail('Expected persistence failure to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Persistent local write failure.', $exception->getMessage());
            $this->assertSame(3, $attempts);
            $this->assertSame('PENDING', $payment->fresh()->status);
            $this->assertNull($payment->fresh()->paid_at);
            $this->assertCompletionInvariant($payment->fresh());
        }
    }

    public function test_concurrent_completion_calls_send_only_one_gateway_lookup(): void
    {
        if (config('database.default') !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This concurrency test requires MySQL and pcntl.');
        }

        $this->migratePackageTables();
        $billable = $this->billable(303);
        $payment = $billable->preparePayment(100, 'Order A');
        $paymentId = $payment->payment_id;
        $directory = sys_get_temp_dir().'/portone-payment-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        DB::disconnect();
        $childIds = [];

        try {
            $firstChild = pcntl_fork();
            if ($firstChild === -1) {
                $this->fail('Could not start the first completion process.');
            }
            if ($firstChild === 0) {
                $this->completeInChild(303, $paymentId, $directory, true);
            }
            $childIds[] = $firstChild;
            $this->waitForFile($directory.'/lookup-started');

            $secondChild = pcntl_fork();
            if ($secondChild === -1) {
                $this->fail('Could not start the second completion process.');
            }
            if ($secondChild === 0) {
                file_put_contents($directory.'/second-started', 'started', LOCK_EX);
                $this->completeInChild(303, $paymentId, $directory, false);
            }
            $childIds[] = $secondChild;
            $this->waitForFile($directory.'/second-started');
            usleep(250_000);
            file_put_contents($directory.'/release-lookup', 'release', LOCK_EX);

            foreach ($childIds as $childId) {
                pcntl_waitpid($childId, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $this->assertSame('1', file_get_contents($directory.'/lookup-count'));
            $this->assertSame('PAID', file_get_contents($directory.'/result-1'));
            $this->assertSame('PAID', file_get_contents($directory.'/result-2'));
            DB::reconnect();
            $this->assertSame('PAID', $payment->fresh()->status);
            $this->assertCompletionInvariant($payment->fresh());
        } finally {
            file_put_contents($directory.'/release-lookup', 'release', LOCK_EX);
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

    private function gatewayMock(): PaymentGateway&MockObject
    {
        $gateway = $this->createMock(PaymentGateway::class);
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    private function completeInChild(int $billableId, string $paymentId, string $directory, bool $blockLookup): never
    {
        DB::purge();
        $this->app->instance(PaymentGateway::class, new CompletionConcurrencyGateway($directory, $blockLookup));
        $billable = $this->billable($billableId);

        try {
            $payment = $billable->completePayment($paymentId);
            file_put_contents($directory.'/result-'.($blockLookup ? '1' : '2'), $payment->status, LOCK_EX);
            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($directory.'/failure-'.($blockLookup ? '1' : '2'), $exception::class, LOCK_EX);
            exit(1);
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

    private function assertCompletionInvariant(Payment $payment): void
    {
        if ($payment->status === 'PENDING') {
            $this->assertSame(0, $payment->cancelled_amount);
            $this->assertNull($payment->paid_amount);
            $this->assertNull($payment->paid_at);

            return;
        }

        if (in_array($payment->status, ['AMOUNT_MISMATCH_CANCELLED', 'AMOUNT_MISMATCH'], true)) {
            $this->assertNotNull($payment->paid_amount);
            $this->assertNotSame($payment->amount, $payment->paid_amount);
            $this->assertSame($payment->status === 'AMOUNT_MISMATCH_CANCELLED' ? $payment->paid_amount : 0, $payment->cancelled_amount);
            $this->assertNull($payment->paid_at);

            return;
        }

        $this->assertSame('PAID', $payment->status);
        $this->assertSame(0, $payment->cancelled_amount);
        $this->assertSame($payment->amount, $payment->paid_amount);
        $this->assertNotNull($payment->paid_at);
    }

    private function billable(int $key): CompletionTestBillableModel
    {
        $billable = new CompletionTestBillableModel;
        $billable->setRawAttributes(['id' => $key]);

        return $billable;
    }
}

class CompletionTestBillableModel extends Model
{
    use Billable;
}

class CompletionConcurrencyGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $directory,
        private readonly bool $blockLookup,
    ) {}

    public function lookup(string $paymentId): GatewayPayment
    {
        $counterPath = $this->directory.'/lookup-count';
        $counter = fopen($counterPath, 'c+');
        flock($counter, LOCK_EX);
        $count = (int) stream_get_contents($counter);
        ftruncate($counter, 0);
        rewind($counter);
        fwrite($counter, (string) ($count + 1));
        fflush($counter);
        flock($counter, LOCK_UN);
        fclose($counter);

        if ($this->blockLookup) {
            file_put_contents($this->directory.'/lookup-started', 'started', LOCK_EX);
            $deadline = time() + 10;
            while (! is_file($this->directory.'/release-lookup') && time() < $deadline) {
                usleep(10_000);
            }
        }

        return new GatewayPayment('PAID', 100);
    }

    public function lookupForExpiry(string $paymentId): GatewayPayment
    {
        throw new \LogicException('Completion concurrency must not perform an expiry lookup.');
    }

    public function cancel(string $paymentId, string $reason, ?int $amount): void
    {
        throw new \LogicException('Completion concurrency must not send cancellation requests.');
    }
}
