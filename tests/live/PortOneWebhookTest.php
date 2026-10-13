<?php

declare(strict_types=1);

namespace Tests\Live;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class PortOneWebhookTest extends TestCase
{
    /** @var list<string> */
    private array $publishedFiles = [];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (($_SERVER['PORTONE_WEBHOOK_LIVE'] ?? $_ENV['PORTONE_WEBHOOK_LIVE'] ?? null) !== '1') {
            $this->markTestSkipped('Set PORTONE_WEBHOOK_LIVE=1 to enable live PortOne webhook verification.');
        }

        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $apiSecret = $_SERVER['PORTONE_API_SECRET'] ?? $_ENV['PORTONE_API_SECRET'] ?? '';
        $webhookSecret = $_SERVER['PORTONE_WEBHOOK_SECRET'] ?? $_ENV['PORTONE_WEBHOOK_SECRET'] ?? '';
        $storeId = $_SERVER['PORTONE_STORE_ID'] ?? $_ENV['PORTONE_STORE_ID'] ?? '';
        $channelKey = $_SERVER['PORTONE_CHANNEL_KEY'] ?? $_ENV['PORTONE_CHANNEL_KEY'] ?? '';

        self::assertNotSame('', $apiSecret, 'PORTONE_API_SECRET must be configured.');
        self::assertNotSame('', $webhookSecret, 'PORTONE_WEBHOOK_SECRET must be configured.');
        self::assertNotSame('', $storeId, 'PORTONE_STORE_ID must be configured.');
        self::assertNotSame('', $channelKey, 'PORTONE_CHANNEL_KEY must be configured.');

        config([
            'portone.api_secret' => $apiSecret,
            'portone.webhook_secret' => $webhookSecret,
            'portone.store_id' => $storeId,
            'portone.channel_key' => $channelKey,
        ]);

