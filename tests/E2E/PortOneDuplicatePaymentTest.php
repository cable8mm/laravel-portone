<?php

declare(strict_types=1);

namespace Tests\E2E;

use Cable8mm\LaravelPortone\Events\PaymentDuplicated;
use Cable8mm\LaravelPortone\Exceptions\DuplicatePaymentException;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PortOneGateway;
use Illuminate\Support\Facades\Event;
use Laravel\Dusk\Browser;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

/**
 * T-041: two payments of one single-payment Billable model are paid in the browser; the second paid one is
 * cancelled at PortOne (EL-069, EL-055, FR-109 to FR-112, ERR-020).
 *
 * The Workbench user is declared paid at most once by its e-mail domain, so the served app (Transaction.Paid
 * webhooks, when PortOne can reach it) and this test process apply the same rule. Whichever completes a payment
 * first, exactly one record ends PAID and the other DUPLICATE_CANCELLED, cancelled at PortOne.
 */
class PortOneDuplicatePaymentTest extends TestCase
{
    private const OBSERVATION = 'portone-duplicate-payment-observation.json';

    public function test_second_paid_payment_of_a_single_payment_billable_is_cancelled_at_portone(): void
    {
        $user = UserFactory::new()->create(['email' => 'order-'.bin2hex(random_bytes(4)).User::SINGLE_PAYMENT_EMAIL_DOMAIN]);
        self::assertTrue($user->allowsOnlyOnePaidPayment());
        $first = $user->preparePayment(100, 'PortOne duplicate payment probe 1');
        $second = $user->preparePayment(100, 'PortOne duplicate payment probe 2');

        $this->browse(function (Browser $browser) use ($user, $first, $second): void {
            $this->payInBrowser($browser, $user, $first, 'Complete the FIRST 100 KRW PortOne test payment in the browser window.');
            $this->payInBrowser($browser, $user, $second, 'Complete the SECOND 100 KRW PortOne test payment in the browser window.');
        });

        $gateway = $this->app->make(PortOneGateway::class);
        $statusesBeforeCompletion = [$first->fresh()->status, $second->fresh()->status];

        $duplicated = [];
        Event::listen(PaymentDuplicated::class, static function (PaymentDuplicated $event) use (&$duplicated): void {
            $duplicated[] = ['paymentId' => $event->paymentId, 'wasCancelled' => $event->wasCancelled, 'source' => $event->source->value];
        });

        $exceptions = [];
        foreach ([$first, $second] as $payment) {
            try {
                $user->completePayment($payment->payment_id);
            } catch (DuplicatePaymentException $exception) {
                self::assertTrue($exception->wasCancelled(), 'PortOne rejected the automatic cancellation: '.$exception->getPrevious()?->getMessage());
                self::assertSame(100, $exception->paidAmount());
                $exceptions[] = $payment->payment_id;
            }
        }

        $records = Payment::query()->whereKey([$first->getKey(), $second->getKey()])->get()->keyBy('payment_id');
        $paid = $records->where('status', 'PAID');
        $duplicates = $records->where('status', 'DUPLICATE_CANCELLED');
        self::assertCount(1, $paid);
        self::assertCount(1, $duplicates);
        $paidRecord = $paid->sole();
        $duplicate = $duplicates->sole();
        self::assertSame([100, 100], [$duplicate->paid_amount, $duplicate->cancelled_amount]);
        self::assertNotNull($duplicate->paid_at);

        $paidAtPortOne = $gateway->lookup($paidRecord->payment_id);
        $duplicateAtPortOne = $gateway->lookup($duplicate->payment_id);
        self::assertSame('PAID', $paidAtPortOne->status);
        self::assertSame(['CANCELLED', 100], [$duplicateAtPortOne->status, $duplicateAtPortOne->cancelledAmount]);

        // The test process completed the duplicate itself (EL-005 throws) unless the served app's webhook was first.
        if ($exceptions !== []) {
            self::assertSame([$duplicate->payment_id], $exceptions);
            self::assertSame([['paymentId' => $duplicate->payment_id, 'wasCancelled' => true, 'source' => 'complete']], $duplicated);
        }

        $this->recordObservation(self::OBSERVATION, [
            'recordedAt' => gmdate(DATE_ATOM),
            'client' => 'cable8mm/portone-php through cable8mm/laravel-portone',
            'billable' => 'one Workbench user whose allowsOnlyOnePaidPayment() returns true',
            'payments' => [$first->payment_id, $second->payment_id],
            'recordStatusesBeforeTestCompletion' => $statusesBeforeCompletion,
            'completedBy' => $exceptions !== [] ? 'completePayment() in the test process' : 'Transaction.Paid webhook in the served app',
            'paid' => [
                'paymentId' => $paidRecord->payment_id,
                'recordStatus' => $paidRecord->status,
                'portOneStatus' => $paidAtPortOne->status,
            ],
            'duplicate' => [
                'paymentId' => $duplicate->payment_id,
                'cancelReason' => config('portone.duplicate_cancel_reason'),
                'exception' => $exceptions !== [] ? DuplicatePaymentException::class : null,
                'recordStatus' => $duplicate->status,
                'recordPaidAmount' => $duplicate->paid_amount,
                'recordCancelledAmount' => $duplicate->cancelled_amount,
                'portOneStatusAfterCancel' => $duplicateAtPortOne->status,
                'portOneCancelledAmount' => $duplicateAtPortOne->cancelledAmount,
            ],
            'paymentEventsInTestProcess' => $duplicated,
            'secretsRecorded' => false,
        ]);
    }
}
