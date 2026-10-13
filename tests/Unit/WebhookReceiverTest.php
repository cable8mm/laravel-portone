<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\Models\Customer;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\PortonePhp\Webhook\UnrecognizedWebhook;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class WebhookReceiverTest extends TestCase
{
    private const SECRET = 'dGVzdC1zZWNyZXQ=';

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('portone.webhook_secret', self::SECRET);
    }

    public function test_valid_unrecognized_webhook_dispatches_once_without_auth_or_csrf_and_preserves_records(): void
    {
        $this->migratePackageTables();
        Customer::query()->create([
            'billable_type' => 'user',
            'billable_id' => '1',
            'customer_id' => 'customer-1',
        ]);
        Payment::query()->create([
            'payment_id' => 'payment-1',
            'billable_type' => 'user',
            'billable_id' => '1',
            'customer_id' => 'customer-1',
            'order_name' => 'Test payment',
            'amount' => 100,
            'status' => 'PENDING',
            'cancelled_amount' => 0,
            'paid_at' => null,
        ]);
        $customersBefore = Customer::query()->get()->toArray();
        $paymentsBefore = Payment::query()->get()->toArray();
        $route = $this->app['router']->getRoutes()->getByName('portone.webhook');
        $this->assertNotNull($route);
        $this->assertSame([], $route->gatherMiddleware());
        Event::fake([WebhookReceived::class]);

        $body = "{\n  \"type\": \"Future.UnknownEvent\", \"data\": {\"kept\": true}\n}";
        [$headers, $timestamp] = $this->signedHeaders($body);
        $response = $this->call('POST', '/portone/webhook', [], [], [], $headers, $body);

        $response->assertOk();
        $this->assertSame('', $response->getContent());
        Event::assertDispatched(
            WebhookReceived::class,
            fn (WebhookReceived $event): bool => $event->webhookId === 'webhook-test-id'
                && $event->webhook instanceof UnrecognizedWebhook
                && $event->webhook->body['data']['kept'] === true,
        );
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        $this->assertSame($customersBefore, Customer::query()->get()->toArray());
        $this->assertSame($paymentsBefore, Payment::query()->get()->toArray());
        $this->assertGreaterThan(0, (int) $timestamp);
    }

    public function test_repeated_verified_delivery_dispatches_an_event_for_each_request(): void
    {
        Event::fake([WebhookReceived::class]);
        $body = '{"type":"Future.RepeatedEvent"}';
        [$headers] = $this->signedHeaders($body);

        $this->call('POST', '/portone/webhook', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/portone/webhook', [], [], [], $headers, $body)->assertOk();

        Event::assertDispatchedTimes(WebhookReceived::class, 2);
    }

    public function test_invalid_signatures_missing_headers_stale_timestamps_and_invalid_bodies_are_rejected(): void
    {
        Event::fake([WebhookReceived::class]);
        $body = '{"type":"Future.InvalidEvent"}';
        [$headers, $timestamp] = $this->signedHeaders($body);

        $invalidSignature = $headers;
        $invalidSignature['HTTP_WEBHOOK_SIGNATURE'] = 'v1,invalid';
        $this->call('POST', '/portone/webhook', [], [], [], $invalidSignature, $body)->assertStatus(400);

        $missingHeader = $headers;
        unset($missingHeader['HTTP_WEBHOOK_TIMESTAMP']);
        $this->call('POST', '/portone/webhook', [], [], [], $missingHeader, $body)->assertStatus(400);

        $oldTimestamp = (string) (time() - 301);
        $staleHeaders = $this->signatureHeaders($body, $oldTimestamp);
        $this->call('POST', '/portone/webhook', [], [], [], $staleHeaders, $body)->assertStatus(400);

        $invalidBody = '{not-json';
        [$invalidBodyHeaders] = $this->signedHeaders($invalidBody);
        $this->call('POST', '/portone/webhook', [], [], [], $invalidBodyHeaders, $invalidBody)->assertStatus(400);

        Event::assertNotDispatched(WebhookReceived::class);
        $this->assertGreaterThan(0, (int) $timestamp);
    }

    public function test_missing_webhook_secret_returns_server_error_without_dispatching(): void
    {
        config(['portone.webhook_secret' => '']);
        Event::fake([WebhookReceived::class]);

        $response = $this->call('POST', '/portone/webhook', [], [], [], [], '{}');

        $response->assertStatus(500);
        $this->assertSame('', $response->getContent());
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_listener_exceptions_propagate_without_a_success_response(): void
    {
        Event::listen(WebhookReceived::class, static function (): never {
            throw new RuntimeException('listener failed');
        });
        $this->withoutExceptionHandling();
        $body = '{"type":"Future.ListenerFailure"}';
        [$headers] = $this->signedHeaders($body);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('listener failed');

        $this->call('POST', '/portone/webhook', [], [], [], $headers, $body);
    }

    /**
     * @return array{array<string, string>, string}
     */
    private function signedHeaders(string $body): array
    {
        $timestamp = (string) time();

        return [$this->signatureHeaders($body, $timestamp), $timestamp];
    }

    /**
     * @return array<string, string>
     */
    private function signatureHeaders(string $body, string $timestamp): array
    {
        $id = 'webhook-test-id';
        $secret = base64_decode(self::SECRET, true);
        $this->assertIsString($secret);
        $signature = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $secret, true));

        return [
            'HTTP_WEBHOOK_ID' => $id,
            'HTTP_WEBHOOK_SIGNATURE' => 'v1,'.$signature,
            'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
        ];
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
}

class CustomWebhookRouteTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('portone.webhook_path', 'custom/portone-events');
    }

    public function test_configured_webhook_path_replaces_the_default_path(): void
    {
        $this->assertTrue($this->app['router']->has('portone.webhook'));
        $this->post('/custom/portone-events')->assertStatus(500);
        $this->post('/portone/webhook')->assertNotFound();
    }
}
