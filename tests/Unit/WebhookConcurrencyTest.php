<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Events\PaymentCancelled;
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\Models\Webhook;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\Testing\SignedWebhook;
use Cable8mm\PortonePhp\Webhook\WebhookSigner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * T-039 (AC-090): two concurrent deliveries of one webhook-id apply the effect once and dispatch the events once.
 *
 * Runs only against MySQL with pcntl, where the webhook-id row lock serializes the two processes, as the
 * concurrency tests of completion and refund do. The PortOne lookup is traced to
 * tests/E2E/portone-payment-cancellation-observation.json (T-010): CANCELLED with cancelled amount 100.
 */
class WebhookConcurrencyTest extends TestCase
{
    private const SECRET = 'dGVzdC1zZWNyZXQ=';

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('portone.webhook_secret', self::SECRET);
    }

    public function test_concurrent_deliveries_of_one_webhook_id_apply_the_effect_once(): void
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

        $billable = new WebhookConcurrencyBillableModel;
        $billable->setRawAttributes(['id' => 404]);
        $payment = $billable->preparePayment(100, 'Order A');
        $payment->forceFill(['status' => 'PAID', 'paid_at' => now(), 'paid_amount' => 100])->save();
        $body = SignedWebhook::body('Transaction.Cancelled', $payment->payment_id);
        $directory = sys_get_temp_dir().'/portone-webhook-race-'.bin2hex(random_bytes(8));
        mkdir($directory);

        foreach ([WebhookReceived::class, PaymentCancelled::class] as $event) {
            Event::listen($event, static function () use ($directory, $event): void {
                file_put_contents($directory.'/events', class_basename($event).PHP_EOL, FILE_APPEND | LOCK_EX);
            });
        }

        DB::disconnect();
        $childIds = [];

        try {
            $first = pcntl_fork();
            $this->assertNotSame(-1, $first);
            if ($first === 0) {
                $this->deliverInChild($directory, $body, 1, blockLookup: true);
            }
            $childIds[] = $first;
            $this->waitForFile($directory.'/lookup-started');

            $second = pcntl_fork();
            $this->assertNotSame(-1, $second);
            if ($second === 0) {
                file_put_contents($directory.'/second-started', 'started', LOCK_EX);
                $this->deliverInChild($directory, $body, 2, blockLookup: false);
            }
            $childIds[] = $second;
            $this->waitForFile($directory.'/second-started');
            usleep(250_000);
            file_put_contents($directory.'/release-lookup', 'release', LOCK_EX);

            foreach ($childIds as $childId) {
                pcntl_waitpid($childId, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $this->assertSame('200', file_get_contents($directory.'/status-1'));
            $this->assertSame('200', file_get_contents($directory.'/status-2'));
            $this->assertSame('1', file_get_contents($directory.'/lookup-count'));
            $this->assertSame('WebhookReceived'.PHP_EOL.'PaymentCancelled'.PHP_EOL, file_get_contents($directory.'/events'));
            DB::reconnect();
            $this->assertSame(['CANCELLED', 100], [$payment->fresh()->status, $payment->fresh()->cancelled_amount]);
            $record = Webhook::query()->sole();
            $this->assertSame(2, $record->attempts);
            $this->assertNotNull($record->processed_at);
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

    private function deliverInChild(string $directory, string $body, int $child, bool $blockLookup): never
    {
        DB::purge();
        $this->app->instance(PaymentGateway::class, new WebhookConcurrencyGateway($directory, $blockLookup));
        $headers = (new WebhookSigner(self::SECRET))->headers($body, 'msg-concurrent');
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $status = $this->call('POST', '/portone/webhook', [], [], [], $server, $body)->getStatusCode();
        file_put_contents($directory.'/status-'.$child, (string) $status, LOCK_EX);

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

class WebhookConcurrencyBillableModel extends Model
{
    use Billable;
}

class WebhookConcurrencyGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $directory,
        private readonly bool $blockLookup,
    ) {}

    public function lookup(string $paymentId): GatewayPayment
    {
        $counter = fopen($this->directory.'/lookup-count', 'c+');
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

        return new GatewayPayment('CANCELLED', 100, cancelledAmount: 100);
    }

    public function lookupForExpiry(string $paymentId): GatewayPayment
    {
        throw new \LogicException('Webhook concurrency must not perform an expiry lookup.');
    }

    public function cancel(string $paymentId, string $reason, ?int $amount): void
    {
        throw new \LogicException('Webhook concurrency must not send cancellation requests.');
    }
}
