<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Exceptions\DuplicatePaymentException;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * T-041 (AC-097): two concurrent completions of two records of one single-payment Billable model leave exactly one PAID.
 *
 * Runs only against MySQL with pcntl, where the row locks of the Billable reference serialize the two processes, as
 * the other concurrency tests do. The PortOne lookup is traced to tests/E2E/portone-payment-command-observation.json
 * (PAID 100, cancelled 0).
 */
class DuplicatePaymentConcurrencyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    public function test_concurrent_completions_of_one_single_payment_billable_leave_one_paid(): void
    {
        if (config('database.default') !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This concurrency test requires MySQL and pcntl.');
        }

        foreach (['portone_webhooks', 'portone_payments', 'portone_customers', 'migrations'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }

        $order = new DuplicateConcurrencyOrder;
        $order->setRawAttributes(['id' => 505]);
        $first = $order->preparePayment(100, 'Order A');
        $second = $order->preparePayment(100, 'Order A');
        $directory = sys_get_temp_dir().'/portone-duplicate-race-'.bin2hex(random_bytes(8));
        mkdir($directory);

        DB::disconnect();
        $childIds = [];

        try {
            $firstChild = pcntl_fork();
            $this->assertNotSame(-1, $firstChild);
            if ($firstChild === 0) {
                $this->completeInChild($directory, $order, $first->payment_id, 1, blockLookup: true);
            }
            $childIds[] = $firstChild;
            $this->waitForFile($directory.'/lookup-started');

            $secondChild = pcntl_fork();
            $this->assertNotSame(-1, $secondChild);
            if ($secondChild === 0) {
                file_put_contents($directory.'/second-started', 'started', LOCK_EX);
                $this->completeInChild($directory, $order, $second->payment_id, 2, blockLookup: false);
            }
            $childIds[] = $secondChild;
            $this->waitForFile($directory.'/second-started');
            usleep(250_000);
            // The second completion must still be waiting for the Billable reference: it has not looked anything up.
            $this->assertFileDoesNotExist($directory.'/lookup-'.$second->payment_id);
            file_put_contents($directory.'/release-lookup', 'release', LOCK_EX);

            foreach ($childIds as $childId) {
                pcntl_waitpid($childId, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $this->assertSame('PAID', file_get_contents($directory.'/result-1'));
            $this->assertSame('duplicate:cancelled', file_get_contents($directory.'/result-2'));
            $this->assertSame($second->payment_id.PHP_EOL, file_get_contents($directory.'/cancellations'));
            DB::reconnect();
            $this->assertSame(['PAID', 'DUPLICATE_CANCELLED'], [$first->fresh()->status, $second->fresh()->status]);
            $this->assertSame(1, Payment::query()->where('status', 'PAID')->count());
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

    private function completeInChild(string $directory, DuplicateConcurrencyOrder $order, string $paymentId, int $child, bool $blockLookup): never
    {
        DB::purge();
        $this->app->instance(PaymentGateway::class, new DuplicateConcurrencyGateway($directory, $blockLookup));

        try {
            $result = $order->completePayment($paymentId)->status;
        } catch (DuplicatePaymentException $exception) {
            $result = 'duplicate:'.($exception->wasCancelled() ? 'cancelled' : 'failed');
        }

        file_put_contents($directory.'/result-'.$child, $result, LOCK_EX);

        exit(0);
    }

    private function waitForFile(string $path): void
    {
        $deadline = time() + 10;
        while (! is_file($path) && time() < $deadline) {
            usleep(10_000);
        }

        $this->assertFileExists($path, "Expected process coordination file at {$path}.");
    }
}

class DuplicateConcurrencyOrder extends Model
{
    use Billable;

    public function allowsOnlyOnePaidPayment(): bool
    {
        return true;
    }
}

class DuplicateConcurrencyGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $directory,
        private readonly bool $blockLookup,
    ) {}

    public function lookup(string $paymentId): GatewayPayment
    {
        file_put_contents($this->directory.'/lookup-'.$paymentId, 'looked up', LOCK_EX);

        if ($this->blockLookup) {
            file_put_contents($this->directory.'/lookup-started', 'started', LOCK_EX);
            $deadline = time() + 10;
            while (! is_file($this->directory.'/release-lookup') && time() < $deadline) {
                usleep(10_000);
            }
        }

        return new GatewayPayment('PAID', 100, cancelledAmount: 0);
    }

    public function lookupForExpiry(string $paymentId): GatewayPayment
    {
        throw new \LogicException('Duplicate concurrency must not perform an expiry lookup.');
    }

    public function cancel(string $paymentId, string $reason, ?int $amount): void
    {
        file_put_contents($this->directory.'/cancellations', $paymentId.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
