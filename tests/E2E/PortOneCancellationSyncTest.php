<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Events\PaymentCancelled;
use Cable8mm\LaravelPortone\Events\PaymentPartiallyCancelled;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PaymentOperations;
use Laravel\Dusk\Browser;
use Workbench\Database\Factories\UserFactory;

/**
 * T-034: refunds made in the PortOne console reach the Workbench app as webhooks through ngrok, and
 * the Package syncs the Payment record before the typed event is dispatched.
 */
class PortOneCancellationSyncTest extends TestCase
{
    private const OBSERVATION = 'portone-cancellation-sync-observation.json';

    private static string $observationDirectory;

    public static function setUpBeforeClass(): void
    {
        // The Workbench app records webhook deliveries and typed events (with the record it saw) here.
        self::$observationDirectory = sys_get_temp_dir().'/portone-cancellation-sync-'.bin2hex(random_bytes(6));
        mkdir(self::$observationDirectory);
        putenv('PORTONE_WEBHOOK_OBSERVATION_DIR='.self::$observationDirectory);
        $_ENV['PORTONE_WEBHOOK_OBSERVATION_DIR'] = $_SERVER['PORTONE_WEBHOOK_OBSERVATION_DIR'] = self::$observationDirectory;

        parent::setUpBeforeClass();
    }

    public function test_console_refunds_sync_the_payment_record_before_the_events(): void
    {
        $publicUrl = $_SERVER['PORTONE_WEBHOOK_PUBLIC_URL'] ?? $_ENV['PORTONE_WEBHOOK_PUBLIC_URL'] ?? '';

        if ($publicUrl === '') {
            $this->markTestSkipped(
                'Set PORTONE_WEBHOOK_PUBLIC_URL to the ngrok URL of the webhook route ('
                .static::applicationBaseUrl().route('portone.webhook', absolute: false).').',
            );
        }

        self::assertNotEmpty(config('portone.webhook_secret'), 'PORTONE_WEBHOOK_SECRET must be set in the local .env file.');

        $user = UserFactory::new()->create();
        $payment = $user->preparePayment(1000, 'PortOne cancellation sync probe');

        $this->browse(function (Browser $browser) use ($user, $payment, $publicUrl): void {
            $this->payInBrowser($browser, $user, $payment, "Complete the 1,000 KRW PortOne test payment in the browser window. Confirm the PortOne webhook URL is {$publicUrl}.");
        });

        self::assertSame('PAID', $user->completePayment($payment->payment_id)->status);

        $this->instruct("In the PortOne console, PARTIALLY cancel 300 KRW of payment {$payment->payment_id}.");
        $partial = $this->waitForPaymentEvent(self::$observationDirectory, PaymentPartiallyCancelled::class, $payment->payment_id);
        self::assertSame('PARTIALLY_CANCELLED', $partial['event']['recordStatus']);
        self::assertSame(300, $partial['event']['recordCancelledAmount']);

        $this->instruct("In the PortOne console, cancel the remaining 700 KRW of payment {$payment->payment_id}.");
        $cancelled = $this->waitForPaymentEvent(self::$observationDirectory, PaymentCancelled::class, $payment->payment_id);
        self::assertSame('CANCELLED', $cancelled['event']['recordStatus']);
        self::assertSame(1000, $cancelled['event']['recordCancelledAmount']);

        $record = $payment->fresh();
        self::assertSame(['CANCELLED', 1000, 1000], [$record->status, $record->cancelled_amount, $record->paid_amount]);
        self::assertNotNull($record->paid_at);

        // A repeated sync with the same PortOne state changes nothing.
        $lookup = $this->app->make(PaymentGateway::class)->lookup($payment->payment_id);
        $resynced = $this->app->make(PaymentOperations::class)->syncByPaymentId($payment->payment_id);
        self::assertSame(['CANCELLED', 1000, 1000], [$lookup->status, $lookup->totalAmount, $lookup->cancelledAmount]);
        self::assertSame($record->getAttributes(), $resynced->fresh()->getAttributes());

        $this->recordObservation(self::OBSERVATION, [
            'recordedAt' => gmdate(DATE_ATOM),
            'paymentId' => $payment->payment_id,
            'amount' => 1000,
            'partialCancelled' => $partial,
            'cancelled' => $cancelled,
            'lookupAfterCancellation' => [
                'status' => $lookup->status,
                'totalAmount' => $lookup->totalAmount,
                'cancelledAmount' => $lookup->cancelledAmount,
            ],
            'finalRecord' => ['status' => $record->status, 'cancelledAmount' => $record->cancelled_amount],
            'secretsRecorded' => false,
        ]);
    }
}
