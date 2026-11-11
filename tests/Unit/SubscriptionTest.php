<?php

declare(strict_types=1);

namespace Tests\Unit;

use Cable8mm\LaravelPortone\BillingKeyGateway;
use Cable8mm\LaravelPortone\Concerns\Billable;
use Cable8mm\LaravelPortone\Events\BillingKeyDeleted;
use Cable8mm\LaravelPortone\Events\PaymentCompleted;
use Cable8mm\LaravelPortone\Events\PaymentEventSource;
use Cable8mm\LaravelPortone\Events\SubscriptionCancelled;
use Cable8mm\LaravelPortone\Events\SubscriptionEnded;
use Cable8mm\LaravelPortone\Events\SubscriptionExpired;
use Cable8mm\LaravelPortone\Events\SubscriptionRenewalFailed;
use Cable8mm\LaravelPortone\Events\SubscriptionRenewed;
use Cable8mm\LaravelPortone\Events\SubscriptionResumed;
use Cable8mm\LaravelPortone\Events\SubscriptionStarted;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\InvalidBillingKeyException;
use Cable8mm\LaravelPortone\Exceptions\InvalidSubscriptionStatusException;
use Cable8mm\LaravelPortone\GatewayBillingKey;
use Cable8mm\LaravelPortone\Jobs\ChargeSubscription;
use Cable8mm\LaravelPortone\Models\BillingKey;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\Models\Subscription;
use Cable8mm\LaravelPortone\PortOneServiceProvider;
use Cable8mm\LaravelPortone\SubscriptionLifecycle;
use Cable8mm\LaravelPortone\SubscriptionOperations;
use Cable8mm\LaravelPortone\Testing\FakePaymentGateway;
use Cable8mm\PortonePhp\Exception\UnknownException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Throwable;

/**
 * T-047: subscriptions (SCR-013, EL-058, FR-065, FR-123, FR-131 to FR-144, ERR-024, ERR-026 to ERR-028, FLOW-007,
 * AC-102, AC-106 to AC-110).
 *
 * Every PortOne value below is traced to the T-045 observation (tools/probes/portone-billing-key-observation.json):
 * - getBillingKeyInfo after issuance: status ISSUED with customer.id and issueId equal to the issuance values (steps.billingKeyInfo).
 * - payWithBillingKey success, then getPayment PAID with amount.total 100 and a receiptUrl on testpartner.kcp.co.kr
 *   (steps.charge, steps.chargedPaymentLookup).
 * - A PG refusal of payWithBillingKey is UnknownException HTTP 502 PG_PROVIDER with pgCode 8105; getPayment then reports
 *   FAILED with amount.total equal to the charged amount (steps.chargeFailure).
 * - payWithBillingKey on a deleted key is UnknownException HTTP 409 BILLING_KEY_ALREADY_DELETED and creates no payment,
 *   so getPayment is PAYMENT_NOT_FOUND (steps.afterDeletion).
 *
 * The billing key and receipt URL path are placeholders because the observation redacts them.
 */
class SubscriptionTest extends TestCase
{
    private const KEY = 'billing-key-00000000-0000-0000-0000-000000000001';

    private const OTHER_KEY = 'billing-key-00000000-0000-0000-0000-000000000002';

    private const RECEIPT = 'https://testpartner.kcp.co.kr/receipt-subscription';

    private FakePaymentGateway $payments;

    private SubscriptionBillingKeyGateway $gateway;

    /** @var list<object> */
    private array $events = [];

    protected function getPackageProviders($app): array
    {
        return [PortOneServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
            $this->artisan('migrate', ['--path' => $migration, '--realpath' => true, '--force' => true])->assertExitCode(0);
        }

        Schema::create('subscription_users', function (Blueprint $table): void {
            $table->id();
        });

        $this->payments = FakePaymentGateway::bind();
        $this->gateway = new SubscriptionBillingKeyGateway;
        $this->app->instance(BillingKeyGateway::class, $this->gateway);

        Event::listen([
            PaymentCompleted::class, BillingKeyDeleted::class, SubscriptionStarted::class, SubscriptionRenewed::class,
            SubscriptionRenewalFailed::class, SubscriptionExpired::class, SubscriptionCancelled::class,
            SubscriptionResumed::class, SubscriptionEnded::class,
        ], function (object $event): void {
            $this->events[] = $event;
        });

        $this->travelTo(Carbon::parse('2026-01-31 10:00:00'));
    }

