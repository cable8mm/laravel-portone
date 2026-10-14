<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Laravel\Dusk\Browser;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

class PortOnePaymentRefundTest extends TestCase
{
    private const OBSERVATION = 'portone-payment-refund-observation.json';

    public function test_live_prepared_payments_are_completed_and_refunded_via_the_package(): void
    {
        $user = UserFactory::new()->create();

        $payments = [
            'full' => $user->preparePayment(100, 'PortOne full refund probe'),
            'partial' => $user->preparePayment(10000, 'PortOne partial refund probe'),
            'remaining' => $user->preparePayment(10000, 'PortOne remaining refund probe'),
        ];

        $this->browse(function (Browser $browser) use ($user, $payments): void {
            foreach ($payments as $key => $payment) {
                $this->payInBrowser(
                    $browser,
                    $user,
                    $payment,
                    'Complete the '.number_format($payment->amount)." KRW PortOne test payment ({$key}) in the browser window.",
                );
                $this->assertSame('PENDING', $payment->fresh()->status);
            }
        });

        $observation = [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
            'publishedStubs' => true,
            'defaultApiBaseUrl' => 'https://api.portone.io',
            'livePreparedPayments' => [],
            'secretsRecorded' => false,
        ];

        foreach ($payments as $key => $payment) {
            $completed = $user->completePayment($payment->payment_id);
            self::assertSame('PAID', $completed->status);
            self::assertNotNull($completed->paid_at);
            $observation['livePreparedPayments'][$key] = [
                'paymentId' => $payment->payment_id,
                'totalAmount' => $payment->amount,
                'origin' => 'package-prepared payment',
                'browserResult' => 'returned without an error code',
                'completionStatus' => $completed->status,
                'paidAtRecorded' => true,
            ];
        }

        config(['portone.base_url' => 'https://api.portone.io/']);
        $full = $user->refund($payments['full']->payment_id, 'PortOne refund verification');
        self::assertSame('CANCELLED', $full->status);
        self::assertSame($payments['full']->amount, $full->cancelled_amount);
        $observation['fullRefund'] = [
            'paymentId' => $full->payment_id,
            'amountOmitted' => true,
            'status' => $full->status,
            'cancelledAmount' => $full->cancelled_amount,
            'configuredApiBaseUrl' => config('portone.base_url'),
        ];

        config(['portone.base_url' => null]);
        $partial = $user->refund($payments['partial']->payment_id, 'PortOne refund verification', 3000);
        self::assertSame('PARTIALLY_CANCELLED', $partial->status);
        self::assertSame(3000, $partial->cancelled_amount);
        $partialFinal = $user->refund($payments['partial']->payment_id, 'PortOne refund verification', 7000);
        self::assertSame('CANCELLED', $partialFinal->status);
        self::assertSame(10000, $partialFinal->cancelled_amount);
        $observation['partialRefund'] = [
            'paymentId' => $partial->payment_id,
            'partialAmount' => 3000,
            'partialStatus' => $partial->status,
            'partialCancelledAmount' => 3000,
            'remainingAmount' => 7000,
            'finalStatus' => $partialFinal->status,
            'finalCancelledAmount' => $partialFinal->cancelled_amount,
        ];

        $remainingPartial = $user->refund($payments['remaining']->payment_id, 'PortOne refund verification', 3000);
        self::assertSame('PARTIALLY_CANCELLED', $remainingPartial->status);
        $remainingFinal = $user->refund($payments['remaining']->payment_id, 'PortOne refund verification');
        self::assertSame('CANCELLED', $remainingFinal->status);
        self::assertSame(10000, $remainingFinal->cancelled_amount);
        $observation['remainingRefund'] = [
            'paymentId' => $remainingFinal->payment_id,
            'initialPartialAmount' => 3000,
            'fullRemainingAmountOmitted' => true,
            'finalStatus' => $remainingFinal->status,
            'finalCancelledAmount' => $remainingFinal->cancelled_amount,
        ];

        $this->recordObservation(self::OBSERVATION, $observation);
    }

    public function test_portone_rejected_cancellation_exception_passes_through_unchanged(): void
    {
        $observation = $this->readObservation(__DIR__.'/portone-payment-cancellation-observation.json');
        $cancelledPaymentId = $observation['payments']['fullCancellation']['paymentId'] ?? null;
        self::assertIsString($cancelledPaymentId);

        $user = UserFactory::new()->create();
        $payment = $this->localPayment($user, $cancelledPaymentId, 'Recorded cancelled payment', 'PAID');

        try {
            $user->refund($payment->payment_id, 'PortOne refund verification');
            $this->fail('Expected PortOne to reject an already-cancelled payment.');
        } catch (PortOneException $exception) {
            self::assertSame($observation['alreadyCancelled']['exception'], $exception::class);
            self::assertSame($observation['alreadyCancelled']['httpStatus'], $exception->status);
            self::assertSame($observation['alreadyCancelled']['errorType'], $exception->errorType);
            self::assertSame('PAID', $payment->fresh()->status);
            self::assertSame(0, $payment->fresh()->cancelled_amount);
        }

        $this->recordObservation(self::OBSERVATION, [
            'alreadyCancelled' => [
                'result' => 'PortOne error propagated without wrapping',
                'exception' => $exception::class,
                'httpStatus' => $exception->status,
                'errorType' => $exception->errorType,
                'localStatus' => $payment->fresh()->status,
                'localCancelledAmount' => $payment->fresh()->cancelled_amount,
            ],
        ]);
    }

    /**
     * A local record for a 100 KRW payment that already exists at PortOne.
     */
    private function localPayment(User $user, string $paymentId, string $orderName, string $status): Payment
    {
        return Payment::query()->create([
            'payment_id' => $paymentId,
            'billable_type' => $user->getMorphClass(),
            'billable_id' => (string) $user->getKey(),
            'customer_id' => 'live-refund-probe',
            'order_name' => $orderName,
            'amount' => 100,
            'status' => $status,
            'cancelled_amount' => 0,
            'paid_at' => $status === 'PAID' ? now() : null,
        ]);
    }
}