        $this->publishStubs();
        $this->migratePackageTables();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->publishedFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_live_payment_and_cancellation_webhooks_reach_the_public_endpoint(): void
    {
        $observationDirectory = $_SERVER['PORTONE_WEBHOOK_OBSERVATION_DIR']
            ?? $_ENV['PORTONE_WEBHOOK_OBSERVATION_DIR']
            ?? '';
        self::assertNotSame('', $observationDirectory, 'PORTONE_WEBHOOK_OBSERVATION_DIR must identify a shared observation directory.');
        self::assertDirectoryExists($observationDirectory);
        $eventsPath = $observationDirectory.'/events.jsonl';
        $requestsPath = $observationDirectory.'/requests.jsonl';
        self::assertFileDoesNotExist($eventsPath);
        self::assertFileDoesNotExist($requestsPath);

        $publicUrl = $_SERVER['PORTONE_WEBHOOK_PUBLIC_URL']
            ?? $_ENV['PORTONE_WEBHOOK_PUBLIC_URL']
            ?? '';
        self::assertNotSame('', $publicUrl, 'PORTONE_WEBHOOK_PUBLIC_URL must be the public webhook route URL.');
        self::assertContains(parse_url($publicUrl, PHP_URL_SCHEME), ['https', 'http']);
        self::assertIsString(parse_url($publicUrl, PHP_URL_HOST));

        $billable = new WebhookLiveBillableModel;
        $billable->setRawAttributes(['id' => 303]);
        $payment = $billable->preparePayment(100, 'PortOne webhook delivery probe');
        $sessionDirectory = $_SERVER['PORTONE_WEBHOOK_SESSION_DIR']
            ?? $_ENV['PORTONE_WEBHOOK_SESSION_DIR']
            ?? '';
        self::assertNotSame('', $sessionDirectory, 'PORTONE_WEBHOOK_SESSION_DIR must identify the browser session directory.');
        self::assertDirectoryExists($sessionDirectory);
        $checkoutPath = $sessionDirectory.'/checkout.json';
        $resultPath = $sessionDirectory.'/result.json';
        file_put_contents(
            $checkoutPath,
            json_encode($payment->checkoutParameters(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );

        fwrite(
            STDOUT,
            "Open http://127.0.0.1:8765/webhook and complete the 100 KRW test payment. Confirm the PortOne webhook URL is {$publicUrl}.\n",
        );
        $this->waitForFile($resultPath, 900, 'The browser did not report the test payment result.');
        $result = json_decode(file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payment->payment_id, $result['paymentId'] ?? null);
        self::assertSame(false, $result['hasError'] ?? true);

        $billable->completePayment($payment->payment_id);
        $paidDelivery = $this->waitForWebhook($eventsPath, $requestsPath, 'Transaction.Paid', $payment->payment_id);
        self::assertSame(200, $paidDelivery['response']['status'] ?? null);
        self::assertSame('', $paidDelivery['response']['body'] ?? null);
        self::assertSame($paidDelivery['headers']['webhook-id'] ?? null, $paidDelivery['event']['webhookId'] ?? null);

        $eventCountBeforeTamperedRequest = count($this->readJsonLines($eventsPath));
        $tamperedHeaders = $paidDelivery['headers'];
        $tamperedHeaders['webhook-signature'] = 'v1,invalid';
        $tampered = Http::withHeaders($tamperedHeaders)
            ->withBody($paidDelivery['body'], 'application/json')
            ->post($publicUrl);
        self::assertSame(400, $tampered->status());
        self::assertSame($eventCountBeforeTamperedRequest, count($this->readJsonLines($eventsPath)));

        $billable->refund($payment->payment_id, 'PortOne webhook verification');
        $cancelledDelivery = $this->waitForWebhook($eventsPath, $requestsPath, 'Transaction.Cancelled', $payment->payment_id);
        self::assertSame(200, $cancelledDelivery['response']['status'] ?? null);
        self::assertSame('', $cancelledDelivery['response']['body'] ?? null);
        self::assertSame(
            $cancelledDelivery['headers']['webhook-id'] ?? null,
            $cancelledDelivery['event']['webhookId'] ?? null,
        );

        $this->recordObservation([
            'recordedAt' => gmdate(DATE_ATOM),
            'publicEndpoint' => parse_url($publicUrl, PHP_URL_HOST),
            'paymentId' => $payment->payment_id,
            'paymentCompleted' => $paidDelivery,
            'tamperedSignatureStatus' => $tampered->status(),
            'paymentCancelled' => $cancelledDelivery,
            'secretsRecorded' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForWebhook(string $eventsPath, string $requestsPath, string $expectedType, string $paymentId): array
    {
        $deadline = time() + 900;

        while (time() < $deadline) {
            $events = $this->readJsonLines($eventsPath);
            $requests = $this->readJsonLines($requestsPath);

            foreach ($events as $event) {
                foreach ($requests as $request) {
                    if (($request['headers']['webhook-id'] ?? null) !== ($event['webhookId'] ?? null)) {
                        continue;
                    }

                    $body = json_decode((string) ($request['body'] ?? ''), true);
                    if (
                        is_array($body)
                        && ($body['type'] ?? null) === $expectedType
                        && ($body['data']['paymentId'] ?? null) === $paymentId
                    ) {
                        return ['event' => $event, ...$request];
                    }
                }
            }

            usleep(250_000);
        }

        throw new RuntimeException("Timed out waiting for live {$expectedType} webhook delivery.");
    }

    private function waitForFile(string $path, int $timeout, string $message): void
    {
        $deadline = time() + $timeout;

        while (! is_file($path) && time() < $deadline) {
            usleep(250_000);
        }

        self::assertFileExists($path, $message);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonLines(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function recordObservation(array $observation): void
    {
        $path = __DIR__.'/portone-webhook-observation.json';
        file_put_contents(
            $path,
            json_encode($observation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            LOCK_EX,
        );
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

    private function publishStubs(): void
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'portone-stubs',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        foreach ([
            'resources/views/portone/checkout.blade.php',
            'resources/js/portone-checkout.js',
            'app/Http/Controllers/PortOneCheckoutController.php',
            'routes/portone.php',
        ] as $path) {
            $file = $this->app->basePath($path);
            self::assertFileExists($file);
            $this->publishedFiles[] = $file;
        }
    }
}

class WebhookLiveBillableModel extends Model
{
    use Billable;
}
