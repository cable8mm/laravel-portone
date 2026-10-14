@extends('workbench::layouts.app')

@section('title', '결제 내역')

@section('content')
    <h1 class="text-3xl font-black text-slate-800">🧾 결제 내역</h1>
    <p class="mt-1 text-slate-500">최신순으로 보여 줘요. 결제하기를 누른 순간 결제 대기 레코드가 생깁니다.</p>

    @if ($payments->isEmpty())
        <div class="card mt-6 text-center" data-empty>
            <p class="text-5xl">🫙</p>
            <p class="mt-3 font-bold">아직 결제 내역이 없어요.</p>
            <a href="{{ route('shop.products.index') }}" class="btn-primary mt-4">상품 보러 가기</a>
        </div>
    @else
        <ul class="mt-6 space-y-3">
            @foreach ($payments as $payment)
                <li>
                    <a href="{{ route('shop.payments.show', $payment->payment_id) }}" class="card flex flex-wrap items-center gap-4 py-4 transition hover:-translate-y-0.5" data-payment="{{ $payment->payment_id }}">
                        @include('workbench::partials.status', ['status' => $payment->status])
                        <span class="font-black text-slate-800">{{ $payment->order_name }}</span>
                        <span class="font-mono text-xs text-slate-400">{{ $payment->payment_id }}</span>
                        <span class="ml-auto font-black text-candy-600">{{ number_format($payment->amount) }}원</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endsection

@section('code')
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Http/Controllers/PaymentController.php',
        'code' => '$request->user()->payments()->get();   // Billable: 최신순',
    ])
@endsection