    public function test_subscribe_charges_the_first_period_and_starts_an_active_subscription(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $this->chargesSucceed();

        $subscription = $user->subscribe($key->issue_id, 'Pro', 100, 'Pro monthly', '1m');

        $this->assertSame(['ACTIVE', false, 0, null], [$subscription->status, $subscription->cancel_at_period_end, $subscription->failed_attempts, $subscription->ended_at]);
        $this->assertSame([$key->id, 'Pro', 100, 'Pro monthly', '1m'], [$subscription->billing_key_id, $subscription->name, $subscription->amount, $subscription->order_name, $subscription->interval]);
        $this->assertSame('2026-01-31 10:00:00', $subscription->current_period_start->toDateTimeString());
        // A month from January 31 ends on the last day of February.
        $this->assertSame('2026-02-28 10:00:00', $subscription->current_period_end->toDateTimeString());
        $this->assertSame('2026-02-28 10:00:00', $subscription->next_charge_at->toDateTimeString());

        $payment = Payment::query()->sole();
        $this->assertSame(['PAID', $key->id, $subscription->id], [$payment->status, $payment->billing_key_id, $payment->subscription_id]);
        $this->assertSame([['payWithBillingKey', $payment->payment_id, self::KEY, 'Pro monthly', 100]], $this->gateway->charges());
        $this->assertSame([$subscription->id], $user->subscriptions()->pluck('id')->all());

        $this->assertSame([PaymentCompleted::class, SubscriptionStarted::class], array_map(get_class(...), $this->events));
        $this->assertSame(PaymentEventSource::Charge, $this->events[0]->source);
        $this->assertSame($subscription->id, $this->events[1]->subscription->id);
    }

    public function test_subscribe_creates_no_subscription_when_the_first_charge_fails(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $failure = $this->pgRefusal();
        $this->gateway->failures['payWithBillingKey'] = $failure;

        $this->assertSame($failure, $this->caught(fn () => $user->subscribe($key->issue_id, 'Pro', 100, 'Pro monthly', '1m')));

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(['PENDING', null], [Payment::query()->sole()->status, Payment::query()->sole()->subscription_id]);
        $this->assertSame([], $this->events);
    }

    public function test_subscribe_validates_its_arguments_before_any_request(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);

