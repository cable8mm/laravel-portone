@extends('workbench::layouts.app')

@section('title', $product->name)

@section('content')
    <a href="{{ route('shop.products.index') }}" class="text-sm font-bold text-slate-400 hover:text-candy-600">← 상품 목록</a>

    <div class="card mt-4 grid gap-8 md:grid-cols-2">
        <span class="grid aspect-square place-items-center rounded-3xl bg-candy-50 text-9xl">{{ $product->emoji }}</span>

        <div class="flex flex-col">
            <h1 class="text-3xl font-black text-slate-800">{{ $product->name }}</h1>
            <p class="mt-3 text-slate-500">{{ $product->description }}</p>
            <p class="mt-6 text-4xl font-black text-candy-600">{{ number_format($product->price) }}<span class="text-xl">원</span></p>

            <a href="{{ route('portone.checkout', ['product' => $product->id]) }}" class="btn-primary mt-auto py-4 text-lg" data-buy>💳 결제</a>
            <p class="mt-3 text-center text-xs text-slate-400">이 화면에서는 아무것도 저장되지 않아요. 주문서로 이동합니다.</p>
        </div>
    </div>
@endsection

@section('code')
    @include('workbench::partials.code', [
        'file' => 'workbench/resources/views/shop/products/show.blade.php',
        'note' => '결제 버튼은 상품 ID만 들고 발행된 체크아웃 스텁(주문서)으로 이동합니다.',
        'code' => "route('portone.checkout', ['product' => \$product->id])",
    ])
@endsection
