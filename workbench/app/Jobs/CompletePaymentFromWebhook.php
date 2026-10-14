<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;

/**
 * Completes a payment PortOne reported paid by webhook, e.g. when the browser
 * closed before the checkout's complete request or the payment had already EXPIRED.
 */
class CompletePaymentFromWebhook implements ShouldQueue
{
    public function __construct(public readonly string $paymentId) {}

    public function handle(): void
    {
        $payment = Payment::query()->where('payment_id', $this->paymentId)->first();

        if ($payment === null) {
            return;
        }

        /** @var class-string<Model>|null $billableClass */
        $billableClass = Model::getActualClassNameForMorph($payment->billable_type);
        $billable = class_exists($billableClass) ? $billableClass::query()->find($payment->billable_id) : null;

        // completePayment() verifies the payment with PortOne itself; the webhook is only the trigger.
        $billable?->completePayment($this->paymentId);
    }
}
