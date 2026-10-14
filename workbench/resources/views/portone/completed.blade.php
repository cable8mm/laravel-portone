{{-- The published completion view (stubs/resources/views/portone/completed.blade.php),
     given the shop layout and styling; its data attributes are unchanged. --}}
@extends('workbench::layouts.app')

@section('title', '주문 완료')

@section('content')
    <main data-portone-completed data-status="{{ $payment->status }}" class="card mx-auto max-w-md text-center">
        <p class="text-6xl">🎉</p>
        <h1 class="mt-3 text-2xl font-black text-slate-800">주문이 완료됐어요!</h1>
        <p class="mt-1 text-sm text-slate-500">맛있게 준비해서 보내 드릴게요.</p>

        <dl class="mt-6 space-y-2 rounded-2xl bg-candy-50 p-4 text-left text-sm">
            <div class="flex justify-between gap-4"><dt class="text-slate-500">주문</dt><dd class="font-bold">{{ $payment->order_name }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">결제 금액</dt><dd class="font-black text-candy-600">{{ number_format($payment->amount) }} KRW</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">결제 ID</dt><dd class="truncate font-mono text-xs">{{ $payment->payment_id }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">결제 시각</dt><dd>{{ $payment->paid_at?->toDateTimeString() }}</dd></div>
            <div class="flex items-center justify-between gap-4"><dt class="text-slate-500">상태</dt><dd>@include('workbench::partials.status', ['status' => $payment->status])</dd></div>
        </dl>

        <div class="mt-6 grid grid-cols-2 gap-3">
            <a href="{{ route('shop.payments.show', $payment->payment_id) }}" class="btn-ghost">🧾 결제 상세</a>
            <a href="{{ route('shop.products.index') }}" class="btn-primary">🛍 계속 쇼핑</a>
        </div>
    </main>
@endsection

@section('code')
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Http/Controllers/PortOneCheckoutController.php',
        'note' => '결제 완료(PAID) 응답의 redirectUrl로 이동해 온 화면이에요. 내 결제이면서 결제된 적 있는 건만 보여 주고, 나머지는 404입니다.',
        'code' => "\$payment = auth()->user()->findPayment(\$paymentId);\nabort_unless(in_array(\$payment?->status, ['PAID', 'PARTIALLY_CANCELLED', 'CANCELLED'], true), 404);",
    ])
@endsection
