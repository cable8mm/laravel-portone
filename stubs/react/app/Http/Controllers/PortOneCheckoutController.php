<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\BillingKeyNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\BillingKeyNotIssuedException;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\DuplicatePaymentException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\PortonePhp\Browser\BillingKeyRedirectResult;
use Cable8mm\PortonePhp\Browser\BrowserBillingKeyRequest;
use Cable8mm\PortonePhp\Browser\BrowserPaymentRequest;
use Cable8mm\PortonePhp\Browser\RedirectResult;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PortOneCheckoutController
{
    /** The name shown for a card registration; replace it with your own. */
    private const BILLING_KEY_ISSUE_NAME = 'Card registration';

    /** The billing-key method sent to the browser SDK. */
    private const BILLING_KEY_METHOD = 'CARD';

    /** The session flash key of the billing-key result. */
    private const BILLING_KEY_ISSUED = 'portone.billing_key_issued';

    public function show(Request $request): Response
    {
        $product = $this->product($request);
        $storeId = config('portone.store_id');
        $channelKey = config('portone.channel_key');
        $missingConfiguration = blank($storeId) || blank($channelKey);

        return Inertia::render('portone/checkout', [
            'product' => ['id' => $product->id, 'name' => $product->name, 'price' => $product->price],
            'storeId' => $storeId,
            'channelKey' => $channelKey,
            'prepareUrl' => route('portone.checkout.prepare'),
            'csrfToken' => csrf_token(),
            'missingConfiguration' => $missingConfiguration,
            'state' => $missingConfiguration ? 'error' : 'initial',
            'statusText' => $missingConfiguration ? 'Error' : 'Idle',
        ]);
    }

    public function prepare(Request $request): JsonResponse
    {
        $product = $this->product($request);
        $payment = auth()->user()->preparePayment($product->price, $product->name);
        $checkout = $payment->checkoutParameters();

        $browser = new BrowserPaymentRequest(
            storeId: (string) config('portone.store_id'),
            channelKey: (string) config('portone.channel_key'),
            paymentId: $checkout['paymentId'],
            orderName: $checkout['orderName'],
            totalAmount: $checkout['totalAmount'],
            redirectUrl: route('portone.checkout.completed', ['paymentId' => $payment->payment_id]),
            customerId: $checkout['customerId'],
            forceRedirect: true,
            customerName: auth()->user()->name,
        );

        return response()->json($browser->toArray());
    }

    public function complete(Request $request): JsonResponse
    {
        try {
            $payment = auth()->user()->completePayment((string) $request->input('paymentId'));
        } catch (PaymentNotFoundException|PaymentNotPaidException|AmountMismatchException|DuplicatePaymentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (ConfigurationException|PortOneException $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }

        if ($payment->status !== 'PAID') {
            return response()->json(['status' => $payment->status]);
        }

        return response()->json([
            'status' => $payment->status,
            'redirectUrl' => route('portone.checkout.completed', ['paymentId' => $payment->payment_id]),
        ]);
    }

    public function completed(Request $request, string $paymentId): Response
    {
        // The path Payment ID identifies the record; the query carries the redirect result.
        $redirect = RedirectResult::fromQuery(['paymentId' => $paymentId] + $request->query());

        if ($redirect->isFailure()) {
            $payment = auth()->user()->findPayment($redirect->paymentId);
            abort_unless($payment, 404);

            return $this->completedPage($payment, true);
        }

        $payment = auth()->user()->findPayment($paymentId);
        abort_unless($payment, 404);

        if (in_array($payment->status, ['PENDING', 'EXPIRED'], true)) {
            try {
                $payment = auth()->user()->completePayment($paymentId);
            } catch (PaymentNotFoundException|PaymentNotPaidException|AmountMismatchException|DuplicatePaymentException|ConfigurationException|PortOneException $exception) {
                return $this->completedPage($payment->fresh() ?? $payment, true);
            }
        }

        // Only a payment that has been paid has an order to show; a later refund keeps the page.
        abort_unless(in_array($payment->status, ['PAID', 'PARTIALLY_CANCELLED', 'CANCELLED'], true), 404);

        return $this->completedPage($payment, false);
    }

    /**
     * The completed page receives only the payment values it shows.
     */
    private function completedPage(Payment $payment, bool $failed): Response
    {
        return Inertia::render('portone/completed', [
            'payment' => [
                'payment_id' => $payment->payment_id,
                'order_name' => $payment->order_name,
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at?->toDateTimeString(),
                'status' => $payment->status,
            ],
            'failed' => $failed,
        ]);
    }

    public function billingKey(): Response
    {
        $storeId = config('portone.store_id');
        $channelKey = config('portone.billing_channel_key');
        $missingConfiguration = blank($storeId) || blank($channelKey);

        return Inertia::render('portone/billing-key', [
            'storeId' => $storeId,
            'channelKey' => $channelKey,
            'prepareUrl' => route('portone.billing-key.prepare'),
            'billingKeyUrl' => route('portone.billing-key'),
            'csrfToken' => csrf_token(),
            'missingConfiguration' => $missingConfiguration,
            'state' => $missingConfiguration ? 'error' : 'initial',
            'statusText' => $missingConfiguration ? 'Error' : 'Idle',
        ]);
    }

    public function prepareBillingKey(): JsonResponse
    {
        $billingKey = auth()->user()->prepareBillingKey(self::BILLING_KEY_ISSUE_NAME);
        $issue = $billingKey->issueParameters();

        $browser = new BrowserBillingKeyRequest(
            storeId: (string) config('portone.store_id'),
            channelKey: (string) config('portone.billing_channel_key'),
            billingKeyMethod: self::BILLING_KEY_METHOD,
            issueId: $issue['issueId'],
            issueName: $issue['issueName'],
            redirectUrl: route('portone.billing-key.completed', ['issueId' => $billingKey->issue_id]),
            customerId: $issue['customerId'],
            forceRedirect: true,
        );

        return response()->json($browser->toArray());
    }

    public function billingKeyCompleted(Request $request, string $issueId): Response|RedirectResponse
    {
        // An Inertia page carries its URL, and the redirect query carries the billing key (FR-160):
        // handle the query, then render the result from the same path without it.
        if ($request->query() === []) {
            return $this->billingKeyResult($issueId, issued: $request->session()->get(self::BILLING_KEY_ISSUED) === true);
        }

        return redirect()
            ->route('portone.billing-key.completed', ['issueId' => $issueId])
            ->with(self::BILLING_KEY_ISSUED, $this->completeBillingKey($request, $issueId));
    }

    /**
     * Whether the redirect return issued the billing key of the Issue ID.
     */
    private function completeBillingKey(Request $request, string $issueId): bool
    {
        try {
            $redirect = BillingKeyRedirectResult::fromQuery($request->query());
        } catch (\InvalidArgumentException) {
            // A return without a billing key or an error code is not a successful issuance.
            return false;
        }

        // A failure query also carries a billing key that was never issued; leave every record as it is.
        if ($redirect->isFailure() || $redirect->billingKey === null) {
            return false;
        }

        try {
            auth()->user()->completeBillingKey($issueId, $redirect->billingKey);
        } catch (BillingKeyNotFoundException|BillingKeyNotIssuedException|ConfigurationException|PortOneException) {
            return false;
        }

        return true;
    }

    /**
     * The billing-key page after the redirect return; it never receives the billing key itself.
     */
    private function billingKeyResult(string $issueId, bool $issued): Response
    {
        return Inertia::render('portone/billing-key', [
            'storeId' => null,
            'channelKey' => null,
            'prepareUrl' => route('portone.billing-key.prepare'),
            'billingKeyUrl' => route('portone.billing-key'),
            'csrfToken' => csrf_token(),
            'missingConfiguration' => false,
            'state' => $issued ? 'issued' : 'failed',
            'statusText' => $issued ? 'Issued' : 'Failed',
            'issueId' => $issueId,
        ]);
    }

    /**
     * The product being paid for; the amount and order name always come from here, never from the request.
     */
    private function product(Request $request): object
    {
        // Replace this sample product with your product from the database,
        // e.g. return Product::findOrFail($request->input('product'));
        return (object) ['id' => 1, 'name' => 'Order A', 'price' => 10000];
    }
}
