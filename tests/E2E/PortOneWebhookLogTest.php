<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Events\PaymentRefunded;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\Models\Webhook;
use Cable8mm\LaravelPortone\PortOneGateway;
use Cable8mm\LaravelPortone\Testing\SignedWebhook;
use Illuminate\Support\Facades\Http;
use Workbench\Database\Factories\UserFactory;

/**
 * T-039: one signed cancellation webhook, delivered twice with the same webhook-id over HTTP to the served
 * Workbench app, is recorded once, syncs the record against live PortOne once, and dispatches its events once.
 *
 * No payment window is needed: it reuses the fully refunded payment recorded by T-010
 * (tests/E2E/portone-payment-refund-observation.json, fullRefund: 100 KRW, CANCELLED, cancelled 100),
 * which stays cancelled across runs, as the replay test of T-036 does.
 */
class PortOneWebhookLogTest extends TestCase
{
    private const OBSERVATION = 'portone-webhook-log-observation.json';

    private static string $observationDirectory;

    public static function setUpBeforeClass(): void
    {
        // The Workbench app records webhook deliveries and the events it dispatches here.
        self::$observationDirectory = sys_get_temp_dir().'/portone-webhook-log-'.bin2hex(random_bytes(6));
        mkdir(self::$observationDirectory);
        putenv('PORTONE_WEBHOOK_OBSERVATION_DIR='.self::$observationDirectory);
        $_ENV['PORTONE_WEBHOOK_OBSERVATION_DIR'] = $_SERVER['PORTONE_WEBHOOK_OBSERVATION_DIR'] = self::$observationDirectory;

        parent::setUpBeforeClass();
    }

    public function test_redelivered_webhook_id_is_recorded_once_and_has_no_second_effect(): void
    {
        self::assertNotEmpty(config('portone.webhook_secret'), 'PORTONE_WEBHOOK_SECRET must be set in the local .env file.');

        $recorded = $this->readObservation(__DIR__.'/portone-payment-refund-observation.json')['fullRefund'];
        $user = UserFactory::new()->create();
        $payment = $user->preparePayment($recorded['cancelledAmount'], 'PortOne webhook log probe');
        // The local record of a paid payment that was refunded outside the Application.
        Payment::query()->whereKey($payment->getKey())->update([
            'payment_id' => $recorded['paymentId'],
            'status' => 'PAID',
            'paid_at' => now(),
            'paid_amount' => $recorded['cancelledAmount'],
        ]);

        $transactionId = $this->app->make(PortOneGateway::class)->paymentResponse($recorded['paymentId'])['transactionId'] ?? '';
        self::assertIsString($transactionId);
        self::assertNotSame('', $transactionId);
        $body = SignedWebhook::body('Transaction.Cancelled', $recorded['paymentId'], ['transactionId' => $transactionId]);
        $headers = SignedWebhook::headers($body);
        $webhookId = $headers['webhook-id'];
        $url = static::applicationBaseUrl().route('portone.webhook', absolute: false);

        $first = Http::withHeaders($headers)->withBody($body, 'application/json')->post($url);
        $afterFirst = Payment::query()->where('payment_id', $recorded['paymentId'])->firstOrFail();
        $second = Http::withHeaders($headers)->withBody($body, 'application/json')->post($url);
        $afterSecond = $afterFirst->fresh();

        self::assertSame([200, ''], [$first->status(), $first->body()]);
        self::assertSame([200, ''], [$second->status(), $second->body()]);
        self::assertSame(['CANCELLED', $recorded['cancelledAmount']], [$afterFirst->status, $afterFirst->cancelled_amount]);
        self::assertSame($afterFirst->getAttributes(), $afterSecond->getAttributes());

        $record = Webhook::query()->where('webhook_id', $webhookId)->sole();
        self::assertSame(['Transaction.Cancelled', $body, 2], [$record->type, $record->body, $record->attempts]);
        self::assertNotNull($record->processed_at);
        self::assertNull($record->failed_at);

        $received = array_values(array_filter(
            $this->readJsonLines(self::$observationDirectory.'/events.jsonl'),
            static fn (array $event): bool => ($event['webhookId'] ?? null) === $webhookId,
        ));
        $paymentEvents = array_values(array_filter(
            $this->readJsonLines(self::$observationDirectory.'/payment-events.jsonl'),
            static fn (array $event): bool => ($event['webhookId'] ?? null) === $webhookId,
        ));
        $requests = array_values(array_filter(
            $this->readJsonLines(self::$observationDirectory.'/requests.jsonl'),
            static fn (array $request): bool => ($request['headers']['webhook-id'] ?? null) === $webhookId,
        ));
        self::assertCount(1, $received);
        self::assertCount(1, $paymentEvents);
        self::assertSame(PaymentRefunded::class, $paymentEvents[0]['event']);
        self::assertSame(['CANCELLED', $recorded['cancelledAmount']], [$paymentEvents[0]['recordStatus'], $paymentEvents[0]['recordCancelledAmount']]);
        self::assertSame([200, 200], array_map(static fn (array $request): int => $request['response']['status'], $requests));

        $this->recordObservation(self::OBSERVATION, [
            'recordedAt' => gmdate(DATE_ATOM),
            'paymentId' => $recorded['paymentId'],
            'webhookType' => 'Transaction.Cancelled',
            'deliveries' => 2,
            'sameWebhookId' => true,
            'responses' => [$first->status(), $second->status()],
            'webhookRecord' => [
                'attempts' => $record->attempts,
                'processedAt' => $record->processed_at->toAtomString(),
                'failedAt' => $record->failed_at,
            ],
            'webhookReceivedDispatched' => count($received),
            'paymentEventsDispatched' => array_map(static fn (array $event): string => $event['event'], $paymentEvents),
            'recordBefore' => ['status' => 'PAID', 'cancelledAmount' => 0],
            'recordAfterFirstDelivery' => ['status' => $afterFirst->status, 'cancelledAmount' => $afterFirst->cancelled_amount],
            'recordAfterSecondDelivery' => ['status' => $afterSecond->status, 'cancelledAmount' => $afterSecond->cancelled_amount],
            'secretsRecorded' => false,
        ]);
    }
}
