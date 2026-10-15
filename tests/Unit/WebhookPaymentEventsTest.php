<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Events\PaymentCancelled;
use Cable8mm\LaravelPortone\Events\PaymentFailed;
use Cable8mm\LaravelPortone\Events\PaymentPaid;
use Cable8mm\LaravelPortone\Events\PaymentPartiallyCancelled;
use Cable8mm\LaravelPortone\Events\PaymentWebhookEvent;
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Webhook\WebhookSigner;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * T-028: typed payment events per transaction webhook type.
 *
 * Transaction.Paid and Transaction.Cancelled bodies are the live recordings in
 * tests/E2E/portone-webhook-observation.json; Transaction.PartialCancelled and Transaction.Failed
 * have the shape recorded in tests/E2E/portone-webhook-events-observation.json (T-028). The recorded bodies are
 * signed with the cable8mm/portone-php WebhookSigner (T-036).
 */
class WebhookPaymentEventsTest extends TestCase
{
    private const SECRET = 'dGVzdC1zZWNyZXQ=';

    private const PAID_BODY = '{"type":"Transaction.Paid","timestamp":"2026-10-14T02:37:44Z","data":{"transactionId":"01a13845-34ca-eb3d-e51a-2f3d35e4ec8e","paymentId":"dc2e6b5f-3dbc-42d7-b2b3-4048cbf44e23","storeId":"store-720c15ca-0a45-4811-9e40-c8321ca05e8e"}}';

    private const PAYMENT_EVENTS = [
        PaymentPaid::class,
        PaymentCancelled::class,
        PaymentPartiallyCancelled::class,
        PaymentFailed::class,
    ];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('portone.webhook_secret', self::SECRET);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Cancellation webhooks look for the Payment record before dispatching (T-034).
        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }
    }

    /**
     * @return iterable<string, array{string, class-string<PaymentWebhookEvent>}>
     */
    public static function paymentWebhooks(): iterable
    {
        yield 'Transaction.Paid (recorded)' => [self::PAID_BODY, PaymentPaid::class];
        yield 'Transaction.Cancelled (recorded)' => [
            '{"type":"Transaction.Cancelled","timestamp":"2026-10-14T02:37:45.733015094Z","data":{"transactionId":"01a13845-34ca-eb3d-e51a-2f3d35e4ec8e","paymentId":"dc2e6b5f-3dbc-42d7-b2b3-4048cbf44e23","storeId":"store-720c15ca-0a45-4811-9e40-c8321ca05e8e","cancellationId":"01a13845-de5d-3a4e-1fb1-8a29df5ee17b"}}',
            PaymentCancelled::class,
        ];
        yield 'Transaction.PartialCancelled (recorded shape)' => [
            '{"type":"Transaction.PartialCancelled","timestamp":"2026-10-14T02:37:45Z","data":{"transactionId":"01a13845-34ca-eb3d-e51a-2f3d35e4ec8e","paymentId":"dc2e6b5f-3dbc-42d7-b2b3-4048cbf44e23","storeId":"store-720c15ca-0a45-4811-9e40-c8321ca05e8e","cancellationId":"01a13845-de5d-3a4e-1fb1-8a29df5ee17b"}}',
            PaymentPartiallyCancelled::class,
        ];
        yield 'Transaction.Failed (recorded shape)' => [
            '{"type":"Transaction.Failed","timestamp":"2026-10-14T02:37:44Z","data":{"transactionId":"01a13845-34ca-eb3d-e51a-2f3d35e4ec8e","paymentId":"dc2e6b5f-3dbc-42d7-b2b3-4048cbf44e23","storeId":"store-720c15ca-0a45-4811-9e40-c8321ca05e8e"}}',
            PaymentFailed::class,
        ];
    }

    /**
     * @param  class-string<PaymentWebhookEvent>  $expected
     */
    #[DataProvider('paymentWebhooks')]
    public function test_payment_webhook_dispatches_webhook_received_and_one_typed_event(string $body, string $expected): void
    {
        Event::fake([WebhookReceived::class, ...self::PAYMENT_EVENTS]);

        $this->postWebhook($body)->assertOk();

        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatchedTimes($expected, 1);
        Event::assertDispatched($expected, fn (PaymentWebhookEvent $event): bool => $event->paymentId === 'dc2e6b5f-3dbc-42d7-b2b3-4048cbf44e23'
            && $event->webhookId === 'webhook-test-id'
            && $event->webhook->data->paymentId === $event->paymentId);

        foreach (array_diff(self::PAYMENT_EVENTS, [$expected]) as $other) {
            Event::assertNotDispatched($other);
        }
    }

    public function test_typed_event_is_dispatched_after_webhook_received(): void
    {
        $order = [];
        Event::listen(WebhookReceived::class, static function () use (&$order): void {
            $order[] = WebhookReceived::class;
        });
        Event::listen(PaymentPaid::class, static function () use (&$order): void {
            $order[] = PaymentPaid::class;
        });

        $this->postWebhook(self::PAID_BODY)->assertOk();

        $this->assertSame([WebhookReceived::class, PaymentPaid::class], $order);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherWebhooks(): iterable
    {
        foreach (['Transaction.Ready', 'Transaction.VirtualAccountIssued', 'Transaction.PayPending', 'Transaction.CancelPending', 'Transaction.DisputeCreated'] as $type) {
            yield $type => ['{"type":"'.$type.'","timestamp":"2026-10-14T02:37:44Z","data":{"transactionId":"t","paymentId":"p","storeId":"s"}}'];
        }

        yield 'BillingKey.Issued' => ['{"type":"BillingKey.Issued","timestamp":"2026-10-14T02:37:44Z","data":{"billingKey":"b","storeId":"s"}}'];
        yield 'unrecognized' => ['{"type":"Future.UnknownEvent","data":{}}'];
    }

    #[DataProvider('otherWebhooks')]
    public function test_other_webhooks_dispatch_only_webhook_received(string $body): void
    {
        Event::fake([WebhookReceived::class, ...self::PAYMENT_EVENTS]);

        $this->postWebhook($body)->assertOk();

        Event::assertDispatchedTimes(WebhookReceived::class, 1);

        foreach (self::PAYMENT_EVENTS as $event) {
            Event::assertNotDispatched($event);
        }
    }

    /**
     * @return TestResponse<Response>
     */
    private function postWebhook(string $body): TestResponse
    {
        $headers = (new WebhookSigner(self::SECRET))->headers($body, 'webhook-test-id');

        return $this->call('POST', '/portone/webhook', [], [], [], [
            'HTTP_WEBHOOK_ID' => $headers['webhook-id'],
            'HTTP_WEBHOOK_SIGNATURE' => $headers['webhook-signature'],
            'HTTP_WEBHOOK_TIMESTAMP' => $headers['webhook-timestamp'],
        ], $body);
    }
}
