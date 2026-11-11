<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\BillingKeyGateway;
use Cable8mm\LaravelPortone\BillingKeyRegistry;
use Cable8mm\LaravelPortone\Events\PaymentCompleted;
use Cable8mm\LaravelPortone\Events\SubscriptionCancelled;
use Cable8mm\LaravelPortone\Events\SubscriptionEnded;
use Cable8mm\LaravelPortone\Events\SubscriptionEvent;
use Cable8mm\LaravelPortone\Events\SubscriptionRenewalFailed;
use Cable8mm\LaravelPortone\Events\SubscriptionRenewed;
use Cable8mm\LaravelPortone\Events\SubscriptionStarted;
use Cable8mm\LaravelPortone\Exceptions\InvalidBillingKeyException;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Illuminate\Support\Facades\Event;
use Laravel\Dusk\Browser;
use Workbench\Database\Factories\UserFactory;

/**
 * T-047: a live subscription through the Package (FLOW-007 steps 3 to 6).
 *
 * The human registers a test card once. The test then starts a subscription (EL-079), checks that EL-075 refuses the
 * subscribed key without a request (AC-102), renews it once through EL-084 and EL-085 at a forced due time (the test
 * clock moved to next_charge_at), deletes the key at PortOne with the Package's PortOne Gateway request but without the
 * record write, and lets the next renewal be refused so that the subscription becomes PAST_DUE (FR-139). The
 * subscription is then ended with EL-081 (FR-141).
 */
class PortOneSubscriptionTest extends TestCase
{
    private const OBSERVATION = 'portone-subscription-observation.json';

    private const AMOUNT = 100;

