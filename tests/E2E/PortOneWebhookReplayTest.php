<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Support\Facades\Artisan;
use Workbench\Database\Factories\UserFactory;

/**
 * T-036: portone:webhook-replay signs a webhook with the configured secret and sends it over HTTP to the
 * served Workbench app, which verifies it and runs the cancellation sync against live PortOne.
 *
 * No payment window is needed: it reuses the fully refunded payment recorded by T-010
 * (tests/E2E/portone-payment-refund-observation.json, fullRefund: 100 KRW, CANCELLED, cancelled 100),
 * which stays cancelled across runs.
 */
class PortOneWebhookReplayTest extends TestCase
{
    public function test_replayed_cancellation_webhook_is_verified_and_synced_by_the_served_app(): void
    {
        self::assertNotEmpty(config('portone.webhook_secret'), 'PORTONE_WEBHOOK_SECRET must be set in the local .env file.');

        $recorded = $this->readObservation(__DIR__.'/portone-payment-refund-observation.json')['fullRefund'];
        $user = UserFactory::new()->create();
        $payment = $user->preparePayment($recorded['cancelledAmount'], 'PortOne webhook replay probe');
        // The local record of a paid payment that was refunded outside the Application.
        Payment::query()->whereKey($payment->getKey())->update([
            'payment_id' => $recorded['paymentId'],
            'status' => 'PAID',
            'paid_at' => now(),
            'paid_amount' => $recorded['cancelledAmount'],
        ]);

        $baseUrl = static::applicationBaseUrl();
        config(['app.url' => $baseUrl]);

        $exitCode = Artisan::call('portone:webhook-replay', [
            'type' => 'Transaction.Cancelled',
            'paymentId' => $recorded['paymentId'],
        ]);
        $output = trim(Artisan::output());

        self::assertSame(0, $exitCode, $output);
        self::assertSame('POST '.$baseUrl.route('portone.webhook', absolute: false).' -> HTTP 200', $output);

        $record = Payment::query()->where('payment_id', $recorded['paymentId'])->firstOrFail();
        self::assertSame(['CANCELLED', $recorded['cancelledAmount']], [$record->status, $record->cancelled_amount]);

        // A second replay finds the record already CANCELLED and changes nothing.
        self::assertSame(0, Artisan::call('portone:webhook-replay', ['type' => 'Transaction.Cancelled', 'paymentId' => $recorded['paymentId']]));
        self::assertSame($record->getAttributes(), $record->fresh()->getAttributes());

        $this->recordObservation('portone-webhook-replay-observation.json', [
            'recordedAt' => gmdate(DATE_ATOM),
            'paymentId' => $recorded['paymentId'],
            'webhookType' => 'Transaction.Cancelled',
            'commandOutput' => str_replace($baseUrl, '<served app>', $output),
            'recordBefore' => ['status' => 'PAID', 'cancelledAmount' => 0],
            'recordAfter' => ['status' => $record->status, 'cancelledAmount' => $record->cancelled_amount],
            'secretsRecorded' => false,
        ]);
    }
}
