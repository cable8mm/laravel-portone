<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The PortOne customer of the signed-in user. preparePayment() creates it on
 * the first payment; this page shows it and can create it ahead of time.
 */
class CustomerController
{
    public function show(Request $request): View
    {
        return view('workbench::shop.customer', [
            'hasPortOneId' => $request->user()->hasPortOneId(),
            'portOneId' => $request->user()->portOneId(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->createOrGetPortOneCustomerId();

        return redirect()->route('shop.customer.show')->with('status', '포트원 고객 ID가 준비됐어요.');
    }
}
