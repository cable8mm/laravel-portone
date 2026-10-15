<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\PaymentResponseRedactor;
use Cable8mm\LaravelPortone\PortOneGateway;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Workbench\Database\Factories\UserFactory;

/**
 * T-030: portone:payment prints the live PortOne payment response, redacted by default.
 * The redacted response is recorded so unit tests can trace the response shape to it.
 */
class PortOnePaymentCommandTest extends TestCase
{
    private const OBSERVATION = 'portone-payment-command-observation.json';

    public function test_command_prints_the_live_payment_response(): void
    {
        $user = UserFactory::new()->create();
        $payment = $user->preparePayment(100, 'PortOne payment command probe');

        $this->browse(function (Browser $browser) use ($user, $payment): void {
            $this->payInBrowser($browser, $user, $payment, 'Complete the 100 KRW PortOne test payment in the browser window.');
        });

        $raw = $this->app->make(PortOneGateway::class)->paymentResponse($payment->payment_id);
        self::assertSame('PAID', $raw['status'] ?? null);
        self::assertSame($payment->payment_id, $raw['id'] ?? null);
        self::assertSame(100, $raw['amount']['total'] ?? null);

        self::assertSame(0, Artisan::call('portone:payment', ['paymentId' => $payment->payment_id, '--raw' => true]));
        self::assertSame($raw, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR));

        self::assertSame(0, Artisan::call('portone:payment', ['paymentId' => $payment->payment_id]));
        $redacted = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((new PaymentResponseRedactor)->redact($raw), $redacted);
        self::assertSame(array_keys($raw), array_keys($redacted));

        // Cancel the probe payment so the test channel keeps no open paid payment.
        $user->completePayment($payment->payment_id);
        $user->refund($payment->payment_id, 'PortOne payment command probe');

        $this->recordObservation(self::OBSERVATION, [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
            'paymentId' => $payment->payment_id,
            'redactedResponse' => $redacted,
            'secretsRecorded' => false,
        ]);
    }
}
