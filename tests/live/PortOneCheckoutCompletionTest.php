<?php

declare(strict_types=1);

namespace Tests\Live;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Dotenv\Dotenv;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class PortOneCheckoutCompletionTest extends TestCase
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

        if (($_SERVER['PORTONE_CHECKOUT_COMPLETE_LIVE'] ?? $_ENV['PORTONE_CHECKOUT_COMPLETE_LIVE'] ?? null) !== '1') {
            $this->markTestSkipped('Set PORTONE_CHECKOUT_COMPLETE_LIVE=1 to enable the live checkout completion test.');
        }

        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $apiSecret = $_SERVER['PORTONE_API_SECRET'] ?? $_ENV['PORTONE_API_SECRET'] ?? '';
        $storeId = $_SERVER['PORTONE_STORE_ID'] ?? $_ENV['PORTONE_STORE_ID'] ?? '';
        $channelKey = $_SERVER['PORTONE_CHANNEL_KEY'] ?? $_ENV['PORTONE_CHANNEL_KEY'] ?? '';

        self::assertNotSame('', $apiSecret, 'PORTONE_API_SECRET must be configured.');
        self::assertNotSame('', $storeId, 'PORTONE_STORE_ID must be configured.');
        self::assertNotSame('', $channelKey, 'PORTONE_CHANNEL_KEY must be configured.');

        config([
            'portone.api_secret' => $apiSecret,
            'portone.store_id' => $storeId,
            'portone.channel_key' => $channelKey,
        ]);

        $this->publishStubs();
        $this->migratePackageTables();
        $this->includePublishedRoutes();
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

    public function test_published_checkout_prepare_and_complete_routes_verify_a_live_payment(): void
    {
        $sessionDirectory = $_SERVER['PORTONE_COMPLETION_SESSION_DIR']
            ?? $_ENV['PORTONE_COMPLETION_SESSION_DIR']
            ?? '';
        self::assertNotSame('', $sessionDirectory, 'PORTONE_COMPLETION_SESSION_DIR must identify a temporary session directory.');
        self::assertDirectoryExists($sessionDirectory);

        $billable = new CheckoutCompletionLiveBillableUser;
        $billable->setRawAttributes(['id' => 101]);
        $this->actingAs($billable);

        $prepare = $this->post(route('portone.checkout.prepare'));
        $prepare->assertOk();
        $checkoutParameters = $prepare->json();
        self::assertIsArray($checkoutParameters);
        self::assertSame(10000, $checkoutParameters['totalAmount'] ?? null);

        $payment = $billable->findPayment((string) ($checkoutParameters['paymentId'] ?? ''));
        self::assertNotNull($payment);
        self::assertSame('PENDING', $payment->status);

        $checkoutPath = $sessionDirectory.'/checkout.json';
        $resultPath = $sessionDirectory.'/result.json';
        self::assertFileDoesNotExist($resultPath);
        file_put_contents($checkoutPath, json_encode($checkoutParameters, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);

        fwrite(
            STDOUT,
            "Open http://127.0.0.1:8765/completion and complete the 10,000 KRW PortOne test payment. This live test leaves the payment paid.\n",
        );
        $this->waitForFile($resultPath, 900);
        $browserResult = json_decode(file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payment->payment_id, $browserResult['paymentId'] ?? null);
        self::assertSame(false, $browserResult['hasError'] ?? true);
        self::assertSame('PENDING', $payment->fresh()->status);

        $payment->forceFill(['amount' => 10001])->save();
        $mismatchResponse = $this->post(route('portone.checkout.complete'), [
            'paymentId' => $payment->payment_id,
            'amount' => 10000,
        ]);
        $mismatchResponse->assertStatus(422)->assertJsonStructure(['error']);
        self::assertSame('PENDING', $payment->fresh()->status);
        $payment->forceFill(['amount' => 10000])->save();

        $unpaidPaymentId = $this->recordedUnpaidPaymentId();
        $unpaidPayment = Payment::query()->create([
            'payment_id' => $unpaidPaymentId,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => '101',
            'customer_id' => 'checkout-completion-live-probe',
            'order_name' => 'Recorded unpaid PortOne payment',
            'amount' => 100,
            'status' => 'PENDING',
            'cancelled_amount' => 0,
            'paid_at' => null,
        ]);
        $this->post(route('portone.checkout.complete'), ['paymentId' => $unpaidPaymentId])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
        self::assertSame('PENDING', $unpaidPayment->fresh()->status);

        $this->post(route('portone.checkout.complete'), ['paymentId' => 'unknown-local-payment'])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);

        $otherBillable = new CheckoutCompletionLiveBillableUser;
        $otherBillable->setRawAttributes(['id' => 202]);
        $otherPayment = $otherBillable->preparePayment(100, 'Other billable probe');
        $this->post(route('portone.checkout.complete'), ['paymentId' => $otherPayment->payment_id])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
        self::assertSame('PENDING', $otherPayment->fresh()->status);

        $realApiSecret = config('portone.api_secret');
        config(['portone.api_secret' => null]);
        $this->post(route('portone.checkout.complete'), ['paymentId' => $unpaidPaymentId])
            ->assertStatus(500)
            ->assertJsonStructure(['error']);

        config(['portone.api_secret' => 'invalid-live-probe-credential']);
        $invalidCredentialResponse = $this->post(route('portone.checkout.complete'), ['paymentId' => $unpaidPaymentId]);
        $invalidCredentialResponse->assertStatus(500)->assertJsonStructure(['error']);
        self::assertStringNotContainsString('invalid-live-probe-credential', (string) $invalidCredentialResponse->json('error'));
        self::assertSame('PENDING', $unpaidPayment->fresh()->status);
        config(['portone.api_secret' => $realApiSecret]);

        $completed = $this->post(route('portone.checkout.complete'), [
            'paymentId' => $payment->payment_id,
            'amount' => 1,
        ]);
        $completed->assertOk()->assertExactJson(['status' => 'PAID']);
        self::assertSame('PAID', $payment->fresh()->status);
        self::assertNotNull($payment->fresh()->paid_at);

        $this->recordObservation([
            'recordedAt' => gmdate(DATE_ATOM),
            'publishedStubs' => true,
            'prepareRoute' => [
                'status' => $prepare->status(),
                'paymentStatusBeforeBrowser' => 'PENDING',
                'browserResult' => 'returned without an error code',
            ],
            'completeRoute' => [
                'status' => $completed->status(),
                'paymentStatus' => $completed->json('status'),
                'requestAmountIgnored' => true,
            ],
            'amountMismatchStatus' => $mismatchResponse->status(),
            'notPaidStatus' => 422,
            'unknownLocalPaymentStatus' => 422,
            'otherBillablePaymentStatus' => 422,
            'missingApiSecretStatus' => 500,
            'invalidApiSecretStatus' => $invalidCredentialResponse->status(),
            'secretsRecorded' => false,
        ]);
    }

    private function recordedUnpaidPaymentId(): string
    {
        $observation = json_decode(
            file_get_contents(__DIR__.'/portone-payment-lookup-observation.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $paymentId = $observation['unpaidPayment']['paymentId'] ?? null;
        self::assertIsString($paymentId);

        return $paymentId;
    }

    private function waitForFile(string $path, int $timeout): void
    {
        $deadline = time() + $timeout;

        while (! is_file($path) && time() < $deadline) {
            usleep(250_000);
        }

        self::assertFileExists($path, 'The browser did not report a payment result within 15 minutes.');
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
            $this->publishedFiles[] = $this->app->basePath($path);
        }
    }

    private function includePublishedRoutes(): void
    {
        require_once $this->app->basePath('app/Http/Controllers/PortOneCheckoutController.php');
        require $this->app->basePath('routes/portone.php');
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function recordObservation(array $observation): void
    {
        $path = __DIR__.'/portone-checkout-completion-observation.json';
        $existing = is_file($path)
            ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
            : [];
        $json = json_encode(
            array_replace_recursive($existing, $observation),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $lines = explode("\n", $json);

        foreach ($lines as &$line) {
            $spaces = strspn($line, ' ');
            $line = str_repeat("\t", intdiv($spaces, 4)).substr($line, $spaces);
        }

        if (file_put_contents($path, implode("\n", $lines).PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Could not record the live checkout completion observation.');
        }
    }
}

class CheckoutCompletionLiveBillableUser extends Authenticatable
{
    use Billable;
}
