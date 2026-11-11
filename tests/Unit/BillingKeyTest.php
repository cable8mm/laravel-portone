<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\BillingKeyGateway;
use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Events\BillingKeyDeleted;
use Cable8mm\LaravelPortone\Events\BillingKeyEvent;
use Cable8mm\LaravelPortone\Events\BillingKeyIssued;
use Cable8mm\LaravelPortone\Events\PaymentCompleted;
use Cable8mm\LaravelPortone\Events\PaymentEvent;
use Cable8mm\LaravelPortone\Events\WebhookFailed;
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\Exceptions\BillingKeyNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\BillingKeyNotIssuedException;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\InvalidBillingKeyException;
use Cable8mm\LaravelPortone\GatewayBillingKey;
use Cable8mm\LaravelPortone\Models\BillingKey;
use Cable8mm\LaravelPortone\Models\Customer;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\Models\Webhook;
use Cable8mm\LaravelPortone\PortOneGateway;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\Testing\FakePaymentGateway;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Cable8mm\PortonePhp\Model\BillingKeyInfo;
use Cable8mm\PortonePhp\Webhook\WebhookSigner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * T-046: billing keys and billing-key charges (SCR-012, FR-118 to FR-130, ERR-023 to ERR-025, ERR-028, AC-100 to AC-105).
 *
 * Every PortOne value below is traced to the T-045 observation (tools/probes/portone-billing-key-observation.json):
 * - getBillingKeyInfo after issuance: status ISSUED with customer.id and issueId equal to the issuance values;
 *   after deletion: status DELETED with the same customer.id and issueId (steps.billingKeyInfo, steps.deletion).
 * - payWithBillingKey: success, then getPayment PAID with amount.total 100 and a receiptUrl on testpartner.kcp.co.kr
 *   (steps.charge, steps.chargedPaymentLookup); a PG refusal is UnknownException HTTP 502 PG_PROVIDER with pgCode 8105,
 *   and charging or deleting a deleted key is UnknownException HTTP 409 BILLING_KEY_ALREADY_DELETED
 *   (steps.chargeFailure, steps.afterDeletion).
 * - BillingKey.Ready, BillingKey.Issued, and BillingKey.Deleted webhooks carry only data.billingKey and data.storeId (webhooks).
 *
 * The billing key and receipt URL path are placeholders because the observation redacts them.
 */
class BillingKeyTest extends TestCase
{
    private const SECRET = 'dGVzdC1zZWNyZXQ=';

    private const KEY = 'billing-key-00000000-0000-0000-0000-000000000001';

    private const OTHER_KEY = 'billing-key-00000000-0000-0000-0000-000000000002';

    private const RECEIPT = 'https://testpartner.kcp.co.kr/receipt-billing-key';

    private FakePaymentGateway $payments;

    private RecordingBillingKeyGateway $gateway;

    /** @var list<object> */
    private array $events = [];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('portone.webhook_secret', self::SECRET);
        $app['config']->set('portone.store_id', 'store-test');
        // The billing key is encrypted with the application key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }

        Schema::create('billing_users', function (Blueprint $table): void {
            $table->id();
        });

        $this->payments = FakePaymentGateway::bind();
        $this->gateway = new RecordingBillingKeyGateway;
        $this->app->instance(BillingKeyGateway::class, $this->gateway);

