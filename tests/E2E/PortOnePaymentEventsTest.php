<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Events\PaymentCompleted;
use Cable8mm\LaravelPortone\Events\PaymentEvent;
use Cable8mm\LaravelPortone\Jobs\ExpirePendingPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\Models\Webhook;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneGateway;
use Cable8mm\LaravelPortone\Testing\SignedWebhook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Workbench\Database\Factories\UserFactory;

/**
 * T-040: a live paid payment completed by the redirect return of the published checkout and then by its
 * Transaction.Paid webhook (sent over HTTP to the served Workbench app) dispatches PaymentCompleted once.
 *
 * No payment window is needed: it reuses the 100 KRW payment paid in the browser by T-022
 * (tests/E2E/portone-payment-expiry-observation.json, payments.paid), which PortOne keeps PAID across runs.
 * The redirect return carries only paymentId on success, as recorded in tests/E2E/portone-checkout-flow-observation.json.
 */
class PortOnePaymentEventsTest extends TestCase
{
    private const OBSERVATION = 'portone-payment-events-observation.json';

    private static string $observationDirectory;

    public static function setUpBeforeClass(): void
    {
        // The served Workbench app records webhook deliveries and the payment events it dispatches here.
        self::$observationDirectory = sys_get_temp_dir().'/portone-payment-events-'.bin2hex(random_bytes(6));
        mkdir(self::$observationDirectory);
        putenv('PORTONE_WEBHOOK_OBSERVATION_DIR='.self::$observationDirectory);
        $_ENV['PORTONE_WEBHOOK_OBSERVATION_DIR'] = $_SERVER['PORTONE_WEBHOOK_OBSERVATION_DIR'] = self::$observationDirectory;

        parent::setUpBeforeClass();
    }

    public function test_browser_return_then_paid_webhook_dispatches_payment_completed_once(): void
    {
        self::assertNotEmpty(config('portone.webhook_secret'), 'PORTONE_WEBHOOK_SECRET must be set in the local .env file.');

        $paymentId = $this->readObservation(__DIR__.'/portone-payment-expiry-observation.json')['payments']['paid']['paymentId'];
        $response = $this->app->make(PortOneGateway::class)->paymentResponse($paymentId);
        self::assertSame('PAID', $response['status'] ?? null, "The recorded payment {$paymentId} must still be PAID at PortOne.");
        $amount = $response['amount']['total'] ?? null;
        self::assertIsInt($amount);
        $transactionId = $response['transactionId'] ?? null;
        self::assertIsString($transactionId);

        $user = UserFactory::new()->create();
        $payment = $user->preparePayment($amount, 'PortOne payment events probe');
        // The local record of the paid payment before the Payer returns from PortOne.
        Payment::query()->whereKey($payment->getKey())->update(['payment_id' => $paymentId]);

        $dispatched = [];
        Event::listen('*', static function (string $name, array $payload) use (&$dispatched): void {
            if (($payload[0] ?? null) instanceof PaymentEvent) {
                $dispatched[] = ['event' => $payload[0]::class, 'source' => $payload[0]->source->value, 'status' => $payload[0]->payment->status];
            }
        });

        // 1. The redirect return of the published checkout (FR-066) completes the PENDING record.
        $this->actingAs($user)
            ->get(route('portone.checkout.completed', ['paymentId' => $paymentId], false).'?paymentId='.$paymentId)
            ->assertOk();
        $afterReturn = Payment::query()->where('payment_id', $paymentId)->firstOrFail();
        self::assertSame('PAID', $afterReturn->status);

        // 2. The Transaction.Paid webhook of the same payment reaches the served app.
        $body = SignedWebhook::body('Transaction.Paid', $paymentId, ['transactionId' => $transactionId]);
        $headers = SignedWebhook::headers($body);
        $webhook = Http::withHeaders($headers)->withBody($body, 'application/json')
            ->post(static::applicationBaseUrl().route('portone.webhook', absolute: false));
        self::assertSame(200, $webhook->status());

        // 3. A queued expiry check of the same record finds it PAID and writes nothing.
        (new ExpirePendingPayment($afterReturn->billable_type, $afterReturn->billable_id, $paymentId))
            ->handle($this->app->make(PaymentOperations::class));

        $afterAll = $afterReturn->fresh();
        self::assertSame($afterReturn->getAttributes(), $afterAll->getAttributes());
        self::assertNotNull(Webhook::query()->where('webhook_id', $headers['webhook-id'])->sole()->processed_at);

        // The Workbench observer runs in the test process and in the served app, so this file holds the payment
        // events of all three steps.
        $recordedEvents = array_values(array_filter(
            $this->readJsonLines(self::$observationDirectory.'/payment-events.jsonl'),
            static fn (array $event): bool => ($event['paymentId'] ?? null) === $paymentId,
        ));
        self::assertSame([['event' => PaymentCompleted::class, 'source' => 'complete', 'status' => 'PAID']], $dispatched);
        self::assertCount(1, $recordedEvents);
        self::assertSame([PaymentCompleted::class, 'complete', null], [$recordedEvents[0]['event'], $recordedEvents[0]['source'], $recordedEvents[0]['webhookId']]);

        $this->recordObservation(self::OBSERVATION, [
            'recordedAt' => gmdate(DATE_ATOM),
            'paymentId' => $paymentId,
            'portOneStatus' => $response['status'],
            'amount' => $amount,
            'steps' => ['browser return (completed route, test process)', 'Transaction.Paid webhook over HTTP (served app)', 'expiry job (test process)'],
            'webhookResponse' => $webhook->status(),
            'paymentEvents' => array_map(static fn (array $event): array => [
                'event' => $event['event'],
                'source' => $event['source'],
                'duringWebhookRequest' => $event['webhookId'] !== null,
            ], $recordedEvents),
            'finalRecord' => ['status' => $afterAll->status, 'paidAmount' => $afterAll->paid_amount],
            'secretsRecorded' => false,
        ]);
    }

