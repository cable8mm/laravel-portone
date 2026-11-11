<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\BillingKeyGateway;
use Cable8mm\LaravelPortone\BillingKeyRegistry;
use Cable8mm\LaravelPortone\Events\BillingKeyDeleted;
use Cable8mm\LaravelPortone\Exceptions\InvalidBillingKeyException;
use Cable8mm\LaravelPortone\Models\Webhook;
use Cable8mm\LaravelPortone\PaymentGateway;
use Laravel\Dusk\Browser;
use Workbench\Database\Factories\UserFactory;

/**
 * T-046: a billing key is issued through the Package (EL-071, EL-073), charged once (EL-076) and deleted at PortOne;
 * the BillingKey.Deleted webhook reaching the Workbench app through ngrok marks the record DELETED (FR-127).
 *
 * The key is deleted with the Package's PortOne Gateway request (the one EL-075 sends) without the record write, so
 * only the webhook can move the record to DELETED. EL-075 on the DELETED record then refuses without a request.
 */
class PortOneBillingKeyTest extends TestCase
{
    private const OBSERVATION = 'portone-billing-key-e2e-observation.json';

    private const CHARGE_AMOUNT = 100;

    private static string $observationDirectory;

    public static function setUpBeforeClass(): void
    {
        // The Workbench app records webhook deliveries and billing-key events (with the record it saw) here.
        self::$observationDirectory = sys_get_temp_dir().'/portone-billing-key-'.bin2hex(random_bytes(6));
        mkdir(self::$observationDirectory);
        putenv('PORTONE_WEBHOOK_OBSERVATION_DIR='.self::$observationDirectory);
        $_ENV['PORTONE_WEBHOOK_OBSERVATION_DIR'] = $_SERVER['PORTONE_WEBHOOK_OBSERVATION_DIR'] = self::$observationDirectory;

        parent::setUpBeforeClass();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // EL-057 billing_channel_key is added to the configuration file by T-048. Until then the live test uses
        // the KCP test channel that issued billing keys in T-045 (PORTONE_E2E_CHANNEL_KEY).
        $app['config']->set('portone.billing_channel_key', self::env('PORTONE_BILLING_CHANNEL_KEY') ?? self::env('PORTONE_E2E_CHANNEL_KEY'));
    }

