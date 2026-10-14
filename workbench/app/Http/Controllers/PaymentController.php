<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\InvalidPaymentStatusException;
use Cable8mm\LaravelPortone\Exceptions\InvalidRefundAmountException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Payment history, confirmation, and cancellation through the Billable methods of the signed-in user.
 */
class PaymentController
{
    public function index(Request $request): View
    {
        return view('workbench::shop.payments.index', [
            'payments' => $request->user()->payments()->get(),
        ]);
    }

    public function show(Request $request, string $paymentId): View
    {
        return view('workbench::shop.payments.show', [
            'payment' => $request->user()->findPayment($paymentId) ?? abort(404),
        ]);
    }

    /**
     * Ask PortOne again for a payment still PENDING, e.g. when the browser closed right after paying.
     */
    public function complete(Request $request, string $paymentId): RedirectResponse
    {
        try {
            $payment = $request->user()->completePayment($paymentId);
        } catch (PaymentNotFoundException) {
            abort(404);
        } catch (PaymentNotPaidException|AmountMismatchException|ConfigurationException|PortOneException $exception) {
            return back()->withErrors(['complete' => $exception->getMessage()]);
        }

        return redirect()->route('shop.payments.show', $paymentId)->with('status', $payment->status === 'PAID' ? '결제가 확인됐어요.' : '이미 처리된 결제예요.');
    }

    /**
     * Cancel the whole remaining balance (no amount) or part of it (amount).
     */
    public function refund(Request $request, string $paymentId): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            // A null amount cancels the whole remaining balance.
            $request->user()->refund($paymentId, $validated['reason'], $validated['amount'] ?? null);
        } catch (PaymentNotFoundException) {
            abort(404);
        } catch (InvalidPaymentStatusException|InvalidRefundAmountException|ConfigurationException|PortOneException $exception) {
            return back()->withInput()->withErrors(['refund' => $exception->getMessage()]);
        }

        return redirect()->route('shop.payments.show', $paymentId)->with('status', isset($validated['amount']) ? '부분 취소했어요.' : '전액 취소했어요.');
    }
}
