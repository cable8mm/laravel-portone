<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortOneCheckoutController
{
    private const AMOUNT = 10000;

    private const ORDER_NAME = 'Order A';

    public function show(): View
    {
        $storeId = config('portone.store_id');
        $channelKey = config('portone.channel_key');
        $missingConfiguration = blank($storeId) || blank($channelKey);

        return view('portone.checkout', [
            'storeId' => $storeId,
            'channelKey' => $channelKey,
            'prepareUrl' => route('portone.checkout.prepare'),
            'completeUrl' => route('portone.checkout.complete'),
            'missingConfiguration' => $missingConfiguration,
            'state' => $missingConfiguration ? 'error' : 'initial',
            'statusText' => $missingConfiguration ? 'Error' : 'Idle',
        ]);
    }

    public function prepare(): JsonResponse
    {
        $payment = auth()->user()->preparePayment(self::AMOUNT, self::ORDER_NAME);

        return response()->json($payment->checkoutParameters());
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

        return response()->json(['status' => $payment->status]);
    }
}
