@extends('workbench::layouts.app')

@section('title', '웹훅 로그')

@section('content')
    <h1 class="text-3xl font-black text-slate-800">📮 웹훅 로그</h1>
    <p class="mt-1 text-slate-500">
        포트원이 <code class="rounded bg-white px-1.5 py-0.5 font-mono text-sm">POST {{ $webhookPath }}</code>로 보낸 웹훅 중 서명 검증을 통과한 것만 쌓여요.
        로컬에서는 ngrok 같은 공개 URL이 필요합니다.
    </p>

    @if ($logs->isEmpty())
        <div class="card mt-6 text-center" data-empty>
            <p class="text-5xl">📭</p>
            <p class="mt-3 font-bold">아직 받은 웹훅이 없어요.</p>
        </div>
    @else
        <ul class="mt-6 space-y-3">
            @foreach ($logs as $log)
                <li class="card flex flex-wrap items-center gap-4 py-4" data-webhook-log="{{ $log->webhook_id }}">
                    <span class="chip bg-sky-soft text-sky-600">{{ $log->type }}</span>
                    <span class="font-mono text-xs text-slate-400">{{ $log->webhook_id }}</span>
                    @if ($log->payment_id)
                        <a href="{{ route('shop.payments.show', $log->payment_id) }}" class="font-mono text-xs text-candy-600 hover:underline">{{ $log->payment_id }}</a>
                    @endif
                    <span class="ml-auto text-xs text-slate-400">{{ $log->created_at?->format('Y-m-d H:i:s') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
@endsection

@section('code')
    @include('workbench::partials.code', [
        'file' => 'workbench/app/Providers/WorkbenchServiceProvider.php',
        'note' => '패키지는 검증된 웹훅마다 WebhookReceived 이벤트를 한 번 보냅니다.',
        'code' => "Event::listen(WebhookReceived::class, function (WebhookReceived \$event) {\n    WebhookLog::create([...]);\n});",
    ])
@endsection
