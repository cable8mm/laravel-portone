<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Events\PaymentCancelled;
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\PortOneGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\Testing\InteractsWithPortOneWebhooks;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Cable8mm\PortonePhp\Webhook\WebhookTransaction;
use Cable8mm\PortonePhp\Webhook\WebhookVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * T-036: the signed webhook test helper (AC-087) and portone:webhook-replay (AC-088).
 *
 * The body follows the live Transaction.* recordings (tests/E2E/portone-webhook-observation.json); the
 * transactionId comes from the recorded lookup response (tests/E2E/portone-payment-command-observation.json).
 * Unknown payments fail with HTTP 404 PAYMENT_NOT_FOUND (T-003).
 */
class WebhookTestingSupportTest extends TestCase
{
    use InteractsWithPortOneWebhooks;

    private const SECRET = 'dGVzdC1zZWNyZXQ=';

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('portone.webhook_secret', self::SECRET);
        $app['config']->set('portone.store_id', 'store-720c15ca-0a45-4811-9e40-c8321ca05e8e');
        $app['config']->set('app.url', 'http://shop.test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }
    }

    public function test_helper_sends_a_webhook_the_package_verifies(): void
    {
        Event::fake([WebhookReceived::class, PaymentCancelled::class]);

        $this->postPortOneWebhook('Transaction.Cancelled', 'payment-1', ['cancellationId' => 'cancellation-1'])->assertOk();

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->webhook instanceof WebhookTransaction
            && $event->webhook->type === 'Transaction.Cancelled'
            && $event->webhook->data->paymentId === 'payment-1'
            && $event->webhook->data->storeId === 'store-720c15ca-0a45-4811-9e40-c8321ca05e8e'
            && $event->webhook->data->cancellationId === 'cancellation-1'
            && str_starts_with($event->webhookId, 'msg-'));
        Event::assertDispatched(PaymentCancelled::class, fn (PaymentCancelled $event): bool => $event->paymentId === 'payment-1');
    }

    public function test_helper_requires_the_webhook_secret(): void
    {
        config(['portone.webhook_secret' => null]);

        $this->expectException(ConfigurationException::class);

        $this->postPortOneWebhook('Transaction.Paid', 'payment-1');
    }

    public function test_replay_signs_and_posts_to_the_application_webhook_url(): void
    {
        $this->fakeLookup(['transactionId' => '01a1404f-1056-9cbe-6cb3-fee538ee8d34']);
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('portone:webhook-replay', [
            'type' => 'Transaction.Paid',
            'paymentId' => 'payment-1',
            '--data' => '{"note":"local"}',
        ])->expectsOutput('POST http://shop.test/portone/webhook -> HTTP 200')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $webhook = (new WebhookVerifier(self::SECRET))->verify(
                $request->body(),
                $request->header('webhook-id')[0] ?? null,
                $request->header('webhook-signature')[0] ?? null,
                $request->header('webhook-timestamp')[0] ?? null,
            );
            $data = json_decode($request->body(), true)['data'];

            return $request->url() === 'http://shop.test/portone/webhook'
                && $request->method() === 'POST'
                && $webhook instanceof WebhookTransaction
                && $webhook->type === 'Transaction.Paid'
                && $webhook->data->transactionId === '01a1404f-1056-9cbe-6cb3-fee538ee8d34'
                && $data['note'] === 'local';
        });
    }

    public function test_replay_refuses_production_without_force(): void
    {
        $this->app['env'] = 'production';
        $this->fakeLookup(['transactionId' => 't']);
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('portone:webhook-replay', ['type' => 'Transaction.Paid', 'paymentId' => 'payment-1'])->assertExitCode(1);
        Http::assertNothingSent();

        $this->artisan('portone:webhook-replay', ['type' => 'Transaction.Paid', 'paymentId' => 'payment-1', '--force' => true])->assertExitCode(0);
        Http::assertSentCount(1);
    }

    public function test_replay_fails_on_a_non_success_response(): void
    {
        $this->fakeLookup(['transactionId' => 't']);
        Http::fake(['*' => Http::response('', 400)]);

        $this->artisan('portone:webhook-replay', ['type' => 'Transaction.Paid', 'paymentId' => 'payment-1'])
            ->expectsOutput('POST http://shop.test/portone/webhook -> HTTP 400')
            ->assertExitCode(1);
    }

    public function test_replay_sends_nothing_without_a_secret_with_bad_data_or_for_an_unknown_payment(): void
    {
        Http::fake();
        $this->fakeLookup(['transactionId' => 't']);

        $this->artisan('portone:webhook-replay', ['type' => 'Transaction.Paid', 'paymentId' => 'payment-1', '--data' => '[1,2]'])->assertExitCode(1);

        config(['portone.webhook_secret' => '']);
        $this->artisan('portone:webhook-replay', ['type' => 'Transaction.Paid', 'paymentId' => 'payment-1'])->assertExitCode(1);

        config(['portone.webhook_secret' => self::SECRET]);
        $this->fakeLookup(new UnknownException('getPayment', 'Payment not found', status: 404, errorType: 'PAYMENT_NOT_FOUND'));
        $this->artisan('portone:webhook-replay', ['type' => 'Transaction.Paid', 'paymentId' => 'unknown'])->assertExitCode(1);

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>|\Throwable  $response
     */
    private function fakeLookup(array|\Throwable $response): void
    {
        $this->app->instance(PortOneGateway::class, new class($response) extends PortOneGateway
        {
            /**
             * @param  array<string, mixed>|\Throwable  $response
             */
            public function __construct(private readonly array|\Throwable $response) {}

            public function paymentResponse(string $paymentId): array
            {
                if ($this->response instanceof \Throwable) {
                    throw $this->response;
                }

                return $this->response;
            }
        });
    }
}
