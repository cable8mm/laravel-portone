<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Models\Payment;
use Laravel\Dusk\Browser;
use Workbench\Database\Factories\UserFactory;

class PortOneCheckoutCompletionTest extends TestCase
{
    public function test_published_checkout_prepare_and_complete_routes_verify_a_live_payment(): void
    {
        $user = UserFactory::new()->create();
        $this->actingAs($user);

        $prepare = $this->post(route('portone.checkout.prepare'));
        $prepare->assertOk();
        $checkoutParameters = $prepare->json();
        self::assertIsArray($checkoutParameters);
        self::assertSame(10000, $checkoutParameters['totalAmount'] ?? null);

        $payment = $user->findPayment((string) ($checkoutParameters['paymentId'] ?? ''));
        self::assertNotNull($payment);
        self::assertSame('PENDING', $payment->status);

        $this->browse(function (Browser $browser) use ($user, $payment): void {
            $this->payInBrowser(
                $browser,
                $user,
                $payment,
                'Complete the 10,000 KRW PortOne test payment in the browser window. This live test leaves the payment paid.',
            );
        });
        self::assertSame('PENDING', $payment->fresh()->status);

        $payment->forceFill(['amount' => 10001])->save();
        $mismatchResponse = $this->post(route('portone.checkout.complete'), [
            'paymentId' => $payment->payment_id,
            'amount' => 10000,
        ]);
        $mismatchResponse->assertStatus(422)->assertJsonStructure(['error']);
        self::assertSame('PENDING', $payment->fresh()->status);
        $payment->forceFill(['amount' => 10000])->save();

        $unpaidPaymentId = $this->probeObservation('portone-payment-lookup-observation.json')['unpaidPayment']['paymentId'] ?? null;
        self::assertIsString($unpaidPaymentId);
        $unpaidPayment = Payment::query()->create([
            'payment_id' => $unpaidPaymentId,
            'billable_type' => $user->getMorphClass(),
            'billable_id' => (string) $user->getKey(),
            'customer_id' => 'checkout-completion-live-probe',
            'order_name' => 'Recorded unpaid PortOne payment',
            'amount' => 100,
            'status' => 'PENDING',
            'cancelled_amount' => 0,
            'paid_at' => null,
        ]);
        $this->post(route('portone.checkout.complete'), ['paymentId' => $unpaidPaymentId])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
        self::assertSame('PENDING', $unpaidPayment->fresh()->status);

        $this->post(route('portone.checkout.complete'), ['paymentId' => 'unknown-local-payment'])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);

        $otherPayment = UserFactory::new()->create()->preparePayment(100, 'Other billable probe');
        $this->post(route('portone.checkout.complete'), ['paymentId' => $otherPayment->payment_id])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
        self::assertSame('PENDING', $otherPayment->fresh()->status);

        $realApiSecret = config('portone.api_secret');
        config(['portone.api_secret' => null]);
        $this->post(route('portone.checkout.complete'), ['paymentId' => $unpaidPaymentId])
            ->assertStatus(500)
            ->assertJsonStructure(['error']);

        config(['portone.api_secret' => 'invalid-live-probe-credential']);
        $invalidCredentialResponse = $this->post(route('portone.checkout.complete'), ['paymentId' => $unpaidPaymentId]);
        $invalidCredentialResponse->assertStatus(500)->assertJsonStructure(['error']);
        self::assertStringNotContainsString('invalid-live-probe-credential', (string) $invalidCredentialResponse->json('error'));
        self::assertSame('PENDING', $unpaidPayment->fresh()->status);
        config(['portone.api_secret' => $realApiSecret]);

        $completed = $this->post(route('portone.checkout.complete'), [
            'paymentId' => $payment->payment_id,
            'amount' => 1,
        ]);
        $completed->assertOk()->assertExactJson(['status' => 'PAID']);
        self::assertSame('PAID', $payment->fresh()->status);
        self::assertNotNull($payment->fresh()->paid_at);

        $this->recordObservation('portone-checkout-completion-observation.json', [
            'recordedAt' => gmdate(DATE_ATOM),
            'publishedStubs' => true,
            'prepareRoute' => [
                'status' => $prepare->status(),
                'paymentStatusBeforeBrowser' => 'PENDING',
                'browserResult' => 'returned without an error code',
            ],
            'completeRoute' => [
                'status' => $completed->status(),
                'paymentStatus' => $completed->json('status'),
                'requestAmountIgnored' => true,
            ],
            'amountMismatchStatus' => $mismatchResponse->status(),
            'notPaidStatus' => 422,
            'unknownLocalPaymentStatus' => 422,
            'otherBillablePaymentStatus' => 422,
            'missingApiSecretStatus' => 500,
            'invalidApiSecretStatus' => $invalidCredentialResponse->status(),
            'secretsRecorded' => false,
        ]);
    }
}
