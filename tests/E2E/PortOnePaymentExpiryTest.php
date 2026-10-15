<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Events\PaymentExpired;
use Cable8mm\LaravelPortone\Events\PaymentExpiryEvent;
use Cable8mm\LaravelPortone\Events\PaymentPaidOnExpiry;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Illuminate\Support\Facades\Event;
use Laravel\Dusk\Browser;
use Workbench\Database\Factories\UserFactory;

class PortOnePaymentExpiryTest extends TestCase
{
    public function test_stale_pending_payments_are_checked_against_live_portone(): void
    {
        $user = UserFactory::new()->create();
        $payments = [
            'paid' => $user->preparePayment(100, 'PortOne expiry probe (paid)'),
            'closed' => $user->preparePayment(100, 'PortOne expiry probe (closed)'),
            'neverOpened' => $user->preparePayment(100, 'PortOne expiry probe (never opened)'),
        ];

        $this->browse(function (Browser $browser) use ($user, $payments): void {
            $this->payInBrowser($browser, $user, $payments['paid'], 'Complete the 100 KRW PortOne test payment (paid) in the browser window.');
            $this->closeInBrowser($browser, $user, $payments['closed'], 'Close the PortOne payment window (closed) in the browser WITHOUT completing the payment.');
        });

        foreach ($payments as $payment) {
            self::assertSame('PENDING', $payment->fresh()->status);
            Payment::query()->whereKey($payment->getKey())->update(['created_at' => now()->subMinutes(1441)]);
        }

        $lookups = [];
        foreach ($payments as $key => $payment) {
            $result = $this->app->make(PaymentGateway::class)->lookupForExpiry($payment->payment_id);
            $lookups[$key] = ['status' => $result->status, 'found' => $result->found];
        }

        // T-021 recorded these results for a paid, a closed, and a never-opened payment.
        $recorded = $this->probeObservation('portone-abandoned-payment-observation.json');
        self::assertSame($recorded['retryAfterFailed']['lookup']['status'], $lookups['paid']['status']);
        self::assertSame($recorded['windowClosed']['lookup']['status'], $lookups['closed']['status']);
        self::assertSame($recorded['neverOpened']['lookup']['errorType'], $lookups['neverOpened']['status']);
        self::assertFalse($lookups['neverOpened']['found']);

        // Run the jobs the command queues right away so they reach live PortOne in this test.
        config(['queue.default' => 'sync']);
        $events = [];
        Event::listen([PaymentExpired::class, PaymentPaidOnExpiry::class], static function (PaymentExpiryEvent $event) use (&$events): void {
            $events[$event->paymentId] = ['event' => class_basename($event), 'status' => $event->payment->status];
        });
        $this->artisan('portone:expire-pending')->assertExitCode(0);

        $final = array_map(static fn (Payment $payment): Payment => $payment->fresh(), $payments);
        self::assertSame('PAID', $final['paid']->status);
        self::assertNotNull($final['paid']->paid_at);
        self::assertSame('EXPIRED', $final['closed']->status);
        self::assertSame('EXPIRED', $final['neverOpened']->status);

        // T-035: one event per written outcome, carrying the updated record.
        self::assertSame(['event' => 'PaymentPaidOnExpiry', 'status' => 'PAID'], $events[$final['paid']->payment_id] ?? null);
        self::assertSame(['event' => 'PaymentExpired', 'status' => 'EXPIRED'], $events[$final['closed']->payment_id] ?? null);
        self::assertSame(['event' => 'PaymentExpired', 'status' => 'EXPIRED'], $events[$final['neverOpened']->payment_id] ?? null);
        self::assertCount(3, $events);

        $observed = [];
        foreach ($final as $key => $payment) {
            $observed[$key] = [
                'paymentId' => $payment->payment_id,
                'expiryLookup' => $lookups[$key],
                'finalStatus' => $payment->status,
                'event' => $events[$payment->payment_id]['event'] ?? null,
            ];
        }

        $this->recordObservation('portone-payment-expiry-observation.json', [
            'recordedAt' => now()->toAtomString(),
            'payments' => $observed,
        ]);
    }
}
