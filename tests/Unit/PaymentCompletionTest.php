<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
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
        $this->assertCompletionInvariant($completed);
        $this->assertSame('PAID', $payment->fresh()->status);
    }

    public function test_non_pending_payment_is_returned_without_a_gateway_request(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $gateway->expects($this->never())->method('lookup');
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $paidAt = now()->subMinute();
        $payment->forceFill(['status' => 'PAID', 'paid_at' => $paidAt])->save();
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

    public function test_amount_mismatch_is_rejected_without_changing_the_record(): void
    {
        $this->migratePackageTables();
        $gateway = $this->gatewayMock();
        $billable = $this->billable(101);
        $payment = $billable->preparePayment(100, 'Order A');
        $gateway->expects($this->once())
            ->method('lookup')
            ->willReturn(new GatewayPayment('PAID', 99));

        try {
            $billable->completePayment($payment->payment_id);
            $this->fail('Expected a mismatched amount to be rejected.');
        } catch (AmountMismatchException) {
            $this->assertSame('PENDING', $payment->fresh()->status);
            $this->assertNull($payment->fresh()->paid_at);
            $this->assertCompletionInvariant($payment->fresh());
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
            $this->assertNull($payment->paid_at);

            return;
        }

        $this->assertSame('PAID', $payment->status);
        $this->assertSame(0, $payment->cancelled_amount);
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
}