    /** @var list<array{event: string, status?: string, source?: string, attempt?: int, nextChargeAt?: string}> */
    private array $events = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // EL-057 billing_channel_key is added to the configuration file by T-048. Until then the live test uses
        // the KCP test channel that issued billing keys in T-045 (PORTONE_E2E_CHANNEL_KEY).
        $app['config']->set('portone.billing_channel_key', self::env('PORTONE_BILLING_CHANNEL_KEY') ?? self::env('PORTONE_E2E_CHANNEL_KEY'));
        // EL-084 queues EL-085; the test process runs it at once, with the default EL-058 waits.
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('portone.subscription_retry_after', '1,3,5');
    }

    public function test_live_subscription_is_started_renewed_and_set_past_due_by_a_refused_billing_key(): void
    {
        self::assertNotEmpty(config('portone.billing_channel_key'), 'PORTONE_E2E_CHANNEL_KEY (the KCP billing-key test channel) must be set in the local .env file.');

        Event::listen([PaymentCompleted::class, SubscriptionStarted::class, SubscriptionRenewed::class, SubscriptionRenewalFailed::class, SubscriptionCancelled::class, SubscriptionEnded::class], function (object $event): void {
            $this->events[] = array_filter([
                'event' => class_basename($event),
                'status' => $event instanceof PaymentCompleted ? $event->payment->status : ($event instanceof SubscriptionEvent ? $event->subscription->status : null),
                'source' => $event instanceof PaymentCompleted ? $event->source->value : null,
                'attempt' => $event instanceof SubscriptionRenewalFailed ? $event->attempt : null,
                'nextChargeAt' => $event instanceof SubscriptionRenewalFailed ? $event->nextChargeAt->toIso8601String() : null,
            ], static fn (mixed $value): bool => $value !== null);
        });

        $user = UserFactory::new()->create();
        $record = $user->prepareBillingKey('PortOne subscription probe');

        // Issuance: the human registers a card; the completed page calls completeBillingKey() in the served app.
        $this->browse(function (Browser $browser) use ($user, $record): void {
            $browser->loginAs($user)
                ->visit(route('workbench.billing-key', ['issueId' => $record->issue_id], false))
                ->waitFor('button#workbench-billing-key-button:enabled')
                ->click('#workbench-billing-key-button');

            $this->instruct("Register a test card in the KCP window (billing-key issuance {$record->issue_id}).");
            $browser->waitFor('[data-workbench-billing-key-completed], [data-workbench-billing-key][data-result="error"]', self::PAYER_TIMEOUT);
            self::assertSame('issued', $browser->attribute('[data-workbench-billing-key-completed]', 'data-result'), 'The billing-key issuance did not complete.');
        });

        $issued = $record->fresh();
        self::assertSame('ISSUED', $issued->status);
        $plainKey = $this->app->make(BillingKeyRegistry::class)->plainKey($issued);
        self::assertIsString($plainKey);
        $lookups = $this->app->make(PaymentGateway::class);

        // EL-079: the first period is charged and the subscription starts.
        $subscription = $user->subscribe($issued->issue_id, 'PortOne subscription probe', self::AMOUNT, 'PortOne subscription probe', '1m');
        self::assertSame(['ACTIVE', 0, false], [$subscription->status, $subscription->failed_attempts, $subscription->cancel_at_period_end]);
        $first = Payment::query()->where('subscription_id', $subscription->id)->sole();
        self::assertSame(['PAID', self::AMOUNT], [$first->status, $first->paid_amount]);
        $firstLookup = $lookups->lookup($first->payment_id);
        self::assertSame(['PAID', self::AMOUNT], [$firstLookup->status, $firstLookup->totalAmount]);
        $started = $subscription->toArray();

        // EL-075 refuses the key while the subscription charges it; the key stays issued at PortOne.
        try {
            $user->deleteBillingKey($issued->issue_id, 'PortOne subscription probe');
            self::fail('Deleting a key charged by an ACTIVE subscription must be refused.');
        } catch (InvalidBillingKeyException) {
        }
        self::assertSame('ISSUED', $issued->fresh()->status);
        $keyAfterRefusal = $this->app->make(BillingKeyGateway::class)->billingKeyInfo($plainKey);
        self::assertSame('ISSUED', $keyAfterRefusal->status);

        // EL-084 and EL-085 at the forced due time: the second period is charged.
        $this->travelTo($subscription->next_charge_at);
        $this->artisan('portone:charge-subscriptions')->assertExitCode(0);
        $renewed = $subscription->fresh();
        self::assertSame(['ACTIVE', 0], [$renewed->status, $renewed->failed_attempts]);
        self::assertTrue($renewed->current_period_start->equalTo($subscription->current_period_end));
        self::assertTrue($renewed->current_period_end->equalTo($subscription->current_period_end->copy()->addMonthsNoOverflow(1)));
        $renewal = Payment::query()->where('subscription_id', $subscription->id)->whereKeyNot($first->id)->sole();
        self::assertSame(['PAID', self::AMOUNT, $issued->id], [$renewal->status, $renewal->paid_amount, $renewal->billing_key_id]);
        $renewalLookup = $lookups->lookup($renewal->payment_id);
        self::assertSame(['PAID', self::AMOUNT], [$renewalLookup->status, $renewalLookup->totalAmount]);

        // The key is deleted at PortOne without the record write, so the next renewal is refused.
        $this->app->make(BillingKeyGateway::class)->deleteBillingKey($plainKey, 'PortOne subscription probe refusal');
        $this->travelTo($renewed->next_charge_at);
        $this->artisan('portone:charge-subscriptions')->assertExitCode(0);

        $pastDue = $subscription->fresh();
        self::assertSame(['PAST_DUE', 1], [$pastDue->status, $pastDue->failed_attempts]);
        self::assertSame(now()->addDay()->toDateTimeString(), $pastDue->next_charge_at->toDateTimeString());
        self::assertTrue($pastDue->current_period_end->equalTo($renewed->current_period_end));
        $attempt = Payment::query()->where('subscription_id', $subscription->id)->whereKeyNot([$first->id, $renewal->id])->first();
        $attemptLookup = $attempt === null ? null : $lookups->lookupForExpiry($attempt->payment_id);
        if ($attempt !== null) {
            self::assertSame('PENDING', $attempt->status);
        }

        // EL-081 on the PAST_DUE subscription ends it at once.
        $ended = $pastDue->cancel();
        self::assertSame(['ENDED', true], [$ended->status, $ended->cancel_at_period_end]);

        // A Transaction.Paid webhook may complete a charge in the served app first, so only the subscription events are exact.
        self::assertSame(
            ['SubscriptionStarted', 'SubscriptionRenewed', 'SubscriptionRenewalFailed', 'SubscriptionCancelled', 'SubscriptionEnded'],
            array_values(array_filter(array_column($this->events, 'event'), static fn (string $event): bool => str_starts_with($event, 'Subscription'))),
        );
        self::assertStringNotContainsString($plainKey, (string) json_encode([$this->events, $ended->toArray()]));

        $observation = [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
            'channel' => 'NHN KCP test (PORTONE_E2E_CHANNEL_KEY)',
            'interval' => '1m',
            'amount' => self::AMOUNT,
            'retryAfter' => '1,3,5',
            'start' => [
                'subscription' => array_intersect_key($started, array_flip(['status', 'cancel_at_period_end', 'failed_attempts', 'current_period_start', 'current_period_end', 'next_charge_at'])),
                'firstPayment' => ['recordStatus' => $first->status, 'portOneStatus' => $firstLookup->status, 'portOneTotal' => $firstLookup->totalAmount],
            ],
            'deleteWhileActive' => [
                'result' => InvalidBillingKeyException::class,
                'recordStatus' => $issued->fresh()->status,
                'portOneKeyStatus' => $keyAfterRefusal->status,
            ],
            'renewal' => [
                'forcedDueTime' => $subscription->next_charge_at->toIso8601String(),
                'subscription' => array_intersect_key($renewed->toArray(), array_flip(['status', 'failed_attempts', 'current_period_start', 'current_period_end', 'next_charge_at'])),
                'payment' => ['recordStatus' => $renewal->status, 'portOneStatus' => $renewalLookup->status, 'portOneTotal' => $renewalLookup->totalAmount],
            ],
            'refusedRenewal' => [
                'by' => 'BillingKeyGateway::deleteBillingKey() (the EL-075 request) without the record write, then EL-084 at the next due time',
                'billingKeyRecordStatusAtCharge' => $attempt === null ? 'DELETED (the BillingKey.Deleted webhook arrived first; EL-076 refused without a request)' : 'ISSUED',
                'attemptPayment' => $attempt === null ? null : [
                    'recordStatus' => $attempt->status,
                    'portOneLookup' => $attemptLookup?->found === false ? 'PAYMENT_NOT_FOUND' : $attemptLookup?->status,
                ],
                'subscription' => array_intersect_key($pastDue->toArray(), array_flip(['status', 'failed_attempts', 'current_period_end', 'next_charge_at'])),
            ],
            'cancelPastDue' => array_intersect_key($ended->toArray(), array_flip(['status', 'cancel_at_period_end', 'ended_at'])),
            'events' => $this->events,
            'secretsRecorded' => false,
        ];
        self::assertStringNotContainsString($plainKey, (string) json_encode($observation));
        $this->recordObservation(self::OBSERVATION, $observation);
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
