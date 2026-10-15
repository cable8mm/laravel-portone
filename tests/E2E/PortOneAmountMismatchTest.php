<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\GatewayPayment;
use Cable8mm\LaravelPortone\Jobs\ExpirePendingPayment;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentGateway;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneGateway;
use Laravel\Dusk\Browser;
use Workbench\Database\Factories\UserFactory;

/**
 * T-025: a payment paid at PortOne with a total different from the prepared amount is cancelled automatically.
 *
 * The Payer pays 100 KRW; the test then raises the record's amount to 1000, which is what PortOne and the
 * Package see when a buyer tampers the totalAmount passed to requestPayment.
 */
class PortOneAmountMismatchTest extends TestCase
{
    private const OBSERVATION = 'portone-amount-mismatch-observation.json';

    public function test_completion_cancels_a_payment_paid_with_a_different_amount(): void
    {
        $user = UserFactory::new()->create();
        $payment = $user->preparePayment(100, 'PortOne amount mismatch probe');

        $this->browse(function (Browser $browser) use ($user, $payment): void {
            $this->payInBrowser($browser, $user, $payment, 'Complete the 100 KRW PortOne test payment in the browser window.');
        });

        $payment->forceFill(['amount' => 1000])->save();
        $gateway = $this->countingGateway();

        try {
            $user->completePayment($payment->payment_id);
            $this->fail('Expected the mismatched payment to be rejected.');
        } catch (AmountMismatchException $exception) {
            self::assertSame(100, $exception->paidAmount());
            self::assertTrue($exception->wasCancelled());
        }

        $stored = $payment->fresh();
        self::assertSame('AMOUNT_MISMATCH_CANCELLED', $stored->status);
        self::assertSame(1000, $stored->amount);
        self::assertSame(100, $stored->paid_amount);
        self::assertSame(100, $stored->cancelled_amount);
        self::assertNull($stored->paid_at);
        self::assertSame(['lookup' => 1, 'cancel' => 1], $gateway->requests);

        try {
            $user->completePayment($payment->payment_id);
            $this->fail('Expected the repeated completion to be rejected the same way.');
        } catch (AmountMismatchException $exception) {
            self::assertSame(100, $exception->paidAmount());
            self::assertTrue($exception->wasCancelled());
        }
        self::assertSame(['lookup' => 1, 'cancel' => 1], $gateway->requests);

        $portOne = $this->app->make(PortOneGateway::class)->lookup($payment->payment_id);
        self::assertSame('CANCELLED', $portOne->status);

        $this->recordObservation(self::OBSERVATION, [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
            'completion' => [
                'paymentId' => $payment->payment_id,
                'paidAmount' => 100,
                'preparedAmount' => 1000,
                'recordStatus' => $stored->status,
                'portOneStatusAfterCancel' => $portOne->status,
                'repeatedCompletion' => 'same exception, no PortOne request',
            ],
            'secretsRecorded' => false,
        ]);
    }

    public function test_expiry_cancels_a_stale_payment_paid_with_a_different_amount(): void
    {
        $user = UserFactory::new()->create();
        $payment = $user->preparePayment(100, 'PortOne amount mismatch expiry probe');

        $this->browse(function (Browser $browser) use ($user, $payment): void {
            $this->payInBrowser($browser, $user, $payment, 'Complete the 100 KRW PortOne test payment in the browser window.');
        });

        Payment::query()->whereKey($payment->getKey())->update(['amount' => 1000, 'created_at' => now()->subDays(2)]);

        (new ExpirePendingPayment($user->getMorphClass(), (string) $user->getKey(), $payment->payment_id))
            ->handle($this->app->make(PaymentOperations::class));

        $stored = $payment->fresh();
        self::assertSame('AMOUNT_MISMATCH_CANCELLED', $stored->status);
        self::assertSame(100, $stored->paid_amount);
        self::assertSame(100, $stored->cancelled_amount);

        $portOne = $this->app->make(PortOneGateway::class)->lookup($payment->payment_id);
        self::assertSame('CANCELLED', $portOne->status);

        $this->recordObservation(self::OBSERVATION, [
            'expiry' => [
                'paymentId' => $payment->payment_id,
                'paidAmount' => 100,
                'preparedAmount' => 1000,
                'recordStatus' => $stored->status,
                'portOneStatusAfterCancel' => $portOne->status,
            ],
        ]);
    }

    /**
     * Wrap the live gateway so the test can count the PortOne requests the Package sends.
     */
    private function countingGateway(): object
    {
        $gateway = new class($this->app->make(PortOneGateway::class)) implements PaymentGateway
        {
            /** @var array{lookup: int, cancel: int} */
            public array $requests = ['lookup' => 0, 'cancel' => 0];

            public function __construct(private readonly PortOneGateway $live) {}

            public function lookup(string $paymentId): GatewayPayment
            {
                $this->requests['lookup']++;

                return $this->live->lookup($paymentId);
            }

            public function lookupForExpiry(string $paymentId): GatewayPayment
            {
                return $this->live->lookupForExpiry($paymentId);
            }

            public function cancel(string $paymentId, string $reason, ?int $amount): void
            {
                $this->requests['cancel']++;
                $this->live->cancel($paymentId, $reason, $amount);
            }
        };
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }
}
