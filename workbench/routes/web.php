<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\CustomerController;
use Workbench\App\Http\Controllers\PaymentController;
use Workbench\App\Http\Controllers\ProductController;
use Workbench\App\Http\Controllers\WebhookLogController;
use Workbench\App\Http\Middleware\MapBrowserSdkImport;

// The checkout stubs are published into the application by
// `vendor:publish --tag=portone-stubs`; include them once they exist.
$controller = app_path('Http/Controllers/PortOneCheckoutController.php');
$routes = base_path('routes/portone.php');

if (is_file($controller) && is_file($routes)) {
    require_once $controller;

    Route::middleware(MapBrowserSdkImport::class)->group($routes);
} elseif (! app()->runningUnitTests()) {
    // Otherwise the sample shop uses its copy of the stubs, edited only at the product lookup.
    // PHPUnit runs (live tests included) never see it, so they always exercise the published stubs.
    Route::group([], __DIR__.'/portone.php');
}

// Developer guide of `composer serve`; signing in is a link, never automatic.
Route::get('/', function () use ($controller, $routes) {
    $settings = ['api_secret', 'webhook_secret', 'base_url', 'webhook_path', 'store_id', 'channel_key'];

    return view('workbench::index', [
        'configured' => collect($settings)->mapWithKeys(fn (string $key) => [
            'PORTONE_'.strtoupper($key) => filled(config('portone.'.$key)),
        ])->all(),
        'webhookPath' => Route::has('portone.webhook') ? route('portone.webhook', absolute: false) : null,
        'checkoutStubs' => match (true) {
            is_file($controller) && is_file($routes) => 'published',
            Route::has('portone.checkout') => 'sample',
            default => null,
        },
        'pendingExpireAfter' => config('portone.pending_expire_after'),
        'signInUrl' => Route::has('workbench.login') ? route('workbench.login', ['userId' => 'test@example.com']) : null,
    ]);
})->name('workbench.guide');

// Guests reaching an `auth` route are sent back to the guide.
Route::redirect('/login', '/')->name('login');

// The sample shop: plain Application code on top of the Billable methods.
Route::middleware('auth')->prefix('shop')->name('shop.')->group(function (): void {
    Route::get('/', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/payments/expire', [PaymentController::class, 'expire'])->name('payments.expire');
    Route::get('/payments/{paymentId}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('/payments/{paymentId}/complete', [PaymentController::class, 'complete'])->name('payments.complete');
    Route::post('/payments/{paymentId}/refund', [PaymentController::class, 'refund'])->name('payments.refund');
    Route::get('/customer', [CustomerController::class, 'show'])->name('customer.show');
    Route::post('/customer', [CustomerController::class, 'store'])->name('customer.store');
    Route::get('/webhooks', [WebhookLogController::class, 'index'])->name('webhooks.index');
});

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