        Event::listen([BillingKeyIssued::class, BillingKeyDeleted::class, PaymentCompleted::class], function (object $event): void {
            $this->events[] = $event;
        });
    }

    public function test_prepare_creates_a_pending_record_with_a_new_issue_id_and_sends_no_request(): void
    {
        $user = BillingUser::query()->create();
        $payment = $user->preparePayment(100, 'Order A');

        $first = $user->prepareBillingKey('Monthly plan');
        $second = $user->prepareBillingKey('Monthly plan');

        $this->assertSame('PENDING', $first->status);
        $this->assertSame('Monthly plan', $first->issue_name);
        $this->assertNull($first->billing_key);
        $this->assertSame($user->portOneId(), $first->customer_id);
        $this->assertSame(1, Customer::query()->count());
        $this->assertMatchesRegularExpression('/^bk[0-9a-f]{32}$/', $first->issue_id);
        $this->assertNotSame($first->issue_id, $second->issue_id);
        $this->assertNotSame($payment->payment_id, $first->issue_id);
        $this->assertSame([
            'issueId' => $first->issue_id,
            'issueName' => 'Monthly plan',
            'customerId' => $user->portOneId(),
        ], $first->issueParameters());
        $this->assertSame([], $this->gateway->requests);
        $this->payments->assertNothingLookedUp();
        // Records created in the same second have no defined order (RD-08).
        $this->assertEqualsCanonicalizing([$first->issue_id, $second->issue_id], $user->billingKeys()->pluck('issue_id')->all());
    }

    public function test_complete_stores_the_key_encrypted_and_dispatches_issued_once(): void
    {
        $user = BillingUser::query()->create();
        $record = $user->prepareBillingKey('Monthly plan');
        $this->gateway->info(self::KEY, 'ISSUED', $record->customer_id, $record->issue_id);

        $issued = $user->completeBillingKey($record->issue_id, self::KEY);

        $this->assertSame('ISSUED', $issued->status);
        $this->assertNotNull($issued->issued_at);
        $stored = DB::table('portone_billing_keys')->where('issue_id', $record->issue_id)->value('billing_key');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString(self::KEY, $stored);
        $this->assertSame(self::KEY, Crypt::decryptString($stored));
        $this->assertSame([['billingKeyInfo', self::KEY]], $this->gateway->requests);
        $this->assertBillingKeyEvents([[BillingKeyIssued::class, $record->issue_id, 'call', 'ISSUED']]);

        $again = $user->completeBillingKey($record->issue_id, self::KEY);

        $this->assertSame($issued->getAttributes(), $again->getAttributes());
        $this->assertCount(1, $this->gateway->requests);
        $this->assertCount(1, $this->events);
    }

    public function test_a_key_issued_for_another_customer_fails_the_record(): void
    {
        $user = BillingUser::query()->create();
        $record = $user->prepareBillingKey('Monthly plan');
        $this->gateway->info(self::KEY, 'ISSUED', 'another-customer', $record->issue_id);

        $this->assertThrows(
            fn () => $user->completeBillingKey($record->issue_id, self::KEY),
            BillingKeyNotIssuedException::class,
        );

        $this->assertSame('FAILED', $record->fresh()->status);
        $this->assertNull($record->fresh()->billing_key);
        $this->assertSame([], $this->events);
    }

    public function test_a_deleted_key_fails_the_record_and_a_failed_record_can_be_issued_later(): void
    {
        $user = BillingUser::query()->create();
        $record = $user->prepareBillingKey('Monthly plan');
        $this->gateway->info(self::OTHER_KEY, 'DELETED', $record->customer_id, $record->issue_id);

        $this->assertThrows(fn () => $user->completeBillingKey($record->issue_id, self::OTHER_KEY), BillingKeyNotIssuedException::class);
        $this->assertSame('FAILED', $record->fresh()->status);

        $this->gateway->info(self::KEY, 'ISSUED', $record->customer_id, $record->issue_id);
        $issued = $user->completeBillingKey($record->issue_id, self::KEY);

        $this->assertSame('ISSUED', $issued->status);
        $this->assertBillingKeyEvents([[BillingKeyIssued::class, $record->issue_id, 'call', 'ISSUED']]);
    }

    public function test_complete_refuses_unknown_and_other_models_issue_ids_without_a_request(): void
    {
        $user = BillingUser::query()->create();
        $other = BillingUser::query()->create();
        $record = $other->prepareBillingKey('Monthly plan');

        $this->assertThrows(fn () => $user->completeBillingKey($record->issue_id, self::KEY), BillingKeyNotFoundException::class);
        $this->assertThrows(fn () => $user->completeBillingKey('bk-unknown', self::KEY), BillingKeyNotFoundException::class);

        $this->assertSame('PENDING', $record->fresh()->status);
        $this->assertSame([], $this->gateway->requests);
    }

    public function test_complete_refuses_another_key_for_an_issued_record_without_a_request_or_change(): void
    {
        [$user, $record] = $this->issuedRecord();
        $before = $record->fresh()->getAttributes();

        $this->assertThrows(fn () => $user->completeBillingKey($record->issue_id, self::OTHER_KEY), BillingKeyNotIssuedException::class);

        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame([], $this->gateway->requests);
    }

    public function test_complete_on_a_deleted_record_is_not_found(): void
    {
        [$user, $record] = $this->issuedRecord();
        $user->deleteBillingKey($record->issue_id, 'Card removed');
        $this->gateway->requests = [];

        $this->assertThrows(fn () => $user->completeBillingKey($record->issue_id, self::KEY), BillingKeyNotFoundException::class);
        $this->assertSame([], $this->gateway->requests);
    }

    public function test_a_portone_failure_during_complete_passes_through_and_changes_nothing(): void
    {
        $user = BillingUser::query()->create();
        $record = $user->prepareBillingKey('Monthly plan');
        $failure = new UnknownException('getBillingKeyInfo', 'PortOne returned an error response.', 502, null, null, 'PG_PROVIDER');
        $this->gateway->failures['billingKeyInfo'] = $failure;

        $this->assertSame($failure, $this->caught(fn () => $user->completeBillingKey($record->issue_id, self::KEY)));
        $this->assertSame('PENDING', $record->fresh()->status);
        $this->assertSame([], $this->events);
    }

    public function test_requests_without_an_api_secret_throw_configuration_exception_and_change_nothing(): void
    {
        [$user, $record] = $this->issuedRecord();
        $pending = $user->prepareBillingKey('Another plan');
        $this->app->bind(BillingKeyGateway::class, PortOneGateway::class);
        config(['portone.api_secret' => '']);
        $before = BillingKey::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->assertThrows(fn () => $user->completeBillingKey($pending->issue_id, self::KEY), ConfigurationException::class);
        $this->assertThrows(fn () => $user->deleteBillingKey($record->issue_id, 'Card removed'), ConfigurationException::class);
        $this->assertThrows(fn () => $user->chargeBillingKey($record->issue_id, 100, 'Monthly plan'), ConfigurationException::class);

        $this->assertSame($before, BillingKey::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_delete_removes_the_key_at_portone_and_dispatches_deleted(): void
    {
        [$user, $record] = $this->issuedRecord();

        $deleted = $user->deleteBillingKey($record->issue_id, 'Card removed');

        $this->assertSame('DELETED', $deleted->status);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame([['deleteBillingKey', self::KEY, 'Card removed']], $this->gateway->requests);
        $this->assertBillingKeyEvents([[BillingKeyDeleted::class, $record->issue_id, 'call', 'DELETED']]);

        $this->assertThrows(fn () => $user->deleteBillingKey($record->issue_id, 'Card removed'), InvalidBillingKeyException::class);
        $this->assertCount(1, $this->gateway->requests);
    }

    public function test_delete_refuses_pending_and_other_models_records_without_a_request(): void
    {
        $user = BillingUser::query()->create();
        $pending = $user->prepareBillingKey('Monthly plan');
        [, $othersRecord] = $this->issuedRecord();

        $this->assertThrows(fn () => $user->deleteBillingKey($pending->issue_id, 'Card removed'), InvalidBillingKeyException::class);
        $this->assertThrows(fn () => $user->deleteBillingKey($othersRecord->issue_id, 'Card removed'), InvalidBillingKeyException::class);

        $this->assertSame('PENDING', $pending->fresh()->status);
        $this->assertSame('ISSUED', $othersRecord->fresh()->status);
        $this->assertSame([], $this->gateway->requests);
    }

    public function test_a_refused_deletion_passes_through_and_keeps_the_record_issued(): void
    {
        [$user, $record] = $this->issuedRecord();
        $failure = new UnknownException('deleteBillingKey', 'PortOne returned an error response.', 409, '{"type":"BILLING_KEY_ALREADY_DELETED"}', null, 'BILLING_KEY_ALREADY_DELETED');
        $this->gateway->failures['deleteBillingKey'] = $failure;

        $this->assertSame($failure, $this->caught(fn () => $user->deleteBillingKey($record->issue_id, 'Card removed')));
        $this->assertSame('ISSUED', $record->fresh()->status);
        $this->assertSame([], $this->events);
    }

    public function test_charge_records_the_payment_against_the_key_and_completes_it_with_source_charge(): void
    {
        [$user, $record] = $this->issuedRecord();
        $this->gateway->onCharge = function (string $paymentId): void {
            $this->payments->payment($paymentId, 'PAID', totalAmount: 100, cancelledAmount: 0, receiptUrl: self::RECEIPT);
        };

        $payment = $user->chargeBillingKey($record->issue_id, 100, 'Monthly plan');

        $this->assertSame('PAID', $payment->status);
        $this->assertSame([100, 100, 0], [$payment->amount, $payment->paid_amount, $payment->cancelled_amount]);
        $this->assertSame(self::RECEIPT, $payment->receipt_url);
        $this->assertSame($record->id, $payment->billing_key_id);
        $this->assertTrue($payment->billingKey?->is($record));
        $this->assertSame($user->portOneId(), $payment->customer_id);
        $this->assertSame([['payWithBillingKey', $payment->payment_id, self::KEY, 'Monthly plan', 100]], $this->gateway->requests);
        $this->payments->assertLookedUp($payment->payment_id, times: 1);
        $completed = array_values(array_filter($this->events, fn (object $event): bool => $event instanceof PaymentEvent));
        $this->assertCount(1, $completed);
        $this->assertInstanceOf(PaymentCompleted::class, $completed[0]);
        $this->assertSame('charge', $completed[0]->source->value);
    }

    public function test_a_refused_charge_leaves_the_payment_pending_and_passes_the_portone_exception(): void
    {
        [$user, $record] = $this->issuedRecord();
        $failure = new UnknownException('payWithBillingKey', 'PortOne returned an error response.', 502, '{"type":"PG_PROVIDER","pgCode":"8105","pgMessage":"포맷에러(지불|신용카드|금액)"}', null, 'PG_PROVIDER');
        $this->gateway->failures['payWithBillingKey'] = $failure;

        $this->assertSame($failure, $this->caught(fn () => $user->chargeBillingKey($record->issue_id, 1, 'Monthly plan')));

        $payment = Payment::query()->sole();
        $this->assertSame(['PENDING', 1, $record->id], [$payment->status, $payment->amount, $payment->billing_key_id]);
        $this->payments->assertNothingLookedUp();
        $this->assertSame([], array_filter($this->events, fn (object $event): bool => $event instanceof PaymentEvent));
    }

    public function test_charge_refuses_records_that_are_not_issued_or_belong_to_another_model(): void
    {
        $user = BillingUser::query()->create();
        $pending = $user->prepareBillingKey('Monthly plan');
        [, $othersRecord] = $this->issuedRecord();

        $this->assertThrows(fn () => $user->chargeBillingKey($pending->issue_id, 100, 'Monthly plan'), InvalidBillingKeyException::class);
        $this->assertThrows(fn () => $user->chargeBillingKey($othersRecord->issue_id, 100, 'Monthly plan'), InvalidBillingKeyException::class);
        $this->assertThrows(fn () => $user->chargeBillingKey($othersRecord->issue_id, 0, 'Monthly plan'), \InvalidArgumentException::class);

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame([], $this->gateway->requests);
    }

    public function test_billing_key_deleted_webhook_marks_the_issued_record_deleted_with_source_webhook(): void
    {
        [, $record] = $this->issuedRecord();
        $this->gateway->info(self::KEY, 'DELETED', $record->customer_id, $record->issue_id);
        Event::fake([WebhookReceived::class]);

        $body = $this->webhookBody('BillingKey.Deleted', self::KEY);
        $this->postWebhook('webhook-deleted', $body)->assertOk();

        $this->assertSame('DELETED', $record->fresh()->status);
        $this->assertNotNull($record->fresh()->deleted_at);
        $this->assertSame([['billingKeyInfo', self::KEY]], $this->gateway->requests);
        $this->assertBillingKeyEvents([[BillingKeyDeleted::class, $record->issue_id, 'webhook', 'DELETED']]);
        Event::assertDispatchedTimes(WebhookReceived::class, 1);

        // The Webhook record keeps the body without the billing key (FR-130).
        $stored = Webhook::query()->where('webhook_id', 'webhook-deleted')->sole();
        $this->assertSame('BillingKey.Deleted', $stored->type);
        $this->assertStringNotContainsString(self::KEY, $stored->body);
        $this->assertSame(str_replace(self::KEY, '[REDACTED]', $body), $stored->body);

        // A repeated deletion webhook for the now DELETED record changes nothing.
        $this->postWebhook('webhook-deleted-again', $this->webhookBody('BillingKey.Deleted', self::KEY))->assertOk();
        $this->assertCount(1, $this->events);
    }

    public function test_billing_key_issued_webhook_issues_a_pending_record_and_ready_changes_nothing(): void
    {
        $user = BillingUser::query()->create();
        $record = $user->prepareBillingKey('Monthly plan');

        $this->postWebhook('webhook-ready', $this->webhookBody('BillingKey.Ready', self::OTHER_KEY))->assertOk();
        $this->assertSame('PENDING', $record->fresh()->status);
        $this->assertSame([], $this->gateway->requests);

        $this->gateway->info(self::KEY, 'ISSUED', $record->customer_id, $record->issue_id);
        $this->postWebhook('webhook-issued', $this->webhookBody('BillingKey.Issued', self::KEY))->assertOk();

        $this->assertSame('ISSUED', $record->fresh()->status);
        $this->assertSame(self::KEY, Crypt::decryptString((string) $record->fresh()->billing_key));
        $this->assertBillingKeyEvents([[BillingKeyIssued::class, $record->issue_id, 'webhook', 'ISSUED']]);

        // The redirect return after the webhook finds the record ISSUED with the same key.
        $this->assertSame('ISSUED', $user->completeBillingKey($record->issue_id, self::KEY)->status);
        $this->assertCount(1, $this->events);
    }

    public function test_billing_key_webhooks_for_unknown_issuances_change_nothing(): void
    {
        [, $record] = $this->issuedRecord();
        $before = $record->fresh()->getAttributes();
        $this->gateway->info(self::OTHER_KEY, 'DELETED', 'another-customer', 'bk-of-another-application');

        $this->postWebhook('webhook-unknown', $this->webhookBody('BillingKey.Deleted', self::OTHER_KEY))->assertOk();

        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame([], $this->events);
    }

    public function test_a_failed_lookup_during_a_billing_key_webhook_fails_the_delivery(): void
    {
        [, $record] = $this->issuedRecord();
        $this->gateway->failures['billingKeyInfo'] = new UnknownException('getBillingKeyInfo', 'PortOne returned an error response.', 502, null, null, 'PG_PROVIDER');
        Event::fake([WebhookFailed::class, WebhookReceived::class]);

        $this->postWebhook('webhook-failing', $this->webhookBody('BillingKey.Deleted', self::KEY))->assertServerError();

        $this->assertSame('ISSUED', $record->fresh()->status);
        $this->assertNotNull(Webhook::query()->where('webhook_id', 'webhook-failing')->sole()->failed_at);
        Event::assertDispatchedTimes(WebhookFailed::class, 1);
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_the_billing_key_value_is_never_exposed_by_the_record_or_its_events(): void
    {
        [, $record] = $this->issuedRecord();
        $record = $record->fresh();

        $this->assertArrayNotHasKey('billing_key', $record->toArray());
        $this->assertStringNotContainsString(self::KEY, $record->toJson());
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($record->issueParameters()));
        $this->assertStringNotContainsString(self::KEY, (string) $record->billing_key);
        $this->assertStringNotContainsString(self::KEY, print_r($this->events, true));
    }

    public function test_the_recorded_billing_key_info_maps_to_status_customer_and_issue_id(): void
    {
        $observation = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/tools/probes/portone-billing-key-observation.json'), true);
        $issued = $observation['steps']['billingKeyInfo']['data'];
        $deleted = $observation['steps']['deletion']['billingKeyInfoAfterDeletion']['data'];

        $mapper = new class extends PortOneGateway
        {
            public function map(BillingKeyInfo $info): GatewayBillingKey
            {
                return $this->gatewayBillingKey($info);
            }
        };
        $issuedResult = $mapper->map(BillingKeyInfo::fromArray($issued));
        $deletedResult = $mapper->map(BillingKeyInfo::fromArray($deleted));

        $this->assertSame(['ISSUED', '[the probe customerId]', 'bkdec740f2aa9af93a9667a4e3bac25515'], [$issuedResult->status, $issuedResult->customerId, $issuedResult->issueId]);
        $this->assertSame(['DELETED', '[the probe customerId]', 'bkdec740f2aa9af93a9667a4e3bac25515'], [$deletedResult->status, $deletedResult->customerId, $deletedResult->issueId]);
    }

    /**
     * @return array{0: BillingUser, 1: BillingKey} a Billable model and its ISSUED record, with the recorded requests and events reset
     */
    private function issuedRecord(): array
    {
        $user = BillingUser::query()->create();
        $record = $user->prepareBillingKey('Monthly plan');
        $this->gateway->info(self::KEY, 'ISSUED', $record->customer_id, $record->issue_id);
        $user->completeBillingKey($record->issue_id, self::KEY);
        $this->gateway->requests = [];
        $this->events = [];

        return [$user, $record];
    }

    /**
     * @param  list<array{0: class-string<BillingKeyEvent>, 1: string, 2: string, 3: string}>  $expected  event, Issue ID, source, record status
     */
    private function assertBillingKeyEvents(array $expected): void
    {
        $this->assertSame($expected, array_map(
            static fn (BillingKeyEvent $event): array => [$event::class, $event->billingKey->issue_id, $event->source->value, $event->billingKey->status],
            array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof BillingKeyEvent)),
        ));
    }

    private function caught(\Closure $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    private function webhookBody(string $type, string $billingKey): string
    {
        return json_encode([
            'type' => $type,
            'timestamp' => '2026-11-11T04:56:21.334743665Z',
            'data' => ['billingKey' => $billingKey, 'storeId' => 'store-test'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return TestResponse<Response>
     */
    private function postWebhook(string $webhookId, string $body): TestResponse
    {
        $timestamp = time();
        $signature = (new WebhookSigner(self::SECRET))->sign($webhookId, $timestamp, $body);

        return $this->call('POST', '/portone/webhook', [], [], [], [
            'HTTP_WEBHOOK_ID' => $webhookId,
            'HTTP_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        ], $body);
    }
}

class BillingUser extends Model
{
    use Billable;

    protected $table = 'billing_users';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * BillingKeyGateway test double. Responses are the T-045 recorded shapes set per billing key; every request is recorded.
 */
final class RecordingBillingKeyGateway implements BillingKeyGateway
{
    /** @var list<list<int|string>> */
    public array $requests = [];

    /** @var array<string, Throwable> failures per operation */
    public array $failures = [];

    /** @var array<string, GatewayBillingKey> */
    private array $infos = [];

    /** @var (\Closure(string): void)|null runs after a successful charge, with the Payment ID */
    public ?\Closure $onCharge = null;

    public function info(string $billingKey, string $status, ?string $customerId, ?string $issueId): void
    {
        $this->infos[$billingKey] = new GatewayBillingKey($status, $customerId, $issueId);
    }

    public function assertConfigured(): void {}

    public function billingKeyInfo(#[\SensitiveParameter] string $billingKey): GatewayBillingKey
    {
        $this->requests[] = ['billingKeyInfo', $billingKey];
        $this->fail('billingKeyInfo');

        return $this->infos[$billingKey] ?? throw new \LogicException('The test set no billing-key info for this key.');
    }

    public function deleteBillingKey(#[\SensitiveParameter] string $billingKey, string $reason): void
    {
        $this->requests[] = ['deleteBillingKey', $billingKey, $reason];
        $this->fail('deleteBillingKey');
    }

    public function payWithBillingKey(string $paymentId, #[\SensitiveParameter] string $billingKey, string $orderName, int $amount): void
    {
        $this->requests[] = ['payWithBillingKey', $paymentId, $billingKey, $orderName, $amount];
        $this->fail('payWithBillingKey');

        if ($this->onCharge !== null) {
            ($this->onCharge)($paymentId);
        }
    }

    private function fail(string $operation): void
    {
        if (isset($this->failures[$operation])) {
            throw $this->failures[$operation];
        }
    }
}
