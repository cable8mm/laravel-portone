{{-- The published checkout view (stubs/resources/views/portone/checkout.blade.php),
     given the shop layout and styling; its data attributes and ids are unchanged. --}}
@extends('workbench::layouts.app')

@section('title', '주문서')

@section('content')
    <main
        data-portone-checkout
        data-store-id="{{ $storeId }}"
        data-channel-key="{{ $channelKey }}"
        data-prepare-url="{{ $prepareUrl }}"
        data-complete-url="{{ $completeUrl }}"
        data-product="{{ $product->id }}"
        data-csrf-token="{{ csrf_token() }}"
        data-state="{{ $state }}"
        class="group card mx-auto max-w-md"
    >
        <p class="text-center text-sm font-black tracking-widest text-candy-400">ORDER SHEET</p>
        <span class="mx-auto mt-4 grid size-28 place-items-center rounded-3xl bg-candy-50 text-6xl">{{ $product->emoji }}</span>
        <h1 class="mt-4 text-center text-2xl font-black text-slate-800">{{ $product->name }}</h1>

        <dl class="mt-6 space-y-2 rounded-2xl bg-candy-50 p-4 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">주문자</dt><dd class="font-bold">{{ auth()->user()->name }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">결제 수단</dt><dd class="font-bold">카드 (테스트)</dd></div>
            <div class="flex justify-between border-t-2 border-dashed border-candy-200 pt-2"><dt class="font-bold">결제 금액</dt><dd class="text-xl font-black text-candy-600">{{ number_format($product->price) }} KRW</dd></div>
        </dl>

        <button id="portone-checkout-button" type="button" class="btn-primary mt-6 w-full py-4 text-lg" @disabled($missingConfiguration)>💳 결제하기</button>
        <p id="portone-checkout-status" role="status" class="mt-4 rounded-full py-2 text-center text-sm font-bold bg-slate-100 text-slate-500 group-data-[state=loading]:bg-sky-soft group-data-[state=loading]:text-sky-600 group-data-[state=success]:bg-mint-100 group-data-[state=success]:text-mint-500 group-data-[state=error]:bg-candy-100 group-data-[state=error]:text-candy-600">{{ $statusText }}</p>

        <a href="{{ route('shop.payments.index') }}" class="btn-ghost mt-4 hidden w-full group-data-[state=success]:flex">🧾 결제 내역 보기</a>
        @if ($missingConfiguration)
            <p class="mt-4 text-center text-xs text-candy-600">PORTONE_STORE_ID · PORTONE_CHANNEL_KEY를 .env에 설정해 주세요.</p>
        @endif
    </main>

    @vite('resources/js/portone-checkout.js')
@endsection

@section('code')
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Http/Controllers/PortOneCheckoutController.php',
        'note' => '발행된 스텁 컨트롤러에서 product() 한 곳만 데이터베이스 조회로 바꿨어요. 주문서(show)와 결제 준비(prepare)가 같이 씁니다.',
        'code' => "private function product(Request \$request): object\n{\n    return Product::findOrFail(\$request->input('product'));\n}",
    ])
    @include('workbench::partials.code', [
        'file' => 'workbench/resources/js/portone-checkout.js',
        'note' => '발행된 스텁 스크립트 그대로입니다. 결제하기 → prepare(상품 ID만 전송) → 포트원 결제창 → complete.',
        'code' => "post(prepareUrl, { product })            // PENDING 레코드 생성\nPortOne.requestPayment({ ...payment })    // 결제창은 사람이 조작\npost(completeUrl, { paymentId })          // 서버에서 금액 검증 후 PAID",
    ])
@endsection
