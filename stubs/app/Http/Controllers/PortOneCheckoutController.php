<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

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
}
