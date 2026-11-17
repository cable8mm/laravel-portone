<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\PortOneCheckoutController;

Route::middleware('auth')->group(function (): void {
    Route::get('/portone/checkout', [PortOneCheckoutController::class, 'show'])
        ->name('portone.checkout');

    Route::post('/portone/checkout/prepare', [PortOneCheckoutController::class, 'prepare'])
        ->name('portone.checkout.prepare');

    Route::post('/portone/checkout/complete', [PortOneCheckoutController::class, 'complete'])
        ->name('portone.checkout.complete');

    Route::get('/portone/checkout/completed/{paymentId}', [PortOneCheckoutController::class, 'completed'])
        ->name('portone.checkout.completed');

    Route::get('/portone/billing-key', [PortOneCheckoutController::class, 'billingKey'])
        ->name('portone.billing-key');

    Route::post('/portone/billing-key/prepare', [PortOneCheckoutController::class, 'prepareBillingKey'])
        ->name('portone.billing-key.prepare');

    Route::get('/portone/billing-key/completed/{issueId}', [PortOneCheckoutController::class, 'billingKeyCompleted'])
        ->name('portone.billing-key.completed');
});
