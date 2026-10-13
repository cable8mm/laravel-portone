<?php

declare(strict_types=1);

namespace Tests\Live;

use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Client\PortOneClient;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Dotenv\Dotenv;
use Orchestra\Testbench\TestCase;

class PortOnePaymentCancellationTest extends TestCase
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

        if (($_SERVER['PORTONE_CANCEL_LIVE'] ?? $_ENV['PORTONE_CANCEL_LIVE'] ?? null) !== '1') {
            $this->markTestSkipped('Set PORTONE_CANCEL_LIVE=1 to enable live PortOne cancellation requests.');
        }

        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $this->publishStubs();
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

    public function test_live_cancellation_behavior(): void
    {
        $apiSecret = $_SERVER['PORTONE_API_SECRET'] ?? $_ENV['PORTONE_API_SECRET'] ?? '';
        $partialPaymentId = $_SERVER['PORTONE_CANCEL_PARTIAL_PAYMENT_ID'] ?? $_ENV['PORTONE_CANCEL_PARTIAL_PAYMENT_ID'] ?? '';

        self::assertNotSame('', $apiSecret, 'PORTONE_API_SECRET must be set in the local .env file.');
        self::assertNotSame('', $partialPaymentId, 'PORTONE_CANCEL_PARTIAL_PAYMENT_ID must identify a paid test payment.');

        $browserObservation = json_decode(
            file_get_contents(__DIR__.'/portone-browser-sdk-observation.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $fullPaymentId = $browserObservation['completedPayment']['paymentId'] ?? null;
        self::assertIsString($fullPaymentId, 'The T-002 browser observation must contain a completed payment ID.');

        $client = new PortOneClient($apiSecret);
        $fullPaymentBefore = $client->payment->getPayment($fullPaymentId);
        $partialPaymentBefore = $client->payment->getPayment($partialPaymentId);
        $fullAmount = $this->totalAmount($fullPaymentBefore->data);
        $partialAmount = $this->totalAmount($partialPaymentBefore->data);
        self::assertSame('PAID', $fullPaymentBefore->variant);
        self::assertSame('PAID', $partialPaymentBefore->variant);
        self::assertSame(100, $fullAmount);
        self::assertSame($fullAmount, $partialAmount);
        self::assertNotSame($fullPaymentId, $partialPaymentId);

        $observation = [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php',
            'environment' => 'PortOne test channel',
            'publishedStubs' => true,
            'payments' => [
                'fullCancellation' => [
                    'paymentId' => $fullPaymentId,
                    'initialStatus' => $fullPaymentBefore->variant,
                    'initialAmount' => $fullAmount,
                ],
                'partialCancellation' => [
                    'paymentId' => $partialPaymentId,
                    'initialStatus' => $partialPaymentBefore->variant,
                    'initialAmount' => $partialAmount,
                ],
            ],
            'secretsRecorded' => false,
        ];

        try {
            $fullCancellation = $this->cancel($client, $fullPaymentId, null);
            $observation['fullCancellation'] = $fullCancellation;
            self::assertSame('success', $fullCancellation['result']);
            self::assertSame('SUCCEEDED', $fullCancellation['status']);
            self::assertSame($fullAmount, $fullCancellation['amount']);

            $alreadyCancelled = $this->cancel($client, $fullPaymentId, null);
            $observation['alreadyCancelled'] = $alreadyCancelled;
            self::assertSame('error', $alreadyCancelled['result']);

            $partialCancellation = $this->cancel($client, $partialPaymentId, 30);
            $observation['partialCancellation'] = $partialCancellation;
            self::assertSame('success', $partialCancellation['result']);
            self::assertSame('SUCCEEDED', $partialCancellation['status']);
            self::assertSame(30, $partialCancellation['amount']);

            $partialPaymentAfter = $client->payment->getPayment($partialPaymentId);
            $partialState = $this->paymentState($partialPaymentAfter->variant, $partialPaymentAfter->data);
            $observation['partialPaymentAfterFirstCancellation'] = $partialState;
            self::assertSame('PARTIAL_CANCELLED', $partialPaymentAfter->variant);
            self::assertSame(30, $partialState['cancelledAmount']);
            self::assertSame(70, $partialState['cancellableAmount']);

            $overRemaining = $this->cancel($client, $partialPaymentId, 71);
            $observation['amountOverRemaining'] = $overRemaining;
            self::assertSame('error', $overRemaining['result']);

            $remainingCancellation = $this->cancel($client, $partialPaymentId, null);
            $observation['remainingCancellation'] = $remainingCancellation;
            self::assertSame('success', $remainingCancellation['result']);
            self::assertSame('SUCCEEDED', $remainingCancellation['status']);
            self::assertSame(70, $remainingCancellation['amount']);

            $partialPaymentAfterRemaining = $client->payment->getPayment($partialPaymentId);
            $finalState = $this->paymentState($partialPaymentAfterRemaining->variant, $partialPaymentAfterRemaining->data);
            $observation['partialPaymentAfterRemainingCancellation'] = $finalState;
            self::assertSame('CANCELLED', $partialPaymentAfterRemaining->variant);
            self::assertSame(100, $finalState['cancelledAmount']);
            self::assertSame(0, $finalState['cancellableAmount']);
        } finally {
            file_put_contents(
                __DIR__.'/portone-payment-cancellation-observation.json',
                $this->prettyJson($observation),
                LOCK_EX,
            );
        }
    }

    /**
     * @return array{result: string, status?: string|null, amount?: int|null, exception?: string, httpStatus?: int|null, errorType?: string|null}
     */
    private function cancel(PortOneClient $client, string $paymentId, ?int $amount): array
    {
        try {
            $response = $client->payment->cancelPayment(
                paymentId: $paymentId,
                amount: $amount,
                reason: 'PortOne Laravel package live cancellation probe',
            );

            return [
                'result' => 'success',
                'status' => $response->cancellation->data['status'] ?? null,
                'amount' => $response->cancellation->data['totalAmount'] ?? null,
            ];
        } catch (PortOneException $exception) {
            return [
                'result' => 'error',
                'exception' => $exception::class,
                'httpStatus' => $exception->status,
                'errorType' => $exception->errorType,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function totalAmount(array $data): ?int
    {
        $amount = $data['amount'] ?? null;

        if (! is_array($amount)) {
            return null;
        }

        return is_int($amount['total'] ?? null) ? $amount['total'] : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: string, cancelledAmount: int|null, cancellableAmount: int|null}
     */
    private function paymentState(string $status, array $data): array
    {
        $cancellations = $data['cancellations'] ?? [];
        $cancelledAmount = 0;

        if (is_array($cancellations)) {
            foreach ($cancellations as $cancellation) {
                if (is_array($cancellation) && is_int($cancellation['totalAmount'] ?? null)) {
                    $cancelledAmount += $cancellation['totalAmount'];
                }
            }
        }

        $totalAmount = $this->totalAmount($data);

        return [
            'status' => $status,
            'cancelledAmount' => $cancelledAmount,
            'cancellableAmount' => $totalAmount === null ? null : $totalAmount - $cancelledAmount,
        ];
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