    public function test_paid_webhook_completes_a_pending_record_with_source_webhook(): void
    {
        self::assertNotEmpty(config('portone.webhook_secret'), 'PORTONE_WEBHOOK_SECRET must be set in the local .env file.');

        // The 100 KRW payment paid in the browser by T-011, which PortOne keeps PAID across runs.
        $paymentId = $this->readObservation(__DIR__.'/portone-payment-completion-observation.json')['packagePreparedPayment']['paymentId'];
        $response = $this->app->make(PortOneGateway::class)->paymentResponse($paymentId);
        self::assertSame('PAID', $response['status'] ?? null, "The recorded payment {$paymentId} must still be PAID at PortOne.");
        $amount = $response['amount']['total'] ?? null;
        self::assertIsInt($amount);
        $transactionId = $response['transactionId'] ?? null;
        self::assertIsString($transactionId);

        $user = UserFactory::new()->create();
        $payment = $user->preparePayment($amount, 'PortOne paid webhook probe');
        Payment::query()->whereKey($payment->getKey())->update(['payment_id' => $paymentId]);

        $body = SignedWebhook::body('Transaction.Paid', $paymentId, ['transactionId' => $transactionId]);
        $headers = SignedWebhook::headers($body);
        $url = static::applicationBaseUrl().route('portone.webhook', absolute: false);
        $first = Http::withHeaders($headers)->withBody($body, 'application/json')->post($url);
        $second = Http::withHeaders(SignedWebhook::headers($body))->withBody($body, 'application/json')->post($url);

        self::assertSame([200, 200], [$first->status(), $second->status()]);
        $record = Payment::query()->where('payment_id', $paymentId)->firstOrFail();
        self::assertSame(['PAID', $amount], [$record->status, $record->paid_amount]);
        self::assertNotNull($record->paid_at);

        $recordedEvents = array_values(array_filter(
            $this->readJsonLines(self::$observationDirectory.'/payment-events.jsonl'),
            static fn (array $event): bool => ($event['paymentId'] ?? null) === $paymentId,
        ));
        self::assertCount(1, $recordedEvents);
        self::assertSame([PaymentCompleted::class, 'webhook', $headers['webhook-id'], 'PAID'], [
            $recordedEvents[0]['event'], $recordedEvents[0]['source'], $recordedEvents[0]['webhookId'], $recordedEvents[0]['recordStatus'],
        ]);

        $this->recordObservation(self::OBSERVATION, [
            'webhookFirst' => [
                'recordedAt' => gmdate(DATE_ATOM),
                'paymentId' => $paymentId,
                'portOneStatus' => $response['status'],
                'amount' => $amount,
                'deliveries' => 'two Transaction.Paid webhooks with different webhook-ids',
                'responses' => [$first->status(), $second->status()],
                'paymentEvents' => array_map(static fn (array $event): array => [
                    'event' => $event['event'],
                    'source' => $event['source'],
                    'recordStatus' => $event['recordStatus'],
                ], $recordedEvents),
                'finalRecord' => ['status' => $record->status, 'paidAmount' => $record->paid_amount],
            ],
        ]);
    }
}
