<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Events\PaymentCancelled;
use Cable8mm\LaravelPortone\Events\PaymentFailed;
use Cable8mm\LaravelPortone\Events\PaymentPaid;
use Cable8mm\LaravelPortone\Events\PaymentPartiallyCancelled;
use Laravel\Dusk\Browser;
use Workbench\Database\Factories\UserFactory;

/**
 * T-028: PortOne delivers transaction webhooks through ngrok, and the Package dispatches one typed
 * payment event per webhook. Records the PartialCancelled and Failed bodies that were not observed before.
 */
class PortOneWebhookEventsTest extends TestCase
{
    private const OBSERVATION = 'portone-webhook-events-observation.json';

    private static string $observationDirectory;

    public static function setUpBeforeClass(): void
    {
        // The Workbench app records webhook deliveries and typed events here.
        self::$observationDirectory = sys_get_temp_dir().'/portone-webhook-events-'.bin2hex(random_bytes(6));
        mkdir(self::$observationDirectory);
        putenv('PORTONE_WEBHOOK_OBSERVATION_DIR='.self::$observationDirectory);
        $_ENV['PORTONE_WEBHOOK_OBSERVATION_DIR'] = $_SERVER['PORTONE_WEBHOOK_OBSERVATION_DIR'] = self::$observationDirectory;

        parent::setUpBeforeClass();
    }

    public function test_live_transaction_webhooks_dispatch_typed_payment_events(): void
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
        $paid = $user->preparePayment(1000, 'PortOne webhook events probe');
        $failed = $user->preparePayment(100, 'PortOne failed webhook probe');

        $this->browse(function (Browser $browser) use ($user, $paid, $failed, $publicUrl): void {
            $this->payInBrowser($browser, $user, $paid, "Complete the 1,000 KRW PortOne test payment in the browser window. Confirm the PortOne webhook URL is {$publicUrl}.");
            $this->closeInBrowser($browser, $user, $failed, 'Close the 100 KRW PortOne payment window without paying.');
        });

        $user->completePayment($paid->payment_id);
        $paidEvent = $this->waitForPaymentEvent(self::$observationDirectory, PaymentPaid::class, $paid->payment_id);

        $user->refund($paid->payment_id, 'PortOne webhook events probe', 300);
        $partialEvent = $this->waitForPaymentEvent(self::$observationDirectory, PaymentPartiallyCancelled::class, $paid->payment_id);

        $user->refund($paid->payment_id, 'PortOne webhook events probe');
        $cancelledEvent = $this->waitForPaymentEvent(self::$observationDirectory, PaymentCancelled::class, $paid->payment_id);

        $failedEvent = $this->waitForPaymentEvent(self::$observationDirectory, PaymentFailed::class, $failed->payment_id);

        $this->recordObservation(self::OBSERVATION, [
            'recordedAt' => gmdate(DATE_ATOM),
            'paid' => $paidEvent,
            'partialCancelled' => $partialEvent,
            'cancelled' => $cancelledEvent,
            'failed' => $failedEvent,
            'secretsRecorded' => false,
        ]);
    }
}
