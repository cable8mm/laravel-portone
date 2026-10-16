<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\PortonePhp\Browser\BrowserPaymentRequest;
use Cable8mm\PortonePhp\Browser\RedirectResult;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortOneCheckoutController
{
    public function show(Request $request): View
    {
        $product = $this->product($request);
        $storeId = config('portone.store_id');
        $channelKey = config('portone.channel_key');
        $missingConfiguration = blank($storeId) || blank($channelKey);

        return view('portone.checkout', [
            'product' => $product,
            'storeId' => $storeId,
            'channelKey' => $channelKey,
            'prepareUrl' => route('portone.checkout.prepare'),
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
        );

        return response()->json($browser->toArray());
    }

    public function complete(Request $request): JsonResponse
    {
        try {
            $payment = auth()->user()->completePayment((string) $request->input('paymentId'));
        } catch (PaymentNotFoundException|PaymentNotPaidException|AmountMismatchException $exception) {
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

    public function completed(Request $request, string $paymentId): View
    {
        // The path Payment ID identifies the record; the query carries the redirect result.
        $redirect = RedirectResult::fromQuery(['paymentId' => $paymentId] + $request->query());

        if ($redirect->isFailure()) {
            $payment = auth()->user()->findPayment($redirect->paymentId);
            abort_unless($payment, 404);

            return view('portone.completed', ['payment' => $payment, 'failed' => true]);
        }

        $payment = auth()->user()->findPayment($paymentId);
        abort_unless($payment, 404);

        if (in_array($payment->status, ['PENDING', 'EXPIRED'], true)) {
            try {
                $payment = auth()->user()->completePayment($paymentId);
            } catch (PaymentNotFoundException|PaymentNotPaidException|AmountMismatchException|ConfigurationException|PortOneException $exception) {
                return view('portone.completed', ['payment' => $payment->fresh() ?? $payment, 'failed' => true]);
            }
        }

        // Only a payment that has been paid has an order to show; a later refund keeps the page.
        abort_unless(in_array($payment->status, ['PAID', 'PARTIALLY_CANCELLED', 'CANCELLED'], true), 404);

        return view('portone.completed', ['payment' => $payment, 'failed' => false]);
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
