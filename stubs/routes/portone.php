<?php

declare(strict_types=1);

use App\Http\Controllers\PortOneCheckoutController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/portone/checkout', [PortOneCheckoutController::class, 'show'])
        ->name('portone.checkout');

    Route::post('/portone/checkout/prepare', [PortOneCheckoutController::class, 'prepare'])
        ->name('portone.checkout.prepare');

    Route::post('/portone/checkout/complete', [PortOneCheckoutController::class, 'complete'])
        ->name('portone.checkout.complete');

    Route::get('/portone/checkout/completed/{paymentId}', [PortOneCheckoutController::class, 'completed'])
        ->name('portone.checkout.completed');
});
