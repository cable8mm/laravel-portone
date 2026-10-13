<?php

declare(strict_types=1);

namespace Tests\Live;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;

class PortOnePaymentRefundTest extends TestCase
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

        if (($_SERVER['PORTONE_REFUND_LIVE'] ?? $_ENV['PORTONE_REFUND_LIVE'] ?? null) !== '1') {
            $this->markTestSkipped('Set PORTONE_REFUND_LIVE=1 to enable the live PortOne refund test.');
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

    public function test_live_prepared_payments_are_completed_and_refunded_via_the_package(): void
    {
        $sessionDirectory = $_SERVER['PORTONE_REFUND_SESSION_DIR']
            ?? $_ENV['PORTONE_REFUND_SESSION_DIR']
            ?? '';
        self::assertNotSame('', $sessionDirectory, 'PORTONE_REFUND_SESSION_DIR must identify a temporary session directory.');
        self::assertDirectoryExists($sessionDirectory);

        $checkoutPath = $sessionDirectory.'/checkout.json';
        $resultPath = $sessionDirectory.'/results.json';
        $fullRefundPath = $sessionDirectory.'/full-refund.json';

        $billable = new RefundLiveBillableModel;
        $billable->setRawAttributes(['id' => 101]);
        $completionObservation = json_decode(
            file_get_contents(__DIR__.'/portone-payment-completion-observation.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $previouslyPaidPaymentId = $completionObservation['packagePreparedPayment']['paymentId'] ?? null;
        self::assertIsString($previouslyPaidPaymentId);
        $fullRefundObservation = is_file($fullRefundPath)
            ? json_decode(file_get_contents($fullRefundPath), true, 512, JSON_THROW_ON_ERROR)
            : null;

        $payments = [
            'full' => Payment::query()->create([
                'payment_id' => $previouslyPaidPaymentId,
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => '101',
                'customer_id' => 'live-refund-probe',
                'order_name' => 'Previously completed refund probe',
                'amount' => 100,
                'status' => $fullRefundObservation['status'] ?? 'PENDING',
                'cancelled_amount' => $fullRefundObservation['cancelledAmount'] ?? 0,
                'paid_at' => $fullRefundObservation === null ? null : now(),
            ]),
        ];
        if ($fullRefundObservation !== null) {
            self::assertSame($previouslyPaidPaymentId, $fullRefundObservation['paymentId'] ?? null);
        }
        $checkouts = is_file($checkoutPath)
            ? json_decode(file_get_contents($checkoutPath), true, 512, JSON_THROW_ON_ERROR)
            : [];

        foreach (['partial', 'remaining'] as $key) {
            if (isset($checkouts[$key])) {
                self::assertIsArray($checkouts[$key]);
                $checkout = $checkouts[$key];
                $payments[$key] = Payment::query()->create([
                    'payment_id' => $checkout['paymentId'],
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => '101',
                    'customer_id' => $checkout['customerId'],
                    'order_name' => $checkout['orderName'],
                    'amount' => $checkout['totalAmount'],
                    'status' => 'PENDING',
                    'cancelled_amount' => 0,
                ]);
            } else {
                $payments[$key] = $billable->preparePayment(
                    10000,
                    "PortOne {$key} refund probe",
                );
                $checkouts[$key] = $payments[$key]->checkoutParameters();
            }
        }

        if (! is_file($checkoutPath)) {
            file_put_contents($checkoutPath, json_encode($checkouts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        }

        fwrite(STDOUT, "Package-generated checkout values are ready. Open http://127.0.0.1:8765/refund and complete both 10000 KRW test payments.\n");

        $deadline = time() + 1800;
        while (! is_file($resultPath) && time() < $deadline) {
            usleep(250_000);
        }

        self::assertFileExists($resultPath, 'The checkout page did not report the payment results within 30 minutes.');
        $results = json_decode(file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        foreach (['partial', 'remaining'] as $key) {
            $payment = $payments[$key];
            self::assertSame($payment->payment_id, $results[$key]['paymentId'] ?? null);
            self::assertSame(false, $results[$key]['hasError'] ?? true, "The browser SDK reported a payment error for {$key}.");
            $this->assertSame('PENDING', $payment->fresh()->status);
        }

        $observation = [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
            'publishedStubs' => true,
            'defaultApiBaseUrl' => 'https://api.portone.io',
            'livePreparedPayments' => [],
            'secretsRecorded' => false,
        ];

        foreach ($payments as $key => $payment) {
            if ($key === 'full' && $fullRefundObservation !== null) {
                self::assertSame('CANCELLED', $payment->status);
                self::assertSame($payment->amount, $payment->cancelled_amount);
                $observation['livePreparedPayments'][$key] = [
                    'paymentId' => $payment->payment_id,
                    'totalAmount' => $payment->amount,
                    'origin' => 'T-011 live payment',
                    'browserResult' => 'previously returned without an error code',
                    'completionStatus' => 'PAID',
                    'paidAtRecorded' => true,
                ];

                continue;
            }

            $completed = $billable->completePayment($payment->payment_id);
            self::assertSame('PAID', $completed->status);
            self::assertNotNull($completed->paid_at);
            $observation['livePreparedPayments'][$key] = [
                'paymentId' => $payment->payment_id,
                'totalAmount' => $payment->amount,
                'origin' => $key === 'full' ? 'T-011 live payment' : 'package-prepared payment',
                'browserResult' => $key === 'full' ? 'previously returned without an error code' : 'returned without an error code',
                'completionStatus' => $completed->status,
                'paidAtRecorded' => true,
            ];
        }

        config(['portone.base_url' => 'https://api.portone.io/']);
        $full = $fullRefundObservation === null
            ? $billable->refund($payments['full']->payment_id, 'PortOne refund verification')
            : $payments['full'];
        if ($fullRefundObservation === null) {
            file_put_contents(
                $fullRefundPath,
                json_encode([
                    'paymentId' => $full->payment_id,
                    'status' => $full->status,
                    'cancelledAmount' => $full->cancelled_amount,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                LOCK_EX,
            );
        }

        self::assertSame('CANCELLED', $full->status);
        self::assertSame($payments['full']->amount, $full->cancelled_amount);
        $observation['fullRefund'] = [
            'paymentId' => $full->payment_id,
            'amountOmitted' => true,
            'status' => $full->status,
            'cancelledAmount' => $full->cancelled_amount,
            'configuredApiBaseUrl' => config('portone.base_url'),
        ];

        config(['portone.base_url' => null]);
        $partial = $billable->refund($payments['partial']->payment_id, 'PortOne refund verification', 3000);
        self::assertSame('PARTIALLY_CANCELLED', $partial->status);
        self::assertSame(3000, $partial->cancelled_amount);
        $partialFinal = $billable->refund($payments['partial']->payment_id, 'PortOne refund verification', 7000);
        self::assertSame('CANCELLED', $partialFinal->status);
        self::assertSame(10000, $partialFinal->cancelled_amount);
        $observation['partialRefund'] = [
            'paymentId' => $partial->payment_id,
            'partialAmount' => 3000,
            'partialStatus' => $partial->status,
            'partialCancelledAmount' => 3000,
            'remainingAmount' => 7000,
            'finalStatus' => $partialFinal->status,
            'finalCancelledAmount' => $partialFinal->cancelled_amount,
        ];

        $remainingPartial = $billable->refund($payments['remaining']->payment_id, 'PortOne refund verification', 3000);
        self::assertSame('PARTIALLY_CANCELLED', $remainingPartial->status);
        $remainingFinal = $billable->refund($payments['remaining']->payment_id, 'PortOne refund verification');
        self::assertSame('CANCELLED', $remainingFinal->status);
        self::assertSame(10000, $remainingFinal->cancelled_amount);
        $observation['remainingRefund'] = [
            'paymentId' => $remainingFinal->payment_id,
            'initialPartialAmount' => 3000,
            'fullRemainingAmountOmitted' => true,
            'finalStatus' => $remainingFinal->status,
            'finalCancelledAmount' => $remainingFinal->cancelled_amount,
        ];

        $this->recordObservation($observation);
    }

    public function test_portone_rejected_cancellation_exception_passes_through_unchanged(): void
    {
        $observation = json_decode(
            file_get_contents(__DIR__.'/portone-payment-cancellation-observation.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $cancelledPaymentId = $observation['payments']['fullCancellation']['paymentId'] ?? null;
        self::assertIsString($cancelledPaymentId);

        $billable = new RefundLiveBillableModel;
        $billable->setRawAttributes(['id' => 202]);
        $payment = Payment::query()->create([
            'payment_id' => $cancelledPaymentId,
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => '202',
            'customer_id' => 'live-refund-probe',
            'order_name' => 'Recorded cancelled payment',
            'amount' => 100,
            'status' => 'PAID',
            'cancelled_amount' => 0,
            'paid_at' => now(),
        ]);

        try {
            $billable->refund($payment->payment_id, 'PortOne refund verification');
            $this->fail('Expected PortOne to reject an already-cancelled payment.');
        } catch (PortOneException $exception) {
            self::assertSame($observation['alreadyCancelled']['exception'], $exception::class);
            self::assertSame($observation['alreadyCancelled']['httpStatus'], $exception->status);
            self::assertSame($observation['alreadyCancelled']['errorType'], $exception->errorType);
            self::assertSame('PAID', $payment->fresh()->status);
            self::assertSame(0, $payment->fresh()->cancelled_amount);
        }

        $this->recordObservation([
            'alreadyCancelled' => [
                'result' => 'PortOne error propagated without wrapping',
                'exception' => $exception::class,
                'httpStatus' => $exception->status,
                'errorType' => $exception->errorType,
                'localStatus' => $payment->fresh()->status,
                'localCancelledAmount' => $payment->fresh()->cancelled_amount,
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
    private function recordObservation(array $observation): void
    {
        $path = __DIR__.'/portone-payment-refund-observation.json';
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
}

class RefundLiveBillableModel extends Model
{
    use Billable;
}
