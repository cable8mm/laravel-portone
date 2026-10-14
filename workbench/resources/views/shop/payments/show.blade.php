@extends('workbench::layouts.app')

@section('title', $payment->order_name)

@section('content')
    <a href="{{ route('shop.payments.index') }}" class="text-sm font-bold text-slate-400 hover:text-candy-600">← 결제 내역</a>

    <div class="mt-4 grid gap-6 md:grid-cols-2">
        <section class="card">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-black text-slate-800">{{ $payment->order_name }}</h1>
                @include('workbench::partials.status', ['status' => $payment->status])
            </div>

            <dl class="mt-6 space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">결제 금액</dt><dd class="font-black">{{ number_format($payment->amount) }}원</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">취소된 금액</dt><dd class="font-black" data-cancelled-amount>{{ number_format($payment->cancelled_amount) }}원</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">남은 금액</dt><dd class="font-black text-candy-600">{{ number_format($payment->amount - $payment->cancelled_amount) }}원</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Payment ID</dt><dd class="truncate font-mono text-xs">{{ $payment->payment_id }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Customer ID</dt><dd class="truncate font-mono text-xs">{{ $payment->customer_id }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">결제 시각</dt><dd>{{ $payment->paid_at?->format('Y-m-d H:i:s') ?? '-' }}</dd></div>
            </dl>
        </section>

        @php($remaining = $payment->amount - $payment->cancelled_amount)
        @if (in_array($payment->status, ['PENDING', 'EXPIRED'], true))
            <section class="card" data-complete>
                <h2 class="text-xl font-black text-slate-800">🔎 결제 확인</h2>
                <p class="mt-2 text-sm text-slate-500">
                    @if ($payment->status === 'EXPIRED')
                        확인 시점에 결제되지 않아 만료된 건이에요. 같은 결제 ID로 나중에 결제됐다면 포트원에 다시 물어봐 PAID로 복구할 수 있어요.
                        보관 기간({{ config('portone.expired_retention_days') }}일)이 지나면 model:prune이 지웁니다.
                    @else
                        결제창에서 결제했는데 브라우저가 닫혀 완료 처리가 안 됐다면, 포트원에 다시 물어봐 PAID로 바꿀 수 있어요.
                        결제하지 않은 건이면 미결제 오류가 납니다.
                    @endif
                </p>
                <form method="post" action="{{ route('shop.payments.complete', $payment->payment_id) }}" class="mt-4">
                    @csrf
                    <button class="btn-primary w-full">포트원에서 결제 확인</button>
                </form>
                @error('complete')
                    <p class="mt-4 rounded-2xl bg-candy-100 px-4 py-3 text-sm font-bold text-candy-600" role="alert">{{ $message }}</p>
                @enderror
            </section>
        @elseif ($payment->status === 'CANCELLED')
            <section class="card">
                <h2 class="text-xl font-black text-slate-800">↩️ 결제 취소</h2>
                <p class="mt-4 rounded-2xl bg-slate-100 px-4 py-6 text-center text-sm text-slate-500">전액 취소된 결제예요.</p>
            </section>
        @else
            <section class="card space-y-6" data-cancel>
                <h2 class="text-xl font-black text-slate-800">↩️ 결제 취소</h2>

                @error('refund')
                    <p class="rounded-2xl bg-candy-100 px-4 py-3 text-sm font-bold text-candy-600" role="alert">{{ $message }}</p>
                @enderror

                <form method="post" action="{{ route('shop.payments.refund', $payment->payment_id) }}" class="space-y-3 rounded-2xl bg-candy-50 p-4" data-cancel-full>
                    @csrf
                    <p class="font-bold">전액 취소 <span class="text-sm font-normal text-slate-500">· 남은 {{ number_format($remaining) }}원 모두</span></p>
                    <input name="reason" value="단순 변심" required aria-label="취소 사유" class="w-full rounded-2xl border-2 border-candy-100 bg-white px-4 py-2.5 focus:border-candy-400 focus:outline-none">
                    <button class="btn-primary w-full">전액 취소</button>
                </form>

                <form method="post" action="{{ route('shop.payments.refund', $payment->payment_id) }}" class="space-y-3 rounded-2xl bg-butter/60 p-4" data-cancel-partial>
                    @csrf
                    <p class="font-bold">부분 취소 <span class="text-sm font-normal text-slate-500">· 금액을 정해 여러 번 나눠 취소</span></p>
                    <input name="reason" value="부분 반품" required aria-label="취소 사유" class="w-full rounded-2xl border-2 border-candy-100 bg-white px-4 py-2.5 focus:border-candy-400 focus:outline-none">
                    <input name="amount" type="number" min="1" max="{{ $remaining }}" value="{{ old('amount') }}" required placeholder="취소 금액 (최대 {{ number_format($remaining) }}원)" aria-label="취소 금액" class="w-full rounded-2xl border-2 border-candy-100 bg-white px-4 py-2.5 focus:border-candy-400 focus:outline-none">
                    <button class="btn-ghost w-full">부분 취소</button>
                </form>
            </section>
        @endif
    </div>
@endsection

@section('code')
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Http/Controllers/PaymentController.php',
        'note' => '결제 대기·만료 건은 completePayment()로 다시 확인하고(만료 뒤 결제됐으면 PAID로 복구), 결제된 건은 refund()로 취소합니다. 금액이 null이면 전액, 숫자면 부분 취소예요.',
        'code' => "\$request->user()->findPayment(\$paymentId);\n\$request->user()->completePayment(\$paymentId);          // PENDING → PAID\n\$request->user()->refund(\$paymentId, \$reason);           // 전액 취소\n\$request->user()->refund(\$paymentId, \$reason, 3000);     // 부분 취소",
    ])
@endsection
