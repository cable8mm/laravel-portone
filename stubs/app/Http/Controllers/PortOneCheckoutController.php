<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class PortOneCheckoutController
{
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
}
