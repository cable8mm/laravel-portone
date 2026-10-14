@extends('workbench::layouts.app')

@section('title', '결제 내역')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-800">🧾 결제 내역</h1>
            <p class="mt-1 text-slate-500">최신순으로 보여 줘요. 결제하기를 누른 순간 결제 대기 레코드가 생깁니다.</p>
        </div>
        <form method="post" action="{{ route('shop.payments.expire') }}" data-expire-now>
            @csrf
            <button class="btn-ghost">⏰ 지금 만료 검사 실행</button>
        </form>
    </div>
    <p class="mt-3 rounded-2xl bg-white/70 px-4 py-3 text-xs text-slate-500">
        {{ config('portone.pending_expire_after') }}분 넘게 결제 대기인 건을 포트원에 확인해 결제됐으면 결제 완료, 아니면 만료로 바꿔요.
        스케줄러가 10분마다 실행하고, 확인은 큐 워커가 합니다.
    </p>

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
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Providers/WorkbenchServiceProvider.php',
        'note' => '패키지는 스케줄을 등록하지 않아요. 앱이 등록합니다. 지금 만료 검사 버튼은 웹 요청이라 Artisan::queue()로 같은 명령을 큐에 넣어요(패키지 명령은 콘솔에서만 등록되므로 Artisan::call()은 실패해요).',
        'code' => "\$schedule->command('portone:expire-pending')->everyTenMinutes();\n\$schedule->command('model:prune', ['--model' => [Payment::class]])->daily();",
    ])
@endsection
