<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Events\PaymentAmountMismatchedOnExpiry;
use Cable8mm\LaravelPortone\Events\PaymentCancelled;
use Cable8mm\LaravelPortone\Events\PaymentExpired;
use Cable8mm\LaravelPortone\Events\PaymentFailed;
use Cable8mm\LaravelPortone\Events\PaymentPaid;
use Cable8mm\LaravelPortone\Events\PaymentPaidOnExpiry;
use Cable8mm\LaravelPortone\Events\PaymentPartiallyCancelled;
use Cable8mm\LaravelPortone\Events\WebhookFailed;
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\Models\Webhook;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PortOneGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\Testing\FakePaymentGateway;
use Cable8mm\LaravelPortone\Testing\SignedWebhook;
use Cable8mm\LaravelPortone\WebhookLog;
use Cable8mm\PortonePhp\Exception\UnauthorizedException;
use Cable8mm\PortonePhp\Webhook\WebhookSigner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-039: every verified webhook is recorded once per webhook-id before any effect, processed once,
 * marked failed and redelivered on a ConfigurationException or PortOne exception, and pruned after EL-056.
 *
 * Webhook bodies have the shape of the live Transaction.Cancelled recording in
 * tests/E2E/portone-webhook-observation.json. PortOne lookups are traced to
 * tests/E2E/portone-payment-cancellation-observation.json (T-010): CANCELLED with cancelled amount 100
 * for amount 100. An invalid api secret fails with UNAUTHORIZED (T-003); an unset api secret raises
 * ConfigurationException before any request (ERR-001).
 */
class WebhookLogTest extends TestCase
{
    private const SECRET = 'dGVzdC1zZWNyZXQ=';

    private const CANCELLATION = [
        'transactionId' => '01a13845-34ca-eb3d-e51a-2f3d35e4ec8e',
        'cancellationId' => '01a13845-de5d-3a4e-1fb1-8a29df5ee17b',
    ];

    /** Every event class the Package dispatches. */
    private const PACKAGE_EVENTS = [
        WebhookReceived::class,
        WebhookFailed::class,
        PaymentPaid::class,
        PaymentCancelled::class,
        PaymentPartiallyCancelled::class,
        PaymentFailed::class,
        PaymentExpired::class,
        PaymentPaidOnExpiry::class,
        PaymentAmountMismatchedOnExpiry::class,
    ];

