<?php

declare(strict_types=1);

namespace Tests\Live;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;

class PortOnePaymentCompletionTest extends TestCase
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

        if (($_SERVER['PORTONE_COMPLETE_LIVE'] ?? $_ENV['PORTONE_COMPLETE_LIVE'] ?? null) !== '1') {
            $this->markTestSkipped('Set PORTONE_COMPLETE_LIVE=1 to enable the live PortOne completion test.');
        }

        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $apiSecret = $_SERVER['PORTONE_API_SECRET'] ?? $_ENV['PORTONE_API_SECRET'] ?? '';
        $storeId = $_SERVER['PORTONE_STORE_ID'] ?? $_ENV['PORTONE_STORE_ID'] ?? '';
        $channelKey = $_SERVER['PORTONE_CHANNEL_KEY'] ?? $_ENV['PORTONE_CHANNEL_KEY'] ?? '';

        self::assertNotSame('', $apiSecret, 'PORTONE_API_SECRET must be set in the local .env file.');
        self::assertNotSame('', $storeId, 'PORTONE_STORE_ID must be set in the local .env file.');
        self::assertNotSame('', $channelKey, 'PORTONE_CHANNEL_KEY must be set in the local .env file.');

        config([
            'portone.api_secret' => $apiSecret,
            'portone.store_id' => $storeId,
            'portone.channel_key' => $channelKey,
            'portone.base_url' => $_SERVER['PORTONE_BASE_URL'] ?? $_ENV['PORTONE_BASE_URL'] ?? null,
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

    public function test_payment_prepared_by_the_package_is_completed_against_live_portone(): void
    {
        $sessionDirectory = $_SERVER['PORTONE_COMPLETION_SESSION_DIR']
            ?? $_ENV['PORTONE_COMPLETION_SESSION_DIR']
            ?? '';
        self::assertNotSame('', $sessionDirectory, 'PORTONE_COMPLETION_SESSION_DIR must identify a temporary session directory.');
        self::assertDirectoryExists($sessionDirectory);

        $checkoutPath = $sessionDirectory.'/checkout.json';
        $resultPath = $sessionDirectory.'/result.json';
        self::assertFileDoesNotExist($resultPath);

        $billable = new CompletionLiveBillableModel;
        $billable->setRawAttributes(['id' => 101]);
        $payment = $billable->preparePayment(100, 'PortOne payment completion probe');
        $checkoutParameters = $payment->checkoutParameters();

        file_put_contents(
            $checkoutPath,
            json_encode($checkoutParameters, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );

        fwrite(
            STDOUT,
            "Package-generated checkout parameters are ready. Open http://127.0.0.1:8765/completion and complete the 100 KRW test payment.\n",
        );

        $deadline = time() + 900;
        while (! is_file($resultPath) && time() < $deadline) {
            usleep(250_000);
        }

        self::assertFileExists($resultPath, 'The checkout page did not report a payment result within 15 minutes.');
        $result = json_decode(file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payment->payment_id, $result['paymentId'] ?? null);
        self::assertSame(false, $result['hasError'] ?? true, 'The browser SDK reported a payment error.');
        $observation = [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
            'publishedStubs' => true,
            'defaultApiBaseUrl' => 'https://api.portone.io',
            'packagePreparedPayment' => [
                'paymentId' => $payment->payment_id,
                'totalAmount' => $payment->amount,
                'browserResult' => 'returned without an error code',
            ],
            'secretsRecorded' => false,
        ];

        $this->assertSame('PENDING', $payment->fresh()->status);

        $payment->forceFill(['amount' => 101])->save();
        try {
            $billable->completePayment($payment->payment_id);
            $this->fail('Expected the live payment amount to differ from the local amount.');
        } catch (AmountMismatchException) {
            $this->assertSame('PENDING', $payment->fresh()->status);
            $this->assertSame(101, $payment->fresh()->amount);
        }
        $observation['amountMismatch'] = [
            'result' => 'rejected by PaymentLifecycle',
            'exception' => AmountMismatchException::class,
            'recordStatus' => $payment->fresh()->status,
        ];

        $payment->forceFill(['amount' => 100])->save();
        $realApiSecret = config('portone.api_secret');
        self::assertIsString($realApiSecret);
        $invalidApiSecret = 'invalid-live-probe-credential';
        config([
            'portone.api_secret' => $invalidApiSecret,
            'portone.base_url' => 'https://api.portone.io/',
        ]);

        try {
            $billable->completePayment($payment->payment_id);
            $this->fail('Expected PortOne to reject an invalid API secret.');
        } catch (PortOneException $exception) {
            $this->assertSame('Cable8mm\\PortonePhp\\Exception\\UnauthorizedException', $exception::class);
            $this->assertSame(401, $exception->status);
            $this->assertSame('UNAUTHORIZED', $exception->errorType);
            $this->assertStringNotContainsString($invalidApiSecret, $exception->getMessage());
            $this->assertSame('PENDING', $payment->fresh()->status);
            $observation['invalidApiSecret'] = [
                'exception' => $exception::class,
                'httpStatus' => $exception->status,
                'errorType' => $exception->errorType,
                'credentialInMessage' => str_contains($exception->getMessage(), $invalidApiSecret),
            ];
        }

        config([
            'portone.api_secret' => $realApiSecret,
            'portone.base_url' => null,
        ]);
        $completed = $billable->completePayment($payment->payment_id);
        self::assertSame('PAID', $completed->status);
        self::assertNotNull($completed->paid_at);
        self::assertSame(0, $completed->cancelled_amount);

        $unchanged = $billable->completePayment($payment->payment_id);
        self::assertSame('PAID', $unchanged->status);
        self::assertSame($completed->paid_at->toISOString(), $unchanged->paid_at->toISOString());
        $observation['completion'] = [
            'status' => $completed->status,
            'paidAtRecorded' => true,
            'secondCall' => 'returned unchanged',
        ];

        $unknownId = 'probe-unknown-'.Str::uuid()->toString();
        $unknownPayment = Payment::query()->create([
            'payment_id' => $unknownId,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => '101',
            'customer_id' => 'live-completion-probe',
            'order_name' => 'Unknown PortOne payment',
            'amount' => 100,
            'status' => 'PENDING',
            'cancelled_amount' => 0,
            'paid_at' => null,
        ]);

        try {
            $billable->completePayment($unknownId);
            $this->fail('Expected PortOne to reject an unknown Payment ID.');
        } catch (PortOneException $exception) {
            $this->assertSame('Cable8mm\\PortonePhp\\Exception\\UnknownException', $exception::class);
            $this->assertSame(404, $exception->status);
            $this->assertSame('PAYMENT_NOT_FOUND', $exception->errorType);
            $this->assertSame('PENDING', $unknownPayment->fresh()->status);
            $observation['unknownPortOnePayment'] = [
                'exception' => $exception::class,
                'httpStatus' => $exception->status,
                'errorType' => $exception->errorType,
                'recordStatus' => $unknownPayment->fresh()->status,
            ];
        }

        $lookupObservation = json_decode(
            file_get_contents(__DIR__.'/portone-payment-lookup-observation.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $unpaidPaymentId = $lookupObservation['unpaidPayment']['paymentId'] ?? null;
        self::assertIsString($unpaidPaymentId);

        $billable = new CompletionLiveBillableModel;
        $billable->setRawAttributes(['id' => 202]);
        $payment = Payment::query()->create([
            'payment_id' => $unpaidPaymentId,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => '202',
            'customer_id' => 'live-completion-probe',
            'order_name' => 'Recorded unpaid payment',
            'amount' => 100,
            'status' => 'PENDING',
            'cancelled_amount' => 0,
            'paid_at' => null,
        ]);

        config(['portone.base_url' => 'https://api.portone.io/']);
        try {
            $billable->completePayment($payment->payment_id);
            $this->fail('Expected the recorded unpaid PortOne payment to be rejected through the configured base URL.');
        } catch (PaymentNotPaidException) {
            self::assertSame('PENDING', $payment->fresh()->status);
            self::assertNull($payment->fresh()->paid_at);
        }
        $observation['unpaidPayment'] = [
            'observedStatus' => $lookupObservation['unpaidPayment']['observedStatus'] ?? null,
            'result' => PaymentNotPaidException::class,
            'recordStatus' => $payment->fresh()->status,
            'configuredApiBaseUrl' => config('portone.base_url'),
        ];

        $this->recordObservation($observation);
    }

    public function test_recorded_unpaid_portone_payment_is_rejected_through_the_package(): void
    {
        $lookupObservation = json_decode(
            file_get_contents(__DIR__.'/portone-payment-lookup-observation.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $unpaidPaymentId = $lookupObservation['unpaidPayment']['paymentId'] ?? null;
        self::assertIsString($unpaidPaymentId);

        $billable = new CompletionLiveBillableModel;
        $billable->setRawAttributes(['id' => 202]);
        $payment = Payment::query()->create([
            'payment_id' => $unpaidPaymentId,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => '202',
            'customer_id' => 'live-completion-probe',
            'order_name' => 'Recorded unpaid payment',
            'amount' => 100,
            'status' => 'PENDING',
            'cancelled_amount' => 0,
            'paid_at' => null,
        ]);

        config(['portone.base_url' => 'https://api.portone.io/']);
        try {
            $billable->completePayment($payment->payment_id);
            $this->fail('Expected the recorded unpaid PortOne payment to be rejected through the configured base URL.');
        } catch (PaymentNotPaidException) {
            self::assertSame('PENDING', $payment->fresh()->status);
            self::assertNull($payment->fresh()->paid_at);
        }

        $this->recordObservation([
            'unpaidPayment' => [
                'observedStatus' => $lookupObservation['unpaidPayment']['observedStatus'] ?? null,
                'result' => PaymentNotPaidException::class,
                'recordStatus' => $payment->fresh()->status,
                'configuredApiBaseUrl' => config('portone.base_url'),
            ],
        ]);
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

    /**
     * @param  array<string, mixed>  $observation
     */
    private function prettyJson(array $observation): string
    {
        $json = json_encode($observation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $lines = explode("\n", $json);

        foreach ($lines as &$line) {
            $spaces = strspn($line, ' ');
            $line = str_repeat("\t", intdiv($spaces, 4)).substr($line, $spaces);
        }

        return implode("\n", $lines).PHP_EOL;
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function recordObservation(array $observation): void
    {
        $path = __DIR__.'/portone-payment-completion-observation.json';
        $existing = is_file($path)
            ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
            : [
                'recordedAt' => gmdate(DATE_ATOM),
                'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
                'publishedStubs' => true,
                'defaultApiBaseUrl' => 'https://api.portone.io',
                'secretsRecorded' => false,
            ];

        file_put_contents(
            $path,
            $this->prettyJson(array_replace_recursive($existing, $observation)),
            LOCK_EX,
        );
    }
}

class CompletionLiveBillableModel extends Model
{
    use Billable;
}
