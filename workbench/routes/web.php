<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Middleware\MapBrowserSdkImport;

// The checkout stubs are published into the application by
// `vendor:publish --tag=portone-stubs`; include them once they exist.
$controller = app_path('Http/Controllers/PortOneCheckoutController.php');
$routes = base_path('routes/portone.php');

if (is_file($controller) && is_file($routes)) {
    require_once $controller;

    Route::middleware(MapBrowserSdkImport::class)->group($routes);
}

// Lets a live test have the human pay any Package-prepared payment of the signed-in user.
Route::middleware('auth')->get('/workbench/pay/{paymentId}', function (string $paymentId) {
    $payment = auth()->user()->findPayment($paymentId) ?? abort(404);

    return view('workbench::pay', [
        'storeId' => config('portone.store_id'),
        'channelKey' => config('portone.channel_key'),
        'checkout' => $payment->checkoutParameters(),
        'sdkUrl' => MapBrowserSdkImport::SDK_URL,
    ]);
})->name('workbench.pay');