    private FakePaymentGateway $gateway;

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('portone.webhook_secret', self::SECRET);
        $app['config']->set('portone.store_id', 'store-720c15ca-0a45-4811-9e40-c8321ca05e8e');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }

        Schema::create('webhook_log_users', function (Blueprint $table): void {
            $table->id();
        });

        $this->gateway = FakePaymentGateway::bind();
    }

    public function test_first_delivery_records_the_webhook_and_marks_it_processed(): void
    {
        $this->travelTo('2026-11-11 10:00:00');
        $body = SignedWebhook::body('Future.UnknownEvent', 'payment-1', ['kept' => true]);

        $this->deliver('msg-first', $body)->assertOk();

        $record = Webhook::query()->sole();
        $this->assertSame(['msg-first', 'Future.UnknownEvent', $body, 1], [$record->webhook_id, $record->type, $record->body, $record->attempts]);
        $this->assertSame('2026-11-11 10:00:00', $record->received_at->toDateTimeString());
        $this->assertSame('2026-11-11 10:00:00', $record->processed_at?->toDateTimeString());
        $this->assertNull($record->failed_at);
        $this->assertNull($record->failure);
    }

    public function test_repeated_delivery_changes_and_dispatches_nothing(): void
    {
        $payment = $this->paidPayment();
        $this->gateway->payment($payment->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);
        $body = SignedWebhook::body('Transaction.Cancelled', $payment->payment_id, self::CANCELLATION);
        $this->deliver('msg-repeated', $body)->assertOk();
        $paymentAfterFirst = $payment->fresh()->getAttributes();
        $processedAt = Webhook::query()->sole()->processed_at;
        Event::fake(self::PACKAGE_EVENTS);

        $this->travel(5)->minutes();
        $response = $this->deliver('msg-repeated', $body);

        $response->assertOk();
        $this->assertSame('', $response->getContent());
        foreach (self::PACKAGE_EVENTS as $event) {
            Event::assertNotDispatched($event);
        }
        $this->assertSame($paymentAfterFirst, $payment->fresh()->getAttributes());
        $this->assertSame(['CANCELLED', 100], [$payment->fresh()->status, $payment->fresh()->cancelled_amount]);
        $this->gateway->assertLookedUp($payment->payment_id, times: 1);
        $record = Webhook::query()->sole();
        $this->assertSame(2, $record->attempts);
        $this->assertEquals($processedAt, $record->processed_at);
    }

    public function test_portone_failure_marks_the_record_failed_dispatches_only_webhook_failed_and_is_processed_on_redelivery(): void
    {
        $payment = $this->paidPayment();
        $failure = new UnauthorizedException('Unauthorized', status: 401, errorType: 'UNAUTHORIZED');
        $this->gateway->failLookup($payment->payment_id, $failure);
        $body = SignedWebhook::body('Transaction.Cancelled', $payment->payment_id, self::CANCELLATION);
        Event::fake(self::PACKAGE_EVENTS);

        $this->deliver('msg-failing', $body)->assertStatus(500);

        $this->assertSame('PAID', $payment->fresh()->status);
        Event::assertDispatchedTimes(WebhookFailed::class, 1);
        Event::assertDispatched(WebhookFailed::class, fn (WebhookFailed $event): bool => $event->webhookId === 'msg-failing'
            && $event->type === 'Transaction.Cancelled'
            && $event->exception === $failure);
        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(PaymentCancelled::class);
        $record = Webhook::query()->sole();
        $this->assertNull($record->processed_at);
        $this->assertNotNull($record->failed_at);
        $this->assertSame(UnauthorizedException::class.': Unauthorized', $record->failure);
        $this->assertSame(1, $record->attempts);

        // PortOne delivers the same webhook-id again once the lookup works.
        $this->gateway->payment($payment->payment_id, 'CANCELLED', totalAmount: 100, cancelledAmount: 100);
        $this->deliver('msg-failing', $body)->assertOk();

        $this->assertSame(['CANCELLED', 100], [$payment->fresh()->status, $payment->fresh()->cancelled_amount]);
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        Event::assertDispatchedTimes(PaymentCancelled::class, 1);
        Event::assertDispatchedTimes(WebhookFailed::class, 1);
        $record = $record->fresh();
        $this->assertNotNull($record->processed_at);
        $this->assertNull($record->failed_at);
        $this->assertNull($record->failure);
        $this->assertSame(2, $record->attempts);
    }

    public function test_missing_api_secret_marks_the_record_failed(): void
    {
        $this->app->bind(PaymentGateway::class, PortOneGateway::class);
        config(['portone.api_secret' => null]);
        $payment = $this->paidPayment();
        Event::fake(self::PACKAGE_EVENTS);

        $this->deliver('msg-unconfigured', SignedWebhook::body('Transaction.Cancelled', $payment->payment_id, self::CANCELLATION))
            ->assertStatus(500);

        $this->assertSame('PAID', $payment->fresh()->status);
        Event::assertDispatched(WebhookFailed::class, fn (WebhookFailed $event): bool => $event->exception instanceof ConfigurationException);
        Event::assertNotDispatched(WebhookReceived::class);
        $record = Webhook::query()->sole();
        $this->assertNull($record->processed_at);
        $this->assertStringStartsWith(ConfigurationException::class.': ', (string) $record->failure);
    }

    public function test_a_failing_effect_is_rolled_back_with_the_processed_mark(): void
    {
        $payment = $this->paidPayment();
        $log = $this->app->make(WebhookLog::class);

        try {
            $log->process('msg-rollback', 'Transaction.Cancelled', '{}', static function () use ($payment): void {
                Payment::query()->whereKey($payment->getKey())->update(['status' => 'CANCELLED']);

                throw new UnauthorizedException('Unauthorized', status: 401, errorType: 'UNAUTHORIZED');
            });
            $this->fail('Expected the effect exception to propagate.');
        } catch (UnauthorizedException) {
            // The receiver marks the record failed; the log itself only rolls back.
        }

        $this->assertSame('PAID', $payment->fresh()->status);
        $record = Webhook::query()->sole();
        $this->assertSame(1, $record->attempts);
        $this->assertNull($record->processed_at);

        $this->assertTrue($log->process('msg-rollback', 'Transaction.Cancelled', '{}', static function (): void {}));
        $this->assertFalse($log->process('msg-rollback', 'Transaction.Cancelled', '{}', static function (): void {
            throw new RuntimeException('A processed webhook-id must not run its effect again.');
        }));
        $this->assertSame(3, $record->fresh()->attempts);
    }

    public function test_listener_exception_propagates_after_the_record_is_processed(): void
    {
        Event::listen(WebhookReceived::class, static function (): never {
            throw new RuntimeException('listener failed');
        });

        $this->deliver('msg-listener', SignedWebhook::body('Future.UnknownEvent', 'payment-1'))->assertStatus(500);

        $this->assertNotNull(Webhook::query()->sole()->processed_at);
    }

    public function test_unverified_requests_write_no_webhook_record(): void
    {
        $body = SignedWebhook::body('Transaction.Cancelled', 'payment-1');
        $headers = (new WebhookSigner(self::SECRET))->headers($body, 'msg-forged');
        $headers['webhook-signature'] = 'v1,invalid';

        $this->call('POST', '/portone/webhook', [], [], [], $this->server($headers), $body)->assertStatus(400);

        $this->assertSame(0, Webhook::query()->count());
    }

    public function test_prune_deletes_only_processed_records_older_than_the_retention(): void
    {
        config(['portone.webhook_retention_days' => 30]);
        $this->travelTo('2026-11-11 10:00:00');
        $this->record('processed-old', processedAt: now()->subDays(31));
        $this->record('processed-recent', processedAt: now()->subDays(29));
        $this->record('failed-old', failedAt: now()->subDays(200));
        $this->record('received-old');

        $this->artisan('model:prune', ['--model' => [Webhook::class]])->assertExitCode(0);

        $this->assertSame(
            ['failed-old', 'processed-recent', 'received-old'],
            Webhook::query()->orderBy('webhook_id')->pluck('webhook_id')->all(),
        );
    }

    public function test_prune_defaults_to_ninety_days(): void
    {
        config(['portone.webhook_retention_days' => null]);
        $this->record('processed-91', processedAt: now()->subDays(91));
        $this->record('processed-89', processedAt: now()->subDays(89));

        $this->artisan('model:prune', ['--model' => [Webhook::class]])->assertExitCode(0);

        $this->assertSame(['processed-89'], Webhook::query()->pluck('webhook_id')->all());
    }

    public function test_webhook_records_are_unique_per_webhook_id(): void
    {
        $this->record('msg-unique');

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('portone_webhooks')->insert([
            'webhook_id' => 'msg-unique', 'type' => 'X', 'body' => '{}', 'attempts' => 1, 'received_at' => now(),
        ]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function deliver(string $webhookId, string $body): TestResponse
    {
        $headers = (new WebhookSigner(self::SECRET))->headers($body, $webhookId);

        return $this->call('POST', '/portone/webhook', [], [], [], $this->server($headers), $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    private function paidPayment(): Payment
    {
        $payment = WebhookLogUser::query()->create()->preparePayment(100, 'Order A');
        $payment->forceFill(['status' => 'PAID', 'paid_at' => now(), 'paid_amount' => 100])->save();

        return $payment;
    }

    private function record(string $webhookId, ?\DateTimeInterface $processedAt = null, ?\DateTimeInterface $failedAt = null): void
    {
        Webhook::query()->create([
            'webhook_id' => $webhookId,
            'type' => 'Transaction.Paid',
            'body' => '{}',
            'attempts' => 1,
            'received_at' => now()->subDays(365),
            'processed_at' => $processedAt,
            'failed_at' => $failedAt,
            'failure' => $failedAt === null ? null : 'RuntimeException: failed',
        ]);
    }
}

class WebhookLogUser extends Model
{
    use Billable;

    protected $table = 'webhook_log_users';

    public $timestamps = false;

    protected $guarded = [];
}
