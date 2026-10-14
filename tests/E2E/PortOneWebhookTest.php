<?php

declare(strict_types=1);

namespace Tests\E2E;

use Illuminate\Support\Facades\Http;
use Laravel\Dusk\Browser;
use RuntimeException;
use Workbench\Database\Factories\UserFactory;

/**
 * PortOne delivers webhooks to the Dusk-served Workbench app through ngrok;
 * the browser only triggers the payment.
 */
class PortOneWebhookTest extends TestCase
{
    private static string $observationDirectory;

    public static function setUpBeforeClass(): void
    {
        // The Workbench app records webhook deliveries here; the served app
        // inherits the variable when Testbench Dusk starts it.
        self::$observationDirectory = sys_get_temp_dir().'/portone-webhook-'.bin2hex(random_bytes(6));
        mkdir(self::$observationDirectory);
        putenv('PORTONE_WEBHOOK_OBSERVATION_DIR='.self::$observationDirectory);
        $_ENV['PORTONE_WEBHOOK_OBSERVATION_DIR'] = $_SERVER['PORTONE_WEBHOOK_OBSERVATION_DIR'] = self::$observationDirectory;

        parent::setUpBeforeClass();
    }

    public function test_live_payment_and_cancellation_webhooks_reach_the_public_endpoint(): void
    {
        $publicUrl = $_SERVER['PORTONE_WEBHOOK_PUBLIC_URL'] ?? $_ENV['PORTONE_WEBHOOK_PUBLIC_URL'] ?? '';

        if ($publicUrl === '') {
            $this->markTestSkipped(
                'Set PORTONE_WEBHOOK_PUBLIC_URL to the ngrok URL of the webhook route ('
                .static::applicationBaseUrl().route('portone.webhook', absolute: false).').',
            );
        }

        self::assertContains(parse_url($publicUrl, PHP_URL_SCHEME), ['https', 'http']);
        self::assertIsString(parse_url($publicUrl, PHP_URL_HOST));
        self::assertNotEmpty(config('portone.webhook_secret'), 'PORTONE_WEBHOOK_SECRET must be set in the local .env file.');

        $eventsPath = self::$observationDirectory.'/events.jsonl';
        $requestsPath = self::$observationDirectory.'/requests.jsonl';

        $user = UserFactory::new()->create();
        $payment = $user->preparePayment(100, 'PortOne webhook delivery probe');

        $this->browse(function (Browser $browser) use ($user, $payment, $publicUrl): void {
            $this->payInBrowser(
                $browser,
                $user,
                $payment,
                "Complete the 100 KRW PortOne test payment in the browser window. Confirm the PortOne webhook URL is {$publicUrl}.",
            );
        });

        $user->completePayment($payment->payment_id);
        $paidDelivery = $this->waitForWebhook($eventsPath, $requestsPath, 'Transaction.Paid', $payment->payment_id);
        self::assertSame(200, $paidDelivery['response']['status'] ?? null);
        self::assertSame('', $paidDelivery['response']['body'] ?? null);
        self::assertSame($paidDelivery['headers']['webhook-id'] ?? null, $paidDelivery['event']['webhookId'] ?? null);

        $eventCountBeforeTamperedRequest = count($this->readJsonLines($eventsPath));
        $tamperedHeaders = $paidDelivery['headers'];
        $tamperedHeaders['webhook-signature'] = 'v1,invalid';
        $tampered = Http::withHeaders($tamperedHeaders)
            ->withBody($paidDelivery['body'], 'application/json')
            ->post($publicUrl);
        self::assertSame(400, $tampered->status());
        self::assertSame($eventCountBeforeTamperedRequest, count($this->readJsonLines($eventsPath)));

        $user->refund($payment->payment_id, 'PortOne webhook verification');
        $cancelledDelivery = $this->waitForWebhook($eventsPath, $requestsPath, 'Transaction.Cancelled', $payment->payment_id);
        self::assertSame(200, $cancelledDelivery['response']['status'] ?? null);
        self::assertSame('', $cancelledDelivery['response']['body'] ?? null);
        self::assertSame(
            $cancelledDelivery['headers']['webhook-id'] ?? null,
            $cancelledDelivery['event']['webhookId'] ?? null,
        );

        $this->recordObservation('portone-webhook-observation.json', [
            'recordedAt' => gmdate(DATE_ATOM),
            'publicEndpoint' => parse_url($publicUrl, PHP_URL_HOST),
            'paymentId' => $payment->payment_id,
            'paymentCompleted' => $paidDelivery,
            'tamperedSignatureStatus' => $tampered->status(),
            'paymentCancelled' => $cancelledDelivery,
            'secretsRecorded' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForWebhook(string $eventsPath, string $requestsPath, string $expectedType, string $paymentId): array
    {
        $deadline = time() + self::PAYER_TIMEOUT;

        while (time() < $deadline) {
            $events = $this->readJsonLines($eventsPath);
            $requests = $this->readJsonLines($requestsPath);

            foreach ($events as $event) {
                foreach ($requests as $request) {
                    if (($request['headers']['webhook-id'] ?? null) !== ($event['webhookId'] ?? null)) {
                        continue;
                    }

                    $body = json_decode((string) ($request['body'] ?? ''), true);
                    if (
                        is_array($body)
                        && ($body['type'] ?? null) === $expectedType
                        && ($body['data']['paymentId'] ?? null) === $paymentId
                    ) {
                        return ['event' => $event, ...$request];
                    }
                }
            }

            usleep(250_000);
        }

        throw new RuntimeException("Timed out waiting for live {$expectedType} webhook delivery.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonLines(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );
    }
}
