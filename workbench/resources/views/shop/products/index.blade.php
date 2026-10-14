@extends('workbench::layouts.app')

@section('title', '상품')

@section('content')
    <div class="mb-8 text-center">
        <p class="text-5xl">🧁</p>
        <h1 class="mt-2 text-3xl font-black text-slate-800">오늘의 디저트</h1>
        <p class="mt-1 text-slate-500">상품을 고르면 주문서에서 포트원 테스트 결제를 해 볼 수 있어요.</p>
    </div>

    <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($products as $product)
            <li>
                <a href="{{ route('shop.products.show', $product) }}" class="card group block h-full transition hover:-translate-y-1" data-product-card="{{ $product->id }}">
                    <span class="grid aspect-square place-items-center rounded-2xl bg-candy-50 text-6xl transition group-hover:rotate-3">{{ $product->emoji }}</span>
                    <h2 class="mt-4 font-black text-slate-800">{{ $product->name }}</h2>
                    <p class="mt-1 text-lg font-black text-candy-600">{{ number_format($product->price) }}원</p>
                </a>
            </li>
        @endforeach
    </ul>
@endsection

@section('code')
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Http/Controllers/ProductController.php',
        'note' => '애플리케이션의 평범한 상품 화면입니다. 패키지를 호출하지 않아요.',
        'code' => "Product::query()->orderBy('price')->get();",
    ])
@endsection
