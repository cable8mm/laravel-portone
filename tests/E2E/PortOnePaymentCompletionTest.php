<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

class PortOnePaymentCompletionTest extends TestCase
{
    private const OBSERVATION = 'portone-payment-completion-observation.json';

    public function test_payment_prepared_by_the_package_is_completed_against_live_portone(): void
    {
        $user = UserFactory::new()->create();
        $payment = $user->preparePayment(100, 'PortOne payment completion probe');

        $this->browse(function (Browser $browser) use ($user, $payment): void {
            $this->payInBrowser($browser, $user, $payment, 'Complete the 100 KRW PortOne test payment in the browser window.');
        });

        $observation = [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
            'publishedStubs' => true,
            'defaultApiBaseUrl' => 'https://api.portone.io',
            'packagePreparedPayment' => [
                'paymentId' => $payment->payment_id,
                'totalAmount' => $payment->amount,
                'browserResult' => 'returned without an error code',
            ],
            'secretsRecorded' => false,
        ];

        $this->assertSame('PENDING', $payment->fresh()->status);

        // The amount mismatch case cancels the payment at PortOne, so it lives in PortOneAmountMismatchTest (T-025).
        $realApiSecret = config('portone.api_secret');
        self::assertIsString($realApiSecret);
        $invalidApiSecret = 'invalid-live-probe-credential';
        config([
            'portone.api_secret' => $invalidApiSecret,
            'portone.base_url' => 'https://api.portone.io/',
        ]);

        try {
            $user->completePayment($payment->payment_id);
            $this->fail('Expected PortOne to reject an invalid API secret.');
        } catch (PortOneException $exception) {
            $this->assertSame('Cable8mm\\PortonePhp\\Exception\\UnauthorizedException', $exception::class);
            $this->assertSame(401, $exception->status);
            $this->assertSame('UNAUTHORIZED', $exception->errorType);
            $this->assertStringNotContainsString($invalidApiSecret, $exception->getMessage());
            $this->assertSame('PENDING', $payment->fresh()->status);
            $observation['invalidApiSecret'] = [
                'exception' => $exception::class,
                'httpStatus' => $exception->status,
                'errorType' => $exception->errorType,
                'credentialInMessage' => str_contains($exception->getMessage(), $invalidApiSecret),
            ];
        }

        config([
            'portone.api_secret' => $realApiSecret,
            'portone.base_url' => null,
        ]);
        $completed = $user->completePayment($payment->payment_id);
        self::assertSame('PAID', $completed->status);
        self::assertNotNull($completed->paid_at);
        self::assertSame(0, $completed->cancelled_amount);

        $unchanged = $user->completePayment($payment->payment_id);
        self::assertSame('PAID', $unchanged->status);
        self::assertSame($completed->paid_at->toISOString(), $unchanged->paid_at->toISOString());
        $observation['completion'] = [
            'status' => $completed->status,
            'paidAtRecorded' => true,
            'secondCall' => 'returned unchanged',
        ];

        $unknownId = 'probe-unknown-'.Str::uuid()->toString();
        $unknownPayment = $this->pendingPayment($user, $unknownId, 'Unknown PortOne payment');

        try {
            $user->completePayment($unknownId);
            $this->fail('Expected PortOne to reject an unknown Payment ID.');
        } catch (PortOneException $exception) {
            $this->assertSame('Cable8mm\\PortonePhp\\Exception\\UnknownException', $exception::class);
            $this->assertSame(404, $exception->status);
            $this->assertSame('PAYMENT_NOT_FOUND', $exception->errorType);
            $this->assertSame('PENDING', $unknownPayment->fresh()->status);
            $observation['unknownPortOnePayment'] = [
                'exception' => $exception::class,
                'httpStatus' => $exception->status,
                'errorType' => $exception->errorType,
                'recordStatus' => $unknownPayment->fresh()->status,
            ];
        }

        $this->recordObservation(self::OBSERVATION, $observation);
    }

    public function test_recorded_unpaid_portone_payment_is_rejected_through_the_package(): void
    {
        $lookupObservation = $this->probeObservation('portone-payment-lookup-observation.json');
        $unpaidPaymentId = $lookupObservation['unpaidPayment']['paymentId'] ?? null;
        self::assertIsString($unpaidPaymentId);

        $user = UserFactory::new()->create();
        $payment = $this->pendingPayment($user, $unpaidPaymentId, 'Recorded unpaid payment');

        config(['portone.base_url' => 'https://api.portone.io/']);
        try {
            $user->completePayment($payment->payment_id);
            $this->fail('Expected the recorded unpaid PortOne payment to be rejected through the configured base URL.');
        } catch (PaymentNotPaidException) {
            self::assertSame('PENDING', $payment->fresh()->status);
            self::assertNull($payment->fresh()->paid_at);
        }

        $this->recordObservation(self::OBSERVATION, [
            'unpaidPayment' => [
                'observedStatus' => $lookupObservation['unpaidPayment']['observedStatus'] ?? null,
                'result' => PaymentNotPaidException::class,
                'recordStatus' => $payment->fresh()->status,
                'configuredApiBaseUrl' => config('portone.base_url'),
            ],
        ]);
    }

    private function pendingPayment(User $user, string $paymentId, string $orderName): Payment
    {
        return Payment::query()->create([
            'payment_id' => $paymentId,
            'billable_type' => $user->getMorphClass(),
            'billable_id' => (string) $user->getKey(),
            'customer_id' => 'live-completion-probe',
            'order_name' => $orderName,
            'amount' => 100,
            'status' => 'PENDING',
            'cancelled_amount' => 0,
            'paid_at' => null,
        ]);
    }
}