        foreach ([
            ['Pro', 100, 'Pro monthly', '0m'],
            ['Pro', 100, 'Pro monthly', '1w'],
            ['Pro', 100, 'Pro monthly', 'm'],
            ['Pro', 100, 'Pro monthly', '1M'],
            ['Pro', 100, 'Pro monthly', ' 1m'],
            ['Pro', 100, 'Pro monthly', ''],
            ['Pro', 0, 'Pro monthly', '1m'],
            ['Pro', -1, 'Pro monthly', '1m'],
            ['', 100, 'Pro monthly', '1m'],
            ['Pro', 100, '', '1m'],
        ] as [$name, $amount, $orderName, $interval]) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $this->caught(fn () => $user->subscribe($key->issue_id, $name, $amount, $orderName, $interval)));
        }

        $this->assertSame([], $this->gateway->charges());
        $this->assertSame([0, 0], [Payment::query()->count(), Subscription::query()->count()]);
    }

    public function test_subscribe_refuses_a_billing_key_that_is_not_issued_or_belongs_to_another_model(): void
    {
        $user = SubscriptionUser::query()->create();
        $other = SubscriptionUser::query()->create();
        $pending = $user->prepareBillingKey('Pending');
        $othersKey = $this->issuedKey($other, self::OTHER_KEY);

        foreach ([$pending->issue_id, $othersKey->issue_id, 'bk-unknown'] as $issueId) {
            $this->assertInstanceOf(InvalidBillingKeyException::class, $this->caught(fn () => $user->subscribe($issueId, 'Pro', 100, 'Pro monthly', '1d')));
        }

        $this->assertSame([], $this->gateway->charges());
        $this->assertSame([0, 0], [Payment::query()->count(), Subscription::query()->count()]);
    }

    public function test_interval_units_add_days_months_and_years(): void
    {
        $lifecycle = new SubscriptionLifecycle;
        $from = Carbon::parse('2028-02-29 09:30:00');

        $this->assertSame('2028-03-14 09:30:00', $lifecycle->addInterval($from, '14d')->toDateTimeString());
        $this->assertSame('2028-05-29 09:30:00', $lifecycle->addInterval($from, '3m')->toDateTimeString());
        $this->assertSame('2029-02-28 09:30:00', $lifecycle->addInterval($from, '1y')->toDateTimeString());
        $this->assertSame('2028-02-29 09:30:00', $from->toDateTimeString());
        $this->assertSame([1, 3, 5], $lifecycle->retryAfterDays('1,3,5'));
        $this->assertSame([2, 0], $lifecycle->retryAfterDays(' 2 , x, 0,-1'));
        $this->assertSame([], $lifecycle->retryAfterDays(''));
    }

    public function test_the_command_queues_one_charge_per_due_active_or_past_due_subscription_and_sends_no_request(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $dueActive = $this->subscription($user, $key, ['next_charge_at' => now()->subMinute()]);
        $dueNow = $this->subscription($user, $key, ['next_charge_at' => now()]);
        $duePastDue = $this->subscription($user, $key, ['status' => 'PAST_DUE', 'next_charge_at' => now()->subDay()]);
        $this->subscription($user, $key, ['next_charge_at' => now()->addSecond()]);
        $this->subscription($user, $key, ['status' => 'ENDED', 'next_charge_at' => now()->subDay()]);
        $this->subscription($user, $key, ['status' => 'EXPIRED', 'next_charge_at' => now()->subDay()]);
        Bus::fake();

        $this->artisan('portone:charge-subscriptions')->assertExitCode(0);

        $queued = [];
        Bus::assertDispatched(ChargeSubscription::class, function (ChargeSubscription $job) use (&$queued): bool {
            $queued[] = $job->subscriptionId;

            return true;
        });
        $this->assertSame([$dueActive->id, $dueNow->id, $duePastDue->id], $queued);
        $this->assertSame([], $this->gateway->requests);
        $this->payments->assertNothingLookedUp();
    }

    public function test_the_package_schedules_nothing(): void
    {
        $this->assertSame([], $this->app->make(Schedule::class)->events());
    }

    public function test_a_due_subscription_is_renewed_after_a_paid_charge(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $this->chargesSucceed();
        $subscription = $user->subscribe($key->issue_id, 'Pro', 100, 'Pro monthly', '1m');
        $this->events = [];

        $this->travelTo($subscription->next_charge_at);
        $this->artisan('portone:charge-subscriptions')->assertExitCode(0);

        $renewed = $subscription->fresh();
        $this->assertSame(['ACTIVE', 0], [$renewed->status, $renewed->failed_attempts]);
        $this->assertSame('2026-02-28 10:00:00', $renewed->current_period_start->toDateTimeString());
        $this->assertSame('2026-03-28 10:00:00', $renewed->current_period_end->toDateTimeString());
        $this->assertSame('2026-03-28 10:00:00', $renewed->next_charge_at->toDateTimeString());

        $renewal = Payment::query()->latest('id')->first();
        $this->assertSame(['PAID', $subscription->id, $key->id], [$renewal?->status, $renewal?->subscription_id, $renewal?->billing_key_id]);
        $this->assertCount(2, $this->gateway->charges());
        $this->assertSame([PaymentCompleted::class, SubscriptionRenewed::class], array_map(get_class(...), $this->events));
    }

    public function test_failed_renewals_retry_after_each_configured_wait_and_then_expire(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $this->chargesSucceed();
        $subscription = $user->subscribe($key->issue_id, 'Pro', 100, 'Pro monthly', '1m');
        $this->events = [];
        $this->failCharges();

        $expectedWaits = [1, 3, 5];
        $this->travelTo($subscription->next_charge_at);

        foreach ($expectedWaits as $index => $days) {
            $this->runCharge($subscription);

            $pastDue = $subscription->fresh();
            $this->assertSame(['PAST_DUE', $index + 1], [$pastDue->status, $pastDue->failed_attempts]);
            $this->assertSame(now()->addDays($days)->toDateTimeString(), $pastDue->next_charge_at->toDateTimeString());
            $this->assertSame('2026-02-28 10:00:00', $pastDue->current_period_end->toDateTimeString());

            $event = array_pop($this->events);
            $this->assertInstanceOf(SubscriptionRenewalFailed::class, $event);
            $this->assertSame([$index + 1, $pastDue->next_charge_at->toDateTimeString()], [$event->attempt, $event->nextChargeAt->toDateTimeString()]);
            $this->assertSame([], $this->events);

            $this->travelTo($pastDue->next_charge_at);
        }

        $this->runCharge($subscription);

        $expired = $subscription->fresh();
        $this->assertSame(['EXPIRED', 4], [$expired->status, $expired->failed_attempts]);
        $this->assertSame(now()->toDateTimeString(), $expired->ended_at?->toDateTimeString());
        $this->assertSame([SubscriptionExpired::class], array_map(get_class(...), $this->events));
        // The first charge plus one charge per attempt; every failed attempt left its record PENDING.
        $this->assertCount(5, $this->gateway->charges());
        $this->assertSame(4, Payment::query()->where('subscription_id', $subscription->id)->where('status', 'PENDING')->count());

        $this->events = [];
        $this->runCharge($subscription);
        $this->assertSame([], $this->events);
        $this->assertCount(5, $this->gateway->charges());
    }

    public function test_a_past_due_subscription_renews_from_the_previous_period_end_after_a_paid_retry(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $this->chargesSucceed();
        $subscription = $user->subscribe($key->issue_id, 'Pro', 100, 'Pro monthly', '1m');
        $this->failCharges();
        $this->travelTo($subscription->next_charge_at);
        $this->runCharge($subscription);
        $this->assertSame('PAST_DUE', $subscription->fresh()->status);

        $this->gateway->failures = [];
        $this->chargesSucceed();
        $this->events = [];
        $this->travelTo($subscription->fresh()->next_charge_at);
        $this->runCharge($subscription);

        $renewed = $subscription->fresh();
        $this->assertSame(['ACTIVE', 0], [$renewed->status, $renewed->failed_attempts]);
        $this->assertSame('2026-02-28 10:00:00', $renewed->current_period_start->toDateTimeString());
        $this->assertSame('2026-03-28 10:00:00', $renewed->next_charge_at->toDateTimeString());
        // The failed attempt is FAILED at PortOne and is looked up before charging again.
        $this->assertSame([PaymentCompleted::class, SubscriptionRenewed::class], array_map(get_class(...), $this->events));
    }

    public function test_a_charge_refused_for_a_deleted_key_counts_as_a_failed_renewal(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $this->chargesSucceed();
        $subscription = $user->subscribe($key->issue_id, 'Pro', 100, 'Pro monthly', '1m');
        $this->gateway->onCharge = null;
        $this->gateway->failures['payWithBillingKey'] = new UnknownException('payWithBillingKey', 'PortOne returned an error response.', 409, '{"type":"BILLING_KEY_ALREADY_DELETED"}', null, 'BILLING_KEY_ALREADY_DELETED');
        $this->travelTo($subscription->next_charge_at);

        $this->runCharge($subscription);
        $this->assertSame(['PAST_DUE', 1], [$subscription->fresh()->status, $subscription->fresh()->failed_attempts]);

        // On the retry PortOne does not know the attempt (no payment was created), so it is charged again.
        $this->travelTo($subscription->fresh()->next_charge_at);
        $this->runCharge($subscription);
        $this->assertSame(['PAST_DUE', 2], [$subscription->fresh()->status, $subscription->fresh()->failed_attempts]);
        $this->assertCount(3, $this->gateway->charges());
    }

    public function test_a_rerun_after_a_crash_completes_the_pending_record_instead_of_charging_again(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $this->chargesSucceed();
        $subscription = $user->subscribe($key->issue_id, 'Pro', 100, 'Pro monthly', '1m');
        $this->travelTo($subscription->next_charge_at);

        // PortOne accepts the charge, then the worker dies before any local write.
        $this->gateway->onCharge = function (string $paymentId): void {
            $this->payments->payment($paymentId, 'PAID', totalAmount: 100, cancelledAmount: 0, receiptUrl: self::RECEIPT);

            throw new \RuntimeException('worker crashed');
        };
        $this->assertSame('worker crashed', $this->caught(fn () => $this->runCharge($subscription))?->getMessage());
        $pending = Payment::query()->latest('id')->firstOrFail();
        $this->assertSame(['PENDING', $subscription->id], [$pending->status, $pending->subscription_id]);
        $this->assertSame('2026-02-28 10:00:00', $subscription->fresh()->current_period_end->toDateTimeString());

        $this->gateway->onCharge = null;
        $this->events = [];
        $this->runCharge($subscription);

        $this->assertCount(2, $this->gateway->charges());
        $this->assertSame('PAID', $pending->fresh()->status);
        $this->assertSame(['ACTIVE', '2026-03-28 10:00:00'], [$subscription->fresh()->status, $subscription->fresh()->current_period_end->toDateTimeString()]);
        $this->assertSame([PaymentCompleted::class, SubscriptionRenewed::class], array_map(get_class(...), $this->events));
    }

    public function test_a_job_that_runs_when_the_subscription_is_not_due_changes_nothing(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $notDue = $this->subscription($user, $key, ['next_charge_at' => now()->addMinute()]);
        $ended = $this->subscription($user, $key, ['status' => 'ENDED', 'next_charge_at' => now()->subDay()]);
        $expired = $this->subscription($user, $key, ['status' => 'EXPIRED', 'next_charge_at' => now()->subDay()]);

        foreach ([$notDue, $ended, $expired] as $subscription) {
            $before = $subscription->fresh()->toArray();
            $this->runCharge($subscription);
            $this->assertSame($before, $subscription->fresh()->toArray());
        }

        $this->runCharge(new Subscription(['id' => 999]));
        $this->assertSame([], $this->gateway->requests);
        $this->assertSame([], $this->events);
    }

    public function test_a_missing_api_secret_fails_the_job_without_any_change(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $subscription = $this->subscription($user, $key, ['next_charge_at' => now()->subMinute()]);
        $this->gateway->configured = false;
        $before = $subscription->fresh()->toArray();

        $this->assertInstanceOf(ConfigurationException::class, $this->caught(fn () => $this->runCharge($subscription)));

        $this->assertSame($before, $subscription->fresh()->toArray());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame([], $this->events);
    }

    public function test_a_cancelled_subscription_ends_at_the_period_end_without_a_charge(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $this->chargesSucceed();
        $subscription = $user->subscribe($key->issue_id, 'Pro', 100, 'Pro monthly', '1m');
        $this->events = [];

        $cancelled = $subscription->cancel();
        $this->assertSame(['ACTIVE', true], [$cancelled->status, $cancelled->cancel_at_period_end]);
        $this->assertSame([SubscriptionCancelled::class], array_map(get_class(...), $this->events));

        // A repeated cancel changes nothing and dispatches nothing.
        $subscription->fresh()->cancel();
        $this->assertCount(1, $this->events);

        $this->travelTo($subscription->next_charge_at);
        $this->runCharge($subscription);

        $ended = $subscription->fresh();
        $this->assertSame(['ENDED', now()->toDateTimeString()], [$ended->status, $ended->ended_at?->toDateTimeString()]);
        $this->assertCount(1, $this->gateway->charges());
        $this->assertSame([SubscriptionCancelled::class, SubscriptionEnded::class], array_map(get_class(...), $this->events));
        $this->assertInstanceOf(InvalidSubscriptionStatusException::class, $this->caught(fn () => $ended->cancel()));
        $this->assertInstanceOf(InvalidSubscriptionStatusException::class, $this->caught(fn () => $ended->resume()));
    }

    public function test_a_resumed_subscription_keeps_renewing(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $this->chargesSucceed();
        $subscription = $user->subscribe($key->issue_id, 'Pro', 100, 'Pro monthly', '1m');
        $this->assertInstanceOf(InvalidSubscriptionStatusException::class, $this->caught(fn () => $subscription->resume()));
        $subscription->cancel();
        $this->events = [];

        $resumed = $subscription->fresh()->resume();
        $this->assertSame(['ACTIVE', false], [$resumed->status, $resumed->cancel_at_period_end]);
        $this->assertSame([SubscriptionResumed::class], array_map(get_class(...), $this->events));

        $this->travelTo($subscription->next_charge_at);
        $this->runCharge($subscription);

        $this->assertSame(['ACTIVE', '2026-03-28 10:00:00'], [$subscription->fresh()->status, $subscription->fresh()->current_period_end->toDateTimeString()]);
        $this->assertCount(2, $this->gateway->charges());
    }

    public function test_cancelling_a_past_due_subscription_ends_it_at_once(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $subscription = $this->subscription($user, $key, ['status' => 'PAST_DUE', 'failed_attempts' => 1, 'next_charge_at' => now()->addDay()]);

        $ended = $subscription->cancel();

        $this->assertSame(['ENDED', true, now()->toDateTimeString()], [$ended->status, $ended->cancel_at_period_end, $ended->ended_at?->toDateTimeString()]);
        $this->assertSame([SubscriptionCancelled::class, SubscriptionEnded::class], array_map(get_class(...), $this->events));
        $this->assertInstanceOf(InvalidSubscriptionStatusException::class, $this->caught(fn () => $this->subscription($user, $key, ['status' => 'EXPIRED'])->cancel()));
    }

    public function test_change_billing_key_charges_another_issued_key_of_the_same_model_from_the_next_charge(): void
    {
        $user = SubscriptionUser::query()->create();
        $other = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $second = $this->issuedKey($user, self::OTHER_KEY);
        $othersKey = $this->issuedKey($other, 'billing-key-00000000-0000-0000-0000-000000000003');
        $pending = $user->prepareBillingKey('Pending');
        $this->chargesSucceed();
        $subscription = $user->subscribe($key->issue_id, 'Pro', 100, 'Pro monthly', '1m');

        foreach ([$othersKey->issue_id, $pending->issue_id, 'bk-unknown'] as $issueId) {
            $this->assertInstanceOf(InvalidBillingKeyException::class, $this->caught(fn () => $subscription->changeBillingKey($issueId)));
            $this->assertSame($key->id, $subscription->fresh()->billing_key_id);
        }

        $changed = $subscription->changeBillingKey($second->issue_id);
        $this->assertSame($second->id, $changed->billing_key_id);

        $this->travelTo($subscription->next_charge_at);
        $this->runCharge($subscription);
        $this->assertSame(self::OTHER_KEY, $this->gateway->charges()[1][2]);
        $this->assertSame($second->id, Payment::query()->latest('id')->firstOrFail()->billing_key_id);

        $ended = $this->subscription($user, $key, ['status' => 'ENDED']);
        $this->assertInstanceOf(InvalidSubscriptionStatusException::class, $this->caught(fn () => $ended->changeBillingKey($second->issue_id)));
        $this->assertSame([], $this->gateway->requestsOf('billingKeyInfo'));
    }

    public function test_deleting_a_billing_key_charged_by_an_active_or_past_due_subscription_is_refused(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $subscription = $this->subscription($user, $key, []);

        foreach (['ACTIVE', 'PAST_DUE'] as $status) {
            $subscription->forceFill(['status' => $status])->save();

            $this->assertInstanceOf(InvalidBillingKeyException::class, $this->caught(fn () => $user->deleteBillingKey($key->issue_id, 'Card removed')));
            $this->assertSame('ISSUED', $key->fresh()->status);
        }

        $this->assertSame([], $this->gateway->requests);
        $this->assertSame([], $this->events);

        foreach (['ENDED', 'EXPIRED'] as $status) {
            $this->subscription($user, $key, ['status' => $status]);
        }
        $subscription->forceFill(['status' => 'ENDED'])->save();

        $deleted = $user->deleteBillingKey($key->issue_id, 'Card removed');
        $this->assertSame('DELETED', $deleted->status);
        $this->assertSame([['deleteBillingKey', self::KEY, 'Card removed']], $this->gateway->requests);
        $this->assertSame([BillingKeyDeleted::class], array_map(get_class(...), $this->events));
    }

    public function test_subscription_events_are_dispatched_after_commit_and_discarded_on_rollback(): void
    {
        $user = SubscriptionUser::query()->create();
        $key = $this->issuedKey($user, self::KEY);
        $subscription = $this->subscription($user, $key, []);

        try {
            DB::transaction(function () use ($subscription): void {
                $subscription->cancel();
                $this->assertSame([], $this->events);

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->events);
        $this->assertFalse($subscription->fresh()->cancel_at_period_end);
    }

    private function issuedKey(SubscriptionUser $user, string $billingKey): BillingKey
    {
        $record = $user->prepareBillingKey('Monthly plan');
        $this->gateway->info($billingKey, 'ISSUED', $record->customer_id, $record->issue_id);
        $issued = $user->completeBillingKey($record->issue_id, $billingKey);
        $this->gateway->requests = [];
        $this->events = [];

        return $issued;
    }

    private function chargesSucceed(): void
    {
        $this->gateway->onCharge = function (string $paymentId): void {
            $this->payments->payment($paymentId, 'PAID', totalAmount: 100, cancelledAmount: 0, receiptUrl: self::RECEIPT);
        };
    }

    private function failCharges(): void
    {
        $this->gateway->onCharge = null;
        $this->gateway->failures['payWithBillingKey'] = $this->pgRefusal();
        // After a PG refusal PortOne reports the payment FAILED with amount.total equal to the charged amount.
        $this->gateway->beforeFailure = function (string $paymentId): void {
            $this->payments->payment($paymentId, 'FAILED', totalAmount: 100, cancelledAmount: 0);
        };
    }

    private function pgRefusal(): UnknownException
    {
        return new UnknownException('payWithBillingKey', 'PortOne returned an error response.', 502, '{"type":"PG_PROVIDER","pgCode":"8105","pgMessage":"포맷에러(지불|신용카드|금액)"}', null, 'PG_PROVIDER');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function subscription(SubscriptionUser $user, BillingKey $key, array $values): Subscription
    {
        return Subscription::query()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => (string) $user->getKey(),
            'billing_key_id' => $key->id,
            'name' => 'Pro',
            'amount' => 100,
            'order_name' => 'Pro monthly',
            'interval' => '1m',
            'status' => 'ACTIVE',
            'cancel_at_period_end' => false,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now(),
            'next_charge_at' => now(),
            'failed_attempts' => 0,
            ...$values,
        ]);
    }

    private function runCharge(Subscription $subscription): void
    {
        (new ChargeSubscription($subscription->id))->handle($this->app->make(SubscriptionOperations::class));
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
}

class SubscriptionUser extends Model
{
    use Billable;

    protected $table = 'subscription_users';

    public $timestamps = false;

    protected $guarded = [];
}

final class SubscriptionBillingKeyGateway implements BillingKeyGateway
{
    /** @var list<list<int|string>> */
    public array $requests = [];

    /** @var array<string, Throwable> failures per operation */
    public array $failures = [];

    public bool $configured = true;

    /** @var (\Closure(string): void)|null runs after a successful charge, with the Payment ID */
    public ?\Closure $onCharge = null;

    /** @var (\Closure(string): void)|null runs before a set charge failure is thrown, with the Payment ID */
    public ?\Closure $beforeFailure = null;

    /** @var array<string, GatewayBillingKey> */
    private array $infos = [];

    public function info(string $billingKey, string $status, ?string $customerId, ?string $issueId): void
    {
        $this->infos[$billingKey] = new GatewayBillingKey($status, $customerId, $issueId);
    }

    /**
     * @return list<list<int|string>>
     */
    public function charges(): array
    {
        return $this->requestsOf('payWithBillingKey');
    }

    /**
     * @return list<list<int|string>>
     */
    public function requestsOf(string $operation): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => $request[0] === $operation));
    }

    public function assertConfigured(): void
    {
        if (! $this->configured) {
            throw new ConfigurationException('The PortOne API secret is not configured.');
        }
    }

    public function billingKeyInfo(#[\SensitiveParameter] string $billingKey): GatewayBillingKey
    {
        $this->requests[] = ['billingKeyInfo', $billingKey];

        return $this->infos[$billingKey] ?? throw new \LogicException('The test set no billing-key info for this key.');
    }

    public function deleteBillingKey(#[\SensitiveParameter] string $billingKey, string $reason): void
    {
        $this->requests[] = ['deleteBillingKey', $billingKey, $reason];
    }

    public function payWithBillingKey(string $paymentId, #[\SensitiveParameter] string $billingKey, string $orderName, int $amount): void
    {
        $this->requests[] = ['payWithBillingKey', $paymentId, $billingKey, $orderName, $amount];

        if (isset($this->failures['payWithBillingKey'])) {
            if ($this->beforeFailure !== null) {
                ($this->beforeFailure)($paymentId);
            }

            throw $this->failures['payWithBillingKey'];
        }

        if ($this->onCharge !== null) {
            ($this->onCharge)($paymentId);
        }
    }
}
