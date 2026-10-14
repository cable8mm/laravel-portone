<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Workbench\App\Models\Product;

/**
 * The shop's own pages. Nothing is created here: the order sheet is the
 * published checkout stub, linked with the product ID.
 */
class ProductController
{
    public function index(): View
    {
        return view('workbench::shop.products.index', [
            'products' => Product::query()->orderBy('price')->get(),
        ]);
    }

    public function show(Product $product): View
    {
        return view('workbench::shop.products.show', [
            'product' => $product,
        ]);
    }
}