    public function test_billing_key_is_issued_charged_and_deleted_by_webhook(): void
    {
        $publicUrl = self::env('PORTONE_WEBHOOK_PUBLIC_URL') ?? '';

        if ($publicUrl === '') {
            $this->markTestSkipped(
                'Set PORTONE_WEBHOOK_PUBLIC_URL to the ngrok URL of the webhook route ('
                .static::applicationBaseUrl().route('portone.webhook', absolute: false).').',
            );
        }

        self::assertNotEmpty(config('portone.webhook_secret'), 'PORTONE_WEBHOOK_SECRET must be set in the local .env file.');
        self::assertNotEmpty(config('portone.billing_channel_key'), 'PORTONE_E2E_CHANNEL_KEY (the KCP billing-key test channel) must be set in the local .env file.');

        $user = UserFactory::new()->create();
        $record = $user->prepareBillingKey('PortOne billing key probe');
        self::assertSame('PENDING', $record->status);

        // Issuance: the human registers a card; the completed page calls completeBillingKey() in the served app.
        $this->browse(function (Browser $browser) use ($user, $record, $publicUrl): void {
            $browser->loginAs($user)
                ->visit(route('workbench.billing-key', ['issueId' => $record->issue_id], false))
                ->waitFor('button#workbench-billing-key-button:enabled')
                ->click('#workbench-billing-key-button');

            $this->instruct("Register a test card in the KCP window (billing-key issuance {$record->issue_id}). Confirm the PortOne webhook URL is {$publicUrl}.");
            $browser->waitFor('[data-workbench-billing-key-completed], [data-workbench-billing-key][data-result="error"]', self::PAYER_TIMEOUT);
            self::assertSame('issued', $browser->attribute('[data-workbench-billing-key-completed]', 'data-result'), 'The billing-key issuance did not complete.');
        });

        $issued = $record->fresh();
        self::assertSame('ISSUED', $issued->status);
        self::assertNotNull($issued->issued_at);
        $plainKey = $this->app->make(BillingKeyRegistry::class)->plainKey($issued);
        self::assertIsString($plainKey);
        self::assertNotSame($plainKey, $issued->billing_key, 'The billing key must be stored encrypted.');

        // One charge with the issued key (EL-076).
        $payment = $user->chargeBillingKey($issued->issue_id, self::CHARGE_AMOUNT, 'PortOne billing key charge probe');
        self::assertSame(['PAID', self::CHARGE_AMOUNT, $issued->id], [$payment->status, $payment->paid_amount, $payment->billing_key_id]);
        $lookup = $this->app->make(PaymentGateway::class)->lookup($payment->payment_id);
        self::assertSame(['PAID', self::CHARGE_AMOUNT], [$lookup->status, $lookup->totalAmount]);

        // Deletion at PortOne without the record write; the webhook must apply it.
        $this->app->make(BillingKeyGateway::class)->deleteBillingKey($plainKey, 'PortOne billing key probe cleanup');
        self::assertSame('ISSUED', $issued->fresh()->status);
        $this->instruct('Waiting for the BillingKey.Deleted webhook to reach the Workbench app.');
        $deleted = $this->waitForBillingKeyEvent(BillingKeyDeleted::class, $issued->issue_id);
        self::assertSame(['webhook', 'DELETED'], [$deleted['event']['source'], $deleted['event']['recordStatus']]);

        $final = $issued->fresh();
        self::assertSame('DELETED', $final->status);
        self::assertNotNull($final->deleted_at);

        // EL-075 on the DELETED record: refused before any PortOne request.
        try {
            $user->deleteBillingKey($final->issue_id, 'PortOne billing key probe repeat');
            self::fail('Deleting a DELETED billing key must be refused.');
        } catch (InvalidBillingKeyException) {
        }
        self::assertSame('DELETED', $final->fresh()->status);

        // No recording keeps the billing key (FR-130).
        $webhookBodies = Webhook::query()->pluck('body')->implode("\n");
        $recordings = implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents($file),
            glob(self::$observationDirectory.'/*.jsonl') ?: [],
        ));
        self::assertStringNotContainsString($plainKey, $webhookBodies);
        self::assertStringNotContainsString($plainKey, $recordings);
        self::assertStringNotContainsString($plainKey, (string) json_encode($final->toArray()));

        $observation = [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
            'issuance' => [
                'issueId' => $issued->issue_id,
                'recordStatusAfterCompletion' => $issued->status,
                'billingKeyEvents' => array_values(array_filter(
                    $this->readJsonLines(self::$observationDirectory.'/billing-key-events.jsonl'),
                    static fn (array $event): bool => ($event['issueId'] ?? null) === $issued->issue_id,
                )),
            ],
            'charge' => [
                'paymentId' => $payment->payment_id,
                'amount' => self::CHARGE_AMOUNT,
                'recordStatus' => $payment->status,
                'portOneStatus' => $lookup->status,
                'billingKeyIdLinked' => $payment->billing_key_id === $issued->id,
            ],
            'deletion' => [
                'by' => 'BillingKeyGateway::deleteBillingKey() (the EL-075 request) without the record write',
                'deletedEvent' => $deleted['event'],
                'webhookBody' => $deleted['body'],
                'finalRecordStatus' => $final->status,
                'repeatDelete' => InvalidBillingKeyException::class,
            ],
            'webhookTypesReceived' => array_values(array_unique(array_map(
                static fn (array $request): ?string => json_decode((string) ($request['body'] ?? ''), true)['type'] ?? null,
                $this->readJsonLines(self::$observationDirectory.'/requests.jsonl'),
            ))),
            'secretsRecorded' => false,
        ];
        self::assertStringNotContainsString($plainKey, (string) json_encode($observation));
        $this->recordObservation(self::OBSERVATION, $observation);
    }

    /**
     * Wait until the Workbench app records the billing-key event, and return it with the (redacted) webhook body.
     *
     * @return array{event: array<string, mixed>, body: string}
     */
    private function waitForBillingKeyEvent(string $eventClass, string $issueId): array
    {
        $deadline = time() + self::PAYER_TIMEOUT;

        while (time() < $deadline) {
            foreach ($this->readJsonLines(self::$observationDirectory.'/billing-key-events.jsonl') as $event) {
                if (($event['event'] ?? null) !== $eventClass || ($event['issueId'] ?? null) !== $issueId) {
                    continue;
                }

                foreach ($this->readJsonLines(self::$observationDirectory.'/requests.jsonl') as $request) {
                    if (($request['headers']['webhook-id'] ?? null) === ($event['webhookId'] ?? null)) {
                        self::assertSame(200, $request['response']['status'] ?? null);

                        return ['event' => $event, 'body' => (string) ($request['body'] ?? '')];
                    }
                }
            }

            usleep(250_000);
        }

        throw new \RuntimeException("Timed out waiting for {$eventClass} for billing-key issuance {$issueId}.");
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
