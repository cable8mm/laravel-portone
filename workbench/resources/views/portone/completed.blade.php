{{-- The published completion view (stubs/resources/views/portone/completed.blade.php),
     given the shop layout and styling; its data attributes are unchanged. --}}
@extends('workbench::layouts.app')

@section('title', '주문 완료')

@section('content')
    <main data-portone-completed data-status="{{ $payment->status }}"@if($failed) data-failed @endif class="card mx-auto max-w-md text-center">
        @if($failed)
            <p class="text-6xl">😞</p>
            <h1 class="mt-3 text-2xl font-black text-slate-800">결제에 실패했어요</h1>
            <p class="mt-1 text-sm text-slate-500">결제가 끝나지 않았어요. 다시 시도해 주세요.</p>
        @else
            <p class="text-6xl">🎉</p>
            <h1 class="mt-3 text-2xl font-black text-slate-800">주문이 완료됐어요!</h1>
            <p class="mt-1 text-sm text-slate-500">맛있게 준비해서 보내 드릴게요.</p>
        @endif

        <dl class="mt-6 space-y-2 rounded-2xl bg-candy-50 p-4 text-left text-sm">
            <div class="flex justify-between gap-4"><dt class="text-slate-500">주문</dt><dd class="font-bold">{{ $payment->order_name }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">결제 금액</dt><dd class="font-black text-candy-600">{{ number_format($payment->amount) }} KRW</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">결제 ID</dt><dd class="truncate font-mono text-xs">{{ $payment->payment_id }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">결제 시각</dt><dd>{{ $payment->paid_at?->toDateTimeString() }}</dd></div>
            <div class="flex items-center justify-between gap-4"><dt class="text-slate-500">상태</dt><dd>@include('workbench::partials.status', ['status' => $payment->status])</dd></div>
        </dl>

        <div class="mt-6 grid grid-cols-2 gap-3">
            @if($failed)
                <a href="{{ route('shop.payments.show', $payment->payment_id) }}" class="btn-ghost">🧾 결제 상세</a>
                <a href="{{ route('shop.products.index') }}" class="btn-primary">🛍 다시 쇼핑</a>
            @else
                <a href="{{ route('shop.payments.show', $payment->payment_id) }}" class="btn-ghost">🧾 결제 상세</a>
                <a href="{{ route('shop.products.index') }}" class="btn-primary">🛍 계속 쇼핑</a>
            @endif
        </div>
    </main>
@endsection

@section('code')
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Http/Controllers/PortOneCheckoutController.php',
        'note' => '리다이렉트로 돌아오는 화면이에요. 실패 복귀는 레코드를 건드리지 않고, PENDING·EXPIRED는 먼저 complete으로 확정한 뒤 보여 줍니다.',
        'code' => "if (\$redirect->isFailure()) { /* 레코드 변경 없이 실패 화면 */ }\nif (in_array(\$payment->status, ['PENDING', 'EXPIRED'], true)) {\n    \$payment = auth()->user()->completePayment(\$paymentId);\n}",
    ])
@endsection
